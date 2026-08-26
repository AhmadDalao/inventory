<?php
declare(strict_types=1);

function default_storage_payload(?array $sourceStorage = null): array
{
    return [
        'name' => old('name', $sourceStorage ? next_storage_copy_name((string) $sourceStorage['name']) : ''),
        'storage_type' => old('storage_type', (string) ($sourceStorage['storage_type'] ?? 'storage')),
        'usage_profile' => old('usage_profile', normalize_storage_usage_profile((string) ($sourceStorage['usage_profile'] ?? 'general'))),
        'notes' => old('notes', (string) ($sourceStorage['notes'] ?? '')),
        'owner_user_id' => old('owner_user_id', (string) ($sourceStorage['owner_user_id'] ?? ((Auth::user()['id'] ?? '') ?: ''))),
        'owner_user_ids' => old('owner_user_ids', $sourceStorage ? storage_owner_user_ids((int) $sourceStorage['id']) : [(int) (Auth::user()['id'] ?? 0)]),
        'member_user_ids' => old('member_user_ids', $sourceStorage ? storage_assigned_user_ids((int) $sourceStorage['id'], 'member') : []),
        'copy_storage_id' => old('copy_storage_id', $sourceStorage ? (string) $sourceStorage['id'] : ''),
        'copy_contents_mode' => old('copy_contents_mode', 'empty'),
        'is_active' => 1,
    ];
}
