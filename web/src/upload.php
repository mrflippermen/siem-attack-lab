<?php
// Product image importer (admin only).
require 'partials.php';
require 'config.php';

header('Content-Type: text/plain; charset=utf-8');

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo "403 · Acceso restringido al panel de administracion.\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Importador de imagenes de producto. Envia un POST multipart con "
       . "los campos 'token' y 'productImage'.\n";
    exit;
}

$token = $_POST['token'] ?? '';
if (!hash_equals(UPLOAD_TOKEN, (string)$token)) {
    http_response_code(403);
    echo "403 · Firma de importador invalida (campo 'token').\n";
    error_log('[upload] firma invalida desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit;
}

if (!isset($_FILES['productImage']) || $_FILES['productImage']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo "400 · Falta el fichero 'productImage'.\n";
    exit;
}
$f    = $_FILES['productImage'];
$name = basename($f['name']);
$tmp  = $f['tmp_name'];

if ($f['size'] > 200 * 1024) {
    http_response_code(413); echo "413 · Imagen demasiado grande (max 200KB).\n"; exit;
}

$ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
$deny = ['php', 'php3', 'php4', 'php5', 'php7', 'pht', 'phps', 'htaccess', 'cgi'];
if (in_array($ext, $deny, true)) {
    http_response_code(415);
    echo "415 · Extension no permitida: .$ext\n";
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmp) ?: 'application/octet-stream';
finfo_close($finfo);
if (strncmp($mime, 'image/', 6) !== 0) {
    http_response_code(415);
    echo "415 · El contenido no es una imagen (MIME: $mime).\n";
    exit;
}

if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0775, true); }
$dest = UPLOAD_DIR . '/' . $name;
if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500); echo "500 · No se pudo guardar la imagen.\n"; exit;
}

$url = 'uploads/' . rawurlencode($name);
echo "200 · Imagen '$name' importada correctamente (MIME $mime).\n";
echo "URL: /$url\n";
error_log("[upload] guardado uploads/$name mime=$mime por admin");
