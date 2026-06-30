#!/usr/bin/env python3
# =====================================================================
#  Provisiona el SOC sobre un stack ELK ya levantado:
#   1) plantilla de indice weblogs-*  (mapeos correctos)
#   2) importa data views + saved searches del analista
#   3) crea un connector de tipo Index -> indice 'soc-alerts'
#   4) crea las reglas de deteccion (.es-query) que alimentan ese feed
#  Re-ejecutable: limpia lo que creo antes (tag 'soc-lab').
# =====================================================================
import json, os, sys, time, urllib.request, urllib.error

ES = os.environ.get("ES_URL", "http://localhost:9200")
KB = os.environ.get("KB_URL", "http://localhost:5601")
HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)

def req(method, url, data=None, headers=None, raw=False):
    h = {"Content-Type": "application/json"}
    if headers:
        h.update(headers)
    body = None
    if data is not None:
        body = data if raw else json.dumps(data).encode()
    r = urllib.request.Request(url, data=body, headers=h, method=method)
    with urllib.request.urlopen(r, timeout=30) as resp:
        txt = resp.read().decode()
        return resp.status, (json.loads(txt) if txt.strip().startswith(("{", "[")) else txt)

def try_req(*a, **k):
    try:
        return req(*a, **k)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()
    except Exception as e:
        return None, str(e)

def wait(label, fn, timeout=300):
    print(f"⏳ Esperando {label} ...", end="", flush=True)
    t0 = time.time()
    while time.time() - t0 < timeout:
        if fn():
            print(" OK")
            return True
        print(".", end="", flush=True)
        time.sleep(4)
    print(" TIMEOUT")
    return False

# ---------- 1) health ----------
def es_ready():
    s, b = try_req("GET", f"{ES}/_cluster/health")
    return s == 200 and isinstance(b, dict) and b.get("status") in ("green", "yellow")

def kb_ready():
    s, b = try_req("GET", f"{KB}/api/status", headers={"kbn-xsrf": "true"})
    if s != 200 or not isinstance(b, dict):
        return False
    return b.get("status", {}).get("overall", {}).get("level") == "available"

if not wait("Elasticsearch", es_ready):
    sys.exit("Elasticsearch no respondio. ¿'docker compose up -d' terminado?")
if not wait("Kibana", kb_ready):
    sys.exit("Kibana no respondio.")

# ---------- 2) index template ----------
print("📐 Aplicando plantilla de indice weblogs-* ...")
tpl = open(os.path.join(HERE, "index-template-weblogs.json")).read().encode()
s, b = try_req("PUT", f"{ES}/_index_template/weblogs", data=tpl, raw=True)
print("   ->", s, b if s != 200 else "acknowledged")

# ---------- 3) importar saved objects ----------
def import_saved_objects(path):
    boundary = "----soclabboundary"
    fname = os.path.basename(path)
    payload = open(path, "rb").read()
    body = (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="file"; filename="{fname}"\r\n'
        "Content-Type: application/ndjson\r\n\r\n"
    ).encode() + payload + f"\r\n--{boundary}--\r\n".encode()
    url = f"{KB}/api/saved_objects/_import?overwrite=true"
    h = {"kbn-xsrf": "true", "Content-Type": f"multipart/form-data; boundary={boundary}"}
    s, b = try_req("POST", url, data=body, headers=h, raw=True)
    ok = isinstance(b, dict) and b.get("success")
    print(f"📥 Import {fname}: HTTP {s} success={ok} "
          f"({b.get('successCount') if isinstance(b, dict) else b})")

import_saved_objects(os.path.join(ROOT, "kibana", "data-view-weblogs.ndjson"))
import_saved_objects(os.path.join(ROOT, "kibana", "soc-saved-searches.ndjson"))

# Dashboard con visualizaciones Lens (se regenera por si cambia la version)
try:
    import subprocess
    subprocess.run([sys.executable, os.path.join(HERE, "build_dashboard.py")],
                   check=True, capture_output=True)
except Exception as e:
    print("   (no pude regenerar el dashboard:", e, ")")
import_saved_objects(os.path.join(ROOT, "kibana", "soc-dashboard.ndjson"))

# ---------- 4) limpiar provisioning previo (idempotencia) ----------
KH = {"kbn-xsrf": "true"}
print("🧹 Limpiando reglas/conectores previos del lab ...")
s, b = try_req("GET", f"{KB}/api/alerting/rules/_find?per_page=200", headers=KH)
if isinstance(b, dict):
    for rule in b.get("data", []):
        if "soc-lab" in (rule.get("tags") or []):
            try_req("DELETE", f"{KB}/api/alerting/rule/{rule['id']}", headers=KH)
s, b = try_req("GET", f"{KB}/api/actions/connectors", headers=KH)
if isinstance(b, list):
    for c in b:
        if c.get("name") == "SOC Alert Index":
            try_req("DELETE", f"{KB}/api/actions/connector/{c['id']}", headers=KH)

# ---------- 5) connector Index -> soc-alerts ----------
print("🔌 Creando connector 'SOC Alert Index' -> indice soc-alerts ...")
s, b = try_req("POST", f"{KB}/api/actions/connector", headers=KH, data={
    "name": "SOC Alert Index",
    "connector_type_id": ".index",
    "config": {"index": "soc-alerts", "refresh": True},
})
if not (isinstance(b, dict) and b.get("id")):
    sys.exit(f"No pude crear el connector: {s} {b}")
CID = b["id"]
print("   connector id:", CID)

# ---------- 6) reglas de deteccion ----------
def rule(name, query, threshold, severity, comparator=">"):
    body = {
        "name": name,
        "rule_type_id": ".es-query",
        "consumer": "stackAlerts",
        "tags": ["soc-lab"],
        "schedule": {"interval": "30s"},
        "params": {
            "searchType": "esQuery",
            "timeField": "@timestamp",
            "index": ["weblogs-*"],
            "esQuery": json.dumps({"query": query}),
            "size": 100,
            "threshold": [threshold],
            "thresholdComparator": comparator,
            "timeWindowSize": 5,
            "timeWindowUnit": "m",
        },
        "actions": [{
            "group": "query matched",
            "id": CID,
            "params": {"documents": [{
                "@timestamp": "{{date}}",
                "rule": "{{rule.name}}",
                "severity": severity,
                "count": "{{context.value}}",
                "summary": "{{context.message}}",
            }]},
            "frequency": {"notify_when": "onActiveAlert", "throttle": None, "summary": False},
        }],
    }
    s, b = try_req("POST", f"{KB}/api/alerting/rule", headers=KH, data=body)
    ok = isinstance(b, dict) and b.get("id")
    print(f"🚨 Regla '{name}': {'creada' if ok else 'ERROR'} "
          f"{'' if ok else (str(s)+' '+str(b))}")

print("🚨 Creando reglas de deteccion ...")
rule("SQLi attempt detected",        {"term": {"tags": "sqli_pattern"}},      0,  "critical")
rule("LFI / path traversal attempt", {"term": {"tags": "lfi_pattern"}},       0,  "high")
rule("XSS attempt detected",         {"term": {"tags": "xss_pattern"}},       0,  "high")
rule("Scanner / recon tool detected",{"term": {"tags": "scanner_ua"}},        3,  "medium")
rule("Directory brute-force (404)",  {"term": {"response": 404}},             15, "medium")
rule("Server error spike (5xx)",     {"range": {"response": {"gte": 500}}},   3,  "high")
rule("Login brute-force (401)",      {"term": {"tags": "auth_failed"}},       5,  "high")

print("\n✅ SOC provisionado. Reglas activas cada 30s; ventana de 5 min.")
print("   Feed de alertas -> indice 'soc-alerts' (data view 'soc-alerts' en Kibana).")
print("   Reglas en: Kibana -> Stack Management -> Alerts/Rules.")
