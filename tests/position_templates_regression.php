<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'prefix::', 'password::', 'allow-live']);
if (!isset($options['base-url'])) {
    fwrite(STDERR, "Usage: php tests/position_templates_regression.php --base-url=http://127.0.0.1:8080 [--prefix=ZZPOSITION...] [--password=...] [--allow-live]\n");
    exit(1);
}

$baseUrl = rtrim((string) $options['base-url'], '/');
$prefix = strtoupper((string) ($options['prefix'] ?? 'ZZPOSITION' . date('YmdHis')));
$password = (string) ($options['password'] ?? 'CodexPosition!123');
$baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
if (in_array($baseHost, ['inventory.ahmaddalao.com', 'www.inventory.ahmaddalao.com'], true) && !array_key_exists('allow-live', $options)) {
    fwrite(STDERR, "Refusing to run position-template regression against {$baseUrl} without --allow-live.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/modules.php';

function position_regression_fail(string $message): never
{
    fwrite(STDERR, '[position-templates-regression] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function position_regression_assert(bool $condition, string $message): void
{
    if (!$condition) {
        position_regression_fail($message);
    }
}

function position_regression_request(string $baseUrl, string $cookieFile, string $method, string $path, array $data = []): array
{
    $handle = curl_init($baseUrl . $path);
    if ($handle === false) {
        position_regression_fail('Could not initialize cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'InventoryPositionTemplatesRegression/1.0',
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }

    $rawResponse = curl_exec($handle);
    if ($rawResponse === false) {
        position_regression_fail('HTTP request failed: ' . curl_error($handle));
    }
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headers = substr((string) $rawResponse, 0, $headerSize);
    $location = null;
    foreach (preg_split("/\r\n|\n|\r/", trim($headers)) ?: [] as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    if (PHP_VERSION_ID < 80500) {
        curl_close($handle);
    }

    return [
        'status' => $status,
        'body' => substr((string) $rawResponse, $headerSize),
        'location' => $location,
    ];
}

function position_regression_csrf(string $html): string
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $node = (new DOMXPath($document))->query('//input[@name="_token"]')->item(0);
    if (!$node instanceof DOMElement) {
        position_regression_fail('Could not find a CSRF token.');
    }

    return (string) $node->getAttribute('value');
}

function position_regression_location_is(?string $location, string $path): bool
{
    return $location === $path || (string) parse_url((string) $location, PHP_URL_PATH) === $path;
}

function position_regression_login(string $baseUrl, string $cookieFile, string $email, string $password): void
{
    $page = position_regression_request($baseUrl, $cookieFile, 'GET', '/login');
    $response = position_regression_request($baseUrl, $cookieFile, 'POST', '/login', [
        '_token' => position_regression_csrf((string) $page['body']),
        'email' => $email,
        'password' => $password,
    ]);
    position_regression_assert($response['status'] === 302 && position_regression_location_is($response['location'], '/dashboard'), 'Login failed for ' . $email);
}

$ownerEmail = strtolower($prefix) . '-owner@example.com';
$editorEmail = strtolower($prefix) . '-editor@example.com';
$employeeEmail = strtolower($prefix) . '-employee@example.com';
$templateCode = strtolower(normalize_position_template_code($prefix . '_beach_lead'));
$ownerCookie = tempnam(sys_get_temp_dir(), 'inventory-position-owner-');
$editorCookie = tempnam(sys_get_temp_dir(), 'inventory-position-editor-');
$ownerId = null;
$editorId = null;
$employeeId = null;
$templateId = null;

if ($ownerCookie === false || $editorCookie === false) {
    position_regression_fail('Could not create cookie jars.');
}

$cleanup = static function () use (&$ownerId, &$editorId, &$employeeId, &$templateId, $ownerEmail, $editorEmail, $employeeEmail, $ownerCookie, $editorCookie): void {
    try {
        $userIds = array_values(array_filter([$employeeId, $editorId, $ownerId], static fn (?int $id): bool => $id !== null));
        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            Database::execute('DELETE FROM inventory_change_events WHERE performed_by IN (' . $placeholders . ')', $userIds);
            Database::execute('DELETE FROM activity_logs WHERE user_id IN (' . $placeholders . ')', $userIds);
            Database::execute('DELETE FROM persistent_login_tokens WHERE user_id IN (' . $placeholders . ')', $userIds);
            Database::execute('DELETE FROM user_storage_assignments WHERE user_id IN (' . $placeholders . ')', $userIds);
            Database::execute('DELETE FROM mobile_user_access WHERE user_id IN (' . $placeholders . ')', $userIds);
            Database::execute('DELETE FROM user_permissions WHERE user_id IN (' . $placeholders . ')', $userIds);
        }
        if ($templateId !== null) {
            Database::execute('DELETE FROM inventory_change_events WHERE entity_type = "position_template" AND entity_id = :id', ['id' => $templateId]);
            Database::execute('DELETE FROM activity_logs WHERE entity_type = "position_template" AND entity_id = :id', ['id' => $templateId]);
        }
        if ($employeeId !== null) {
            Database::execute('DELETE FROM users WHERE id = :id', ['id' => $employeeId]);
        }
        if ($editorId !== null) {
            Database::execute('DELETE FROM users WHERE id = :id', ['id' => $editorId]);
        }
        if ($templateId !== null) {
            Database::execute('DELETE FROM position_template_permissions WHERE position_template_id = :id', ['id' => $templateId]);
            Database::execute('DELETE FROM position_templates WHERE id = :id', ['id' => $templateId]);
        }
        if ($ownerId !== null) {
            Database::execute('DELETE FROM users WHERE id = :id', ['id' => $ownerId]);
        }
        Database::execute('DELETE FROM login_attempts WHERE email IN (?, ?, ?)', [$ownerEmail, $editorEmail, $employeeEmail]);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[position-templates-regression] Cleanup warning: ' . $exception->getMessage() . PHP_EOL);
    }
    foreach ([$ownerCookie, $editorCookie] as $cookieFile) {
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }
    }
};
register_shutdown_function($cleanup);

Database::execute('DELETE FROM login_attempts WHERE email IN (?, ?, ?)', [$ownerEmail, $editorEmail, $employeeEmail]);
Database::execute('DELETE FROM users WHERE email IN (?, ?, ?)', [$ownerEmail, $editorEmail, $employeeEmail]);
Database::execute(
    'INSERT INTO users (name, email, password_hash, role, position, is_active, department_id, created_at, updated_at)
     VALUES (:name, :email, :password_hash, "owner", "owner_operator", 1, :department_id, NOW(), NOW())',
    [
        'name' => $prefix . ' Owner',
        'email' => $ownerEmail,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'department_id' => unassigned_department_id(),
    ]
);
$ownerId = Database::lastInsertId();
position_regression_login($baseUrl, $ownerCookie, $ownerEmail, $password);

$index = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/positions');
position_regression_assert($index['status'] === 200, 'Owner could not open position templates.');
foreach (['Cleaner / Housekeeping Staff', 'Storekeeper / Storage Manager', 'IT Support', 'Beach Operations Staff'] as $seededName) {
    position_regression_assert(str_contains((string) $index['body'], $seededName), 'Missing seeded position: ' . $seededName);
}

$housekeepingId = (int) Database::scalar('SELECT id FROM departments WHERE code = "HOUSEKEEPING" LIMIT 1');
$maintenanceId = (int) Database::scalar('SELECT id FROM departments WHERE code = "MAINTENANCE" LIMIT 1');
$createPage = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/positions/create');
$save = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/positions/save', [
    '_token' => position_regression_csrf((string) $createPage['body']),
    'name' => $prefix . ' Beach Lead',
    'code' => $templateCode,
    'description' => 'Regression template.',
    'access_role' => 'staff',
    'default_department_id' => $housekeepingId,
    'permissions' => ['dashboard.view', 'items.view', 'mobile.access'],
]);
position_regression_assert($save['status'] === 302 && position_regression_location_is($save['location'], '/users/positions'), 'Position create did not redirect to the directory.');
$template = Database::fetch('SELECT * FROM position_templates WHERE code = :code LIMIT 1', ['code' => $templateCode]);
position_regression_assert($template !== null, 'Position template was not persisted.');
$templateId = (int) $template['id'];
position_regression_assert((int) $template['default_department_id'] === $housekeepingId && (string) $template['access_role'] === 'staff', 'Position defaults were not persisted.');
position_regression_assert(position_template_permissions($templateCode) === ['dashboard.view', 'items.view', 'mobile.access'], 'Position permissions were not persisted exactly.');

$userCreatePage = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/create');
position_regression_assert(str_contains((string) $userCreatePage['body'], 'value="' . $templateCode . '"'), 'Custom position is missing from user creation.');
position_regression_assert(str_contains((string) $userCreatePage['body'], 'data-position-departments='), 'User form is missing position department defaults.');
position_regression_assert(!str_contains((string) $userCreatePage['body'], '<option value="owner_operator"'), 'Protected owner position is assignable in the user form.');
$protectedCreate = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/create', [
    '_token' => position_regression_csrf((string) $userCreatePage['body']),
    'name' => $prefix . ' Forbidden Owner',
    'email' => $employeeEmail,
    'position' => 'owner_operator',
    'role' => 'admin',
    'password' => $password,
    'password_confirmation' => $password,
]);
position_regression_assert($protectedCreate['status'] === 302 && position_regression_location_is($protectedCreate['location'], '/users/create'), 'Protected owner position was accepted for a new user.');
position_regression_assert(Database::fetch('SELECT id FROM users WHERE email = :email LIMIT 1', ['email' => $employeeEmail]) === null, 'Protected owner position created an account.');

$userCreatePage = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/create');
$userCreate = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/create', [
    '_token' => position_regression_csrf((string) $userCreatePage['body']),
    'name' => $prefix . ' Employee',
    'email' => $employeeEmail,
    'position' => $templateCode,
    'manager_user_id' => $ownerId,
    'password' => $password,
    'password_confirmation' => $password,
]);
position_regression_assert($userCreate['status'] === 302 && position_regression_location_is($userCreate['location'], '/users'), 'User create with a position template failed.');
$employee = Database::fetch('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $employeeEmail]);
position_regression_assert($employee !== null, 'Template user was not created.');
$employeeId = (int) $employee['id'];
position_regression_assert((string) $employee['role'] === 'staff' && (int) $employee['department_id'] === $housekeepingId, 'User did not receive the template access level and department defaults.');
$originalPermissions = Auth::permissionsForUserId($employeeId);
position_regression_assert($originalPermissions === ['dashboard.view', 'items.view', 'mobile.access'], 'User did not receive the stored position permissions.');

$editTemplatePage = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/positions/' . $templateId . '/edit');
$update = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/positions/save', [
    '_token' => position_regression_csrf((string) $editTemplatePage['body']),
    'position_template_id' => $templateId,
    'name' => $prefix . ' Beach Supervisor',
    'description' => 'Updated regression template.',
    'access_role' => 'admin',
    'default_department_id' => $maintenanceId,
    'permissions' => ['dashboard.view', 'assets.view', 'assets.maintenance'],
]);
position_regression_assert($update['status'] === 302, 'Position update failed.');
position_template_cache_reset();
position_regression_assert(position_template_permissions($templateCode) === ['dashboard.view', 'assets.view', 'assets.maintenance'], 'Updated template permissions were not saved.');
position_regression_assert(Auth::permissionsForUserId($employeeId) === $originalPermissions, 'Editing a template rewrote an existing user.');

$index = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/positions');
$archive = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/positions/' . $templateId . '/archive', [
    '_token' => position_regression_csrf((string) $index['body']),
]);
position_regression_assert($archive['status'] === 302, 'Position archive failed.');
position_template_cache_reset();
position_regression_assert(!array_key_exists($templateCode, user_position_options()), 'Archived position remains assignable to new users.');
position_regression_assert(user_position_label($templateCode, 'staff') === $prefix . ' Beach Supervisor', 'Archived position label was lost.');
$archivedCreatePage = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/create');
position_regression_assert(!str_contains((string) $archivedCreatePage['body'], 'value="' . $templateCode . '"'), 'Archived position appears on user creation.');
$archivedEditPage = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/' . $employeeId . '/edit');
position_regression_assert(str_contains((string) $archivedEditPage['body'], $prefix . ' Beach Supervisor (Archived)'), 'Assigned archived position is missing from user edit.');

$index = position_regression_request($baseUrl, $ownerCookie, 'GET', '/users/positions');
$recover = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/positions/' . $templateId . '/recover', [
    '_token' => position_regression_csrf((string) $index['body']),
]);
position_regression_assert($recover['status'] === 302, 'Position recover failed.');

$itDepartmentId = (int) Database::scalar('SELECT id FROM departments WHERE code = "IT" LIMIT 1');
Database::execute(
    'INSERT INTO users (name, email, password_hash, role, position, is_active, department_id, created_at, updated_at)
     VALUES (:name, :email, :password_hash, "admin", "it_support", 1, :department_id, NOW(), NOW())',
    [
        'name' => $prefix . ' Editor',
        'email' => $editorEmail,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'department_id' => $itDepartmentId,
    ]
);
$editorId = Database::lastInsertId();
save_user_permissions($editorId, ['dashboard.view', 'users.view', 'users.edit'], $ownerId);
position_regression_login($baseUrl, $editorCookie, $editorEmail, $password);
$blockedTemplates = position_regression_request($baseUrl, $editorCookie, 'GET', '/users/positions');
position_regression_assert($blockedTemplates['status'] === 302 && position_regression_location_is($blockedTemplates['location'], '/dashboard'), 'User without users.permissions opened template management.');
$restrictedEditPage = position_regression_request($baseUrl, $editorCookie, 'GET', '/users/' . $employeeId . '/edit');
position_regression_assert($restrictedEditPage['status'] === 200, 'Restricted account editor could not open user edit.');
position_regression_assert(!str_contains((string) $restrictedEditPage['body'], 'name="permissions[]"'), 'Permission checkboxes leaked to an editor without users.permissions.');
$craftedEdit = position_regression_request($baseUrl, $editorCookie, 'POST', '/users/' . $employeeId . '/edit', [
    '_token' => position_regression_csrf((string) $restrictedEditPage['body']),
    'name' => (string) $employee['name'],
    'email' => $employeeEmail,
    'position' => $templateCode,
    'role' => 'staff',
    'department_id' => $housekeepingId,
    'password' => '',
    'password_confirmation' => '',
    'permissions_present' => '1',
    'permissions' => ['settings.secrets', 'users.permissions'],
]);
position_regression_assert($craftedEdit['status'] === 302, 'Restricted user edit did not complete.');
position_regression_assert(Auth::permissionsForUserId($employeeId) === $originalPermissions, 'Crafted user edit bypassed users.permissions.');

$ownerTemplate = position_template_by_code('owner_operator', true);
$ownerArchive = position_regression_request($baseUrl, $ownerCookie, 'POST', '/users/positions/' . (int) ($ownerTemplate['id'] ?? 0) . '/archive', [
    '_token' => position_regression_csrf((string) $index['body']),
]);
position_regression_assert($ownerArchive['status'] === 302, 'Protected owner archive request did not return safely.');
position_template_cache_reset();
position_regression_assert((int) (position_template_by_code('owner_operator', true)['is_active'] ?? 0) === 1, 'Owner position was archived.');

$cleanup();
$ownerId = $editorId = $employeeId = $templateId = null;
echo '[position-templates-regression] PASS: defaults, CRUD, explicit user assignment, archive safety, and permission enforcement.' . PHP_EOL;
