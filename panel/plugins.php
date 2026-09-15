<?php
$pageTitle = 'Plugins & Modes Manager';
$activeNav = 'plugins';
require_once __DIR__ . '/includes/header.php';

$msg = '';
$msgType = '';
$compileOutput = '';

// Handle Manual Save of Checked Plugins
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plugins'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $selectedPlugins = $_POST['plugins'] ?? [];
        $res = ServerCmd::savePluginsState($activeServer, $selectedPlugins);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';

        // If requested to restart server immediately
        if (isset($_POST['restart_after_save']) && $res['success']) {
            ServerCmd::controlService($activeServer['service_name'], 'restart');
            $msg .= ' Server restarted successfully!';
        }
    }
}

// Handle Quick Mode Switch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $mode = $_POST['target_mode'] ?? '';
        $res = ServerCmd::switchServerMode($activeServer, $mode);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';

        if ($res['success']) {
            ServerCmd::controlService($activeServer['service_name'], 'restart');
            $msg .= ' Server restarted with new mode!';
        }
    }
}

// Handle Compile Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compile_plugins'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $res = ServerCmd::compilePlugins($activeServer);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';
        $compileOutput = $res['output'];
    }
}

$plugins = ServerCmd::getPluginsList($activeServer);

// Group plugins by section
$sections = [];
foreach ($plugins as $p) {
    $sec = $p['section'] ?? 'General';
    $sections[$sec][] = $p;
}
?>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- Quick Server Mode Presets -->
<div class="card" style="background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(6, 182, 212, 0.05));">
    <div class="card-title">
        <span>⚡ Quick Server Mode Switcher</span>
        <span style="font-size: 0.8rem; color: var(--text-muted);">One-click preset switch & server restart</span>
    </div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <form method="POST" action="plugins.php" onsubmit="return confirm('Switch server to 5v5 AutoMix mode and restart?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="target_mode" value="mix5v5">
            <button type="submit" name="switch_mode" class="btn btn-primary" style="padding: 0.75rem 1.25rem;">
                🎮 Switch to: 5v5 AutoMix Mode (12 Slots)
            </button>
        </form>

        <form method="POST" action="plugins.php" onsubmit="return confirm('Switch server to Classic Public mode and restart?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="target_mode" value="public">
            <button type="submit" name="switch_mode" class="btn btn-secondary" style="padding: 0.75rem 1.25rem;">
                🎯 Switch to: Classic Public Mode (24 Slots)
            </button>
        </form>

        <form method="POST" action="plugins.php" style="margin-left: auto;">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <button type="submit" name="compile_plugins" class="btn btn-warning">
                ⚙ Compile & Update Mix Plugins (.sma &rarr; .amxx)
            </button>
        </form>
    </div>
</div>

<?php if (!empty($compileOutput)): ?>
    <div class="card">
        <div class="card-title">Compiler Output Log</div>
        <div class="console-box" style="height: 180px; font-size: 0.8rem;">
            <?php echo htmlspecialchars($compileOutput); ?>
        </div>
    </div>
<?php endif; ?>

<!-- Detailed Plugins Toggle Form -->
<form method="POST" action="plugins.php">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h2 style="font-size: 1.2rem; font-weight: 600;">Active Plugins in plugins.ini</h2>
        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" name="save_plugins" class="btn btn-secondary">💾 Save Configuration</button>
            <button type="submit" name="save_plugins" onclick="this.form.restart_after_save.value='1'" class="btn btn-success">
                💾 Save & Restart Server
            </button>
            <input type="hidden" name="restart_after_save" value="0">
        </div>
    </div>

    <?php foreach ($sections as $secName => $secPlugins): ?>
        <div class="card" style="margin-bottom: 1.25rem; <?php echo ($secName === '5v5 Mix System') ? 'border: 1px solid var(--accent);' : ''; ?>">
            <div class="card-title" style="color: <?php echo ($secName === '5v5 Mix System') ? 'var(--accent)' : 'var(--text-main)'; ?>;">
                <span>📂 <?php echo htmlspecialchars($secName); ?> (<?php echo count($secPlugins); ?> plugins)</span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">Status</th>
                            <th>Plugin Name</th>
                            <th>Description / Comments</th>
                            <th style="width: 120px;">Binary (.amxx)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secPlugins as $p): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="plugins[]" value="<?php echo htmlspecialchars($p['file']); ?>" <?php echo $p['enabled'] ? 'checked' : ''; ?> style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer;">
                                </td>
                                <td>
                                    <strong><code><?php echo htmlspecialchars($p['file']); ?></code></strong>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.82rem;">
                                    <?php echo htmlspecialchars($p['comment']); ?>
                                </td>
                                <td>
                                    <?php if ($p['installed']): ?>
                                        <span style="color: var(--success); font-size: 0.8rem; font-weight: 600;">✔ Installed</span>
                                    <?php else: ?>
                                        <span style="color: var(--danger); font-size: 0.8rem; font-weight: 600;">✖ Missing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <div style="text-align: right; margin-top: 1rem; margin-bottom: 2rem;">
        <button type="submit" name="save_plugins" onclick="this.form.restart_after_save.value='1'" class="btn btn-success" style="padding: 0.75rem 1.5rem; font-size: 1rem;">
            💾 Save Changes & Restart Server
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
