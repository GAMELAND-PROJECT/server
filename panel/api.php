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

    case 'service_control':
        $serviceAction = $_POST['service_action'] ?? '';
        $res = ServerCmd::controlService($activeServer['service_name'], $serviceAction);
        echo json_encode($res);
        break;

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

    case 'get_logs':
        $lines = (int)($_POST['lines'] ?? 60);
        $logs = ServerCmd::getLogLines($activeServer['log_file'], $lines);
        echo json_encode(['success' => true, 'logs' => $logs]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
