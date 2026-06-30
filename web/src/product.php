<?php
// =====================================================================
//  VULNERABILIDAD 1:  SQL INJECTION  (parametro 'id' sin sanitizar)
// =====================================================================
//  La entrada del usuario se concatena DIRECTAMENTE en la consulta y
//  permite UNION SELECT para volcar otras tablas. La consulta vulnerable
//  y el codigo de estado 500 ante un error se CONSERVAN (el SIEM detecta
//  por status + patrones de URL). Lo unico que se ha quitado es el
//  detalle tecnico en pantalla (query, motor de BD, nombres de columnas):
//  el target ya no filtra de que fichero/tabla/BD procede la informacion.
//
//  Forma CORRECTA (comentada):
//      $stmt = $conn->prepare("SELECT ... WHERE id = ?");
//      $stmt->bind_param("i", $_GET['id']);
// =====================================================================
require 'partials.php';

$id = $_GET['id'] ?? '1';

// !!! Concatenacion insegura a proposito !!!
$sql = "SELECT id, name, description, price FROM products WHERE id = $id";

$res = mysqli_query(db(), $sql);

$failed = !$res;
if ($failed) {
    http_response_code(500);   // se conserva: lo consume la deteccion del SIEM
}

$rows = [];
while ($res && $row = mysqli_fetch_assoc($res)) { $rows[] = $row; }

store_header('Producto', 'shop');
?>

<nav class="crumbs" aria-label="Ruta">
  <a href="index.php">Inicio</a> <?= icon('arrow', 13) ?>
  <a href="index.php#catalogo">Catálogo</a> <?= icon('arrow', 13) ?>
  <span aria-current="page">Detalle</span>
</nav>

<?php if ($failed): ?>
  <div class="panel" style="text-align:center">
    <span class="empty__ic" style="color:var(--danger)"><?= icon('box', 28) ?></span>
    <p class="empty__title">No se pudo cargar el producto</p>
    <p class="muted" style="margin-top:0.3rem">Ha ocurrido un problema temporal. Inténtalo de nuevo en unos minutos.</p>
    <a class="btn btn--solid" href="index.php" style="margin-top:1.1rem"><?= icon('aleft', 16) ?> Volver al catálogo</a>
  </div>

<?php elseif (count($rows) === 1):
  $r     = $rows[0];
  $name  = htmlspecialchars((string)($r['name'] ?? ''));
  $desc  = htmlspecialchars((string)($r['description'] ?? ''));
  $price = isset($r['price']) && is_numeric($r['price']) ? eur((float)$r['price']) : htmlspecialchars((string)($r['price'] ?? '—'));
?>
  <article class="pdp">
    <div class="pdp__media"><?= product_icon((string)($r['name'] ?? ''), 96) ?></div>
    <div>
      <span class="eyebrow"><?= icon('tag', 14) ?> Marca ACME · garantía 3 años</span>
      <h1 class="pdp__title"><?= $name !== '' ? $name : 'Producto' ?></h1>
      <div class="pdp__price"><?= $price ?> <small>IVA incluido</small></div>
      <p class="pdp__desc"><?= $desc !== '' ? $desc : 'Sin descripción disponible.' ?></p>

      <form method="post" action="cart.php" class="pdp__buy">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="id" value="<?= htmlspecialchars((string)($r['id'] ?? '')) ?>">
        <div class="qty" role="group" aria-label="Cantidad">
          <button class="qty__btn" type="button" data-step="-1" aria-label="Quitar uno"><?= icon('minus', 15) ?></button>
          <input class="qty__val" id="buyQty" type="text" inputmode="numeric" name="qty" value="1" aria-label="Cantidad">
          <button class="qty__btn" type="button" data-step="1" aria-label="Añadir uno"><?= icon('plus', 15) ?></button>
        </div>
        <button class="btn btn--primary btn--lg" type="submit"><?= icon('cart', 17) ?> Añadir a la cesta</button>
      </form>

      <p class="stock">En stock · envío en 24&nbsp;h</p>

      <ul class="features">
        <li><?= icon('truck', 17) ?> Envío gratis a partir de 50&nbsp;€</li>
        <li><?= icon('shield', 17) ?> Garantía oficial de 3 años</li>
        <li><?= icon('aleft', 17) ?> Devolución gratuita en 30 días</li>
      </ul>
    </div>
  </article>

  <script>
    (function () {
      var q = document.getElementById('buyQty');
      document.querySelectorAll('.pdp__buy [data-step]').forEach(function (b) {
        b.addEventListener('click', function () {
          var n = Math.max(1, Math.min(99, (parseInt(q.value, 10) || 1) + parseInt(this.dataset.step, 10)));
          q.value = n;
        });
      });
    })();
  </script>

<?php elseif (count($rows) > 1): ?>
  <div class="section__head"><div><h2>Resultados</h2><p class="muted"><?= count($rows) ?> coincidencias.</p></div></div>
  <div class="grid-products">
    <?php foreach ($rows as $r):
      $name  = htmlspecialchars((string)($r['name'] ?? ''));
      $desc  = htmlspecialchars((string)($r['description'] ?? ''));
      $price = isset($r['price']) && is_numeric($r['price']) ? eur((float)$r['price']) : htmlspecialchars((string)($r['price'] ?? ''));
    ?>
    <div class="product-card">
      <div class="product-card__media"><?= product_icon((string)($r['name'] ?? ''), 56) ?></div>
      <div class="product-card__body">
        <div class="product-card__name"><?= $name !== '' ? $name : '—' ?></div>
        <p class="product-card__desc"><?= $desc ?></p>
        <div class="product-card__foot"><span class="price"><?= $price !== '' ? $price : '&nbsp;' ?></span></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

<?php else: ?>
  <div class="panel empty">
    <span class="empty__ic"><?= icon('box', 28) ?></span>
    <p class="empty__title">Producto no encontrado</p>
    <p class="muted">No existe ningún producto con ese identificador.</p>
    <a class="btn btn--solid" href="index.php" style="margin-top:1.1rem"><?= icon('aleft', 16) ?> Volver al catálogo</a>
  </div>
<?php endif; ?>

<?php store_footer(); ?>
