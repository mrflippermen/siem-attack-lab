# Capítulo 5 — SOC: Detección y Alerting

Hasta ahora teníamos *visibilidad* (dashboards). Un SOC real necesita
**detección automática y notificación**. Este capítulo añade el motor de
alertas que convierte el laboratorio en un mini-SOC.

## 5.1 Arquitectura de detección

```
 weblogs-*  ──▶  Reglas .es-query (Kibana Alerting, cada 30s)
 (eventos)        │   evalúan una consulta sobre los últimos 5 min
                  │   y comparan el conteo contra un umbral
                  ▼
            Connector "Index"  ──▶  índice  soc-alerts
                                         │
                                         ▼
                          Data view 'soc-alerts' en Kibana
                          (el "feed" del analista) + 'make alerts'
```

## 5.2 Las reglas de detección

Provisionadas automáticamente por `soc/provision.py`:

| Regla | Condición (sobre 5 min) | Severidad |
|-------|-------------------------|-----------|
| SQLi attempt detected | `tags:sqli_pattern` > 0 | critical |
| LFI / path traversal attempt | `tags:lfi_pattern` > 0 | high |
| XSS attempt detected | `tags:xss_pattern` > 0 | high |
| Scanner / recon tool detected | `tags:scanner_ua` > 3 | medium |
| Directory brute-force (404) | `response:404` > 15 | medium |
| Server error spike (5xx) | `response>=500` > 3 | high |
| Login brute-force (401) | `tags:auth_failed` > 5 | high |

Cada regla, al dispararse, escribe un documento en `soc-alerts` con:
`rule`, `severity`, `count`, `summary`, `@timestamp`.

## 5.3 Cómo se crean (API de Kibana)

Todo es código (no clicks), vía la API de alerting. Resumen de lo que hace
`provision.py`:

1. **Connector** (`POST /api/actions/connector`) de tipo `.index` → `soc-alerts`.
2. **Reglas** (`POST /api/alerting/rule`) de tipo `.es-query`, cada una con su
   consulta, umbral, ventana de 5 min e intervalo de 30 s, y una acción que usa
   el connector anterior.

Es **idempotente**: al re-ejecutarse borra primero lo etiquetado con `soc-lab`.

## 5.4 Ver las alertas

Tres formas:

```bash
# a) Desde la terminal (resumen rápido)
make alerts

# b) En Kibana -> Discover -> data view 'soc-alerts'

# c) Estado de las reglas:
#    Kibana -> Stack Management -> Alerts and Insights -> Rules
```

## 5.5 De la alerta a la respuesta (siguiente nivel)

El connector `.index` deja el rastro en Elasticsearch. Para un SOC productivo se
añadirían connectors de notificación/acción:

- **Webhook / Slack / Email**: avisar al analista de guardia.
- **Index + Watcher externo**: un script que lea `soc-alerts` y aplique
  `iptables`/`fail2ban` sobre la `clientip` ofensora (respuesta automática).

> Para añadir un webhook: crea un connector `.webhook` apuntando a tu receptor y
> añádelo como segunda acción en cada regla (mismo patrón que el `.index`).

## 5.6 Silenciar / ajustar

- Las reglas disparan cada 30 s mientras haya coincidencias en la ventana de
  5 min (puede generar varias alertas por incidente — es intencional para ver
  el flujo en vivo).
- Para reducir ruido: en cada regla, sube `timeWindow`/`schedule` o cambia la
  acción a `notify_when: onActionGroupChange` (solo al activarse).
- Para desactivar todo: Kibana → Rules → seleccionar → **Disable**, o
  `make clean` para borrar el entorno.
