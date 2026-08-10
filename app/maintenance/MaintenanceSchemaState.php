<?php
declare(strict_types=1);

trait MaintenanceSchemaState
{
    private static function schemaIsCurrent(): bool
    {
        if (!self::tableExists('app_settings')) {
            return false;
        }

        $currentVersion = Database::scalar(
            'SELECT setting_value
             FROM app_settings
             WHERE setting_key = :setting_key
             LIMIT 1',
            ['setting_key' => self::SCHEMA_VERSION_SETTING_KEY]
        );

        if ((string) $currentVersion !== self::SCHEMA_VERSION) {
            return false;
        }

        return self::userSchemaIsCurrent()
            && self::itemSchemaIsCurrent()
            && self::itemPackageSchemaIsCurrent()
            && self::handoverStatusSchemaIsCurrent()
            && self::handoverUsageSchemaIsCurrent()
            && self::purchaseSchemaIsCurrent()
            && self::supplierSchemaIsCurrent()
            && self::operationalSchemaIsCurrent()
            && self::fileSchemaIsCurrent()
            && self::workflowDocumentSchemaIsCurrent()
            && self::assetCategorySchemaIsCurrent()
            && self::reportPresetSchemaIsCurrent()
            && self::mobileSchemaIsCurrent();
    }

    private static function mobileSchemaIsCurrent(): bool
    {
        return self::tableExists('mobile_user_access')
            && self::columnExists('mobile_user_access', 'direct_restock_enabled')
            && self::tableExists('user_storage_assignments')
            && self::tableExists('mobile_device_sessions')
            && self::tableExists('mobile_operations')
            && self::tableExists('inventory_movement_usage_details');
    }

    private static function reportPresetSchemaIsCurrent(): bool
    {
        return self::tableExists('report_presets')
            && self::columnExists('report_presets', 'filters_json')
            && self::columnExists('report_presets', 'visibility')
            && self::columnExists('report_presets', 'archived_by');
    }

    private static function assetCategorySchemaIsCurrent(): bool
    {
        return self::tableExists('asset_categories')
            && self::columnExists('company_assets', 'category_id')
            && self::columnExists('company_assets', 'depreciation_start_date')
            && self::columnExists('company_assets', 'useful_life_months')
            && self::columnExists('company_assets', 'salvage_value')
            && self::columnExists('company_assets', 'depreciation_method');
    }

    private static function markSchemaCurrent(): void
    {
        if (!self::tableExists('app_settings')) {
            return;
        }

        self::setMaintenanceSetting(self::SCHEMA_VERSION_SETTING_KEY, self::SCHEMA_VERSION);
    }

    private static function handoverStatusSchemaIsCurrent(): bool
    {
        if (!self::tableExists('handovers')) {
            return false;
        }

        $columnType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'handovers',
                'column_name' => 'status',
            ]
        );

        return str_contains($columnType, "'requested'")
            && str_contains($columnType, "'rejected'");
    }

    private static function handoverUsageSchemaIsCurrent(): bool
    {
        return self::tableExists('handover_usage_breakdowns')
            && self::columnExists('handover_usage_breakdowns', 'handover_line_id')
            && self::columnExists('handover_usage_breakdowns', 'reason_code')
            && self::columnExists('handover_usage_breakdowns', 'reason_custom')
            && self::columnExists('handover_usage_breakdowns', 'quantity')
            && self::tableExists('handover_expected_usage_breakdowns')
            && self::columnExists('handover_expected_usage_breakdowns', 'handover_line_id')
            && self::columnExists('handover_expected_usage_breakdowns', 'reason_code')
            && self::columnExists('handover_expected_usage_breakdowns', 'reason_custom')
            && self::columnExists('handover_expected_usage_breakdowns', 'quantity')
            && self::columnExists('handovers', 'usage_reporting_mode')
            && self::tableExists('handover_reconciliations')
            && self::columnExists('handover_reconciliations', 'unit')
            && self::columnExists('handover_reconciliations', 'difference_total')
            && self::tableExists('handover_reconciliation_entries')
            && self::columnExists('handover_reconciliation_entries', 'reason_code')
            && self::columnExists('handover_reconciliation_entries', 'quantity');
    }

    private static function userSchemaIsCurrent(): bool
    {
        return self::columnExists('users', 'position');
    }

    private static function itemSchemaIsCurrent(): bool
    {
        return self::columnExists('items', 'barcode')
            && self::columnExists('purchase_lines', 'item_barcode');
    }

    private static function itemPackageSchemaIsCurrent(): bool
    {
        return self::tableExists('item_package_presets')
            && self::columnExists('item_package_presets', 'pieces_per_unit')
            && self::columnExists('item_package_presets', 'is_default');
    }

    private static function purchaseSchemaIsCurrent(): bool
    {
        foreach (['suppliers', 'purchases', 'purchase_lines', 'purchase_documents', 'purchase_ocr_runs'] as $tableName) {
            if (!self::tableExists($tableName)) {
                return false;
            }
        }

        $columnType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'purchases',
                'column_name' => 'status',
            ]
        );

        return str_contains($columnType, "'receipt_review'")
            && str_contains($columnType, "'completed'");
    }

    private static function supplierSchemaIsCurrent(): bool
    {
        if (!self::tableExists('suppliers')) {
            return false;
        }

        return self::columnExists('suppliers', 'supplier_type')
            && self::columnExists('suppliers', 'supplier_type_other')
            && self::columnExists('suppliers', 'commercial_registration')
            && self::columnExists('suppliers', 'national_address')
            && self::columnExists('suppliers', 'authorized_person');
    }

    private static function operationalSchemaIsCurrent(): bool
    {
        foreach (['stocktakes', 'stocktake_lines', 'activity_logs', 'login_attempts', 'password_reset_tokens', 'email_delivery_logs'] as $tableName) {
            if (!self::tableExists($tableName)) {
                return false;
            }
        }

        $columnType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'stocktakes',
                'column_name' => 'status',
            ]
        );

        return str_contains($columnType, "'pending_approval'")
            && str_contains($columnType, "'approved'");
    }

    private static function fileSchemaIsCurrent(): bool
    {
        if (!self::tableExists('file_assets')) {
            return false;
        }

        return self::columnExists('file_assets', 'relative_path')
            && self::columnExists('file_assets', 'archive_path')
            && self::columnExists('file_assets', 'deleted_at');
    }

    private static function workflowDocumentSchemaIsCurrent(): bool
    {
        if (!self::tableExists('workflow_documents')) {
            return false;
        }

        $documentType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'workflow_documents',
                'column_name' => 'document_type',
            ]
        );

        return self::tableExists('workflow_documents')
            && self::columnExists('workflow_documents', 'workflow_type')
            && self::columnExists('workflow_documents', 'stage')
            && self::columnExists('workflow_documents', 'stored_filename')
            && str_contains($documentType, "'signoff_excel'");
    }
}
