<?php
require_once __DIR__ . '/mis_functions.php';
$user = mis_require_employee(['manager', 'admin']);
$report = (string)($_GET['report'] ?? 'sales');
if (!in_array($report, ['sales', 'products', 'inventory', 'customers'], true)) {
    http_response_code(400);
    exit('Jenis laporan tidak dikenali.');
}
[$start, $end] = mis_period();
[$from, $to] = mis_bounds($start, $end);

try {
    audit($pdo, (int)$user['id'], 'export_csv', 'mis_' . $report, null, null, [
        'start' => $start,
        'end' => $end,
        'report' => $report,
    ]);
} catch (Throwable $e) {
    // Kegagalan pencatatan log tidak membatalkan laporan, tetapi export tetap dibatasi role.
}

if ($report === 'sales') {
    $stmt = $pdo->prepare("SELECT DATE(o.created_at) AS date, o.order_number, u.name AS buyer,
            o.status, o.payment_status, o.subtotal, o.shipping_cost, o.discount_amount, o.total_price
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        ORDER BY o.created_at ASC");
    $stmt->execute([$from, $to]);
    $headers = ['Tanggal', 'Nomor Pesanan', 'Pelanggan', 'Status Pesanan', 'Status Pembayaran', 'Subtotal', 'Ongkir', 'Diskon', 'Total'];
    $filename = 'mis_penjualan_' . $start . '_' . $end . '.csv';
} elseif ($report === 'products') {
    $stmt = $pdo->prepare("SELECT oi.product_name, COALESCE(oi.variation_sku,'') AS sku,
            COALESCE(SUM(oi.quantity),0) AS units_sold, COALESCE(SUM(oi.subtotal),0) AS revenue,
            COUNT(DISTINCT o.id) AS order_count
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        GROUP BY oi.product_name, oi.variation_sku
        ORDER BY units_sold DESC, revenue DESC");
    $stmt->execute([$from, $to]);
    $headers = ['Nama Produk', 'SKU', 'Unit Terjual', 'Pendapatan', 'Jumlah Pesanan'];
    $filename = 'mis_produk_' . $start . '_' . $end . '.csv';
} elseif ($report === 'inventory') {
    $stmt = $pdo->prepare("SELECT p.name AS product_name, v.sku, v.color, v.size, v.stock, v.price,
            COALESCE(m.stock_in,0) AS stock_in, COALESCE(m.stock_out,0) AS stock_out
        FROM product_variations v JOIN products p ON p.id = v.product_id
        LEFT JOIN (
            SELECT variation_id,
                SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS stock_in,
                SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END) AS stock_out
            FROM inventory_movements WHERE created_at BETWEEN ? AND ? GROUP BY variation_id
        ) m ON m.variation_id = v.id
        WHERE p.is_active=1 AND v.is_active=1 ORDER BY p.name, v.sku");
    $stmt->execute([$from, $to]);
    $headers = ['Nama Produk', 'SKU', 'Warna', 'Ukuran', 'Stok Saat Ini', 'Harga Variasi', 'Stok Masuk Periode', 'Stok Keluar Periode'];
    $filename = 'mis_stok_' . $start . '_' . $end . '.csv';
} else {
    $stmt = $pdo->prepare("SELECT u.name, u.email, COUNT(DISTINCT o.id) AS order_count,
            COALESCE(SUM(o.total_price),0) AS total_spent, MAX(o.created_at) AS last_order
        FROM users u JOIN user_roles ur ON ur.user_id=u.id
        JOIN roles r ON r.id=ur.role_id AND r.name='customer'
        JOIN orders o ON o.user_id=u.id
        WHERE o.created_at BETWEEN ? AND ? AND " . mis_sales_condition('o') . "
        GROUP BY u.id,u.name,u.email ORDER BY total_spent DESC");
    $stmt->execute([$from, $to]);
    $headers = ['Nama Pelanggan', 'Email', 'Jumlah Pesanan', 'Total Pembelian', 'Pembelian Terakhir'];
    $filename = 'mis_pelanggan_' . $start . '_' . $end . '.csv';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // BOM membantu Excel mengenali UTF-8.
fputcsv($output, $headers, ',', '"', '\\');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $fields = [];
    foreach (array_values($row) as $value) {
        if (is_string($value)) $value = mis_csv_safe($value);
        $fields[] = $value;
    }
    fputcsv($output, $fields, ',', '"', '\\');
}
fclose($output);
exit;
