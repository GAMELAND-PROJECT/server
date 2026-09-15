<?php
$pageTitle = 'Maps & Players';
$activeNav = 'maps';
require_once __DIR__ . '/includes/header.php';

$maps = ServerCmd::getAvailableMaps($activeServer);

// Query status via RCON to get active player list
$rcon = new GoldSourceRcon($activeServer['ip'], $activeServer['port'], $activeServer['rcon_password'], 1.5);
$statusOutput = '';
$players = [];

try {
    $statusOutput = $rcon->execute('status');
    // Parse players from status command:
    // # 1 "PlayerName" 1234 STEAM_0:0:12345 0 00:15 25 0
    if (!empty($statusOutput)) {
        $lines = explode("\n", $statusOutput);
        foreach ($lines as $line) {
            if (preg_match('/^#\s*(\d+)\s+"([^"]+)"\s+(\d+)\s+([^\s]+)\s+(\d+)\s+([^\s]+)\s+(\d+)/', trim($line), $m)) {
                $players[] = [
                    'userid' => $m[1],
                    'name'   => $m[2],
                    'auth'   => $m[4],
                    'time'   => $m[6],
                    'ping'   => $m[7],
                ];
            }
        }
    }
} catch (Exception $e) {
    $statusOutput = 'Could not query server status.';
}
?>

<div class="grid-cols-2">
    <!-- Active Players Management -->
    <div class="card">
        <div class="card-title">
            <span>Active Players Online (<?php echo count($players); ?>)</span>
            <button onclick="location.reload()" class="btn btn-secondary btn-sm">Refresh List</button>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#ID</th>
                        <th>Nickname</th>
                        <th>SteamID / Auth</th>
                        <th>Ping</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($players)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">
                                No players currently connected or server offline.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($players as $p): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($p['userid']); ?></td>
                                <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($p['auth']); ?></code></td>
                                <td><?php echo htmlspecialchars($p['ping']); ?> ms</td>
                                <td>
                                    <button onclick="kickPlayer('<?php echo $p['userid']; ?>', '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')" class="btn btn-danger btn-sm">Kick</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Map Rotation & Map Switching -->
    <div class="card">
        <div class="card-title">
            <span>Change Map</span>
            <button onclick="changeMap('')" class="btn btn-primary btn-sm">Custom Map...</button>
        </div>

        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
            Click any map below to immediately change the server map via RCON:
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 0.5rem; max-height: 340px; overflow-y: auto; padding-right: 0.5rem;">
            <?php foreach ($maps as $mName): ?>
                <button onclick="changeMap('<?php echo htmlspecialchars($mName); ?>')" class="btn btn-secondary btn-sm" style="justify-content: flex-start;">
                    🗺 <?php echo htmlspecialchars($mName); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
