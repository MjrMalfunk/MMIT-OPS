<?php
declare(strict_types=1);

// Smoke check for the central Syncro staging write guard. This intentionally
// avoids bootstrap/config secrets and must not perform any network requests.

function smoke_run_php(string $code): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    $output = [];
    $status = 0;
    exec($cmd . ' 2>&1', $output, $status);
    return ['status' => $status, 'output' => implode("\n", $output)];
}

$bootstrap = <<<'PHP_CODE'
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
require_once getcwd() . '/inc/syncro.php';
PHP_CODE;

$defaultCode = $bootstrap . <<<'PHP_CODE'
$failed = [];
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $result = syncro_api_request($method, 'customers/123/policy_assignments', [], ['smoke' => true]);
    if (($result['status'] ?? null) !== 'STAGING_BLOCKED' || empty($result['staging_blocked'])) {
        $failed[] = $method . ' not blocked by default';
    }
}
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;

$overrideCode = <<<'PHP_CODE'
define('SYNCRO_ALLOW_STAGING_WRITES', true);
PHP_CODE . $bootstrap . <<<'PHP_CODE'
$GLOBALS['syncro_api_request_mock_count'] = 0;
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload): array {
    $GLOBALS['syncro_api_request_mock_count']++;
    return ['ok' => true, 'status' => 299, 'method' => $method, 'path' => $path, 'payload' => $payload];
};
$failed = [];
foreach (['POST', 'PUT', 'PATCH'] as $method) {
    $result = syncro_api_request($method, 'customers/123/policy_assignments', [], ['smoke' => true]);
    if (($result['ok'] ?? null) !== true || ($result['status'] ?? null) !== 299) {
        $failed[] = $method . ' not allowed with override';
    }
}
$assetUpdate = syncro_update_customer_asset_policy_folder(12561086, 5029833);
if (($assetUpdate['ok'] ?? null) !== true || ($assetUpdate['path'] ?? null) !== 'customer_assets/12561086' || (int)($assetUpdate['payload']['policy_folder_id'] ?? 0) !== 5029833) {
    $failed[] = 'controlled asset folder update not allowed with override';
}
$delete = syncro_api_request('DELETE', 'customers/123/policy_folders/456');
if (($delete['status'] ?? null) !== 'STAGING_BLOCKED' || empty($delete['staging_blocked'])) {
    $failed[] = 'DELETE not blocked with override';
}
if (($GLOBALS['syncro_api_request_mock_count'] ?? 0) !== 4) {
    $failed[] = 'unexpected mock call count ' . (string)($GLOBALS['syncro_api_request_mock_count'] ?? 0);
}
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;


$syncroSourceLoader = <<<'PHP_CODE'
function smoke_load_syncro_with_stubs(): void {
    $source = file_get_contents(getcwd() . '/inc/syncro.php');
    $source = preg_replace('/^<\?php\s*/', '', $source);
    $source = preg_replace('/^declare\(strict_types=1\);\s*/', '', $source);
    $source = preg_replace('/^require_once __DIR__ \. \'\/db\.php\';\s*/m', '', $source);
    $source = preg_replace('/^require_once __DIR__ \. \'\/clients\.php\';\s*/m', '', $source);
    eval($source);
}
class SmokeStmt {
    private string $sql;
    public function __construct(string $sql) { $this->sql = $sql; }
    public function execute(array $params = []): bool {
        $GLOBALS['smoke_db_execs'][] = ['sql' => $this->sql, 'params' => $params];
        if (stripos($this->sql, 'UPDATE clients SET') !== false) {
            $GLOBALS['smoke_client_marked'][] = $params;
            if (isset($GLOBALS['smoke_client']) && is_array($GLOBALS['smoke_client'])) {
                $GLOBALS['smoke_client']['syncro_customer_id'] = $params[0] ?? null;
                $GLOBALS['smoke_client']['syncro_sync_status'] = $params[1] ?? null;
                $GLOBALS['smoke_client']['syncro_last_error'] = $params[2] ?? null;
            }
        }
        if (stripos($this->sql, 'INSERT INTO client_syncro_folder_map') !== false) {
            $GLOBALS['smoke_folder_map_writes'][] = $params;
            $GLOBALS['smoke_folder_map']['provision_status'] = (string)($params[6] ?? $GLOBALS['smoke_folder_map']['provision_status'] ?? 'PENDING');
            $GLOBALS['smoke_folder_map']['provision_message'] = (string)($params[7] ?? $GLOBALS['smoke_folder_map']['provision_message'] ?? '');
            $GLOBALS['smoke_folder_map']['policy_assignment_status'] = (string)($params[9] ?? $GLOBALS['smoke_folder_map']['policy_assignment_status'] ?? 'PENDING_MANUAL');
            $GLOBALS['smoke_folder_map']['policy_assignment_message'] = (string)($params[10] ?? $GLOBALS['smoke_folder_map']['policy_assignment_message'] ?? '');
        }
        return true;
    }
    public function fetch() {
        if (stripos($this->sql, 'FROM contract ctr') !== false) {
            return ['contract_id' => 14, 'client_id' => 77, 'status' => 'ACTIVE', 'syncro_customer_id' => null, 'syncro_sync_status' => null];
        }
        if (stripos($this->sql, 'FROM client_syncro_folder_map') !== false) {
            return $GLOBALS['smoke_folder_map'] ?? false;
        }
        return false;
    }
    public function fetchAll(): array { return []; }
    public function fetchColumn(): int { return 1; }
}
class SmokeDb {
    public function prepare(string $sql): SmokeStmt { return new SmokeStmt($sql); }
    public function exec(string $sql): bool { $GLOBALS['smoke_db_execs'][] = ['sql' => $sql, 'params' => []]; return true; }
}
function db(): SmokeDb { static $db = null; if ($db === null) { $db = new SmokeDb(); } return $db; }
function db_column_exists(string $table, string $column): bool { return true; }
function db_table_exists(string $table): bool { return true; }
function client_get_by_id(int $clientId): ?array { return $GLOBALS['smoke_client'] ?? null; }
function client_get_contacts(int $clientId): array { return $GLOBALS['smoke_contacts'] ?? []; }
function client_get_locations(int $clientId): array { return $GLOBALS['smoke_locations'] ?? []; }
PHP_CODE;

$customerDefaultCode = $syncroSourceLoader . <<<'PHP_CODE'
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
$GLOBALS['smoke_client'] = ['client_id' => 77, 'legal_name' => 'Smoke Co LLC', 'dba_name' => '', 'email' => 'ops@example.test', 'phone' => '555-0100', 'syncro_customer_id' => null, 'syncro_policy_tier' => 'manage'];
$GLOBALS['syncro_api_request_mock'] = static function (): array { $GLOBALS['smoke_unexpected_api'] = true; return ['ok' => false]; };
smoke_load_syncro_with_stubs();
$result = syncro_retry_contract_sync(14);
$failed = [];
if (($result['status'] ?? null) !== 'STAGING_BLOCKED' || empty($result['staging_blocked'])) { $failed[] = 'customer sync not blocked by default'; }
if (!empty($GLOBALS['smoke_unexpected_api'])) { $failed[] = 'default block called Syncro API'; }
if (($GLOBALS['smoke_client_marked'][0][1] ?? null) !== 'STAGING_BLOCKED') { $failed[] = 'client status not marked STAGING_BLOCKED'; }
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;

$customerOverrideCode = $syncroSourceLoader . <<<'PHP_CODE'
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('SYNCRO_ALLOW_STAGING_WRITES', true);
define('SYNCRO_POLICY_ASSIGNMENTS', [
    'manage.standard.root' => 'MMIT-Test-1100',
    'manage.deploy.workstations' => 'MMIT-Test-1101',
    'manage.deploy.servers' => 'MMIT-Test-1102',
    'manage.production.workstations' => 'MMIT-Test-1103',
    'manage.production.servers' => 'MMIT-Test-1104',
    'protect.standard.root' => 'MMIT-Test-2100',
    'protect.deploy.workstations' => 'MMIT-Test-2101',
    'protect.deploy.servers' => 'MMIT-Test-2102',
    'protect.production.workstations' => 'MMIT-Test-2103',
    'protect.production.servers' => 'MMIT-Test-2104',
    'govern.standard.root' => 'MMIT-Test-3100',
    'govern.deploy.workstations' => 'MMIT-Test-3101',
    'govern.deploy.servers' => 'MMIT-Test-3102',
    'govern.production.workstations' => 'MMIT-Test-3103',
    'govern.production.servers' => 'MMIT-Test-3104',
]);
$GLOBALS['smoke_client'] = ['client_id' => 77, 'legal_name' => 'Smoke Co LLC', 'dba_name' => '', 'email' => 'ops@example.test', 'phone' => '555-0100', 'syncro_customer_id' => null, 'syncro_policy_tier' => 'manage'];
$GLOBALS['smoke_contacts'] = [['first_name' => 'Ops', 'last_name' => 'Smoke', 'email' => 'ops@example.test', 'phone' => '555-0100']];
$GLOBALS['smoke_locations'] = [['address1' => '1 Test Way', 'address2' => '', 'city' => 'St Louis', 'state' => 'MO', 'postal_code' => '63101', 'country' => 'US']];
$GLOBALS['smoke_folder_map'] = [
    'client_id' => 77,
    'syncro_customer_id' => 35690276,
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
    'provision_status' => 'READY',
];
$folders = [
    ['id' => 9001, 'name' => 'Smoke Co LLC', 'parent_id' => null],
    ['id' => 5001, 'name' => 'Deploy', 'parent_id' => 9001],
    ['id' => 5002, 'name' => 'Production', 'parent_id' => 9001],
    ['id' => 5029833, 'name' => 'Workstations', 'parent_id' => 5001, 'partial_policy_id' => 'MMIT-Test-1101'],
    ['id' => 5029834, 'name' => 'Servers', 'parent_id' => 5001, 'partial_policy_id' => 'MMIT-Test-1102'],
    ['id' => 5029835, 'name' => 'Workstations', 'parent_id' => 5002, 'partial_policy_id' => 'MMIT-Test-1103'],
    ['id' => 5029836, 'name' => 'Servers', 'parent_id' => 5002, 'partial_policy_id' => 'MMIT-Test-1104'],
];
$GLOBALS['smoke_api_calls'] = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use ($folders): array {
    $GLOBALS['smoke_api_calls'][] = ['method' => $method, 'path' => $path, 'query' => $query, 'payload' => $payload];
    if ($method === 'GET' && $path === 'customers') {
        return ['ok' => true, 'status' => 200, 'data' => ['customers' => []]];
    }
    if ($method === 'POST' && $path === 'customers') {
        return ['ok' => true, 'status' => 201, 'data' => ['customer' => ['id' => 35690276]]];
    }
    if ($method === 'GET' && $path === 'policy_folders') {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folders' => $folders], 'request' => ['method' => 'GET', 'path' => '/api/v1/policy_folders']];
    }
    if ($method === 'PUT' && $path === 'policy_folders/9001') {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folder' => [
            'id' => 9001,
            'name' => (string)($payload['name'] ?? ''),
            'customer_id' => (int)($payload['customer_id'] ?? 0),
            'parent_id' => null,
            'partial_policy_id' => (string)($payload['partial_policy_id'] ?? ''),
        ]], 'request' => ['method' => 'PUT', 'path' => '/api/v1/policy_folders/9001']];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected ' . $method . ' ' . $path]];
};
smoke_load_syncro_with_stubs();
$result = syncro_contract_activation_sync(14);
$failed = [];
if (empty($result['ok']) || ($result['status'] ?? null) !== 'SYNCED' || ($result['action'] ?? null) !== 'created') { $failed[] = 'customer create did not sync'; }
if (($result['folder_provision_status'] ?? null) !== 'READY') { $failed[] = 'folder provisioning did not run to READY'; }
if (($result['folder_provisioning']['policy_assignment_status'] ?? null) !== 'READY') { $failed[] = 'policy assignment did not run to READY'; }
$methods = array_map(static fn(array $call): string => $call['method'] . ' ' . $call['path'], $GLOBALS['smoke_api_calls']);
foreach (['GET customers', 'POST customers', 'GET policy_folders'] as $expected) {
    if (!in_array($expected, $methods, true)) { $failed[] = 'missing API call ' . $expected; }
}
if (str_contains((string)($result['message'] ?? ''), 'Staging mode')) { $failed[] = 'staging skipped message persisted after allowed sync'; }
$delete = syncro_api_request('DELETE', 'customers/35690276');
if (($delete['status'] ?? null) !== 'STAGING_BLOCKED' || empty($delete['staging_blocked'])) { $failed[] = 'DELETE not blocked in customer override smoke'; }
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;


$staleLinkCode = $syncroSourceLoader . <<<'PHP_CODE'
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('SYNCRO_ALLOW_STAGING_WRITES', true);
define('SYNCRO_POLICY_ASSIGNMENTS', [
    'manage.standard.root' => 'MMIT-Test-1100',
    'manage.deploy.workstations' => 'MMIT-Test-1101',
    'manage.deploy.servers' => 'MMIT-Test-1102',
    'manage.production.workstations' => 'MMIT-Test-1103',
    'manage.production.servers' => 'MMIT-Test-1104',
]);
$GLOBALS['smoke_contacts'] = [['first_name' => 'Ops', 'last_name' => 'Smoke', 'email' => 'ops@example.test', 'phone' => '555-0100']];
$GLOBALS['smoke_locations'] = [['address1' => '1 Test Way', 'address2' => '', 'city' => 'St Louis', 'state' => 'MO', 'postal_code' => '63101', 'country' => 'US']];
$GLOBALS['smoke_folder_map'] = [
    'client_id' => 77,
    'syncro_customer_id' => 12345,
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
    'provision_status' => 'READY',
];
$GLOBALS['smoke_api_calls'] = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload): array {
    $GLOBALS['smoke_api_calls'][] = ['method' => $method, 'path' => $path, 'query' => $query, 'payload' => $payload];
    if ($method === 'GET' && $path === 'customers/12345') {
        return ['ok' => true, 'status' => 200, 'data' => ['customer' => ['id' => 12345]]];
    }
    if ($method === 'PUT' && $path === 'customers/12345') {
        return ['ok' => true, 'status' => 200, 'data' => ['customer' => ['id' => 12345]]];
    }
    if ($method === 'GET' && $path === 'customers/99999') {
        return ['ok' => false, 'status' => 404, 'errors' => ['Customer not found']];
    }
    if ($method === 'GET' && $path === 'customers') {
        return ['ok' => true, 'status' => 200, 'data' => ['customers' => []]];
    }
    if ($method === 'POST' && $path === 'customers') {
        return ['ok' => true, 'status' => 201, 'data' => ['customer' => ['id' => 77777]]];
    }
    if ($method === 'PUT' && str_starts_with($path, 'policy_folders/')) {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folder' => ['id' => (int)substr($path, 15)]]];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected ' . $method . ' ' . $path]];
};
smoke_load_syncro_with_stubs();
$failed = [];
$GLOBALS['smoke_client'] = ['client_id' => 77, 'legal_name' => 'Smoke Co LLC', 'dba_name' => '', 'email' => 'ops@example.test', 'phone' => '555-0100', 'syncro_customer_id' => 12345, 'syncro_policy_tier' => 'manage'];
$result = syncro_sync_client(77);
if (empty($result['ok']) || ($result['action'] ?? null) !== 'updated' || ($GLOBALS['smoke_client']['syncro_sync_status'] ?? null) !== 'SYNCED') { $failed[] = 'valid existing customer did not update successfully'; }
$methods = array_map(static fn(array $call): string => $call['method'] . ' ' . $call['path'], $GLOBALS['smoke_api_calls']);
if (!in_array('GET customers/12345', $methods, true) || !in_array('PUT customers/12345', $methods, true)) { $failed[] = 'existing customer was not validated before update'; }
$GLOBALS['smoke_api_calls'] = [];
$GLOBALS['smoke_client_marked'] = [];
$GLOBALS['smoke_client'] = ['client_id' => 77, 'legal_name' => 'Smoke Co LLC', 'dba_name' => '', 'email' => 'ops@example.test', 'phone' => '555-0100', 'syncro_customer_id' => 99999, 'syncro_policy_tier' => 'manage'];
$stale = syncro_sync_client(77);
if (!empty($stale['ok']) || empty($stale['stale_link']) || ($stale['status'] ?? null) !== 'STALE_LINK') { $failed[] = 'stale Syncro customer ID was not reported'; }
if (($GLOBALS['smoke_client']['syncro_customer_id'] ?? null) !== 99999 || ($GLOBALS['smoke_client']['syncro_sync_status'] ?? null) !== 'STALE_LINK') { $failed[] = 'stale marker did not preserve old ID/status'; }
if (!str_contains((string)($GLOBALS['smoke_client']['syncro_last_error'] ?? ''), 'Stored Syncro customer ID no longer exists')) { $failed[] = 'stale marker message not clear'; }
$staleMethods = array_map(static fn(array $call): string => $call['method'] . ' ' . $call['path'], $GLOBALS['smoke_api_calls']);
if (in_array('POST customers', $staleMethods, true)) { $failed[] = 'stale detection silently created a customer'; }
$GLOBALS['smoke_api_calls'] = [];
$GLOBALS['smoke_client']['syncro_sync_status'] = 'STALE_LINK';
$repair = syncro_repair_stale_customer_link(77);
if (empty($repair['ok']) || ($repair['action'] ?? null) !== 'created' || (int)($repair['cleared_syncro_customer_id'] ?? 0) !== 99999 || (int)($GLOBALS['smoke_client']['syncro_customer_id'] ?? 0) !== 77777) { $failed[] = 'repair did not clear old ID and recreate'; }
$repairMethods = array_map(static fn(array $call): string => $call['method'] . ' ' . $call['path'], $GLOBALS['smoke_api_calls']);
if (!in_array('GET customers', $repairMethods, true) || !in_array('POST customers', $repairMethods, true)) { $failed[] = 'repair did not use recreate path'; }
$delete = syncro_api_request('DELETE', 'customers/99999');
if (($delete['status'] ?? null) !== 'STAGING_BLOCKED' || empty($delete['staging_blocked'])) { $failed[] = 'repair test allowed Syncro DELETE'; }
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;

$productionCode = $syncroSourceLoader . <<<'PHP_CODE'
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
smoke_load_syncro_with_stubs();
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload): array { return ['ok' => true, 'status' => 299, 'method' => $method, 'path' => $path]; };
$result = syncro_api_request('POST', 'customers', [], ['smoke' => true]);
if (($result['ok'] ?? null) !== true || ($result['status'] ?? null) !== 299) { fwrite(STDERR, 'production POST was blocked'); exit(1); }
PHP_CODE;

$checks = [
    'default blocks POST/PUT/PATCH/DELETE' => smoke_run_php($defaultCode),
    'override allows POST/PUT/PATCH and blocks DELETE' => smoke_run_php($overrideCode),
    'customer sync blocks by default in staging' => smoke_run_php($customerDefaultCode),
    'customer sync and provisioning allowed with override' => smoke_run_php($customerOverrideCode),
    'stale link detection and repair recreate path' => smoke_run_php($staleLinkCode),
    'production writes remain allowed' => smoke_run_php($productionCode),
];

$failed = [];
foreach ($checks as $name => $result) {
    if (($result['status'] ?? 1) !== 0) {
        $failed[] = $name . ': ' . ($result['output'] ?? '');
    }
}

if ($failed) {
    fwrite(STDERR, 'Syncro staging guard smoke check failed: ' . implode(' | ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro staging guard smoke check passed.' . PHP_EOL;
