<?php
declare(strict_types=1);

// Handover pickers and list filters. Function names stay global for route/view compatibility.

function handover_request_owner_candidates_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(
        users.is_active = 1
        AND users.role IN ("owner", "admin")
        AND EXISTS (
            SELECT 1
            FROM storages storage
            WHERE storage.owner_user_id = users.id
              AND storage.is_active = 1
              AND storage.is_system = 0
        )
    )'];

    if ($selectedId !== null) {
        $conditions[] = 'users.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT users.id, users.name, users.email, users.role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(users.role, "owner", "admin"), users.name ASC',
        $params
    );
}

function handover_request_assigned_owner(array $user): ?array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);

    if ($assignedOwnerId === null) {
        return null;
    }

    return Database::fetch(
        'SELECT id, name, email, role, is_active
         FROM users
         WHERE id = :id
         LIMIT 1',
        ['id' => $assignedOwnerId]
    ) ?: null;
}

function handover_source_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function handover_request_source_storages_for_staff(array $user, ?int $selectedId = null, ?int $selectedOwnerId = null): array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);
    $requiredOwnerId = $assignedOwnerId ?? $selectedOwnerId;
    $storages = all_storages_for_select($selectedId);

    return array_values(array_filter($storages, static function (array $storage) use ($requiredOwnerId, $selectedId): bool {
        if (empty($storage['owner_user_id'])) {
            return false;
        }

        if ($selectedId !== null && (int) $storage['id'] === $selectedId) {
            return true;
        }

        if ($requiredOwnerId === null) {
            return true;
        }

        return (int) $storage['owner_user_id'] === (int) $requiredOwnerId;
    }));
}

function handover_destination_storages_for_select(?int $selectedId = null): array
{
    return array_values(array_filter(
        all_storages_for_select($selectedId),
        static fn (array $storage): bool => !empty($storage['owner_user_id']) || ($selectedId !== null && (int) $storage['id'] === $selectedId)
    ));
}

function handover_is_storage_transfer(array $handover): bool
{
    return (string) ($handover['recipient_type'] ?? 'staff') === 'storage'
        || !empty($handover['destination_storage_id']);
}

function handover_target_type_label(array $handover): string
{
    return handover_is_storage_transfer($handover) ? 'Storage transfer' : 'Staff use';
}

function handover_filters(): array
{
    $status = (string) query('status', 'all');
    $targetType = (string) query('target_type', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval', 'closed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'target_type' => in_array($targetType, ['all', 'staff', 'storage'], true) ? $targetType : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_handover_where(array $filters, string $alias = 'h'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :handover_status";
        $params['handover_status'] = $filters['status'];
    }

    if (($filters['target_type'] ?? 'all') === 'storage') {
        $conditions[] = "(COALESCE({$alias}.recipient_type, 'staff') = 'storage' OR {$alias}.destination_storage_id IS NOT NULL)";
    } elseif (($filters['target_type'] ?? 'all') === 'staff') {
        $conditions[] = "(COALESCE({$alias}.recipient_type, 'staff') = 'staff' AND {$alias}.destination_storage_id IS NULL)";
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$alias}.source_storage_id = :handover_storage_id OR {$alias}.destination_storage_id = :handover_storage_id)";
        $params['handover_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.handover_number LIKE :handover_search_number
            OR {$alias}.recipient_name LIKE :handover_search_recipient
            OR COALESCE({$alias}.notes, '') LIKE :handover_search_notes
            OR source_storage.name LIKE :handover_search_source_storage
            OR destination_storage.name LIKE :handover_search_destination_storage
            OR EXISTS (
                SELECT 1
                FROM handover_lines handover_lines
                WHERE handover_lines.handover_id = {$alias}.id
                  AND (
                      handover_lines.item_name LIKE :handover_search_item_name
                      OR handover_lines.item_sku LIKE :handover_search_item_sku
                  )
            )
        )";
        $handoverSearchLike = '%' . $filters['search'] . '%';
        $params['handover_search_number'] = $handoverSearchLike;
        $params['handover_search_recipient'] = $handoverSearchLike;
        $params['handover_search_notes'] = $handoverSearchLike;
        $params['handover_search_source_storage'] = $handoverSearchLike;
        $params['handover_search_destination_storage'] = $handoverSearchLike;
        $params['handover_search_item_name'] = $handoverSearchLike;
        $params['handover_search_item_sku'] = $handoverSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.issued_at >= :handover_date_from";
        $params['handover_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.issued_at <= :handover_date_to";
        $params['handover_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_handover_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}

function find_handover_or_abort(int $handoverId): array
{
    [$scopeSql, $scopeParams] = visible_handover_scope('h');
    $handover = Database::fetch(
        'SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                source_storage.owner_user_id AS source_owner_user_id,
                destination_storage.owner_user_id AS destination_owner_user_id,
                creator.name AS creator_name,
                request_approver.name AS request_approver_name,
                request_approved_by_user.name AS request_approved_by_name,
                completer.name AS completed_by_name,
                submitter.name AS submitted_by_name,
                approver.name AS approved_by_name,
                recipient.name AS recipient_user_name,
                recipient.email AS recipient_user_email,
                source_owner.name AS source_owner_name,
                destination_owner.name AS destination_owner_name,
                destination_owner.email AS destination_owner_email
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN users request_approver ON request_approver.id = h.approver_user_id
         LEFT JOIN users request_approved_by_user ON request_approved_by_user.id = h.request_approved_by
         LEFT JOIN users submitter ON submitter.id = h.submitted_by
         LEFT JOIN users approver ON approver.id = h.approved_by
         LEFT JOIN users completer ON completer.id = h.completed_by
         LEFT JOIN users recipient ON recipient.id = h.recipient_user_id
         LEFT JOIN users source_owner ON source_owner.id = source_storage.owner_user_id
         LEFT JOIN users destination_owner ON destination_owner.id = destination_storage.owner_user_id
         WHERE h.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $handoverId] + $scopeParams
    );

    if (!$handover) {
        abort(404, 'Handover not found.');
    }

    return $handover;
}

function handover_lines(int $handoverId): array
{
    $lines = Database::fetchAll(
        'SELECT handover_line.*,
                i.image_path,
                i.barcode AS item_barcode
         FROM handover_lines handover_line
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE handover_line.handover_id = :handover_id
         ORDER BY handover_line.item_name ASC, handover_line.id ASC',
        ['handover_id' => $handoverId]
    );

    return hydrate_handover_lines_expected_usage_breakdowns(hydrate_handover_lines_usage_breakdowns($lines));
}

function handover_summary_rows(array $filters): array
{
    [$where, $params] = build_handover_where($filters);

    return Database::fetchAll(
        "SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                creator.name AS creator_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_handed, 0) AS total_handed,
                COALESCE(line_totals.total_used, 0) AS total_used,
                COALESCE(line_totals.total_returned, 0) AS total_returned
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN (
             SELECT handover_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_handed), 0) AS total_handed,
                    COALESCE(SUM(quantity_used), 0) AS total_used,
                    COALESCE(SUM(quantity_returned), 0) AS total_returned
             FROM handover_lines
             GROUP BY handover_id
         ) line_totals ON line_totals.handover_id = h.id
         {$where}
         ORDER BY h.issued_at DESC, h.id DESC
         LIMIT 250",
        $params
    );
}

function staff_dashboard_handover_cards(int $userId): array
{
    return Database::fetchAll(
        'SELECT h.id,
                h.handover_number,
                h.status,
                h.scheduled_for_date,
                h.issued_at,
                h.closed_notes,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                handover_line.item_id,
                handover_line.item_name,
                handover_line.item_sku,
                handover_line.unit,
                handover_line.quantity_handed,
                handover_line.quantity_received,
                handover_line.quantity_used,
                handover_line.quantity_returned,
                i.image_path
         FROM handovers h
         INNER JOIN handover_lines handover_line ON handover_line.handover_id = h.id
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE h.recipient_user_id = :user_id
           AND COALESCE(h.recipient_type, "staff") = "staff"
           AND h.status IN ("awaiting_receipt", "receipt_review", "delivered", "pending_approval")
           AND (
               CASE
                   WHEN h.status IN ("awaiting_receipt", "receipt_review") THEN handover_line.quantity_handed
                   ELSE handover_line.quantity_received
               END - handover_line.quantity_used - handover_line.quantity_returned
           ) > 0
         ORDER BY COALESCE(h.scheduled_for_date, DATE(h.issued_at)) ASC, h.issued_at DESC, handover_line.item_name ASC
         LIMIT 24',
        ['user_id' => $userId]
    );
}
