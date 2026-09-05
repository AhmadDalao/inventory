<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'prefix::', 'password::', 'allow-live']);

if (!isset($options['base-url'])) {
    fwrite(STDERR, "Usage: php tests/departments_regression.php --base-url=http://127.0.0.1:8080 [--prefix=ZZDEPT...] [--password=...] [--allow-live]\n");
    exit(1);
}

$baseUrl = rtrim((string) $options['base-url'], '/');
$prefix = strtoupper((string) ($options['prefix'] ?? 'ZZDEPT' . date('YmdHis')));
$password = (string) ($options['password'] ?? 'CodexDept!123');
$baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

if (in_array($baseHost, ['inventory.ahmaddalao.com', 'www.inventory.ahmaddalao.com'], true) && !array_key_exists('allow-live', $options)) {
    fwrite(STDERR, "Refusing to run department regression against {$baseUrl} without --allow-live.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/modules.php';

function department_regression_fail(string $message): never
{
    fwrite(STDERR, '[departments-regression] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function department_regression_assert(bool $condition, string $message): void
{
    if (!$condition) {
        department_regression_fail($message);
    }
}

function department_regression_request(string $baseUrl, string $cookieFile, string $method, string $path, array $data = []): array
{
    $handle = curl_init($baseUrl . $path);
    if ($handle === false) {
        department_regression_fail('Could not initialize cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'InventoryDepartmentsRegression/1.0',
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }

    $rawResponse = curl_exec($handle);
    if ($rawResponse === false) {
        department_regression_fail('HTTP request failed: ' . curl_error($handle));
    }

    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headers = substr((string) $rawResponse, 0, $headerSize);
    $body = substr((string) $rawResponse, $headerSize);
    $location = null;

    foreach (preg_split("/\r\n|\n|\r/", trim($headers)) ?: [] as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }

    if (PHP_VERSION_ID < 80500) {
        curl_close($handle);
    }

    return ['status' => $status, 'body' => $body, 'location' => $location];
}

function department_regression_csrf(string $html): string
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($document);
    $node = $xpath->query('//input[@name="_token"]')->item(0);
    if (!$node instanceof DOMElement) {
        department_regression_fail('Could not find a CSRF token.');
    }

    return (string) $node->getAttribute('value');
}

function department_regression_location_is(?string $location, string $path): bool
{
    return $location === $path || (string) parse_url((string) $location, PHP_URL_PATH) === $path;
}

$email = strtolower($prefix) . '-owner@example.com';
$departmentName = $prefix . ' Operations';
$updatedDepartmentName = $prefix . ' Field Operations';
$departmentCode = substr($prefix . '_OPS', 0, 40);
$cookieFile = tempnam(sys_get_temp_dir(), 'inventory-dept-reg-');
$userId = null;
$departmentId = null;
$templateId = null;

if ($cookieFile === false) {
    department_regression_fail('Could not create a cookie jar.');
}

$cleanup = static function () use (&$userId, &$departmentId, &$templateId, $email, $cookieFile): void {
    try {
        if ($userId !== null || $departmentId !== null) {
            $conditions = [];
            $params = [];
            if ($userId !== null) {
                $conditions[] = 'performed_by = :performed_by';
                $params['performed_by'] = $userId;
            }
            if ($departmentId !== null) {
                $conditions[] = '(entity_type = "department" AND entity_id = :department_id)';
                $params['department_id'] = $departmentId;
            }
            Database::execute('DELETE FROM inventory_change_events WHERE ' . implode(' OR ', $conditions), $params);
        }
        if ($templateId !== null) {
            Database::execute('DELETE FROM position_template_permissions WHERE position_template_id = :id', ['id' => $templateId]);
            Database::execute('DELETE FROM position_templates WHERE id = :id', ['id' => $templateId]);
        }
        if ($departmentId !== null) {
            Database::execute(
                'DELETE FROM activity_logs WHERE entity_type = "department" AND entity_id = :department_id',
                ['department_id' => $departmentId]
            );
            Database::execute('DELETE FROM departments WHERE id = :id', ['id' => $departmentId]);
        }

        if ($userId !== null) {
            Database::execute('DELETE FROM persistent_login_tokens WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM user_permissions WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM activity_logs WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM users WHERE id = :user_id', ['user_id' => $userId]);
        }

        Database::execute('DELETE FROM login_attempts WHERE email = :email', ['email' => $email]);
    } catch (Throwable $exception) {
        fwrite(STDERR, '[departments-regression] Cleanup warning: ' . $exception->getMessage() . PHP_EOL);
    }

    if (is_file($cookieFile)) {
        @unlink($cookieFile);
    }
};

register_shutdown_function($cleanup);

Database::execute('DELETE FROM login_attempts WHERE email = :email', ['email' => $email]);
Database::execute('DELETE FROM users WHERE email = :email', ['email' => $email]);
Database::execute(
    'INSERT INTO users (name, email, password_hash, role, position, is_active, created_at, updated_at)
     VALUES (:name, :email, :password_hash, "owner", "Regression Owner", 1, NOW(), NOW())',
    [
        'name' => $prefix . ' Owner',
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]
);
$userId = Database::lastInsertId();

$loginPage = department_regression_request($baseUrl, $cookieFile, 'GET', '/login');
department_regression_assert($loginPage['status'] === 200, 'Login page did not load.');
$login = department_regression_request($baseUrl, $cookieFile, 'POST', '/login', [
    '_token' => department_regression_csrf((string) $loginPage['body']),
    'email' => $email,
    'password' => $password,
]);
department_regression_assert($login['status'] === 302, 'Owner login did not redirect.');
department_regression_assert(department_regression_location_is($login['location'], '/dashboard'), 'Owner login did not reach the dashboard.');

$departmentsPage = department_regression_request($baseUrl, $cookieFile, 'GET', '/departments');
department_regression_assert($departmentsPage['status'] === 200, 'Departments page did not load.');
$save = department_regression_request($baseUrl, $cookieFile, 'POST', '/departments/save', [
    '_token' => department_regression_csrf((string) $departmentsPage['body']),
    'name' => $departmentName,
    'code' => '',
]);
department_regression_assert($save['status'] === 302, 'Department create did not redirect.');
department_regression_assert(department_regression_location_is($save['location'], '/departments'), 'Department create redirected to the wrong page.');

$department = Database::fetch('SELECT * FROM departments WHERE name = :name LIMIT 1', ['name' => $departmentName]);
department_regression_assert(is_array($department), 'Saved department was not persisted.');
$departmentId = (int) $department['id'];
department_regression_assert((string) $department['code'] === normalize_department_code('', $departmentName), 'Generated department code is incorrect.');
department_regression_assert((int) $department['created_by'] === $userId, 'Department creator was not recorded.');
department_regression_assert((int) $department['updated_by'] === $userId, 'Department updater was not recorded.');

$departmentsPage = department_regression_request($baseUrl, $cookieFile, 'GET', '/departments');
department_regression_assert(str_contains((string) $departmentsPage['body'], 'Department saved.'), 'Save confirmation was not shown.');
department_regression_assert(str_contains((string) $departmentsPage['body'], $departmentName), 'Saved department is missing from the directory.');
$departmentEditPage = department_regression_request($baseUrl, $cookieFile, 'GET', '/departments?edit=' . $departmentId);
department_regression_assert($departmentEditPage['status'] === 200, 'Department edit form did not load.');
department_regression_assert(str_contains((string) $departmentEditPage['body'], 'name="department_id" value="' . $departmentId . '"'), 'Department edit form is missing its record id.');
department_regression_assert(str_contains((string) $departmentEditPage['body'], 'value="' . $departmentName . '"'), 'Department edit form did not preload its name.');

$update = department_regression_request($baseUrl, $cookieFile, 'POST', '/departments/save', [
    '_token' => department_regression_csrf((string) $departmentEditPage['body']),
    'department_id' => (string) $departmentId,
    'name' => $updatedDepartmentName,
    'code' => $departmentCode,
    'is_active' => '1',
]);
department_regression_assert($update['status'] === 302, 'Department update did not redirect.');
$department = Database::fetch('SELECT * FROM departments WHERE id = :id LIMIT 1', ['id' => $departmentId]);
department_regression_assert((string) ($department['name'] ?? '') === $updatedDepartmentName, 'Department name update was not persisted.');
department_regression_assert((string) ($department['code'] ?? '') === $departmentCode, 'Department code update was not persisted.');

Database::execute(
    'INSERT INTO position_templates (
        code, name, description, access_role, default_department_id, is_system, is_active,
        sort_order, created_by, updated_by, created_at, updated_at
     ) VALUES (
        :code, :name, NULL, "staff", :department_id, 0, 1,
        9990, :created_by, :updated_by, NOW(), NOW()
     )',
    [
        'code' => strtolower($departmentCode) . '_template',
        'name' => $prefix . ' Department Template',
        'department_id' => $departmentId,
        'created_by' => $userId,
        'updated_by' => $userId,
    ]
);
$templateId = Database::lastInsertId();
Database::execute(
    'INSERT INTO position_template_permissions (position_template_id, permission_key, created_at) VALUES (:id, "dashboard.view", NOW())',
    ['id' => $templateId]
);

$departmentsPage = department_regression_request($baseUrl, $cookieFile, 'GET', '/departments');
$archive = department_regression_request($baseUrl, $cookieFile, 'POST', '/departments/' . $departmentId . '/archive', [
    '_token' => department_regression_csrf((string) $departmentsPage['body']),
]);
department_regression_assert($archive['status'] === 302, 'Department archive did not redirect.');
$department = Database::fetch('SELECT * FROM departments WHERE id = :id LIMIT 1', ['id' => $departmentId]);
department_regression_assert((int) ($department['is_active'] ?? 1) === 0 && !empty($department['deleted_at']), 'Department archive was not persisted.');
department_regression_assert(
    (int) Database::scalar('SELECT default_department_id FROM position_templates WHERE id = :id', ['id' => $templateId]) === (int) unassigned_department_id(),
    'Archived department remained a default on a position template.'
);

$departmentsPage = department_regression_request($baseUrl, $cookieFile, 'GET', '/departments');
$recover = department_regression_request($baseUrl, $cookieFile, 'POST', '/departments/' . $departmentId . '/recover', [
    '_token' => department_regression_csrf((string) $departmentsPage['body']),
]);
department_regression_assert($recover['status'] === 302, 'Department recovery did not redirect.');
$department = Database::fetch('SELECT * FROM departments WHERE id = :id LIMIT 1', ['id' => $departmentId]);
department_regression_assert((int) ($department['is_active'] ?? 0) === 1 && empty($department['deleted_at']), 'Department recovery was not persisted.');

echo '[departments-regression] PASS: create, generated code, update, archive, and recover.' . PHP_EOL;
