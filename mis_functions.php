<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** MIS hanya membaca data transaksi dari TPS. */
function mis_require_employee(array $roles = ['staff', 'manager', 'admin']): array
{
    global $pdo;
    return need_role($pdo, $roles);
}

function mis_date(string $value, string $fallback): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : $fallback;
}

/** Rentang bawaan 30 hari dan batas maksimal 366 hari agar query tetap ringan. */
function mis_period(): array
{
    $today = date('Y-m-d');
    $defaultStart = date('Y-m-d', strtotime('-29 days'));
    $start = mis_date((string)($_GET['start'] ?? ''), $defaultStart);
    $end = mis_date((string)($_GET['end'] ?? ''), $today);

    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }

    $days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;
    $GLOBALS['mis_period_limited'] = false;
    if ($days > 366) {
        $start = date('Y-m-d', strtotime($end . ' -365 days'));
        $GLOBALS['mis_period_limited'] = true;
    }

    return [$start, $end];
}

function mis_date_label(string $start, string $end): string
{
    return date('d M Y', strtotime($start)) . ' – ' . date('d M Y', strtotime($end));
}

function mis_number(float|int|string|null $value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function mis_percent(float $value): string
{
    return number_format($value, 1, ',', '.') . '%';
}

function mis_sales_condition(string $alias = 'o'): string
{
    return "$alias.payment_status = 'paid' AND $alias.status <> 'cancelled'";
}

function mis_bounds(string $start, string $end): array
{
    return [$start . ' 00:00:00', $end . ' 23:59:59'];
}

function mis_selected_query(array $extra = []): string
{
    $params = [
        'start' => $extra['start'] ?? $_GET['start'] ?? '',
        'end' => $extra['end'] ?? $_GET['end'] ?? '',
    ];
    foreach (['metric', 'report'] as $key) {
        if (isset($extra[$key])) {
            $params[$key] = $extra[$key];
        } elseif (isset($_GET[$key])) {
            $params[$key] = $_GET[$key];
        }
    }
    return http_build_query($params);
}

function mis_status_label(string $status): string
{
    return [
        'pending' => 'Menunggu',
        'processing' => 'Diproses',
        'shipped' => 'Dikirim',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'paid' => 'Lunas',
        'failed' => 'Gagal',
        'refunded' => 'Dikembalikan',
        'success' => 'Berhasil',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function mis_compact_number(float|int $value): string
{
    $abs = abs((float)$value);
    if ($abs >= 1_000_000_000) return number_format($value / 1_000_000_000, 1, ',', '.') . ' M';
    if ($abs >= 1_000_000) return number_format($value / 1_000_000, 1, ',', '.') . ' jt';
    if ($abs >= 10_000) return number_format($value / 1_000, 0, ',', '.') . ' rb';
    if ($abs >= 1_000) return number_format($value / 1_000, 1, ',', '.') . ' rb';
    return mis_number($value);
}

/** Pendapatan dan jumlah unit selalu diagregasi dalam query berbeda supaya total order tidak berlipat. */
function mis_fetch_daily_sales(PDO $pdo, string $start, string $end): array
{
    [$from, $to] = mis_bounds($start, $end);

    $salesStmt = $pdo->prepare("SELECT DATE(o.created_at) AS sales_date, COUNT(*) AS orders_count,
            COALESCE(SUM(o.total_price), 0) AS revenue
        FROM orders o
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        GROUP BY DATE(o.created_at)");
    $salesStmt->execute([$from, $to]);
    $salesByDate = [];
    foreach ($salesStmt->fetchAll() as $row) {
        $salesByDate[$row['sales_date']] = $row;
    }

    $itemsStmt = $pdo->prepare("SELECT DATE(o.created_at) AS sales_date,
            COALESCE(SUM(oi.quantity), 0) AS units_sold
        FROM orders o JOIN order_items oi ON oi.order_id = o.id
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        GROUP BY DATE(o.created_at)");
    $itemsStmt->execute([$from, $to]);
    $unitsByDate = [];
    foreach ($itemsStmt->fetchAll() as $row) {
        $unitsByDate[$row['sales_date']] = (int)$row['units_sold'];
    }

    $rows = [];
    $cursor = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);
    while ($cursor <= $last) {
        $date = $cursor->format('Y-m-d');
        $sales = $salesByDate[$date] ?? [];
        $rows[] = [
            'sales_date' => $date,
            'orders_count' => (int)($sales['orders_count'] ?? 0),
            'units_sold' => (int)($unitsByDate[$date] ?? 0),
            'revenue' => (float)($sales['revenue'] ?? 0),
        ];
        $cursor = $cursor->modify('+1 day');
    }
    return $rows;
}

function mis_fetch_top_products(PDO $pdo, string $start, string $end, string $metric = 'units', int $limit = 10): array
{
    [$from, $to] = mis_bounds($start, $end);
    $metric = $metric === 'revenue' ? 'revenue' : 'units';
    $limit = max(1, min(50, $limit));
    $orderBy = $metric === 'revenue' ? 'revenue DESC, units_sold DESC' : 'units_sold DESC, revenue DESC';

    $stmt = $pdo->prepare("SELECT oi.product_name,
            COALESCE(SUM(oi.quantity), 0) AS units_sold,
            COALESCE(SUM(oi.subtotal), 0) AS revenue
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        GROUP BY oi.product_name
        ORDER BY $orderBy, oi.product_name ASC
        LIMIT $limit");
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
}

function mis_svg_sales_chart(array $rows): string
{
    if (!$rows) {
        return '<div class="mis-empty-chart">Belum ada data penjualan pada periode ini.</div>';
    }

    $width = 960;
    $height = 310;
    $left = 78;
    $right = 20;
    $top = 24;
    $bottom = 50;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;
    $values = array_map(static fn($r) => (float)$r['revenue'], $rows);
    $max = max(1.0, max($values));
    $tickMax = ceil($max / 4);
    if ($tickMax <= 0) $tickMax = 1;
    $scaleMax = $tickMax * 4;
    $count = count($rows);
    $denom = max(1, $count - 1);
    $xAt = static fn(int $i): float => $left + ($i / $denom) * $plotW;
    $yAt = static fn(float $v): float => $top + $plotH - (($v / $scaleMax) * $plotH);

    $svg = '<svg class="mis-line-svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Grafik tren penjualan harian">';
    $svg .= '<defs><linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#29aafe" stop-opacity=".24"/><stop offset="100%" stop-color="#29aafe" stop-opacity=".015"/></linearGradient></defs>';

    for ($tick = 0; $tick <= 4; $tick++) {
        $value = $tickMax * $tick;
        $y = $yAt($value);
        $svg .= '<line x1="' . $left . '" y1="' . round($y, 2) . '" x2="' . ($width - $right) . '" y2="' . round($y, 2) . '" class="mis-chart-grid" />';
        $label = 'Rp ' . mis_compact_number($value);
        $svg .= '<text x="' . ($left - 12) . '" y="' . (round($y, 2) + 4) . '" text-anchor="end" class="mis-chart-y-label">' . e($label) . '</text>';
    }

    $points = [];
    foreach ($rows as $i => $row) {
        $points[] = round($xAt($i), 2) . ',' . round($yAt((float)$row['revenue']), 2);
    }
    $firstX = $xAt(0);
    $lastX = $xAt($count - 1);
    $baseY = $top + $plotH;
    $areaPath = 'M ' . round($firstX, 2) . ' ' . round($baseY, 2) . ' L ' . implode(' L ', $points) . ' L ' . round($lastX, 2) . ' ' . round($baseY, 2) . ' Z';
    $svg .= '<path d="' . $areaPath . '" fill="url(#salesFill)" />';
    $svg .= '<polyline points="' . implode(' ', $points) . '" class="mis-chart-line" />';

    $pointStride = $count > 90 ? (int)ceil($count / 24) : 1;
    foreach ($rows as $i => $row) {
        if ($i % $pointStride !== 0 && $i !== $count - 1) continue;
        $x = round($xAt($i), 2);
        $y = round($yAt((float)$row['revenue']), 2);
        $tip = date('d M Y', strtotime($row['sales_date'])) . ' · ' . rupiah($row['revenue']) . ' · ' . mis_number($row['orders_count']) . ' order';
        $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="3.5" class="mis-chart-point"><title>' . e($tip) . '</title></circle>';
    }

    $labelStride = max(1, (int)ceil($count / 7));
    foreach ($rows as $i => $row) {
        if ($i % $labelStride !== 0 && $i !== $count - 1) continue;
        $x = round($xAt($i), 2);
        $label = date('d M', strtotime($row['sales_date']));
        $svg .= '<text x="' . $x . '" y="' . ($height - 20) . '" text-anchor="middle" class="mis-chart-x-label">' . e($label) . '</text>';
    }
    $svg .= '</svg>';
    return $svg;
}

function mis_csv_safe(string $value): string
{
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}
?>
