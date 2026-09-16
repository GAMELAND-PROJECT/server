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

// Handle GitHub Sync Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_github'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $downloadRes = ServerCmd::syncMixFromGitHub($activeServer);
        if (!$downloadRes['success']) {
            $msg = 'Failed to download plugins from GitHub repository. Check internet connection and file permissions.';
            $msgType = 'danger';
        } else {
            $updatedListStr = implode(', ', $downloadRes['updated']);
            $msg = "Downloaded " . count($downloadRes['updated']) . " files from GitHub (" . $updatedListStr . ").";
            
            // If requested to compile immediately
            if (isset($_POST['compile_after_sync'])) {
                $compRes = ServerCmd::compilePlugins($activeServer);
                $compileOutput = $compRes['output'];
                if ($compRes['success']) {
                    $msg .= " Successfully compiled {$compRes['compiled_count']} binaries (.amxx)!";
                    $msgType = 'success';
                } else {
                    $msg .= " Compilation had warnings or errors. Check compiler log.";
                    $msgType = 'warning';
                }
            } else {
                $msgType = 'info';
            }

            // If requested to restart immediately
            if (isset($_POST['restart_after_sync'])) {
                ServerCmd::controlService($activeServer['service_name'], 'restart');
                $msg .= " Server restarted to apply changes!";
            }
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

        if (isset($_POST['restart_after_compile']) && $res['success']) {
            ServerCmd::controlService($activeServer['service_name'], 'restart');
            $msg .= ' Server restarted successfully!';
        }
    }
}

$plugins = ServerCmd::getPluginsList($activeServer);
$gitStatus = ServerCmd::getGitRepoStatus();
$lastSyncMeta = null;
$lastSyncFile = $activeServer['cstrike_dir'] . '/addons/amxmodx/configs/.mix_last_sync.json';
if (file_exists($lastSyncFile)) {
    $lastSyncMeta = @json_decode(file_get_contents($lastSyncFile), true);
}

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

<!-- Auto-Sync & GitHub Deploy Widget -->
<div class="card" style="border: 1px solid rgba(6, 182, 212, 0.4); background: linear-gradient(135deg, rgba(6, 182, 212, 0.08), rgba(79, 70, 229, 0.05));">
    <div class="card-title" style="color: var(--accent);">
        <span>🔄 GitHub Repository Auto-Sync & Deploy (GAMELAND-PROJECT/MixSystem_SV_PL)</span>
        <a href="https://github.com/GAMELAND-PROJECT/MixSystem_SV_PL" target="_blank" style="font-size: 0.8rem; color: var(--text-muted); text-decoration: none;">🔗 Open GitHub &rarr;</a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
        <div style="background: var(--bg-card); padding: 0.9rem 1.1rem; border-radius: 8px; border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Latest Commit on GitHub (main)</div>
            <?php if ($gitStatus): ?>
                <div style="font-family: var(--font-mono); font-size: 0.92rem; font-weight: 700; color: var(--accent); margin-top: 0.3rem;">
                    [<?php echo htmlspecialchars($gitStatus['sha']); ?>] <?php echo htmlspecialchars(mb_strimwidth($gitStatus['message'], 0, 45, '...')); ?>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.2rem;">
                    Author: <?php echo htmlspecialchars($gitStatus['author']); ?> &bull; <?php echo htmlspecialchars(substr($gitStatus['date'], 0, 10)); ?>
                </div>
            <?php else: ?>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.3rem;">Unable to check GitHub status directly.</div>
            <?php endif; ?>
        </div>

        <div style="background: var(--bg-card); padding: 0.9rem 1.1rem; border-radius: 8px; border: 1px solid var(--border-color);">
            <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Current Server Mix Status</div>
            <?php if ($lastSyncMeta && isset($lastSyncMeta['commit'])): ?>
                <div style="font-family: var(--font-mono); font-size: 0.92rem; font-weight: 700; color: var(--success); margin-top: 0.3rem;">
                    Installed SHA: [<?php echo htmlspecialchars($lastSyncMeta['commit']['sha'] ?? 'unknown'); ?>]
                </div>
                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.2rem;">
                    Last Synced: <?php echo htmlspecialchars($lastSyncMeta['sync_time'] ?? 'N/A'); ?>
                </div>
            <?php else: ?>
                <div style="font-size: 0.85rem; color: var(--warning); margin-top: 0.3rem;">No sync record found. Click below to download latest code.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sync Action Buttons -->
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
        <form method="POST" action="plugins.php" onsubmit="return confirm('Download latest Mix code from GitHub, compile all binaries (.amxx), and restart server?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="sync_github" value="1">
            <input type="hidden" name="compile_after_sync" value="1">
            <input type="hidden" name="restart_after_sync" value="1">
            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.35rem; font-weight: 700; background: linear-gradient(135deg, #06b6d4, #4f46e5);">
                ⚡ 1-Click Auto Deploy: Download, Compile & Restart
            </button>
        </form>

        <form method="POST" action="plugins.php" onsubmit="return confirm('Download latest files from GitHub without restarting?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="sync_github" value="1">
            <input type="hidden" name="compile_after_sync" value="1">
            <button type="submit" class="btn btn-secondary" style="padding: 0.75rem 1.1rem;">
                📥 Download & Compile Only
            </button>
        </form>

        <form method="POST" action="plugins.php" onsubmit="return confirm('Compile local .sma files and restart server?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="compile_plugins" value="1">
            <input type="hidden" name="restart_after_compile" value="1">
            <button type="submit" class="btn btn-warning" style="padding: 0.75rem 1.1rem;">
                ⚙ Compile Local & Restart
            </button>
        </form>
    </div>
</div>

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
