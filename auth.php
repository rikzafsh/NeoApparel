<?php
require_once 'db.php';
require_once 'functions.php';
if (session_user($pdo)) go('dashboard.php');

$error = '';
$success = '';
$activeTab = 'login';
$MAX_ATTEMPTS = 6;          // percobaan gagal per 15 menit (email + IP)

function login_blocked(PDO $pdo, string $email, int $max): bool
{
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $s->execute([client_ip(), mb_strtolower($email)]);
        return (int)$s->fetchColumn() >= $max;
    } catch (Throwable $e) { return false; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $activeTab = 'register';
        $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $phone = trim($_POST['phone'] ?? ''); $pw = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8) {
            $error = 'Nama, email yang valid, dan kata sandi minimal 8 karakter wajib diisi.';
        } else {
            try {
                $pdo->beginTransaction();
                $s = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $s->execute([$email]);
                if ($s->fetch()) throw new RuntimeException('Email sudah terdaftar. Silakan masuk.');
                $pdo->prepare('INSERT INTO users(name,email,password,phone) VALUES(?,?,?,?)')->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), $phone ?: null]);
                $uid = (int)$pdo->lastInsertId();
                $rid = $pdo->query("SELECT id FROM roles WHERE name='customer' LIMIT 1")->fetchColumn();
                if (!$rid) throw new RuntimeException('Peran "customer" belum ada. Impor ulang sql/10_seed_main.sql.');
                $pdo->prepare('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)')->execute([$uid, $rid]);
                $pdo->commit();
                $success = 'Akun berhasil dibuat. Silakan masuk.'; $activeTab = 'login';
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
        }
    }
    if ($action === 'login') {
        $email = trim($_POST['email'] ?? ''); $pw = $_POST['password'] ?? '';
        if (login_blocked($pdo, $email, $MAX_ATTEMPTS)) {
            $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
        } else {
            $s = $pdo->prepare('SELECT id,name,password,is_active FROM users WHERE email=? LIMIT 1'); $s->execute([$email]); $u = $s->fetch();
            if ($u && $u['is_active'] && password_verify($pw, $u['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id']; $_SESSION['csrf'] = bin2hex(random_bytes(32));
                try { $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([mb_strtolower($email)]); } catch (Throwable $e) {}
                session_user($pdo);
                audit($pdo, (int)$u['id'], 'LOGIN', 'users', (int)$u['id']);
                go('dashboard.php');
            }
            try { $pdo->prepare('INSERT INTO login_attempts(email,ip_address) VALUES(?,?)')->execute([mb_strtolower($email), client_ip()]); } catch (Throwable $e) {}
            $error = 'Email atau kata sandi tidak sesuai.';
        }
    }
}
$demo = neo_config('show_demo_accounts', true) ? [
    ['Administrator', 'admin@neoapparel.test', 'DSS · semua modul'], ['Manager', 'manager@neoapparel.test', 'Approval · MIS'],
    ['Staf operasional', 'staff@neoapparel.test', 'Pesanan · tugas'], ['Seller', 'seller@neoapparel.test', 'Kelola produk'],
    ['Pembeli', 'budi@neoapparel.test', 'Belanja · Neo Stylist'],
] : [];
?>
<!doctype html>
<html lang="id"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Masuk | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/neo.css">
</head>
<body class="auth-page">
<section class="auth-hero" aria-hidden="true">
    <div class="brand"><span class="brand-mark">N</span><?= e(APP_NAME) ?></div>
    <div>
        <h2>Fashion yang cerdas, operasional yang rapi.</h2>
        <p>Satu platform untuk belanja, operasional toko, analitik, pemilihan supplier, dan pengetahuan tim, dengan sistem pakar yang membaca semuanya dan memberi rekomendasi yang bisa dijelaskan.</p>
        <div class="garments"><div class="g"><?= garment_svg('T-Shirt', 'Putih') ?></div><div class="g"><?= garment_svg('Hoodie', 'Hijau Sage') ?></div><div class="g"><?= garment_svg('Jacket', 'Hitam') ?></div><div class="g"><?= garment_svg('Trousers', 'Navy') ?></div></div>
    </div>
    <div class="auth-points">
        <span><?= icon('check') ?>Belanja &amp; Neo Stylist</span><span><?= icon('check') ?>Pusat Pakar (Expert System)</span>
        <span><?= icon('check') ?>Stok, tugas &amp; persetujuan</span><span><?= icon('check') ?>MIS, DSS &amp; KMS terhubung</span>
    </div>
</section>
<main class="auth-pane">
<div class="auth-box">
    <h1>Selamat datang</h1>
    <p class="muted">Masuk ke akun Neo Apparel Anda. Pembeli dan karyawan memakai halaman yang sama.</p>
    <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
    <div class="seg" role="tablist">
        <button type="button" data-tab="login" class="<?= $activeTab === 'login' ? 'on' : '' ?>" onclick="tab('login')">Masuk</button>
        <button type="button" data-tab="register" class="<?= $activeTab === 'register' ? 'on' : '' ?>" onclick="tab('register')">Daftar pembeli</button>
    </div>
    <div id="login" style="display:<?= $activeTab === 'login' ? 'block' : 'none' ?>">
        <form method="post" autocomplete="on">
            <?= csrf_field() ?><input type="hidden" name="action" value="login">
            <label for="l_email">Email</label><input id="l_email" type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>">
            <label for="l_pw">Kata sandi</label>
            <div class="pw-wrap"><input id="l_pw" type="password" name="password" required autocomplete="current-password"><button type="button" aria-label="Tampilkan kata sandi" onclick="var i=document.getElementById('l_pw');i.type=i.type==='password'?'text':'password'"><?= icon('eye') ?></button></div>
            <button class="btn primary" style="width:100%;margin-top:18px">Masuk</button>
        </form>
        <?php if ($demo): ?>
        <div class="demo-box"><strong>Akun demo <small style="font-weight:600;color:#7b7fa3">· kata sandi: NeoApparel123!</small></strong>
            <?php foreach ($demo as $d): ?><button type="button" onclick="document.getElementById('l_email').value='<?= e($d[1]) ?>';document.getElementById('l_pw').value='NeoApparel123!'"><span><?= e($d[0]) ?></span><small><?= e($d[2]) ?></small></button><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div id="register" style="display:<?= $activeTab === 'register' ? 'block' : 'none' ?>">
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="register">
            <label>Nama lengkap</label><input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
            <label>Email</label><input type="email" name="email" required>
            <label>No. telepon</label><input type="text" name="phone" inputmode="tel">
            <label>Kata sandi (min. 8 karakter)</label><input type="password" name="password" minlength="8" required autocomplete="new-password">
            <button class="btn primary" style="width:100%;margin-top:18px">Buat akun pembeli</button>
            <p class="muted center small">Pendaftaran publik hanya membuat peran pembeli. Peran karyawan diberikan administrator.</p>
        </form>
    </div>
</div>
</main>
<script src="script.js"></script>
</body></html>
