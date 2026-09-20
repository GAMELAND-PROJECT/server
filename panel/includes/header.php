<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Rcon.class.php';
require_once __DIR__ . '/ServerCmd.php';

Auth::check();
$activeServer = get_active_server();
$serverStatus = ServerCmd::getServiceStatus($activeServer['service_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?> - GameLand Web Panel</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.CSRF_TOKEN = "<?php echo Auth::csrf_token(); ?>";
    </script>
</head>
<body>

<nav class="navbar">
    <div style="display: flex; align-items: center; gap: 1.5rem;">
        <a href="dashboard.php" class="nav-brand">
            <span class="badge-gl">GAMELAND</span>
            <span>CS 1.6 Panel</span>
        </a>

        <!-- Multi-Server Switcher -->
        <select class="server-selector" onchange="switchServer(this.value)">
            <?php foreach ($SERVERS as $sId => $s): ?>
                <option value="<?php echo htmlspecialchars($sId); ?>" <?php echo ($sId === $activeServer['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($s['name']); ?> (Port: <?php echo $s['port']; ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="nav-menu">
        <a href="dashboard.php" class="nav-link <?php echo ($activeNav ?? '') === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
        <a href="servers.php" class="nav-link <?php echo ($activeNav ?? '') === 'servers' ? 'active' : ''; ?>">Servers</a>
        <a href="plugins.php" class="nav-link <?php echo ($activeNav ?? '') === 'plugins' ? 'active' : ''; ?>" style="color: var(--accent);">Plugins & Modes</a>
        <a href="console.php" class="nav-link <?php echo ($activeNav ?? '') === 'console' ? 'active' : ''; ?>">RCON Console</a>
        <a href="maps.php" class="nav-link <?php echo ($activeNav ?? '') === 'maps' ? 'active' : ''; ?>">Maps & Players</a>
        <a href="demos.php" class="nav-link <?php echo ($activeNav ?? '') === 'demos' ? 'active' : ''; ?>">HLTV Demos</a>
        <a href="admins.php" class="nav-link <?php echo ($activeNav ?? '') === 'admins' ? 'active' : ''; ?>">Admin Manager</a>
        <a href="logout.php" class="nav-link" style="color: var(--danger);">Logout</a>
    </div>
</nav>

<div class="container">
