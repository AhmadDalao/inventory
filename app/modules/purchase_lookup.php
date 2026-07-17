<?php
declare(strict_types=1);

// Purchase lookup helpers shared by purchase pages, lifecycle actions, and exports.

function find_purchase_or_abort(int $purchaseId): array
{
    $purchase = Database::fetch(
        'SELECT p.*,
                supplier.name AS supplier_name,
                supplier.supplier_type AS supplier_type,
                supplier.supplier_type_other AS supplier_type_other,
                supplier.phone AS supplier_phone,
                supplier.email AS supplier_email,
                supplier.tax_number AS supplier_tax_number,
                supplier.commercial_registration AS supplier_commercial_registration,
                supplier.national_address AS supplier_national_address,
                supplier.authorized_person AS supplier_authorized_person,
                storage.name AS storage_name,
                storage.storage_type,
                requester.name AS requester_name,
                approver.name AS approver_name,
                receiver.name AS receiver_name,
                approved_user.name AS approved_by_name,
                completed_user.name AS completed_by_name
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         INNER JOIN users requester ON requester.id = p.requester_user_id
         INNER JOIN users approver ON approver.id = p.approver_user_id
         LEFT JOIN users receiver ON receiver.id = p.receiver_user_id
         LEFT JOIN users approved_user ON approved_user.id = p.approved_by
         LEFT JOIN users completed_user ON completed_user.id = p.completed_by
         WHERE p.id = :id
         LIMIT 1',
        ['id' => $purchaseId]
    );

    if (!$purchase) {
        abort(404, 'Purchase not found.');
    }

    return $purchase;
}
