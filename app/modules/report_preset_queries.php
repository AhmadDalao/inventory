<?php
declare(strict_types=1);

// Saved report preset list queries.

function saved_report_presets(): array
{
    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);

    $rows = Database::fetchAll(
        'SELECT presets.*, creator.name AS creator_name
         FROM report_presets presets
         LEFT JOIN users creator ON creator.id = presets.created_by
         WHERE presets.is_active = 1
           AND (presets.visibility = "shared" OR presets.created_by = :user_id)
         ORDER BY presets.updated_at DESC, presets.created_at DESC, presets.name ASC',
        ['user_id' => $userId]
    );

    return array_values(array_filter($rows, static function (array $preset): bool {
        return saved_report_can_view_type((string) $preset['report_type']);
    }));
}
