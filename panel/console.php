<?php
$pageTitle = 'RCON Console';
$activeNav = 'console';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-title">
        <span>Interactive RCON Terminal &bull; <?php echo htmlspecialchars($activeServer['name']); ?></span>
        <div style="display: flex; gap: 0.5rem;">
            <button onclick="document.getElementById('consoleBox').innerHTML = 'Console cleared.\n'" class="btn btn-secondary btn-sm">Clear</button>
        </div>
    </div>

    <!-- Quick Shortcuts -->
    <div style="display: flex; gap: 0.5rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
        <span style="color: var(--text-muted); font-size: 0.8rem; align-self: center;">Quick:</span>
        <button onclick="runQuickCmd('status')" class="btn btn-secondary btn-sm">status</button>
        <button onclick="runQuickCmd('amx_plugins')" class="btn btn-secondary btn-sm">amx_plugins</button>
        <button onclick="runQuickCmd('amx_modules')" class="btn btn-secondary btn-sm">amx_modules</button>
        <button onclick="runQuickCmd('stats')" class="btn btn-secondary btn-sm">stats</button>
        <button onclick="runQuickCmd('amx_reloadadmin')" class="btn btn-secondary btn-sm">amx_reloadadmin</button>
        <button onclick="runQuickCmd('mp_timelimit')" class="btn btn-secondary btn-sm">mp_timelimit</button>
        <button onclick="runQuickCmd('sv_restart 1')" class="btn btn-warning btn-sm">sv_restart 1</button>
    </div>

    <div class="console-box" id="consoleBox">> Ready. Type a command below or click a quick action above...</div>

    <div class="console-input-row">
        <input type="text" id="rconCommandInput" class="input-field" placeholder="Enter console command (e.g. status, amx_who, kick #1, changelevel de_dust2)..." autofocus autocomplete="off">
        <button onclick="sendRconCommand()" class="btn btn-primary">Send (Enter)</button>
    </div>
</div>

<script>
function runQuickCmd(cmd) {
    const input = document.getElementById('rconCommandInput');
    if (input) {
        input.value = cmd;
        sendRconCommand();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
