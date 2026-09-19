<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Rcon.class.php';
require_once __DIR__ . '/includes/ServerCmd.php';

// Verify session
if (!isset($_SESSION['gameland_logged_in']) || $_SESSION['gameland_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Verify CSRF
if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = $_POST['action'] ?? '';
$activeServer = get_active_server();

switch ($action) {

    // ─── RCON Raw Command (Console page) ────────────────────────────────────
    case 'rcon_command':
        $command = trim($_POST['command'] ?? '');
        if (empty($command)) {
            echo json_encode(['success' => false, 'message' => 'Command cannot be empty']);
            exit;
        }
        try {
            $rcon = new GoldSourceRcon(
                $activeServer['ip'],
                $activeServer['port'],
                $activeServer['rcon_password'],
                2.5
            );
            $response = $rcon->execute($command);
            echo json_encode(['success' => true, 'response' => $response]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ─── Service Control (start/stop/restart) ───────────────────────────────
    case 'service_control':
        $serviceAction = $_POST['service_action'] ?? '';
        $res = ServerCmd::controlService($activeServer['service_name'], $serviceAction);
        echo json_encode($res);
        break;

    // ─── Switch Active Server ────────────────────────────────────────────────
    case 'switch_server':
        $serverId = $_POST['server_id'] ?? '';
        global $SERVERS;
        if (isset($SERVERS[$serverId])) {
            $_SESSION['active_server_id'] = $serverId;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Unknown server']);
        }
        break;

    // ─── Get Server Logs ─────────────────────────────────────────────────────
    case 'get_logs':
        $lines = (int)($_POST['lines'] ?? 60);
        $logs = ServerCmd::getLogLines($activeServer['log_file'], $lines);
        echo json_encode(['success' => true, 'logs' => $logs]);
        break;

    // ─── GitHub Sync + Compile + Restart ─────────────────────────────────────
    case 'sync_mix':
        $compile = isset($_POST['compile']) && $_POST['compile'] == '1';
        $restart = isset($_POST['restart']) && $_POST['restart'] == '1';

        $syncRes = ServerCmd::syncMixFromGitHub($activeServer);
        if (!$syncRes['success']) {
            echo json_encode(['success' => false, 'message' => 'Failed to download files from GitHub. Check internet and file permissions.', 'details' => $syncRes]);
            exit;
        }

        $compileRes = null;
        if ($compile) {
            $compileRes = ServerCmd::compilePlugins($activeServer);
        }

        $restartRes = null;
        if ($restart) {
            $restartRes = ServerCmd::controlService($activeServer['service_name'], 'restart');
        }

        echo json_encode([
            'success'  => true,
            'sync'     => $syncRes,
            'compile'  => $compileRes,
            'restart'  => $restartRes,
            'message'  => 'GitHub sync and deploy finished!'
        ]);
        break;

    // ─── Compile Mix Plugins ──────────────────────────────────────────────────
    case 'compile_mix':
        $res = ServerCmd::compilePlugins($activeServer);
        echo json_encode($res);
        break;

    // ─── Mix In-Game Commands via RCON ────────────────────────────────────────
    // Supports: start, stop, knife, warm, pause, restart_round, stop_round
    case 'mix_command':
        $mixAction = trim($_POST['mix_action'] ?? '');

        $mixCmdMap = [
            'start'         => 'amx_mixa',          // start automix queue
            'stop'          => 'amx_mixstop',        // stop/reset match
            'knife'         => 'amx_knife',          // start knife round
            'warm'          => 'amx_warm',           // start warmup
            'pause'         => 'amx_pause',          // pause/unpause
            'restart_round' => 'sv_restart 1',       // restart current round
            'stop_round'    => 'amx_mixstop',        // stop match completely
            'endround'      => 'mp_roundtime 0.01',  // force end round quickly
        ];

        if (!isset($mixCmdMap[$mixAction])) {
            echo json_encode(['success' => false, 'message' => 'Unknown mix action: ' . htmlspecialchars($mixAction)]);
            exit;
        }

        $rconCmd = $mixCmdMap[$mixAction];

        try {
            $rcon = new GoldSourceRcon(
                $activeServer['ip'],
                $activeServer['port'],
                $activeServer['rcon_password'],
                2.5
            );
            $response = $rcon->execute($rconCmd);
            echo json_encode([
                'success'  => true,
                'action'   => $mixAction,
                'command'  => $rconCmd,
                'response' => trim((string)$response)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'RCON error: ' . $e->getMessage()
            ]);
        }
        break;

    // ─── Live Admin Reload via RCON ───────────────────────────────────────────
    case 'reload_admins':
        $res = ServerCmd::reloadAdminsLive($activeServer);
        echo json_encode($res);
        break;

    // ─── Get Plugin List (for plugins page live refresh) ─────────────────────
    case 'get_plugins':
        $plugins  = ServerCmd::getPluginsList($activeServer);
        $gitStatus = ServerCmd::getGitRepoStatus();
        echo json_encode([
            'success'   => true,
            'plugins'   => $plugins,
            'gitStatus' => $gitStatus
        ]);
        break;

    // ─── Toggle a Single Plugin (enable/disable in plugins.ini) ──────────────
    case 'toggle_plugin':
        $pluginFile = trim($_POST['plugin_file'] ?? '');
        $enable     = ($_POST['enable'] ?? '0') === '1';

        if (empty($pluginFile) || !preg_match('/^[a-zA-Z0-9_\-]+\.amxx$/', $pluginFile)) {
            echo json_encode(['success' => false, 'message' => 'Invalid plugin filename.']);
            exit;
        }

        // Get current plugin list, then toggle this one
        $allPlugins = ServerCmd::getPluginsList($activeServer);
        $enabled    = [];
        foreach ($allPlugins as $p) {
            if ($p['file'] === $pluginFile) {
                if ($enable) $enabled[] = $p['file'];
                // else skip (disable it)
            } elseif ($p['enabled']) {
                $enabled[] = $p['file'];
            }
        }

        $res = ServerCmd::savePluginsState($activeServer, $enabled);
        echo json_encode($res);
        break;

    // ─── Get GitHub Repo Status ───────────────────────────────────────────────
    case 'git_status':
        $status = ServerCmd::getGitRepoStatus();
        echo json_encode(['success' => true, 'status' => $status]);
        break;

    // ─── Get Compile Status (check if .amxx files exist and are newer) ────────
    case 'plugin_build_status':
        $managedStatus = ServerCmd::getPluginBuildStatus($activeServer);
        echo json_encode(['success' => true, 'builds' => array_values($managedStatus)]);
        break;






    // ─── Full Build Pipeline Diagnostic ──────────────────────────────────────
    case 'diagnose_build':
        $scriptingDir = $activeServer['cstrike_dir'] . '/addons/amxmodx/scripting';
        $pluginsDir   = $activeServer['cstrike_dir'] . '/addons/amxmodx/plugins';
        $includeDir   = $scriptingDir . '/include';
        $compiler     = $scriptingDir . '/amxxpc';

        $diag = [];

        // 1. Compiler binary
        $compilerExists = file_exists($compiler);
        $diag[] = ['check' => 'Compiler (amxxpc) exists', 'ok' => $compilerExists, 'detail' => $compiler];

        if ($compilerExists) {
            // Make executable
            @chmod($compiler, 0755);
            foreach (['amxxpc32.so', 'amxxpc64.so'] as $lib) {
                if (file_exists($scriptingDir.'/'.$lib)) @chmod($scriptingDir.'/'.$lib, 0755);
            }

            // Test compiler version
            $verOut = @shell_exec('cd ' . escapeshellarg($scriptingDir) . ' && ./amxxpc --version 2>&1');
            $diag[] = ['check' => 'Compiler --version test', 'ok' => !empty($verOut), 'detail' => trim((string)$verOut)];
        }

        // 2. Include directory
        $diag[] = ['check' => 'Include dir exists', 'ok' => is_dir($includeDir), 'detail' => $includeDir];

        // Key includes
        foreach (['reapi.inc', 'mix_system.inc', 'cstrike.inc', 'amxmodx.inc'] as $inc) {
            $incPath = $includeDir . '/' . $inc;
            // Also check default amxmodx include path
            $sysIncPath = $scriptingDir . '/../include/' . $inc;
            $found = file_exists($incPath) || file_exists($sysIncPath);
            $diag[] = [
                'check'  => "Include: {$inc}",
                'ok'     => $found,
                'detail' => $found ? ($incPath) : 'NOT FOUND in ' . $includeDir,
            ];
        }

        // 3. Managed source files
        foreach (array_keys(ServerCmd::getManagedPluginTargets()) as $sma) {
            $p = $scriptingDir . '/' . $sma;
            $exists = file_exists($p);
            $diag[] = [
                'check'  => "Source: {$sma}",
                'ok'     => $exists,
                'detail' => $exists ? date('Y-m-d H:i:s', filemtime($p)) . ' (' . number_format(filesize($p)) . ' bytes)' : 'MISSING',
            ];
        }

        // 4. Managed binary status
        foreach (array_values(ServerCmd::getManagedPluginTargets()) as $amxx) {
            $p = $pluginsDir . '/' . $amxx;
            $exists = file_exists($p) && filesize($p) > 100;
            $diag[] = [
                'check'  => "Binary: {$amxx}",
                'ok'     => $exists,
                'detail' => $exists ? date('Y-m-d H:i:s', filemtime($p)) . ' (' . number_format(filesize($p)) . ' bytes)' : 'MISSING or empty',
            ];
        }

        // 5. plugins.ini check
        $pluginsIni = $activeServer['cstrike_dir'] . '/addons/amxmodx/configs/plugins.ini';
        $iniContent = file_exists($pluginsIni) ? file_get_contents($pluginsIni) : '';
        foreach (array_values(ServerCmd::getManagedPluginTargets()) as $amxx) {
            $registered = (bool)preg_match('/^\s*(?!;)\s*' . preg_quote($amxx, '/') . '(?:\s|$)/mi', $iniContent);
            $diag[] = ['check' => "{$amxx} in plugins.ini (enabled)", 'ok' => $registered, 'detail' => $pluginsIni];
        }

        // 6. Try a real test compile of mix_system.sma
        $testOut = '';
        if ($compilerExists && file_exists($scriptingDir . '/mix_system.sma')) {
            $testBin  = $pluginsDir . '/mix_system.amxx';
            $incArg   = escapeshellarg($includeDir);
            $testCmd  = 'cd ' . escapeshellarg($scriptingDir)
                      . ' && ./amxxpc mix_system.sma'
                      . ' -o' . escapeshellarg($testBin)
                      . ' -i"include" -i' . $incArg
                      . ' 2>&1';
            $testOut  = @shell_exec($testCmd);
            $compiled = file_exists($testBin) && filesize($testBin) > 100;
            $diag[]   = [
                'check'  => 'LIVE TEST: Compile mix_system.sma',
                'ok'     => $compiled,
                'detail' => trim((string)$testOut),
            ];
        }

        echo json_encode(['success' => true, 'diagnostics' => $diag]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
