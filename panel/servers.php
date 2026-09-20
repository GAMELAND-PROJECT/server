<?php
$pageTitle = 'Server Instances';
$activeNav = 'servers';
require_once __DIR__ . '/includes/header.php';

$servers = ServerCmd::getServerList($SERVERS);
?>

<div class="card">
    <div class="card-title">
        <span>Server Instances</span>
        <button onclick="refreshServers()" class="btn btn-secondary btn-sm">Refresh</button>
    </div>

    <div style="overflow-x:auto;">
        <table id="serversTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name / Hostname</th>
                    <th>Port</th>
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
                            <div class="action-bar" style="margin:0; gap:0.35rem;">
                                <button class="btn btn-success btn-sm" onclick="serverService('<?php echo htmlspecialchars($server['id']); ?>','start')">Start</button>
                                <button class="btn btn-warning btn-sm" onclick="serverService('<?php echo htmlspecialchars($server['id']); ?>','restart')">Restart</button>
                                <button class="btn btn-danger btn-sm" onclick="serverService('<?php echo htmlspecialchars($server['id']); ?>','stop')">Stop</button>
                                <button class="btn btn-secondary btn-sm" onclick='editServer(<?php echo json_encode($server, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
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
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <input id="newServerPort" class="input-field" type="number" min="1024" max="65535" value="27016" placeholder="Port">
                <input id="newServerSlots" class="input-field" type="number" min="1" max="32" value="12" placeholder="Slots">
            </div>
            <button class="btn btn-primary" onclick="createServer()">Create Instance</button>
            <p style="color:var(--text-muted); font-size:0.82rem; line-height:1.6;">
                Each instance gets its own directory, systemd service, tmux console, port and log file. Remove disables it and keeps files. Purge disables it and deletes its instance directory completely.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><span>Edit Selected Server</span></div>
        <div style="display:grid; gap:0.75rem;">
            <input id="editServerId" class="input-field" readonly placeholder="Select a server from the table">
            <input id="editServerName" class="input-field" placeholder="Panel name">
            <input id="editServerHostname" class="input-field" placeholder="In-game hostname">
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

function createServer() {
    const id = document.getElementById('newServerId').value.trim();
    const name = document.getElementById('newServerName').value.trim();
    const hostname = document.getElementById('newServerHostname').value.trim() || name;
    const port = document.getElementById('newServerPort').value;
    const slots = document.getElementById('newServerSlots').value;
    if (!id || !port) return alert('Instance ID and port are required.');
    if (!confirm('Create server instance ' + id + ' on port ' + port + '?')) return;
    postServerApi('create_server', { server_id: id, name, hostname, port, slots })
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
        rcon_password: document.getElementById('editServerRcon').value.trim()
    };
    postServerApi('update_server', payload).then(d => {
        alert(d.message || (d.success ? 'Saved.' : 'Failed.'));
        if (d.success) location.reload();
    });
}

function serverService(id, action) {
    if (!confirm(action + ' server ' + id + '?')) return;
    postServerApi('switch_server', { server_id: id })
        .then(() => postServerApi('service_control', { service_action: action }))
        .then(d => {
            alert((d.output ? d.output + '\n' : '') + 'Status: ' + (d.status || 'unknown'));
            location.reload();
        });
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
