<?php
// Request filter and abuse throttling for the storefront.

const SHIELD_MAX_HITS    = 10;
const SHIELD_BAN_SECONDS = 600;
const SHIELD_WINDOW      = 600;
const SHIELD_HARD_BAN    = 1800;   // reincidentes: bloqueo de 30 min

const SHIELD_RATE_WINDOW = 5;
const SHIELD_RATE_MAX    = 25;
const SHIELD_SIMILAR_MAX = 8;
const SHIELD_COMPLEX_MAX = 10;
const SHIELD_BEHAV_BAN   = 180;
const SHIELD_HIST_KEEP   = 40;

function shield_dir(): string {
    $d = sys_get_temp_dir() . '/acme_shield';
    if (!is_dir($d)) { @mkdir($d, 0777, true); }
    return $d;
}

function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return preg_replace('/[^0-9a-fA-F:.]/', '_', $ip);
}

function shield_state(string $ip): array {
    $f = shield_dir() . '/' . $ip . '.json';
    $s = @file_get_contents($f);
    $st = $s ? json_decode($s, true) : null;
    return is_array($st) ? $st : ['hits' => 0, 'first' => 0, 'ban_until' => 0];
}

function shield_save(string $ip, array $st): void {
    @file_put_contents(shield_dir() . '/' . $ip . '.json', json_encode($st), LOCK_EX);
}

function shield_ban_page(int $secs_left): void {
    http_response_code(403);
    header('X-WAF: acme-shield/1.2');
    header('X-Shield-Ban: ' . $secs_left);
    header('Retry-After: ' . $secs_left);
    echo '<!doctype html><meta charset="utf-8"><title>403 · IP bloqueada</title>';
    echo '<style>body{font-family:system-ui;background:#0f1420;color:#e6ebf5;'
       . 'display:grid;place-items:center;height:100vh;margin:0}.b{max-width:34rem;'
       . 'text-align:center;padding:2rem}code{color:#e89a2c}</style>';
    echo '<div class="b"><h1>403 · IP bloqueada temporalmente</h1>';
    echo '<p><code>acme-shield</code> ha detectado actividad automatizada o '
       . 'abusiva desde tu direccion y la ha vetado temporalmente.</p>';
    echo '<p>Reintenta en <b>' . ceil($secs_left / 60) . ' min</b> '
       . '(' . $secs_left . ' s).</p></div>';
    exit;
}

function waf_block(string $why): void {
    shield_register_offense('waf:' . $why);
    http_response_code(403);
    header('X-WAF: acme-shield/1.2');
    echo '<!doctype html><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:system-ui;background:#0f1420;color:#e6ebf5;'
       . 'display:grid;place-items:center;height:100vh;margin:0}.b{max-width:32rem;'
       . 'text-align:center;padding:2rem}code{color:#e89a2c}</style>';
    echo '<div class="b"><h1>403 · Peticion bloqueada</h1>';
    echo '<p>El firewall <code>acme-shield</code> ha bloqueado un patron malicioso.</p>';
    echo '<p style="opacity:.6;font-size:.85rem">Ref: ' . htmlspecialchars($why) . '</p></div>';
    exit;
}

function shield_register_offense(string $reason = ''): bool {
    $ip  = client_ip();
    $now = time();
    $st  = shield_state($ip);

    if ($now - ($st['first'] ?? 0) > SHIELD_WINDOW) {
        $st['first'] = $now;
        $st['hits']  = 0;
    }
    $st['hits'] = ($st['hits'] ?? 0) + 1;

    $banned_now = false;
    if ($st['hits'] >= SHIELD_MAX_HITS && ($st['ban_until'] ?? 0) < $now) {
        $st['bans'] = ($st['bans'] ?? 0) + 1;
        $dur = ($st['bans'] >= 2) ? SHIELD_HARD_BAN : SHIELD_BAN_SECONDS;   // reincidente -> 30 min
        $st['ban_until'] = $now + $dur;
        $banned_now = true;
        error_log("[acme-shield] BAN ip=$ip hits={$st['hits']} bans={$st['bans']} reason=$reason for {$dur}s");
    }
    shield_save($ip, $st);
    return $banned_now;
}

function shield_guard(): void {
    $st  = shield_state(client_ip());
    $now = time();
    if (($st['ban_until'] ?? 0) > $now) {
        shield_ban_page($st['ban_until'] - $now);
    }
}

function shield_watch(string $request): void {
    static $sig = '/(?i)(union[\s\S]*?select|information_schema|sleep\s*\(|benchmark\s*\('
        . '|\bor\b\s*[\'"\d]|\.\.(\/|%2f){2,}|\/etc\/passwd|php:\/\/|convert\.base64'
        . '|<script|%3cscript|onerror=|onload=|javascript:)/';
    if (preg_match($sig, $request)) {
        if (shield_register_offense('watch')) {
            shield_guard();
        }
    }
}

function shield_complexity(string $req, string $qs): int {
    if (strlen($qs) > 60)                     return 1;
    if (substr_count($qs, '=') >= 4)          return 1;
    if (substr_count($req, '%') >= 5)         return 1;
    if (preg_match('#(/\*|\bunion\b|\bselect\b|php://|\.\./|convert\.base64|<|0x[0-9a-f]{4}|\bor\b\s|sleep\(|benchmark\()#i', $req)) return 1;
    return 0;
}

function shield_behavior(string $req, string $qs): void {
    $ip  = client_ip();
    $now = microtime(true);
    $st  = shield_state($ip);

    $path = strtok($req, '?');
    $cx   = shield_complexity($req, $qs);
    $hist = $st['hist'] ?? [];
    $hist[] = ['t' => $now, 'p' => $path, 'c' => $cx];
    if (count($hist) > SHIELD_HIST_KEEP) { $hist = array_slice($hist, -SHIELD_HIST_KEEP); }
    $st['hist'] = $hist;

    $rate = 0;
    foreach ($hist as $h) { if ($now - $h['t'] <= SHIELD_RATE_WINDOW) { $rate++; } }
    $sim = 0;
    for ($i = count($hist) - 1; $i >= 0; $i--) { if ($hist[$i]['p'] === $path) { $sim++; } else { break; } }
    $cxs = 0;
    for ($i = count($hist) - 1; $i >= 0; $i--) { if ($hist[$i]['c'] === 1) { $cxs++; } else { break; } }

    $reason = '';
    if     ($rate > SHIELD_RATE_MAX)                 { $reason = "burst:{$rate}/" . SHIELD_RATE_WINDOW . 's'; }
    elseif ($sim >= SHIELD_SIMILAR_MAX && $cxs >= 2) { $reason = "enum-pattern:{$sim}"; }
    elseif ($cxs >= SHIELD_COMPLEX_MAX)              { $reason = "complex-streak:{$cxs}"; }

    if ($reason !== '' && ($st['ban_until'] ?? 0) < time()) {
        $st['bans'] = ($st['bans'] ?? 0) + 1;
        $dur = ($st['bans'] >= 2) ? SHIELD_HARD_BAN : SHIELD_BEHAV_BAN;   // reincidente -> 30 min
        $st['ban_until']  = time() + $dur;
        $st['ban_reason'] = 'behavior';
        error_log("[acme-shield] BEHAV-BLOCK ip={$ip} reason={$reason} bans={$st['bans']} for {$dur}s");
        shield_save($ip, $st);
        shield_guard();
        return;
    }
    shield_save($ip, $st);
}

function waf_inspect(string $input): void {
    $signatures = [
        'sqli-union'   => '/union\s+select/i',
        'sqli-orderby' => '/order\s+by/i',
        'sqli-boolean' => '/[\'"]\s*(or|and)\s+[\'"\d]/i',
        'sqli-comment' => '/--\s/',
        'sqli-sleep'   => '/(sleep|benchmark)\s*\(/i',
    ];
    foreach ($signatures as $name => $re) {
        if (preg_match($re, $input)) {
            waf_block($name);
        }
    }
}
