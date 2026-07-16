<?php
declare(strict_types=1);

trait MaintenanceBoot
{
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        ensure_directory_exists(item_upload_directory());
        ensure_directory_exists(purchase_upload_directory());
        ensure_directory_exists(workflow_upload_directory());
        ensure_directory_exists(file_archive_directory());
        ensure_directory_exists(asset_upload_directory());
        ensure_directory_exists(asset_document_upload_directory());
        ensure_directory_exists(brand_logo_upload_directory());

        try {
            self::syncSchema();
        } catch (Throwable $exception) {
            return;
        }
    }
}
