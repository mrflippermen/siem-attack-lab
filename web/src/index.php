<?php
// =====================================================================
//  Storefront — pagina de inicio del target.
//  El listado del catalogo NO recibe entrada del usuario (no es
//  superficie de inyeccion). Las vulnerabilidades viven en product.php
//  (SQLi), view.php (LFI) y login.php (SQLi) y permanecen intactas.
// =====================================================================
require 'partials.php';

store_header('Inicio', 'home');
$products = get_products();
?>

<section class="hero">
  <div class="hero__grid">
    <div>
      <span class="eyebrow"><?= icon('spark', 15) ?> Temporada de reformas</span>
      <h1>Herramienta que aguanta el ritmo de tu obra.</h1>
      <p>Más de 4.000 referencias en herramienta eléctrica, manual, jardín y electrónica. Stock real, envío en 24&nbsp;h y garantía de 3&nbsp;años en marca propia.</p>
      <div class="hero__actions">
        <a class="btn btn--primary btn--lg" href="catalog.php"><?= icon('cart', 17) ?> Ver catálogo</a>
        <a class="btn btn--ghost btn--lg" href="view.php?page=home.html" style="color:var(--on-brand);border-color:oklch(1 0 0 / 0.25)">Ofertas de la semana</a>
      </div>
      <div class="hero__stats">
        <div><b>4.200+</b><span>referencias en stock</span></div>
        <div><b>24&nbsp;h</b><span>envío península</span></div>
        <div><b>3&nbsp;años</b><span>garantía marca propia</span></div>
      </div>
    </div>
    <aside class="hero__panel">
      <h3>Por qué ACME</h3>
      <div class="hero__feat"><?= icon('truck', 18) ?> <span>Entrega 24&nbsp;h y recogida en tienda gratuita.</span></div>
      <div class="hero__feat"><?= icon('shield', 18) ?> <span>Devolución sin preguntas durante 30&nbsp;días.</span></div>
      <div class="hero__feat"><?= icon('tag', 18) ?> <span>Precio igualado: te devolvemos la diferencia.</span></div>
      <div class="hero__feat"><?= icon('tool', 18) ?> <span>Servicio técnico oficial para herramienta eléctrica.</span></div>
    </aside>
  </div>
</section>

<section class="section">
  <div class="section__head">
    <div><h2>Compra por categoría</h2><p class="muted">Encuentra lo que necesitas para tu próximo proyecto.</p></div>
  </div>
  <div class="grid-cats">
    <a class="cat-tile" href="catalog.php?q=taladro"><span class="cat-tile__ic"><?= icon('drill', 20) ?></span> Herramienta eléctrica <small>Taladros, lijadoras, sierras</small></a>
    <a class="cat-tile" href="catalog.php?q=destornillador"><span class="cat-tile__ic"><?= icon('tool', 20) ?></span> Herramienta manual <small>Destornilladores, llaves</small></a>
    <a class="cat-tile" href="catalog.php?q=manguera"><span class="cat-tile__ic"><?= icon('leaf', 20) ?></span> Jardín y riego <small>Mangueras, poda, césped</small></a>
    <a class="cat-tile" href="catalog.php?q=led"><span class="cat-tile__ic"><?= icon('plug', 20) ?></span> Electrónica e iluminación <small>LED, cableado, enchufes</small></a>
  </div>
</section>

<section class="section" id="catalogo">
  <div class="section__head">
    <div><h2>Lo más vendido</h2><p class="muted"><?= count($products) ?> productos destacados esta semana.</p></div>
    <a class="btn btn--ghost" href="catalog.php">Ver todo <?= icon('arrow', 16) ?></a>
  </div>

  <?php if (!$products): ?>
    <div class="panel"><p class="muted">El catálogo no está disponible en este momento. Vuelve a intentarlo en unos minutos.</p></div>
  <?php else: ?>
  <div class="grid-products">
    <?php foreach ($products as $i => $p) {
        $tag = $i === 0 ? 'Top ventas' : ($i === count($products) - 1 ? 'Novedad' : null);
        echo render_product_card($p, $tag);
    } ?>
  </div>
  <?php endif; ?>
</section>

<?php store_footer(); ?>
