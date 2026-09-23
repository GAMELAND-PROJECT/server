<?php
require_once __DIR__ . '/../config.php';

class ServerCmd {

    public static function getManagerRoot() {
        return realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    public static function getSvglPath() {
        $root = self::getManagerRoot();
        if (is_file($root . '/svgl.sh')) {
            return $root . '/svgl.sh';
        }
        return '/usr/local/bin/svgl';
    }

    public static function validateInstanceId($id) {
        return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{2,32}$/', $id);
    }

    public static function getServerList(array $servers) {
        $result = [];
        foreach ($servers as $server) {
            $status = self::getServiceStatus($server['service_name']);
            $runtime = self::getServerRuntimeSettings($server);
            $result[] = [
                'id' => $server['id'],
                'name' => $server['name'],
                'ip' => $server['ip'],
                'port' => $server['port'],
                'slots' => $runtime['slots'],
                'map' => $runtime['map'],
                'hostname' => $runtime['hostname'],
                'service_name' => $server['service_name'],
                'server_dir' => $server['server_dir'],
                'status' => $status,
                'protected' => $server['id'] === 'main',
            ];
        }
        return $result;
    }

    public static function getManagedServers() {
        global $SERVERS;
        return is_array($SERVERS) ? $SERVERS : [];
    }

    private static function aggregateResults(array $results) {
        $failed = [];
        foreach ($results as $id => $result) {
            if (!($result['success'] ?? false)) {
                $failed[$id] = $result['message'] ?? 'Operation failed';
            }
        }
        return [
            'success' => empty($failed),
            'results' => $results,
            'failed' => $failed,
            'message' => empty($failed)
                ? 'Operation completed on all selected servers.'
                : 'Operation completed with failures on: ' . implode(', ', array_keys($failed)),
        ];
    }

    public static function saveAdminEverywhere($auth, $password, $access, $flags, $comment, $all = false, $selectedId = null) {
        $servers = self::getManagedServers();
        if (!$all) {
            $selectedId = $selectedId ?: (get_active_server()['id'] ?? 'main');
            $servers = isset($servers[$selectedId]) ? [$selectedId => $servers[$selectedId]] : [];
        }
        $results = [];
        foreach ($servers as $id => $server) {
            $res = self::saveAdmin($server['users_ini'], $auth, $password, $access, $flags, $comment);
            if ($res['success'] && self::getServiceStatus($server['service_name']) === 'running') {
                $reload = self::reloadAdminsLive($server);
                $res['reload'] = $reload;
                if (!$reload['success']) {
                    $res['success'] = false;
                    $res['message'] .= ' File saved, but live reload failed.';
                }
            }
            $results[$id] = $res;
        }
        return self::aggregateResults($results);
    }

    public static function deleteAdminEverywhere($auth, $all = false, $selectedId = null) {
        $servers = self::getManagedServers();
        if (!$all) {
            $selectedId = $selectedId ?: (get_active_server()['id'] ?? 'main');
            $servers = isset($servers[$selectedId]) ? [$selectedId => $servers[$selectedId]] : [];
        }
        $results = [];
        foreach ($servers as $id => $server) {
            $res = self::deleteAdmin($server['users_ini'], $auth);
            if ($res['success'] && self::getServiceStatus($server['service_name']) === 'running') {
                $reload = self::reloadAdminsLive($server);
                $res['reload'] = $reload;
                if (!$reload['success']) {
                    $res['success'] = false;
                    $res['message'] .= ' File deleted, but live reload failed.';
                }
            }
            $results[$id] = $res;
        }
        return self::aggregateResults($results);
    }

    public static function savePluginsEverywhere(array $enabledPluginFiles, $all = false, $selectedId = null, $restart = false) {
        $servers = self::getManagedServers();
        if (!$all) {
            $selectedId = $selectedId ?: (get_active_server()['id'] ?? 'main');
            $servers = isset($servers[$selectedId]) ? [$selectedId => $servers[$selectedId]] : [];
        }
        $results = [];
        foreach ($servers as $id => $server) {
            $res = self::savePluginsState($server, $enabledPluginFiles);
            if ($res['success'] && $restart) {
                $res['restart'] = self::controlService($server['service_name'], 'restart');
                if (!($res['restart']['success'] ?? false)) {
                    $res['success'] = false;
                    $res['message'] .= ' Configuration saved, but restart failed.';
                }
            }
            $results[$id] = $res;
        }
        return self::aggregateResults($results);
    }

    public static function deployMixEverywhere($compile = true, $restart = true) {
        $results = [];
        foreach (self::getManagedServers() as $id => $server) {
            $sync = self::syncMixFromGitHub($server);
            $result = ['success' => $sync['success'], 'sync' => $sync];
            if ($sync['success'] && $compile) {
                $result['compile'] = self::compilePlugins($server);
                $result['success'] = $result['compile']['success'];
            }
            if ($result['success'] && $restart) {
                $result['restart'] = self::controlService($server['service_name'], 'restart');
                $result['success'] = $result['restart']['success'] ?? false;
            }
            $result['message'] = $result['success'] ? 'Deployed successfully.' : 'Deployment failed.';
            $results[$id] = $result;
        }
        return self::aggregateResults($results);
    }

    private static function getServerRuntimeSettings(array $server) {
        $settings = [
            'slots' => 12,
            'map' => 'de_dust2',
            'hostname' => $server['name'] ?? '',
        ];

        $cfg = rtrim($server['server_dir'], '/') . '/cstrike/server.cfg';
        if (is_file($cfg)) {
            $content = (string)file_get_contents($cfg);
            if (preg_match('/^\s*hostname\s+"?([^"\r\n]+)"?/mi', $content, $m)) {
                $settings['hostname'] = trim($m[1]);
            }
        }

        if (($server['id'] ?? '') === 'main') {
            $start = rtrim($server['server_dir'], '/') . '/start.sh';
            if (is_file($start)) {
                $content = (string)file_get_contents($start);
                if (preg_match('/^MAX_PLAYERS="([^"]+)"/m', $content, $m)) $settings['slots'] = (int)$m[1];
                if (preg_match('/^MAP="([^"]+)"/m', $content, $m)) $settings['map'] = $m[1];
            }
        } else {
            $env = self::getManagerRoot() . '/instances/' . $server['id'] . '.env';
            if (is_file($env)) {
                $content = (string)file_get_contents($env);
                if (preg_match('/^MAX_PLAYERS="([^"]+)"/m', $content, $m)) $settings['slots'] = (int)$m[1];
                if (preg_match('/^MAP="([^"]+)"/m', $content, $m)) $settings['map'] = $m[1];
            }
        }

        return $settings;
    }

    private static function getRegistryPath() {
        return self::getManagerRoot() . '/instances/servers.json';
    }

    private static function readRegistry() {
        $path = self::getRegistryPath();
        if (!is_file($path)) {
            return ['servers' => []];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : ['servers' => []];
    }

    private static function writeRegistry(array $registry) {
        $path = self::getRegistryPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return @file_put_contents($path, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
    }

    private static function writeServerCfgValue($serverDir, $key, $value) {
        $cfg = rtrim($serverDir, '/') . '/cstrike/server.cfg';
        $value = trim((string)$value);
        if ($value === '') {
            return true;
        }
        $line = $key . ' "' . str_replace('"', '', $value) . '"';
        $lines = is_file($cfg) ? file($cfg, FILE_IGNORE_NEW_LINES) : [];
        $found = false;
        foreach ($lines as $i => $existing) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s+/i', $existing)) {
                $lines[$i] = $line;
                $found = true;
            }
        }
        if (!$found) {
            $lines[] = $line;
        }
        return @file_put_contents($cfg, implode("\n", $lines) . "\n") !== false;
    }

    private static function updateEnvFile($id, array $values) {
        $envPath = self::getManagerRoot() . '/instances/' . $id . '.env';
        if (!is_file($envPath)) {
            return false;
        }
        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        $seen = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z0-9_]+)=/', $line, $m) && array_key_exists($m[1], $values)) {
                $lines[$i] = $m[1] . '="' . str_replace('"', '', (string)$values[$m[1]]) . '"';
                $seen[$m[1]] = true;
            }
        }
        foreach ($values as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '="' . str_replace('"', '', (string)$value) . '"';
            }
        }
        return @file_put_contents($envPath, implode("\n", $lines) . "\n") !== false;
    }

    private static function updateStartShValue($serverDir, $key, $value) {
        $start = rtrim($serverDir, '/') . '/start.sh';
        if (!is_file($start)) {
            return false;
        }
        $content = (string)file_get_contents($start);
        $value = str_replace('"', '', (string)$value);
        $content = preg_replace('/^' . preg_quote($key, '/') . '=".*"$/m', $key . '="' . $value . '"', $content, 1, $count);
        if (!$count) {
            $content .= "\n" . $key . '="' . $value . '"' . "\n";
        }
        return @file_put_contents($start, $content) !== false;
    }

    public static function createServerInstance($id, $port, $name, $slots = 12, $hostname = '') {
        $id = trim((string)$id);
        $name = trim((string)$name);
        $port = (int)$port;

        if (!self::validateInstanceId($id) || $id === 'main') {
            return ['success' => false, 'message' => 'Invalid server id. Use 2-32 chars: letters, numbers, dash or underscore.'];
        }
        if ($port < 1024 || $port > 65535) {
            return ['success' => false, 'message' => 'Invalid port. Use a value between 1024 and 65535.'];
        }
        $slots = (int)$slots;
        if ($slots < 1 || $slots > 32) {
            return ['success' => false, 'message' => 'Invalid slot count. Use 1-32.'];
        }
        if ($name === '') {
            $name = 'GameLand CS 1.6 ' . $id;
        }
        if (trim((string)$hostname) === '') {
            $hostname = $name;
        }

        $svgl = escapeshellarg(self::getSvglPath());
        $cmd = 'sudo -n /bin/bash ' . $svgl . ' add '
             . escapeshellarg($id) . ' '
             . escapeshellarg((string)$port) . ' '
             . escapeshellarg($name) . ' 2>&1';
        $lines = [];
        $exitCode = 1;
        @exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);

        $ok = $exitCode === 0 && str_contains($output, '[OK]');
        if ($ok) {
            $registry = self::readRegistry();
            $serverDir = '/opt/gameland/instances/' . $id;
            foreach ($registry['servers'] as &$entry) {
                if (($entry['id'] ?? '') === $id) {
                    $entry['name'] = $name;
                    $entry['port'] = $port;
                    $entry['rcon_password'] = $entry['rcon_password'] ?? 'GameLand@Rcon2026';
                    $serverDir = $entry['server_dir'] ?? $serverDir;
                    break;
                }
            }
            self::writeRegistry($registry);
            self::updateEnvFile($id, [
                'SERVER_PORT' => $port,
                'MAX_PLAYERS' => $slots,
                'MAP' => 'de_dust2',
            ]);
            self::writeServerCfgValue($serverDir, 'hostname', $hostname);
        }

        return [
            'success' => $ok,
            'message' => is_string($output) ? trim($output) : 'No output from SVGL.',
            'output' => trim((string)$output),
        ];
    }

    public static function removeServerInstance($id, $purge = false) {
        $id = trim((string)$id);
        if (!self::validateInstanceId($id) || $id === 'main') {
            return ['success' => false, 'message' => 'Invalid or protected server id.'];
        }

        $svgl = escapeshellarg(self::getSvglPath());
        $cmd = 'sudo -n /bin/bash ' . $svgl . ' remove ' . escapeshellarg($id)
             . ($purge ? ' --purge' : '')
             . ' 2>&1';
        $lines = [];
        $exitCode = 1;
        @exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);

        return [
            'success' => $exitCode === 0 && str_contains($output, '[OK]'),
            'message' => trim($output) !== '' ? trim($output) : 'No output from SVGL.',
            'output' => trim($output),
            'exit_code' => $exitCode,
        ];
    }

    public static function updateServerInstance($id, array $data) {
        $id = trim((string)$id);
        if (!self::validateInstanceId($id) && $id !== 'main') {
            return ['success' => false, 'message' => 'Invalid server id.'];
        }

        $name = trim((string)($data['name'] ?? ''));
        $hostname = trim((string)($data['hostname'] ?? ''));
        $port = (int)($data['port'] ?? 0);
        $slots = (int)($data['slots'] ?? 0);
        $map = trim((string)($data['map'] ?? ''));
        $rcon = trim((string)($data['rcon_password'] ?? ''));

        if ($port && ($port < 1024 || $port > 65535)) {
            return ['success' => false, 'message' => 'Invalid port.'];
        }
        if ($slots && ($slots < 1 || $slots > 32)) {
            return ['success' => false, 'message' => 'Invalid slot count.'];
        }
        if ($map !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $map)) {
            return ['success' => false, 'message' => 'Invalid map name.'];
        }

        global $SERVERS;
        if (!isset($SERVERS[$id])) {
            return ['success' => false, 'message' => 'Server not found in panel registry.'];
        }

        $server = $SERVERS[$id];
        if ($port) {
            foreach ($SERVERS as $otherId => $otherServer) {
                if ($otherId !== $id && (int)($otherServer['port'] ?? 0) === $port) {
                    return ['success' => false, 'message' => 'Port is already assigned to another server.'];
                }
            }
        }
        $serverDir = $server['server_dir'];

        if ($hostname !== '') {
            self::writeServerCfgValue($serverDir, 'hostname', $hostname);
        }
        if ($rcon !== '') {
            self::writeServerCfgValue($serverDir, 'rcon_password', $rcon);
        }

        if ($id === 'main') {
            if ($slots) {
                self::updateStartShValue($serverDir, 'MAX_PLAYERS', $slots);
            }
            if ($port) {
                self::updateStartShValue($serverDir, 'SERVER_PORT', $port);
            }
            if ($map !== '') {
                self::updateStartShValue($serverDir, 'MAP', $map);
            }
        } else {
            $env = [];
            if ($slots) $env['MAX_PLAYERS'] = $slots;
            if ($port) $env['SERVER_PORT'] = $port;
            if ($map !== '') $env['MAP'] = $map;
            if (!empty($env)) {
                self::updateEnvFile($id, $env);
            }

            $registry = self::readRegistry();
            foreach ($registry['servers'] as &$entry) {
                if (($entry['id'] ?? '') === $id) {
                    if ($name !== '') $entry['name'] = $name;
                    if ($port) $entry['port'] = $port;
                    if ($rcon !== '') $entry['rcon_password'] = $rcon;
                    break;
                }
            }
            self::writeRegistry($registry);
        }

        return [
            'success' => true,
            'message' => 'Server settings saved. Restart the server to apply port, map and slot changes.',
        ];
    }

    /**
     * Single source of truth for plugins managed by the panel.
     * Keep this list aligned with install_mix.sh and plugins.ini.
     */
    public static function getManagedPluginTargets() {
        return [
            'mix_system.sma'            => 'mix_system.amxx',
            'mix_system_voice_chat.sma' => 'mix_system_voice_chat.amxx',
            'gameland_admin_tools.sma'  => 'gameland_admin_tools.amxx',
        ];
    }

    public static function getPluginBuildStatus($serverInfo) {
        $scriptingDir = $serverInfo['cstrike_dir'] . '/addons/amxmodx/scripting';
        $pluginsDir   = $serverInfo['cstrike_dir'] . '/addons/amxmodx/plugins';
        $pluginsIni   = $serverInfo['cstrike_dir'] . '/addons/amxmodx/configs/plugins.ini';
        $registeredText = file_exists($pluginsIni) ? (string)file_get_contents($pluginsIni) : '';
        $status = [];

        foreach (self::getManagedPluginTargets() as $src => $bin) {
            $srcPath = $scriptingDir . '/' . $src;
            $binPath = $pluginsDir . '/' . $bin;
            $srcExists = is_file($srcPath);
            $binExists = is_file($binPath) && filesize($binPath) > 100;
            $srcMtime = $srcExists ? filemtime($srcPath) : 0;
            $binMtime = $binExists ? filemtime($binPath) : 0;
            $registered = (bool)preg_match(
                '/^[ \t]*(?!;)[ \t]*' . preg_quote($bin, '/') . '(?:[ \t]|$)/mi',
                $registeredText
            );
            $status[$src] = [
                'source' => $src,
                'binary' => $bin,
                'source_exists' => $srcExists,
                'binary_exists' => $binExists,
                'registered' => $registered,
                'needs_rebuild' => $srcExists && (!$binExists || $binMtime < $srcMtime),
                'source_mtime' => $srcMtime ? date('Y-m-d H:i:s', $srcMtime) : null,
                'binary_mtime' => $binMtime ? date('Y-m-d H:i:s', $binMtime) : null,
                'source_hash' => $srcExists ? hash_file('sha256', $srcPath) : null,
                'binary_hash' => $binExists ? hash_file('sha256', $binPath) : null,
            ];
        }
        return $status;
    }

    /**
     * Get systemctl service status
     */
    public static function getServiceStatus($serviceName) {
        $cleanService = escapeshellarg($serviceName);
        $output = @shell_exec("systemctl is-active {$cleanService} 2>&1");
        $status = trim((string)$output);
        return ($status === 'active') ? 'running' : 'stopped';
    }

    /**
     * Execute systemctl start/stop/restart via sudo
     */
    public static function controlService($serviceName, $action) {
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return ['success' => false, 'message' => 'Invalid action'];
        }

        $cleanService = escapeshellarg($serviceName);
        $cleanAction = escapeshellarg($action);
        
        $lines = [];
        $exitCode = 1;
        @exec("sudo -n /usr/bin/systemctl {$cleanAction} {$cleanService} 2>&1", $lines, $exitCode);
        $output = implode("\n", $lines);
        sleep(1);
        $newStatus = self::getServiceStatus($serviceName);
        return [
            'success' => $exitCode === 0,
            'action' => $action,
            'status' => $newStatus,
            'output' => trim($output),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Read the last N lines of the server log file
     */
    public static function getLogLines($logPath, $lines = 80) {
        if (!file_exists($logPath)) {
            return "Log file not found at: {$logPath}\nMake sure the server has been launched at least once.";
        }
        $cleanPath = escapeshellarg($logPath);
        $cleanLines = (int)$lines;
        $output = @shell_exec("tail -n {$cleanLines} {$cleanPath} 2>&1");
        return $output ? $output : "No logs available.";
    }

    /**
     * List all available .bsp maps in cstrike/maps and maps.ini
     */
    public static function getAvailableMaps($serverInfo) {
        $maps = [];
        $mapsDir = $serverInfo['cstrike_dir'] . '/maps';
        if (is_dir($mapsDir)) {
            $files = scandir($mapsDir);
            foreach ($files as $file) {
                if (substr($file, -4) === '.bsp') {
                    $maps[] = substr($file, 0, -4);
                }
            }
        }

        // Also check maps.ini if exists
        $mapsIni = $serverInfo['maps_ini'];
        if (file_exists($mapsIni)) {
            $iniContent = file($mapsIni, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($iniContent as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== ';' && $line[0] !== '/') {
                    $parts = preg_split('/\s+/', $line);
                    $mapName = trim($parts[0]);
                    if (!in_array($mapName, $maps, true)) {
                        $maps[] = $mapName;
                    }
                }
            }
        }

        sort($maps);
        return $maps;
    }

    /**
     * Parse AMX Mod X users.ini
     * Format: "identity" "password" "flags" "connection_flags" ; comment
     */
    public static function getAdmins($usersIniPath) {
        $admins = [];
        if (!file_exists($usersIniPath)) {
            return $admins;
        }

        $lines = file($usersIniPath, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === ';' || substr($trimmed, 0, 2) === '//') {
                continue;
            }

            // Match: "auth" "password" "access" "flags"
            if (preg_match('/^"([^"]+)"\s+"([^"]*)"\s+"([^"]+)"\s+"([^"]+)"(?:\s*;?\s*(.*))?$/', $trimmed, $m)) {
                $admins[] = [
                    'line_num' => $index,
                    'auth'     => $m[1],
                    'password' => $m[2],
                    'access'   => $m[3],
                    'flags'    => $m[4],
                    'comment'  => isset($m[5]) ? trim($m[5]) : ''
                ];
            }
        }
        return $admins;
    }

    /**
     * Add or edit an admin in users.ini
     */
    public static function saveAdmin($usersIniPath, $auth, $password, $access, $flags, $comment = '') {
        $auth = trim($auth);
        $password = trim($password);
        $access = trim($access);
        $flags = trim($flags);
        $comment = trim($comment);

        if (empty($auth) || empty($access) || empty($flags)) {
            return ['success' => false, 'message' => 'Auth, Access, and Flags are required.'];
        }

        $lineStr = sprintf('"%s" "%s" "%s" "%s"', $auth, $password, $access, $flags);
        if ($comment !== '') {
            $lineStr .= ' ; ' . $comment;
        }

        // Check if admin already exists to replace, or append
        $lines = file_exists($usersIniPath) ? file($usersIniPath, FILE_IGNORE_NEW_LINES) : [];
        $found = false;

        foreach ($lines as $idx => $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === ';' || substr($t, 0, 2) === '//') {
                continue;
            }
            if (preg_match('/^"([^"]+)"/', $t, $m)) {
                if ($m[1] === $auth) {
                    $lines[$idx] = $lineStr;
                    $found = true;
                    break;
                }
            }
        }

        if (!$found) {
            $lines[] = $lineStr;
        }

        $res = @file_put_contents($usersIniPath, implode("\n", $lines) . "\n");
        if ($res === false) {
            return ['success' => false, 'message' => 'Unable to write to users.ini. Check file permissions.'];
        }

        return ['success' => true, 'message' => $found ? 'Admin updated successfully.' : 'Admin added successfully.'];
    }

    /**
     * Delete an admin from users.ini
     */
    public static function deleteAdmin($usersIniPath, $auth) {
        if (!file_exists($usersIniPath)) {
            return ['success' => false, 'message' => 'users.ini not found.'];
        }

        $lines = file($usersIniPath, FILE_IGNORE_NEW_LINES);
        $newLines = [];
        $deleted = false;

        foreach ($lines as $line) {
            $t = trim($line);
            if (preg_match('/^"([^"]+)"/', $t, $m)) {
                if ($m[1] === $auth) {
                    $deleted = true;
                    continue; // skip this line
                }
            }
            $newLines[] = $line;
        }

        if (!$deleted) {
            return ['success' => false, 'message' => 'Admin not found.'];
        }

        @file_put_contents($usersIniPath, implode("\n", $newLines) . "\n");
        return ['success' => true, 'message' => 'Admin removed successfully.'];
    }

    /**
     * Send live commands to server to reload admins and refresh connected players' rights
     */
    public static function reloadAdminsLive($serverInfo) {
        try {
            $rcon = new GoldSourceRcon(
                $serverInfo['ip'],
                $serverInfo['port'],
                $serverInfo['rcon_password'],
                1.5
            );
            // In AMX Mod X the command is amx_reloadadmins (with an 's')
            $r1 = $rcon->execute('amx_reloadadmins');
            return [
                'success' => true,
                'output' => trim($r1),
                'message' => 'Admins reloaded live on server instantly!'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'RCON error while reloading admins: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all plugins registered in plugins.ini, plus unlisted plugins in plugins/ dir
     */
    public static function getPluginsList($serverInfo) {
        $pluginsIni = $serverInfo['cstrike_dir'] . '/addons/amxmodx/configs/plugins.ini';
        $pluginsDir = $serverInfo['cstrike_dir'] . '/addons/amxmodx/plugins';
        
        $plugins = [];
        $registered = [];

        if (file_exists($pluginsIni)) {
            $lines = file($pluginsIni, FILE_IGNORE_NEW_LINES);
            $currentSection = 'General';

            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') continue;

                // Section header comment (e.g. "; Menus")
                if ($trimmed[0] === ';' && !preg_match('/;\s*([a-zA-Z0-9_\-]+\.amxx)/', $trimmed)) {
                    $cleanSection = trim(ltrim($trimmed, ';- '));
                    if (!empty($cleanSection)) {
                        $currentSection = $cleanSection;
                    }
                    continue;
                }

                // Check if line is a plugin (enabled or disabled)
                $isEnabled = ($trimmed[0] !== ';');
                $lineClean = ltrim($trimmed, '; ');

                // Match plugin filename and optional comment
                if (preg_match('/^([a-zA-Z0-9_\-]+\.amxx)(?:\s*;?\s*(.*))?/', $lineClean, $m)) {
                    $pluginFile = $m[1];
                    $comment = isset($m[2]) ? trim($m[2]) : '';
                    $isMixPlugin = str_contains($pluginFile, 'mix_');

                    $plugins[] = [
                        'file'       => $pluginFile,
                        'enabled'    => $isEnabled,
                        'section'    => $isMixPlugin ? '5v5 Mix System' : $currentSection,
                        'comment'    => $comment,
                        'is_mix'     => $isMixPlugin,
                        'installed'  => file_exists($pluginsDir . '/' . $pluginFile),
                    ];
                    $registered[$pluginFile] = true;
                }
            }
        }

        // Also check if there are .amxx files in plugins/ that are not yet in plugins.ini
        if (is_dir($pluginsDir)) {
            $diskFiles = scandir($pluginsDir);
            foreach ($diskFiles as $f) {
                if (substr($f, -5) === '.amxx' && !isset($registered[$f])) {
                    $isMix = str_contains($f, 'mix_');
                    $plugins[] = [
                        'file'       => $f,
                        'enabled'    => false,
                        'section'    => $isMix ? '5v5 Mix System' : 'Custom / Unregistered',
                        'comment'    => 'Found in plugins folder',
                        'is_mix'     => $isMix,
                        'installed'  => true,
                    ];
                }
            }
        }

        return $plugins;
    }

    /**
     * Save updated enabled/disabled state of plugins back to plugins.ini
     */
    public static function savePluginsState($serverInfo, array $enabledPluginFiles) {
        $pluginsIni = $serverInfo['cstrike_dir'] . '/addons/amxmodx/configs/plugins.ini';
        if (!file_exists($pluginsIni)) {
            return ['success' => false, 'message' => 'plugins.ini not found.'];
        }

        $lines = file($pluginsIni, FILE_IGNORE_NEW_LINES);
        $newLines = [];
        $handled = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || ($trimmed[0] === ';' && !preg_match('/;\s*([a-zA-Z0-9_\-]+\.amxx)/', $trimmed))) {
                $newLines[] = $line;
                continue;
            }

            $lineClean = ltrim($trimmed, '; ');
            if (preg_match('/^([a-zA-Z0-9_\-]+\.amxx)(.*)$/', $lineClean, $m)) {
                $pName = $m[1];
                $rest = $m[2];
                $shouldEnable = in_array($pName, $enabledPluginFiles, true);

                if ($shouldEnable) {
                    $newLines[] = $pName . $rest;
                } else {
                    $newLines[] = ';' . $pName . $rest;
                }
                $handled[$pName] = true;
            } else {
                $newLines[] = $line;
            }
        }

        // Add any newly enabled plugins that were not originally in plugins.ini
        foreach ($enabledPluginFiles as $pName) {
            if (!isset($handled[$pName])) {
                $newLines[] = $pName;
            }
        }

        $res = @file_put_contents($pluginsIni, implode("\n", $newLines) . "\n");
        if ($res === false) {
            return ['success' => false, 'message' => 'Failed to write plugins.ini. Check file permissions.'];
        }

        return ['success' => true, 'message' => 'Plugins configuration updated successfully.'];
    }

    /**
     * Quick Switch Mode: 'mix5v5' or 'public'
     */
    public static function switchServerMode($serverInfo, $targetMode) {
        $mixPlugins = array_values(self::getManagedPluginTargets());
        $currentPlugins = self::getPluginsList($serverInfo);
        $enabled = [];

        foreach ($currentPlugins as $p) {
            if ($p['enabled']) {
                $enabled[] = $p['file'];
            }
        }

        $startSh = $serverInfo['server_dir'] . '/start.sh';

        if ($targetMode === 'mix5v5') {
            // Enable mix plugins
            foreach ($mixPlugins as $mp) {
                if (!in_array($mp, $enabled, true)) {
                    $enabled[] = $mp;
                }
            }
            // Set 12 players in start.sh
            if (file_exists($startSh)) {
                $content = file_get_contents($startSh);
                $content = preg_replace('/MAX_PLAYERS=".*"/', 'MAX_PLAYERS="12"', $content);
                file_put_contents($startSh, $content);
            }
        } elseif ($targetMode === 'public') {
            // Disable mix plugins
            $enabled = array_filter($enabled, fn($p) => !in_array($p, $mixPlugins, true));
            // Set 24 or 32 players in start.sh
            if (file_exists($startSh)) {
                $content = file_get_contents($startSh);
                $content = preg_replace('/MAX_PLAYERS=".*"/', 'MAX_PLAYERS="24"', $content);
                file_put_contents($startSh, $content);
            }
        } else {
            return ['success' => false, 'message' => 'Unknown mode: ' . $targetMode];
        }

        $res = self::savePluginsState($serverInfo, $enabled);
        if (!$res['success']) {
            return $res;
        }

        return [
            'success' => true,
            'message' => 'Switched to ' . ($targetMode === 'mix5v5' ? '5v5 Mix Mode (12 Slots)' : 'Public Mode (24 Slots)') . '. Restart server to apply.'
        ];
    }

    /**
     * Build a GitHub API HTTP context with optional auth token
     */
    private static function githubHttpContext($timeout = 8) {
        $token = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';
        $headers = "User-Agent: GameLand-WebPanel/2.0\r\n"
                 . "Accept: application/vnd.github.v3+json\r\n";
        if (!empty($token)) {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        return stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $headers,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]
        ]);
    }

    /**
     * Build a GitHub raw-content HTTP context (no JSON accept header needed)
     */
    private static function githubRawContext($timeout = 10) {
        $token = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';
        $headers = "User-Agent: GameLand-WebPanel/2.0\r\n";
        if (!empty($token)) {
            $headers .= "Authorization: Bearer {$token}\r\n";
        }
        return stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $headers,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]
        ]);
    }

    /**
     * Get the latest commit information from the GitHub repository
     */
    public static function getGitRepoStatus($repo = null, $branch = null) {
        $repo   = $repo   ?? (defined('GITHUB_REPO')   ? GITHUB_REPO   : 'GAMELAND-PROJECT/MixSystem_SV_PL');
        $branch = $branch ?? (defined('GITHUB_BRANCH') ? GITHUB_BRANCH : 'main');

        $url  = "https://api.github.com/repos/{$repo}/commits/{$branch}";
        $ctx  = self::githubHttpContext(6);
        $res  = @file_get_contents($url, false, $ctx);
        if ($res === false) return null;

        $data = json_decode($res, true);
        if (!$data || !isset($data['sha'])) return null;

        return [
            'sha'      => substr($data['sha'], 0, 7),
            'full_sha' => $data['sha'],
            'message'  => $data['commit']['message'] ?? '',
            'author'   => $data['commit']['author']['name'] ?? '',
            'date'     => $data['commit']['author']['date'] ?? '',
        ];
    }

    /**
     * Download the latest source files from GitHub repo and update server files
     * Downloads .sma sources + configs + lang files.
     * Does NOT download .amxx (they are gitignored and must be compiled).
     */
    public static function syncMixFromGitHub($serverInfo) {
        $repo    = defined('GITHUB_REPO')   ? GITHUB_REPO   : 'GAMELAND-PROJECT/MixSystem_SV_PL';
        $branch  = defined('GITHUB_BRANCH') ? GITHUB_BRANCH : 'main';
        $baseUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/";
        $cstrike = $serverInfo['cstrike_dir'];

        // File mapping: GitHub relative path => local destination relative to cstrike
        $fileMap = [
            'scripting/include/mix_system.inc'    => 'addons/amxmodx/scripting/include/mix_system.inc',
            'scripting/mix_system.sma'            => 'addons/amxmodx/scripting/mix_system.sma',
            'scripting/mix_system_voice_chat.sma' => 'addons/amxmodx/scripting/mix_system_voice_chat.sma',
            'scripting/gameland_admin_tools.sma'  => 'addons/amxmodx/scripting/gameland_admin_tools.sma',
            'configs/MixSettings.ini'             => 'addons/amxmodx/configs/MixSettings.ini',
            'configs/start.cfg'                   => 'addons/amxmodx/configs/start.cfg',
            'configs/stop.cfg'                    => 'addons/amxmodx/configs/stop.cfg',
            'configs/overtime.cfg'                => 'addons/amxmodx/configs/overtime.cfg',
            'data/lang/mix_system.txt'            => 'addons/amxmodx/data/lang/mix_system.txt',
        ];

        $updatedFiles = [];
        $failedFiles  = [];
        $context      = self::githubRawContext(12);

        foreach ($fileMap as $remotePath => $localRelPath) {
            $destFile = $cstrike . '/' . $localRelPath;
            $destDir  = dirname($destFile);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }

            $url     = $baseUrl . $remotePath;
            $content = @file_get_contents($url, false, $context);

            if ($content !== false && strlen($content) > 10) {
                if (@file_put_contents($destFile, $content) !== false) {
                    $updatedFiles[] = basename($destFile);
                } else {
                    $failedFiles[] = basename($destFile) . ' (write error — check permissions)';
                }
            } else {
                $failedFiles[] = basename($destFile) . ' (download failed from ' . $url . ')';
            }
        }

        // Save last sync metadata (commit SHA + time + file list)
        $commit = self::getGitRepoStatus();
        $syncMeta = [
            'commit'    => $commit,
            'sync_time' => date('Y-m-d H:i:s'),
            'files'     => $updatedFiles,
            'failed'    => $failedFiles,
        ];
        @file_put_contents(
            $cstrike . '/addons/amxmodx/configs/.mix_last_sync.json',
            json_encode($syncMeta, JSON_PRETTY_PRINT)
        );

        return [
            'success' => count($updatedFiles) > 0 && count($failedFiles) === 0,
            'updated' => $updatedFiles,
            'failed'  => $failedFiles,
            'commit'  => $commit,
        ];
    }

    /**
     * Compile plugins using the server's amxxpc compiler
     */
    public static function compilePlugins($serverInfo) {
        $scriptingDir = $serverInfo['cstrike_dir'] . '/addons/amxmodx/scripting';
        $pluginsDir   = $serverInfo['cstrike_dir'] . '/addons/amxmodx/plugins';

        if (!is_dir($scriptingDir)) {
            return ['success' => false, 'message' => 'Scripting directory not found: ' . $scriptingDir, 'output' => '', 'compiled_count' => 0];
        }

        $compiler = $scriptingDir . '/amxxpc';

        // Ensure compiler is executable
        if (file_exists($compiler)) {
            @chmod($compiler, 0755);
        } else {
            return [
                'success'        => false,
                'compiled_count' => 0,
                'output'         => '',
                'message'        => 'Compiler not found: ' . $compiler . ". Make sure amxxpc binary exists in the scripting/ directory."
            ];
        }

        // Also make shared library executable
        foreach (['amxxpc32.so', 'amxxpc64.so'] as $lib) {
            $libPath = $scriptingDir . '/' . $lib;
            if (file_exists($libPath)) @chmod($libPath, 0755);
        }

        $targets = self::getManagedPluginTargets();

        $output       = '';
        $compiledCount = 0;
        $errorCount    = 0;

        foreach ($targets as $src => $bin) {
            $srcPath = $scriptingDir . '/' . $src;
            $binPath = $pluginsDir   . '/' . $bin;

            if (!file_exists($srcPath)) {
                $output .= "=== SKIP {$src} === (source file not found)\n\n";
                continue;
            }

            // Build command: cd into scripting dir so includes resolve correctly
            // IMPORTANT: -i"include" is required for reapi.inc and mix_system.inc to be found
            $includeDir = escapeshellarg($scriptingDir . '/include');
            $cmd = "cd " . escapeshellarg($scriptingDir)
                 . " && ./amxxpc " . escapeshellarg($src)
                 . " -o" . escapeshellarg($binPath)
                 . " -i\"include\""
                 . " -i" . $includeDir
                 . " 2>&1";

            $exitCode = 0;
            $lines = [];
            @exec($cmd, $lines, $exitCode);
            $res = implode("\n", $lines);
            $output .= "=== Compiling {$src} (exit {$exitCode}) ===\n" . trim($res) . "\n\n";

            // Verify the .amxx was actually created and is non-zero
            if ($exitCode === 0 && file_exists($binPath) && filesize($binPath) > 100) {
                $compiledCount++;
            } else {
                $errorCount++;
            }
        }

        $mainOk = file_exists($pluginsDir . '/mix_system.amxx')
               && filesize($pluginsDir . '/mix_system.amxx') > 100;

        // If main plugin compiled OK, register it in plugins.ini
        if ($mainOk) {
            self::ensureMixRegistered($serverInfo);
        }

        // Persist an authoritative deployment record used by the panel.
        $metaPath = $serverInfo['cstrike_dir'] . '/addons/amxmodx/configs/.mix_last_build.json';
        $buildMeta = [
            'build_time' => date('c'),
            'commit' => self::getGitRepoStatus(),
            'success' => $errorCount === 0 && $compiledCount > 0,
            'compiled_count' => $compiledCount,
            'targets' => self::getPluginBuildStatus($serverInfo),
        ];
        @file_put_contents($metaPath, json_encode($buildMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($compiledCount === 0 && $errorCount === 0) {
            $message = 'No .sma source files found to compile.';
        } elseif ($mainOk) {
            $message = "Compiled {$compiledCount} mix plugin(s) successfully!" . ($errorCount > 0 ? " ({$errorCount} had errors — check log)" : '');
        } else {
            $message = "Compilation failed. mix_system.amxx was not produced. Check compiler output below.";
        }

        return [
            'success'        => $errorCount === 0 && $compiledCount > 0,
            'output'         => trim($output),
            'compiled_count' => $compiledCount,
            'message'        => $message
        ];
    }

    /**
     * Ensure mix plugins are listed and ENABLED in plugins.ini
     */
    public static function ensureMixRegistered($serverInfo) {
        $pluginsIni = $serverInfo['cstrike_dir'] . '/addons/amxmodx/configs/plugins.ini';
        $pluginsDir = $serverInfo['cstrike_dir'] . '/addons/amxmodx/plugins';

        if (!file_exists($pluginsIni)) {
            return;
        }

        // Only register files that actually exist as binaries
        $mixFiles = [];
        $candidates = [
            'mix_system.amxx'            => 'AutoMix 5v5 System (main)',
            'mix_system_voice_chat.amxx' => 'AutoMix Voice Chat',
            'gameland_admin_tools.amxx'  => 'GameLand admin tools (/map, /j0-/j2, /ff0-/ff1)',
        ];
        foreach ($candidates as $f => $desc) {
            if (file_exists($pluginsDir . '/' . $f)) {
                $mixFiles[$f] = $desc;
            }
        }

        if (empty($mixFiles)) return;

        $content  = file_get_contents($pluginsIni);
        $modified = false;

        foreach ($mixFiles as $mf => $desc) {
            // If it exists but is commented out → uncomment it
            if (preg_match('/^\s*;+\s*' . preg_quote($mf, '/') . '/m', $content)) {
                $content  = preg_replace('/^\s*;+\s*(' . preg_quote($mf, '/') . '.*)$/m', '$1', $content);
                $modified = true;
            } elseif (!preg_match('/^\s*' . preg_quote($mf, '/') . '/m', $content)) {
                // Not present at all → add it
                // Ensure we have a section header
                if (!str_contains($content, '; 5v5 AutoMix System')) {
                    $content .= "\n; 5v5 AutoMix System\n";
                }
                $content .= $mf . " ; " . $desc . "\n";
                $modified = true;
            }
        }

        if ($modified) {
            @file_put_contents($pluginsIni, $content);
        }
    }
}
