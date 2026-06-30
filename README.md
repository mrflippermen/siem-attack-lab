# 🛡️ Laboratorio: Web Vulnerable + SIEM (ELK) + Attacker Box

Entorno **100% dockerizado** para practicar la detección de ataques web mediante
análisis de logs en tiempo real. Combina un objetivo vulnerable (SQLi + LFI),
un stack ELK como SIEM, y una caja atacante con herramientas ofensivas.

> ⚠️ **Solo para uso educativo en entorno aislado.** La red `lab-net` no debe
> exponerse a Internet. Las vulnerabilidades son intencionadas.

---

## 🧱 Arquitectura

```
                         lab-net (bridge, aislada)
 ┌──────────┐   HTTP    ┌──────────────┐   SQL    ┌──────────┐
 │ attacker │ ───────▶  │   web        │ ───────▶ │   db     │
 │ (kali)   │           │ Apache+PHP   │          │ MySQL 8  │
 └──────────┘           │ :80 (→8080)  │          └──────────┘
                        └──────┬───────┘
                               │ access.log / error.log
                               ▼  (volumen COMPARTIDO 'weblogs')
                        ┌──────────────┐
                        │  filebeat    │  lee el log en tiempo real
                        └──────┬───────┘
                               ▼ :5044
                        ┌──────────────┐  grok + detección de escáneres,
                        │  logstash    │  firmas SQLi/LFI, geoip, user-agent
                        └──────┬───────┘
                               ▼ :9200
                        ┌──────────────┐      ┌──────────────┐
                        │elasticsearch │ ◀──▶ │   kibana     │ :5601
                        └──────────────┘      │  Dashboards  │
                                              └──────────────┘
```

| Servicio        | Rol                                   | Puerto host |
|-----------------|---------------------------------------|-------------|
| `web`           | Target vulnerable (Apache + PHP)      | 8080        |
| `db`            | MySQL con la credencial a exfiltrar   | —           |
| `elasticsearch` | Almacén del SIEM                      | 9200        |
| `logstash`      | Parseo del log crudo → estructurado   | 5044        |
| `filebeat`      | Cosecha el log del volumen compartido | —           |
| `kibana`        | Visualización / dashboards            | 5601        |
| `attacker`      | nmap, sqlmap, nikto, gobuster, hydra  | —           |

---

## ✅ Requisitos previos

Solo necesitas una máquina Linux con **~4 GB de RAM libre** y `git`.

El `Makefile` detecta automáticamente si faltan Docker, Compose, `make` o `python3`
y los instala sin intervención manual — soporta **Debian, Ubuntu, Kali, Parrot,
Fedora, RHEL, CentOS, Arch, openSUSE, Alpine** (y cualquier distro con el script
oficial de Docker como fallback).

```bash
make check        # si falta algo, lo instala solo
```

> 🔑 El único `sudo` que pide el lab es para `vm.max_map_count` (lo lanza `make soc`).
> Si no quieres meter tu usuario en el grupo `docker`, el Makefile usa `sg docker`
> automáticamente — no necesitas cerrar sesión ni hacer `newgrp`.

---

## 🚀 Puesta en marcha (un solo comando)

```bash
git clone https://github.com/mrflippermen/siem-attack-lab.git
cd siem-attack-lab
make soc          # check → host (sysctl) → up --build → provision  ← todo en uno
```

Si es la primera vez y no tienes Docker instalado, `make soc` lo detecta,
**instala automáticamente Docker + Compose + make + python3**, arranca el
demonio, ajusta los permisos con `sg docker` y continúa sin que hagas nada.

La **primera vez** tarda varios minutos (descarga las imágenes de ELK y Kali y
construye el target). `make soc` deja listo: contenedores arriba, plantilla de
índice aplicada, data views + saved searches + dashboard importados, y
**7 reglas de detección activas**.

- **Target vulnerable** → http://localhost:8080
- **Kibana**            → http://localhost:5601  (espera ~1 min a que cargue)

Equivalente manual (lo que hace `make soc` por dentro):
```bash
sudo sysctl -w vm.max_map_count=262144
docker compose up -d --build
python3 soc/provision.py
```

---

## 🎯 Test completo (un comando)

```bash
make test
```
Levanta el SOC, genera tráfico legítimo de fondo, lanza el ataque de 3 fases,
espera a que las reglas evalúen y muestra el conteo de eventos + el feed de
alertas. Para sólo atacar: `make attack`. Para ver alertas: `make alerts`.

---

## 🛠️ Comandos del Makefile

| Comando | Acción |
|---------|--------|
| `make check`   | verifica que Docker y Compose estén listos (los instala si faltan) |
| `make install` | instala Docker + Compose + make + python3 en cualquier distro |
| `make soc`     | check + host + up + provision (pipeline completo) |
| `make test`    | test end-to-end automatizado |
| `make attack`  | lanza el ataque de 3 fases |
| `make alerts`  | muestra el feed de alertas del SOC |
| `make status`  | salud del cluster + conteo de eventos suspicious |
| `make dashboard` | (re)genera e importa el dashboard de Kibana |
| `make web`     | abre la web vulnerable en el navegador |
| `make open`    | abre Kibana en el navegador |
| `make logs`    | sigue logs de logstash/filebeat |
| `make provision` | re-aplica plantilla, data views y reglas (idempotente) |
| `make down`    | detiene (conserva datos) |
| `make clean`   | detiene y borra volúmenes |

---

## 📊 Kibana

`make soc` ya deja Kibana **auto-configurado**: el data view `weblogs-*`, las
saved searches del analista y el dashboard **"SOC — Web Attack Monitoring"** se
importan solos. Solo tienes que abrir http://localhost:5601 y:

1. Menú ☰ → **Analytics → Dashboard** → **"SOC — Web Attack Monitoring"**.
2. O **Analytics → Discover** con el data view `weblogs-*` para explorar eventos.

> Para re-importar el dashboard a mano: `make dashboard`.
> Guía paso a paso con capturas: **`docs/06-guia-capturas.md`**.

### Filtros KQL listos para usar
```text
tags : "scanner_ua"                      # peticiones de sqlmap/nikto/nmap...
tags : "sqli_pattern"                    # firmas de inyección SQL
tags : "lfi_pattern"                     # path traversal / LFI
response >= 500                          # errores que delatan inyección
response : 404                           # ráfagas de fuerza bruta de dirs
clientip : "172.22.0.5"                  # rastrear una IP de origen concreta
ua.name : "Other" and tags : "suspicious"
```

---

## 🩺 Solución de problemas

| Síntoma | Causa / Solución |
|---------|------------------|
| `make: docker: No such file or directory` (Error 127) | Docker no está instalado. `make check` lo instala automáticamente, o ejecuta `make install`. |
| `Cannot connect to the Docker daemon` | El servicio no corre o faltan permisos. `make check` lo resuelve automáticamente con `sg docker`. Si persiste: `sudo systemctl enable --now docker`. |
| `Permission denied` al usar docker | El Makefile lo maneja solo con `sg docker`. Si ejecutas comandos a mano: `sudo usermod -aG docker $USER && newgrp docker`. |
| Kibana no carga (5601) | Tarda ~1 min en arrancar tras `make soc`. Mira `docker compose logs -f kibana`. |
| No salen eventos en Kibana | Lanza tráfico/ataque primero (`make attack`) y pon el rango de tiempo en *Last 15 minutes*. |
| Puerto 8080/5601/9200 ocupado | Cambia el mapeo de puertos en `docker-compose.yml` o libera el puerto. |
| Empezar de cero | `make clean` (borra datos) y luego `make soc`. |

---

## 🧹 Limpieza

```bash
make clean                  # = docker compose down -v (borra también los datos de ES)
```

---

## 📚 Documentación

| Capítulo | Archivo |
|----------|---------|
| 1. Infraestructura            | `docs/01-infraestructura.md` |
| 2. El vector de ataque        | `docs/02-vector-de-ataque.md` |
| 3. Análisis forense (Kibana)  | `docs/03-analisis-forense.md` |
| 4. Conclusión                 | `docs/04-conclusion.md` |
| 5. SOC: detección y alerting  | `docs/05-soc-alerting.md` |
| 6. Guía paso a paso para capturas | `docs/06-guia-capturas.md` |
