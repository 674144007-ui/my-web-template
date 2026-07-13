<?php
/**
 * index.php — Multi-Tenant Login Page
 * ────────────────────────────────────────────────────────────
 * เพิ่มจากเดิม:
 *   - ช่องกรอก School Code (3 ช่อง: school_code + username + password)
 *   - Lookup tenant จาก Master DB ก่อน authenticate
 *   - บันทึก school_code ลง session เพื่อให้ db.php ใช้งานได้
 *   - แสดงโลโก้และสีของโรงเรียนตาม tenant
 * ────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// โหลดไฟล์สคริปต์ที่จำเป็น
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// ----------------------------------------------------
// เติมฟังก์ชัน h() แบบปลอดภัย ป้องกันการแครชระหว่างเรนเดอร์หน้าเว็บ
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}
// ----------------------------------------------------

// ถ้า login แล้วและมี tenant → ไป dashboard
if (isLoggedIn() && $pdo !== null) {
    redirectToDashboardIfLoggedIn();
}

$error_msg   = '';
$school_info = null; // ข้อมูลโรงเรียนสำหรับแสดง branding

// ── อ่าน error/msg จาก URL ──────────────────────────────────
if (isset($_GET['error'])) {
    $error_msg = match($_GET['error']) {
        'session_expired'  => '⏱️ เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่',
        'require_login'    => '🔒 กรุณาเข้าสู่ระบบก่อนใช้งาน',
        'invalid_role'     => '❌ สิทธิ์การใช้งานไม่ถูกต้อง',
        'invalid_school'   => '❌ ไม่พบรหัสโรงเรียนในระบบ',
        'session_invalid'  => '🔐 เซสชันไม่ถูกต้อง กรุณาเข้าสู่ระบบใหม่',
        default            => ''
    };
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $error_msg = '✅ ออกจากระบบสำเร็จ';
}

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $action      = $_POST['action'] ?? 'login';
    $school_code = strtoupper(trim($_POST['school_code'] ?? ''));

    // ── ACTION: Login ─────────────────────────────────────────
    if ($action === 'login') {
        // ตรวจ Rate Limit ก่อน process ใดๆ
        checkLoginRateLimit($school_code ?? null);

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username)) {
            $error_msg = '⚠️ กรุณากรอกชื่อผู้ใช้งาน';
        } elseif (empty($password)) {
            $error_msg = '⚠️ กรุณากรอกรหัสผ่าน';
        } else {
            // ── ตรวจ developer login ก่อนเสมอ (ไม่ต้องใส่ school_code) ──
            // developer login ได้โดยไม่ต้องกรอก school_code และกรอกก็ได้ (ไม่สนใจ)
            $dev_user = null;
            try {
                $stmt = $master_pdo->prepare(
                    'SELECT * FROM dev_users WHERE username = ? AND is_active = 1 LIMIT 1'
                );
                $stmt->execute([$username]);
                $dev_user = $stmt->fetch();
            } catch (PDOException $e) {
                $dev_user = null;
            }

            if (!empty($dev_user)) {
                // ── Developer Login Flow ──────────────────────────
                if (!password_verify($password, $dev_user['password'])) {
                    $error_msg = '❌ รหัสผ่านไม่ถูกต้อง';
                } else {
                    $_SESSION['role'] = 'developer'; // set ก่อน setUserSession
                    setUserSession([
                        'id'           => $dev_user['id'],
                        'username'     => $dev_user['username'],
                        'display_name' => $dev_user['display_name'],
                        'class_id'     => null,
                    ], 'developer');
                    $master_pdo->prepare('UPDATE dev_users SET last_login_at=NOW() WHERE id=?')
                        ->execute([$dev_user['id']]);
                    header('Location: dashboard_dev.php');
                    exit;
                }
            } elseif (empty($school_code)) {
                $error_msg = '⚠️ กรุณากรอกรหัสโรงเรียน';
            } else {
            // ── Normal Tenant Login Flow ──────────────────────────
            // 1. Lookup tenant จาก master
            try {
                $stmt = $master_pdo->prepare(
                    "SELECT * FROM sys_tenants WHERE school_code = ? AND is_active = 1 LIMIT 1"
                );
                $stmt->execute([$school_code]);
                $tenant_row = $stmt->fetch();
            } catch (PDOException $e) {
                $tenant_row = null;
                error_log('[login] master lookup: ' . $e->getMessage());
            }

            if (!$tenant_row) {
                $error_msg = '❌ ไม่พบรหัสโรงเรียน "' . h($school_code) . '" ในระบบ';
            } else {
                // 2. เชื่อมต่อ Tenant DB
                try {
                    $tenant_pdo = new PDO(
                        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                            env('MASTER_DB_HOST', 'localhost'),
                            (int)env('MASTER_DB_PORT', 3306),
                            $tenant_row['db_name']
                        ),
                        env('MASTER_DB_USER', 'root'),
                        env('MASTER_DB_PASS', ''),
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                    );

                    // 3. ดึง user
                    $stmt = $tenant_pdo->prepare('
                        SELECT u.*, r.role_key
                        FROM users u
                        JOIN sys_roles r ON r.id = u.role_id
                        WHERE u.username = ? AND u.is_deleted = 0
                        LIMIT 1
                    ');
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    if (!$user) {
                        $error_msg = '❌ ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง';

                    } elseif (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                        $error_msg = '🔒 บัญชีถูกระงับชั่วคราว กรุณารอ ' . LOGIN_LOCKOUT_MINUTES . ' นาที';

                    } elseif ($user['is_first_login'] == 1) {
                        $_SESSION['setup_uid']      = $user['id'];
                        $_SESSION['setup_username'] = $user['username'];
                        $_SESSION['setup_display']  = $user['display_name'];
                        $_SESSION['setup_school']   = $school_code;
                        // ยังไม่ set school_code ใน session หลัก
                        // รอจน setup_password สำเร็จ

                    } elseif (!password_verify($password, $user['password'])) {
                        $attempts     = $user['failed_login_attempts'] + 1;
                        $locked_until = null;
                        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
                            $locked_until = date('Y-m-d H:i:s', strtotime('+' . LOGIN_LOCKOUT_MINUTES . ' minutes'));
                            $error_msg = '❌ รหัสผ่านผิดเกินกำหนด บัญชีถูกระงับ ' . LOGIN_LOCKOUT_MINUTES . ' นาที';
                        } else {
                            $error_msg = '❌ รหัสผ่านไม่ถูกต้อง (ครั้งที่ ' . $attempts . '/' . LOGIN_MAX_ATTEMPTS . ')';
                        }
                        $tenant_pdo->prepare('UPDATE users SET failed_login_attempts=?, locked_until=? WHERE id=?')
                            ->execute([$attempts, $locked_until, $user['id']]);

                    } else {
                        // ✅ Login สำเร็จ
                        $tenant_pdo->prepare('UPDATE users SET failed_login_attempts=0, locked_until=NULL, last_login_at=NOW() WHERE id=?')
                            ->execute([$user['id']]);

                        // บันทึก school_code ลง session ก่อน setUserSession
                        $_SESSION['school_code'] = $school_code;

                        setUserSession($user, $user['role_key']);

                        // redirect ไป dashboard
                        $url = _dashboardUrl($user['role_key']);
                        if (!$url) {
                            _destroySession();
                            $error_msg = '❌ บัญชีนี้มีปัญหาเรื่องสิทธิ์';
                        } else {
                            header('Location: ' . $url);
                            exit;
                        }
                    }

                } catch (PDOException $e) {
                    $error_msg = '🚧 ฐานข้อมูลขัดข้อง';
                    error_log('[login] tenant auth: ' . $e->getMessage());
                }
            }
            } // end else tenant block
        }
    }

    // ── ACTION: setup_password ───────────────────────────────
    elseif ($action === 'setup_password') {
        $setup_uid    = $_SESSION['setup_uid']    ?? null;
        $setup_school = $_SESSION['setup_school'] ?? null;
        $new_pw       = $_POST['new_password']      ?? '';
        $conf_pw      = $_POST['confirm_password']   ?? '';

        if (!$setup_uid || !$setup_school) {
            $error_msg = '❌ เซสชันไม่ถูกต้อง';
        } elseif ($new_pw !== $conf_pw || empty($new_pw)) {
            $error_msg = '❌ รหัสผ่านไม่ตรงกันหรือว่างเปล่า';
        } elseif (
            strlen($new_pw) < 8 ||
            !preg_match('/[A-Z]/', $new_pw) ||
            !preg_match('/[a-z]/', $new_pw) ||
            !preg_match('/[0-9]/', $new_pw) ||
            !preg_match('/[^a-zA-Z0-9]/', $new_pw)
        ) {
            $error_msg = '❌ รหัสผ่านไม่ผ่านเกณฑ์ความปลอดภัย';
        } else {
            try {
                // Lookup tenant
                $stmt = $master_pdo->prepare("SELECT * FROM sys_tenants WHERE school_code = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$setup_school]);
                $t = $stmt->fetch();

                if ($t) {
                    $tenant_pdo = new PDO(
                        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                            env('MASTER_DB_HOST','localhost'),
                            (int)env('MASTER_DB_PORT', 3306),
                            $t['db_name']
                        ),
                        env('MASTER_DB_USER','root'), env('MASTER_DB_PASS',''),
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
                    );

                    $hashed = password_hash($new_pw, PASSWORD_ARGON2ID);
                    $tenant_pdo->prepare('UPDATE users SET password=?, is_first_login=0, failed_login_attempts=0 WHERE id=?')
                        ->execute([$hashed, $setup_uid]);

                    $stmt = $tenant_pdo->prepare('SELECT u.*, r.role_key FROM users u JOIN sys_roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
                    $stmt->execute([$setup_uid]);
                    $user = $stmt->fetch();

                    if ($user) {
                        $_SESSION['school_code'] = $setup_school;
                        unset($_SESSION['setup_uid'], $_SESSION['setup_username'],
                              $_SESSION['setup_display'], $_SESSION['setup_school']);

                        setUserSession($user, $user['role_key']);
                        header('Location: ' . _dashboardUrl($user['role_key']));
                        exit;
                    }
                }
            } catch (PDOException $e) {
                $error_msg = '❌ เกิดข้อผิดพลาด: ' . $e->getMessage();
                error_log('[setup_password] ' . $e->getMessage());
            }
        }
    }

    // ── ACTION: cancel_setup ─────────────────────────────────
    elseif ($action === 'cancel_setup') {
        unset($_SESSION['setup_uid'], $_SESSION['setup_username'],
              $_SESSION['setup_display'], $_SESSION['setup_school']);
        header('Location: index.php');
        exit;
    }
}

$is_setup_mode = isset($_SESSION['setup_uid']);
$csrf_token    = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ — Smart School Plus</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1e3a8a;
            --accent:  #f59e0b;
        }
        body { margin:0; font-family:'Prompt',sans-serif; background:#f1f5f9;
               min-height:100vh; display:flex; align-items:center; justify-content:center; }

        .login-card {
            background:#fff; width:100%; max-width:460px;
            padding:40px 36px; border-radius:16px;
            box-shadow:0 10px 40px rgba(0,0,0,.1);
            border-top:5px solid var(--primary);
        }
        .logo { width:80px; height:80px; display:block; margin:0 auto 4px;
                object-fit:contain; border-radius:12px; transition:.3s; }
        .school-brand { text-align:center; margin-bottom:22px; }
        .school-brand h1 { color:var(--primary); font-size:1.35rem; font-weight:700; margin:0 0 3px; transition:.3s; }
        .school-brand .subtitle { color:#64748b; font-size:.88rem; margin:0; }
        .school-brand .school-tag { display:inline-block; font-size:.72rem; background:var(--primary);
            color:#fff; padding:2px 10px; border-radius:20px; margin-top:6px;
            opacity:0; transform:translateY(-4px); transition:.3s; }
        .school-brand .school-tag.show { opacity:1; transform:translateY(0); }
        .login-card { transition:border-color .4s; }

        .alert { padding:11px 14px; border-radius:8px; margin-bottom:18px; font-size:.88rem; }
        .alert-error   { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
        .alert-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }

        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-size:.84rem; color:#475569; font-weight:500; margin-bottom:6px; }
        .form-group input {
            width:100%; padding:11px 14px; border:1.5px solid #cbd5e1;
            border-radius:8px; font-family:'Prompt',sans-serif; font-size:.95rem;
            box-sizing:border-box; transition:.2s;
        }
        .form-group input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(30,58,138,.1); }

        .school-code-input { text-transform:uppercase; letter-spacing:.08em; font-weight:600; }

        .pw-wrap { position:relative; }
        .pw-wrap input { padding-right:44px; }
        .pw-toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%);
                     background:none; border:none; cursor:pointer; font-size:1.1rem; color:#64748b; }

        .btn { width:100%; padding:13px; border:none; border-radius:8px;
               font-family:'Prompt',sans-serif; font-size:1rem; font-weight:600;
               cursor:pointer; transition:.2s; margin-top:4px; }
        .btn:hover { opacity:.88; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-success { background:#10b981; color:#fff; }
        .btn-secondary { background:#f1f5f9; color:#475569; margin-top:8px; }

        .divider { border:none; border-top:1px solid #e2e8f0; margin:20px 0; }

        .pw-rules { background:#f8fafc; border:1px solid #e2e8f0; padding:12px 14px;
                    border-radius:8px; font-size:.82rem; margin-bottom:16px; }
        .pw-rules ul { margin:6px 0 0; padding-left:0; list-style:none; }
        .pw-rules li { margin-bottom:3px; color:#ef4444; display:flex; align-items:center; gap:7px; }
        .pw-rules li.valid { color:#10b981; }

        .footer { text-align:center; margin-top:24px; color:#94a3b8; font-size:.8rem; }

        @media(max-width:480px){ .login-card{ margin:16px; padding:28px 20px; } }
    </style>
</head>
<body>
<div class="login-card" id="loginCard">
    <div id="schoolLogoWrap" style="text-align:center;margin-bottom:12px;">
        <img src="logo.png" class="logo" id="schoolLogo" alt="โลโก้"
             style="display:none"
             onerror="this.style.display='none'">
        <div id="schoolEmoji" style="font-size:64px;line-height:1;margin-bottom:4px;">🏫</div>
    </div>

    <?php if ($is_setup_mode): ?>

        <!-- ── โหมดตั้งรหัสผ่านครั้งแรก ───────────────────── -->
        <h1>กำหนดรหัสผ่านใหม่</h1>
        <p class="subtitle">ยินดีต้อนรับ <?= h($_SESSION['setup_display'] ?? '') ?></p>

        <?php if ($error_msg): ?>
        <div class="alert alert-error"><?= h($error_msg) ?></div>
        <?php endif; ?>

        <div class="pw-rules">
            <strong>เกณฑ์ความปลอดภัย:</strong>
            <ul>
                <li id="r-len">❌ ความยาว ≥ 8 ตัวอักษร</li>
                <li id="r-up">❌ ตัวพิมพ์ใหญ่ (A-Z)</li>
                <li id="r-lo">❌ ตัวพิมพ์เล็ก (a-z)</li>
                <li id="r-num">❌ ตัวเลข (0-9)</li>
                <li id="r-sym">❌ อักขระพิเศษ (!@#$...)</li>
            </ul>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action"     value="setup_password">
            <div class="form-group">
                <label>รหัสผ่านใหม่</label>
                <div class="pw-wrap">
                    <input type="password" name="new_password" id="pw1" required oninput="checkRules()">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw1','i1')"><span id="i1">👁️</span></button>
                </div>
            </div>
            <div class="form-group">
                <label>ยืนยันรหัสผ่าน</label>
                <div class="pw-wrap">
                    <input type="password" name="confirm_password" id="pw2" required>
                    <button type="button" class="pw-toggle" onclick="togglePw('pw2','i2')"><span id="i2">👁️</span></button>
                </div>
            </div>
            <button type="submit" class="btn btn-success">💾 บันทึกและเข้าสู่ระบบ</button>
        </form>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action"     value="cancel_setup">
            <button type="submit" class="btn btn-secondary">ยกเลิก</button>
        </form>

    <?php else: ?>

        <!-- ── โหมด Login ปกติ ──────────────────────────────── -->
        <div class="school-brand">
            <h1 id="schoolName">Smart School Plus</h1>
            <p class="subtitle" id="schoolSub">ระบบบริหารจัดการห้องเรียนอัจฉริยะ</p>
            <span class="school-tag" id="schoolTag"></span>
        </div>

        <?php if ($error_msg): ?>
        <div class="alert <?= str_starts_with($error_msg, '✅') ? 'alert-success' : 'alert-error' ?>">
            <?= h($error_msg) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token"  value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action"       value="login">

            <!-- school_code: ซ่อนถ้าพิมพ์ username ที่เป็น dev -->
            <div class="form-group" id="schoolCodeGroup">
                <label>🏫 รหัสโรงเรียน (School Code)</label>
                <input type="text" name="school_code"
                       class="school-code-input"
                       value="<?= h($_POST['school_code'] ?? '') ?>"
                       placeholder="เช่น BKW"
                       maxlength="20" autocomplete="organization"
                       id="inp_school_code"
                       oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'');lookupSchool(this.value)">
                <span style="font-size:.76rem;color:#94a3b8;margin-top:4px;display:block">
                    หากเป็น Developer ไม่ต้องกรอก — ว่างไว้ได้
                </span>
            </div>
            <!-- dev hint: แสดงเมื่อ username ดูเหมือน dev -->
            <div id="devHint" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;
                 border-radius:8px;padding:10px 14px;font-size:.84rem;color:#166534;margin-bottom:8px;">
                ✅ <strong>Developer Mode</strong> — ไม่ต้องกรอกรหัสโรงเรียน กรอกแค่ Username + Password
            </div>

            <hr class="divider">

            <div class="form-group">
                <label>👤 ชื่อผู้ใช้งาน</label>
                <input type="text" name="username"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       placeholder="ระบุชื่อผู้ใช้งาน"
                       autocomplete="username"
                       id="inp_username"
                       oninput="checkDevMode(this.value)">
            </div>
            <div class="form-group">
                <label>🔑 รหัสผ่าน</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="pw3"
                           placeholder="ระบุรหัสผ่าน" autocomplete="current-password">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw3','i3')"><span id="i3">👁️</span></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">เข้าสู่ระบบ</button>
        </form>

    <?php endif; ?>

    <p class="footer">© <?= date('Y') ?> Smart School Plus | Platform Edition</p>
</div>

<script>
function togglePw(id, iconId) {
    const el = document.getElementById(id);
    const ic = document.getElementById(iconId);
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.textContent = el.type === 'password' ? '👁️' : '🙈';
}

function checkRules() {
    const p = document.getElementById('pw1').value;
    const set = (id, ok) => {
        const li = document.getElementById(id);
        li.classList.toggle('valid', ok);
        li.textContent = (ok ? '✅' : '❌') + li.textContent.slice(1);
    };
    set('r-len',  p.length >= 8);
    set('r-up',   /[A-Z]/.test(p));
    set('r-lo',   /[a-z]/.test(p));
    set('r-num',  /[0-9]/.test(p));
    set('r-sym',  /[^a-zA-Z0-9]/.test(p));
}

// ── Dev mode detection ─────────────────────────────────────
// ถ้า username ไม่มีคน login ในระบบปกติเลย หรือดูเหมือน dev
// → ซ่อน school_code field, แสดง dev hint
// ── School branding lookup ────────────────────────────────
let _lookupTimer = null;
let _currentCode = '';

function lookupSchool(code) {
    clearTimeout(_lookupTimer);
    if (!code || code.length < 2) {
        resetBrand();
        return;
    }
    _lookupTimer = setTimeout(() => fetchSchoolInfo(code), 350);
}

async function fetchSchoolInfo(code) {
    if (code === _currentCode) return;
    _currentCode = code;
    try {
        const res  = await fetch('api_school_info.php?code=' + encodeURIComponent(code));
        const data = await res.json();
        if (data.ok) applyBrand(data);
        else resetBrand();
    } catch(e) { resetBrand(); }
}

function applyBrand(data) {
    const card  = document.getElementById('loginCard');
    const logo  = document.getElementById('schoolLogo');
    const name  = document.getElementById('schoolName');
    const sub   = document.getElementById('schoolSub');
    const tag   = document.getElementById('schoolTag');
    const color = data.primary_color || '#1e3a8a';

    document.documentElement.style.setProperty('--primary', color);
    if (card) card.style.borderTopColor = color;

    const emoji = document.getElementById('schoolEmoji');
    console.log('[SSP] branding data:', data); // debug
    if (emoji) {
        if (data.logo_url) {
            // มีโลโก้จริง → แสดง img ซ่อน emoji
            if (!logo) {
                // สร้าง img ถ้าไม่มี
                const newImg = document.createElement('img');
                newImg.id = 'schoolLogo';
                newImg.className = 'logo';
                newImg.alt = 'โลโก้';
                emoji.parentNode.insertBefore(newImg, emoji);
            }
            const logoEl = document.getElementById('schoolLogo') || logo;
            if (logoEl) {
                logoEl.src = data.logo_url;
                logoEl.style.display = 'block';
                logoEl.onerror = () => {
                    logoEl.style.display = 'none';
                    emoji.style.display = 'block';
                    console.warn('[SSP] logo load failed:', data.logo_url);
                };
            }
            emoji.style.display = 'none';
        } else {
            if (logo) logo.style.display = 'none';
            emoji.style.display = 'block';
            emoji.textContent = '🏫';
        }
    }
    if (name) name.textContent = data.school_name || 'Smart School Plus';
    if (sub)  sub.textContent  = 'เข้าสู่ระบบโรงเรียน';
    if (tag) { tag.textContent = _currentCode; tag.classList.add('show'); }
}

function resetBrand() {
    _currentCode = '';
    document.documentElement.style.setProperty('--primary','#1e3a8a');
    const card = document.getElementById('loginCard');
    const logo = document.getElementById('schoolLogo');
    const name = document.getElementById('schoolName');
    const sub  = document.getElementById('schoolSub');
    const tag  = document.getElementById('schoolTag');
    if (card) card.style.borderTopColor = '#1e3a8a';
    if (logo) { logo.style.display='none'; }
    const emojiEl = document.getElementById('schoolEmoji');
    if (emojiEl) { emojiEl.style.display='block'; emojiEl.textContent='🏫'; }
    if (name) name.textContent = 'Smart School Plus';
    if (sub)  sub.textContent  = 'ระบบบริหารจัดการห้องเรียนอัจฉริยะ';
    if (tag)  tag.classList.remove('show');
}

// ── Auto-lookup ถ้ามี value จาก POST ──────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const sc = document.getElementById('inp_school_code');
    if (sc && sc.value.length >= 2) fetchSchoolInfo(sc.value);
});

const DEV_USERNAMES = ['PichayaY.', 'PichayaY', 'pichaya']; // dev usernames

function checkDevMode(val) {
    const schoolGroup = document.getElementById('schoolCodeGroup');
    const devHint     = document.getElementById('devHint');
    const schoolInput = document.getElementById('inp_school_code');
    if (!schoolGroup) return;

    const isDev = DEV_USERNAMES.some(u => u.toLowerCase() === val.trim().toLowerCase());
    if (isDev) {
        schoolGroup.style.display = 'none';
        devHint.style.display     = 'block';
        if (schoolInput) schoolInput.value = ''; // clear school_code
    } else {
        schoolGroup.style.display = '';
        devHint.style.display     = 'none';
    }
}

// check on load ถ้า username มีค่าอยู่แล้ว (เช่น session expired แล้วกลับมา)
document.addEventListener('DOMContentLoaded', () => {
    const uEl = document.getElementById('inp_username');
    if (uEl && uEl.value) checkDevMode(uEl.value);
    // autofocus: ถ้าไม่มี school_code → focus username แทน
    const schoolGroup = document.getElementById('schoolCodeGroup');
    if (schoolGroup && schoolGroup.style.display === 'none') {
        uEl?.focus();
    }
});
</script>
</body>
</html>
