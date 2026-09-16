<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/header.php';

// Fetch live server info via A2S query
$rcon = new GoldSourceRcon($activeServer['ip'], $activeServer['port'], $activeServer['rcon_password'], 1.0);
$info = $rcon->getInfo();
?>

<!-- Server Title & Quick Power Controls -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; font-weight: 700;"><?php echo htmlspecialchars($activeServer['name']); ?></h1>
        <p style="color: var(--text-muted); font-size: 0.88rem; margin-top: 0.2rem;">
            IP: <?php echo htmlspecialchars($activeServer['ip'] . ':' . $activeServer['port']); ?> &bull;
            Service: <code><?php echo htmlspecialchars($activeServer['service_name']); ?></code>
        </p>
    </div>

    <div class="action-bar" style="margin-bottom: 0;">
        <button onclick="controlService('start')" class="btn btn-success" <?php echo ($serverStatus === 'running') ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
            ▶ Start
        </button>
        <button onclick="controlService('restart')" class="btn btn-warning" title="Restart CS 1.6 systemd service">
            ↻ Restart Service
        </button>
        <button onclick="controlService('stop')" class="btn btn-danger" <?php echo ($serverStatus !== 'running') ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
            ⏹ Stop
        </button>
        <a href="plugins.php" class="btn btn-primary" style="background: linear-gradient(135deg, #06b6d4, #4f46e5); font-weight: 600;" title="Download Latest Mix from GitHub, Compile & Restart">
            🔄 Update AutoMix
        </a>
        <button onclick="sendMixAction('restart_round')" class="btn btn-secondary" style="border-color: #3b82f6;" title="Restart Current Round (sv_restart 1)">
            🔄 Restart Round
        </button>
        <button onclick="sendMixAction('start')" class="btn btn-secondary" title="Start 5v5 Mix Match (/start)">
            ⚡ Start Mix
        </button>
        <button onclick="sendMixAction('knife')" class="btn btn-secondary" title="Start Knife Round (/knife)">
            🔪 Knife Round
        </button>
        <button onclick="sendMixAction('warm')" class="btn btn-secondary" title="Start WarmUp (/warm)">
            🔥 WarmUp
        </button>
        <button onclick="sendMixAction('pause')" class="btn btn-secondary" title="Pause/Unpause Match (/pause)">
            ⏸ Pause
        </button>
        <button onclick="sendMixAction('stop')" class="btn btn-danger" title="Stop Match & Reset (/stop)">
            🛑 Stop Mix
        </button>
    </div>
</div>

<!-- Stat Widgets -->
<div class="grid-cols-4">
    <div class="stat-widget">
        <div class="stat-label">Server Status</div>
        <div class="stat-value" style="display: flex; align-items: center; gap: 0.5rem;">
            <?php if ($serverStatus === 'running'): ?>
                <span class="status-pill status-running"><span class="status-dot"></span> RUNNING</span>
            <?php else: ?>
                <span class="status-pill status-stopped"><span class="status-dot"></span> STOPPED</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-label">Online Players</div>
        <div class="stat-value" style="color: var(--accent);">
            <?php echo $info ? "{$info['players']} / {$info['maxplayers']}" : '-- / --'; ?>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-label">Current Map</div>
        <div class="stat-value" style="color: var(--warning); font-size: 1.35rem;">
            <?php echo $info ? htmlspecialchars($info['map']) : 'N/A'; ?>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-label">Local Query Ping</div>
        <div class="stat-value" style="color: var(--success);">
            <?php echo $info ? "{$info['ping']} ms" : 'Offline'; ?>
        </div>
    </div>
</div>

<div class="grid-cols-2">
    <!-- Server Details & Fast Settings -->
    <div class="card">
        <div class="card-title">
            <span>Server Information</span>
            <button onclick="location.reload()" class="btn btn-secondary btn-sm">Refresh</button>
        </div>
        <table>
            <tr>
                <td style="color: var(--text-muted); width: 40%;">Hostname</td>
                <td><strong><?php echo $info ? htmlspecialchars($info['hostname']) : 'Server Offline'; ?></strong></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Connection Port</td>
                <td><code><?php echo $activeServer['port']; ?> / UDP</code></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Engine Version</td>
                <td>ReHLDS (GoldSource x86)</td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Server Directory</td>
                <td><code style="font-size: 0.8rem;"><?php echo htmlspecialchars($activeServer['server_dir']); ?></code></td>
            </tr>
            <tr>
                <td style="color: var(--text-muted);">Quick Map Switch</td>
                <td>
                    <button onclick="changeMap('de_dust2')" class="btn btn-secondary btn-sm">de_dust2</button>
                    <button onclick="changeMap('de_inferno')" class="btn btn-secondary btn-sm">de_inferno</button>
                    <button onclick="changeMap('de_train')" class="btn btn-secondary btn-sm">de_train</button>
                    <button onclick="changeMap('')" class="btn btn-primary btn-sm">Other...</button>
                </td>
            </tr>
        </table>
    </div>

    <!-- Live Recent Server Logs -->
    <div class="card">
        <div class="card-title">
            <span>Recent Server Logs</span>
            <a href="console.php" class="btn btn-secondary btn-sm">Open Live RCON</a>
        </div>
        <div class="console-box" style="height: 250px; font-size: 0.8rem;" id="dashboardLogBox">
            <?php echo htmlspecialchars(ServerCmd::getLogLines($activeServer['log_file'], 50)); ?>
        </div>
    </div>
</div>

<script>
    // Auto-scroll dashboard log box to bottom
    const logBox = document.getElementById('dashboardLogBox');
    if (logBox) logBox.scrollTop = logBox.scrollHeight;
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
