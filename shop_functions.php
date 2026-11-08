<?php
declare(strict_types=1);
/** Fungsi bersama etalase Neo Apparel: data produk, rating, jumlah terjual, dan kartu mini. */
require_once __DIR__ . '/functions.php';

/**
 * Mengambil produk aktif beserta variasi, rating, dan jumlah terjual.
 * Filter: id, ids[], seller_id, category (nama), exclude[] (id produk).
 * @return array<int,array>
 */
function shop_products(PDO $pdo, array $f = []): array
{
    $where = ['p.is_active = 1']; $args = [];
    if (!empty($f['id'])) { $where[] = 'p.id = ?'; $args[] = (int)$f['id']; }
    if (!empty($f['ids'])) { $where[] = 'p.id IN (' . implode(',', array_map('intval', $f['ids'])) . ')'; }
    if (!empty($f['seller_id'])) { $where[] = 'p.seller_id = ?'; $args[] = (int)$f['seller_id']; }
    if (!empty($f['category'])) { $where[] = 'c.name = ?'; $args[] = (string)$f['category']; }
    if (!empty($f['exclude'])) { $where[] = 'p.id NOT IN (' . implode(',', array_map('intval', $f['exclude'])) . ')'; }
    $st = $pdo->prepare("SELECT p.id pid, p.name, p.description, p.created_at, p.seller_id, su.name seller_name, c.name category, b.name brand,
            v.id vid, v.sku, v.color, v.size, v.price, v.stock, v.image, v.weight_gram
        FROM products p
        JOIN product_variations v ON v.product_id = p.id AND v.is_active = 1
        JOIN users su ON su.id = p.seller_id
        LEFT JOIN product_categories pc ON pc.product_id = p.id LEFT JOIN categories c ON c.id = pc.category_id
        LEFT JOIN brands b ON b.id = p.brand_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY p.created_at DESC, p.id, v.id');
    $st->execute($args);
    $products = [];
    foreach ($st->fetchAll() as $r) {
        $pid = (int)$r['pid'];
        if (!isset($products[$pid])) {
            $products[$pid] = ['id' => $pid, 'name' => $r['name'], 'desc' => (string)$r['description'], 'cat' => $r['category'] ?: 'Lainnya', 'brand' => $r['brand'],
                'created' => $r['created_at'], 'seller_id' => (int)$r['seller_id'], 'seller_name' => $r['seller_name'], 'vars' => [], 'sold' => 0, 'sold30' => 0, 'avg' => null, 'n' => 0];
        }
        $products[$pid]['vars'][] = ['id' => (int)$r['vid'], 'sku' => $r['sku'], 'color' => $r['color'] ?: '-', 'size' => $r['size'] ?: '-', 'price' => (float)$r['price'],
            'stock' => (int)$r['stock'], 'hex' => color_hex($r['color']), 'image' => $r['image'], 'weight' => $r['weight_gram']];
    }
    if (!$products) { return []; }
    $ids = implode(',', array_keys($products));
    foreach ($pdo->query("SELECT v.product_id pid, SUM(oi.quantity) q, SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN oi.quantity ELSE 0 END) q30
        FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN product_variations v ON v.id = oi.variation_id
        WHERE o.payment_status = 'paid' AND o.status <> 'cancelled' AND v.product_id IN ($ids) GROUP BY v.product_id")->fetchAll() as $r) {
        $products[(int)$r['pid']]['sold'] = (int)$r['q']; $products[(int)$r['pid']]['sold30'] = (int)$r['q30'];
    }
    foreach ($pdo->query("SELECT product_id pid, AVG(rating) a, COUNT(*) n FROM product_reviews WHERE status = 'published' AND product_id IN ($ids) GROUP BY product_id")->fetchAll() as $r) {
        $products[(int)$r['pid']]['avg'] = round((float)$r['a'], 1); $products[(int)$r['pid']]['n'] = (int)$r['n'];
    }
    foreach ($products as &$p) {
        $prices = array_column($p['vars'], 'price');
        $p['min'] = min($prices); $p['max'] = max($prices); $p['stock'] = array_sum(array_column($p['vars'], 'stock'));
        $first = null;
        foreach ($p['vars'] as $v) { if ($v['stock'] > 0) { $first = $v; break; } }
        $p['lead'] = $first ?? $p['vars'][0];
    }
    unset($p);
    return $products;
}

function shop_sold_label(int $n): string
{
    if ($n >= 1000) { return number_format($n / 1000, 1, ',', '') . 'rb+'; }
    return number_format($n, 0, ',', '.');
}
function shop_stars(?float $avg): string
{
    if ($avg === null) { return '<span class="muted small">Belum ada ulasan</span>'; }
    return '<span class="stars">★</span><b>' . number_format($avg, 1, ',', '') . '</b>';
}
function shop_price_label(array $p): string
{
    return $p['min'] === $p['max'] ? rupiah($p['min']) : rupiah($p['min']) . ' – ' . rupiah($p['max']);
}

/** Kartu mini untuk carousel/grid (tanpa pemilih varian); seluruh kartu menaut ke halaman detail. */
function shop_mini_card(array $p): string
{
    $lead = $p['lead'];
    $badge = $p['stock'] <= 0 ? '<span class="pill warn">Habis</span>' : ($p['sold30'] >= 10 ? '<span class="pill hot">Laris</span>' : '');
    $vis = product_visual($lead['image'], $p['cat'], $lead['color'], $p['name']);
    return '<a class="mini-card" href="product.php?id=' . (int)$p['id'] . '"><div class="visual">' . ($badge ? '<div class="p-badges">' . $badge . '</div>' : '') . $vis . '</div>'
        . '<div class="mini-body"><span class="p-cat">' . e($p['cat']) . '</span><h3>' . e($p['name']) . '</h3>'
        . '<div class="price">' . e(shop_price_label($p)) . '</div>'
        . '<div class="p-meta">' . shop_stars($p['avg']) . ($p['sold'] > 0 ? '<span>· ' . e(shop_sold_label($p['sold'])) . ' terjual</span>' : '') . '</div></div></a>';
}

/** Nama pembeli disamarkan untuk ulasan publik: "Budi Pratama" -> "B***i". */
function shop_mask_name(string $name): string
{
    $first = mb_strtolower(trim(explode(' ', trim($name))[0]));
    if (mb_strlen($first) < 2) { return mb_strtoupper($first) . '***'; }
    return mb_strtoupper(mb_substr($first, 0, 1)) . '***' . mb_substr($first, -1);
}

/** Catat produk yang baru dilihat (sesi). */
function shop_remember_view(int $pid): void
{
    $r = $_SESSION['recent_products'] ?? [];
    $r = array_values(array_diff($r, [$pid]));
    array_unshift($r, $pid);
    $_SESSION['recent_products'] = array_slice($r, 0, 10);
}
