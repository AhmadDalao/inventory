<?php
declare(strict_types=1);

// Request detail lookup with visibility scope enforcement.
function find_request_or_abort(int $requestId): array
{
    [$scopeSql, $scopeParams] = visible_request_scope('r');
    $request = Database::fetch(
        'SELECT r.*,
                requester.name AS requester_name,
                requester.email AS requester_email,
                requester.role AS requester_role,
                approver.name AS approver_name,
                approver.email AS approver_email,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                approved_by_user.name AS approved_by_name,
                completed_by_user.name AS completed_by_name
         FROM item_requests r
         INNER JOIN users requester ON requester.id = r.requester_user_id
         INNER JOIN users approver ON approver.id = r.approver_user_id
         INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
         LEFT JOIN users approved_by_user ON approved_by_user.id = r.approved_by
         LEFT JOIN users completed_by_user ON completed_by_user.id = r.completed_by
         WHERE r.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $requestId] + $scopeParams
    );

    if (!$request) {
        abort(404, 'Request not found.');
    }

    return $request;
}
