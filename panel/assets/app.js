/**
 * GameLand Web Panel Interactive Client
 */

// Helper: Post to api.php
async function postApi(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', window.CSRF_TOKEN || '');
    for (const key in data) {
        formData.append(key, data[key]);
    }

    try {
        const res = await fetch('api.php', {
            method: 'POST',
            body: formData
        });
        return await res.json();
    } catch (err) {
        return { success: false, message: 'Network error or server unreachable.' };
    }
}

// Service Control (Start / Stop / Restart)
async function controlService(action) {
    if (action === 'stop' && !confirm('Are you sure you want to STOP the server?')) {
        return;
    }

    const btn = document.activeElement;
    const oldText = btn ? btn.innerText : '';
    if (btn) btn.innerText = 'Processing...';

    const res = await postApi('service_control', { service_action: action });
    if (btn) btn.innerText = oldText;

    if (res.success) {
        alert('Action (' + action + ') executed: ' + res.status);
        location.reload();
    } else {
        alert('Error: ' + res.message);
    }
}

// Quick helper to run any RCON command with confirmation or notification
async function runRcon(cmd, promptMsg = '') {
    if (promptMsg && !confirm(promptMsg)) {
        return;
    }
    const res = await postApi('rcon_command', { command: cmd });
    if (res.success) {
        alert('Command [' + cmd + '] sent successfully!');
    } else {
        alert('Error: ' + res.message);
    }
}

// Send RCON Command from Console
async function sendRconCommand() {
    const input = document.getElementById('rconCommandInput');
    const consoleBox = document.getElementById('consoleBox');
    if (!input || !consoleBox) return;

    const cmd = input.value.trim();
    if (!cmd) return;

    consoleBox.innerHTML += `\n<span style="color: #93c5fd;">> ${cmd}</span>\n`;
    consoleBox.scrollTop = consoleBox.scrollHeight;
    input.value = '';

    const res = await postApi('rcon_command', { command: cmd });
    if (res.success) {
        const responseText = res.response ? res.response : '(Command sent, no output)';
        consoleBox.innerHTML += `<span style="color: #6ee7b7;">${escapeHtml(responseText)}</span>\n`;
    } else {
        consoleBox.innerHTML += `<span style="color: #fca5a5;">Error: ${escapeHtml(res.message)}</span>\n`;
    }
    consoleBox.scrollTop = consoleBox.scrollHeight;
}

// Switch active server instance
async function switchServer(serverId) {
    const res = await postApi('switch_server', { server_id: serverId });
    if (res.success) {
        location.reload();
    }
}

// Quick action: Restart Round (sv_restart 1)
async function restartRound() {
    const res = await postApi('rcon_command', { command: 'sv_restart 1' });
    if (res.success) {
        alert('Round restart command issued (sv_restart 1)!');
    } else {
        alert('Error: ' + res.message);
    }
}

// Quick action: Change Map
async function changeMap(mapName) {
    if (!mapName) {
        mapName = prompt('Enter map name (e.g. de_dust2, de_inferno):');
    }
    if (!mapName) return;

    if (confirm(`Change map to "${mapName}" now?`)) {
        const res = await postApi('rcon_command', { command: `changelevel ${mapName}` });
        if (res.success) {
            alert(`Map changed to ${mapName}!`);
            setTimeout(() => location.reload(), 3000);
        } else {
            alert('Error: ' + res.message);
        }
    }
}

// Kick player via RCON
async function kickPlayer(userid, name) {
    if (confirm(`Are you sure you want to kick player "${name}" (#${userid})?`)) {
        const res = await postApi('rcon_command', { command: `kick #${userid}` });
        if (res.success) {
            alert(`Player kicked.`);
            location.reload();
        } else {
            alert('Error: ' + res.message);
        }
    }
}

// Escape HTML utility
function escapeHtml(string) {
    const entityMap = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };
    return String(string).replace(/[&<>"']/g, s => entityMap[s]);
}

// Event listener for enter key in console input
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('rconCommandInput');
    if (input) {
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                sendRconCommand();
            }
        });
    }
});
