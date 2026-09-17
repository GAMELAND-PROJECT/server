<?php
$pageTitle = 'Match Demos (HLTV)';
$activeNav = 'demos';
require_once __DIR__ . '/includes/header.php';

$demosDir = __DIR__ . '/demos/';
$demos = [];

if (is_dir($demosDir)) {
    $files = scandir($demosDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
            $filePath = $demosDir . $file;
            $demos[] = [
                'name' => $file,
                'size' => round(filesize($filePath) / 1024 / 1024, 2) . ' MB',
                'time' => date("Y-m-d H:i:s", filemtime($filePath))
            ];
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
                <span class="badge bg-primary rounded-pill"><?= count($demos) ?> Demos</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Match Name</th>
                                <th>File Size</th>
                                <th>Date / Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($demos)): ?>
                            <tr>
                                <td colspan="4" class="text-muted py-4">No demos found. Start a mix to record one!</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($demos as $demo): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($demo['name']) ?></td>
                                    <td><span class="badge bg-secondary"><?= $demo['size'] ?></span></td>
                                    <td><?= $demo['time'] ?></td>
                                    <td>
                                        <a href="demos/<?= urlencode($demo['name']) ?>" class="btn btn-sm btn-success shadow-sm" download>
                                            <i class="fas fa-download"></i> Download
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
