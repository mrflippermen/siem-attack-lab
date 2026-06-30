<?php
// =====================================================================
//  Chrome + estado compartido del storefront.
//  - Cabecera / pie / iconos SVG (solo presentacion).
//  - Carrito de la compra (sesion PHP) y helpers de catalogo.
//  Sin recursos externos -> la red del laboratorio esta aislada.
//  No expone en pantalla ficheros, tablas ni motor de BD.
// =====================================================================
require_once 'db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---- Catalogo -------------------------------------------------------- */

/** Todos los productos del catalogo. */
function get_products(): array {
    $res = mysqli_query(db(), "SELECT id, name, description, price FROM products ORDER BY id");
    $out = [];
    while ($res && $row = mysqli_fetch_assoc($res)) { $out[] = $row; }
    return $out;
}

/** Un producto por id (cast entero -> consulta segura, no es superficie del lab). */
function get_product(int $id): ?array {
    $res = mysqli_query(db(), "SELECT id, name, description, price FROM products WHERE id = " . $id);
    if ($res && $row = mysqli_fetch_assoc($res)) { return $row; }
    return null;
}

/* ---- Carrito (sesion) ------------------------------------------------ */

function &cart_ref(): array {
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) { $_SESSION['cart'] = []; }
    return $_SESSION['cart'];
}
function cart_add(int $id, int $qty = 1): void {
    $c =& cart_ref();
    $c[$id] = max(1, ($c[$id] ?? 0) + $qty);
}
function cart_set(int $id, int $qty): void {
    $c =& cart_ref();
    if ($qty <= 0) { unset($c[$id]); } else { $c[$id] = min(99, $qty); }
}
function cart_remove(int $id): void { $c =& cart_ref(); unset($c[$id]); }
function cart_count(): int { return array_sum(cart_ref()); }

/** Lineas del carrito enriquecidas con datos de producto. */
function cart_lines(): array {
    $lines = [];
    foreach (cart_ref() as $id => $qty) {
        $p = get_product((int)$id);
        if (!$p) { continue; }
        $price = (float)$p['price'];
        $lines[] = [
            'id' => (int)$id, 'name' => $p['name'], 'price' => $price,
            'qty' => (int)$qty, 'total' => $price * (int)$qty,
        ];
    }
    return $lines;
}
function cart_subtotal(): float {
    $s = 0.0;
    foreach (cart_lines() as $l) { $s += $l['total']; }
    return $s;
}
function shipping_cost(float $subtotal): float {
    return ($subtotal <= 0 || $subtotal >= 50) ? 0.0 : 4.95;
}
/** Formato de precio es-ES. */
function eur(float $n): string { return number_format($n, 2, ',', '.') . '&nbsp;€'; }

/* ---- Iconos SVG ------------------------------------------------------ */

function icon(string $name, int $size = 18): string {
    $paths = [
        'bolt'   => '<path d="M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5Z"/>',
        'arrow'  => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'aleft'  => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'truck'  => '<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        'tag'    => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4a2 2 0 0 1 2-2h7.8a2 2 0 0 1 1.4.6l6.6 6.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'tool'   => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2l-6 6 2.2 2.2 6-6a4 4 0 0 0 5.2-5.4l-2.4 2.4-2-2 2.4-2.4Z"/>',
        'drill'  => '<rect x="3" y="7" width="11" height="7" rx="2"/><path d="M14 9h4l3-2v8l-3-2h-4"/><path d="M7 14v5"/>',
        'leaf'   => '<path d="M11 20A7 7 0 0 1 4 13c0-5 5-9 16-9 0 11-4 16-9 16Z"/><path d="M4 20c4-6 8-8 12-9"/>',
        'plug'   => '<path d="M9 2v6M15 2v6"/><path d="M7 8h10v3a5 5 0 0 1-10 0V8Z"/><path d="M12 16v6"/>',
        'box'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'lock'   => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'cart'   => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.4 12.5a2 2 0 0 0 2 1.5h7.6a2 2 0 0 0 2-1.6L22 7H6"/>',
        'spark'  => '<path d="M12 2v6M12 16v6M2 12h6M16 12h6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'sort'   => '<path d="M7 4v16M7 20l-3-3M7 4l3 3M17 20V4M17 4l3 3M17 20l-3-3"/>',
        'trash'  => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'minus'  => '<path d="M5 12h14"/>',
        'card'   => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'pin'    => '<path d="M12 22s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/>',
        'mail'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="13.5" r="1"/>',
        'lock2'  => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    ];
    $p = $paths[$name] ?? $paths['box'];
    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">'.$p.'</svg>';
}

function product_icon(string $name, int $size = 18): string {
    $n = strtolower($name);
    $map = ['taladro'=>'drill','destornillador'=>'tool','manguera'=>'leaf','bombilla'=>'spark',
            'led'=>'spark','jardin'=>'leaf','electr'=>'plug','cable'=>'plug'];
    foreach ($map as $needle => $ic) { if (str_contains($n, $needle)) return icon($ic, $size); }
    return icon('box', $size);
}

/** Tarjeta de producto reutilizable (catalogo + inicio). */
function render_product_card(array $p, ?string $tag = null): string {
    $id    = (int)($p['id'] ?? 0);
    $name  = htmlspecialchars((string)($p['name'] ?? ''));
    $desc  = htmlspecialchars((string)($p['description'] ?? ''));
    $price = isset($p['price']) && is_numeric($p['price']) ? eur((float)$p['price']) : '<small>Consultar</small>';
    ob_start(); ?>
    <article class="product-card">
      <a class="product-card__media" href="product.php?id=<?= $id ?>" aria-label="<?= $name ?>">
        <?php if ($tag): ?><span class="product-card__tag"><?= htmlspecialchars($tag) ?></span><?php endif; ?>
        <?= product_icon((string)($p['name'] ?? ''), 56) ?>
      </a>
      <div class="product-card__body">
        <a class="product-card__name" href="product.php?id=<?= $id ?>"><?= $name ?></a>
        <p class="product-card__desc"><?= $desc ?></p>
        <div class="product-card__foot">
          <span class="price"><?= $price ?></span>
          <form method="post" action="cart.php">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn--solid btn--sm" type="submit" aria-label="Añadir <?= $name ?> a la cesta"><?= icon('cart', 15) ?> Añadir</button>
          </form>
        </div>
      </div>
    </article>
    <?php return (string)ob_get_clean();
}

/* ---- Chrome ---------------------------------------------------------- */

function store_header(string $title, string $current = ''): void {
    $favicon = 'data:image/svg+xml,'.rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="5" fill="%23e89a2c"/>'
        .'<path d="M13 4 6 13.5h5l-1 6.5 7-9.5h-5l1-6.5Z" fill="%231a2336"/></svg>'
    );
    $nav = [
        'home'   => ['index.php',               'Inicio'],
        'shop'   => ['catalog.php',             'Catálogo'],
        'offers' => ['view.php?page=home.html', 'Ofertas'],
        'help'   => ['view.php?page=home.html', 'Ayuda'],
    ];
    $count = cart_count();
    echo '<!DOCTYPE html><html lang="es"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="color-scheme" content="light">';
    echo '<title>'.htmlspecialchars($title).' · ACME Store</title>';
    echo '<link rel="icon" href="'.$favicon.'">';
    echo '<link rel="stylesheet" href="/assets/store.css">';
    echo '</head><body>';

    $q = htmlspecialchars((string)($_GET['q'] ?? ''));
    echo '<header class="topbar"><div class="wrap topbar__row">';
    echo '<a class="brand" href="index.php"><span class="brand__mark">'.icon('bolt', 19).'</span>';
    echo 'ACME&nbsp;Store<span class="brand__sub">Herramienta &amp; Hogar</span></a>';

    echo '<form class="topbar__search" role="search" method="get" action="catalog.php">'
        .'<span class="topbar__search-ic">'.icon('search', 17).'</span>'
        .'<input type="search" name="q" value="'.$q.'" placeholder="Buscar herramientas, jardín, electrónica…" aria-label="Buscar productos" autocomplete="off">'
        .'<button type="submit" aria-label="Buscar">Buscar</button>'
        .'</form>';

    echo '<nav class="nav" aria-label="Principal">';
    foreach ($nav as $key => [$href, $label]) {
        $cur = $key === $current ? ' aria-current="page"' : '';
        echo '<a href="'.$href.'"'.$cur.'>'.$label.'</a>';
    }
    echo '</nav>';

    echo '<div class="top-actions">';
    echo '<a class="icon-btn" href="login.php" aria-label="Acceso de administración">'.icon('user', 18).'</a>';

    // ---- Mini-cesta desplegable (hover / focus) ----
    echo '<div class="cart-menu">';
    echo '<a class="icon-btn cart-btn'.($current==='cart'?' is-active':'').'" href="cart.php" aria-label="Ver cesta" aria-haspopup="true">'
        .icon('cart', 18);
    if ($count > 0) { echo '<span class="cart-badge">'.$count.'</span>'; }
    echo '</a>';
    echo '<div class="cart-pop" role="region" aria-label="Resumen de la cesta">';
    $lines = cart_lines();
    if (!$lines) {
        echo '<div class="cart-pop__empty">'.icon('cart', 26).'<p>Tu cesta está vacía</p>'
            .'<a class="btn btn--solid btn--sm btn--block" href="catalog.php">Ver catálogo</a></div>';
    } else {
        echo '<div class="cart-pop__head"><b>Tu cesta</b><span>'.cart_count().' artículo(s)</span></div>';
        echo '<ul class="cart-pop__list">';
        foreach ($lines as $l) {
            echo '<li><span class="cart-pop__ic">'.product_icon($l['name'], 20).'</span>'
                .'<span class="cart-pop__info"><b>'.htmlspecialchars($l['name']).'</b>'
                .'<span>'.(int)$l['qty'].' × '.eur($l['price']).'</span></span>'
                .'<span class="cart-pop__tot">'.eur($l['total']).'</span></li>';
        }
        echo '</ul>';
        echo '<div class="cart-pop__foot"><div class="cart-pop__sub"><span>Subtotal</span><b>'.eur(cart_subtotal()).'</b></div>'
            .'<a class="btn btn--ghost btn--sm btn--block" href="cart.php">Ver cesta</a>'
            .'<a class="btn btn--primary btn--sm btn--block" href="checkout.php">Finalizar compra</a></div>';
    }
    echo '</div></div>';

    echo '</div>';
    echo '</div></header>';

    echo '<main><div class="wrap">';
}

function store_footer(): void {
    $year = date('Y');
    echo '</div></main>';
    echo '<footer class="site-foot"><div class="wrap">';
    echo '<div class="site-foot__top">';
    echo '<div class="site-foot__brand">';
    echo '<a class="brand" href="index.php"><span class="brand__mark">'.icon('bolt', 19).'</span>ACME&nbsp;Store</a>';
    echo '<p>Herramienta profesional, jardín y electrónica para quien construye, repara y cuida su casa.</p>';
    echo '<div class="pay-row" aria-label="Métodos de pago aceptados">';
    echo '<span class="pay-chip">VISA</span><span class="pay-chip">Mastercard</span>';
    echo '<span class="pay-chip">PayPal</span><span class="pay-chip">Bizum</span>';
    echo '</div></div>';
    $cols = [
        'Tienda'  => ['Catálogo completo', 'Ofertas de la semana', 'Novedades', 'Marcas'],
        'Ayuda'   => ['Envíos y entregas', 'Devoluciones', 'Garantía', 'Contacto'],
        'Empresa' => ['Sobre ACME', 'Tiendas físicas', 'Trabaja con nosotros', 'Prensa'],
    ];
    foreach ($cols as $h => $items) {
        echo '<div><h4>'.$h.'</h4><ul>';
        foreach ($items as $it) echo '<li><a href="index.php">'.$it.'</a></li>';
        echo '</ul></div>';
    }
    echo '</div>';
    echo '<div class="site-foot__bar">';
    echo '<span>© '.$year.' ACME Store S.L. · Todos los precios incluyen IVA.</span>';
    echo '<span class="lab-pill">'.icon('shield', 14).' Entorno de laboratorio · uso educativo</span>';
    echo '</div></div></footer>';
    echo '</body></html>';
}
