<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/includes/header.php';

// Fetch live server info via A2S query
$rcon = new GoldSourceRcon($activeServer['ip'], $activeServer['port'], $activeServer['rcon_password'], 1.0);
$info = $rcon->getInfo();
?>

<!-- CSRF for JS AJAX -->
<script>window.CSRF_TOKEN = "<?php echo Auth::csrf_token(); ?>";</script>

<!-- Alert area for async actions -->
<div id="dashActionAlert" style="display:none; padding:0.7rem 1rem; border-radius:8px; margin-bottom:1rem; font-size:0.88rem;"></div>

<!-- ══ Server Title & Quick Power Controls ══════════════════════════════════ -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
    <div>
        <h1 style="font-size:1.6rem; font-weight:700;"><?php echo htmlspecialchars($activeServer['name']); ?></h1>
        <p style="color:var(--text-muted); font-size:0.88rem; margin-top:0.2rem;">
            IP: <?php echo htmlspecialchars($activeServer['ip'].':'.$activeServer['port']); ?> &bull;
            Service: <code><?php echo htmlspecialchars($activeServer['service_name']); ?></code>
        </p>
    </div>

    <!-- Quick Action Bar -->
    <div class="action-bar" style="margin-bottom:0; flex-wrap:wrap; gap:0.5rem;">
        <!-- Server Power -->
        <button onclick="controlService('start')" class="btn btn-success"
                <?php echo ($serverStatus==='running')?'disabled style="opacity:0.5;cursor:not-allowed;"':''; ?>>
            ▶ Start
        </button>
        <button onclick="controlService('restart')" class="btn btn-warning" title="Restart CS 1.6 systemd service">
            ↻ Restart
        </button>
        <button onclick="controlService('stop')" class="btn btn-danger"
                <?php echo ($serverStatus!=='running')?'disabled style="opacity:0.5;cursor:not-allowed;"':''; ?>>
            ⏹ Stop
        </button>

        <span style="width:1px; height:32px; background:var(--border-color); display:inline-block; vertical-align:middle; margin:0 4px;"></span>

        <!-- Mix in-game RCON commands -->
        <button onclick="sendMixAction('restart_round')" class="btn btn-secondary"
                style="border-color:#3b82f6;" title="sv_restart 1">
            🔄 Restart Round
        </button>
        <button onclick="sendMixAction('knife')" class="btn btn-secondary" title="amx_knife">
            🔪 Knife Round
        </button>
        <button onclick="sendMixAction('start')" class="btn btn-primary" title="amx_mixa">
            ⚡ Start AutoMix
        </button>
        <button onclick="sendMixAction('warm')" class="btn btn-secondary" title="amx_warm">
            🔥 WarmUp
        </button>
        <button onclick="sendMixAction('pause')" class="btn btn-warning" title="amx_pause">
            ⏸ Pause
        </button>
        <button onclick="sendMixAction('stop')" class="btn btn-danger" title="amx_mixstop">
            🛑 Stop Mix
        </button>

        <span style="width:1px; height:32px; background:var(--border-color); display:inline-block; vertical-align:middle; margin:0 4px;"></span>

        <!-- Quick Deploy -->
        <a href="plugins.php" class="btn btn-primary"
           style="background:linear-gradient(135deg,#06b6d4,#4f46e5); font-weight:600;"
           title="Go to Plugins page for GitHub Deploy">
            🔄 Update AutoMix Plugin
        </a>
    </div>
</div>

<!-- ══ Stat Widgets ══════════════════════════════════════════════════════════ -->
<div class="grid-cols-4">
    <div class="stat-widget">
        <div class="stat-label">Server Status</div>
        <div class="stat-value" style="display:flex; align-items:center; gap:0.5rem;">
            <?php if ($serverStatus === 'running'): ?>
                <span class="status-pill status-running"><span class="status-dot"></span> RUNNING</span>
            <?php else: ?>
                <span class="status-pill status-stopped"><span class="status-dot"></span> STOPPED</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-label">Online Players</div>
        <div class="stat-value" style="color:var(--accent);">
            <?php echo $info ? "{$info['players']} / {$info['maxplayers']}" : '-- / --'; ?>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-label">Current Map</div>
        <div class="stat-value" style="color:var(--warning); font-size:1.35rem;">
            <?php echo $info ? htmlspecialchars($info['map']) : 'N/A'; ?>
        </div>
    </div>

    <div class="stat-widget">
        <div class="stat-label">Query Ping</div>
        <div class="stat-value" style="color:var(--success);">
            <?php echo $info ? "{$info['ping']} ms" : 'Offline'; ?>
        </div>
    </div>
</div>

<div class="grid-cols-2">
    <!-- Server Details -->
    <div class="card">
        <div class="card-title">
            <span>Server Information</span>
            <button onclick="location.reload()" class="btn btn-secondary btn-sm">⟳ Refresh</button>
        </div>
        <table>
            <tr>
                <td style="color:var(--text-muted); width:40%;">Hostname</td>
                <td><strong><?php echo $info ? htmlspecialchars($info['hostname']) : 'Server Offline'; ?></strong></td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Connection Port</td>
                <td><code><?php echo $activeServer['port']; ?> / UDP</code></td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Engine Version</td>
                <td>ReHLDS (GoldSource x86)</td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Server Directory</td>
                <td><code style="font-size:0.8rem;"><?php echo htmlspecialchars($activeServer['server_dir']); ?></code></td>
            </tr>
            <tr>
                <td style="color:var(--text-muted);">Quick Map Switch</td>
                <td>
                    <button onclick="changeMap('de_dust2')" class="btn btn-secondary btn-sm">de_dust2</button>
                    <button onclick="changeMap('de_inferno')" class="btn btn-secondary btn-sm">de_inferno</button>
                    <button onclick="changeMap('de_train')" class="btn btn-secondary btn-sm">de_train</button>
                    <button onclick="changeMap('')" class="btn btn-primary btn-sm">Other...</button>
                </td>
            </tr>
        </table>
    </div>

    <!-- Live Server Logs -->
    <div class="card">
        <div class="card-title">
            <span>Recent Server Logs</span>
            <a href="console.php" class="btn btn-secondary btn-sm">Open Live RCON</a>
        </div>
        <div class="console-box" style="height:250px; font-size:0.8rem;" id="dashboardLogBox">
            <?php echo htmlspecialchars(ServerCmd::getLogLines($activeServer['log_file'], 50)); ?>
        </div>
    </div>
</div>

<script>
// Auto-scroll log box
const logBox = document.getElementById('dashboardLogBox');
if (logBox) logBox.scrollTop = logBox.scrollHeight;

// ── Alert helper ────────────────────────────────────────────────────────────
function showAlert(msg, type) {
    const el = document.getElementById('dashActionAlert');
    const colors = {
        success: ['rgba(16,185,129,0.15)', 'rgba(16,185,129,0.4)', 'var(--success)'],
        danger:  ['rgba(239,68,68,0.15)',  'rgba(239,68,68,0.4)',  'var(--danger)'],
        info:    ['rgba(6,182,212,0.15)',   'rgba(6,182,212,0.4)',   'var(--accent)'],
        warning: ['rgba(245,158,11,0.15)', 'rgba(245,158,11,0.4)', 'var(--warning)'],
    };
    const c = colors[type] || colors.info;
    el.style.display   = 'block';
    el.style.background = c[0];
    el.style.border     = '1px solid ' + c[1];
    el.style.color      = c[2];
    el.innerHTML        = msg;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.style.display = 'none', 7000);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Service Control (start/stop/restart) ────────────────────────────────────
function controlService(action) {
    if (!confirm('Are you sure you want to ' + action + ' the CS 1.6 server service?')) return;
    showAlert('⏳ Sending <strong>' + action + '</strong> to service...', 'info');

    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('action', 'service_control');
    fd.append('service_action', action);

    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success !== false) {
                showAlert('✅ Service <strong>' + action + '</strong> sent. New status: <strong>' + (d.status || 'unknown') + '</strong>. Refreshing in 3s...', 'success');
                setTimeout(() => location.reload(), 3000);
            } else {
                showAlert('❌ Error: ' + escHtml(d.message || 'Unknown error'), 'danger');
            }
        })
        .catch(e => showAlert('❌ Network error: ' + e.message, 'danger'));
}

// ── Map Change via RCON ─────────────────────────────────────────────────────
function changeMap(mapName) {
    if (!mapName) mapName = prompt('Enter map name (e.g. de_dust2):');
    if (!mapName || !mapName.trim()) return;
    mapName = mapName.trim();
    if (!confirm('Change map to: ' + mapName + '?')) return;

    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('action', 'rcon_command');
    fd.append('command', 'changelevel ' + mapName);

    showAlert('⏳ Changing map to <strong>' + escHtml(mapName) + '</strong>...', 'info');
    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showAlert('✅ Map change command sent: <code>changelevel ' + escHtml(mapName) + '</code>', 'success');
            } else {
                showAlert('❌ RCON error: ' + escHtml(d.message || 'Failed'), 'danger');
            }
        })
        .catch(e => showAlert('❌ Network error: ' + e.message, 'danger'));
}

// ── AutoMix In-Game RCON Commands ────────────────────────────────────────────
function sendMixAction(action) {
    const labels = {
        restart_round: 'Restart Round (sv_restart 1)',
        knife:         'Knife Round (amx_knife)',
        start:         'Start AutoMix (amx_mixa)',
        warm:          'WarmUp (amx_warm)',
        pause:         'Pause/Unpause (amx_pause)',
        stop:          'Stop Match (amx_mixstop)',
    };
    const label = labels[action] || action;
    if (!confirm('Send command to live server: ' + label + '?')) return;

    showAlert('⏳ Sending: <strong>' + escHtml(label) + '</strong>...', 'info');

    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('action', 'mix_command');
    fd.append('mix_action', action);

    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                showAlert('✅ <strong>' + escHtml(label) + '</strong> sent! RCON: <code>' + escHtml(d.command) + '</code>'
                    + (d.response ? ' → <em>' + escHtml(d.response) + '</em>' : ''), 'success');
            } else {
                showAlert('❌ RCON error: ' + escHtml(d.message || 'Server may be offline or RCON wrong password'), 'danger');
            }
        })
        .catch(e => showAlert('❌ Network error: ' + e.message, 'danger'));
}

// ── Server Switcher in Navbar ────────────────────────────────────────────────
function switchServer(serverId) {
    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('action', 'switch_server');
    fd.append('server_id', serverId);
    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => location.reload());
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
