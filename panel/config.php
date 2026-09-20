<?php
/**
 * GameLand Web Panel Configuration
 * Supports Multi-Server instances
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------------------
// Web Panel Authentication
// Username & Password
// You can set plain text password or bcrypt hash here
// -------------------------------------------------------------
define('PANEL_USER', 'admin');
define('PANEL_PASS', 'changeme123'); // Change this to your preferred password!

// Session security timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// -------------------------------------------------------------
// GitHub Integration (for AutoMix plugin auto-deploy)
// Set your GitHub Personal Access Token here to enable:
//   - Triggering GitHub Actions builds remotely
//   - Downloading pre-built .amxx artifacts (recommended!)
//   - No need for local compilation
// Create token at: https://github.com/settings/tokens
// Required scope: repo (or just actions:read for public repos)
// Leave empty to use local amxxpc compiler instead.
// -------------------------------------------------------------
define('GITHUB_TOKEN', '');  // e.g. 'ghp_xxxxxxxxxxxx'
define('GITHUB_REPO', 'GAMELAND-PROJECT/MixSystem_SV_PL');
define('GITHUB_BRANCH', 'main');

// -------------------------------------------------------------
// Multi-Server Instances List
// The main server stays static for backward compatibility. Extra servers
// created by SVGL are loaded from instances/servers.json automatically.
// -------------------------------------------------------------
$detectedServerDir = is_dir('/opt/gameland/server') ? '/opt/gameland/server' : dirname(__DIR__);
$detectedPublicIp = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';

$SERVERS = [
    'main' => [
        'id'            => 'main',
        'name'          => 'GameLand CS 1.6 #1 (Competitive)',
        'ip'            => $detectedPublicIp,
        'port'          => 27015,
        'rcon_password' => 'GameLand@Rcon2026',
        'service_name'  => 'gameland.service',
        'server_dir'    => $detectedServerDir,
        'cstrike_dir'   => $detectedServerDir . '/cstrike',
        'users_ini'     => $detectedServerDir . '/cstrike/addons/amxmodx/configs/users.ini',
        'maps_ini'      => $detectedServerDir . '/cstrike/addons/amxmodx/configs/maps.ini',
        'log_file'      => $detectedServerDir . '/logs/server.log',
    ],
];

function gameland_normalize_server(array $server, string $fallbackIp): array {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($server['id'] ?? ''));
    $serverDir = rtrim((string)($server['server_dir'] ?? ''), '/');

    return [
        'id'            => $id,
        'name'          => (string)($server['name'] ?? ('GameLand ' . $id)),
        'ip'            => (string)($server['ip'] ?? $fallbackIp),
        'port'          => (int)($server['port'] ?? 27015),
        'rcon_password' => (string)($server['rcon_password'] ?? 'GameLand@Rcon2026'),
        'service_name'  => (string)($server['service_name'] ?? ('gameland@' . $id . '.service')),
        'server_dir'    => $serverDir,
        'cstrike_dir'   => $serverDir . '/cstrike',
        'users_ini'     => $serverDir . '/cstrike/addons/amxmodx/configs/users.ini',
        'maps_ini'      => $serverDir . '/cstrike/addons/amxmodx/configs/maps.ini',
        'log_file'      => $serverDir . '/logs/server_' . $id . '.log',
    ];
}

$instancesRegistry = dirname(__DIR__) . '/instances/servers.json';
if (is_file($instancesRegistry)) {
    $registry = json_decode((string)file_get_contents($instancesRegistry), true);
    foreach (($registry['servers'] ?? []) as $server) {
        $normalized = gameland_normalize_server($server, $detectedPublicIp);
        if ($normalized['id'] !== '' && $normalized['server_dir'] !== '') {
            $SERVERS[$normalized['id']] = $normalized;
        }
    }
}

// Helper to get selected active server
function get_active_server() {
    global $SERVERS;
    if (isset($_SESSION['active_server_id']) && isset($SERVERS[$_SESSION['active_server_id']])) {
        return $SERVERS[$_SESSION['active_server_id']];
    }
    // Default to the first server in the list
    $firstKey = array_key_first($SERVERS);
    return $SERVERS[$firstKey];
}
