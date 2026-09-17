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
// Each server has its own RCON credentials, port, and file paths.
// You can add as many servers as you want!
// -------------------------------------------------------------
// Auto-detect default server root dir (e.g. parent of panel/ or /opt/gameland/server)
$detectedServerDir = is_dir('/opt/gameland/server') ? '/opt/gameland/server' : dirname(__DIR__);

$SERVERS = [
    'cs_main' => [
        'id'            => 'cs_main',
        'name'          => 'GameLand CS 1.6 #1 (Competitive)',
        'ip'            => '185.236.38.83',
        'port'          => 27015,
        'rcon_password' => 'GameLand@Rcon2026',
        'service_name'  => 'gameland.service',
        'server_dir'    => $detectedServerDir,
        'cstrike_dir'   => $detectedServerDir . '/cstrike',
        'users_ini'     => $detectedServerDir . '/cstrike/addons/amxmodx/configs/users.ini',
        'maps_ini'      => $detectedServerDir . '/cstrike/addons/amxmodx/configs/maps.ini',
        'log_file'      => $detectedServerDir . '/logs/server.log',
    ],

    /*
    // Example for a 2nd server instance in the future:
    'cs_public' => [
        'id'            => 'cs_public',
        'name'          => 'GameLand CS 1.6 #2 (Public/Deathmatch)',
        'ip'            => '185.236.38.83',
        'port'          => 27016,
        'rcon_password' => 'GameLand@Rcon2026',
        'service_name'  => 'gameland2.service',
        'server_dir'    => '/opt/gameland2/server',
        'cstrike_dir'   => '/opt/gameland2/server/cstrike',
        'users_ini'     => '/opt/gameland2/server/cstrike/addons/amxmodx/configs/users.ini',
        'maps_ini'      => '/opt/gameland2/server/cstrike/addons/amxmodx/configs/maps.ini',
        'log_file'      => '/opt/gameland2/server/logs/server.log',
    ],
    */
];

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
