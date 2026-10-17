<?php
/**
 * GameLand CS 1.6 Web Panel - Admin Manager
 * Full AJAX + Fallback POST support, zero hangs, instant live server reload
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Rcon.class.php';
require_once __DIR__ . '/includes/ServerCmd.php';

Auth::check();
$activeServer = get_active_server();
$serverStatus = ServerCmd::getServiceStatus($activeServer['service_name']);

$msg = '';
$msgType = '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['ajax']);

// ─────────────────────────────────────────────────────────────
// 1. Handle Add / Edit Admin POST Action
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_admin']) || !empty($_POST['admin_auth']))) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'خطای امنیتی: توکن CSRF نامعتبر یا منقضی شده است. لطفاً صفحه را مجدداً بارگذاری کنید.';
        $msgType = 'danger';
    } else {
        $auth = trim($_POST['admin_auth'] ?? '');
        $password = trim($_POST['admin_pass'] ?? '');
        $access = trim($_POST['admin_access'] ?? '');
        $flags = trim($_POST['admin_flags'] ?? 'a');
        $comment = trim($_POST['admin_comment'] ?? '');
        $oldAuth = trim($_POST['old_auth'] ?? '');
        $applyAll = ($_POST['apply_all_servers'] ?? '0') === '1';

        try {
            // 1. Direct save to current active server
            $res = ServerCmd::saveAdmin($activeServer['users_ini'], $auth, $password, $access, $flags, $comment, $oldAuth);

            // 2. Multi-server sync if checked
            if ($applyAll && $res['success']) {
                global $SERVERS;
                if (is_array($SERVERS)) {
                    foreach ($SERVERS as $sId => $srv) {
                        if ($sId !== $activeServer['id'] && !empty($srv['users_ini'])) {
                            try {
                                ServerCmd::saveAdmin($srv['users_ini'], $auth, $password, $access, $flags, $comment, $oldAuth);
                                if (ServerCmd::getServiceStatus($srv['service_name']) === 'running') {
                                    ServerCmd::reloadAdminsLive($srv);
                                }
                            } catch (Throwable $t) {
                                // Ignore non-critical secondary server failures
                            }
                        }
                    }
                }
            }

            $msg = $res['message'];
            $msgType = $res['success'] ? 'success' : 'danger';

            // 3. Immediately reload admins live in the running CS server via RCON
            if ($res['success'] && $serverStatus === 'running') {
                try {
                    $reloadRes = ServerCmd::reloadAdminsLive($activeServer);
                    if ($reloadRes['success']) {
                        $msg .= ' ⚡ (تغییرات بلافاصله روی سرور آنلاین اعمال شد)';
                    } else {
                        $msg .= ' (نکته RCON: ' . $reloadRes['message'] . ')';
                    }
                } catch (Throwable $t) {
                    $msg .= ' (ارتباط RCON با سرور برقرار نشد)';
                }
            }
        } catch (Throwable $e) {
            $msg = 'خطای سیستمی در ذخیره ادمین: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => ($msgType === 'success'),
            'message' => $msg,
            'admins'  => ServerCmd::getAdmins($activeServer['users_ini'])
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
// 2. Handle Delete Admin POST Action
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_admin']) || !empty($_POST['delete_auth']))) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'خطای امنیتی: توکن CSRF نامعتبر است.';
        $msgType = 'danger';
    } else {
        $auth = trim($_POST['delete_auth'] ?? '');
        $applyAll = ($_POST['apply_all_servers'] ?? '0') === '1';

        try {
            $res = ServerCmd::deleteAdmin($activeServer['users_ini'], $auth);
            if ($applyAll && $res['success']) {
                global $SERVERS;
                if (is_array($SERVERS)) {
                    foreach ($SERVERS as $sId => $srv) {
                        if ($sId !== $activeServer['id'] && !empty($srv['users_ini'])) {
                            try {
                                ServerCmd::deleteAdmin($srv['users_ini'], $auth);
                                if (ServerCmd::getServiceStatus($srv['service_name']) === 'running') {
                                    ServerCmd::reloadAdminsLive($srv);
                                }
                            } catch (Throwable $t) {
                                // ignore secondary server errors
                            }
                        }
                    }
                }
            }

            $msg = $res['message'];
            $msgType = $res['success'] ? 'success' : 'danger';

            if ($res['success'] && $serverStatus === 'running') {
                try {
                    $reloadRes = ServerCmd::reloadAdminsLive($activeServer);
                    if ($reloadRes['success']) {
                        $msg .= ' ⚡ (تغییرات آنی روی سرور اعمال شد)';
                    }
                } catch (Throwable $t) {
                    // ignore RCON reload error on delete
                }
            }
        } catch (Throwable $e) {
            $msg = 'خطا در حذف ادمین: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => ($msgType === 'success'),
            'message' => $msg,
            'admins'  => ServerCmd::getAdmins($activeServer['users_ini'])
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────
// 3. Handle Manual Live Reload Admins Action
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reload_admins'])) {
    if (!Auth::verify_csrf($_POST['csrf_token'] ?? '')) {
        $msg = 'خطای امنیتی: توکن CSRF نامعتبر است.';
        $msgType = 'danger';
    } else {
        try {
            $reloadRes = ServerCmd::reloadAdminsLive($activeServer);
            $msg = $reloadRes['message'];
            $msgType = $reloadRes['success'] ? 'success' : 'danger';
        } catch (Throwable $e) {
            $msg = 'خطا در ارسال دستور RCON به سرور: ' . $e->getMessage();
            $msgType = 'danger';
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => ($msgType === 'success'),
            'message' => $msg,
            'admins'  => ServerCmd::getAdmins($activeServer['users_ini'])
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Fetch current admins list
$admins = ServerCmd::getAdmins($activeServer['users_ini']);

$pageTitle = 'مدیریت ادمین‌ها';
$activeNav = 'admins';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Floating Notification Toast -->
<div id="toastContainer" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 0.5rem; pointer-events: none;"></div>

<?php if ($msg): ?>
    <div id="initialAlertBox" class="alert alert-<?php echo $msgType; ?>" style="margin-bottom: 1.25rem;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<!-- Top Action Banner -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem; margin: 0;">
            <span>🛡️ مدیریت ادمین‌های سرور (Admin Manager)</span>
            <span class="badge" id="activeAdminsCountBadge" style="background: var(--primary); font-size: 0.85rem; padding: 0.25rem 0.65rem; border-radius: 999px;">
                <?php echo count($admins); ?> فعال
            </span>
        </h2>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.3rem; margin-bottom: 0;">
            سرور فعال: <strong><?php echo htmlspecialchars($activeServer['name']); ?></strong> (پورت: <?php echo $activeServer['port']; ?>)
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <button type="button" onclick="reloadAdminsAjax(this)" class="btn btn-warning" title="ارسال دستور amx_reloadadmins به سرور برای اعمال حقوق ادمین‌ها">
            ⚡ ریلود آنی سرور (amx_reloadadmins)
        </button>
        <button onclick="location.reload()" class="btn btn-secondary">
            🔄 بروزرسانی لیست
        </button>
    </div>
</div>

<div class="grid-cols-2" style="align-items: start; gap: 1.5rem;">

    <!-- Left Column: Existing Admins Table -->
    <div class="card">
        <div class="card-title" style="flex-wrap: wrap; gap: 0.5rem; justify-content: space-between; align-items: center;">
            <span id="adminsCardTitle">لیست ادمین‌ها (<?php echo count($admins); ?>)</span>
            <input type="text" id="adminSearchInput" onkeyup="filterAdminsTable()" placeholder="🔍 جستجوی سریع..." 
                   class="input-field" style="width: 170px; padding: 0.35rem 0.65rem; font-size: 0.82rem;">
        </div>

        <div class="table-responsive">
            <table id="adminsTable" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="width: 60px;">نوع</th>
                        <th>هویت (Auth / Name)</th>
                        <th>رمز عبور</th>
                        <th>دسترسی</th>
                        <th>پرچم</th>
                        <th>توضیحات</th>
                        <th style="text-align: center; width: 110px;">عملیات</th>
                    </tr>
                </thead>
                <tbody id="adminsTableBody">
                    <?php if (empty($admins)): ?>
                        <tr id="emptyRow">
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                ℹ️ هیچ ادمینی در فایل users.ini یافت نشد.<br>
                                از فرم روبرو نام کاربری و دسترسی ادمین را ثبت کنید تا فوراً فعال گردد!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($admins as $adm): 
                            $authStr = (string)($adm['auth'] ?? '');
                            $flagsStr = (string)($adm['flags'] ?? '');
                            $isLoopback = ($authStr === 'loopback');
                            $isSteam = (str_starts_with($authStr, 'STEAM_') || str_starts_with($authStr, 'VALVE_') || str_contains($flagsStr, 'c'));
                            $isIp = (filter_var($authStr, FILTER_VALIDATE_IP) !== false || str_contains($flagsStr, 'd'));
                            
                            if ($isLoopback) {
                                $typeBadge = '<span class="badge" style="background: rgba(148,163,184,0.15); color: #94a3b8; border: 1px solid rgba(148,163,184,0.3);">SYSTEM</span>';
                            } elseif ($isSteam) {
                                $typeBadge = '<span class="badge" style="background: rgba(6,182,212,0.15); color: #38bdf8; border: 1px solid rgba(6,182,212,0.3);">STEAM</span>';
                            } elseif ($isIp) {
                                $typeBadge = '<span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">IP</span>';
                            } else {
                                $typeBadge = '<span class="badge" style="background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3);">NICK</span>';
                            }
                            $authLower = function_exists('mb_strtolower') ? mb_strtolower($adm['auth'], 'UTF-8') : strtolower($adm['auth']);
                            $commentLower = function_exists('mb_strtolower') ? mb_strtolower($adm['comment'] ?? '', 'UTF-8') : strtolower($adm['comment'] ?? '');
                        ?>
                            <tr class="admin-row" data-auth="<?php echo htmlspecialchars($authLower); ?>" data-comment="<?php echo htmlspecialchars($commentLower); ?>">
                                <td><?php echo $typeBadge; ?></td>
                                <td>
                                    <strong style="color: #fff; font-size: 0.95rem;"><?php echo htmlspecialchars($adm['auth']); ?></strong>
                                    <?php if (!$isSteam && !$isIp && !$isLoopback && !empty($adm['password'])): ?>
                                        <div style="margin-top: 0.25rem;">
                                            <button type="button" class="btn btn-secondary btn-sm" style="font-size: 0.68rem; padding: 0.15rem 0.45rem;" 
                                                    onclick="copySetinfo('<?php echo htmlspecialchars(addslashes($adm['password'])); ?>')"
                                                    title="کپی دستور setinfo برای کنسول کانتر">
                                                📋 کپی setinfo
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($adm['password'])): ?>
                                        <span style="color: var(--text-muted); font-size: 0.75rem;">(بدون پسورد)</span>
                                    <?php else: ?>
                                        <span class="pw-masked" style="font-family: monospace; cursor: pointer; color: #94a3b8; user-select: none;" 
                                              onclick="togglePasswordVisibility(this, '<?php echo htmlspecialchars(addslashes($adm['password'])); ?>')" 
                                              title="کلیک برای نمایش/مخفی‌سازی رمز">•••••• 👁️</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="color: var(--accent); font-weight: bold; font-size: 0.85rem;" title="پرچم‌های دسترسی">
                                        <?php echo htmlspecialchars($adm['access']); ?>
                                    </code>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($adm['flags']); ?></code>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($adm['comment'] ?: '—'); ?>
                                </td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <!-- Edit button -->
                                    <button type="button" class="btn btn-primary btn-sm" style="padding: 0.25rem 0.5rem;"
                                            onclick='startEditAdmin(<?php echo json_encode($adm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            title="ویرایش این ادمین">
                                        ✏️
                                    </button>

                                    <!-- Delete button -->
                                    <button type="button" class="btn btn-danger btn-sm" style="padding: 0.25rem 0.5rem;" 
                                            onclick="deleteAdminAjax('<?php echo htmlspecialchars(addslashes($adm['auth'])); ?>')"
                                            title="حذف این ادمین">
                                        🗑️
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column: Add / Edit Admin Form -->
    <div class="card" id="adminFormCard" style="border: 1px solid var(--border-color); transition: all 0.3s ease;">
        <div class="card-title" style="display: flex; justify-content: space-between; align-items: center;">
            <span id="formTitle">➕ افزودن ادمین جدید</span>
            <button type="button" id="cancelEditBtn" onclick="cancelEdit()" class="btn btn-secondary btn-sm" style="display: none;">
                ❌ لغو ویرایش
            </button>
        </div>

        <form method="POST" action="admins.php" id="adminForm">
            <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="save_admin" value="1">
            <input type="hidden" name="old_auth" id="old_auth" value="">

            <!-- 1. Auth Identifier -->
            <div class="form-group">
                <label for="admin_auth" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">
                    شناسه ادمین (نام کاربری / استیم‌آیدی / آی‌پی) <span style="color: var(--danger);">*</span>
                </label>
                <input type="text" id="admin_auth" name="admin_auth" class="input-field" style="width: 100%; font-size: 0.95rem;" 
                       placeholder="مثال: Ali یا GameLand_Admin یا STEAM_0:0:12345678" required autocomplete="off">
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem;">
                    💡 برای نام کاربری، رمز عبور را در فیلد زیر وارد کنید. برای استیم‌آیدی یا آی‌پی نیازی به پسورد نیست.
                </div>
            </div>

            <!-- 2. Password -->
            <div class="form-group" id="passGroup">
                <label for="admin_pass" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">
                    رمز عبور ادمین (Password)
                </label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="admin_pass" name="admin_pass" class="input-field" style="flex: 1;" 
                           placeholder="مثال: 123456 یا mySecretPass" oninput="updateSetinfoPreview()">
                    <button type="button" onclick="generateRandomPass()" class="btn btn-secondary btn-sm" title="ایجاد رمز تصادفی و مطمئن">
                        🎲 ایجاد رمز
                    </button>
                </div>
                <div id="setinfoPreviewBox" style="font-size: 0.76rem; color: #34d399; margin-top: 0.4rem; background: rgba(6, 78, 59, 0.4); padding: 0.4rem 0.6rem; border-radius: 6px; border: 1px solid rgba(16, 185, 129, 0.2); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.4rem;">
                    <span>دستور ورود بازیکن در کنسول کانتر: <code id="setinfoCode" style="color: #6ee7b7; font-weight: bold;">setinfo _pw ""</code></span>
                    <button type="button" onclick="copySetinfoText()" class="btn btn-secondary btn-sm" style="font-size: 0.68rem; padding: 0.15rem 0.4rem;">
                        📋 کپی
                    </button>
                </div>
            </div>

            <!-- 3. Account Flags (Auth Type) -->
            <div class="form-group">
                <label for="admin_flags" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">
                    نوع اعتبارسنجی اکانت (Account Flags) <span style="color: var(--danger);">*</span>
                </label>
                <select id="admin_flags" name="admin_flags" class="input-field" style="width: 100%; font-size: 0.9rem;" onchange="handleFlagsChange()">
                    <option value="a" selected>a - شناسایی با نام کاربری در بازی (الزام ورود پسورد با setinfo _pw)</option>
                    <option value="ce">ce - شناسایی با استیم‌آیدی (SteamID - ورود مستقیم بدون پسورد)</option>
                    <option value="de">de - شناسایی با آدرس آی‌پی (IP Address - بدون نیاز به پسورد)</option>
                    <option value="ab">ab - نام کاربری با کلن‌تگ و پسورد</option>
                    <option value="e">e - نام کاربری بدون پسورد (عدم بررسی پسورد)</option>
                </select>
            </div>

            <!-- 4. Access Flags (Permissions) -->
            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label for="admin_access" style="font-weight: 600; margin: 0;">
                        پرچم‌های دسترسی (Access Flags) <span style="color: var(--danger);">*</span>
                    </label>
                </div>

                <!-- Presets buttons -->
                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap; margin-bottom: 0.45rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('abcdefghijklmnopqrstuv')" title="دسترسی به تمام فرامین سرور">👑 مدیر کل (Full)</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('bcdefijmu')" title="کیک، بن، اسلی، چت، منو">🛡️ ادمین سرور</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('cdefiju')" title="داور مسابقات میکس 5v5">🎯 داور مسابقات</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('ab')" title="مصونیت و رزرو اسلات">⭐ وی‌آی‌پی (VIP)</button>
                </div>

                <input type="text" id="admin_access" name="admin_access" class="input-field" 
                       style="width: 100%; font-family: monospace; letter-spacing: 1.5px; font-weight: bold; color: var(--accent);" 
                       value="abcdefghijklmnopqrstuv" required>
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.35rem; line-height: 1.5;">
                    <code>a</code>: مصونیت | <code>b</code>: رزرو اسلات | <code>c</code>: کیک | <code>d</code>: بن | <code>e</code>: اسلی/اسلپ | <code>f</code>: تغییر مپ | <code>l</code>: دسترسی RCON | <code>u</code>: منوی amxmodmenu
                </div>
            </div>

            <!-- 5. Comment / Real Name -->
            <div class="form-group">
                <label for="admin_comment" style="font-weight: 600; display: block; margin-bottom: 0.35rem;">
                    یادداشت / نام واقعی ادمین (اختیاری)
                </label>
                <input type="text" id="admin_comment" name="admin_comment" class="input-field" style="width: 100%;" 
                       placeholder="مثال: علی - هد ادمین مسابقات">
            </div>

            <!-- 6. Multi-Server Sync Checkbox -->
            <div style="background: rgba(30, 41, 59, 0.5); padding: 0.6rem 0.8rem; border-radius: 6px; margin-bottom: 1.25rem;">
                <label style="display:flex; align-items:center; gap: 0.6rem; cursor:pointer; font-size: 0.85rem; margin: 0;">
                    <input type="checkbox" name="apply_all_servers" id="apply_all_servers" value="1">
                    <span>اعمال و همگام‌سازی این ادمین روی تمام سرورهای فعال (Multi-Server Sync)</span>
                </label>
            </div>

            <!-- Submit Button -->
            <button type="submit" name="save_admin" value="1" id="submitBtn" class="btn btn-primary" 
                    style="width: 100%; padding: 0.85rem; font-size: 1.05rem; font-weight: 700; background: #059669; border-color: #10b981; box-shadow: 0 4px 14px rgba(5, 150, 105, 0.4);">
                💾 ذخیره ادمین و اعمال آنی روی سرور
            </button>
        </form>
    </div>

</div>

<!-- Guide & Help Card -->
<div class="card" style="margin-top: 1.5rem; background: rgba(15, 23, 42, 0.4); border-color: rgba(56, 189, 248, 0.2);">
    <div class="card-title" style="color: #38bdf8;">
        <span>📖 راهنمای سریع استفاده از ادمینی در Counter-Strike 1.6</span>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; font-size: 0.85rem; color: #cbd5e1; line-height: 1.6;">
        <div>
            <h4 style="color: #fff; margin-bottom: 0.4rem;">🔑 نحوه ورود ادمین با نام کاربری (Nickname):</h4>
            ادمین‌هایی که با نام کاربری و پسورد اضافه شده‌اند، باید قبل از اتصال به سرور دستور زیر را در کنسول بازی بزنند:
            <pre style="background: #020617; padding: 0.5rem; border-radius: 6px; color: #34d399; margin-top: 0.3rem;">setinfo _pw "رمز_عبور"</pre>
        </div>
        <div>
            <h4 style="color: #fff; margin-bottom: 0.4rem;">🎮 باز کردن منوی ادمین در بازی:</h4>
            برای باز کردن منوی مدیریت در حین بازی، کلید P را با دستور زیر در کنسول بایند کنید:
            <pre style="background: #020617; padding: 0.5rem; border-radius: 6px; color: #38bdf8; margin-top: 0.3rem;">bind "p" "amxmodmenu"</pre>
        </div>
        <div>
            <h4 style="color: #fff; margin-bottom: 0.4rem;">⚡ آپدیت آنی بدون ریستارت سرور:</h4>
            هنگام ذخیره یا حذف ادمین در پنل، دستور <code>amx_reloadadmins</code> بلافاصله به سرور ارسال می‌شود و بدون قطعی سرور اعمال می‌گردد.
        </div>
    </div>
</div>

<style>
.badge {
    display: inline-block;
    padding: 0.15rem 0.45rem;
    font-size: 0.7rem;
    border-radius: 4px;
    font-weight: 600;
}
.table-responsive table tbody tr:hover {
    background: rgba(255, 255, 255, 0.03);
}
.toast-msg {
    padding: 0.85rem 1.25rem;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #fff;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    gap: 0.6rem;
    pointer-events: auto;
    animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.toast-success {
    background: #065f46;
    border: 1px solid #10b981;
}
.toast-danger {
    background: #7f1d1d;
    border: 1px solid #ef4444;
}
@keyframes toastIn {
    from { opacity: 0; transform: translateY(-15px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes toastOut {
    from { opacity: 1; transform: translateY(0) scale(1); }
    to { opacity: 0; transform: translateY(-15px) scale(0.95); }
}
</style>

<script>
// Floating Toast Notification Helper
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = `toast-msg toast-${type}`;
    const icon = type === 'success' ? '✅' : '❌';
    toast.innerHTML = `<span>${icon}</span> <span>${escapeHtml(message)}</span>`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastOut 0.3s forwards';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

// Auto detect SteamID or IP while typing in Auth
document.getElementById('admin_auth').addEventListener('input', function() {
    const val = this.value.trim();
    const flagsSelect = document.getElementById('admin_flags');
    if (val.startsWith('STEAM_') || val.startsWith('VALVE_')) {
        flagsSelect.value = 'ce';
    } else if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(val)) {
        flagsSelect.value = 'de';
    } else if (flagsSelect.value === 'ce' && val.length > 0 && !val.includes('_')) {
        flagsSelect.value = 'a';
    }
    updateSetinfoPreview();
});

function handleFlagsChange() {
    updateSetinfoPreview();
}

function updateSetinfoPreview() {
    const pass = document.getElementById('admin_pass').value.trim();
    const code = document.getElementById('setinfoCode');
    const box = document.getElementById('setinfoPreviewBox');
    const flags = document.getElementById('admin_flags').value;

    if (flags === 'ce' || flags === 'de') {
        box.style.display = 'none';
    } else {
        box.style.display = 'flex';
        code.innerText = `setinfo _pw "${pass}"`;
    }
}

function generateRandomPass() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let pass = '';
    for (let i = 0; i < 8; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('admin_pass').value = pass;
    document.getElementById('admin_flags').value = 'a';
    updateSetinfoPreview();
}

function copySetinfo(password) {
    const cmd = `setinfo _pw "${password}"`;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(cmd).then(() => {
            showToast('دستور در کلیپ‌بورد کپی شد: ' + cmd, 'success');
        }).catch(() => {
            prompt('دستور را کپی کنید:', cmd);
        });
    } else {
        prompt('دستور را کپی کنید:', cmd);
    }
}

function copySetinfoText() {
    const pass = document.getElementById('admin_pass').value.trim();
    copySetinfo(pass);
}

function togglePasswordVisibility(span, realPass) {
    if (span.innerText.includes('••••••')) {
        span.innerText = realPass + ' 🔒';
        span.style.color = '#34d399';
    } else {
        span.innerText = '•••••• 👁️';
        span.style.color = '#94a3b8';
    }
}

function applyPreset(presetFlags) {
    document.getElementById('admin_access').value = presetFlags;
}

function startEditAdmin(adm) {
    document.getElementById('formTitle').innerHTML = '✏️ ویرایش ادمین: <span style="color: var(--accent);">' + escapeHtml(adm.auth) + '</span>';
    document.getElementById('cancelEditBtn').style.display = 'inline-block';
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.innerText = '🔄 بروزرسانی ادمین و اعمال روی سرور';
    submitBtn.style.background = '#2563eb';
    submitBtn.style.borderColor = '#3b82f6';
    document.getElementById('old_auth').value = adm.auth;

    document.getElementById('admin_auth').value = adm.auth;
    document.getElementById('admin_pass').value = adm.password || '';
    document.getElementById('admin_access').value = adm.access || 'abcdefghijklmnopqrstuv';
    document.getElementById('admin_flags').value = adm.flags || 'a';
    document.getElementById('admin_comment').value = adm.comment || '';

    updateSetinfoPreview();

    const card = document.getElementById('adminFormCard');
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    card.style.borderColor = 'var(--accent)';
    card.style.boxShadow = '0 0 15px rgba(6, 182, 212, 0.4)';
    setTimeout(() => {
        card.style.boxShadow = '';
    }, 2000);
}

function cancelEdit() {
    document.getElementById('formTitle').innerText = '➕ افزودن ادمین جدید';
    document.getElementById('cancelEditBtn').style.display = 'none';
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.innerText = '💾 ذخیره ادمین و اعمال آنی روی سرور';
    submitBtn.style.background = '#059669';
    submitBtn.style.borderColor = '#10b981';
    document.getElementById('old_auth').value = '';
    document.getElementById('adminForm').reset();
    document.getElementById('admin_access').value = 'abcdefghijklmnopqrstuv';
    document.getElementById('admin_flags').value = 'a';
    updateSetinfoPreview();
}

function filterAdminsTable() {
    const filter = document.getElementById('adminSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#adminsTable tbody tr.admin-row');
    rows.forEach(row => {
        const auth = row.getAttribute('data-auth') || '';
        const comment = row.getAttribute('data-comment') || '';
        if (auth.includes(filter) || comment.includes(filter)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function escapeHtml(string) {
    if (!string) return '';
    const entityMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(string).replace(/[&<>"']/g, s => entityMap[s]);
}

// ─────────────────────────────────────────────────────────────
// Dynamic DOM Rendering for Admins Table
// ─────────────────────────────────────────────────────────────
function renderAdminsTable(adminsList) {
    const tbody = document.getElementById('adminsTableBody');
    const badge = document.getElementById('activeAdminsCountBadge');
    const title = document.getElementById('adminsCardTitle');
    
    if (badge) badge.innerText = `${adminsList.length} فعال`;
    if (title) title.innerText = `لیست ادمین‌ها (${adminsList.length})`;

    if (!adminsList || adminsList.length === 0) {
        tbody.innerHTML = `
            <tr id="emptyRow">
                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                    ℹ️ هیچ ادمینی در فایل users.ini یافت نشد.<br>
                    از فرم روبرو نام کاربری و دسترسی ادمین را ثبت کنید تا فوراً فعال گردد!
                </td>
            </tr>`;
        return;
    }

    let html = '';
    adminsList.forEach(adm => {
        const authStr = adm.auth || '';
        const flagsStr = adm.flags || '';
        const isLoopback = (authStr === 'loopback');
        const isSteam = (authStr.startsWith('STEAM_') || authStr.startsWith('VALVE_') || flagsStr.includes('c'));
        const isIp = (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(authStr) || flagsStr.includes('d'));

        let typeBadge = '';
        if (isLoopback) {
            typeBadge = '<span class="badge" style="background: rgba(148,163,184,0.15); color: #94a3b8; border: 1px solid rgba(148,163,184,0.3);">SYSTEM</span>';
        } else if (isSteam) {
            typeBadge = '<span class="badge" style="background: rgba(6,182,212,0.15); color: #38bdf8; border: 1px solid rgba(6,182,212,0.3);">STEAM</span>';
        } else if (isIp) {
            typeBadge = '<span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">IP</span>';
        } else {
            typeBadge = '<span class="badge" style="background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3);">NICK</span>';
        }

        const safeAuth = escapeHtml(authStr);
        const safeComment = escapeHtml(adm.comment || '—');
        const safePass = escapeHtml(adm.password || '');
        const admJson = escapeHtml(JSON.stringify(adm));

        let passCell = '<span style="color: var(--text-muted); font-size: 0.75rem;">(بدون پسورد)</span>';
        let setinfoBtn = '';

        if (!isSteam && !isIp && !isLoopback && adm.password) {
            setinfoBtn = `
                <div style="margin-top: 0.25rem;">
                    <button type="button" class="btn btn-secondary btn-sm" style="font-size: 0.68rem; padding: 0.15rem 0.45rem;" 
                            onclick="copySetinfo('${safePass}')" title="کپی دستور setinfo برای کنسول کانتر">
                        📋 کپی setinfo
                    </button>
                </div>`;
        }

        if (adm.password) {
            passCell = `<span class="pw-masked" style="font-family: monospace; cursor: pointer; color: #94a3b8; user-select: none;" 
                              onclick="togglePasswordVisibility(this, '${safePass}')" 
                              title="کلیک برای نمایش/مخفی‌سازی رمز">•••••• 👁️</span>`;
        }

        html += `
            <tr class="admin-row" data-auth="${safeAuth.toLowerCase()}" data-comment="${safeComment.toLowerCase()}">
                <td>${typeBadge}</td>
                <td>
                    <strong style="color: #fff; font-size: 0.95rem;">${safeAuth}</strong>
                    ${setinfoBtn}
                </td>
                <td>${passCell}</td>
                <td>
                    <code style="color: var(--accent); font-weight: bold; font-size: 0.85rem;" title="پرچم‌های دسترسی">
                        ${escapeHtml(adm.access || '')}
                    </code>
                </td>
                <td>
                    <code>${escapeHtml(adm.flags || '')}</code>
                </td>
                <td style="color: var(--text-muted); font-size: 0.8rem;">
                    ${safeComment}
                </td>
                <td style="text-align: center; white-space: nowrap;">
                    <button type="button" class="btn btn-primary btn-sm" style="padding: 0.25rem 0.5rem;"
                            onclick='startEditAdmin(${admJson})' title="ویرایش این ادمین">
                        ✏️
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" style="padding: 0.25rem 0.5rem;" 
                            onclick="deleteAdminAjax('${safeAuth}')" title="حذف این ادمین">
                        🗑️
                    </button>
                </td>
            </tr>`;
    });

    tbody.innerHTML = html;
    filterAdminsTable();
}

// ─────────────────────────────────────────────────────────────
// AJAX Form Submit Handler (Zero Page-Refresh)
// ─────────────────────────────────────────────────────────────
document.getElementById('adminForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerText;
    submitBtn.disabled = true;
    submitBtn.innerText = '⏳ در حال ذخیره و اعمال آنی...';

    const formData = new FormData(this);
    formData.append('ajax', '1');

    try {
        const response = await fetch('admins.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`خطای سرور: HTTP ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            showToast(data.message, 'success');
            cancelEdit();
            if (data.admins) {
                renderAdminsTable(data.admins);
            }
            // Hide initial static alert box if visible
            const initAlert = document.getElementById('initialAlertBox');
            if (initAlert) initAlert.style.display = 'none';
        } else {
            showToast(data.message || 'خطا در ثبت ادمین', 'danger');
        }
    } catch (err) {
        console.warn('AJAX submit failed, performing fallback standard form submit', err);
        // Fallback: Submit standard form if fetch or JSON parsing fails
        submitBtn.disabled = false;
        submitBtn.innerText = originalText;
        this.submit();
        return;
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerText = originalText;
    }
});

// ─────────────────────────────────────────────────────────────
// AJAX Delete Admin Handler
// ─────────────────────────────────────────────────────────────
async function deleteAdminAjax(auth) {
    if (!confirm(`آیا از حذف ادمین "${auth}" از سرور اطمینان دارید؟`)) return;

    const fd = new FormData();
    fd.append('delete_admin', '1');
    fd.append('delete_auth', auth);
    fd.append('csrf_token', document.getElementById('csrf_token').value);
    fd.append('ajax', '1');

    try {
        const response = await fetch('admins.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        showToast(data.message, data.success ? 'success' : 'danger');
        if (data.admins) {
            renderAdminsTable(data.admins);
        }
    } catch (err) {
        // Fallback
        location.reload();
    }
}

// ─────────────────────────────────────────────────────────────
// AJAX Live Reload Server Admins
// ─────────────────────────────────────────────────────────────
async function reloadAdminsAjax(btn) {
    const originalText = btn.innerText;
    btn.disabled = true;
    btn.innerText = '⏳ در حال ارسال دستور RCON...';

    const fd = new FormData();
    fd.append('reload_admins', '1');
    fd.append('csrf_token', document.getElementById('csrf_token').value);
    fd.append('ajax', '1');

    try {
        const response = await fetch('admins.php', {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        showToast(data.message, data.success ? 'success' : 'danger');
        if (data.admins) {
            renderAdminsTable(data.admins);
        }
    } catch (err) {
        location.reload();
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateSetinfoPreview();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
