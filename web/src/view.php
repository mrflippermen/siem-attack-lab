<?php
// =====================================================================
//  VULNERABILIDAD 2:  LOCAL FILE INCLUSION / PATH TRAVERSAL
//  El parametro 'page' se pasa directo a include() sin validar.
//      view.php?page=../../../../etc/passwd
//      view.php?page=../../var/log/apache2/access.log   (log poisoning)
//
//  Forma CORRECTA: lista blanca ->
//      $allow = ['home.html','about.html'];
//      if(!in_array($_GET['page'],$allow)) die('403');
//
//  NOTA: el include directo del input se CONSERVA intacto (LFI + log
//  poisoning funcionan igual). Solo se ha quitado de la pagina el
//  indicador del recurso/fichero solicitado: se muestra el contenido,
//  no de que fichero procede.
// =====================================================================
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
    // !!! include directo de input del usuario !!!
    @include($page);
?>
</pre>
</div>

<?php store_footer(); ?>
