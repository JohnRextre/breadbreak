<?php

/**
 * Inventory activity log — powers the staff "Inventory History" feed.
 *
 * One row per catalogue/stock change (item added, price/stock edited, item
 * deleted, menu categories and service sizes). The actor is snapshotted by name
 * so the trail stays readable even if the staff account that made the change
 * is later removed (the FK only nullifies the id).
 *
 * Auditing is best-effort by design — a failed insert must never break the
 * action it is auditing (mirrors logUserActivity in includes/activity_log.php).
 */
function ensureInventoryActivityLog(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventory_activity_log (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                actor_id INT NULL,
                actor_name VARCHAR(160) NULL,
                entity VARCHAR(20) NOT NULL DEFAULT 'item',
                entity_id INT NULL,
                entity_name VARCHAR(150) NOT NULL,
                action VARCHAR(40) NOT NULL,
                detail VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ial_created (created_at),
                INDEX idx_ial_entity (entity, created_at),
                CONSTRAINT fk_ial_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (Throwable) {
        // Already exists, or this account cannot CREATE — inserts below still try,
        // and any failure there is swallowed the same way.
    }
}

function logInventoryActivity(PDO $pdo, string $action, string $entity, ?int $entityId, string $entityName, ?string $detail = null): void
{
    if ($action === '' || trim($entityName) === '') {
        return;
    }

    ensureInventoryActivityLog($pdo);

    try {
        $actorId = (int) ($_SESSION['user_id'] ?? 0);
        $actorName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $pdo->prepare(
            'INSERT INTO inventory_activity_log (actor_id, actor_name, entity, entity_id, entity_name, action, detail)
             VALUES (:actor_id, :actor_name, :entity, :entity_id, :entity_name, :action, :detail)'
        )->execute([
            'actor_id' => $actorId > 0 ? $actorId : null,
            'actor_name' => $actorName !== '' ? mb_substr($actorName, 0, 160) : null,
            'entity' => mb_substr($entity, 0, 20),
            'entity_id' => $entityId,
            'entity_name' => mb_substr(trim($entityName), 0, 150),
            'action' => mb_substr($action, 0, 40),
            'detail' => ($detail !== null && trim($detail) !== '') ? mb_substr(trim($detail), 0, 500) : null,
        ]);
    } catch (Throwable) {
        // Best-effort audit only — never block the real action.
    }
}
