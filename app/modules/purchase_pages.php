<?php
declare(strict_types=1);

// Domain module: purchase list/form page queries and page handlers.
// Function names are preserved for route/view/test compatibility.

function purchase_status_options(): array
{
    return [
        'all' => 'All Purchases',
        'draft' => 'Draft',
        'pending_approval' => 'Waiting Approval',
        'approved' => 'Approved',
        'receipt_review' => 'Receipt Review',
        'completed' => 'Completed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

function purchase_filters(): array
{
    $status = trim((string) query('status', 'all'));

    return [
        'status' => array_key_exists($status, purchase_status_options()) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'supplier_id' => ctype_digit((string) query('supplier_id', '')) ? (int) query('supplier_id') : null,
        'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) query('date_from', '')) ? (string) query('date_from') : '',
        'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) query('date_to', '')) ? (string) query('date_to') : '',
        'search' => trim((string) query('search', '')),
    ];
}

function suppliers_for_select(?int $selectedId = null, bool $includeInactive = false): array
{
    $conditions = [$includeInactive ? '1 = 1' : 'is_active = 1'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    $rows = Database::fetchAll(
        'SELECT id, name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, is_active
         FROM suppliers
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY is_active DESC, name ASC',
        $params
    );

    return array_map(static function (array $supplier): array {
        $supplier['supplier_type_label'] = supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null);

        return $supplier;
    }, $rows);
}

function purchase_approvers_for_select(?int $selectedId = null): array
{
    $params = [];
    $selectedClause = '';

    if ($selectedId !== null) {
        $selectedClause = ' OR users.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT DISTINCT users.id, users.name, users.email, users.role
         FROM users
         LEFT JOIN user_permissions permissions
             ON permissions.user_id = users.id
            AND permissions.permission_key = "purchases.approve"
         WHERE users.is_active = 1
           AND (users.role = "owner" OR permissions.id IS NOT NULL' . $selectedClause . ')
         ORDER BY FIELD(users.role, "owner", "admin"), users.name ASC',
        $params
    );
}

function purchase_item_catalog(): array
{
    $rows = Database::fetchAll(
        'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
         FROM items
         WHERE is_active = 1
         ORDER BY name ASC'
    );

    return array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'name' => (string) $item['name'],
            'sku' => (string) $item['sku'],
            'barcode' => (string) ($item['barcode'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'unit' => (string) $item['unit'],
            'cost_per_unit' => (float) $item['cost_per_unit'],
            'notes' => (string) ($item['notes'] ?? ''),
            'image_url' => item_image_url($item['image_path'] ?? null),
        ];
    }, $rows);
}

function purchase_summary_rows(array $filters): array
{
    [$where, $params] = build_purchase_where($filters);

    return Database::fetchAll(
        "SELECT p.*,
                supplier.name AS supplier_name,
                storage.name AS storage_name,
                storage.storage_type,
                requester.name AS requester_name,
                approver.name AS approver_name,
                receiver.name AS receiver_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.requested_total, 0) AS requested_total,
                COALESCE(line_totals.approved_total, 0) AS approved_total,
                COALESCE(line_totals.received_total, 0) AS received_total,
                COALESCE(document_totals.document_count, 0) AS document_count
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         INNER JOIN users requester ON requester.id = p.requester_user_id
         INNER JOIN users approver ON approver.id = p.approver_user_id
         LEFT JOIN users receiver ON receiver.id = p.receiver_user_id
         LEFT JOIN (
             SELECT purchase_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_requested * unit_cost_quoted), 0) AS requested_total,
                    COALESCE(SUM(quantity_approved * unit_cost_approved), 0) AS approved_total,
                    COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS received_total
             FROM purchase_lines
             GROUP BY purchase_id
         ) line_totals ON line_totals.purchase_id = p.id
         LEFT JOIN (
             SELECT purchase_id, COUNT(*) AS document_count
             FROM purchase_documents
             GROUP BY purchase_id
         ) document_totals ON document_totals.purchase_id = p.id
         {$where}
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 250",
        $params
    );
}

function purchase_lines(int $purchaseId): array
{
    return Database::fetchAll(
        'SELECT purchase_line.*,
                catalog.image_path AS catalog_image_path,
                catalog.is_active AS item_is_active
         FROM purchase_lines purchase_line
         LEFT JOIN items catalog ON catalog.id = purchase_line.item_id
         WHERE purchase_line.purchase_id = :purchase_id
         ORDER BY purchase_line.id ASC',
        ['purchase_id' => $purchaseId]
    );
}

function purchase_form_lines(?array $purchase = null): array
{
    $oldNames = input('line_item_name', old('line_item_name', null));

    if (is_array($oldNames)) {
        $itemIds = input('line_item_id', old('line_item_id', []));
        $skus = input('line_item_sku', old('line_item_sku', []));
        $barcodes = input('line_item_barcode', old('line_item_barcode', []));
        $categories = input('line_item_category', old('line_item_category', []));
        $units = input('line_unit', old('line_unit', []));
        $customUnits = input('line_custom_unit', old('line_custom_unit', []));
        $quantities = input('line_quantity_requested', old('line_quantity_requested', []));
        $costs = input('line_unit_cost_quoted', old('line_unit_cost_quoted', []));
        $notes = input('line_item_notes', old('line_item_notes', []));
        $existingImages = input('line_existing_image_path', old('line_existing_image_path', []));
        $rows = [];

        foreach ($oldNames as $index => $name) {
            $rows[] = [
                'item_id' => $itemIds[$index] ?? '',
                'item_name' => $name,
                'item_sku' => $skus[$index] ?? '',
                'item_barcode' => $barcodes[$index] ?? '',
                'item_category' => $categories[$index] ?? '',
                'unit' => $units[$index] ?? 'pcs',
                'custom_unit' => $customUnits[$index] ?? '',
                'quantity_requested' => $quantities[$index] ?? '',
                'unit_cost_quoted' => $costs[$index] ?? '',
                'item_notes' => $notes[$index] ?? '',
                'item_image_path' => $existingImages[$index] ?? '',
            ];
        }

        return $rows !== [] ? $rows : [[]];
    }

    if ($purchase) {
        $rows = [];

        foreach (purchase_lines((int) $purchase['id']) as $line) {
            $unitState = item_unit_form_state((string) $line['unit']);
            $rows[] = [
                'item_id' => $line['item_id'] ? (string) $line['item_id'] : '',
                'item_name' => $line['item_name'],
                'item_sku' => $line['item_sku'],
                'item_barcode' => $line['item_barcode'] ?: '',
                'item_category' => $line['item_category'] ?: '',
                'unit' => $unitState['unit'],
                'custom_unit' => $unitState['custom_unit'],
                'quantity_requested' => format_quantity($line['quantity_requested']),
                'unit_cost_quoted' => format_quantity($line['unit_cost_quoted']),
                'item_notes' => $line['item_notes'] ?: '',
                'item_image_path' => $line['item_image_path'] ?: '',
            ];
        }

        return $rows !== [] ? $rows : [[]];
    }

    return [[]];
}

function purchase_submit_ready(int $purchaseId): bool
{
    return purchase_document_count($purchaseId) > 0
        && (int) Database::scalar('SELECT COUNT(*) FROM purchase_lines WHERE purchase_id = :id', ['id' => $purchaseId]) > 0;
}

function handle_purchases_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.view');

    $filters = purchase_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['purchase']);

    View::render('purchases/index', [
        'title' => site_setting('page.purchases', 'Purchases'),
        'filters' => $filters,
        'purchases' => purchase_summary_rows($filters),
        'statuses' => purchase_status_options(),
        'storages' => all_storages_for_select($filters['storage_id']),
        'suppliers' => suppliers_for_select($filters['supplier_id']),
    ]);
}

function handle_purchases_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');

    View::render('purchases/form', [
        'title' => 'Create Purchase',
        'mode' => 'create',
        'purchase' => [
            'supplier_id' => old('supplier_id', ''),
            'supplier_name' => old('supplier_name', ''),
            'supplier_type' => old('supplier_type', 'product'),
            'supplier_type_other' => old('supplier_type_other', ''),
            'supplier_phone' => old('supplier_phone', ''),
            'supplier_email' => old('supplier_email', ''),
            'supplier_tax_number' => old('supplier_tax_number', ''),
            'supplier_commercial_registration' => old('supplier_commercial_registration', ''),
            'supplier_national_address' => old('supplier_national_address', ''),
            'supplier_authorized_person' => old('supplier_authorized_person', ''),
            'supplier_notes' => old('supplier_notes', ''),
            'destination_storage_id' => old('destination_storage_id', ''),
            'approver_user_id' => old('approver_user_id', ''),
            'expected_date' => old('expected_date', ''),
            'currency' => old('currency', 'SAR'),
            'notes' => old('notes', ''),
        ],
        'lineRows' => purchase_form_lines(),
        'suppliers' => suppliers_for_select(normalize_entity_id(old('supplier_id', ''))),
        'storages' => all_storages_for_select(normalize_entity_id(old('destination_storage_id', ''))),
        'approvers' => purchase_approvers_for_select(normalize_entity_id(old('approver_user_id', ''))),
        'items' => purchase_item_catalog(),
        'unitOptions' => item_unit_options(),
        'documentTypes' => purchase_document_type_options(),
    ]);
}

function handle_purchases_import_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');

    View::render('purchases/import', [
        'title' => 'Bulk Import Purchases',
        'storages' => all_storages_for_select(normalize_entity_id(old('destination_storage_id', ''))),
        'approvers' => purchase_approvers_for_select(normalize_entity_id(old('approver_user_id', ''))),
        'items' => purchase_item_catalog(),
        'unitOptions' => item_unit_options(),
        'documentTypes' => purchase_document_type_options(),
        'defaults' => [
            'destination_storage_id' => old('destination_storage_id', ''),
            'approver_user_id' => old('approver_user_id', ''),
            'currency' => old('default_currency', 'SAR'),
            'document_type' => old('default_document_type', 'quote'),
            'notes' => old('notes', ''),
        ],
    ]);
}

function handle_purchases_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');

    $purchase = find_purchase_or_abort((int) $params['id']);

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be edited.');
        redirect('/purchases/' . $purchase['id']);
    }

    View::render('purchases/form', [
        'title' => 'Edit ' . $purchase['purchase_number'],
        'mode' => 'edit',
        'purchase' => [
            'id' => $purchase['id'],
            'supplier_id' => old('supplier_id', $purchase['supplier_id']),
            'supplier_name' => old('supplier_name', ''),
            'supplier_type' => old('supplier_type', 'product'),
            'supplier_type_other' => old('supplier_type_other', ''),
            'supplier_phone' => old('supplier_phone', ''),
            'supplier_email' => old('supplier_email', ''),
            'supplier_tax_number' => old('supplier_tax_number', ''),
            'supplier_commercial_registration' => old('supplier_commercial_registration', ''),
            'supplier_national_address' => old('supplier_national_address', ''),
            'supplier_authorized_person' => old('supplier_authorized_person', ''),
            'supplier_notes' => old('supplier_notes', ''),
            'destination_storage_id' => old('destination_storage_id', $purchase['destination_storage_id']),
            'approver_user_id' => old('approver_user_id', $purchase['approver_user_id']),
            'expected_date' => old('expected_date', $purchase['expected_date']),
            'currency' => old('currency', $purchase['currency'] ?: 'SAR'),
            'notes' => old('notes', $purchase['notes']),
        ],
        'lineRows' => purchase_form_lines($purchase),
        'documents' => purchase_documents((int) $purchase['id']),
        'suppliers' => suppliers_for_select((int) $purchase['supplier_id']),
        'storages' => all_storages_for_select((int) $purchase['destination_storage_id']),
        'approvers' => purchase_approvers_for_select((int) $purchase['approver_user_id']),
        'items' => purchase_item_catalog(),
        'unitOptions' => item_unit_options(),
        'documentTypes' => purchase_document_type_options(),
    ]);
}
