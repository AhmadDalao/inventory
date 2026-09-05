<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'permissions-characterization';

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$expected = characterization_fixture($root, 'domain');
$actual = characterization_domain_contract();

foreach (['permission_catalog', 'permission_keys', 'role_defaults', 'position_defaults', 'position_templates', 'mobile_required_permissions'] as $section) {
    characterization_assert($actual[$section] === $expected[$section], $suite, 'Permission snapshot changed: ' . $section);
}

$builtIns = built_in_position_templates();
foreach (['operations_manager', 'cleaning_supervisor', 'cleaner', 'storage_manager', 'maintenance_supervisor', 'maintenance_technician', 'cfo', 'accountant', 'it_support', 'reception_staff', 'beach_operations_staff', 'staff'] as $position) {
    characterization_assert(isset($builtIns[$position]), $suite, 'Required position template is missing: ' . $position);
}
foreach (['cleaner', 'maintenance_technician', 'beach_operations_staff', 'staff'] as $position) {
    characterization_assert(($builtIns[$position]['access_role'] ?? '') === 'staff', $suite, $position . ' must retain staff access.');
    characterization_assert(in_array('mobile.access', $builtIns[$position]['permissions'], true), $suite, $position . ' must be ready for explicit mobile enablement.');
    foreach (['movements.adjustment', 'purchases.approve', 'users.permissions', 'settings.secrets'] as $forbidden) {
        characterization_assert(!in_array($forbidden, $builtIns[$position]['permissions'], true), $suite, $position . ' gained dangerous permission: ' . $forbidden);
    }
}
foreach (['movements.adjustment', 'movements.override_department', 'purchases.approve', 'users.permissions', 'settings.secrets'] as $forbidden) {
    characterization_assert(!in_array($forbidden, $builtIns['operations_manager']['permissions'], true), $suite, 'Operations Manager gained separated authority: ' . $forbidden);
}
foreach (['users.permissions', 'settings.edit', 'settings.secrets', 'movements.adjustment'] as $forbidden) {
    characterization_assert(!in_array($forbidden, $builtIns['it_support']['permissions'], true), $suite, 'IT Support gained elevated authority: ' . $forbidden);
}
characterization_assert(user_position_label('night_shift_lead', 'staff') === 'Night Shift Lead', $suite, 'Legacy position labels must remain readable.');

characterization_assert(file_library_can_access(['role' => 'owner']), $suite, 'Owners must retain file-library access.');
characterization_assert(file_library_can_download(['role' => 'admin']), $suite, 'Admins must retain protected-download access.');
characterization_assert(file_library_can_export(['role' => 'owner']), $suite, 'Owners must retain file export access.');
characterization_assert(!file_library_can_access(['role' => 'staff']), $suite, 'Staff must not gain file-library visibility.');
characterization_assert(!file_library_can_manage(['role' => 'staff']), $suite, 'Staff must not gain file management.');

$permissionSources = [
    'app/modules/request_guards.php' => ['request_decision_block_reason', 'request_can_report_receipt'],
    'app/modules/handover_permissions.php' => ['handover_request_decision_block_reason', 'handover_can_report_receipt'],
    'app/modules/purchase_decision_rules.php' => ['purchase_decision_block_reason', 'requester_user_id'],
    'app/modules/workflow_filters.php' => ['purchase_visibility_condition', 'file_asset_visibility_condition'],
    'app/modules/mobile_api_support.php' => ['mobile_api_require_capability', 'storage_forbidden'],
];
foreach ($permissionSources as $relativePath => $markers) {
    $source = (string) file_get_contents($root . '/' . $relativePath);
    foreach ($markers as $marker) {
        characterization_assert(str_contains($source, $marker), $suite, $relativePath . ' is missing denial/scope marker: ' . $marker);
    }
}

echo '[' . $suite . '] PASS' . PHP_EOL;
