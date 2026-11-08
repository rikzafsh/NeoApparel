<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db_kms.php';

function kms_require_role(array $roles): array
{
    global $pdo;
    return need_role($pdo, $roles);
}

function kms_user_name(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return (string)($stmt->fetchColumn() ?: 'Unknown');
}

function kms_can_view(array $user, array $item): bool
{
    if ($item['status'] !== 'published') {
        return user_has_any_role(['manager','admin']);
    }

    if (user_has_any_role(['manager','admin'])) {
        return true;
    }

    if ($item['visibility'] === 'public') {
        return true;
    }

    return $item['visibility'] === 'employees'
        && user_has_any_role(['seller','staff']);
}
?>
