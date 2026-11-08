<?php
declare(strict_types=1);
if (defined('NEO_FUNCTIONS_LOADED')) { return; }
define('NEO_FUNCTIONS_LOADED', true);
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('NEOSESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    session_start();
}
const SHIPPING_FLAT_RATE = 15000;

/* ---------- dasar ---------- */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rupiah($v): string { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }
function go(string $url): never { header('Location: ' . $url); exit; }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf()) . '">'; }
function check_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) { http_response_code(419); die('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.'); }
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/* ---------- pesan flash (muncul sekali setelah redirect) ---------- */
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type' => $type, 'message' => $message]; }
function flash_pull(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/* ---------- autentikasi & peran ---------- */
function roles(PDO $pdo, int $uid): array
{
    $s = $pdo->prepare("SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? ORDER BY r.name");
    $s->execute([$uid]);
    return array_column($s->fetchAll(), 'name');
}
function session_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    static $cache = [];
    $id = (int)$_SESSION['user_id'];
    if (isset($cache[$id])) return $cache[$id];
    $s = $pdo->prepare('SELECT id,name,email,phone,is_active FROM users WHERE id=? LIMIT 1');
    $s->execute([$id]);
    $u = $s->fetch();
    if (!$u || !$u['is_active']) { session_unset(); session_destroy(); return null; }
    $_SESSION['roles'] = roles($pdo, (int)$u['id']);
    $_SESSION['user_name'] = $u['name'];
    $_SESSION['user_email'] = $u['email'];
    return $cache[$id] = $u;
}
function login_required(PDO $pdo): array { $u = session_user($pdo); if (!$u) go('auth.php'); return $u; }
function has_role(string $r): bool { return in_array($r, $_SESSION['roles'] ?? [], true); }
function has_any(array $r): bool { return (bool)array_intersect($r, $_SESSION['roles'] ?? []); }
function need_role(PDO $pdo, array $r): array
{
    $u = login_required($pdo);
    if (!has_any($r)) { http_response_code(403); render_error_page(403, 'Akses ditolak', 'Peran akun Anda tidak memiliki izin untuk membuka halaman ini.'); }
    return $u;
}
function is_employee(): bool { return has_any(['admin', 'manager', 'staff', 'seller']); }
function label_role(string $r): string { return ['customer' => 'Buyer', 'seller' => 'Seller', 'staff' => 'Staff', 'manager' => 'Manager', 'admin' => 'Administrator'][$r] ?? ucfirst($r); }
function render_error_page(int $code, string $title, string $text): never
{
    http_response_code($code);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' | ' . APP_NAME . '</title><link rel="stylesheet" href="assets/neo.css"></head>'
       . '<body class="plain-page"><div class="auth-box" style="text-align:center"><div class="brand-mark" style="margin:0 auto 14px">N</div><h1>' . e($title) . '</h1><p class="muted">' . e($text) . '</p>'
       . '<p><a class="btn primary" href="dashboard.php">Kembali ke dashboard</a></p></div></body></html>';
    exit;
}

/* ---------- notifikasi & audit ---------- */
function notify(PDO $pdo, int $uid, string $type, string $title, string $msg, ?string $rt = null, ?int $rid = null): void
{
    $s = $pdo->prepare('INSERT INTO notifications(user_id,type,title,message,related_type,related_id) VALUES(?,?,?,?,?,?)');
    $s->execute([$uid, $type, $title, $msg, $rt, $rid]);
}
/** Kirim notifikasi ke semua pengguna aktif dengan salah satu peran tertentu. */
function notify_roles(PDO $pdo, array $roleNames, string $type, string $title, string $msg, ?string $rt = null, ?int $rid = null): int
{
    if (!$roleNames) return 0;
    $in = implode(',', array_fill(0, count($roleNames), '?'));
    $s = $pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 AND r.name IN ($in)");
    $s->execute($roleNames);
    $n = 0;
    foreach ($s->fetchAll() as $row) { notify($pdo, (int)$row['id'], $type, $title, $msg, $rt, $rid); $n++; }
    return $n;
}
function audit(PDO $pdo, ?int $uid, string $action, string $table, ?int $rid, ?array $old = null, ?array $new = null): void
{
    $s = $pdo->prepare('INSERT INTO audit_logs(user_id,action,table_name,record_id,old_data,new_data,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)');
    $s->execute([$uid, $action, $table, $rid, $old ? json_encode($old) : null, $new ? json_encode($new) : null, $_SERVER['REMOTE_ADDR'] ?? null, isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 250) : null]);
}

/* ---------- util produk ---------- */
function slugify(string $s): string { $s = mb_strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? ''; return trim($s, '-') ?: 'product-' . time(); }
function unique_slug(PDO $pdo, string $base, ?int $except = null): string
{
    $slug = $base; $i = 1;
    while (true) {
        $q = 'SELECT id FROM products WHERE slug=?'; $p = [$slug];
        if ($except !== null) { $q .= ' AND id<>?'; $p[] = $except; }
        $s = $pdo->prepare($q); $s->execute($p);
        if (!$s->fetch()) return $slug;
        $slug = $base . '-' . $i++;
    }
}
function employee_users(PDO $pdo): array
{
    $s = $pdo->query("SELECT DISTINCT u.id,u.name,u.email,GROUP_CONCAT(DISTINCT r.name SEPARATOR ', ') roles FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.name IN('admin','manager','staff','seller') AND u.is_active=1 GROUP BY u.id ORDER BY u.name");
    return $s->fetchAll();
}

/** Simpan gambar unggahan setelah divalidasi (ukuran, MIME asli). Mengembalikan nama file atau null. */
function save_uploaded_image(array $file, string $prefix = 'prod_'): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Unggahan gambar gagal (kode ' . (int)$file['error'] . ').');
    if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('Ukuran gambar maksimal 3 MB.');
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowed[$mime])) throw new RuntimeException('Format gambar harus JPG, PNG, atau WEBP.');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $prefix . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Gagal menyimpan gambar.');
    return $name;
}

/* ---------- tampilan: label, waktu, badge ---------- */
function time_ago(?string $dt): string
{
    if (!$dt) return '-';
    $t = strtotime($dt); if (!$t) return e($dt);
    $d = time() - $t;
    if ($d < 60) return 'baru saja';
    if ($d < 3600) return floor($d / 60) . ' menit lalu';
    if ($d < 86400) return floor($d / 3600) . ' jam lalu';
    if ($d < 86400 * 30) return floor($d / 86400) . ' hari lalu';
    return date('d M Y', $t);
}
function fmt_dt(?string $dt): string { return $dt ? date('d M Y, H:i', (int)strtotime($dt)) : '-'; }
function status_label(string $s): string
{
    static $m = ['pending' => 'Menunggu', 'processing' => 'Diproses', 'shipped' => 'Dikirim', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan',
        'paid' => 'Lunas', 'failed' => 'Gagal', 'refunded' => 'Dikembalikan', 'delivered' => 'Terkirim', 'returned' => 'Diretur',
        'in_progress' => 'Dikerjakan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'under_review' => 'Ditinjau', 'draft' => 'Draf', 'archived' => 'Arsip',
        'open' => 'Terbuka', 'acknowledged' => 'Diakui', 'resolved' => 'Selesai', 'dismissed' => 'Diabaikan'];
    return $m[$s] ?? ucfirst(str_replace('_', ' ', $s));
}
function status_tone(string $s): string
{
    return match ($s) {
        'completed', 'paid', 'delivered', 'approved', 'resolved', 'success', 'done' => 'success',
        'shipped', 'in_progress', 'acknowledged' => 'info',
        'processing', 'under_review', 'pending', 'open', 'draft' => 'warning',
        'cancelled', 'failed', 'rejected', 'returned', 'refunded' => 'danger',
        default => 'neutral',
    };
}
function badge(string $status, ?string $label = null): string { return '<span class="status-pill ' . status_tone($status) . '">' . e($label ?? status_label($status)) . '</span>'; }

/* ---------- ilustrasi produk (SVG) saat belum ada foto ---------- */
function color_hex(?string $name): string
{
    static $m = ['hitam' => '#1f2430', 'putih' => '#f2f3f6', 'abu-abu' => '#9aa1ad', 'navy' => '#1f3a5f', 'hijau sage' => '#9db59a', 'biru muda' => '#a9cdee',
        'merah marun' => '#7b2d3a', 'olive' => '#6b7043', 'krem' => '#e9dcc3', 'biru denim' => '#3f6a96', 'khaki' => '#c2a878', 'biru tua' => '#1d4f9c',
        'merah' => '#c8283d', 'hijau' => '#3b8f5a', 'biru' => '#3b6fd0', 'cokelat' => '#7a5230', 'kuning' => '#e7c33c', 'pink' => '#f0a5bd', 'ungu' => '#7b5fc7'];
    return $m[mb_strtolower(trim((string)$name))] ?? '#c7ced9';
}
function shade_hex(string $hex, float $f): string
{
    $hex = ltrim($hex, '#');
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $adj = static fn(int $c): int => (int)max(0, min(255, $f < 0 ? $c * (1 + $f) : $c + (255 - $c) * $f));
    return sprintf('#%02x%02x%02x', $adj($r), $adj($g), $adj($b));
}
function garment_svg(string $category, ?string $color = null, string $label = ''): string
{
    $fill = color_hex($color); $edge = shade_hex($fill, -0.28); $hi = shade_hex($fill, 0.22);
    $cat = mb_strtolower($category);
    // token gaya: warna dapat diganti lewat variabel CSS --gf (badan), --gh (sorot), --ge (garis)
    $F = 'style="fill:var(--gf,' . $fill . ');stroke:var(--ge,' . $edge . ')"';
    $H = 'style="fill:var(--gh,' . $hi . ');stroke:var(--ge,' . $edge . ')"';
    $L = 'style="fill:none;stroke:var(--ge,' . $edge . ')"';
    $D = 'style="fill:var(--ge,' . $edge . ')"';
    $paths = match (true) {
        str_contains($cat, 'hood') => '<path d="M70 38 Q100 8 130 38 L128 58 Q100 72 72 58 Z" ' . $H . ' stroke-width="2.4"/><path d="M64 52 L24 82 L36 128 L56 118 L58 200 L142 200 L144 118 L164 128 L176 82 L136 52 Q100 78 64 52Z" ' . $F . ' stroke-width="2.4" stroke-linejoin="round"/><path d="M70 150 Q100 142 130 150 L126 184 L74 184 Z" ' . $H . ' opacity=".55" stroke-width="1.6"/><path d="M92 62 L90 96 M108 62 L110 96" ' . $L . ' stroke-width="2.2" stroke-linecap="round"/>',
        str_contains($cat, 'jacket') || str_contains($cat, 'jaket') => '<path d="M70 34 L100 52 L130 34 L158 46 L182 104 L160 112 L148 90 L146 200 L54 200 L52 90 L40 112 L18 104 L42 46 Z" ' . $F . ' stroke-width="2.4" stroke-linejoin="round"/><path d="M100 52 L100 200" ' . $L . ' stroke-width="2.4"/><path d="M70 34 L100 52 L84 66 L62 48 Z M130 34 L100 52 L116 66 L138 48 Z" ' . $H . ' stroke-width="2"/><path d="M66 150 L88 150 M112 150 L134 150" ' . $L . ' stroke-width="2.4" stroke-linecap="round"/>',
        str_contains($cat, 'trouser') || str_contains($cat, 'celana') || str_contains($cat, 'pant') => '<path d="M58 22 L142 22 L150 70 L142 204 L108 204 L100 92 L92 204 L58 204 L50 70 Z" ' . $F . ' stroke-width="2.4" stroke-linejoin="round"/><path d="M58 22 L142 22 L143 36 L57 36 Z" ' . $H . ' stroke-width="2"/><path d="M100 36 L100 92" ' . $L . ' stroke-width="2"/><path d="M66 56 Q74 66 80 56" ' . $L . ' stroke-width="1.8"/>',
        str_contains($cat, 'shirt') && !str_contains($cat, 't-') => '<path d="M72 30 L100 44 L128 30 L162 50 L184 112 L160 120 L146 94 L146 200 L54 200 L54 94 L40 120 L16 112 L38 50 Z" ' . $F . ' stroke-width="2.4" stroke-linejoin="round"/><path d="M72 30 L100 44 L86 62 L66 44 Z M128 30 L100 44 L114 62 L134 44 Z" ' . $H . ' stroke-width="2"/><path d="M100 44 L100 200" ' . $L . ' stroke-width="2"/><circle cx="100" cy="82" r="2.2" ' . $D . '/><circle cx="100" cy="112" r="2.2" ' . $D . '/><circle cx="100" cy="142" r="2.2" ' . $D . '/><circle cx="100" cy="172" r="2.2" ' . $D . '/>',
        default => '<path d="M70 28 Q100 54 130 28 L170 50 L188 96 L160 108 L146 88 L146 200 L54 200 L54 88 L40 108 L12 96 L30 50 Z" ' . $F . ' stroke-width="2.4" stroke-linejoin="round"/><path d="M70 28 Q100 54 130 28" ' . $L . ' stroke-width="3.2"/><path d="M72 32 Q100 58 128 32" style="fill:none;stroke:var(--gh,' . $hi . ')" stroke-width="2" opacity=".7"/>',
    };
    $aria = e($label !== '' ? $label : ($category . ' ' . (string)$color));
    return '<svg viewBox="0 0 200 224" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . $aria . '" class="garment-svg">' . $paths . '</svg>';
}
/** Kartu gambar produk: foto bila ada, jika tidak ilustrasi SVG. */
function product_visual(?string $image, string $category, ?string $color, string $name): string
{
    if ($image && is_file(__DIR__ . '/uploads/' . basename($image))) {
        return '<img src="uploads/' . e(rawurlencode(basename($image))) . '" alt="' . e($name) . '" loading="lazy">';
    }
    return garment_svg($category, $color, $name);
}

/* ---------- alias kompatibilitas lama ---------- */
if (!function_exists('redirect')) { function redirect(string $url): never { go($url); } }
if (!function_exists('csrf_token')) { function csrf_token(): string { return csrf(); } }
if (!function_exists('verify_csrf')) { function verify_csrf(): void { check_csrf(); } }
if (!function_exists('user_has_role')) { function user_has_role(string $role): bool { return has_role($role); } }
if (!function_exists('user_has_any_role')) { function user_has_any_role(array $roles): bool { return has_any($roles); } }
if (!function_exists('role_label')) { function role_label(string $role): string { return label_role($role); } }

/* ---------- ikon SVG (gaya garis) ---------- */
function icon(string $name, string $class = ''): string
{
    static $p = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h8.1a2 2 0 0 0 2-1.5L21 8H6"/>',
        'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'pin' => '<path d="M12 22s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
        'box' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/>',
        'layers' => '<path d="M12 2l10 5-10 5L2 7z"/><path d="M2 12l10 5 10-5M2 17l10 5 10-5"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
        'file' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h6"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'bell' => '<path d="M18 16V11a6 6 0 0 0-12 0v5l-2 2h16z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/>',
        'star' => '<path d="M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0"/><circle cx="17" cy="9" r="2.5"/><path d="M17 14a5 5 0 0 1 5 5"/>',
        'truck' => '<path d="M2 6h11v10H2zM13 9h4l4 4v3h-8z"/><circle cx="6" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
        'book' => '<path d="M4 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4z"/><path d="M20 4h-4a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h4z"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'cpu' => '<rect x="6" y="6" width="12" height="12" rx="2"/><rect x="9.5" y="9.5" width="5" height="5"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
        'sparkles' => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
        'sliders' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'alert' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>',
        'play' => '<path d="M7 4l13 8-13 8z"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'snow' => '<path d="M12 2v20M4 6l16 12M20 6L4 18"/>',
        'cloud' => '<path d="M7 18a4 4 0 0 1-.6-8A6 6 0 0 1 18 9a4.5 4.5 0 0 1 0 9z"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
        'zap' => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    ];
    return '<svg class="ico ' . e($class) . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? $p['grid']) . '</svg>';
}

/**
 * Membatalkan pesanan yang belum dibayar dan mengembalikan stok (dipakai pembeli, staf, dan Expert System).
 * Mengembalikan [berhasil(bool), pesan].
 */
function cancel_unpaid_order(PDO $pdo, int $orderId, ?int $actorId, string $note = 'Pembatalan pesanan'): array
{
    $pdo->beginTransaction();
    try {
        $o = $pdo->prepare('SELECT id, order_number, status, payment_status, user_id FROM orders WHERE id = ? FOR UPDATE');
        $o->execute([$orderId]);
        $order = $o->fetch();
        if (!$order || $order['status'] !== 'pending' || $order['payment_status'] !== 'pending') {
            $pdo->rollBack();
            return [false, 'Pesanan tidak lagi berstatus menunggu pembayaran.'];
        }
        $items = $pdo->prepare('SELECT variation_id, quantity FROM order_items WHERE order_id = ?');
        $items->execute([$orderId]);
        foreach ($items->fetchAll() as $it) {
            if (!$it['variation_id']) continue;
            $s = $pdo->prepare('SELECT stock FROM product_variations WHERE id = ? FOR UPDATE');
            $s->execute([$it['variation_id']]);
            $before = (int)$s->fetchColumn();
            $pdo->prepare('UPDATE product_variations SET stock = stock + ? WHERE id = ?')->execute([$it['quantity'], $it['variation_id']]);
            $pdo->prepare("INSERT INTO inventory_movements (variation_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, notes, created_by) VALUES (?, 'return', ?, ?, ?, 'order', ?, ?, ?)")
                ->execute([$it['variation_id'], $it['quantity'], $before, $before + (int)$it['quantity'], $orderId, $note, $actorId]);
        }
        $pdo->prepare("UPDATE orders SET status='cancelled', shipping_status='cancelled' WHERE id = ?")->execute([$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    notify($pdo, (int)$order['user_id'], 'order', 'Pesanan dibatalkan', 'Pesanan ' . $order['order_number'] . ' dibatalkan. ' . $note . '.', 'order', $orderId);
    audit($pdo, $actorId, 'CANCEL_UNPAID', 'orders', $orderId, ['status' => 'pending'], ['status' => 'cancelled', 'note' => $note]);
    return [true, 'Pesanan ' . $order['order_number'] . ' dibatalkan dan stok dikembalikan.'];
}
