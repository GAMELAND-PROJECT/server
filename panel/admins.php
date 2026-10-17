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
        $oldAuth = trim($_POST['old_auth'] ?? '');

        $applyAll = ($_POST['apply_all_servers'] ?? '0') === '1';
        $res = ServerCmd::saveAdminEverywhere($auth, $password, $access, $flags, $comment, $applyAll, $activeServer['id'], $oldAuth);
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
    <div class="alert alert-<?php echo $msgType; ?>" style="margin-bottom: 1.25rem;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<!-- Top Action Banner -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
            <span>🛡️ مدیریت ادمین‌های سرور</span>
            <span class="badge" style="background: var(--primary); font-size: 0.8rem; padding: 0.2rem 0.6rem; border-radius: 999px;">
                <?php echo count($admins); ?> فعال
            </span>
        </h2>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.2rem;">
            سرور فعال: <strong><?php echo htmlspecialchars($activeServer['name']); ?></strong> (پورت: <?php echo $activeServer['port']; ?>)
        </p>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <form method="POST" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <button type="submit" name="reload_admins" class="btn btn-warning" title="اعمال آنی تغییرات روی سرور روشن بدون ریستارت">
                ⚡ ریلود آنی سرور (amx_reloadadmins)
            </button>
        </form>
        <button onclick="location.reload()" class="btn btn-secondary">
            🔄 بروزرسانی لیست
        </button>
    </div>
</div>

<div class="grid-cols-2" style="align-items: start;">

    <!-- Left Column: Existing Admins Table -->
    <div class="card">
        <div class="card-title" style="flex-wrap: wrap; gap: 0.5rem;">
            <span>لیست ادمین‌ها (<?php echo count($admins); ?>)</span>
            <input type="text" id="adminSearchInput" onkeyup="filterAdminsTable()" placeholder="🔍 جستجوی سریع..." 
                   class="input-field" style="width: 170px; padding: 0.3rem 0.6rem; font-size: 0.8rem;">
        </div>

        <div class="table-responsive">
            <table id="adminsTable">
                <thead>
                    <tr>
                        <th>نوع</th>
                        <th>هویت (Auth / Name)</th>
                        <th>رمز</th>
                        <th>دسترسی</th>
                        <th>توضیح</th>
                        <th style="text-align: center;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($admins)): ?>
                        <tr id="emptyRow">
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                                هیچ ادمینی در فایل users.ini یافت نشد. از فرم روبرو اولین ادمین را ثبت کنید!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($admins as $adm): 
                            $isSteam = (str_starts_with($adm['auth'], 'STEAM_') || str_starts_with($adm['auth'], 'VALVE_') || str_contains($adm['flags'], 'c'));
                            $isIp = (filter_var($adm['auth'], FILTER_VALIDATE_IP) !== false || str_contains($adm['flags'], 'd'));
                            $typeBadge = $isSteam ? '<span class="badge" style="background: rgba(6,182,212,0.15); color: #38bdf8; border: 1px solid rgba(6,182,212,0.3);">STEAM</span>' : 
                                         ($isIp ? '<span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">IP</span>' : 
                                                  '<span class="badge" style="background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3);">NICK</span>');
                        ?>
                            <tr class="admin-row" data-auth="<?php echo htmlspecialchars(strtolower($adm['auth'])); ?>" data-comment="<?php echo htmlspecialchars(strtolower($adm['comment'])); ?>">
                                <td><?php echo $typeBadge; ?></td>
                                <td>
                                    <strong style="color: #fff;"><?php echo htmlspecialchars($adm['auth']); ?></strong>
                                    <?php if (!$isSteam && !$isIp && !empty($adm['password'])): ?>
                                        <div style="margin-top: 0.25rem;">
                                            <button type="button" class="btn btn-secondary btn-sm" style="font-size: 0.65rem; padding: 0.1rem 0.4rem;" 
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
                                        <span class="pw-masked" style="font-family: monospace; cursor: pointer; color: #94a3b8;" 
                                              onclick="togglePasswordVisibility(this, '<?php echo htmlspecialchars(addslashes($adm['password'])); ?>')" 
                                              title="کلیک برای نمایش/مخفی‌سازی رمز">•••••• 👁️</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="color: var(--accent); font-weight: bold;" title="پرچم‌های دسترسی: <?php echo htmlspecialchars($adm['access']); ?>">
                                        <?php echo htmlspecialchars($adm['access']); ?>
                                    </code>
                                    <div style="font-size: 0.7rem; color: var(--text-muted);">
                                        پرچم: <code><?php echo htmlspecialchars($adm['flags']); ?></code>
                                    </div>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($adm['comment'] ?: '—'); ?>
                                </td>
                                <td style="text-align: center; white-space: nowrap;">
                                    <!-- Edit button -->
                                    <button type="button" class="btn btn-primary btn-sm" 
                                            onclick='startEditAdmin(<?php echo json_encode($adm, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                            title="ویرایش این ادمین">
                                        ✏️ ویرایش
                                    </button>

                                    <!-- Delete single server -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف ادمین <?php echo htmlspecialchars(addslashes($adm['auth'])); ?> از این سرور اطمینان دارید؟')">
                                        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
                                        <input type="hidden" name="delete_auth" value="<?php echo htmlspecialchars($adm['auth']); ?>">
                                        <button type="submit" name="delete_admin" class="btn btn-danger btn-sm" title="حذف از سرور فعال">
                                            🗑️
                                        </button>
                                    </form>

                                    <!-- Delete all servers -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف ادمین <?php echo htmlspecialchars(addslashes($adm['auth'])); ?> از تمام سرورهای فعال اطمینان دارید؟')">
                                        <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
                                        <input type="hidden" name="delete_auth" value="<?php echo htmlspecialchars($adm['auth']); ?>">
                                        <input type="hidden" name="apply_all_servers" value="1">
                                        <button type="submit" name="delete_admin" class="btn btn-danger btn-sm" title="حذف از تمام سرورها" style="background: #991b1b;">
                                            🌐 همه
                                        </button>
                                    </form>
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
        <div class="card-title">
            <span id="formTitle">➕ افزودن ادمین جدید</span>
            <button type="button" id="cancelEditBtn" onclick="cancelEdit()" class="btn btn-secondary btn-sm" style="display: none;">
                ❌ لغو ویرایش
            </button>
        </div>

        <!-- Identity Type Selector Tabs -->
        <div style="margin-bottom: 1rem;">
            <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.35rem;">نوع شناسایی (Auth Type):</label>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button type="button" class="btn btn-sm auth-type-btn active" id="tabSteam" onclick="selectAuthType('steam')">
                    🎮 SteamID (پیشنهادی)
                </button>
                <button type="button" class="btn btn-sm auth-type-btn" id="tabNick" onclick="selectAuthType('nick')">
                    👤 نام کاربری (Nickname)
                </button>
                <button type="button" class="btn btn-sm auth-type-btn" id="tabIp" onclick="selectAuthType('ip')">
                    🌐 آدرس آی‌پی (IP)
                </button>
            </div>
        </div>

        <form method="POST" action="admins.php" id="adminForm">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrf_token(); ?>">
            <input type="hidden" name="old_auth" id="old_auth" value="">

            <div class="form-group">
                <label for="admin_auth" id="labelAuth">استیم‌آیدی (SteamID) *</label>
                <input type="text" id="admin_auth" name="admin_auth" class="input-field" style="width: 100%;" 
                       placeholder="مثال: STEAM_0:0:12345678 یا VALVE_0:0:12345678" required autocomplete="off">
                <div id="authHelpText" style="font-size: 0.75rem; color: #38bdf8; margin-top: 0.3rem;">
                    💡 نکته: استیم‌آیدی را با زدن دستور status در کنسول بازی پیدا کنید. نیازی به ست کردن پسورد ندارد.
                </div>
            </div>

            <div class="form-group" id="passGroup" style="display: none;">
                <label for="admin_pass">رمز عبور ادمین (Password)</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" id="admin_pass" name="admin_pass" class="input-field" style="width: 100%;" 
                           placeholder="مثال: myAdminPass123" oninput="updateSetinfoPreview()">
                    <button type="button" onclick="generateRandomPass()" class="btn btn-secondary btn-sm" title="ایجاد رمز تصادفی">
                        🎲 ایجاد
                    </button>
                </div>
                <div id="setinfoPreview" style="font-size: 0.75rem; color: #34d399; margin-top: 0.35rem; display: none;">
                    دستور اتصال در کنسول بازی: <code id="setinfoCode" style="color: #6ee7b7; background: #064e3b; padding: 2px 6px; border-radius: 4px;">setinfo _pw ""</code>
                </div>
            </div>

            <!-- Access Level Presets -->
            <div class="form-group" style="margin-bottom: 0.75rem;">
                <label style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-bottom: 0.35rem;">سطح دسترسی سریع (Presets):</label>
                <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('owner')">👑 مدیر کل (Full)</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('admin')">🛡️ ادمین سرور</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('referee')">🎯 داور میکس</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('vip')">⭐ وی‌آی‌پی (VIP)</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="applyPreset('clear')">🧹 پاکسازی</button>
                </div>
            </div>

            <div class="form-group">
                <label for="admin_access">رشته پرچم‌های دسترسی (Access Flags) *</label>
                <input type="text" id="admin_access" name="admin_access" class="input-field" style="width: 100%; font-family: monospace; letter-spacing: 1px;" 
                       value="abcdefghijklmnopqrstuv" required oninput="syncCheckboxesFromInput()">
            </div>

            <!-- Permission Checkbox Matrix (Expandable/Interactive) -->
            <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.75rem; margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.8rem; font-weight: 600; color: #e2e8f0;">انتخاب تفکیکی اختیارات:</span>
                    <button type="button" onclick="toggleAllFlags()" class="btn btn-secondary btn-sm" style="font-size: 0.7rem; padding: 0.15rem 0.5rem;">
                        انتخاب همه / هیچکدام
                    </button>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 0.4rem; font-size: 0.78rem;">
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="a" onchange="syncInputFromCheckboxes()">
                        <span><code>a</code> - مصونیت و ایمنی (Immunity)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="b" onchange="syncInputFromCheckboxes()">
                        <span><code>b</code> - رزرو اسلات (Reserved Slot)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="c" onchange="syncInputFromCheckboxes()">
                        <span><code>c</code> - دستور کیک (Kick Player)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="d" onchange="syncInputFromCheckboxes()">
                        <span><code>d</code> - بن و آن‌بن (Ban & Unban)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="e" onchange="syncInputFromCheckboxes()">
                        <span><code>e</code> - اسلی و اسلپ (Slay & Slap)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="f" onchange="syncInputFromCheckboxes()">
                        <span><code>f</code> - تغییر مپ (Change Map)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="g" onchange="syncInputFromCheckboxes()">
                        <span><code>g</code> - تغییر Cvar های سرور</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="h" onchange="syncInputFromCheckboxes()">
                        <span><code>h</code> - اجرای کانفیگ‌ها (Exec Cfg)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="i" onchange="syncInputFromCheckboxes()">
                        <span><code>i</code> - چت اختصاصی ادمین (Chat)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="j" onchange="syncInputFromCheckboxes()">
                        <span><code>j</code> - نظرسنجی و رای (Votes)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="l" onchange="syncInputFromCheckboxes()">
                        <span><code>l</code> - دسترسی RCON (amx_rcon)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="u" onchange="syncInputFromCheckboxes()">
                        <span><code>u</code> - منوی اصلی (amxmodmenu)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <input type="checkbox" class="perm-flag" value="v" onchange="syncInputFromCheckboxes()">
                        <span><code>v</code> - بن موقت (Temp Ban)</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="admin_flags">نوع اعتبارسنجی اکانت (Account Flags) *</label>
                <select id="admin_flags" name="admin_flags" class="input-field" style="width: 100%;">
                    <option value="ce" selected>ce - شناسایی با استیم‌آیدی (بدون پسورد)</option>
                    <option value="a">a - شناسایی با نام کاربری (کیک در صورت عدم تطابق پسورد)</option>
                    <option value="e">e - شناسایی با نام کاربری بدون پسورد (توصیه نمی‌شود)</option>
                    <option value="de">de - شناسایی با آدرس آی‌پی (بدون پسورد)</option>
                    <option value="ab">ab - کلن تگ + نام کاربری با پسورد</option>
                </select>
            </div>

            <div class="form-group">
                <label for="admin_comment">یادداشت / نام واقعی / شماره تماس</label>
                <input type="text" id="admin_comment" name="admin_comment" class="input-field" style="width: 100%;" 
                       placeholder="مثال: علی - هد ادمین مسابقات">
            </div>

            <div style="background: rgba(30, 41, 59, 0.5); padding: 0.6rem 0.8rem; border-radius: 6px; margin-bottom: 1rem;">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size: 0.85rem;">
                    <input type="checkbox" name="apply_all_servers" id="apply_all_servers" value="1">
                    <span>اعمال و همگام‌سازی این ادمین روی تمام سرورهای فعال (Multi-Server Sync)</span>
                </label>
            </div>

            <button type="submit" name="save_admin" id="submitBtn" class="btn btn-primary" style="width: 100%; padding: 0.75rem; font-size: 1rem; font-weight: 600;">
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
            <h4 style="color: #fff; margin-bottom: 0.4rem;">🔑 نحوه اتصال ادمین‌های Nickname:</h4>
            ادمین‌هایی که با نام کاربری و پسورد اضافه شده‌اند، باید قبل از فشردن Connect، در کنسول بازی خود دستور زیر را وارد کنند:
            <pre style="background: #020617; padding: 0.5rem; border-radius: 6px; color: #34d399; margin-top: 0.3rem;">setinfo _pw "YOUR_PASSWORD"</pre>
        </div>
        <div>
            <h4 style="color: #fff; margin-bottom: 0.4rem;">🎮 باز کردن منوی ادمین در بازی:</h4>
            برای باز کردن منوی مدیریت، در کنسول بازی بنویسید یا روی یک کلید مثل P ست کنید:
            <pre style="background: #020617; padding: 0.5rem; border-radius: 6px; color: #38bdf8; margin-top: 0.3rem;">bind "p" "amxmodmenu"</pre>
        </div>
        <div>
            <h4 style="color: #fff; margin-bottom: 0.4rem;">⚡ آپدیت آنی بدون ریستارت سرور:</h4>
            هنگام ذخیره ادمین در پنل، دستور <code>amx_reloadadmins</code> به صورت خودکار به سرور ارسال می‌شود و حقوق ادمین بلافاصله روی سرور اعمال می‌گردد.
        </div>
    </div>
</div>

<style>
.auth-type-btn {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
}
.auth-type-btn.active {
    background: var(--primary);
    border-color: var(--primary-hover);
    color: #fff;
    font-weight: 600;
}
.badge {
    display: inline-block;
    padding: 0.15rem 0.45rem;
    font-size: 0.7rem;
    border-radius: 4px;
    font-weight: 600;
}
</style>

<script>
// State for Auth Type
let currentAuthType = 'steam';

function selectAuthType(type) {
    currentAuthType = type;
    document.querySelectorAll('.auth-type-btn').forEach(btn => btn.classList.remove('active'));
    
    const labelAuth = document.getElementById('labelAuth');
    const inputAuth = document.getElementById('admin_auth');
    const passGroup = document.getElementById('passGroup');
    const helpText = document.getElementById('authHelpText');
    const flagsSelect = document.getElementById('admin_flags');

    if (type === 'steam') {
        document.getElementById('tabSteam').classList.add('active');
        labelAuth.innerText = 'استیم‌آیدی (SteamID) *';
        inputAuth.placeholder = 'مثال: STEAM_0:0:12345678 یا VALVE_0:0:12345678';
        passGroup.style.display = 'none';
        helpText.innerHTML = '💡 نکته: استیم‌آیدی را با زدن دستور status در کنسول بازی پیدا کنید. بدون نیاز به پسورد بلافاصله ادمین فعال می‌شود.';
        flagsSelect.value = 'ce';
    } else if (type === 'nick') {
        document.getElementById('tabNick').classList.add('active');
        labelAuth.innerText = 'نام کاربری دقیق در بازی (Nickname) *';
        inputAuth.placeholder = 'مثال: MyPlayerName';
        passGroup.style.display = 'block';
        helpText.innerHTML = '⚠️ بازیکن باید قبل از ورود، در کنسول بازی دستور <code>setinfo _pw "رمز"</code> را بزند.';
        flagsSelect.value = 'a';
        updateSetinfoPreview();
    } else if (type === 'ip') {
        document.getElementById('tabIp').classList.add('active');
        labelAuth.innerText = 'آدرس آی‌پی بازیکن (IP Address) *';
        inputAuth.placeholder = 'مثال: 185.236.38.83';
        passGroup.style.display = 'none';
        helpText.innerHTML = '💡 هر بازیکنی که با این آدرس آی‌پی وصل شود دسترسی ادمین خواهد داشت.';
        flagsSelect.value = 'de';
    }
}

function updateSetinfoPreview() {
    const pass = document.getElementById('admin_pass').value;
    const preview = document.getElementById('setinfoPreview');
    const code = document.getElementById('setinfoCode');
    if (currentAuthType === 'nick' && pass.trim().length > 0) {
        preview.style.display = 'block';
        code.innerText = `setinfo _pw "${pass.trim()}"`;
    } else {
        preview.style.display = 'none';
    }
}

function generateRandomPass() {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('admin_pass');
    input.value = pass;
    updateSetinfoPreview();
}

function copySetinfo(password) {
    const cmd = `setinfo _pw "${password}"`;
    navigator.clipboard.writeText(cmd).then(() => {
        if (typeof showToast === 'function') {
            showToast('دستور کپی شد: ' + cmd, 'success');
        } else {
            alert('کپی شد:\n' + cmd);
        }
    }).catch(() => {
        prompt('دستور را کپی کنید:', cmd);
    });
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

// Preset application
const PRESETS = {
    owner: 'abcdefghijklmnopqrstuv',
    admin: 'bcdefijmu',
    referee: 'cdefiju',
    vip: 'ab',
    clear: ''
};

function applyPreset(presetName) {
    const flags = PRESETS[presetName] !== undefined ? PRESETS[presetName] : '';
    document.getElementById('admin_access').value = flags;
    syncCheckboxesFromInput();
}

function syncCheckboxesFromInput() {
    const val = document.getElementById('admin_access').value;
    document.querySelectorAll('.perm-flag').forEach(cb => {
        cb.checked = val.includes(cb.value);
    });
}

function syncInputFromCheckboxes() {
    let flags = '';
    document.querySelectorAll('.perm-flag:checked').forEach(cb => {
        flags += cb.value;
    });
    // Preserve custom flags not in checkboxes (like m, n, o, p, q, r, s, t, k)
    const currentVal = document.getElementById('admin_access').value;
    for (let ch of currentVal) {
        if (!'abcdefghijlmnpqrstuv'.includes(ch)) {
            flags += ch;
        }
    }
    document.getElementById('admin_access').value = flags;
}

function toggleAllFlags() {
    const cbs = document.querySelectorAll('.perm-flag');
    const allChecked = Array.from(cbs).every(cb => cb.checked);
    cbs.forEach(cb => cb.checked = !allChecked);
    syncInputFromCheckboxes();
}

// Edit existing admin
function startEditAdmin(adm) {
    document.getElementById('formTitle').innerHTML = '✏️ ویرایش ادمین: <span style="color: var(--accent);">' + escapeHtml(adm.auth) + '</span>';
    document.getElementById('cancelEditBtn').style.display = 'inline-block';
    document.getElementById('submitBtn').innerText = '🔄 بروزرسانی ادمین و اعمال روی سرور';
    document.getElementById('old_auth').value = adm.auth;

    // Detect type
    const auth = adm.auth;
    const flags = adm.flags || '';
    if (auth.startsWith('STEAM_') || auth.startsWith('VALVE_') || flags.includes('c')) {
        selectAuthType('steam');
    } else if (flags.includes('d')) {
        selectAuthType('ip');
    } else {
        selectAuthType('nick');
    }

    document.getElementById('admin_auth').value = adm.auth;
    document.getElementById('admin_pass').value = adm.password || '';
    document.getElementById('admin_access').value = adm.access || '';
    document.getElementById('admin_flags').value = adm.flags || 'ce';
    document.getElementById('admin_comment').value = adm.comment || '';

    syncCheckboxesFromInput();
    updateSetinfoPreview();

    // Scroll to form and highlight
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
    document.getElementById('submitBtn').innerText = '💾 ذخیره ادمین و اعمال آنی روی سرور';
    document.getElementById('old_auth').value = '';
    document.getElementById('adminForm').reset();
    selectAuthType('steam');
    applyPreset('owner');
}

// Table search filter
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

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    syncCheckboxesFromInput();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
