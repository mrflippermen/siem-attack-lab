# Capítulo 3 — Análisis Forense

Comparamos el **log crudo** (lo que ve el administrador sin herramientas) con
la **visualización estructurada** en Kibana (lo que ve un analista SOC).

## 3.1 El log crudo

```bash
docker compose exec web tail -n 20 /var/log/apache2/access.log
```

Ejemplo de líneas tras el ataque:

```
172.18.0.8 - - [23/Jun/2026:10:41:02 +0000] "GET /product.php?id=1' HTTP/1.1" 500 612 "-" "curl/8.5.0"
172.18.0.8 - - [23/Jun/2026:10:41:05 +0000] "GET /product.php?id=-1%20UNION%20SELECT%20id,username,password,role%20FROM%20users--%20- HTTP/1.1" 200 980 "-" "sqlmap/1.8#stable"
172.18.0.8 - - [23/Jun/2026:10:41:09 +0000] "GET /view.php?page=../../../../etc/passwd HTTP/1.1" 200 1450 "-" "curl/8.5.0"
172.18.0.8 - - [23/Jun/2026:10:41:12 +0000] "GET /backup.zip HTTP/1.1" 404 488 "-" "gobuster/3.6"
```

**Problema:** en un servidor real esto está mezclado con miles de líneas
legítimas. Buscar el ataque a ojo es inviable. Ahí entra el SIEM.

## 3.2 Cómo Logstash lo transforma

La misma línea de sqlmap, tras pasar por el pipeline (`logstash/pipeline/apache.conf`),
se convierte en un documento JSON:

```json
{
  "@timestamp": "2026-06-23T10:41:05.000Z",
  "clientip": "172.18.0.8",
  "verb": "GET",
  "request": "/product.php?id=-1 UNION SELECT id,username,password,role FROM users-- -",
  "response": 200,
  "bytes": 980,
  "agent": "sqlmap/1.8#stable",
  "ua": { "name": "Other", "version": null },
  "tags": ["scanner_ua", "sqli_pattern", "suspicious"]
}
```

Las etiquetas (`tags`) las añaden las heurísticas del pipeline:
- `scanner_ua` → User-Agent coincide con sqlmap/nikto/nmap/gobuster…
- `sqli_pattern` → la petición contiene `UNION SELECT`, `information_schema`, `OR 1=1`…
- `lfi_pattern` → `../`, `/etc/passwd`, `php://`…
- `server_error` (500) / `not_found` (404).

## 3.3 Crear el data view en Kibana

1. **Stack Management → Saved Objects → Import** → `kibana/data-view-weblogs.ndjson`.
2. **Discover** → selecciona `weblogs-*` → ajusta el rango de tiempo a "Last 1 hour".

## 3.4 Construir el Dashboard

Crea las siguientes visualizaciones (**Dashboard → Create → Add panel → Lens**)
y guárdalas en un dashboard llamado **"Web Attack Monitoring"**:

| # | Visualización | Tipo | Configuración |
|---|---------------|------|---------------|
| 1 | **Peticiones en el tiempo** | Bar (date histogram) | Eje X: `@timestamp`; desglose por `tags`. Un pico = un ataque. |
| 2 | **Top IPs de origen** | Tabla / Pie | `clientip` (Top 10) por `Count`. Rastrea al atacante. |
| 3 | **Códigos de estado HTTP** | Bar apilada | `response` por `Count`. Detecta picos de 404 (recon) y 500 (SQLi rota). |
| 4 | **User-Agents sospechosos** | Tabla | `agent` filtrado por `tags : "scanner_ua"`. Delata sqlmap/nikto. |
| 5 | **Mapa de URLs atacadas** | Tabla | `request` filtrado por `tags : "suspicious"`. |

### Filtros (KQL) para el panel de control

| Pregunta del analista | Filtro KQL |
|-----------------------|-----------|
| ¿Quién me está escaneando? | `tags : "scanner_ua"` |
| ¿Hay intentos de inyección SQL? | `tags : "sqli_pattern"` |
| ¿Intentos de leer archivos del sistema? | `tags : "lfi_pattern"` |
| ¿Errores que indican inyección rompiendo el backend? | `response >= 500` |
| ¿Fuerza bruta de directorios? | `response : 404` |
| Rastrear una IP concreta | `clientip : "172.18.0.8"` |

## 3.5 La comparación (lo que pide el capítulo)

| | Log crudo (`access.log`) | Dashboard de Kibana |
|---|---|---|
| Volumen | Miles de líneas planas | Filtrado y agregado |
| Detección | Manual, a ojo | Etiquetas automáticas (`suspicious`) |
| Correlación | Imposible | Por IP, por User-Agent, por patrón |
| Tiempo real | `tail -f` ciego | Histograma temporal, alertable |
| Rastreo del atacante | grep de la IP | Click en la IP → todo su recorrido |

> **Captura sugerida para el informe:** pon lado a lado el `tail` del paso 3.1
> y el panel "User-Agents sospechosos" mostrando la fila `sqlmap/1.8`.
