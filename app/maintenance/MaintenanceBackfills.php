<?php
declare(strict_types=1);

trait MaintenanceBackfills
{
    private static function repairMissingStorageBalancesFromMovementHistory(): void
    {
        $settingKey = 'maintenance.repair_missing_storage_balances_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $rows = Database::fetchAll(
            'SELECT latest.item_id,
                    latest.storage_id,
                    latest.balance_after
             FROM (
                 SELECT events.item_id,
                        events.storage_id,
                        events.balance_after,
                        events.reference_code,
                        ROW_NUMBER() OVER (
                            PARTITION BY events.item_id, events.storage_id
                            ORDER BY events.used_at DESC, events.movement_id DESC
                        ) AS rn
                 FROM (
                     SELECT m.item_id,
                            m.source_storage_id AS storage_id,
                            m.source_balance_after AS balance_after,
                            m.reference_code,
                            m.used_at,
                            m.id AS movement_id
                     FROM inventory_movements m
                     WHERE m.source_storage_id IS NOT NULL
                       AND m.source_balance_after IS NOT NULL

                     UNION ALL

                     SELECT m.item_id,
                            m.destination_storage_id AS storage_id,
                            m.destination_balance_after AS balance_after,
                            m.reference_code,
                            m.used_at,
                            m.id AS movement_id
                     FROM inventory_movements m
                     WHERE m.destination_storage_id IS NOT NULL
                       AND m.destination_balance_after IS NOT NULL
                 ) events
             ) latest
             INNER JOIN items i ON i.id = latest.item_id
             INNER JOIN storages s ON s.id = latest.storage_id
             LEFT JOIN item_storage_balances balances
                 ON balances.item_id = latest.item_id
                AND balances.storage_id = latest.storage_id
             WHERE latest.rn = 1
               AND i.is_active = 1
               AND s.is_active = 1
               AND balances.id IS NULL
               AND latest.balance_after >= 0
               AND COALESCE(latest.reference_code, "") != "REMOVE-LOCATION"'
        );

        foreach ($rows as $row) {
            Database::execute(
                'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
                 VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()',
                [
                    'item_id' => (int) $row['item_id'],
                    'storage_id' => (int) $row['storage_id'],
                    'quantity' => round((float) $row['balance_after'], 2),
                ]
            );
        }

        self::setMaintenanceSetting($settingKey, (string) count($rows));
    }

    private static function backfillFileAssets(): void
    {
        $settingKey = 'maintenance.backfill_file_assets_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $count = 0;

        $purchaseDocuments = Database::fetchAll(
            'SELECT documents.*,
                    purchases.id AS purchase_id,
                    purchases.purchase_number
             FROM purchase_documents documents
             INNER JOIN purchases ON purchases.id = documents.purchase_id
             ORDER BY documents.id ASC'
        );

        foreach ($purchaseDocuments as $document) {
            register_purchase_document_asset(
                (int) $document['id'],
                (int) $document['purchase_id'],
                (string) $document['purchase_number'],
                $document,
                $document['uploaded_by'] !== null ? (int) $document['uploaded_by'] : null,
                (string) $document['created_at']
            );
            $count++;
        }

        $purchaseLineImages = Database::fetchAll(
            'SELECT purchase_line.id,
                    purchase_line.purchase_id,
                    purchase_line.item_name,
                    purchase_line.item_image_path,
                    purchase_line.created_at,
                    purchases.requester_user_id
             FROM purchase_lines purchase_line
             INNER JOIN purchases ON purchases.id = purchase_line.purchase_id
             WHERE COALESCE(purchase_line.item_image_path, "") != ""
             ORDER BY purchase_line.id ASC'
        );

        foreach ($purchaseLineImages as $line) {
            register_purchase_line_image_asset(
                (int) $line['id'],
                (int) $line['purchase_id'],
                (string) $line['item_image_path'],
                (string) $line['item_name'],
                $line['requester_user_id'] !== null ? (int) $line['requester_user_id'] : null,
                (string) $line['created_at']
            );
            $count++;
        }

        $itemImages = Database::fetchAll(
            'SELECT id,
                    name,
                    image_path,
                    created_by,
                    created_at
             FROM items
             WHERE COALESCE(image_path, "") != ""
             ORDER BY id ASC'
        );

        foreach ($itemImages as $item) {
            register_item_image_asset(
                (int) $item['id'],
                (string) $item['image_path'],
                (string) $item['name'],
                $item['created_by'] !== null ? (int) $item['created_by'] : null,
                (string) $item['created_at']
            );
            $count++;
        }

        self::setMaintenanceSetting($settingKey, (string) $count);
    }
}
