<?php
$pageTitle = 'Admin Manager';
$activeNav = 'admins';
require_once __DIR__ . '/includes/header.php';

$msg = '';
$msgType = '';

// Handle Add / Edit Admin Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_admin'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $auth = trim($_POST['admin_auth'] ?? '');
        $password = trim($_POST['admin_pass'] ?? '');
        $access = trim($_POST['admin_access'] ?? '');
        $flags = trim($_POST['admin_flags'] ?? '');
        $comment = trim($_POST['admin_comment'] ?? '');

        $applyAll = ($_POST['apply_all_servers'] ?? '0') === '1';
        $res = ServerCmd::saveAdminEverywhere($auth, $password, $access, $flags, $comment, $applyAll, $activeServer['id']);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'warning';

    }
}

// Handle Delete Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $auth = trim($_POST['delete_auth'] ?? '');
        $applyAll = ($_POST['apply_all_servers'] ?? '0') === '1';
        $res = ServerCmd::deleteAdminEverywhere($auth, $applyAll, $activeServer['id']);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'warning';

    }
}

// Handle Manual Live Reload Admins Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reload_admins'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token!';
        $msgType = 'danger';
    } else {
        $reloadRes = ServerCmd::reloadAdminsLive($activeServer);
        $msg = $reloadRes['message'];
        $msgType = $reloadRes['success'] ? 'success' : 'danger';
    }
}

$admins = ServerCmd::getAdmins($activeServer['users_ini']);
?>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>


<div class="grid-cols-2">
    <!-- Existing Admins List -->
    <div class="card">
        <div class="card-title">
            <span>Server Admins (<?php echo count($admins); ?>)</span>
            <div style="display: flex; gap: 0.5rem;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
                    <button type="submit" name="reload_admins" class="btn btn-warning btn-sm" title="Send amx_reloadadmins to live server immediately">
                        ⚡ Reload on Server
                    </button>
                </form>
                <button onclick="location.reload()" class="btn btn-secondary btn-sm">Refresh</button>
            </div>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Identity (Auth)</th>
                        <th>Access</th>
                        <th>Flag</th>
                        <th>Comment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($admins)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">
                                No admins found in users.ini. Add one using the form.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($admins as $adm): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($adm['auth']); ?></strong></td>
                                <td><code style="color: var(--accent);"><?php echo htmlspecialchars($adm['access']); ?></code></td>
                                <td><code><?php echo htmlspecialchars($adm['flags']); ?></code></td>
                                <td style="color: var(--text-muted); font-size: 0.8rem;"><?php echo htmlspecialchars($adm['comment']); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remove admin <?php echo htmlspecialchars($adm['auth']); ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
                                        <input type="hidden" name="delete_auth" value="<?php echo htmlspecialchars($adm['auth']); ?>">
                                        <button type="submit" name="delete_admin" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this admin from every managed server?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
                                        <input type="hidden" name="delete_auth" value="<?php echo htmlspecialchars($adm['auth']); ?>">
                                        <input type="hidden" name="apply_all_servers" value="1">
                                        <button type="submit" name="delete_admin" class="btn btn-danger btn-sm">Delete All</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add / Edit Admin Form -->
    <div class="card">
        <div class="card-title">
            <span>Add / Edit Admin</span>
        </div>

        <form method="POST" action="admins.php">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">

            <div class="form-group">
                <label for="admin_auth">Nickname, SteamID, or IP Address *</label>
                <input type="text" id="admin_auth" name="admin_auth" class="input-field" style="width: 100%;" placeholder="e.g. PlayerName or STEAM_0:0:12345678" required>
            </div>

            <div class="form-group">
                <label for="admin_pass">Password (Leave empty if Auth is SteamID)</label>
                <input type="text" id="admin_pass" name="admin_pass" class="input-field" style="width: 100%;" placeholder="e.g. MySecretPass">
            </div>

            <div class="form-group">
                <label for="admin_access">Access Flags * (Default Full Admin: <code>abcdefghijklmnopqrstu</code>)</label>
                <input type="text" id="admin_access" name="admin_access" class="input-field" style="width: 100%;" value="abcdefghijklmnopqrstu" required>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.3rem;">
                    a: Immunity | b: Reservation | c: Kick | d: Ban/Unban | e: Slay/Slap | l: RCON | u: Menu Access
                </div>
            </div>

            <div class="form-group">
                <label for="admin_flags">Account Flags *</label>
                <select id="admin_flags" name="admin_flags" class="input-field" style="width: 100%;">
                    <option value="a">a - Disconnect player on invalid password (for Nickname auth)</option>
                    <option value="ce" selected>ce - SteamID (no password required)</option>
                    <option value="de">de - IP Address (no password required)</option>
                    <option value="ab">ab - Nickname with Clan Tag password</option>
                </select>
            </div>

            <div class="form-group">
                <label for="admin_comment">Note / Comment</label>
                <input type="text" id="admin_comment" name="admin_comment" class="input-field" style="width: 100%;" placeholder="e.g. Head Admin or VIP">
            </div>

            <label style="display:flex;align-items:center;gap:.5rem;margin:.5rem 0 1rem;">
                <input type="checkbox" name="apply_all_servers" value="1">
                Apply this admin to every managed server
            </label>
            <button type="submit" name="save_admin" class="btn btn-primary" style="width: 100%;">Save Admin</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
