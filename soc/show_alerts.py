#!/usr/bin/env python3
# Muestra las ultimas alertas del feed SOC (indice soc-alerts).
import json, os, sys, urllib.request, urllib.error

ES = os.environ.get("ES_URL", "http://localhost:9200")
try:
    url = f"{ES}/soc-alerts/_search?size=25&sort=@timestamp:desc"
    with urllib.request.urlopen(url, timeout=15) as r:
        data = json.load(r)
except urllib.error.HTTPError:
    print("  (el indice 'soc-alerts' aun no existe — lanza el ataque y espera ~1 min)")
    sys.exit(0)
except Exception as e:
    print("  (no pude consultar Elasticsearch:", e, ")")
    sys.exit(0)

hits = data.get("hits", {}).get("hits", [])
if not hits:
    print("  (sin alertas todavia — las reglas evaluan cada 30s)")
    sys.exit(0)

print(f"  {'SEVERIDAD':10} {'REGLA':34} {'COUNT':>6}  CUANDO")
print("  " + "-" * 72)
for h in hits:
    s = h.get("_source", {})
    print(f"  {str(s.get('severity','?')).upper():10} "
          f"{str(s.get('rule','?')):34} "
          f"{str(s.get('count','?')):>6}  {s.get('@timestamp','')}")
