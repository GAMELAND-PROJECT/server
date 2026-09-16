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
        $scriptingDir = $activeServer['cstrike_dir'] . '/addons/amxmodx/scripting';
        $pluginsDir   = $activeServer['cstrike_dir'] . '/addons/amxmodx/plugins';

        $targets = [
            'mix_system.sma'            => 'mix_system.amxx',
            'mix_system_voice_chat.sma' => 'mix_system_voice_chat.amxx',
            'player_drop.sma'           => 'player_drop.amxx',
            'mix_database_stats.sma'    => 'mix_database_stats.amxx',
        ];

        $statusList = [];
        foreach ($targets as $src => $bin) {
            $srcPath = $scriptingDir . '/' . $src;
            $binPath = $pluginsDir . '/' . $bin;

            $srcExists  = file_exists($srcPath);
            $binExists  = file_exists($binPath);
            $srcMtime   = $srcExists ? filemtime($srcPath) : 0;
            $binMtime   = $binExists ? filemtime($binPath) : 0;
            $needsRebuild = $srcExists && ($binMtime < $srcMtime);

            $statusList[] = [
                'sma'          => $src,
                'amxx'         => $bin,
                'sma_exists'   => $srcExists,
                'amxx_exists'  => $binExists,
                'needs_rebuild'=> $needsRebuild,
                'sma_mtime'    => $srcExists  ? date('Y-m-d H:i:s', $srcMtime) : null,
                'amxx_mtime'   => $binExists  ? date('Y-m-d H:i:s', $binMtime) : null,
            ];
        }

        echo json_encode(['success' => true, 'builds' => $statusList]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
