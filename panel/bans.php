<?php
$pageTitle = 'Ban Manager';
$activeNav = 'bans';
require_once __DIR__ . '/includes/header.php';

$msg = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid security token.';
        $msgType = 'danger';
    } elseif (isset($_POST['remove_ban'])) {
        $res = ServerCmd::removeBan($activeServer, $_POST['ban_type'] ?? '', $_POST['ban_target'] ?? '');
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'danger';
    } elseif (isset($_POST['remove_avg_bans'])) {
        $res = ServerCmd::removeAvgBans($activeServer);
        $msg = $res['message'];
        $msgType = $res['success'] ? 'success' : 'warning';
    }
}

$bans = ServerCmd::getBanList($activeServer);
$avgCount = count(array_filter($bans, fn($b) => $b['is_avg']));
?>

<?php if ($msg): ?>
    <div class="alert alert-<?php echo htmlspecialchars($msgType); ?>"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-title">
        <span>Ban Manager</span>
        <div style="display:flex; gap:.5rem; align-items:center;">
            <span class="status-pill <?php echo $avgCount ? 'status-stopped' : 'status-running'; ?>">
                <span class="status-dot"></span><?php echo (int)$avgCount; ?> AVG
            </span>
            <button onclick="location.reload()" class="btn btn-secondary btn-sm">Refresh</button>
        </div>
    </div>

    <div style="color:var(--text-muted);font-size:.86rem;line-height:1.6;margin-bottom:1rem;">
        Reads real engine bans from <code>listip.cfg</code> and <code>banned.cfg</code>. Bans whose recent log context contains <code>avg</code> are highlighted.
    </div>

    <?php if ($avgCount > 0): ?>
        <form method="POST" style="margin-bottom:1rem;" onsubmit="return confirm('Remove all bans that look related to avg/ping detection on this server?')">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <button type="submit" name="remove_avg_bans" class="btn btn-warning">Remove All AVG Bans</button>
        </form>
    <?php endif; ?>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Target</th>
                    <th>Duration</th>
                    <th>Reason</th>
                    <th>Source</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bans)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--text-muted);padding:1.5rem;">
                            No bans found for this server.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bans as $ban): ?>
                        <tr style="<?php echo $ban['is_avg'] ? 'background:rgba(245,158,11,.08);' : ''; ?>">
                            <td><code><?php echo htmlspecialchars(strtoupper($ban['type'])); ?></code></td>
                            <td><strong><?php echo htmlspecialchars($ban['target']); ?></strong></td>
                            <td><?php echo ((float)$ban['minutes'] <= 0) ? 'Permanent' : htmlspecialchars($ban['minutes']) . ' min'; ?></td>
                            <td>
                                <?php if ($ban['is_avg']): ?>
                                    <span style="color:var(--warning);font-weight:700;">avg / ping</span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">-</span>
                                <?php endif; ?>
                                <?php if (!empty($ban['log_line'])): ?>
                                    <div style="font-size:.75rem;color:var(--text-muted);max-width:420px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($ban['log_line']); ?>">
                                        <?php echo htmlspecialchars($ban['log_line']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars(basename($ban['file'])); ?></code></td>
                            <td>
                                <form method="POST" onsubmit="return confirm('Unban <?php echo htmlspecialchars($ban['target']); ?>?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
                                    <input type="hidden" name="ban_type" value="<?php echo htmlspecialchars($ban['type']); ?>">
                                    <input type="hidden" name="ban_target" value="<?php echo htmlspecialchars($ban['target']); ?>">
                                    <button type="submit" name="remove_ban" class="btn btn-danger btn-sm">Unban</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
