<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function auth_login_contract_fail(string $message): never
{
    fwrite(STDERR, '[auth-login-contract] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function auth_login_contract_read(string $relativePath): string
{
    global $root;

    $source = file_get_contents($root . '/' . $relativePath);

    if ($source === false) {
        auth_login_contract_fail('Could not read ' . $relativePath . '.');
    }

    return $source;
}

function auth_login_contract_requires(string $source, array $markers, string $scope): void
{
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            auth_login_contract_fail($scope . ' is missing: ' . $marker);
        }
    }
}

$loginView = auth_login_contract_read('views/auth/login.php');
$authActions = auth_login_contract_read('app/modules/auth_actions.php');
$auth = auth_login_contract_read('app/Auth.php');
$components = auth_login_contract_read('assets/css/components.css');

auth_login_contract_requires($loginView, [
    'class="field-label" for="login_password">Password',
    'id="login_password" type="password" name="password"',
    'autocomplete="current-password"',
    'required data-password-input',
    'data-login-password-field',
    'name="remember_me"',
    'Your password is required now.',
], 'Login view');

if (str_contains($loginView, '<label class="field password-field">')) {
    auth_login_contract_fail('Password toggle must not be nested inside a label element.');
}

auth_login_contract_requires($authActions, [
    "if (\$email === '' || \$password === '')",
    "record_login_attempt(\$email, false, 'missing_credentials')",
    'Auth::attempt($email, $password)',
    "input('remember_me', '0') === '1'",
    'Auth::rememberCurrentUser()',
], 'Login handler');

$loginHandlerStart = strpos($authActions, 'function handle_login_submit(): void');
$loginHandlerEnd = strpos($authActions, 'function handle_logout_submit(): void');

if ($loginHandlerStart === false || $loginHandlerEnd === false || $loginHandlerEnd <= $loginHandlerStart) {
    auth_login_contract_fail('Could not isolate the login handler.');
}

$loginHandler = substr($authActions, $loginHandlerStart, $loginHandlerEnd - $loginHandlerStart);
$missingCredentialsCheck = strpos($loginHandler, "if (\$email === '' || \$password === '')");
$passwordAttempt = strpos($loginHandler, 'Auth::attempt($email, $password)');
$rememberTokenCreation = strpos($loginHandler, 'Auth::rememberCurrentUser()');

if (
    $missingCredentialsCheck === false
    || $passwordAttempt === false
    || $rememberTokenCreation === false
    || $missingCredentialsCheck > $passwordAttempt
    || $passwordAttempt > $rememberTokenCreation
) {
    auth_login_contract_fail('Login must reject missing credentials before password authentication, then create remember tokens only after authentication.');
}

auth_login_contract_requires($auth, [
    'password_verify($password, $user[\'password_hash\'])',
    'private static bool $passwordAuthenticatedThisRequest = false;',
    'if (!self::$passwordAuthenticatedThisRequest)',
    'session_regenerate_id(true)',
], 'Authentication service');

auth_login_contract_requires($components, [
    '.auth-card-login .auth-login-password,',
    '.auth-card-login [data-login-password-field]',
    '.auth-card-login .auth-login-password .password-input-wrap,',
    'visibility: visible !important;',
], 'Login field CSS');

echo '[auth-login-contract] PASS' . PHP_EOL;
