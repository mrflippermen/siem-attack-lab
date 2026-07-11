<?php
require 'partials.php';

$page = $_GET['page'] ?? 'home.html';

store_header('ACME Store', 'offers');
?>

<nav class="crumbs" aria-label="Ruta">
  <a href="index.php">Inicio</a> <?= icon('arrow', 13) ?>
  <span aria-current="page">Información</span>
</nav>

<div class="panel">
<pre class="output" style="margin:0;border:0;background:transparent;padding:0;white-space:pre-wrap">
<?php
    @include($page);
?>
</pre>
</div>

<?php store_footer(); ?>
