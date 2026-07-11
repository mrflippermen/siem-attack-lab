<?php
// =====================================================================
//  Carrito de la compra (bolsa). Estado en sesion PHP.
//  Acciones POST (add/update/remove) -> patron PRG (redirige tras actuar).
// =====================================================================
require 'partials.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'add' && $id > 0) {
        cart_add($id, max(1, (int)($_POST['qty'] ?? 1)));
    } elseif ($action === 'remove' && $id > 0) {
        cart_remove($id);
    } elseif ($action === 'update') {
        foreach (($_POST['qty'] ?? []) as $pid => $q) { cart_set((int)$pid, (int)$q); }
    } elseif ($action === 'clear') {
        $_SESSION['cart'] = [];
    }
    header('Location: cart.php');
    exit;
}

$lines    = cart_lines();
$subtotal = cart_subtotal();
$ship     = shipping_cost($subtotal);
$total    = $subtotal + $ship;

store_header('Carrito', 'cart');
?>

<nav class="crumbs" aria-label="Ruta">
  <a href="index.php">Inicio</a> <?= icon('arrow', 13) ?>
  <span aria-current="page">Cesta</span>
</nav>

<div class="section__head">
  <div>
    <h2>Tu cesta</h2>
    <p class="muted"><?= $lines ? count($lines).' artículo(s) en la cesta.' : 'Tu cesta está vacía por ahora.' ?></p>
  </div>
  <a class="btn btn--ghost" href="index.php#catalogo"><?= icon('aleft', 16) ?> Seguir comprando</a>
</div>

<?php if (!$lines): ?>
  <div class="panel empty">
    <span class="empty__ic"><?= icon('cart', 30) ?></span>
    <p class="empty__title">Aún no has añadido nada</p>
    <p class="muted">Explora el catálogo y añade tus herramientas favoritas a la cesta.</p>
    <a class="btn btn--primary" href="index.php#catalogo" style="margin-top:1.1rem"><?= icon('box', 16) ?> Ver catálogo</a>
  </div>
<?php else: ?>
  <div class="bag">
   <div class="bag__main">
    <form method="post" class="bag__items">
      <input type="hidden" name="action" value="update">
      <?php foreach ($lines as $l): ?>
      <div class="bag-item">
        <span class="bag-item__media"><?= product_icon($l['name'], 30) ?></span>
        <div class="bag-item__info">
          <a class="bag-item__name" href="product.php?id=<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></a>
          <span class="muted"><?= eur($l['price']) ?> / unidad</span>
        </div>
        <div class="qty" role="group" aria-label="Cantidad">
          <button class="qty__btn" type="submit" name="qty[<?= $l['id'] ?>]" value="<?= $l['qty']-1 ?>" aria-label="Quitar uno"><?= icon('minus', 15) ?></button>
          <input class="qty__val" type="text" inputmode="numeric" name="qty[<?= $l['id'] ?>]" value="<?= $l['qty'] ?>" aria-label="Cantidad">
          <button class="qty__btn" type="submit" name="qty[<?= $l['id'] ?>]" value="<?= $l['qty']+1 ?>" aria-label="Añadir uno"><?= icon('plus', 15) ?></button>
        </div>
        <span class="bag-item__total"><?= eur($l['total']) ?></span>
      </div>
      <?php endforeach; ?>
      <div class="bag__bar">
        <button class="btn btn--ghost btn--sm" type="submit">Actualizar cesta</button>
      </div>
    </form>

    <form method="post" class="bag__items" style="border:0;background:transparent;padding:0;margin-top:-0.5rem">
      <input type="hidden" name="action" value="clear">
      <button class="link-danger" type="submit"><?= icon('trash', 15) ?> Vaciar cesta</button>
    </form>
   </div>

    <aside class="summary">
      <h3>Resumen del pedido</h3>
      <div class="summary__row"><span>Subtotal</span><b><?= eur($subtotal) ?></b></div>
      <div class="summary__row"><span>Envío</span><b><?= $ship == 0 ? 'Gratis' : eur($ship) ?></b></div>
      <?php if ($ship > 0): ?>
        <p class="summary__hint"><?= icon('truck', 14) ?> Te faltan <?= eur(50 - $subtotal) ?> para el envío gratis.</p>
      <?php endif; ?>
      <div class="summary__row summary__total"><span>Total</span><b><?= eur($total) ?></b></div>
      <a class="btn btn--primary btn--block btn--lg" href="checkout.php"><?= icon('lock2', 17) ?> Finalizar compra</a>
      <p class="summary__safe"><?= icon('shield', 13) ?> Pago seguro · devolución en 30 días</p>
    </aside>
  </div>
<?php endif; ?>

<?php store_footer(); ?>
