<?php
$pageTitle = 'Match Demos (HLTV)';
$activeNav = 'demos';
require_once __DIR__ . '/includes/header.php';

$demosDir = __DIR__ . '/demos/';
$demos = [];

// Handle manual trigger scan request from web
if (isset($_GET['sync'])) {
    $script = dirname(__DIR__) . '/compress_demos.sh';
    if (file_exists($script)) {
        @shell_exec('bash ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
    }
}

if (is_dir($demosDir)) {
    $files = scandir($demosDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === 'zip' || $ext === 'dem') {
                $filePath = $demosDir . $file;
                $demos[] = [
                    'name' => $file,
                    'ext'  => $ext,
                    'size' => round(filesize($filePath) / 1024 / 1024, 2) . ' MB',
                    'time' => date("Y-m-d H:i:s", filemtime($filePath))
                ];
            }
        }
    }
}
// Sort newest first
usort($demos, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});
?>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-video me-2"></i> HLTV Match Demos</h5>
                <div>
                    <a href="demos.php?sync=1" class="btn btn-sm btn-outline-info me-2">
                        <i class="fas fa-sync-alt"></i> Refresh & Sync Demos
                    </a>
                    <span class="badge bg-primary rounded-pill"><?= count($demos) ?> Demos</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Match Name</th>
                                <th>Format</th>
                                <th>File Size</th>
                                <th>Date / Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($demos)): ?>
                            <tr>
                                <td colspan="5" class="text-muted py-4">
                                    <i class="fas fa-info-circle me-1"></i> No demos found yet in <code>panel/demos/</code>. Run a match or use <code>/hs1</code> and <code>/hs0</code>!
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($demos as $demo): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($demo['name']) ?></td>
                                    <td>
                                        <?php if ($demo['ext'] === 'zip'): ?>
                                            <span class="badge bg-success"><i class="fas fa-file-archive me-1"></i> ZIP</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-film me-1"></i> DEM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-dark"><?= $demo['size'] ?></span></td>
                                    <td><?= $demo['time'] ?></td>
                                    <td>
                                        <a href="demos/<?= urlencode($demo['name']) ?>" class="btn btn-sm btn-success shadow-sm" download>
                                            <i class="fas fa-download me-1"></i> Download
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light text-muted small">
                Demos are automatically compressed after the match winner is declared.
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
