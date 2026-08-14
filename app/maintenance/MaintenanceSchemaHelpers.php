<?php
declare(strict_types=1);

trait MaintenanceSchemaHelpers
{
    private static function setMaintenanceSetting(string $settingKey, string $settingValue): void
    {
        Database::execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:setting_key, :setting_value, NULL, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = NULL, updated_at = NOW()',
            [
                'setting_key' => $settingKey,
                'setting_value' => $settingValue,
            ]
        );
    }

    private static function maintenanceSettingExists(string $settingKey): bool
    {
        return Database::fetch(
            'SELECT setting_key FROM app_settings WHERE setting_key = :setting_key LIMIT 1',
            ['setting_key' => $settingKey]
        ) !== null;
    }

    private static function tableExists(string $tableName): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name',
            ['table_name' => $tableName]
        ) > 0;
    }

    private static function columnExists(string $tableName, string $columnName): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name',
            [
                'table_name' => $tableName,
                'column_name' => $columnName,
            ]
        ) > 0;
    }

    private static function indexExists(string $tableName, string $indexName): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name',
            [
                'table_name' => $tableName,
                'index_name' => $indexName,
            ]
        ) > 0;
    }

    private static function ensureNonUniqueIndex(string $table, string $column, string $indexName): void
    {
        $uniqueIndexes = Database::fetchAll(
            'SELECT DISTINCT index_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
               AND non_unique = 0
               AND index_name != "PRIMARY"',
            [
                'table_name' => $table,
                'column_name' => $column,
            ]
        );

        foreach ($uniqueIndexes as $index) {
            Database::execute('ALTER TABLE `' . $table . '` DROP INDEX `' . $index['index_name'] . '`');
        }

        $indexExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name',
            [
                'table_name' => $table,
                'index_name' => $indexName,
            ]
        );

        if ($indexExists === 0) {
            Database::execute('CREATE INDEX `' . $indexName . '` ON `' . $table . '` (`' . $column . '`)');
        }
    }

    private static function ensureIndexExists(string $table, string $indexName, string $sql): void
    {
        $indexExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name',
            [
                'table_name' => $table,
                'index_name' => $indexName,
            ]
        );

        if ($indexExists === 0) {
            Database::execute($sql);
        }
    }

    private static function ensureForeignKeyExists(string $table, string $constraintName, string $sql): void
    {
        $constraintExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND constraint_name = :constraint_name
               AND constraint_type = "FOREIGN KEY"',
            [
                'table_name' => $table,
                'constraint_name' => $constraintName,
            ]
        );

        if ($constraintExists === 0) {
            Database::execute($sql);
        }
    }
}
