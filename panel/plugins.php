<?php
$pageTitle = 'Plugins & Build Manager';
$activeNav = 'plugins';
require_once __DIR__ . '/includes/header.php';

$msg = '';
$msgType = '';
$compileOutput = '';

// ── Handle Manual Save of Checked Plugins ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plugins'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!'; $msgType = 'danger';
    } else {
        $selectedPlugins = $_POST['plugins'] ?? [];
        $res = ServerCmd::savePluginsState($activeServer, $selectedPlugins);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';

        if (isset($_POST['restart_after_save']) && $_POST['restart_after_save'] === '1' && $res['success']) {
            ServerCmd::controlService($activeServer['service_name'], 'restart');
            $msg .= ' ✅ Server restarted!';
        }
    }
}

// ── Handle Quick Mode Switch ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!'; $msgType = 'danger';
    } else {
        $mode = $_POST['target_mode'] ?? '';
        $res = ServerCmd::switchServerMode($activeServer, $mode);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';
        if ($res['success']) {
            ServerCmd::controlService($activeServer['service_name'], 'restart');
            $msg .= ' 🔄 Server restarted with new mode!';
        }
    }
}

// ── Handle GitHub Sync Action ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_github'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!'; $msgType = 'danger';
    } else {
        $downloadRes = ServerCmd::syncMixFromGitHub($activeServer);
        if (!$downloadRes['success']) {
            $failedStr = !empty($downloadRes['failed']) ? implode(', ', $downloadRes['failed']) : 'unknown error';
            $msg = "❌ Failed to download some files from GitHub: {$failedStr}";
            $msgType = 'danger';
        } else {
            $updatedStr = implode(', ', $downloadRes['updated']);
            $msg = "✅ Downloaded " . count($downloadRes['updated']) . " files: {$updatedStr}";

            if (isset($_POST['compile_after_sync'])) {
                $compRes = ServerCmd::compilePlugins($activeServer);
                $compileOutput = $compRes['output'];
                if ($compRes['success']) {
                    $msg .= " → ✅ Compiled {$compRes['compiled_count']} .amxx binaries!";
                    $msgType = 'success';
                } else {
                    $msg .= " → ⚠️ Compile had errors. See log below.";
                    $msgType = 'warning';
                }
            } else {
                $msgType = 'info';
            }

            if (isset($_POST['restart_after_sync']) && $downloadRes['success']) {
                ServerCmd::controlService($activeServer['service_name'], 'restart');
                $msg .= " → 🔄 Server restarted!";
            }
        }
    }
}

// ── Handle Compile Action ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compile_plugins'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!'; $msgType = 'danger';
    } else {
        $res = ServerCmd::compilePlugins($activeServer);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';
        $compileOutput = $res['output'];

        if (isset($_POST['restart_after_compile']) && $res['success']) {
            ServerCmd::controlService($activeServer['service_name'], 'restart');
            $msg .= ' ✅ Server restarted!';
        }
    }
}

// ── Gather Data ─────────────────────────────────────────────────────────────
$plugins     = ServerCmd::getPluginsList($activeServer);
$gitStatus   = ServerCmd::getGitRepoStatus();

$lastSyncFile = $activeServer['cstrike_dir'] . '/addons/amxmodx/configs/.mix_last_sync.json';
$lastSyncMeta = file_exists($lastSyncFile) ? @json_decode(file_get_contents($lastSyncFile), true) : null;

// Build status check
$scriptingDir = $activeServer['cstrike_dir'] . '/addons/amxmodx/scripting';
$pluginsDir   = $activeServer['cstrike_dir'] . '/addons/amxmodx/plugins';
$buildTargets = [
    'mix_system.sma'            => 'mix_system.amxx',
    'mix_system_voice_chat.sma' => 'mix_system_voice_chat.amxx',
    'player_drop.sma'           => 'player_drop.amxx',
    'mix_database_stats.sma'    => 'mix_database_stats.amxx',
];
$buildStatus = [];
foreach ($buildTargets as $src => $bin) {
    $srcMt = file_exists($scriptingDir.'/'.$src) ? filemtime($scriptingDir.'/'.$src) : 0;
    $binMt = file_exists($pluginsDir.'/'.$bin)   ? filemtime($pluginsDir.'/'.$bin)   : 0;
    $buildStatus[$src] = [
        'sma_exists'    => $srcMt > 0,
        'amxx_exists'   => $binMt > 0,
        'needs_rebuild' => $srcMt > 0 && $binMt < $srcMt,
        'sma_mtime'     => $srcMt > 0 ? date('Y-m-d H:i:s', $srcMt) : null,
        'amxx_mtime'    => $binMt > 0 ? date('Y-m-d H:i:s', $binMt) : null,
    ];
}

// Group plugins by section
$sections = [];
foreach ($plugins as $p) {
    $sec = $p['section'] ?? 'General';
    $sections[$sec][] = $p;
}
?>

<?php if ($msg): ?>
<div class="alert alert-<?php echo $msgType; ?>" style="display:flex;align-items:center;gap:0.75rem;">
    <span style="font-size:1.2rem;"><?php echo $msgType==='success'?'✅':($msgType==='danger'?'❌':($msgType==='warning'?'⚠️':'ℹ️')); ?></span>
    <span><?php echo htmlspecialchars($msg); ?></span>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     GITHUB REPO SYNC & DEPLOY WIDGET
═══════════════════════════════════════════════════════════ -->
<div class="card" style="border: 1px solid rgba(6,182,212,0.45); background: linear-gradient(135deg, rgba(6,182,212,0.07), rgba(79,70,229,0.05));">
    <div class="card-title" style="color: var(--accent);">
        <span>🔄 GitHub Auto-Sync &amp; Deploy — <code style="font-size:0.85rem;">GAMELAND-PROJECT/MixSystem_SV_PL</code></span>
        <a href="https://github.com/GAMELAND-PROJECT/MixSystem_SV_PL" target="_blank"
           style="font-size:0.8rem; color:var(--text-muted); text-decoration:none;">🔗 View on GitHub →</a>
    </div>

    <!-- Status Info Row -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; margin-bottom:1.25rem;">

        <!-- Latest GitHub Commit -->
        <div style="background:var(--bg-card); padding:0.9rem 1.1rem; border-radius:8px; border:1px solid var(--border-color);">
            <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:0.4rem;">Latest Commit on GitHub (main)</div>
            <?php if ($gitStatus): ?>
                <div style="font-family:var(--font-mono); font-size:0.9rem; font-weight:700; color:var(--accent);">
                    [<?php echo htmlspecialchars($gitStatus['sha']); ?>]
                    <?php
                    $msg2 = $gitStatus['message'];
                    echo htmlspecialchars(mb_strlen($msg2)>55 ? mb_substr($msg2,0,52).'...' : $msg2);
                    ?>
                </div>
                <div style="font-size:0.77rem; color:var(--text-muted); margin-top:0.25rem;">
                    👤 <?php echo htmlspecialchars($gitStatus['author']); ?> &bull;
                    📅 <?php echo htmlspecialchars(substr($gitStatus['date'],0,10)); ?>
                </div>
            <?php else: ?>
                <div style="font-size:0.85rem; color:var(--text-muted);">⚠️ Unable to reach GitHub API right now.</div>
            <?php endif; ?>
        </div>

        <!-- Installed Version -->
        <div style="background:var(--bg-card); padding:0.9rem 1.1rem; border-radius:8px; border:1px solid var(--border-color);">
            <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:0.4rem;">Server Installed Version</div>
            <?php if ($lastSyncMeta && isset($lastSyncMeta['commit'])): ?>
                <?php
                $localSha  = $lastSyncMeta['commit']['sha'] ?? 'unknown';
                $remoteSha = $gitStatus ? $gitStatus['sha'] : null;
                $upToDate  = $remoteSha && $localSha === $remoteSha;
                ?>
                <div style="font-family:var(--font-mono); font-size:0.9rem; font-weight:700; color:<?php echo $upToDate?'var(--success)':'var(--warning)'; ?>;">
                    <?php echo $upToDate ? '✅' : '⚠️'; ?> SHA: [<?php echo htmlspecialchars($localSha); ?>]
                </div>
                <div style="font-size:0.77rem; color:var(--text-muted); margin-top:0.25rem;">
                    Last Sync: <?php echo htmlspecialchars($lastSyncMeta['sync_time'] ?? 'N/A'); ?>
                    <?php if (!$upToDate && $remoteSha): ?>
                        &bull; <span style="color:var(--warning); font-weight:600;">Update Available!</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="font-size:0.85rem; color:var(--warning);">⚠️ No sync record. Download latest code below.</div>
            <?php endif; ?>
        </div>

        <!-- Build Status Summary -->
        <div style="background:var(--bg-card); padding:0.9rem 1.1rem; border-radius:8px; border:1px solid var(--border-color);">
            <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:0.4rem;">Plugin Build Status (.sma → .amxx)</div>
            <?php
            $needsRebuildCount = count(array_filter($buildStatus, fn($b)=>$b['needs_rebuild']));
            $missingAmxx       = count(array_filter($buildStatus, fn($b)=>!$b['amxx_exists']));
            ?>
            <div style="font-size:0.9rem; font-weight:700; color:<?php echo ($needsRebuildCount+$missingAmxx)>0?'var(--warning)':'var(--success)'; ?>;">
                <?php if ($needsRebuildCount + $missingAmxx > 0): ?>
                    ⚠️ <?php echo $needsRebuildCount + $missingAmxx; ?> plugin(s) need recompile!
                <?php else: ?>
                    ✅ All binaries are up to date
                <?php endif; ?>
            </div>
            <div style="font-size:0.77rem; color:var(--text-muted); margin-top:0.25rem;">
                <?php foreach ($buildStatus as $src => $bs): ?>
                    <span title="<?php echo htmlspecialchars($src); ?> → amxx: <?php echo $bs['amxx_mtime'] ?? 'missing'; ?>"
                          style="display:inline-block; margin-right:0.3rem;
                                 color:<?php echo $bs['needs_rebuild']?'var(--warning)':($bs['amxx_exists']?'var(--success)':'var(--danger)'); ?>;">
                        <?php echo $bs['needs_rebuild']?'⚠️':($bs['amxx_exists']?'✅':'❌'); ?>
                        <?php echo htmlspecialchars(str_replace(['mix_','.sma'],['',''],$src)); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center;">

        <!-- 1-Click Deploy -->
        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Download latest source from GitHub, compile all plugins, and restart server?\n\nThis will briefly take the server offline!')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="sync_github" value="1">
            <input type="hidden" name="compile_after_sync" value="1">
            <input type="hidden" name="restart_after_sync" value="1">
            <button type="submit" class="btn btn-primary"
                    style="padding:0.8rem 1.4rem; font-weight:700; background:linear-gradient(135deg,#06b6d4,#4f46e5); box-shadow:0 4px 15px rgba(6,182,212,0.3);">
                ⚡ 1-Click Deploy: Download → Compile → Restart
            </button>
        </form>

        <!-- Download + Compile only -->
        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Download latest source from GitHub and compile? (No server restart)')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="sync_github" value="1">
            <input type="hidden" name="compile_after_sync" value="1">
            <button type="submit" class="btn btn-secondary" style="padding:0.8rem 1.2rem;">
                📥 Download &amp; Compile Only
            </button>
        </form>

        <!-- Download .sma only -->
        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Download latest .sma source files only? (.amxx will NOT be updated until you compile)')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="sync_github" value="1">
            <button type="submit" class="btn btn-secondary" style="padding:0.8rem 1.2rem; border-color:#3b82f6;">
                📄 Sync Source Only
            </button>
        </form>

        <!-- Compile Local + Restart -->
        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Compile local .sma files (no GitHub download) and restart server?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="compile_plugins" value="1">
            <input type="hidden" name="restart_after_compile" value="1">
            <button type="submit" class="btn btn-warning" style="padding:0.8rem 1.2rem;">
                ⚙️ Compile Local &amp; Restart
            </button>
        </form>

        <!-- Compile only, no restart -->
        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Compile local .sma files? (No restart — useful while server is running)')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="compile_plugins" value="1">
            <button type="submit" class="btn btn-secondary" style="padding:0.8rem 1.2rem;">
                🔨 Compile Only
            </button>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     COMPILER OUTPUT LOG
═══════════════════════════════════════════════════════════ -->
<?php if (!empty($compileOutput)): ?>
<div class="card">
    <div class="card-title">
        <span>🖥️ Compiler Output Log</span>
        <button onclick="this.closest('.card').style.display='none'" class="btn btn-secondary btn-sm">✕ Close</button>
    </div>
    <div class="console-box" style="height:220px; font-size:0.79rem; white-space:pre-wrap;"><?php echo htmlspecialchars($compileOutput); ?></div>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════
     QUICK SERVER MODE SWITCHER
═══════════════════════════════════════════════════════════ -->
<div class="card" style="background:linear-gradient(135deg,rgba(79,70,229,0.1),rgba(6,182,212,0.05));">
    <div class="card-title">
        <span>⚡ Quick Server Mode Switcher</span>
        <span style="font-size:0.8rem; color:var(--text-muted);">Switch plugin set + player slots + auto restart</span>
    </div>
    <div style="display:flex; gap:1rem; flex-wrap:wrap;">
        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Switch server to 5v5 AutoMix mode (12 slots) and restart?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="target_mode" value="mix5v5">
            <button type="submit" name="switch_mode" class="btn btn-primary" style="padding:0.8rem 1.35rem;">
                🎮 5v5 AutoMix Mode (12 Slots)
            </button>
        </form>

        <form method="POST" action="plugins.php"
              onsubmit="return confirm('Switch server to Classic Public mode (24 slots) and restart?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="target_mode" value="public">
            <button type="submit" name="switch_mode" class="btn btn-secondary" style="padding:0.8rem 1.35rem;">
                🎯 Classic Public Mode (24 Slots)
            </button>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     MIX IN-GAME CONTROLS  (via RCON)
═══════════════════════════════════════════════════════════ -->
<div class="card" style="border:1px solid rgba(139,92,246,0.35); background:linear-gradient(135deg,rgba(139,92,246,0.07),rgba(6,182,212,0.03));">
    <div class="card-title" style="color:var(--accent);">
        <span>🎮 AutoMix In-Game Controls (Live RCON)</span>
        <span id="mixCmdStatus" style="font-size:0.8rem; color:var(--text-muted);"></span>
    </div>

    <div id="mixCmdAlert" style="display:none; padding:0.65rem 1rem; border-radius:6px; margin-bottom:0.8rem; font-size:0.88rem;"></div>

    <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
        <button onclick="mixCmd('restart_round')" class="btn btn-secondary mix-ctrl-btn" title="sv_restart 1 — Restart current round">
            🔄 Restart Round
        </button>
        <button onclick="mixCmd('knife')" class="btn btn-secondary mix-ctrl-btn" title="Start knife round (amx_knife)">
            🔪 Knife Round
        </button>
        <button onclick="mixCmd('start')" class="btn btn-primary mix-ctrl-btn" title="Start AutoMix queue (amx_mixa)">
            ▶️ Start AutoMix
        </button>
        <button onclick="mixCmd('warm')" class="btn btn-secondary mix-ctrl-btn" title="Start warmup (amx_warm)">
            🔥 WarmUp
        </button>
        <button onclick="mixCmd('pause')" class="btn btn-warning mix-ctrl-btn" title="Pause / Unpause match (amx_pause)">
            ⏸ Pause / Unpause
        </button>
        <button onclick="mixCmd('stop')" class="btn btn-danger mix-ctrl-btn" title="Stop/reset match completely (amx_mixstop)">
            🛑 Stop &amp; Reset Match
        </button>
        <button onclick="mixCmd('stop_round')" class="btn btn-danger mix-ctrl-btn" title="Immediately end the current round" style="border-color:#ef4444;">
            ⏭ End Round Now
        </button>
    </div>

    <div style="margin-top:0.75rem; font-size:0.77rem; color:var(--text-muted);">
        ℹ️ These commands are sent to the live server via RCON. Server must be running and RCON must be reachable.
        <code><?php echo htmlspecialchars($activeServer['ip'].':'.$activeServer['port']); ?></code>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     FULL PLUGIN LIST (plugins.ini editor)
═══════════════════════════════════════════════════════════ -->
<form method="POST" action="plugins.php">
    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
        <h2 style="font-size:1.15rem; font-weight:700;">📋 All Plugins (plugins.ini)</h2>
        <div style="display:flex; gap:0.5rem;">
            <button type="submit" name="save_plugins" class="btn btn-secondary">💾 Save Configuration</button>
            <button type="submit" name="save_plugins"
                    onclick="this.form.restart_after_save.value='1'" class="btn btn-success">
                💾 Save &amp; Restart Server
            </button>
            <input type="hidden" name="restart_after_save" value="0">
        </div>
    </div>

    <?php foreach ($sections as $secName => $secPlugins): ?>
    <div class="card" style="margin-bottom:1.1rem;<?php echo ($secName==='5v5 Mix System')?'border:1px solid var(--accent);':''; ?>">
        <div class="card-title" style="color:<?php echo ($secName==='5v5 Mix System')?'var(--accent)':'var(--text-main)'; ?>;">
            <span>📂 <?php echo htmlspecialchars($secName); ?> <span style="font-size:0.8rem; opacity:0.7;">(<?php echo count($secPlugins); ?> plugins)</span></span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:48px;">Active</th>
                        <th>Plugin File</th>
                        <th>Description / Comment</th>
                        <th style="width:130px;">.amxx Binary</th>
                        <?php if ($secName==='5v5 Mix System'): ?>
                        <th style="width:130px;">Build Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($secPlugins as $p): ?>
                    <?php
                    $smaName = str_replace('.amxx', '.sma', $p['file']);
                    $bstat   = $buildStatus[$smaName] ?? null;
                    ?>
                    <tr style="<?php echo $p['is_mix']?'background:rgba(6,182,212,0.04);':''; ?>">
                        <td style="text-align:center;">
                            <input type="checkbox"
                                   name="plugins[]"
                                   value="<?php echo htmlspecialchars($p['file']); ?>"
                                   <?php echo $p['enabled'] ? 'checked' : ''; ?>
                                   style="width:17px; height:17px; accent-color:var(--primary); cursor:pointer;">
                        </td>
                        <td>
                            <code style="font-size:0.88rem; font-weight:<?php echo $p['is_mix']?'700':'400'; ?>; color:<?php echo $p['is_mix']?'var(--accent)':'inherit'; ?>;">
                                <?php echo htmlspecialchars($p['file']); ?>
                            </code>
                            <?php if (!$p['enabled']): ?>
                                <span style="font-size:0.72rem; background:rgba(239,68,68,0.15); color:var(--danger); padding:1px 6px; border-radius:4px; margin-left:4px;">DISABLED</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--text-muted); font-size:0.82rem;"><?php echo htmlspecialchars($p['comment']); ?></td>
                        <td>
                            <?php if ($p['installed']): ?>
                                <span style="color:var(--success); font-size:0.8rem; font-weight:600;">✔ Installed</span>
                            <?php else: ?>
                                <span style="color:var(--danger); font-size:0.8rem; font-weight:600;">✖ Missing</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($secName==='5v5 Mix System'): ?>
                        <td>
                            <?php if ($bstat): ?>
                                <?php if ($bstat['needs_rebuild']): ?>
                                    <span style="color:var(--warning); font-size:0.79rem; font-weight:600;" title="SMA newer than AMXX — recompile needed">⚠️ Stale</span>
                                <?php elseif ($bstat['amxx_exists']): ?>
                                    <span style="color:var(--success); font-size:0.79rem; font-weight:600;" title="amxx: <?php echo htmlspecialchars($bstat['amxx_mtime']); ?>">✅ Fresh</span>
                                <?php else: ?>
                                    <span style="color:var(--danger); font-size:0.79rem; font-weight:600;">❌ Not built</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:0.79rem; color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="text-align:right; margin-top:0.5rem; margin-bottom:2rem;">
        <button type="submit" name="save_plugins"
                onclick="this.form.restart_after_save.value='1'"
                class="btn btn-success" style="padding:0.8rem 1.6rem; font-size:1rem;">
            💾 Save Changes &amp; Restart Server
        </button>
    </div>
</form>

<script>
// ── Mix RCON Commands ─────────────────────────────────────────────────────────
function mixCmd(action) {
    const labels = {
        restart_round: '🔄 Restarting Round...',
        knife:         '🔪 Starting Knife Round...',
        start:         '▶️ Starting AutoMix...',
        warm:          '🔥 Starting WarmUp...',
        pause:         '⏸ Toggling Pause...',
        stop:          '🛑 Stopping Match...',
        stop_round:    '⏭ Ending Round...',
    };
    const alert = document.getElementById('mixCmdAlert');
    const status = document.getElementById('mixCmdStatus');

    alert.style.display = 'block';
    alert.style.background = 'rgba(6,182,212,0.15)';
    alert.style.border = '1px solid rgba(6,182,212,0.4)';
    alert.style.color = 'var(--accent)';
    alert.textContent = labels[action] || 'Sending command...';

    document.querySelectorAll('.mix-ctrl-btn').forEach(b => b.disabled = true);

    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('action', 'mix_command');
    fd.append('mix_action', action);

    fetch('api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert.style.background = 'rgba(16,185,129,0.15)';
                alert.style.border = '1px solid rgba(16,185,129,0.4)';
                alert.style.color = 'var(--success)';
                alert.innerHTML = '✅ <strong>Command sent!</strong> RCON cmd: <code>' +
                    escHtml(data.command) + '</code>' +
                    (data.response ? ' → <em>' + escHtml(data.response) + '</em>' : '');
                status.textContent = '✅ ' + (data.action || action);
            } else {
                alert.style.background = 'rgba(239,68,68,0.15)';
                alert.style.border = '1px solid rgba(239,68,68,0.4)';
                alert.style.color = 'var(--danger)';
                alert.innerHTML = '❌ <strong>Error:</strong> ' + escHtml(data.message || 'Unknown error');
                status.textContent = '❌ Failed';
            }
        })
        .catch(err => {
            alert.style.background = 'rgba(239,68,68,0.15)';
            alert.style.border = '1px solid rgba(239,68,68,0.4)';
            alert.style.color = 'var(--danger)';
            alert.textContent = '❌ Network error: ' + err.message;
        })
        .finally(() => {
            document.querySelectorAll('.mix-ctrl-btn').forEach(b => b.disabled = false);
            setTimeout(() => { if (alert.style.display!=='none') alert.style.display='none'; }, 6000);
        });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
