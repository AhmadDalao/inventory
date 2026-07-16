<?php
declare(strict_types=1);

// Shared workflow filter builders used by module list pages and exports.

function build_purchase_where(array $filters, string $alias = 'p'): array
{
    $conditions = ['1 = 1'];
    $params = [];

    if (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.status = :purchase_status";
        $params['purchase_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.destination_storage_id = :purchase_storage_id";
        $params['purchase_storage_id'] = (int) $filters['storage_id'];
    }

    if (!empty($filters['supplier_id'])) {
        $conditions[] = "{$alias}.supplier_id = :purchase_supplier_id";
        $params['purchase_supplier_id'] = (int) $filters['supplier_id'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at >= :purchase_date_from";
        $params['purchase_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at <= :purchase_date_to";
        $params['purchase_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "({$alias}.purchase_number LIKE :purchase_search_number OR supplier.name LIKE :purchase_search_supplier OR storage.name LIKE :purchase_search_storage)";
        $params['purchase_search_number'] = '%' . $filters['search'] . '%';
        $params['purchase_search_supplier'] = '%' . $filters['search'] . '%';
        $params['purchase_search_storage'] = '%' . $filters['search'] . '%';
    }

    return ['WHERE ' . implode(' AND ', $conditions), $params];
}

function build_file_asset_where(array $filters): array
{
    $conditions = [];
    $params = [];

    if (($filters['status'] ?? 'active') === 'active') {
        $conditions[] = 'assets.deleted_at IS NULL';
    } elseif (($filters['status'] ?? '') === 'deleted') {
        $conditions[] = 'assets.deleted_at IS NOT NULL';
    }

    if (($filters['group'] ?? 'all') !== 'all') {
        $conditions[] = 'assets.file_group = :file_group';
        $params['file_group'] = (string) $filters['group'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = 'assets.created_at >= :file_date_from';
        $params['file_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = 'assets.created_at <= :file_date_to';
        $params['file_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(
            assets.display_name LIKE :file_search_display
            OR assets.original_filename LIKE :file_search_original
            OR assets.stored_filename LIKE :file_search_stored
            OR assets.source_type LIKE :file_search_source
            OR COALESCE(uploader.name, "") LIKE :file_search_uploader
            OR COALESCE(item.name, "") LIKE :file_search_item
            OR COALESCE(item.sku, "") LIKE :file_search_sku
            OR COALESCE(company_asset.asset_number, "") LIKE :file_search_asset
            OR COALESCE(company_asset.name, "") LIKE :file_search_asset_name
            OR COALESCE(company_asset.barcode, "") LIKE :file_search_asset_barcode
            OR COALESCE(company_asset.serial_number, "") LIKE :file_search_asset_serial
            OR COALESCE(purchase.purchase_number, "") LIKE :file_search_purchase
            OR COALESCE(handover.handover_number, "") LIKE :file_search_handover
            OR COALESCE(request_record.request_number, "") LIKE :file_search_request
            OR COALESCE(supplier.name, "") LIKE :file_search_supplier
            OR COALESCE(storage_location.name, "") LIKE :file_search_storage
        )';
        $search = '%' . $filters['search'] . '%';
        $params['file_search_display'] = $search;
        $params['file_search_original'] = $search;
        $params['file_search_stored'] = $search;
        $params['file_search_source'] = $search;
        $params['file_search_uploader'] = $search;
        $params['file_search_item'] = $search;
        $params['file_search_sku'] = $search;
        $params['file_search_asset'] = $search;
        $params['file_search_asset_name'] = $search;
        $params['file_search_asset_barcode'] = $search;
        $params['file_search_asset_serial'] = $search;
        $params['file_search_purchase'] = $search;
        $params['file_search_handover'] = $search;
        $params['file_search_request'] = $search;
        $params['file_search_supplier'] = $search;
        $params['file_search_storage'] = $search;
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function build_stocktake_where(array $filters, string $alias = 's'): array
{
    $conditions = [];
    $params = [];

    if (($filters['status'] ?? 'open') === 'open') {
        $conditions[] = "{$alias}.status IN ('draft', 'pending_approval')";
    } elseif (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.status = :stocktake_status";
        $params['stocktake_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.storage_id = :stocktake_storage_id";
        $params['stocktake_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at >= :stocktake_date_from";
        $params['stocktake_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = "{$alias}.created_at <= :stocktake_date_to";
        $params['stocktake_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "(
            {$alias}.stocktake_number LIKE :stocktake_search_number
            OR storage.name LIKE :stocktake_search_storage
            OR creator.name LIKE :stocktake_search_creator
            OR EXISTS (
                SELECT 1
                FROM stocktake_lines stocktake_line
                WHERE stocktake_line.stocktake_id = {$alias}.id
                  AND (stocktake_line.item_name LIKE :stocktake_search_item_name OR stocktake_line.item_sku LIKE :stocktake_search_item_sku)
            )
        )";
        $params['stocktake_search_number'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_storage'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_creator'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_item_name'] = '%' . $filters['search'] . '%';
        $params['stocktake_search_item_sku'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function build_supplier_where(array $filters, string $alias = 'supplier'): array
{
    $conditions = [];
    $params = [];

    if (($filters['status'] ?? 'active') === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif (($filters['status'] ?? '') === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = "(
            {$alias}.name LIKE :supplier_search_name
            OR {$alias}.supplier_type LIKE :supplier_search_type
            OR COALESCE({$alias}.supplier_type_other, '') LIKE :supplier_search_type_other
            OR COALESCE({$alias}.phone, '') LIKE :supplier_search_phone
            OR COALESCE({$alias}.email, '') LIKE :supplier_search_email
            OR COALESCE({$alias}.tax_number, '') LIKE :supplier_search_tax
            OR COALESCE({$alias}.commercial_registration, '') LIKE :supplier_search_cr
            OR COALESCE({$alias}.national_address, '') LIKE :supplier_search_address
            OR COALESCE({$alias}.authorized_person, '') LIKE :supplier_search_authorized
        )";
        $params['supplier_search_name'] = '%' . $filters['search'] . '%';
        $params['supplier_search_type'] = '%' . $filters['search'] . '%';
        $params['supplier_search_type_other'] = '%' . $filters['search'] . '%';
        $params['supplier_search_phone'] = '%' . $filters['search'] . '%';
        $params['supplier_search_email'] = '%' . $filters['search'] . '%';
        $params['supplier_search_tax'] = '%' . $filters['search'] . '%';
        $params['supplier_search_cr'] = '%' . $filters['search'] . '%';
        $params['supplier_search_address'] = '%' . $filters['search'] . '%';
        $params['supplier_search_authorized'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function build_activity_where(array $filters): array
{
    $conditions = [];
    $params = [];

    if (($filters['action'] ?? '') !== '') {
        $conditions[] = 'activity.action = :activity_action';
        $params['activity_action'] = $filters['action'];
    }

    if (($filters['entity_type'] ?? '') !== '') {
        $conditions[] = 'activity.entity_type = :activity_entity_type';
        $params['activity_entity_type'] = $filters['entity_type'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = 'activity.created_at >= :activity_date_from';
        $params['activity_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = 'activity.created_at <= :activity_date_to';
        $params['activity_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(activity.summary LIKE :activity_search_summary OR activity.action LIKE :activity_search_action OR COALESCE(user.name, "") LIKE :activity_search_user OR COALESCE(activity.entity_type, "") LIKE :activity_search_entity)';
        $params['activity_search_summary'] = '%' . $filters['search'] . '%';
        $params['activity_search_action'] = '%' . $filters['search'] . '%';
        $params['activity_search_user'] = '%' . $filters['search'] . '%';
        $params['activity_search_entity'] = '%' . $filters['search'] . '%';
    }

    return [
        $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function build_email_log_where(array $filters, string $alias = 'log'): array
{
    $conditions = ['1 = 1'];
    $params = [];

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(' . $alias . '.recipient_email LIKE :email_log_search_recipient_email
            OR COALESCE(' . $alias . '.recipient_name, "") LIKE :email_log_search_recipient_name
            OR ' . $alias . '.subject LIKE :email_log_search_subject
            OR ' . $alias . '.email_type LIKE :email_log_search_type
            OR COALESCE(' . $alias . '.entity_type, "") LIKE :email_log_search_entity
            OR COALESCE(' . $alias . '.error_message, "") LIKE :email_log_search_error
            OR COALESCE(user.name, "") LIKE :email_log_search_user_name
            OR COALESCE(user.email, "") LIKE :email_log_search_user_email)';
        $like = '%' . $filters['search'] . '%';
        $params['email_log_search_recipient_email'] = $like;
        $params['email_log_search_recipient_name'] = $like;
        $params['email_log_search_subject'] = $like;
        $params['email_log_search_type'] = $like;
        $params['email_log_search_entity'] = $like;
        $params['email_log_search_error'] = $like;
        $params['email_log_search_user_name'] = $like;
        $params['email_log_search_user_email'] = $like;
    }

    if (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = $alias . '.status = :email_log_status';
        $params['email_log_status'] = (string) $filters['status'];
    }

    if (($filters['email_type'] ?? '') !== '') {
        $conditions[] = $alias . '.email_type = :email_log_type';
        $params['email_log_type'] = (string) $filters['email_type'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = 'DATE(' . $alias . '.created_at) >= :email_log_date_from';
        $params['email_log_date_from'] = (string) $filters['date_from'];
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = 'DATE(' . $alias . '.created_at) <= :email_log_date_to';
        $params['email_log_date_to'] = (string) $filters['date_to'];
    }

    return [implode(' AND ', $conditions), $params];
}
