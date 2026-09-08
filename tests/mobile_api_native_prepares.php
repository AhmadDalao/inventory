<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseSource = file_get_contents($root . '/app/Database.php');

if ($databaseSource === false) {
    fwrite(STDERR, "[mobile-api-native-prepares] FAIL: Could not read app/Database.php.\n");
    exit(1);
}

if (!preg_match('/PDO::ATTR_EMULATE_PREPARES\s*=>\s*false/', $databaseSource)) {
    fwrite(STDERR, "[mobile-api-native-prepares] FAIL: Database connections must use native prepares.\n");
    exit(1);
}

/**
 * Decode a non-interpolated PHP string token without executing source code.
 */
function mobile_native_sql_literal(string $literal): string
{
    $quote = $literal[0] ?? '';
    $value = substr($literal, 1, -1);

    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
    }

    return stripcslashes($value);
}

$failures = [];
$files = [$root . '/index.php'];
foreach (['app', 'scripts', 'views'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
            $files[] = $entry->getPathname();
        }
    }
}
$files = array_values(array_unique($files));
sort($files);

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $failures[] = basename($file) . ': unreadable';
        continue;
    }

    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $sql = mobile_native_sql_literal($token[1]);
        if (!preg_match('/^\s*(?:DELETE|INSERT|REPLACE|SELECT|UPDATE)\b/i', $sql)) {
            continue;
        }

        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        foreach (array_count_values($matches[1] ?? []) as $placeholder => $count) {
            if ($count > 1) {
                $failures[] = sprintf(
                    '%s:%d repeats :%s %d times',
                    substr($file, strlen($root) + 1),
                    (int) $token[2],
                    $placeholder,
                    $count
                );
            }
        }
    }
}

if ($failures !== []) {
    fwrite(
        STDERR,
        "[mobile-api-native-prepares] FAIL: Native PDO cannot reuse a named placeholder in one statement:\n- "
        . implode("\n- ", $failures)
        . "\n"
    );
    exit(1);
}

echo '[mobile-api-native-prepares] PASS (' . count($files) . ' runtime PHP files checked)' . PHP_EOL;
