<?php
// =====================================================================
//  Catalogo con busqueda, filtro por rango de precio, orden y paginacion.
//  Todo el filtrado se hace en PHP (no es superficie de inyeccion): no
//  altera las vulnerabilidades del laboratorio.
// =====================================================================
require 'partials.php';

$q    = trim((string)($_GET['q'] ?? ''));
$sort = in_array(($_GET['sort'] ?? ''), ['price-asc','price-desc','name'], true) ? $_GET['sort'] : 'rel';
$page = max(1, (int)($_GET['p'] ?? 1));
$min  = is_numeric($_GET['min'] ?? '') ? (float)$_GET['min'] : null;
$max  = is_numeric($_GET['max'] ?? '') ? (float)$_GET['max'] : null;
const PER_PAGE = 3;

$catalogAll = get_products();

// Limites de precio del catalogo (para el slider visual).
$prices = array_map(fn($p) => (float)$p['price'], $catalogAll);
$floor  = $prices ? (float)floor(min($prices)) : 0.0;
$ceil   = $prices ? (float)ceil(max($prices))  : 100.0;
if ($ceil <= $floor) { $ceil = $floor + 1; }
$slMin = $min !== null ? max($floor, min($min, $ceil)) : $floor;
$slMax = $max !== null ? min($ceil, max($max, $floor)) : $ceil;

$items = $catalogAll;

// --- Filtro de busqueda (seguro, en memoria) ---
if ($q !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
    $items = array_values(array_filter($items, function ($p) use ($needle) {
        $hay = strtolower(($p['name'] ?? '') . ' ' . ($p['description'] ?? ''));
        return str_contains($hay, $needle);
    }));
}

// --- Filtro por rango de precio ---
if ($min !== null || $max !== null) {
    $items = array_values(array_filter($items, function ($p) use ($min, $max) {
        $price = (float)$p['price'];
        if ($min !== null && $price < $min) return false;
        if ($max !== null && $price > $max) return false;
        return true;
    }));
}

// --- Ordenacion ---
usort($items, function ($a, $b) use ($sort) {
    return match ($sort) {
        'price-asc'  => (float)$a['price'] <=> (float)$b['price'],
        'price-desc' => (float)$b['price'] <=> (float)$a['price'],
        'name'       => strcasecmp((string)$a['name'], (string)$b['name']),
        default      => (int)$a['id'] <=> (int)$b['id'],
    };
});

$total = count($items);
$pages = max(1, (int)ceil($total / PER_PAGE));
$page  = min($page, $pages);
$slice = array_slice($items, ($page - 1) * PER_PAGE, PER_PAGE);

/** URL del catalogo conservando los parametros activos. */
function cat_url(array $over = []): string {
    $base = [
        'q'    => $_GET['q']    ?? '',
        'sort' => $_GET['sort'] ?? '',
        'min'  => $_GET['min']  ?? '',
        'max'  => $_GET['max']  ?? '',
        'p'    => $_GET['p']    ?? '',
    ];
    $merged = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return 'catalog.php' . ($merged ? '?' . http_build_query($merged) : '');
}

// Presets de precio (min/max como string para comparar con la URL).
$presets = [
    ['label' => 'Todos los precios', 'min' => '',   'max' => ''],
    ['label' => 'Menos de 20 €',     'min' => '',   'max' => '20'],
    ['label' => '20 € – 50 €',       'min' => '20', 'max' => '50'],
    ['label' => 'Más de 50 €',       'min' => '50', 'max' => ''],
];
$curMin = (string)($_GET['min'] ?? '');
$curMax = (string)($_GET['max'] ?? '');
$hasFilters = $q !== '' || $min !== null || $max !== null;

store_header($q !== '' ? 'Búsqueda' : 'Catálogo', 'shop');
?>

<nav class="crumbs" aria-label="Ruta">
  <a href="index.php">Inicio</a> <?= icon('arrow', 13) ?>
  <span aria-current="page"><?= $q !== '' ? 'Resultados' : 'Catálogo' ?></span>
</nav>

<div class="section__head">
  <div>
    <h2><?= $q !== '' ? 'Resultados para “'.htmlspecialchars($q).'”' : 'Catálogo completo' ?></h2>
    <p class="muted"><?= $total ?> producto<?= $total === 1 ? '' : 's' ?><?= $hasFilters ? ' tras los filtros' : '' ?>.</p>
  </div>
</div>

<div class="shop-layout">
  <aside class="shop-filters" aria-label="Filtros">
    <form method="get" action="catalog.php">
      <?php if ($sort !== 'rel'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>

      <div class="filter-group">
        <h3>Buscar</h3>
        <div class="shop-search" style="max-width:none">
          <span class="shop-search__ic"><?= icon('search', 16) ?></span>
          <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar…" aria-label="Buscar">
        </div>
      </div>

      <div class="filter-group">
        <h3>Precio</h3>
        <ul class="price-presets">
          <?php foreach ($presets as $pr):
            $active = $curMin === $pr['min'] && $curMax === $pr['max'];
          ?>
          <li>
            <a class="price-preset<?= $active ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars(cat_url(['min' => $pr['min'] ?: null, 'max' => $pr['max'] ?: null, 'p' => null])) ?>">
              <span class="price-preset__dot"></span><?= $pr['label'] ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>

        <div class="rs" data-floor="<?= (int)$floor ?>" data-ceil="<?= (int)$ceil ?>">
          <div class="rs__head">
            <output class="rs__out rs__out--min"><?= eur($slMin) ?></output>
            <output class="rs__out rs__out--max"><?= eur($slMax) ?></output>
          </div>
          <div class="rs__track">
            <div class="rs__fill"></div>
            <input type="range" class="rs__range rs__range--min" min="<?= (int)$floor ?>" max="<?= (int)$ceil ?>" step="1" value="<?= (int)round($slMin) ?>" aria-label="Precio mínimo">
            <input type="range" class="rs__range rs__range--max" min="<?= (int)$floor ?>" max="<?= (int)$ceil ?>" step="1" value="<?= (int)round($slMax) ?>" aria-label="Precio máximo">
          </div>
        </div>

        <div class="price-range">
          <label class="price-range__field">
            <span>Mín</span>
            <input type="number" name="min" min="0" step="0.01" inputmode="decimal" value="<?= htmlspecialchars($curMin) ?>" placeholder="0">
          </label>
          <span class="price-range__sep">–</span>
          <label class="price-range__field">
            <span>Máx</span>
            <input type="number" name="max" min="0" step="0.01" inputmode="decimal" value="<?= htmlspecialchars($curMax) ?>" placeholder="∞">
          </label>
        </div>
        <button class="btn btn--solid btn--sm btn--block" type="submit" style="margin-top:0.85rem">Aplicar precio</button>
      </div>

      <?php if ($hasFilters): ?>
        <a class="filter-clear" href="catalog.php"><?= icon('trash', 14) ?> Borrar todos los filtros</a>
      <?php endif; ?>
    </form>
  </aside>

  <div class="shop-results">
    <div class="shop-bar">
      <div class="filter-chips">
        <?php if ($q !== ''): ?>
          <span class="chip">Búsqueda: <b><?= htmlspecialchars($q) ?></b><a href="<?= htmlspecialchars(cat_url(['q' => null, 'p' => null])) ?>" aria-label="Quitar búsqueda">×</a></span>
        <?php endif; ?>
        <?php if ($min !== null || $max !== null):
          $lbl = $min !== null && $max !== null ? eur($min).' – '.eur($max)
               : ($max !== null ? 'hasta '.eur($max) : 'desde '.eur($min)); ?>
          <span class="chip">Precio: <b><?= $lbl ?></b><a href="<?= htmlspecialchars(cat_url(['min' => null, 'max' => null, 'p' => null])) ?>" aria-label="Quitar precio">×</a></span>
        <?php endif; ?>
        <?php if (!$hasFilters): ?><span class="muted" style="font-size:0.88rem">Mostrando todo el catálogo</span><?php endif; ?>
      </div>

      <form class="shop-sort" method="get" action="catalog.php">
        <?php foreach (['q'=>$q,'min'=>$curMin,'max'=>$curMax] as $k=>$v): if ($v!=='' ): ?>
          <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars((string)$v) ?>">
        <?php endif; endforeach; ?>
        <label for="sort"><?= icon('sort', 16) ?> Ordenar</label>
        <select id="sort" name="sort" onchange="this.form.submit()">
          <option value="rel"        <?= $sort==='rel'?'selected':'' ?>>Relevancia</option>
          <option value="price-asc"  <?= $sort==='price-asc'?'selected':'' ?>>Precio: menor a mayor</option>
          <option value="price-desc" <?= $sort==='price-desc'?'selected':'' ?>>Precio: mayor a menor</option>
          <option value="name"       <?= $sort==='name'?'selected':'' ?>>Nombre (A-Z)</option>
        </select>
      </form>
    </div>

    <?php if (!$slice): ?>
      <div class="panel empty">
        <span class="empty__ic"><?= icon('search', 28) ?></span>
        <p class="empty__title">Sin resultados</p>
        <p class="muted">No hay productos que cumplan estos filtros. Prueba a ampliar el rango de precio o cambiar la búsqueda.</p>
        <a class="btn btn--solid" href="catalog.php" style="margin-top:1.1rem"><?= icon('box', 16) ?> Ver todo el catálogo</a>
      </div>
    <?php else: ?>
      <div class="grid-products">
        <?php foreach ($slice as $p) { echo render_product_card($p); } ?>
      </div>

      <?php if ($pages > 1): ?>
      <nav class="pagination" aria-label="Paginación">
        <a class="page-btn<?= $page <= 1 ? ' is-disabled' : '' ?>" <?= $page > 1 ? 'href="'.htmlspecialchars(cat_url(['p' => $page - 1])).'"' : 'aria-disabled="true"' ?>>
          <?= icon('aleft', 15) ?> Anterior
        </a>
        <div class="page-nums">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a class="page-num<?= $i === $page ? ' is-current' : '' ?>" <?= $i === $page ? 'aria-current="page"' : 'href="'.htmlspecialchars(cat_url(['p' => $i])).'"' ?>><?= $i ?></a>
          <?php endfor; ?>
        </div>
        <a class="page-btn<?= $page >= $pages ? ' is-disabled' : '' ?>" <?= $page < $pages ? 'href="'.htmlspecialchars(cat_url(['p' => $page + 1])).'"' : 'aria-disabled="true"' ?>>
          Siguiente <?= icon('arrow', 15) ?>
        </a>
      </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var rs = document.querySelector('.rs');
  if (!rs) return;
  var floor = +rs.dataset.floor, ceil = +rs.dataset.ceil;
  var rMin = rs.querySelector('.rs__range--min'), rMax = rs.querySelector('.rs__range--max');
  var fill = rs.querySelector('.rs__fill');
  var oMin = rs.querySelector('.rs__out--min'), oMax = rs.querySelector('.rs__out--max');
  var nMin = document.querySelector('.price-range input[name="min"]');
  var nMax = document.querySelector('.price-range input[name="max"]');
  var form = rs.closest('form');
  var span = (ceil - floor) || 1;
  var fmt = function (v) { return v.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; };
  var pct = function (v) { return ((v - floor) / span) * 100; };

  function paint() {
    var a = +rMin.value, b = +rMax.value;
    if (a > b) { var t = a; a = b; b = t; }
    fill.style.left = pct(a) + '%';
    fill.style.right = (100 - pct(b)) + '%';
    oMin.textContent = fmt(a); oMax.textContent = fmt(b);
  }
  function order(active) {
    if (+rMin.value > +rMax.value) {
      if (active === rMin) rMax.value = rMin.value; else rMin.value = rMax.value;
    }
  }
  function toNumbers() {
    var a = +rMin.value, b = +rMax.value; if (a > b) { var t = a; a = b; b = t; }
    nMin.value = a <= floor ? '' : a;
    nMax.value = b >= ceil ? '' : b;
  }
  [rMin, rMax].forEach(function (r) {
    r.addEventListener('input', function () { order(r); paint(); toNumbers(); });
    r.addEventListener('change', function () { if (form) form.submit(); });
  });
  // Campos numericos -> slider (sin auto-enviar; el boton "Aplicar" envia).
  function fromNumbers() {
    var a = nMin.value !== '' ? +nMin.value : floor;
    var b = nMax.value !== '' ? +nMax.value : ceil;
    rMin.value = Math.max(floor, Math.min(a, ceil));
    rMax.value = Math.max(floor, Math.min(b, ceil));
    paint();
  }
  if (nMin) nMin.addEventListener('input', fromNumbers);
  if (nMax) nMax.addEventListener('input', fromNumbers);
  paint();
})();
</script>

<?php store_footer(); ?>
