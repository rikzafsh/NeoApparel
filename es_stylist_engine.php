<?php
declare(strict_types=1);
/**
 * Neo Stylist - Expert System rekomendasi untuk pembeli (TPS).
 * Jawaban pembeli menjadi fakta "style_session"; aturan domain STYLE menyala dan memberi bobot pada kategori/atribut produk.
 * Skor akhir = jumlah (bobot x CF aturan). Setiap rekomendasi membawa alasan yang bisa dibaca manusia.
 */
require_once __DIR__ . '/es_engine.php';

const STYLE_OPTIONS = [
    'occasion' => ['label' => 'Untuk acara apa?', 'icon' => 'briefcase', 'opts' => ['kerja' => 'Kerja', 'kuliah' => 'Kuliah', 'santai' => 'Santai', 'hangout' => 'Hangout', 'acara formal' => 'Acara formal', 'olahraga' => 'Olahraga']],
    'climate' => ['label' => 'Cuaca / suasana?', 'icon' => 'sun', 'opts' => ['panas' => 'Panas & lembap', 'ber-ac' => 'Ruangan ber-AC', 'sejuk' => 'Sejuk / dingin', 'hujan' => 'Musim hujan']],
    'style_pref' => ['label' => 'Gaya favorit?', 'icon' => 'sparkles', 'opts' => ['minimalis' => 'Minimalis', 'streetwear' => 'Streetwear', 'klasik' => 'Klasik', 'sporty' => 'Sporty', 'bebas' => 'Bebas']],
    'fit_pref' => ['label' => 'Potongan yang nyaman?', 'icon' => 'user', 'opts' => ['slim' => 'Slim', 'regular' => 'Regular', 'oversize' => 'Oversize', 'bebas' => 'Bebas']],
    'color_pref' => ['label' => 'Nuansa warna?', 'icon' => 'tag', 'opts' => ['netral' => 'Netral', 'gelap' => 'Gelap', 'terang' => 'Terang', 'earth' => 'Earth tone', 'bebas' => 'Bebas']],
];
const COLOR_TONES = [
    'netral' => ['Hitam', 'Putih', 'Abu-abu', 'Krem', 'Navy', 'Khaki'],
    'gelap' => ['Hitam', 'Navy', 'Merah Marun', 'Olive', 'Biru Tua', 'Biru Denim'],
    'terang' => ['Putih', 'Krem', 'Biru Muda', 'Hijau Sage', 'Merah'],
    'earth' => ['Olive', 'Krem', 'Khaki', 'Merah Marun', 'Hijau Sage'],
];
const NEUTRAL_COLORS = ['Hitam', 'Putih', 'Abu-abu', 'Krem', 'Navy', 'Khaki', 'Biru Denim'];
const TOP_CATEGORIES = ['T-Shirt', 'Shirt', 'Hoodie', 'Jacket'];

function es_style_clean_answers(array $in): array
{
    $a = [];
    foreach (STYLE_OPTIONS as $k => $meta) {
        $v = mb_strtolower(trim((string)($in[$k] ?? '')));
        $a[$k] = isset($meta['opts'][$v]) ? $v : 'bebas';
        if ($k === 'occasion' && !isset($meta['opts'][$v])) { $a[$k] = 'santai'; }
        if ($k === 'climate' && !isset($meta['opts'][$v])) { $a[$k] = 'ber-ac'; }
    }
    $a['budget'] = max(50000, min(2000000, (int)($in['budget'] ?? 400000)));
    $size = strtoupper(trim((string)($in['size'] ?? '')));
    $a['size'] = in_array($size, ['S', 'M', 'L', 'XL', '30', '32', '34'], true) ? $size : '';
    return $a;
}

/** @return array{recommendations: array, outfits: array, fired: array, answers: array} */
function es_stylist_recommend(PDO $pdo, array $answersIn, int $limit = 6): array
{
    $answers = es_style_clean_answers($answersIn);
    $params = es_params($pdo);
    $rules = es_load_rules($pdo, ['STYLE']);
    $facts = $answers;
    $entity = es_entity('style_session', 0, 'Sesi Neo Stylist', $facts);
    $inf = es_infer([$entity], $rules, $params);
    $fired = [];
    $theoretical = 0.0;
    foreach ($inf['results'] as $r) {
        $d = $r['rule']['derives']; $boost = $d['boost'] ?? [];
        $cf = (float)$r['cf'];
        $maxCat = !empty($boost['cat']) ? max(array_merge([0], array_values($boost['cat']))) : 0;
        $sumAttr = 0.0; foreach (($boost['attr'] ?? []) as $b) { if ($b > 0) $sumAttr += $b; }
        $theoretical += $cf * ($maxCat + $sumAttr);
        $fired[] = ['code' => $r['rule']['code'], 'name' => $r['rule']['name'], 'cf' => $cf, 'boost' => $boost, 'why' => $d['why'] ?? $r['rule']['name']];
    }
    $theoretical = max($theoretical, 0.01);

    // produk + variasi tersedia
    $rows = $pdo->query("SELECT p.id pid, p.name, p.description, c.name category, v.id vid, v.sku, v.color, v.size, v.price, v.stock, v.image
        FROM products p JOIN product_variations v ON v.product_id = p.id AND v.is_active = 1 AND v.stock > 0
        LEFT JOIN product_categories pc ON pc.product_id = p.id LEFT JOIN categories c ON c.id = pc.category_id WHERE p.is_active = 1")->fetchAll();
    $attrs = [];
    foreach ($pdo->query('SELECT product_id, attribute, value FROM es_product_attributes')->fetchAll() as $r) { $attrs[(int)$r['product_id']][$r['attribute'] . ':' . $r['value']] = true; }
    $products = [];
    foreach ($rows as $r) {
        $pid = (int)$r['pid'];
        $products[$pid]['info'] = ['id' => $pid, 'name' => $r['name'], 'category' => $r['category'] ?: 'Lainnya'];
        $products[$pid]['vars'][] = $r;
    }
    $tone = COLOR_TONES[$answers['color_pref']] ?? null;
    $scored = [];
    foreach ($products as $pid => $p) {
        $raw = 0.0; $reasons = []; $codes = [];
        foreach ($fired as $f) {
            $contrib = 0.0;
            $cb = $f['boost']['cat'][$p['info']['category']] ?? 0;
            $contrib += $cb * $f['cf'];
            foreach (($f['boost']['attr'] ?? []) as $key => $b) { if (isset($attrs[$pid][$key])) { $contrib += $b * $f['cf']; } }
            if ($contrib != 0.0) { $raw += $contrib; $codes[] = $f['code']; if ($contrib > 0) { $reasons[(string)$f['why']] = $contrib; } }
        }
        // pilih variasi terbaik: ukuran > nuansa warna > anggaran
        $best = null; $bestScore = -INF; $notes = [];
        foreach ($p['vars'] as $v) {
            $s = 0.0;
            if ($answers['size'] !== '' && ctype_digit((string)$v['size']) === ctype_digit($answers['size'])) { $s += ($v['size'] === $answers['size']) ? 3 : 0; }
            if ($tone && in_array($v['color'], $tone, true)) { $s += 2; }
            if ((float)$v['price'] <= $answers['budget']) { $s += 1; }
            $s += min((int)$v['stock'], 10) / 100;
            if ($s > $bestScore) { $bestScore = $s; $best = $v; }
        }
        if (!$best) { continue; }
        if ($tone && in_array($best['color'], $tone, true) && $answers['color_pref'] !== 'bebas') { $raw += 0.15; $reasons['Warna ' . $best['color'] . ' sesuai nuansa ' . $answers['color_pref'] . ' pilihan Anda.'] = 0.15; }
        if ($answers['size'] !== '' && ctype_digit((string)$best['size']) === ctype_digit($answers['size'])) {
            if ($best['size'] === $answers['size']) { $reasons['Ukuran ' . $answers['size'] . ' tersedia.'] = 0.05; }
            else { $notes[] = 'Ukuran ' . $answers['size'] . ' sedang habis; tersedia ukuran ' . $best['size'] . '.'; $raw *= 0.8; }
        }
        $price = (float)$best['price'];
        if ($price > $answers['budget'] * 1.3) { continue; }
        if ($price > $answers['budget']) { $raw *= 0.6; $notes[] = 'Sedikit di atas anggaran (' . rupiah($answers['budget']) . ').'; }
        else { $reasons['Harga ' . rupiah($price) . ' masih dalam anggaran Anda.'] = 0.04; }
        if ($raw <= 0) { continue; }
        arsort($reasons);
        $match = $raw; // dinormalisasi setelah seluruh produk dinilai
        $options = [];
        foreach ($p['vars'] as $v) { $options[$v['color']][] = $v['size']; }
        $scored[] = ['product' => $p['info'], 'variation' => $best, 'match' => $match, 'raw' => $raw, 'reasons' => array_slice(array_keys($reasons), 0, 4), 'notes' => $notes, 'rules' => array_values(array_unique($codes)), 'options' => $options];
    }
    usort($scored, static fn($a, $b) => $b['raw'] <=> $a['raw']);
    $maxRaw = $scored ? max(array_column($scored, 'raw')) : 1.0;
    foreach ($scored as &$sc) {   // kecocokan = campuran skor absolut (terhadap maksimum teoretis) dan relatif (terhadap yang terbaik)
        $abs = min(1.0, $sc['raw'] / $theoretical); $rel = $sc['raw'] / max($maxRaw, 0.0001);
        $sc['match'] = (int)max(5, min(97, round(100 * (0.55 * $abs + 0.45 * $rel * 0.95))));
    }
    unset($sc);
    $recs = array_slice($scored, 0, $limit);

    // pasangan outfit: atasan + celana dengan harmoni warna
    $tops = array_values(array_filter($scored, static fn($s) => in_array($s['product']['category'], TOP_CATEGORIES, true)));
    $bottoms = array_values(array_filter($scored, static fn($s) => $s['product']['category'] === 'Trousers'));
    $outfits = [];
    foreach (array_slice($tops, 0, 4) as $t) {
        foreach (array_slice($bottoms, 0, 4) as $b) {
            $ct = $t['variation']['color']; $cb = $b['variation']['color'];
            $harmony = in_array($ct, NEUTRAL_COLORS, true) || in_array($cb, NEUTRAL_COLORS, true);
            if (!$harmony || $ct === $cb && !in_array($ct, NEUTRAL_COLORS, true)) { continue; }
            $why = in_array($cb, NEUTRAL_COLORS, true) ? ('Celana ' . mb_strtolower($cb) . ' adalah warna netral yang mudah dipadukan dengan ' . mb_strtolower($ct) . '.') : ('Atasan ' . mb_strtolower($ct) . ' yang netral menyeimbangkan celana ' . mb_strtolower($cb) . '.');
            $outfits[] = ['top' => $t, 'bottom' => $b, 'score' => $t['raw'] + $b['raw'], 'total' => (float)$t['variation']['price'] + (float)$b['variation']['price'], 'why' => $why];
        }
    }
    usort($outfits, static fn($a, $b) => $b['score'] <=> $a['score']);
    $outfits = array_slice($outfits, 0, 2);
    return ['recommendations' => $recs, 'outfits' => $outfits, 'fired' => $fired, 'answers' => $answers];
}
