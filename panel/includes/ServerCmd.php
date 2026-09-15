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
}
