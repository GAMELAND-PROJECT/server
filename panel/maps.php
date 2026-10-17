<?php
$pageTitle = 'نقشه‌ها و بازیکنان (Maps & Players)';
$activeNav = 'maps';
require_once __DIR__ . '/includes/header.php';

$maps = ServerCmd::getAvailableMaps($activeServer);

// Query status via RCON to get active player list
$rcon = new GoldSourceRcon($activeServer['ip'], $activeServer['port'], $activeServer['rcon_password'], 2.0);
$statusOutput = '';
$players = [];
$currentMap = 'نامشخص';
$rconError = '';

try {
    $statusOutput = $rcon->execute('status');
    
    // Check for RCON authorization failure or error messages
    if (str_contains($statusOutput, 'Bad rcon_password')) {
        $rconError = 'خطای احراز هویت RCON: رمز عبور RCON سرور در پنل مطابقت ندارد.';
    } elseif (str_contains($statusOutput, 'You have been banned')) {
        $rconError = 'آی‌پی وب‌پنل توسط سرور بن موقت شده است (RCON ban).';
    }

    if (!empty($statusOutput)) {
        // Extract current map name
        if (preg_match('/^map\s*:\s*([^\s]+)/m', $statusOutput, $mm)) {
            $currentMap = $mm[1];
        }

        // Multi-pattern player parsing from status command
        $lines = explode("\n", $statusOutput);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || !str_starts_with($t, '#') || str_contains($t, 'userid') || str_contains($t, 'uniqueid')) {
                continue;
            }

            // Pattern 1: Quoted name -> # 1 "PlayerName" 123 STEAM_0:0:12345 0 00:15 25 0 ...
            if (preg_match('/^#\s*(\d+)\s+"([^"]+)"\s+(\d+)\s+([^\s]+)(?:\s+(-?\d+)\s+([^\s]+)\s+(\d+))?/', $t, $m)) {
                $players[] = [
                    'slot'   => $m[1],
                    'name'   => $m[2],
                    'userid' => $m[3],
                    'auth'   => $m[4],
                    'frags'  => isset($m[5]) ? $m[5] : '0',
                    'time'   => isset($m[6]) ? $m[6] : '—',
                    'ping'   => isset($m[7]) ? $m[7] : '0',
                ];
                continue;
            }

            // Pattern 2: Unquoted name (ReHLDS format) -> #  1 PlayerName 123 STEAM_... 0 00:15 25 0 ...
            if (preg_match('/^#\s*(\d+)\s+(.+?)\s+(\d+)\s+(STEAM_[^\s]+|VALVE_[^\s]+|BOT|[a-zA-Z0-9_:-]+)\s+(-?\d+)\s+([^\s]+)\s+(\d+)/', $t, $m)) {
                $players[] = [
                    'slot'   => $m[1],
                    'name'   => trim($m[2]),
                    'userid' => $m[3],
                    'auth'   => $m[4],
                    'frags'  => $m[5],
                    'time'   => $m[6],
                    'ping'   => $m[7],
                ];
                continue;
            }

            // Pattern 3: Loose format with any tokens starting with #
            if (preg_match('/^#\s*(\d+)\s+(.+)/', $t, $m)) {
                $slot = $m[1];
                $rest = trim($m[2]);
                if (preg_match('/(STEAM_\d+:\d+:\d+|VALVE_\d+:\d+:\d+|BOT)/', $rest, $authMatch, PREG_OFFSET_CAPTURE)) {
                    $auth = $authMatch[0][0];
                    $authOffset = $authMatch[0][1];
                    $beforeAuth = trim(substr($rest, 0, $authOffset));
                    $name = $beforeAuth;
                    $userid = $slot;
                    if (preg_match('/^"([^"]+)"\s+(\d+)$/', $beforeAuth, $bm)) {
                        $name = $bm[1];
                        $userid = $bm[2];
                    } elseif (preg_match('/^(.*?)\s+(\d+)$/', $beforeAuth, $bm)) {
                        $name = trim($bm[1], " \t\n\r\0\x0B\"");
                        $userid = $bm[2];
                    }
                    $players[] = [
                        'slot'   => $slot,
                        'name'   => $name,
                        'userid' => $userid,
                        'auth'   => $auth,
                        'frags'  => '0',
                        'time'   => '—',
                        'ping'   => '0',
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    $statusOutput = 'خطا در ارتباط با سرور: ' . $e->getMessage();
}

// Fallback: If 0 players found from status, query amx_who
if (empty($players) && $serverStatus === 'running') {
    try {
        $whoOutput = $rcon->execute('amx_who');
        if (!empty($whoOutput) && !str_contains($whoOutput, 'Unknown command')) {
            $whoLines = explode("\n", $whoOutput);
            foreach ($whoLines as $wLine) {
                $wt = trim($wLine);
                if (preg_match('/^(\d+)\s+([^\s]+)\s+(STEAM_[^\s]+|VALVE_[^\s]+|[^\s]+)\s+(\d+)/', $wt, $wm)) {
                    if ($wm[2] !== 'nick' && $wm[3] !== 'authid') {
                        $players[] = [
                            'slot'   => $wm[1],
                            'name'   => $wm[2],
                            'auth'   => $wm[3],
                            'userid' => $wm[4],
                            'frags'  => '—',
                            'time'   => '—',
                            'ping'   => '—',
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {}
}
?>

<?php if ($rconError): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.25rem;">
        ⚠️ <?php echo htmlspecialchars($rconError); ?>
    </div>
<?php endif; ?>

<!-- Top Info Banner -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.6rem; margin: 0;">
            <span>🗺️ نقشه‌ها و بازیکنان آنلاین (Maps & Players)</span>
            <span class="badge" style="background: <?php echo ($serverStatus === 'running') ? '#059669' : '#dc2626'; ?>; font-size: 0.82rem; padding: 0.25rem 0.65rem; border-radius: 999px;">
                <?php echo ($serverStatus === 'running') ? '🟢 سرور آنلاین' : '🔴 سرور خاموش'; ?>
            </span>
        </h2>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.3rem; margin-bottom: 0;">
            مپ در حال اجرا: <strong style="color: #38bdf8;"><?php echo htmlspecialchars($currentMap); ?></strong> &bull; 
            تعداد بازیکنان حاضر: <strong style="color: #34d399;"><?php echo count($players); ?> نفر</strong>
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <button onclick="location.reload()" class="btn btn-secondary">
            🔄 بروزرسانی زنده
        </button>
    </div>
</div>

<div class="grid-cols-2" style="align-items: start; gap: 1.5rem;">

    <!-- Left Column: Active Players Management -->
    <div class="card">
        <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <span>👥 بازیکنان حاضر در بازی (<?php echo count($players); ?>)</span>
            <span style="font-size: 0.78rem; color: var(--text-muted);">سرور: <?php echo htmlspecialchars($activeServer['name']); ?></span>
        </div>

        <div class="table-responsive">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="width: 55px;">#ID</th>
                        <th>نام بازیکن (Nickname)</th>
                        <th>استیم‌آیدی (SteamID / Auth)</th>
                        <th>فرگ</th>
                        <th>زمان</th>
                        <th>پینگ</th>
                        <th style="text-align: center; width: 110px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($players)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                <?php if ($serverStatus !== 'running'): ?>
                                    ⚠️ سرور در حال حاضر متوقف است (Offline).
                                <?php else: ?>
                                    ℹ️ در حال حاضر هیچ بازیکنی به سرور متصل نیست (۰ بازیکن آنلاین).
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($players as $p): 
                            $authStr = (string)$p['auth'];
                            $isSteam = str_starts_with($authStr, 'STEAM_') || str_starts_with($authStr, 'VALVE_');
                        ?>
                            <tr>
                                <td><code style="color: #94a3b8;">#<?php echo htmlspecialchars($p['userid']); ?></code></td>
                                <td><strong style="color: #fff; font-size: 0.95rem;"><?php echo htmlspecialchars($p['name']); ?></strong></td>
                                <td>
                                    <code style="color: <?php echo $isSteam ? '#38bdf8' : '#cbd5e1'; ?>; font-weight: bold; font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($p['auth']); ?>
                                    </code>
                                    <?php if ($isSteam): ?>
                                        <button type="button" onclick="copySteamId('<?php echo htmlspecialchars(addslashes($p['auth'])); ?>')" 
                                                class="btn btn-secondary btn-sm" style="font-size: 0.65rem; padding: 0.1rem 0.35rem; margin-right: 0.3rem;" title="کپی استیم‌آیدی">
                                            📋
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color: #f59e0b; font-weight: bold;"><?php echo htmlspecialchars($p['frags']); ?></span></td>
                                <td style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($p['time']); ?></td>
                                <td>
                                    <?php if ($p['ping'] !== '—'): ?>
                                        <span style="color: #34d399;"><?php echo htmlspecialchars($p['ping']); ?> ms</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <button onclick="kickPlayer('<?php echo $p['userid']; ?>', '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')" 
                                            class="btn btn-danger btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.78rem;" title="کیک بازیکن از سرور">
                                        👢 کیک
                                    </button>
                                    <button onclick="banPlayer('<?php echo $p['userid']; ?>', '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')" 
                                            class="btn btn-danger btn-sm" style="padding: 0.2rem 0.5rem; font-size: 0.78rem; background: #991b1b;" title="بن بازیکن">
                                        🚫 بن
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Raw Console Status Accordion -->
        <details style="margin-top: 1rem; background: rgba(15, 23, 42, 0.5); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid var(--border-color); font-size: 0.8rem;">
            <summary style="cursor: pointer; color: var(--text-muted);">🔍 مشاهده پاسخ خام دستور status سرور (Debug Log)</summary>
            <pre style="background: #020617; padding: 0.75rem; border-radius: 4px; color: #94a3b8; font-family: monospace; font-size: 0.75rem; overflow-x: auto; margin-top: 0.5rem; max-height: 200px;"><?php echo htmlspecialchars($statusOutput ?: 'بدون پاسخ از سرور.'); ?></pre>
        </details>
    </div>

    <!-- Right Column: Map Rotation & Map Switching -->
    <div class="card">
        <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
            <span>🗺️ تغییر نقشه سرور (Change Map)</span>
            <button onclick="promptCustomMap()" class="btn btn-primary btn-sm">تایپ مپ دلخواه...</button>
        </div>

        <div style="margin-bottom: 0.75rem;">
            <input type="text" id="mapSearchInput" onkeyup="filterMaps()" placeholder="🔍 فیلتر مپ‌ها..." 
                   class="input-field" style="width: 100%; font-size: 0.85rem; padding: 0.4rem 0.65rem;">
        </div>

        <p style="color: var(--text-muted); font-size: 0.82rem; margin-bottom: 0.75rem;">
            روی هر مپ کلیک کنید تا بلافاصله سرور بدون خاموش شدن تغییر نقشه دهد:
        </p>

        <div id="mapsGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 0.5rem; max-height: 380px; overflow-y: auto; padding-right: 0.25rem;">
            <?php foreach ($maps as $mName): 
                $isCurrent = (strcasecmp($mName, $currentMap) === 0);
            ?>
                <button type="button" onclick="confirmChangeMap('<?php echo htmlspecialchars($mName); ?>')" 
                        class="btn <?php echo $isCurrent ? 'btn-primary' : 'btn-secondary'; ?> btn-sm map-btn" 
                        data-map="<?php echo htmlspecialchars(strtolower($mName)); ?>"
                        style="justify-content: flex-start; text-align: left; padding: 0.4rem 0.6rem; font-size: 0.82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?php echo $isCurrent ? '📍' : '🗺'; ?> <?php echo htmlspecialchars($mName); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function copySteamId(steamid) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(steamid).then(() => {
            alert('استیم‌آیدی کپی شد: ' + steamid);
        });
    } else {
        prompt('استیم‌آیدی:', steamid);
    }
}

async function kickPlayer(userid, name) {
    if (confirm(`آیا از کیک کردن بازیکن "${name}" (#${userid}) اطمینان دارید؟`)) {
        const res = await postApi('rcon_command', { command: `kick #${userid}` });
        if (res.success) {
            alert(`بازیکن "${name}" کیک شد.`);
            location.reload();
        } else {
            alert('خطا در ارسال دستور: ' + res.message);
        }
    }
}

async function banPlayer(userid, name) {
    const mins = prompt(`تعداد دقایق بن برای بازیکن "${name}" را وارد کنید (مثال: 60 یا 0 برای دائمی):`, '60');
    if (mins !== null && mins !== '') {
        const res = await postApi('rcon_command', { command: `amx_ban #${userid} ${mins} "Banned via Panel"` });
        if (res.success) {
            alert(`دستور بن بازیکن "${name}" با موفقیت ارسال شد.`);
            location.reload();
        } else {
            alert('خطا در ارسال دستور: ' + res.message);
        }
    }
}

async function confirmChangeMap(mapName) {
    if (confirm(`آیا می‌خواهید مپ سرور بلافاصله به "${mapName}" تغییر کند؟`)) {
        const res = await postApi('rcon_command', { command: `changelevel ${mapName}` });
        if (res.success) {
            alert(`دستور تغییر مپ به "${mapName}" ارسال شد! صفحه تا ۵ ثانیه دیگر رفرش می‌شود.`);
            setTimeout(() => location.reload(), 5000);
        } else {
            alert('خطا: ' + res.message);
        }
    }
}

async function promptCustomMap() {
    const mapName = prompt('نام دقیق مپ را وارد کنید (مثال: de_dust2 یا cs_assault):');
    if (mapName && mapName.trim().length > 0) {
        confirmChangeMap(mapName.trim());
    }
}

function filterMaps() {
    const filter = document.getElementById('mapSearchInput').value.toLowerCase().trim();
    const btns = document.querySelectorAll('#mapsGrid .map-btn');
    btns.forEach(btn => {
        const map = btn.getAttribute('data-map') || '';
        if (map.includes(filter)) {
            btn.style.display = 'flex';
        } else {
            btn.style.display = 'none';
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
