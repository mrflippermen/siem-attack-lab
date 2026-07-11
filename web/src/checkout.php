<?php
// Checkout: envio + metodo de pago + resumen.
require 'partials.php';

$lines    = cart_lines();
$subtotal = cart_subtotal();
$ship     = shipping_cost($subtotal);
$total    = $subtotal + $ship;

// Sin articulos no hay checkout.
if (!$lines && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

$placed   = false;
$orderId  = '';
$buyer    = ['name' => '', 'email' => '', 'city' => '', 'method' => 'card'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lines) {
    // Solo conservamos datos de envio (no de pago) para la confirmacion.
    $buyer['name']   = trim((string)($_POST['name'] ?? ''));
    $buyer['email']  = trim((string)($_POST['email'] ?? ''));
    $buyer['city']   = trim((string)($_POST['city'] ?? ''));
    $buyer['method'] = in_array(($_POST['method'] ?? ''), ['card','paypal','bizum'], true) ? $_POST['method'] : 'card';

    $placed  = true;
    $orderId = 'ACME-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    // Guardamos un snapshot para la pagina de confirmacion y vaciamos la cesta.
    $confirm = ['id' => $orderId, 'total' => $total, 'lines' => $lines,
                'buyer' => $buyer, 'date' => date('d/m/Y H:i')];
    $_SESSION['cart'] = [];
}

store_header($placed ? 'Pedido confirmado' : 'Pago', 'cart');

if ($placed):
    $method = ['card' => 'Tarjeta', 'paypal' => 'PayPal', 'bizum' => 'Bizum'][$buyer['method']];
?>
  <div class="order-done">
    <span class="order-done__ic"><?= icon('check', 34) ?></span>
    <h1>¡Gracias por tu compra!</h1>
    <p class="lede" style="margin-inline:auto">Hemos recibido tu pedido y te enviaremos la confirmación por correo. Recibirás tu paquete en 24-48&nbsp;h.</p>

    <div class="order-card">
      <div class="order-card__head">
        <div><span class="muted">Número de pedido</span><b><?= htmlspecialchars($orderId) ?></b></div>
        <div><span class="muted">Fecha</span><b><?= $confirm['date'] ?></b></div>
        <div><span class="muted">Pago</span><b><?= $method ?></b></div>
      </div>
      <ul class="order-card__lines">
        <?php foreach ($confirm['lines'] as $l): ?>
          <li><span><?= (int)$l['qty'] ?>× <?= htmlspecialchars($l['name']) ?></span><b><?= eur($l['total']) ?></b></li>
        <?php endforeach; ?>
      </ul>
      <div class="order-card__total"><span>Total pagado</span><b><?= eur($confirm['total']) ?></b></div>
      <?php if ($buyer['name'] !== '' || $buyer['city'] !== ''): ?>
        <p class="order-card__ship"><?= icon('pin', 14) ?> Envío a <?= htmlspecialchars(trim($buyer['name'].' · '.$buyer['city'], ' ·')) ?></p>
      <?php endif; ?>
    </div>

    <a class="btn btn--primary btn--lg" href="index.php#catalogo" style="margin-top:1.5rem"><?= icon('box', 17) ?> Seguir comprando</a>
  </div>

<?php else: ?>

  <nav class="crumbs" aria-label="Ruta">
    <a href="index.php">Inicio</a> <?= icon('arrow', 13) ?>
    <a href="cart.php">Cesta</a> <?= icon('arrow', 13) ?>
    <span aria-current="page">Pago</span>
  </nav>

  <div class="section__head"><div><h2>Finalizar compra</h2><p class="muted">Envío e información de pago.</p></div></div>

  <form method="post" class="checkout">
    <div class="checkout__main">

      <section class="panel">
        <h3 class="block-title"><span class="block-num">1</span> Datos de envío</h3>
        <div class="field"><label for="name">Nombre completo</label>
          <input class="input" id="name" name="name" placeholder="Nombre y apellidos" required></div>
        <div class="grid-2">
          <div class="field"><label for="email">Correo electrónico</label>
            <input class="input" id="email" name="email" type="email" placeholder="tu@correo.com" required></div>
          <div class="field"><label for="phone">Teléfono</label>
            <input class="input" id="phone" name="phone" inputmode="tel" placeholder="+34 600 000 000"></div>
        </div>
        <div class="field"><label for="addr">Dirección</label>
          <input class="input" id="addr" name="addr" placeholder="Calle, número, piso" required></div>
        <div class="grid-3">
          <div class="field"><label for="zip">Código postal</label><input class="input" id="zip" name="zip" inputmode="numeric" placeholder="28001"></div>
          <div class="field"><label for="city">Ciudad</label><input class="input" id="city" name="city" placeholder="Madrid"></div>
          <div class="field"><label for="prov">Provincia</label><input class="input" id="prov" name="prov" placeholder="Madrid"></div>
        </div>
      </section>

      <section class="panel">
        <h3 class="block-title"><span class="block-num">2</span> Método de pago</h3>
        <div class="pay-methods">
          <label class="pay-opt"><input type="radio" name="method" value="card" checked>
            <span class="pay-opt__body"><?= icon('card', 20) ?> <span>Tarjeta de crédito/débito</span><span class="pay-chips"><i class="pay-chip">VISA</i><i class="pay-chip">MC</i></span></span></label>
          <label class="pay-opt"><input type="radio" name="method" value="paypal">
            <span class="pay-opt__body"><?= icon('wallet', 20) ?> <span>PayPal</span></span></label>
          <label class="pay-opt"><input type="radio" name="method" value="bizum">
            <span class="pay-opt__body"><?= icon('spark', 20) ?> <span>Bizum</span></span></label>
        </div>

        <div class="card-fields" id="cardFields">
          <div class="field"><label for="cc">Número de tarjeta</label>
            <div class="input-icon"><?= icon('card', 18) ?>
              <input class="input" id="cc" name="cc_display" inputmode="numeric" autocomplete="off" placeholder="4111 1111 1111 1111" maxlength="19"></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="exp">Caducidad</label>
              <input class="input" id="exp" name="exp_display" inputmode="numeric" autocomplete="off" placeholder="MM/AA" maxlength="5"></div>
            <div class="field"><label for="cvv">CVV</label>
              <div class="input-icon"><?= icon('lock', 18) ?>
                <input class="input" id="cvv" name="cvv_display" inputmode="numeric" autocomplete="off" placeholder="123" maxlength="4"></div></div>
          </div>
          <p class="muted" style="font-size:0.8rem;display:flex;gap:0.4rem;align-items:center"><?= icon('lock2', 13) ?> Tus datos de pago se transmiten cifrados y no se almacenan.</p>
        </div>
        <p class="pay-alt" id="payAlt" hidden>Serás redirigido al proveedor para completar el pago de forma segura.</p>
      </section>
    </div>

    <aside class="summary">
      <h3>Tu pedido</h3>
      <ul class="summary__items">
        <?php foreach ($lines as $l): ?>
          <li><span><?= (int)$l['qty'] ?>× <?= htmlspecialchars($l['name']) ?></span><b><?= eur($l['total']) ?></b></li>
        <?php endforeach; ?>
      </ul>
      <div class="summary__row"><span>Subtotal</span><b><?= eur($subtotal) ?></b></div>
      <div class="summary__row"><span>Envío</span><b><?= $ship == 0 ? 'Gratis' : eur($ship) ?></b></div>
      <div class="summary__row summary__total"><span>Total</span><b><?= eur($total) ?></b></div>
      <button class="btn btn--primary btn--block btn--lg" type="submit"><?= icon('lock2', 17) ?> Pagar <?= eur($total) ?></button>
      <a class="summary__back" href="cart.php"><?= icon('aleft', 14) ?> Volver a la cesta</a>
    </aside>
  </form>

  <script>
    (function () {
      var radios = document.querySelectorAll('input[name="method"]');
      var card = document.getElementById('cardFields');
      var alt = document.getElementById('payAlt');
      function sync() {
        var v = document.querySelector('input[name="method"]:checked').value;
        var isCard = v === 'card';
        card.hidden = !isCard; alt.hidden = isCard;
        card.querySelectorAll('input').forEach(function (i) { i.required = isCard && i.id !== 'exp'; });
      }
      radios.forEach(function (r) { r.addEventListener('change', sync); });
      var cc = document.getElementById('cc');
      if (cc) cc.addEventListener('input', function () {
        var d = this.value.replace(/\D/g, '').slice(0, 16);
        this.value = d.replace(/(.{4})/g, '$1 ').trim();
      });
      var exp = document.getElementById('exp');
      if (exp) exp.addEventListener('input', function () {
        var d = this.value.replace(/\D/g, '').slice(0, 4);
        this.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
      });
      sync();
    })();
  </script>
<?php endif; ?>

<?php store_footer(); ?>
