<?php
$pageTitle = 'Server Instances';
$activeNav = 'servers';
require_once __DIR__ . '/includes/header.php';

$servers = ServerCmd::getServerList($SERVERS);
?>

<div class="card">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
        <div style="display:flex; align-items:center; gap:0.6rem;">
            <span>Server Instances</span>
            <span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa;"><?php echo count($servers); ?> Active</span>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <button onclick="createGlobalCheckpoint()" class="btn btn-sm"
                    style="background:linear-gradient(135deg,#f59e0b,#ea580c); color:#fff; font-weight:700; border:none; box-shadow:0 2px 8px rgba(245,158,11,0.3);"
                    title="ثبت چک‌پوینت سرتاسری از تمام ادمین‌ها و سرورها">
                💾 Take Checkpoint
            </button>
            <button onclick="restoreLatestGlobalCheckpoint()" class="btn btn-sm btn-secondary"
                    style="border:1px solid #f59e0b; color:#fbbf24; font-weight:600;"
                    title="بازگردانی تمام ادمین‌ها و سرورها به آخرین چک‌پوینت">
                ↺ Restore Latest
            </button>
            <button onclick="refreshServers()" class="btn btn-secondary btn-sm">⟳ Refresh</button>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table id="serversTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name / Hostname</th>
                    <th>Port</th>
                    <th>Active Mode</th>
                    <th>AFK Guard</th>
                    <th>Slots</th>
                    <th>Start Map</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($servers as $server): ?>
                    <tr data-server-row="<?php echo htmlspecialchars($server['id']); ?>">
                        <td><code><?php echo htmlspecialchars($server['id']); ?></code></td>
                        <td>
                            <strong><?php echo htmlspecialchars($server['name']); ?></strong><br>
                            <span style="color:var(--text-muted); font-size:0.8rem;"><?php echo htmlspecialchars($server['hostname']); ?></span>
                        </td>
                        <td><code><?php echo (int)$server['port']; ?></code></td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:0.35rem; align-items:flex-start;">
                                <?php if (($server['mode'] ?? '') === 'clanwar'): ?>
                                    <span class="badge" style="background:rgba(245,158,11,0.2); color:#f59e0b; border:1px solid rgba(245,158,11,0.5); font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;">
                                        ⚔️ Clan War (10s)
                                    </span>
                                <?php elseif (($server['mode'] ?? '') === 'mix5v5'): ?>
                                    <span class="badge" style="background:rgba(99,102,241,0.2); color:#818cf8; border:1px solid rgba(99,102,241,0.5); font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;">
                                        🎮 5v5 Match (18s)
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.2); color:#34d399; border:1px solid rgba(16,185,129,0.5); font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;">
                                        🎯 Public (24s)
                                    </span>
                                <?php endif; ?>

                                <select class="input-field" style="padding:0.2rem 0.35rem; font-size:0.72rem; height:auto; min-width:120px; background:rgba(0,0,0,0.35); border-color:rgba(255,255,255,0.15); color:#fff;"
                                        onchange="quickChangeServerMode('<?php echo htmlspecialchars($server['id']); ?>', this.value, '<?php echo htmlspecialchars(addslashes($server['name'])); ?>')">
                                    <option value="" disabled selected>⚡ Change Mode</option>
                                    <option value="clanwar" <?php echo (($server['mode'] ?? '') === 'clanwar') ? 'disabled' : ''; ?>>⚔️ Clan War (10s)</option>
                                    <option value="mix5v5" <?php echo (($server['mode'] ?? '') === 'mix5v5') ? 'disabled' : ''; ?>>🎮 5v5 Match (18s)</option>
                                    <option value="public" <?php echo (($server['mode'] ?? '') === 'public') ? 'disabled' : ''; ?>>🎯 Public (24s)</option>
                                </select>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:0.35rem; align-items:flex-start;">
                                <?php $afkVal = (int)($server['afk_guard'] ?? 1); ?>
                                <?php if ($afkVal === 0): ?>
                                    <span class="badge" style="background:rgba(239,68,68,0.2); color:#f87171; border:1px solid rgba(239,68,68,0.5); font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;" title="کیک و انتقال به اسپکت خاموش است">
                                        🧪 OFF (Test Mode)
                                    </span>
                                <?php elseif ($afkVal === 2): ?>
                                    <span class="badge" style="background:rgba(245,158,11,0.2); color:#fbbf24; border:1px solid rgba(245,158,11,0.5); font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;" title="انتقال به اسپکت بدون هیچ‌گونه کیک">
                                        👁️ Spec Only (No Kick)
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.2); color:#34d399; border:1px solid rgba(16,185,129,0.5); font-weight:700; font-size:0.75rem; padding:0.2rem 0.55rem; border-radius:999px;" title="محافظت کامل فعال">
                                        🛡️ FULL (Spec + Kick)
                                    </span>
                                <?php endif; ?>

                                <select class="input-field" style="padding:0.2rem 0.35rem; font-size:0.72rem; height:auto; min-width:125px; background:rgba(0,0,0,0.35); border-color:rgba(255,255,255,0.15); color:#fff;"
                                        onchange="quickChangeServerAfkGuard('<?php echo htmlspecialchars($server['id']); ?>', this.value, '<?php echo htmlspecialchars(addslashes($server['name'])); ?>')">
                                    <option value="" disabled selected>⚡ AFK Guard</option>
                                    <option value="0" <?php echo ($afkVal === 0) ? 'disabled' : ''; ?>>🧪 Disabled (Test Mode)</option>
                                    <option value="2" <?php echo ($afkVal === 2) ? 'disabled' : ''; ?>>👁️ Spec Only (No Kick)</option>
                                    <option value="1" <?php echo ($afkVal === 1) ? 'disabled' : ''; ?>>🛡️ Full Guard (Normal)</option>
                                </select>
                            </div>
                        </td>
                        <td><?php echo (int)$server['slots']; ?></td>
                        <td><code><?php echo htmlspecialchars($server['map']); ?></code></td>
                        <td>
                            <?php if ($server['status'] === 'running'): ?>
                                <span class="status-pill status-running"><span class="status-dot"></span> RUNNING</span>
                            <?php else: ?>
                                <span class="status-pill status-stopped"><span class="status-dot"></span> STOPPED</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-bar" style="margin:0; gap:0.35rem; flex-wrap:wrap;">
                                <button class="btn btn-success btn-sm" onclick="serverService('<?php echo htmlspecialchars($server['id']); ?>','start')">Start</button>
                                <button class="btn btn-warning btn-sm" onclick="serverService('<?php echo htmlspecialchars($server['id']); ?>','restart')">Restart</button>
                                <button class="btn btn-danger btn-sm" onclick="serverService('<?php echo htmlspecialchars($server['id']); ?>','stop')">Stop</button>
                                <button class="btn btn-secondary btn-sm" onclick='editServer(<?php echo json_encode($server, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                                <button class="btn btn-sm" style="border:1px solid #f59e0b; color:#fbbf24; background:rgba(245,158,11,0.1); font-weight:600;"
                                        onclick="restoreServerToLatestCheckpoint('<?php echo htmlspecialchars($server['id']); ?>', '<?php echo htmlspecialchars(addslashes($server['name'])); ?>')"
                                        title="بازگردانی ادمین‌ها و تنظیمات این سرور به آخرین چک‌پوینت">
                                    ↺ Restore
                                </button>
                                <?php if (!$server['protected']): ?>
                                    <button class="btn btn-danger btn-sm" onclick="removeServer('<?php echo htmlspecialchars($server['id']); ?>', false)">Remove</button>
                                    <button class="btn btn-danger btn-sm" onclick="removeServer('<?php echo htmlspecialchars($server['id']); ?>', true)">Purge</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid-cols-2">
    <div class="card">
        <div class="card-title"><span>Add New Server</span></div>
        <div style="display:grid; gap:0.75rem;">
            <input id="newServerId" class="input-field" placeholder="Instance ID, e.g. cs2">
            <input id="newServerName" class="input-field" placeholder="Panel name, e.g. GameLand #2">
            <input id="newServerHostname" class="input-field" placeholder="In-game hostname">
            <div>
                <label style="display:block; margin-bottom:0.3rem; font-size:0.8rem; color:var(--text-muted); font-weight:600;">Game Mode</label>
                <select id="newServerMode" class="input-field" onchange="onNewServerModeChange(this.value)">
                    <option value="clanwar" selected>⚔️ Clan War Mode (10 Slots - /cw, Captain Pick, Knife, Overtime)</option>
                    <option value="mix5v5">🎮 5v5 Match Server (18 Slots - /mix, Auto-Captain, Match Bot)</option>
                    <option value="public">🎯 Classic Public Server (24 Slots - Casual CS 1.6)</option>
                </select>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <input id="newServerPort" class="input-field" type="number" min="1024" max="65535" value="27016" placeholder="Port">
                <input id="newServerSlots" class="input-field" type="number" min="1" max="32" value="10" placeholder="Slots">
            </div>
            <button class="btn btn-primary" onclick="createServer()">Create Instance</button>
            <p style="color:var(--text-muted); font-size:0.82rem; line-height:1.6;">
                Each instance runs with 100% independence: its own directory, port, plugins.ini, slots, systemd service, and tmux console. Switching mode or restarting one server will never disrupt other servers.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><span>Edit Selected Server</span></div>
        <div style="display:grid; gap:0.75rem;">
            <input id="editServerId" class="input-field" readonly placeholder="Select a server from the table">
            <input id="editServerName" class="input-field" placeholder="Panel name">
            <input id="editServerHostname" class="input-field" placeholder="In-game hostname">
            <div>
                <label style="display:block; margin-bottom:0.3rem; font-size:0.8rem; color:var(--text-muted); font-weight:600;">Server Game Mode</label>
                <select id="editServerMode" class="input-field">
                    <option value="">Keep current mode</option>
                    <option value="clanwar">⚔️ Clan War Mode (10 Slots)</option>
                    <option value="mix5v5">🎮 5v5 Match Server (18 Slots)</option>
                    <option value="public">🎯 Classic Public (24 Slots)</option>
                </select>
            </div>
            <div>
                <label style="display:block; margin-bottom:0.3rem; font-size:0.8rem; color:var(--text-muted); font-weight:600;">AFK & Idle Protection</label>
                <select id="editServerAfkGuard" class="input-field">
                    <option value="1">🛡️ Full Guard (Spec after 2m, Kick after 5m)</option>
                    <option value="0">🧪 Disabled (Testing Mode - No Spec, No Kick)</option>
                    <option value="2">👁️ Spec Only (Move to Spec, Never Kick)</option>
                </select>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <input id="editServerPort" class="input-field" type="number" min="1024" max="65535" placeholder="Port">
                <input id="editServerSlots" class="input-field" type="number" min="1" max="32" placeholder="Slots">
            </div>
            <input id="editServerMap" class="input-field" placeholder="Start map, e.g. de_dust2">
            <input id="editServerRcon" class="input-field" placeholder="New RCON password, leave empty to keep current">
            <button class="btn btn-primary" onclick="saveServerSettings()">Save Settings</button>
        </div>
    </div>
</div>

<script>
function postServerApi(action, data = {}) {
    const fd = new FormData();
    fd.append('csrf_token', window.CSRF_TOKEN);
    fd.append('action', action);
    for (const k in data) fd.append(k, data[k]);
    return fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
}

function refreshServers() {
    location.reload();
}

function onNewServerModeChange(mode) {
    const slotsInput = document.getElementById('newServerSlots');
    if (mode === 'clanwar') slotsInput.value = '10';
    else if (mode === 'mix5v5') slotsInput.value = '18';
    else if (mode === 'public') slotsInput.value = '24';
}

function quickChangeServerMode(id, targetMode, name) {
    if (!targetMode) return;
    const modeNames = {
        'clanwar': 'Clan War (10 اسلات)',
        'mix5v5': '5v5 Match (18 اسلات)',
        'public': 'Classic Public (24 اسلات)'
    };
    const label = modeNames[targetMode] || targetMode;
    if (!confirm('آیا مطمئن هستید که می‌خواهید سرور «' + name + '» (' + id + ') را به حالت ' + label + ' تغییر دهید؟\n\n(سایر سرورها کاملاً مجزا بوده و بدون هیچ اختلالی به کار خود ادامه می‌دهند)')) {
        location.reload();
        return;
    }
    postServerApi('switch_server_mode', { server_id: id, target_mode: targetMode })
        .then(d => {
            if (d.success) {
                alert('✅ ' + (d.message || 'حالت سرور با موفقیت تغییر کرد و سرور ری‌استارت شد.'));
                location.reload();
            } else {
                alert('❌ خطا: ' + (d.message || 'عملیات ناموفق بود.'));
            }
        })
        .catch(e => alert('❌ خطای ارتباط: ' + e.message));
}

function quickChangeServerAfkGuard(id, mode, name) {
    if (mode === '' || mode === null || mode === undefined) return;
    const modeLabels = {
        '0': 'غیرفعال (مخصوص تست - بدون کیک و بدون انتقال به اسپکت)',
        '1': 'حفاظت کامل (حالت عادی - انتقال به اسپکت + کیک)',
        '2': 'فقط انتقال به اسپکت (بدون هیچ‌گونه کیک)'
    };
    const label = modeLabels[mode] || mode;
    if (!confirm('آیا مایلید وضعیت AFK سرور «' + name + '» را به «' + label + '» تغییر دهید؟\n\n(این تغییر بدون ری‌استارت و بلافاصله در سرور اعمال می‌شود)')) {
        location.reload();
        return;
    }
    postServerApi('set_afk_guard', { server_id: id, afk_guard: mode })
        .then(d => {
            if (d.success) {
                alert('✅ ' + (d.message || 'وضعیت AFK با موفقیت تغییر کرد.'));
                location.reload();
            } else {
                alert('❌ خطا: ' + (d.message || 'عملیات ناموفق بود.'));
            }
        })
        .catch(e => alert('❌ خطای ارتباط: ' + e.message));
}

function createServer() {
    const id = document.getElementById('newServerId').value.trim();
    const name = document.getElementById('newServerName').value.trim();
    const hostname = document.getElementById('newServerHostname').value.trim() || name;
    const port = document.getElementById('newServerPort').value;
    const slots = document.getElementById('newServerSlots').value;
    const mode = document.getElementById('newServerMode').value;
    if (!id || !port) return alert('Instance ID and port are required.');
    if (!confirm('Create server instance ' + id + ' on port ' + port + ' (Mode: ' + mode + ')?')) return;
    postServerApi('create_server', { server_id: id, name, hostname, port, slots, mode })
        .then(d => {
            alert(d.message || (d.success ? 'Created.' : 'Failed.'));
            if (d.success) location.reload();
        });
}

function editServer(server) {
    document.getElementById('editServerId').value = server.id;
    document.getElementById('editServerName').value = server.name || '';
    document.getElementById('editServerHostname').value = server.hostname || '';
    document.getElementById('editServerPort').value = server.port || '';
    document.getElementById('editServerSlots').value = server.slots || '';
    document.getElementById('editServerMap').value = server.map || '';
    document.getElementById('editServerRcon').value = '';
    document.getElementById('editServerMode').value = server.mode || '';
    document.getElementById('editServerAfkGuard').value = (server.afk_guard !== undefined) ? String(server.afk_guard) : '1';
}

function saveServerSettings() {
    const id = document.getElementById('editServerId').value.trim();
    if (!id) return alert('Select a server first.');
    const payload = {
        server_id: id,
        name: document.getElementById('editServerName').value.trim(),
        hostname: document.getElementById('editServerHostname').value.trim(),
        port: document.getElementById('editServerPort').value,
        slots: document.getElementById('editServerSlots').value,
        map: document.getElementById('editServerMap').value.trim(),
        rcon_password: document.getElementById('editServerRcon').value.trim(),
        mode: document.getElementById('editServerMode').value,
        afk_guard: document.getElementById('editServerAfkGuard').value
    };
    postServerApi('update_server', payload).then(d => {
        alert(d.message || (d.success ? 'Saved.' : 'Failed.'));
        if (d.success) location.reload();
    });
}

function serverService(id, action) {
    if (!confirm(action + ' server ' + id + '?')) return;
    postServerApi('switch_server', { server_id: id })
        .then(() => postServerApi('service_control', { server_id: id, service_action: action }))
        .then(d => {
            alert((d.output ? d.output + '\n' : '') + 'Status: ' + (d.status || 'unknown'));
            location.reload();
        })
        .catch(e => alert('Error: ' + e.message));
}

function removeServer(id, purge) {
    const msg = purge
        ? 'Permanently remove instance ' + id + ' and DELETE all files under its instance directory?'
        : 'Remove instance ' + id + ' from panel and systemd? Files will be kept on disk.';
    if (!confirm(msg)) return;
    if (purge && !confirm('Final confirmation: purge ' + id + ' completely? This cannot be undone from the panel.')) return;
    postServerApi('remove_server', { server_id: id, purge: purge ? '1' : '0' }).then(d => {
        alert(d.message || (d.success ? 'Removed.' : 'Failed.'));
        if (d.success) location.reload();
    });
}

function restoreServerToLatestCheckpoint(serverId, serverName) {
    if (!confirm('⚠️ بازگردانی سرور به آخرین چک‌پوینت؟\n\nآیا مایلید ادمین‌ها و تنظیمات سرور «' + serverName + '» (' + serverId + ') به آخرین چک‌پوینت ثبت‌شده بازگردانده شوند؟\n\n(پلاگین‌ها بدون تغییر باقی خواهند ماند)')) return;

    postServerApi('checkpoint_restore', { checkpoint_id: 'latest', server_id: serverId })
        .then(d => {
            if (d.success) {
                alert('✅ ' + (d.message || 'سرور با موفقیت به آخرین چک‌پوینت بازگردانده شد!'));
                location.reload();
            } else {
                alert('❌ خطا در بازگردانی: ' + (d.message || 'چک‌پوینتی یافت نشد'));
            }
        })
        .catch(e => alert('❌ خطای ارتباط: ' + e.message));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
