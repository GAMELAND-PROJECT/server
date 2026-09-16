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
     * Get the latest commit information from the GitHub repository
     */
    public static function getGitRepoStatus($repo = 'GAMELAND-PROJECT/MixSystem_SV_PL', $branch = 'main') {
        $url = "https://api.github.com/repos/{$repo}/commits/{$branch}";
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: GameLand-WebPanel/2.0\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 5
            ]
        ];
        $context = stream_context_create($opts);
        $res = @file_get_contents($url, false, $context);
        if ($res === false) {
            return null;
        }

        $data = json_decode($res, true);
        if (!$data || !isset($data['sha'])) {
            return null;
        }

        return [
            'sha'        => substr($data['sha'], 0, 7),
            'full_sha'   => $data['sha'],
            'message'    => $data['commit']['message'] ?? '',
            'author'     => $data['commit']['author']['name'] ?? '',
            'date'       => $data['commit']['author']['date'] ?? '',
        ];
    }

    /**
     * Download the latest files from GitHub repo and update server files
     */
    public static function syncMixFromGitHub($serverInfo) {
        $baseUrl = 'https://raw.githubusercontent.com/GAMELAND-PROJECT/MixSystem_SV_PL/main/';
        $cstrike = $serverInfo['cstrike_dir'];

        // File mapping: GitHub relative path => local destination relative to cstrike
        $fileMap = [
            'scripting/include/mix_system.inc'    => 'addons/amxmodx/scripting/include/mix_system.inc',
            'scripting/mix_system.sma'            => 'addons/amxmodx/scripting/mix_system.sma',
            'scripting/mix_system_voice_chat.sma' => 'addons/amxmodx/scripting/mix_system_voice_chat.sma',
            'scripting/player_drop.sma'           => 'addons/amxmodx/scripting/player_drop.sma',
            'scripting/mix_database_stats.sma'    => 'addons/amxmodx/scripting/mix_database_stats.sma',
            'configs/MixSettings.ini'             => 'addons/amxmodx/configs/MixSettings.ini',
            'configs/start.cfg'                   => 'addons/amxmodx/configs/start.cfg',
            'configs/stop.cfg'                    => 'addons/amxmodx/configs/stop.cfg',
            'configs/overtime.cfg'                => 'addons/amxmodx/configs/overtime.cfg',
            'data/lang/mix_system.txt'            => 'addons/amxmodx/data/lang/mix_system.txt',
        ];

        $updatedFiles = [];
        $failedFiles = [];

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: GameLand-WebPanel/2.0\r\n",
                'timeout' => 10
            ]
        ];
        $context = stream_context_create($opts);

        foreach ($fileMap as $remotePath => $localRelPath) {
            $destFile = $cstrike . '/' . $localRelPath;
            $destDir = dirname($destFile);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0775, true);
            }

            $content = @file_get_contents($baseUrl . $remotePath, false, $context);
            if ($content !== false && strlen($content) > 0) {
                if (@file_put_contents($destFile, $content) !== false) {
                    $updatedFiles[] = basename($destFile);
                } else {
                    $failedFiles[] = basename($destFile) . ' (write permission error)';
                }
            } else {
                $failedFiles[] = basename($destFile) . ' (download failed)';
            }
        }

        // Save last sync metadata
        $commit = self::getGitRepoStatus();
        if ($commit) {
            @file_put_contents($cstrike . '/addons/amxmodx/configs/.mix_last_sync.json', json_encode([
                'commit' => $commit,
                'sync_time' => date('Y-m-d H:i:s'),
                'files' => $updatedFiles
            ], JSON_PRETTY_PRINT));
        }

        return [
            'success' => count($updatedFiles) > 0,
            'updated' => $updatedFiles,
            'failed'  => $failedFiles,
            'commit'  => $commit
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

        $targets = [
            'mix_system.sma'            => 'mix_system.amxx',
            'mix_system_voice_chat.sma' => 'mix_system_voice_chat.amxx',
            'player_drop.sma'           => 'player_drop.amxx',
            'mix_database_stats.sma'    => 'mix_database_stats.amxx',
        ];

        $output = '';
        $compiledCount = 0;

        foreach ($targets as $src => $bin) {
            $srcPath = $scriptingDir . '/' . $src;
            if (file_exists($srcPath)) {
                $cmd = "cd " . escapeshellarg($scriptingDir) . " && ./amxxpc " . escapeshellarg($src) . " -o" . escapeshellarg($pluginsDir . '/' . $bin) . " 2>&1";
                $res = @shell_exec($cmd);
                $output .= "=== Compiling {$src} ===\n" . trim((string)$res) . "\n\n";

                if (file_exists($pluginsDir . '/' . $bin)) {
                    $compiledCount++;
                }
            }
        }

        $mainOk = file_exists($pluginsDir . '/mix_system.amxx');

        // Ensure plugins are activated in plugins.ini if not present
        if ($mainOk) {
            self::ensureMixRegistered($serverInfo);
        }

        return [
            'success' => $mainOk,
            'output'  => trim($output),
            'compiled_count' => $compiledCount,
            'message' => $mainOk 
                ? "Successfully compiled {$compiledCount} mix plugin binaries (.amxx)!" 
                : 'Compilation finished with errors. Check the compiler log below.'
        ];
    }

    /**
     * Ensure mix plugins are listed and active in plugins.ini
     */
    public static function ensureMixRegistered($serverInfo) {
        $pluginsIni = $serverInfo['cstrike_dir'] . '/addons/amxmodx/configs/plugins.ini';
        if (!file_exists($pluginsIni)) {
            return;
        }

        $mixFiles = ['mix_system.amxx', 'mix_system_voice_chat.amxx', 'player_drop.amxx'];
        $content = file_get_contents($pluginsIni);
        $modified = false;

        foreach ($mixFiles as $mf) {
            if (preg_match('/^;\s*' . preg_quote($mf, '/') . '/m', $content)) {
                // Uncomment if commented out
                $content = preg_replace('/^;\s*' . preg_quote($mf, '/') . '(.*)$/m', $mf . '$1', $content);
                $modified = true;
            } elseif (!preg_match('/' . preg_quote($mf, '/') . '/', $content)) {
                // Add to plugins.ini
                $content .= "\n" . $mf . " ; AutoMix 5v5 System";
                $modified = true;
            }
        }

        if ($modified) {
            @file_put_contents($pluginsIni, $content);
        }
    }
}

