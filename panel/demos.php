<?php
$pageTitle = 'Match Demos (HLTV)';
$activeNav = 'demos';
require_once __DIR__ . '/includes/header.php';

$serverDir = dirname(__DIR__);
$cstrikeDir = $serverDir . '/cstrike';
$demosDir = __DIR__ . '/demos/';
$compressScript = $serverDir . '/compress_demos.sh';

// Ensure demos directory exists
if (!is_dir($demosDir)) {
    @mkdir($demosDir, 0777, true);
    @chmod($demosDir, 0777);
}

// Handle manual trigger scan request from web
$syncMessage = '';
if (isset($_GET['sync'])) {
    if (file_exists($compressScript)) {
        $output = @shell_exec('bash ' . escapeshellarg($compressScript) . ' --once 2>&1');
        $syncMessage = 'Demos scanned and synchronized successfully!';
    }
}

// Check live recording status
$isRecording = false;
$activeDemoName = '';
$recordingMarker = $cstrikeDir . '/hltv_recording.txt';
if (file_exists($recordingMarker)) {
    $isRecording = true;
    $activeDemoName = trim((string)@file_get_contents($recordingMarker));
}

// Check for pending raw demos in cstrike
$pendingDemos = [];
if (is_dir($cstrikeDir)) {
    $rawFiles = @glob($cstrikeDir . '/GL_*.dem');
    if (!empty($rawFiles)) {
        foreach ($rawFiles as $rf) {
            $pendingDemos[] = basename($rf);
        }
    }
}

// Read compressed demos from panel/demos/
$demos = [];
if (is_dir($demosDir)) {
    $files = scandir($demosDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === 'zip' || $ext === 'dem') {
                $filePath = $demosDir . $file;
                
                // Extract map if present in filename
                $mapName = 'Unknown';
                if (preg_match('/(de_[a-zA-Z0-9_]+|cs_[a-zA-Z0-9_]+)/i', $file, $m)) {
                    $mapName = strtolower($m[1]);
                }

                $demos[] = [
                    'name' => $file,
                    'map'  => $mapName,
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

<?php if ($syncMessage): ?>
<div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
    <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($syncMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row mb-3">
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <?php if ($isRecording): ?>
                <span class="badge bg-danger p-2 shadow-sm fs-6">
                    <i class="fas fa-circle fa-beat me-1"></i> HLTV Recording Live: <strong><?= htmlspecialchars($activeDemoName) ?></strong>
                </span>
            <?php else: ?>
                <span class="badge bg-success p-2 shadow-sm fs-6">
                    <i class="fas fa-video me-1"></i> HLTV Ready (Port 27020)
                </span>
            <?php endif; ?>

            <?php if (!empty($pendingDemos) && !$isRecording): ?>
                <span class="badge bg-warning text-dark p-2 ms-2 shadow-sm fs-6">
                    <i class="fas fa-hourglass-half me-1"></i> <?= count($pendingDemos) ?> demo(s) waiting in queue
                </span>
            <?php endif; ?>
        </div>

        <div>
            <a href="demos.php?sync=1" class="btn btn-primary btn-sm shadow-sm">
                <i class="fas fa-sync-alt me-1"></i> Refresh & Sync Demos
            </a>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-film me-2"></i> HLTV Match Demos</h5>
                <span class="badge bg-info text-dark rounded-pill"><?= count($demos) ?> Available</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 text-center align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Match / Demo Name</th>
                                <th>Map</th>
                                <th>Format</th>
                                <th>File Size</th>
                                <th>Recorded Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($demos)): ?>
                            <tr>
                                <td colspan="6" class="text-muted py-5">
                                    <i class="fas fa-video-slash fa-2x mb-3 text-secondary d-block"></i>
                                    <h5>No demos found yet</h5>
                                    <p class="mb-0 text-secondary">
                                        Start a 5v5 match with <code>.start</code> or manually test using <code>hs1</code> (start) and <code>hs0</code> (stop) in game console!
                                    </p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($demos as $demo): ?>
                                <tr>
                                    <td class="fw-bold text-start ps-3 text-primary">
                                        <i class="fas fa-file-video me-2 text-secondary"></i>
                                        <?= htmlspecialchars($demo['name']) ?>
                                    </td>
                                    <td>
                                        <?php if ($demo['map'] !== 'Unknown'): ?>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($demo['map']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($demo['ext'] === 'zip'): ?>
                                            <span class="badge bg-success"><i class="fas fa-file-archive me-1"></i> ZIP</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-film me-1"></i> DEM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-dark"><?= $demo['size'] ?></span></td>
                                    <td><small class="text-muted"><?= $demo['time'] ?></small></td>
                                    <td>
                                        <a href="demos/<?= urlencode($demo['name']) ?>" class="btn btn-sm btn-success shadow-sm px-3" download>
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
            <div class="card-footer bg-light text-muted small d-flex justify-content-between">
                <span><i class="fas fa-info-circle me-1"></i> Demos are recorded by HLTV proxy and compressed automatically upon match end or <code>hs0</code>.</span>
                <span>Location: <code>panel/demos/</code></span>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
