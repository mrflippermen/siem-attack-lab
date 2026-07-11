<?php
require 'partials.php';
require 'config.php';

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    store_header('Panel', '');
    echo '<div class="panel empty"><span class="empty__ic">' . icon('lock', 28) . '</span>';
    echo '<p class="empty__title">Acceso restringido</p>';
    echo '<p class="muted">Debes iniciar sesion como administrador para ver el panel.</p>';
    echo '<a class="btn btn--solid" href="login.php" style="margin-top:1rem">'
       . icon('user', 16) . ' Ir al acceso</a></div>';
    store_footer();
    exit;
}

$user = htmlspecialchars((string)($_SESSION['user'] ?? 'admin'));
$user_flag = trim((string)@file_get_contents(dirname(__DIR__) . '/user_flag.txt'));

store_header('Panel de administracion', '');
?>
<div class="section__head"><div>
  <h1>Panel de administracion</h1>
  <p class="muted">Sesion iniciada como <b><?= $user ?></b> · rol superadmin</p>
</div></div>

<?php if ($user_flag !== ''): ?>
<div class="alert alert--ok" role="status" style="margin-bottom:1.5rem">
  <?= icon('check', 18) ?>
  <div>Sesion de administrador verificada · token de acceso:
    <code style="color:var(--brand);font-weight:700"><?= htmlspecialchars($user_flag) ?></code></div>
</div>
<?php endif; ?>

<div class="grid-cats" style="margin-bottom:1.5rem">
  <div class="cat-tile"><span class="cat-tile__ic"><?= icon('box', 20) ?></span> Catalogo <small>4 productos activos</small></div>
  <div class="cat-tile"><span class="cat-tile__ic"><?= icon('user', 20) ?></span> Clientes <small>7 registros</small></div>
  <div class="cat-tile"><span class="cat-tile__ic"><?= icon('card', 20) ?></span> Pedidos <small>tiempo real</small></div>
  <div class="cat-tile"><span class="cat-tile__ic"><?= icon('shield', 20) ?></span> Seguridad <small>activa</small></div>
</div>

<div class="panel">
  <h2><?= icon('truck', 18) ?> Importador de imagenes de producto</h2>
  <p class="muted">Sube la foto de un producto (JPG/PNG/GIF, max 200&nbsp;KB). El importador
     valida el contenido con la firma interna del backend.</p>
  <form method="post" action="upload.php" enctype="multipart/form-data" style="margin-top:1rem">
    <input type="hidden" name="token" value="<?= htmlspecialchars(UPLOAD_TOKEN) ?>">
    <div class="field">
      <label for="productImage">Imagen del producto</label>
      <input class="input" id="productImage" type="file" name="productImage" accept="image/*">
    </div>
    <button class="btn btn--solid btn--lg" type="submit"><?= icon('arrow', 16) ?> Importar imagen</button>
  </form>
</div>

<?php store_footer(); ?>
