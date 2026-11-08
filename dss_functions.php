<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** Modul DSS supplier hanya dapat digunakan oleh administrator. */
function dss_require_admin(): array
{
    global $pdo;
    return need_role($pdo, ['admin']);
}

function dss_missing_tables(): array
{
    global $pdo;
    $required = [
        'suppliers', 'supplier_products', 'dss_criteria', 'dss_evaluations',
        'dss_evaluation_criteria', 'dss_evaluation_candidates',
        'dss_evaluation_scores', 'dss_evaluation_results',
        'supplier_purchase_orders', 'supplier_purchase_order_items',
    ];
    $missing = [];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    foreach ($required as $table) {
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() === 0) {
            $missing[] = $table;
        }
    }
    return $missing;
}

function dss_assert_schema(): void
{
    $missing = dss_missing_tables();
    if ($missing) {
        throw new RuntimeException('Tabel DSS belum lengkap: ' . implode(', ', $missing) . '. Impor sql/01_schema_main.sql ke database db_neo_apparel terlebih dahulu.');
    }
}

function dss_variations(PDO $pdo): array
{
    $sql = "SELECT v.id, v.sku, v.color, v.size, v.stock, v.price, p.name AS product_name
            FROM product_variations v
            JOIN products p ON p.id = v.product_id
            WHERE v.is_active = 1
            ORDER BY p.name, v.sku";
    return $pdo->query($sql)->fetchAll();
}

function dss_variation_label(array $variation): string
{
    $extra = trim((string)($variation['color'] ?? '') . ' ' . (string)($variation['size'] ?? ''));
    return (string)$variation['product_name'] . ' · ' . (string)$variation['sku'] . ($extra !== '' ? ' · ' . $extra : '');
}

function dss_status_label(string $status): string
{
    return [
        'draft' => 'Draf',
        'evaluated' => 'Sudah dihitung',
        'approved' => 'Disetujui',
        'cancelled' => 'Dibatalkan',
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'blacklisted' => 'Diblokir',
        'ordered' => 'Dipesan',
        'partially_received' => 'Diterima sebagian',
        'received' => 'Diterima penuh',
        'included' => 'Diikutkan',
        'excluded' => 'Dikecualikan',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function dss_status_class(string $status): string
{
    return match ($status) {
        'approved', 'active', 'included' => 'success',
        'evaluated', 'ordered', 'partially_received' => 'info',
        'received' => 'success',
        'draft', 'inactive' => 'warning',
        'cancelled', 'blacklisted', 'excluded' => 'danger',
        default => 'neutral',
    };
}

function dss_money(float|int|string|null $amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function dss_number(float|int|string|null $value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals, ',', '.');
}

function dss_touch_draft(PDO $pdo, int $evaluationId): void
{
    $stmt = $pdo->prepare('DELETE r FROM dss_evaluation_results r JOIN dss_evaluation_candidates c ON c.id = r.candidate_id WHERE c.evaluation_id = ?');
    $stmt->execute([$evaluationId]);
    $pdo->prepare("UPDATE dss_evaluations SET status = 'draft', approved_by = NULL, approved_at = NULL WHERE id = ? AND status <> 'approved'")->execute([$evaluationId]);
}

function dss_audit(PDO $pdo, ?int $userId, string $action, string $table, ?int $id, ?array $old = null, ?array $new = null): void
{
    // Audit tidak boleh membuat transaksi utama gagal jika skema audit belum tersedia.
    try {
        audit($pdo, $userId, $action, $table, $id, $old, $new);
    } catch (Throwable $ignored) {
        // Aktivitas utama sudah ditangani; kesalahan audit dapat ditinjau dari log PHP.
        error_log('DSS audit failed: ' . $ignored->getMessage());
    }
}

function dss_can_edit(array $evaluation): bool
{
    return in_array($evaluation['status'] ?? '', ['draft', 'evaluated'], true);
}

function dss_fetch_evaluation(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT e.*, u.name AS creator_name, au.name AS approver_name,
                                  p.name AS product_name, v.sku, v.color, v.size
                           FROM dss_evaluations e
                           LEFT JOIN users u ON u.id = e.created_by
                           LEFT JOIN users au ON au.id = e.approved_by
                           LEFT JOIN product_variations v ON v.id = e.target_variation_id
                           LEFT JOIN products p ON p.id = v.product_id
                           WHERE e.id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dss_evaluation_criteria(PDO $pdo, int $evaluationId): array
{
    $stmt = $pdo->prepare("SELECT c.id, c.criterion_code, c.criterion_name, c.criterion_type,
                                  c.value_type, c.description, ec.weight_percent
                           FROM dss_evaluation_criteria ec
                           JOIN dss_criteria c ON c.id = ec.criterion_id
                           WHERE ec.evaluation_id = ?
                           ORDER BY c.id");
    $stmt->execute([$evaluationId]);
    return $stmt->fetchAll();
}

function dss_evaluation_candidates(PDO $pdo, int $evaluationId): array
{
    $stmt = $pdo->prepare("SELECT c.*, s.name AS supplier_name, s.supplier_code, s.payment_terms_days,
                                  sp.purchase_price, sp.minimum_order_qty, sp.lead_time_days,
                                  sp.shipping_cost, sp.supplier_sku
                           FROM dss_evaluation_candidates c
                           JOIN suppliers s ON s.id = c.supplier_id
                           LEFT JOIN supplier_products sp ON sp.id = c.supplier_product_id
                           WHERE c.evaluation_id = ?
                           ORDER BY s.name");
    $stmt->execute([$evaluationId]);
    return $stmt->fetchAll();
}

function dss_evaluation_scores_map(PDO $pdo, array $candidateIds): array
{
    if (!$candidateIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
    $stmt = $pdo->prepare("SELECT candidate_id, criterion_id, raw_value, input_source, notes
                           FROM dss_evaluation_scores WHERE candidate_id IN ($placeholders)");
    $stmt->execute(array_values($candidateIds));
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['candidate_id']][(int)$row['criterion_id']] = $row;
    }
    return $map;
}

function dss_seed_offer_scores(PDO $pdo, int $candidateId, int $supplierId, ?array $offer, int $userId): void
{
    $criteria = $pdo->query('SELECT id, criterion_code FROM dss_criteria WHERE is_active = 1')->fetchAll();
    $ids = [];
    foreach ($criteria as $criterion) {
        $ids[$criterion['criterion_code']] = (int)$criterion['id'];
    }
    $values = [];
    if ($offer) {
        $values['PRICE'] = [(float)$offer['purchase_price'], 'supplier_offer'];
        $values['LEAD_TIME'] = [(float)$offer['lead_time_days'], 'supplier_offer'];
        $values['MIN_ORDER_QTY'] = [(float)$offer['minimum_order_qty'], 'supplier_offer'];
    }
    $paymentStmt = $pdo->prepare('SELECT payment_terms_days FROM suppliers WHERE id = ?');
    $paymentStmt->execute([$supplierId]);
    $values['PAYMENT_TERMS'] = [(float)$paymentStmt->fetchColumn(), 'supplier_offer'];

    // Jika riwayat pengadaan tersedia, gunakan data aktual untuk dua kriteria historis.
    $history = $pdo->prepare("SELECT
        CASE WHEN SUM(i.quantity_received) > 0
             THEN SUM(i.quantity_accepted) / SUM(i.quantity_received) * 100 ELSE NULL END AS acceptance_rate
        FROM supplier_purchase_orders po
        JOIN supplier_purchase_order_items i ON i.purchase_order_id = po.id
        WHERE po.supplier_id = ? AND po.status IN ('partially_received','received')");
    $history->execute([$supplierId]);
    $acceptance = $history->fetchColumn();
    if ($acceptance !== null && $acceptance !== false) {
        $values['ACCEPTANCE_RATE'] = [(float)$acceptance, 'purchase_history'];
    }

    $onTime = $pdo->prepare("SELECT CASE WHEN COUNT(*) > 0
            THEN SUM(CASE WHEN received_date <= expected_delivery_date THEN 1 ELSE 0 END) / COUNT(*) * 100
            ELSE NULL END AS on_time_rate
        FROM supplier_purchase_orders
        WHERE supplier_id = ? AND status IN ('partially_received','received')
          AND received_date IS NOT NULL AND expected_delivery_date IS NOT NULL");
    $onTime->execute([$supplierId]);
    $onTimeRate = $onTime->fetchColumn();
    if ($onTimeRate !== null && $onTimeRate !== false) {
        $values['ON_TIME_RATE'] = [(float)$onTimeRate, 'purchase_history'];
    }

    $save = $pdo->prepare("INSERT INTO dss_evaluation_scores (candidate_id, criterion_id, raw_value, input_source, created_by)
                           VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                             raw_value = IF(input_source = 'manual', raw_value, VALUES(raw_value)),
                             input_source = IF(input_source = 'manual', input_source, VALUES(input_source)),
                             updated_at = CURRENT_TIMESTAMP");
    foreach ($values as $code => [$value, $source]) {
        if (isset($ids[$code])) {
            $save->execute([$candidateId, $ids[$code], $value, $source, $userId]);
        }
    }
}

function dss_calculate_saw(PDO $pdo, int $evaluationId): array
{
    $criteria = dss_evaluation_criteria($pdo, $evaluationId);
    $candidates = dss_evaluation_candidates($pdo, $evaluationId);
    $candidates = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['candidate_status'] === 'included'));

    if (!$criteria) {
        throw new RuntimeException('Tambahkan minimal satu kriteria aktif pada evaluasi ini.');
    }
    if (count($candidates) < 2) {
        throw new RuntimeException('Tambahkan minimal dua supplier yang diikutkan agar perbandingan bermakna.');
    }
    $weightTotal = array_sum(array_map(static fn(array $c): float => (float)$c['weight_percent'], $criteria));
    if (abs($weightTotal - 100.0) > 0.02) {
        throw new RuntimeException('Total bobot saat ini ' . dss_number($weightTotal) . '%. Atur total bobot menjadi 100%.');
    }

    $ids = array_map(static fn(array $c): int => (int)$c['id'], $candidates);
    $scoresMap = dss_evaluation_scores_map($pdo, $ids);
    $valuesByCriterion = [];
    foreach ($criteria as $criterion) {
        $criterionId = (int)$criterion['id'];
        $values = [];
        foreach ($candidates as $candidate) {
            $candidateId = (int)$candidate['id'];
            if (!isset($scoresMap[$candidateId][$criterionId])) {
                throw new RuntimeException('Nilai kriteria "' . $criterion['criterion_name'] . '" untuk supplier ' . $candidate['supplier_name'] . ' belum diisi. Lengkapi semua nilai sebelum menghitung.');
            }
            $value = (float)$scoresMap[$candidateId][$criterionId]['raw_value'];
            if ($value < 0 || !is_finite($value)) {
                throw new RuntimeException('Nilai kriteria harus berupa angka yang valid dan tidak negatif.');
            }
            if ($criterion['value_type'] === 'percentage' && $value > 100) {
                throw new RuntimeException('Nilai persentase kriteria ' . $criterion['criterion_name'] . ' tidak boleh melebihi 100.');
            }
            if ($criterion['value_type'] === 'rating' && ($value < 1 || $value > 5)) {
                throw new RuntimeException('Rating kriteria ' . $criterion['criterion_name'] . ' harus berada pada skala 1–5.');
            }
            $values[$candidateId] = $value;
        }
        $valuesByCriterion[$criterionId] = $values;
    }

    $results = [];
    foreach ($candidates as $candidate) {
        $candidateId = (int)$candidate['id'];
        $total = 0.0;
        $detail = [];
        foreach ($criteria as $criterion) {
            $criterionId = (int)$criterion['id'];
            $raw = $valuesByCriterion[$criterionId][$candidateId];
            $series = array_values($valuesByCriterion[$criterionId]);
            if ($criterion['criterion_type'] === 'benefit') {
                $best = max($series);
                $normalized = $best > 0 ? $raw / $best : 0.0;
            } else {
                $best = min($series);
                if ($raw == 0.0) {
                    $normalized = ($best == 0.0) ? 1.0 : 0.0;
                } else {
                    $normalized = $best / $raw;
                }
            }
            $weighted = $normalized * ((float)$criterion['weight_percent'] / 100.0);
            $total += $weighted;
            $detail[] = [
                'criterion_id' => $criterionId,
                'criterion_code' => $criterion['criterion_code'],
                'criterion_name' => $criterion['criterion_name'],
                'type' => $criterion['criterion_type'],
                'raw_value' => $raw,
                'normalized_value' => $normalized,
                'weight_percent' => (float)$criterion['weight_percent'],
                'weighted_score' => $weighted,
            ];
        }
        $results[] = ['candidate' => $candidate, 'total_score' => $total, 'details' => $detail];
    }
    usort($results, static function (array $a, array $b): int {
        $scoreOrder = $b['total_score'] <=> $a['total_score'];
        return $scoreOrder !== 0 ? $scoreOrder : strcmp((string)$a['candidate']['supplier_name'], (string)$b['candidate']['supplier_name']);
    });

    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE r FROM dss_evaluation_results r JOIN dss_evaluation_candidates c ON c.id = r.candidate_id WHERE c.evaluation_id = ?');
        $delete->execute([$evaluationId]);
        $save = $pdo->prepare('INSERT INTO dss_evaluation_results (candidate_id, total_score, rank_position, is_recommended, calculation_details, algorithm_version, calculated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        foreach ($results as $index => $result) {
            $save->execute([
                (int)$result['candidate']['id'],
                $result['total_score'],
                $index + 1,
                $index === 0 ? 1 : 0,
                json_encode($result['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'SAW-1.0',
            ]);
        }
        $pdo->prepare("UPDATE dss_evaluations SET status = 'evaluated' WHERE id = ?")->execute([$evaluationId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return $results;
}
