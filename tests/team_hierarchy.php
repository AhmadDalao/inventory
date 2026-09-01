<?php
declare(strict_types=1);

function fail_team_hierarchy(string $message): never
{
    fwrite(STDERR, '[team-hierarchy] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function normalize_entity_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id !== false && $id > 0 ? $id : null;
}

require_once dirname(__DIR__) . '/app/modules/team_hierarchy.php';

function flatten_team_hierarchy(array $nodes, array &$result = []): array
{
    foreach ($nodes as $node) {
        $result[(int) $node['id']] = $node;
        flatten_team_hierarchy($node['children'] ?? [], $result);
    }

    return $result;
}

$records = [
    ['id' => 4, 'name' => 'Zed Staff', 'role' => 'staff', 'manager_user_id' => 2],
    ['id' => 1, 'name' => 'Main Owner', 'role' => 'owner', 'manager_user_id' => null],
    ['id' => 3, 'name' => 'Alpha Staff', 'role' => 'staff', 'manager_user_id' => 2],
    ['id' => 2, 'name' => 'Operations Manager', 'role' => 'admin', 'manager_user_id' => 1],
    ['id' => 5, 'name' => 'Unassigned Staff', 'role' => 'staff', 'manager_user_id' => null],
];

$tree = team_hierarchy_tree($records);
$flat = flatten_team_hierarchy($tree);

if (array_keys($flat) !== [1, 2, 3, 4, 5]) {
    fail_team_hierarchy('Every active user must appear exactly once in deterministic tree order.');
}
if ((int) ($tree[0]['id'] ?? 0) !== 1 || (int) ($tree[0]['children'][0]['id'] ?? 0) !== 2) {
    fail_team_hierarchy('Manager relationships were not nested correctly.');
}
if (array_map(static fn (array $node): int => (int) $node['id'], $tree[0]['children'][0]['children'] ?? []) !== [3, 4]) {
    fail_team_hierarchy('Direct reports must be sorted consistently by role and name.');
}

$legacyCycle = [
    ['id' => 10, 'name' => 'Legacy A', 'role' => 'admin', 'manager_user_id' => 11],
    ['id' => 11, 'name' => 'Legacy B', 'role' => 'admin', 'manager_user_id' => 10],
];
$legacyFlat = flatten_team_hierarchy(team_hierarchy_tree($legacyCycle));
if (array_keys($legacyFlat) !== [10, 11]) {
    fail_team_hierarchy('Legacy cyclic data must stay visible for correction.');
}

if (team_hierarchy_normalize_user_ids(['4', 2, '4', '', ['bad']], '9') !== [4, 2]) {
    fail_team_hierarchy('Bulk employee IDs must be positive, unique, and flat.');
}
if (team_hierarchy_normalize_user_ids([], '9') !== [9]) {
    fail_team_hierarchy('Single-person manager changes must remain backward compatible.');
}

fwrite(STDOUT, '[team-hierarchy] PASS' . PHP_EOL);
