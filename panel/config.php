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
// Default username: admin
// Default password: changeme123 (change this on first login!)
// -------------------------------------------------------------
define('PANEL_USER', 'admin');
// Hash for 'changeme123'
define('PANEL_PASS_HASH', '$2y$10$tZ2R7RjU8Y0sL5oM9eJ/1.FzFkZ6oG4Vv8B4uW9tG4lA3Q8n9XJqS');

// Session security timeout (30 minutes)
define('SESSION_TIMEOUT', 1800);

// -------------------------------------------------------------
// Multi-Server Instances List
// Each server has its own RCON credentials, port, and file paths.
// You can add as many servers as you want!
// -------------------------------------------------------------
$SERVERS = [
    'cs_main' => [
        'id'            => 'cs_main',
        'name'          => 'GameLand CS 1.6 #1 (Competitive)',
        'ip'            => '127.0.0.1',
        'port'          => 27015,
        'rcon_password' => 'GameLand@Rcon2026',
        'service_name'  => 'gameland.service',
        'server_dir'    => '/opt/gameland/server',
        'cstrike_dir'   => '/opt/gameland/server/cstrike',
        'users_ini'     => '/opt/gameland/server/cstrike/addons/amxmodx/configs/users.ini',
        'maps_ini'      => '/opt/gameland/server/cstrike/addons/amxmodx/configs/maps.ini',
        'log_file'      => '/opt/gameland/server/logs/server.log',
    ],
    /*
    // Example for a 2nd server instance in the future:
    'cs_public' => [
        'id'            => 'cs_public',
        'name'          => 'GameLand CS 1.6 #2 (Public/Deathmatch)',
        'ip'            => '127.0.0.1',
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
