<?php
declare(strict_types=1);

final class Maintenance
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        ensure_directory_exists(item_upload_directory());

        try {
            self::syncSchema();
        } catch (Throwable $exception) {
            return;
        }
    }

    private static function syncSchema(): void
    {
        $itemsTableExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'items']
        );

        if ($itemsTableExists === 0) {
            return;
        }

        $imageColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'items',
                'column_name' => 'image_path',
            ]
        );

        if ($imageColumnExists === 0) {
            Database::execute('ALTER TABLE items ADD COLUMN image_path VARCHAR(255) NULL AFTER cost_per_unit');
        }
    }
}
