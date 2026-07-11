# Capítulo 1 — Configuración de la Infraestructura

## 1.1 Objetivo del capítulo
Levantar, con un único `docker compose up`, un entorno que contiene:
una aplicación web vulnerable, su base de datos, un SIEM completo (ELK) y
una caja atacante. Todo en una red de laboratorio aislada (`lab-net`).

## 1.2 Componentes y por qué están

| Componente | Imagen | Justificación |
|------------|--------|---------------|
| `web` | `php:8.1-apache` (build) | Sirve la app con SQLi/LFI y **escribe** `access.log`/`error.log`. |
| `db`  | `mysql:8.0` | Backend con la tabla `users` → contiene el secreto a exfiltrar. |
| `elasticsearch` | `elasticsearch:8.13.4` | Índice y motor de búsqueda del SIEM. |
| `logstash` | `logstash:8.13.4` | Convierte la **línea de log cruda** en campos estructurados. |
| `filebeat` | `filebeat:8.13.4` | **Lee el log en tiempo real** desde el volumen compartido. |
| `kibana` | `kibana:8.13.4` | Dashboards y exploración (Discover). |
| `attacker` | `kalilinux/kali-rolling` (build) | nmap, sqlmap, nikto, gobuster, hydra. |

## 1.3 La pieza clave: el "sistema de logs pro"

El requisito de "logs investigables en tiempo real" se cumple con un
**volumen compartido** (`weblogs`):

```yaml
volumes:
  weblogs:                     # volumen nombrado

services:
  web:
    volumes:
      - weblogs:/var/log/apache2          # Apache ESCRIBE aquí
  filebeat:
    volumes:
      - weblogs:/var/log/apache2:ro       # Filebeat LEE (solo lectura)
```

Flujo de datos:

```
Apache  ──escribe──▶  /var/log/apache2/access.log  (volumen weblogs)
                                  │
Filebeat ──tail -f──▶ envía cada línea nueva ──▶ Logstash:5044
                                                     │ grok + filtros
                                                     ▼
                                            Elasticsearch:9200
                                                     ▲
                                             Kibana:5601 (visualiza)
```

> **Por qué Filebeat además del volumen:** el volumen comparte el *fichero*,
> pero alguien tiene que *seguirlo* (`tail -f`) y empujar cada línea nueva al
> pipeline. Ese es el trabajo de Filebeat: harvesting con control de offset,
> de modo que ninguna línea se relee ni se pierde tras un reinicio.

## 1.4 Formato de log elegido (y por qué importa)

`apache-vhost.conf` fuerza el formato **combined**:

```
LogFormat "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" combined
```

El campo `%{User-Agent}i` es el que delata a los escáneres: sqlmap, nikto o
nmap se anuncian en su User-Agent salvo que se camuflen. Sin `combined` no
tendríamos ese dato.

## 1.5 Comandos de arranque

```bash
sudo sysctl -w vm.max_map_count=262144      # Elasticsearch lo exige
docker compose up -d --build
docker compose ps                            # todos "running"/"healthy"
```

Verificación rápida de que el log llega al SIEM:

```bash
# Genera una visita
curl -s localhost:8080/ >/dev/null
# Debe aparecer el índice del día
curl -s 'localhost:9200/_cat/indices/weblogs-*?v'
```

## 1.6 Notas de configuración

- **Seguridad de ELK desactivada** (`xpack.security.enabled=false`) a propósito,
  para que el lab arranque sin gestionar certificados ni contraseñas. **No
  hacer esto en producción.**
- **Memoria:** Elasticsearch usa 1 GB de heap (`ES_JAVA_MEM`). Si tu host tiene
  poca RAM, bájalo a `512m` en `.env`.
- **Persistencia:** los datos de ES viven en el volumen `esdata`; `down -v` los
  borra.
