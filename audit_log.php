<?php

function ensureAuditLogTable(mysqli $connection, ?string &$errorMessage = null): bool
{
    static $results = [];

    $connectionId = spl_object_id($connection);
    if (isset($results[$connectionId])) {
        $errorMessage = $results[$connectionId]['error'];
        return $results[$connectionId]['ready'];
    }

    $tableLookup = $connection->query(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' LIMIT 1"
    );
    if (!$tableLookup) {
        $errorMessage = 'Unable to check audit log table availability.';
        error_log('Audit log table check failed: ' . $connection->error);
        $results[$connectionId] = ['ready' => false, 'error' => $errorMessage];
        return false;
    }

    if ($tableLookup->num_rows > 0) {
        $tableLookup->free();
        $results[$connectionId] = ['ready' => true, 'error' => ''];
        return true;
    }
    $tableLookup->free();

    $createTable = $connection->query(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            actor_user_id INT NULL,
            actor_role VARCHAR(30) NOT NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT NULL,
            details JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_logs_created_at (created_at),
            KEY idx_audit_logs_actor_created (actor_user_id, created_at),
            KEY idx_audit_logs_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    if (!$createTable) {
        $errorMessage = 'Unable to create the audit log table. Check database permissions.';
        error_log('Audit log table creation failed: ' . $connection->error);
        $results[$connectionId] = ['ready' => false, 'error' => $errorMessage];
        return false;
    }

    $results[$connectionId] = ['ready' => true, 'error' => ''];
    return true;
}

function writeAuditLog(
    mysqli $connection,
    string $action,
    string $entityType,
    ?int $entityId = null,
    array $details = [],
    ?int $actorId = null,
    ?string $actorRole = null
): bool {
    if (!ensureAuditLogTable($connection)) {
        return false;
    }

    $actorId = $actorId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $actorRole = $actorRole ?? (string)($_SESSION['role'] ?? 'system');
    $detailsJson = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($detailsJson === false) {
        error_log('Audit log details could not be encoded for action ' . $action . '.');
        $detailsJson = '{}';
    }

    $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $statement = $connection->prepare(
        'INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, details, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$statement) {
        error_log('Audit log insert preparation failed for action ' . $action . ': ' . $connection->error);
        return false;
    }

    $statement->bind_param('isssisss', $actorId, $actorRole, $action, $entityType, $entityId, $detailsJson, $ipAddress, $userAgent);
    $saved = $statement->execute();
    if (!$saved) {
        error_log('Audit log insert failed for action ' . $action . ': ' . $statement->error);
    }
    $statement->close();
    return $saved;
}
