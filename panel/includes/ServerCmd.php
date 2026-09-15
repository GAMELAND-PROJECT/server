<?php
require_once __DIR__ . '/../config.php';

class ServerCmd {

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
        
        $output = @shell_exec("sudo systemctl {$cleanAction} {$cleanService} 2>&1");
        sleep(1);
        $newStatus = self::getServiceStatus($serviceName);
        return [
            'success' => true,
            'action' => $action,
            'status' => $newStatus,
            'output' => trim((string)$output)
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
        $mixPlugins = ['mix_system.amxx', 'mix_system_voice_chat.amxx', 'player_drop.amxx'];
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
     * Compile plugins using the server's amxxpc compiler
     */
    public static function compilePlugins($serverInfo) {
        $scriptingDir = $serverInfo['cstrike_dir'] . '/addons/amxmodx/scripting';
        $pluginsDir = $serverInfo['cstrike_dir'] . '/addons/amxmodx/plugins';

        if (!is_dir($scriptingDir)) {
            return ['success' => false, 'message' => 'Scripting directory not found.'];
        }

        $compiler = $scriptingDir . '/amxxpc';
        @chmod($compiler, 0755);
        @chmod($scriptingDir . '/amxxpc32.so', 0755);

        $cmd = "cd " . escapeshellarg($scriptingDir) . " && ./amxxpc mix_system.sma -o" . escapeshellarg($pluginsDir . '/mix_system.amxx') . " 2>&1";
        $output = @shell_exec($cmd);

        if (file_exists($scriptingDir . '/mix_system_voice_chat.sma')) {
            $cmd2 = "cd " . escapeshellarg($scriptingDir) . " && ./amxxpc mix_system_voice_chat.sma -o" . escapeshellarg($pluginsDir . '/mix_system_voice_chat.amxx') . " 2>&1";
            $output .= "\n" . @shell_exec($cmd2);
        }

        $isOk = file_exists($pluginsDir . '/mix_system.amxx');
        return [
            'success' => $isOk,
            'output'  => trim((string)$output),
            'message' => $isOk ? 'Compilation completed successfully!' : 'Compilation failed or completed with errors.'
        ];
    }
}
