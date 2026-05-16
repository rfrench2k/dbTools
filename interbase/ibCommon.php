<?php
/**
 * InterBase replication shared helpers.
 */

function ib_escape_arg(string $value): string
{
    return "\"" . str_replace("\"", "\\\"", $value) . "\"";
}

const IB_CONFIG_CACHE_KEY = '__ib_config_cache__';

/**
 * Load InterBase config JSON once.
 */
function ib_load_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $configPath = ib_get_config_path();
    if (!is_file($configPath)) {
        throw new RuntimeException('InterBase config file not found: ' . $configPath);
    }

    $json = file_get_contents($configPath);
    if ($json === false) {
        throw new RuntimeException('Unable to read InterBase config: ' . $configPath);
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON in InterBase config.');
    }

    $cache = $data;
    return $cache;
}

/**
 * Absolute path to the shared JSON config.
 */
function ib_get_config_path(): string
{
    return __DIR__ . '/ib-config.json';
}

/**
 * Resolve configured InterBase databases.
 */
function ib_get_databases(): array
{
    $config = ib_load_config();
    return $config['databases'] ?? [];
}

/**
 * Fetch a database definition by id.
 */
function ib_get_database(string $id): ?array
{
    foreach (ib_get_databases() as $db) {
        if (($db['id'] ?? '') === $id) {
            return $db;
        }
    }
    return null;
}

/**
 * Convenience accessor for sections of the config.
 */
function ib_get_config_section(string $key, $default = null)
{
    $config = ib_load_config();
    return $config[$key] ?? $default;
}

/**
 * Resolve the configured backup directory.
 */
function ib_get_backup_directory(): string
{
    $dir = (string) ib_get_config_section('backupDir', '');
    return $dir;
}

/**
 * Execute a PowerShell helper script bundled with the InterBase tools.
 */
function ib_run_script(string $scriptFilename, array $arguments = [], bool $expectJson = true): array
{
    $script = realpath(__DIR__ . '/' . $scriptFilename);
    if ($script === false) {
        throw new RuntimeException($scriptFilename . ' not found.');
    }

    $configPath = realpath(ib_get_config_path());
    if ($configPath === false) {
        throw new RuntimeException('Unable to resolve InterBase config path.');
    }

    $tokens = [
        'powershell.exe',
        '-NoProfile',
        '-ExecutionPolicy',
        'Bypass',
        '-File',
        ib_escape_arg($script),
        '-ConfigPath',
        ib_escape_arg($configPath)
    ];

    foreach ($arguments as $arg) {
        if ($arg === null || $arg === '') {
            continue;
        }
        $tokens[] = $arg;
    }

    $command = implode(' ', $tokens) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $raw = trim(implode("\n", $output));
    if ($exitCode !== 0) {
        throw new RuntimeException('PowerShell execution failed: ' . $raw);
    }

    if (!$expectJson) {
        return ['output' => $raw];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        $lastBrace = strrpos($raw, '{');
        if ($lastBrace !== false) {
            $jsonSegment = substr($raw, $lastBrace);
            $decoded = json_decode($jsonSegment, true);
        }
        if ($decoded === null) {
            throw new RuntimeException('Unexpected response from PowerShell script: ' . $raw);
        }
    }

    return $decoded;
}

/**
 * Invoke the PowerShell refresh script.
 */
function ib_run_refresh(string $databaseId, bool $checkOnly = false): array
{
    $arguments = [
        '-DatabaseId',
        ib_escape_arg($databaseId)
    ];

    if ($checkOnly) {
        $arguments[] = '-CheckOnly';
    }

    return ib_run_script('ibBackupToolReplicate.ps1', $arguments, true);
}

/**
 * Send JSON success payload.
 */
function ib_send_success(array $payload = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

/**
 * Send JSON error payload.
 */
function ib_send_error(string $message, array $extra = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}
?>
