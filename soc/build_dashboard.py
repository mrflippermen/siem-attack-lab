#!/usr/bin/env python3
# =====================================================================
#  Genera kibana/soc-dashboard.ndjson: 3 visualizaciones Lens + 2 saved
#  searches embebidas en un dashboard "SOC — Web Attack Monitoring".
#  Construido con json.dumps para evitar errores de escapado a mano.
# =====================================================================
import json, os

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(os.path.dirname(HERE), "kibana", "soc-dashboard.ndjson")
DV = "weblogs-data-view"
VER = "8.13.4"

def col_count():
    return {"label": "Count", "dataType": "number", "operationType": "count",
            "sourceField": "___records___", "isBucketed": False, "scale": "ratio"}

def col_date():
    return {"label": "@timestamp", "dataType": "date", "operationType": "date_histogram",
            "sourceField": "@timestamp", "isBucketed": True, "scale": "interval",
            "params": {"interval": "auto"}}

def col_terms(field, size=10):
    return {"label": f"Top {field}", "dataType": "string", "operationType": "terms",
            "sourceField": field, "isBucketed": True, "scale": "ordinal",
            "params": {"size": size, "orderBy": {"type": "column", "columnId": "c_count"},
                       "orderDirection": "desc", "otherBucket": True, "missingBucket": False}}

def lens_obj(oid, title, vis_type, columns, col_order, visualization, query=""):
    return {
        "id": oid, "type": "lens", "version": "1",
        # Marca el objeto como ya-migrado a 8.13 para que Kibana NO ejecute
        # migraciones legacy (que esperan la estructura 'indexpattern' antigua).
        "coreMigrationVersion": "8.13.4", "typeMigrationVersion": "8.9.0",
        "attributes": {
            "title": title, "visualizationType": vis_type,
            "state": {
                "datasourceStates": {"formBased": {"layers": {"layer1": {
                    "columns": columns, "columnOrder": col_order, "incompleteColumns": {}}}}},
                "visualization": visualization,
                "query": {"query": query, "language": "kuery"},
                "filters": [],
            },
        },
        "references": [{"type": "index-pattern", "id": DV,
                        "name": "indexpattern-datasource-layer-layer1"}],
    }

# --- A) Codigos de estado HTTP en el tiempo (barra apilada) ---
A = lens_obj(
    "soc-lens-status", "Codigos de estado HTTP en el tiempo", "lnsXY",
    {"c_time": col_date(), "c_split": col_terms("response", 6), "c_count": col_count()},
    ["c_time", "c_split", "c_count"],
    {"legend": {"isVisible": True, "position": "right"},
     "preferredSeriesType": "bar_stacked", "valueLabels": "hide",
     "layers": [{"layerId": "layer1", "layerType": "data", "seriesType": "bar_stacked",
                 "xAccessor": "c_time", "splitAccessor": "c_split", "accessors": ["c_count"]}]},
)

# --- B) Top IPs de origen (tabla) ---
B = lens_obj(
    "soc-lens-topip", "Top IPs de origen (atacantes)", "lnsDatatable",
    {"c_ip": col_terms("clientip", 10), "c_count": col_count()},
    ["c_ip", "c_count"],
    {"layerId": "layer1", "layerType": "data",
     "columns": [{"columnId": "c_ip"}, {"columnId": "c_count"}]},
)

# --- C) User-Agents sospechosos (tabla, filtrado a suspicious) ---
C = lens_obj(
    "soc-lens-ua", "User-Agents sospechosos", "lnsDatatable",
    {"c_ua": col_terms("agent", 10), "c_count": col_count()},
    ["c_ua", "c_count"],
    {"layerId": "layer1", "layerType": "data",
     "columns": [{"columnId": "c_ua"}, {"columnId": "c_count"}]},
    query='tags : "suspicious"',
)

# --- Dashboard ---
def panel(idx, ptype, refname, x, y, w, h, title):
    return {"version": VER, "type": ptype, "panelIndex": str(idx),
            "gridData": {"x": x, "y": y, "w": w, "h": h, "i": str(idx)},
            "embeddableConfig": {"title": title}, "panelRefName": refname}

panels = [
    panel(1, "lens", "panel_1", 0, 0, 48, 12, "Codigos de estado HTTP en el tiempo"),
    panel(2, "lens", "panel_2", 0, 12, 16, 14, "Top IPs de origen"),
    panel(3, "lens", "panel_3", 16, 12, 16, 14, "User-Agents sospechosos"),
    panel(4, "search", "panel_4", 32, 12, 16, 14, "Intentos de SQL Injection"),
    panel(5, "search", "panel_5", 0, 26, 48, 11, "Trafico sospechoso (todo)"),
]
refs = [
    {"name": "panel_1", "type": "lens", "id": "soc-lens-status"},
    {"name": "panel_2", "type": "lens", "id": "soc-lens-topip"},
    {"name": "panel_3", "type": "lens", "id": "soc-lens-ua"},
    {"name": "panel_4", "type": "search", "id": "soc-search-sqli"},
    {"name": "panel_5", "type": "search", "id": "soc-search-suspicious"},
]
dashboard = {
    "id": "soc-dashboard-web-attack", "type": "dashboard", "version": "1",
    "attributes": {
        "title": "SOC — Web Attack Monitoring",
        "description": "Panel de monitoreo de ataques web del laboratorio",
        "timeRestore": True, "timeFrom": "now-1h", "timeTo": "now",
        "optionsJSON": json.dumps({"useMargins": True, "syncColors": False, "hidePanelTitles": False}),
        "panelsJSON": json.dumps(panels),
        "kibanaSavedObjectMeta": {"searchSourceJSON": json.dumps(
            {"query": {"query": "", "language": "kuery"}, "filter": []})},
    },
    "references": refs,
}

with open(OUT, "w") as f:
    for o in [A, B, C, dashboard]:
        f.write(json.dumps(o) + "\n")
    f.write(json.dumps({"exportedCount": 4, "missingRefCount": 0, "missingReferences": []}) + "\n")

print("escrito:", OUT)
