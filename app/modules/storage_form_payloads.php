<?php
declare(strict_types=1);

function default_storage_payload(?array $sourceStorage = null): array
{
    return [
        'name' => old('name', $sourceStorage ? next_storage_copy_name((string) $sourceStorage['name']) : ''),
        'storage_type' => old('storage_type', (string) ($sourceStorage['storage_type'] ?? 'storage')),
        'notes' => old('notes', (string) ($sourceStorage['notes'] ?? '')),
        'owner_user_id' => old('owner_user_id', (string) ($sourceStorage['owner_user_id'] ?? ((Auth::user()['id'] ?? '') ?: ''))),
        'copy_storage_id' => old('copy_storage_id', $sourceStorage ? (string) $sourceStorage['id'] : ''),
        'copy_contents_mode' => old('copy_contents_mode', 'empty'),
        'is_active' => 1,
    ];
}
