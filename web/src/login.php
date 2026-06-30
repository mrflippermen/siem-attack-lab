<?php
// =====================================================================
//  VULNERABILIDAD 3 (bonus): SQLi en login -> bypass de autenticacion
//      usuario:  admin'-- -      contrasena: cualquiera
//  Tambien sirve para brute force (genera muchos 401/200 en el log).
//
//  NOTA: solo se rediseña la presentacion. La consulta concatenada, el
//  bypass y los codigos de estado (200 OK / 401) se conservan intactos.
// =====================================================================
require 'db.php';
require 'partials.php';

$status = null;   // 'ok' | 'err'
$user   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    // Concatenacion insegura a proposito:
    $sql = "SELECT id, username FROM users WHERE username = '$u' AND password = '$p'";
    $res = mysqli_query(db(), $sql);
    if ($res && mysqli_num_rows($res) > 0) {
        $row    = mysqli_fetch_assoc($res);
        $status = 'ok';
        $user   = $row['username'];
    } else {
        http_response_code(401);
        $status = 'err';
    }
}

store_header('Acceso', '');
?>

<div class="auth" style="margin-inline:calc(-1 * var(--gut))">
  <aside class="auth__aside">
    <span class="auth__badge"><?= icon('lock', 14) ?> Panel privado</span>
    <h2>Centro de administración ACME</h2>
    <p>Gestiona catálogo, pedidos, stock y clientes desde un único panel. El acceso está restringido al personal autorizado.</p>
    <div class="hero__feat" style="color:var(--on-brand-mut)"><?= icon('shield', 18) ?> <span>Sesiones cifradas y registro de auditoría.</span></div>
    <div class="hero__feat" style="color:var(--on-brand-mut)"><?= icon('box', 18) ?> <span>Inventario sincronizado en tiempo real.</span></div>
  </aside>

  <div class="auth__main">
    <div class="auth__card">
      <span class="brand__mark" style="margin-bottom:1.1rem"><?= icon('bolt', 19) ?></span>
      <h1>Inicia sesión</h1>
      <p class="muted">Introduce tus credenciales de administrador.</p>

      <?php if ($status === 'ok'): ?>
        <div class="alert alert--ok" role="status" style="margin-bottom:1.25rem">
          <?= icon('check', 18) ?>
          <div>Autenticado como <b><?= htmlspecialchars($user) ?></b>. Redirigiendo al panel…</div>
        </div>
      <?php elseif ($status === 'err'): ?>
        <div class="alert alert--err" role="alert" style="margin-bottom:1.25rem">
          <?= icon('lock', 18) ?>
          <div><b>Credenciales inválidas.</b> Revisa el usuario y la contraseña.</div>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="field">
          <label for="username">Usuario</label>
          <input class="input" id="username" name="username" placeholder="admin" autocapitalize="none" spellcheck="false">
        </div>
        <div class="field">
          <div class="field__row">
            <label for="password">Contraseña</label>
            <a href="login.php">¿Olvidaste tu clave?</a>
          </div>
          <input class="input" id="password" name="password" type="password" placeholder="••••••••">
        </div>
        <button class="btn btn--solid btn--block btn--lg" type="submit"><?= icon('arrow', 17) ?> Entrar al panel</button>
      </form>

      <p class="muted" style="font-size:0.82rem;margin-top:1.5rem;text-align:center">
        <?= icon('shield', 13) ?> Conexión privada · solo personal autorizado
      </p>
    </div>
  </div>
</div>

<?php store_footer(); ?>
