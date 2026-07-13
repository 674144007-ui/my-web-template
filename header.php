<?php
// header.php (Phase 1 & Phase 5: Mobile Foundation + PWA Integration + Official Logo)
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// คำนวณ logo สำหรับ <head> (ต้องทำก่อน HTML output)
$head_logo = null;
if (!empty($tenant['logo_path']) && file_exists(__DIR__.'/'.$tenant['logo_path'])) {
    $head_logo = $tenant['logo_path'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"> 

<link rel="manifest" href="manifest.php" crossorigin="use-credentials">
<meta name="theme-color" content="#1e3a8a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Bankha Lab">

<?php if ($head_logo): ?>
<link rel="apple-touch-icon" href="<?= htmlspecialchars($head_logo) ?>">
<link rel="icon" href="<?= htmlspecialchars($head_logo) ?>" type="image/png">
<link rel="shortcut icon" href="<?= htmlspecialchars($head_logo) ?>" type="image/png">
<?php endif; ?>

<title><?= isset($page_title) ? h($page_title) . ' - ' . h($brand_name ?? 'Smart School Plus') : h($brand_name ?? 'Smart School Plus') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Itim&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
    /* =========================================================
       1. Global Variables & Foundation — Modern Academic Theme
       ========================================================= */
    :root {
        /* โทนสีหลัก: น้ำเงิน-ขาว-ทอง */
        --primary:       #1e3a8a;   /* น้ำเงินเข้ม */
        --primary-mid:   #2563eb;   /* น้ำเงินกลาง */
        --primary-light: #eff6ff;   /* น้ำเงินอ่อน (background) */
        --accent:        #f59e0b;   /* ทอง */
        --accent-light:  #fef3c7;   /* ทองอ่อน */

        /* Neutral */
        --bg-light:      #f8fafc;
        --bg-card:       #ffffff;
        --border:        #e2e8f0;
        --text-dark:     #0f172a;
        --text-body:     #334155;
        --text-muted:    #64748b;

        /* Status */
        --success:  #10b981;
        --danger:   #ef4444;
        --warning:  #f59e0b;

        /* Shadow */
        --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
        --shadow-md: 0 4px 12px rgba(0,0,0,.08);
        --shadow-lg: 0 10px 30px rgba(0,0,0,.10);

        /* Radius */
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 16px;
    }

    body { 
        font-family: 'Prompt', sans-serif; 
        background: var(--bg-light); 
        margin: 0; 
        padding: 0; 
        color: var(--text-body);
        -webkit-tap-highlight-color: transparent; 
        overflow-x: hidden;
        line-height: 1.6;
    }

    /* ── Inputs ─────────────────────────────────────────────── */
    input[type="text"], 
    input[type="password"], 
    input[type="number"], 
    input[type="email"],
    input[type="url"],
    input[type="date"],
    textarea, 
    select {
        font-size: 16px !important; 
        width: 100%; 
        padding: 10px 14px; 
        border-radius: var(--radius-md); 
        border: 1.5px solid var(--border); 
        margin-bottom: 15px; 
        font-family: inherit; 
        box-sizing: border-box;
        background: #fff;
        color: var(--text-dark);
        transition: border-color .2s, box-shadow .2s;
    }
    
    input:focus, textarea:focus, select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(30,58,138,.12);
    }

    /* ── Container ──────────────────────────────────────────── */
    .container {
        max-width: 1200px;
        margin: 28px auto;
        padding: 0 20px;
        box-sizing: border-box;
    }

    /* ── Buttons ─────────────────────────────────────────────── */
    button.btn-primary, .btn-primary {
        display: inline-block;
        text-align: center;
        padding: 11px 22px; 
        background: var(--primary); 
        color: white; 
        border: none;
        border-radius: var(--radius-md); 
        cursor: pointer; 
        font-size: .95rem; 
        font-family: inherit; 
        font-weight: 600;
        transition: background .2s, transform .15s, box-shadow .2s;
        text-decoration: none;
        width: 100%; 
        box-sizing: border-box;
    }
    button.btn-primary:hover, .btn-primary:hover { 
        background: var(--primary-mid);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(30,58,138,.25);
    }

    /* ── Cards ───────────────────────────────────────────────── */
    .card {
        background: var(--bg-card);
        padding: 24px;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        border: 1px solid var(--border);
        margin-bottom: 20px;
    }
    .msg { padding: 12px 16px; border-radius: var(--radius-md); margin-bottom: 15px; font-weight: 500; font-size: .9rem; }
    .msg.success { background: #d1fae5; color: #065f46; border-left: 4px solid var(--success); }
    .msg.error   { background: #fee2e2; color: #991b1b; border-left: 4px solid var(--danger);  }
    button.btn-primary:hover, .btn-primary:hover { 
        background: var(--primary-dark); 
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(14, 165, 233, 0.3);
    }

    /* =========================================================
       2. Navigation — Modern Academic
       ========================================================= */
    .navbar {
        background: var(--primary);
        color: white;
        padding: 0 24px;
        height: 62px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 2px 8px rgba(15,23,42,.25);
        position: sticky;
        top: 0;
        z-index: 1000;
        border-bottom: 3px solid var(--accent);
    }

    .nav-brand {
        font-size: 1.15rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: white;
        letter-spacing: .01em;
    }
    
    .nav-brand-logo {
        height: 36px;
        width: 36px;
        object-fit: contain;
        background-color: white;
        border-radius: 50%;
        padding: 2px;
        box-shadow: 0 2px 6px rgba(0,0,0,.2);
    }

    .nav-desktop-links {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .nav-desktop-links a {
        color: rgba(255,255,255,.9);
        text-decoration: none;
        font-weight: 500;
        font-size: .9rem;
        transition: background .2s;
        padding: 7px 13px;
        border-radius: var(--radius-sm);
    }
    .nav-desktop-links a:hover { background: rgba(255,255,255,.15); }

    .user-greeting {
        font-size: .85rem;
        background: rgba(255,255,255,.12);
        padding: 5px 12px;
        border-radius: 20px;
        border: 1px solid rgba(255,255,255,.2);
    }

    .hamburger-btn {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 1.8rem;
        cursor: pointer;
        padding: 5px;
    }

    /* ── Mobile Sidebar ─────────────────────────────────────── */
    .mobile-sidebar-overlay {
        position: fixed;
        top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(15,23,42,.55);
        backdrop-filter: blur(4px);
        z-index: 1001;
        opacity: 0; visibility: hidden;
        transition: .3s ease;
    }
    .mobile-sidebar-overlay.active { opacity: 1; visibility: visible; }

    .mobile-sidebar {
        position: fixed;
        top: 0; right: -300px;
        width: 280px; height: 100vh;
        background: white;
        z-index: 1002;
        box-shadow: -5px 0 25px rgba(0,0,0,.15);
        transition: .35s cubic-bezier(.4,0,.2,1);
        display: flex; flex-direction: column;
        border-left: 3px solid var(--accent);
    }
    .mobile-sidebar.active { right: 0; }

    .sidebar-header {
        background: var(--primary);
        color: white;
        padding: 18px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 3px solid var(--accent);
    }
    .sidebar-header h3 { margin: 0; font-size: 1.1rem; }
    .close-sidebar-btn { background: none; border: none; color: white; font-size: 1.4rem; cursor: pointer; }

    .sidebar-user-info {
        padding: 16px 20px;
        background: var(--primary-light);
        border-bottom: 1px solid var(--border);
        display: flex; align-items: center; gap: 14px;
    }
    .sidebar-avatar {
        width: 46px; height: 46px;
        background: var(--primary); color: white;
        border-radius: 50%; display: flex;
        align-items: center; justify-content: center;
        font-size: 1.3rem; font-weight: bold;
        border: 2px solid var(--accent);
    }

    .sidebar-links { list-style: none; padding: 8px 0; margin: 0; flex: 1; overflow-y: auto; }
    .sidebar-links li a {
        display: flex; align-items: center; gap: 12px;
        padding: 13px 22px;
        color: var(--text-body);
        text-decoration: none;
        font-weight: 500; font-size: .92rem;
        border-left: 3px solid transparent;
        transition: .15s;
    }
    .sidebar-links li a:hover, .sidebar-links li a:active {
        background: var(--primary-light);
        border-left-color: var(--primary);
        color: var(--primary);
    }
    .sidebar-footer { padding: 18px 20px; border-top: 1px solid var(--border); }

    /* =========================================================
       3. Toast Notifications
       ========================================================= */
    #toast-container {
        position: fixed;
        bottom: 20px; left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        display: flex; flex-direction: column; gap: 8px;
        width: 90%; max-width: 400px;
        pointer-events: none;
    }
    .toast-msg {
        background: white;
        color: var(--text-body);
        padding: 13px 18px;
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-lg);
        display: flex; align-items: center; gap: 10px;
        font-weight: 500; font-size: .9rem;
        transform: translateY(80px); opacity: 0;
        transition: .35s cubic-bezier(.175,.885,.32,1.275);
        pointer-events: auto;
        border-left: 4px solid var(--primary);
    }
    .toast-msg.show { transform: translateY(0); opacity: 1; }
    .toast-msg.success { border-left-color: var(--success); }
    .toast-msg.error   { border-left-color: var(--danger);  }
    .toast-msg.warning { border-left-color: var(--warning); }
    .toast-icon  { font-size: 1.3rem; }
    .toast-close { margin-left: auto; background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.1rem; }

    /* =========================================================
       4. Responsive
       ========================================================= */
    @media (max-width: 768px) {
        .nav-desktop-links { display: none; }
        .hamburger-btn     { display: block; }
        .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr !important; }
    }
    @media (min-width: 769px) {
        button.btn-primary, .btn-primary { width: auto; }
    }

    /* =========================================================
       🚨 5-Minute Inactivity Warning Popup Styles
       ========================================================= */
    .inactivity-warning-popup {
        position: fixed;
        top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(15, 23, 42, 0.85);
        z-index: 10500;
        display: none;
        justify-content: center;
        align-items: center;
        backdrop-filter: blur(8px);
    }
    .inactivity-box {
        background: #ffffff;
        width: 90%;
        max-width: 420px;
        border-radius: 20px;
        padding: 35px 30px;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0,0,0,0.4);
        border: 2px solid #ef4444;
        transform: scale(0.9);
        opacity: 0;
        transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .inactivity-box.show { transform: scale(1); opacity: 1; }
    .inactivity-icon { font-size: 4rem; margin-bottom: 10px; animation: pulseWarning 1s infinite alternate; }
    @keyframes pulseWarning {
        0% { transform: scale(1); opacity: 0.8; text-shadow: 0 0 10px #ef4444; }
        100% { transform: scale(1.1); opacity: 1; text-shadow: 0 0 30px #ef4444; }
    }
    .countdown-text {
        font-size: 3.5rem;
        font-weight: 900;
        color: #ef4444;
        font-family: 'Share Tech Mono', monospace;
        margin: 15px 0;
        line-height: 1;
    }
</style>
</head>
<body>

<?php if(isset($_SESSION['user_id'])): ?>
<div class="inactivity-warning-popup" id="inactivityPopup">
    <div class="inactivity-box" id="inactivityBox">
        <div class="inactivity-icon">⏳</div>
        <h3 style="color: #0f172a; font-weight: 700; margin-bottom: 10px; font-size: 1.4rem;">คุณยังใช้งานอยู่หรือไม่?</h3>
        <p style="color: #64748b; font-size: 0.95rem; margin-bottom: 10px; line-height: 1.5;">
            ระบบตรวจพบว่าไม่มีการเคลื่อนไหวใดๆ บนหน้าจอ เพื่อความปลอดภัยของข้อมูล ระบบจะทำการออกจากระบบอัตโนมัติใน...
        </p>
        <div class="countdown-text" id="logoutCountdown">60</div>
        <p style="color: #94a3b8; font-size: 0.9rem; font-weight: bold; text-transform: uppercase;">วินาที</p>
        
        <button class="btn-primary w-100 py-3 mt-3" id="btnStayLoggedIn" style="font-size: 1.1rem;">
            ฉันยังใช้งานอยู่ (Stay Logged In)
        </button>
    </div>
</div>

<script>
    (function() {
        const MAX_INACTIVITY_MS = 300000; // 5 นาที
        const WARNING_TIME_MS = MAX_INACTIVITY_MS - 60000; // เด้งเตือนตอน 4 นาที
        
        let inactivityTimer;
        let countdownInterval;
        let timeLeft = 60;
        let isWarningVisible = false;

        const popup = document.getElementById('inactivityPopup');
        const box = document.getElementById('inactivityBox');
        const countdownEl = document.getElementById('logoutCountdown');
        const btnStay = document.getElementById('btnStayLoggedIn');

        function resetActivityTimer() {
            if (isWarningVisible) return;
            clearTimeout(inactivityTimer);
            clearInterval(countdownInterval);
            inactivityTimer = setTimeout(showInactivityWarning, WARNING_TIME_MS);
        }

        function showInactivityWarning() {
            isWarningVisible = true;
            timeLeft = 60;
            countdownEl.innerText = timeLeft;
            popup.style.display = 'flex';
            setTimeout(() => box.classList.add('show'), 10);

            countdownInterval = setInterval(() => {
                timeLeft--;
                countdownEl.innerText = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = 'logout.php?reason=timeout'; // เตะออกทันที
                }
            }, 1000);
        }

        if (btnStay) {
            btnStay.addEventListener('click', () => {
                box.classList.remove('show');
                setTimeout(() => popup.style.display = 'none', 300);
                clearInterval(countdownInterval);
                isWarningVisible = false;
                resetActivityTimer();
                fetch('api_keep_alive.php').catch(e => console.log(e));
                sendHeartbeat();
            });
        }

        const activeEvents = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'];
        activeEvents.forEach(evt => {
            document.addEventListener(evt, resetActivityTimer, { passive: true });
        });

        resetActivityTimer();
    })();
</script>
<?php endif; ?>

<?php if (isset($_SESSION['user_id'])): ?>
    <?php
        $dash_url = 'index.php';
        if(isset($_SESSION['role'])) {
            if($_SESSION['role'] === 'student')   $dash_url = 'dashboard_student.php';
            if($_SESSION['role'] === 'teacher')   $dash_url = 'dashboard_teacher.php';
            if($_SESSION['role'] === 'parent')    $dash_url = 'dashboard_parent.php';
            if($_SESSION['role'] === 'developer') $dash_url = 'dashboard_dev.php';
        }
        $display_initial = mb_substr($_SESSION['display_name'] ?? 'U', 0, 1, 'UTF-8');

        // ── Tenant Branding ─────────────────────────────────
        $brand_name    = 'Smart School Plus';
        $brand_color   = '#1e3a8a';
        if (isset($tenant) && is_array($tenant)) {
            $brand_name  = $tenant['school_name'] ?? $brand_name;
            $brand_color = $tenant['primary_color'] ?? $brand_color;
        }
    ?>
    <style>
        .navbar { background: <?= h($brand_color) ?> !important; }
        :root   { --primary: <?= h($brand_color) ?> !important; }
    </style>

    <div class="navbar">
        <a href="<?= $dash_url ?>" class="nav-brand">
            <?php
                $nav_logo = $head_logo; // คำนวณแล้วตอนต้น head
            ?>
            <img src="<?= htmlspecialchars($nav_logo) ?>" alt="โลโก้โรงเรียน" class="nav-brand-logo"
                 onerror="this.style.display='none'">
            <?= h($brand_name) ?>
        </a>
        
        <div class="nav-desktop-links">
            <span class="user-greeting">
                👋 <?= h($_SESSION['display_name'] ?? 'ผู้ใช้งาน') ?> 
                <span style="opacity:0.7; font-size:0.8em;">(<?= strtoupper(h($_SESSION['role'] ?? '')) ?>)</span>
            </span>
            <a href="<?= $dash_url ?>">🏠 หน้าหลัก</a>
            <a href="edit_profile.php">✏️ โปรไฟล์</a>
            <a href="logout.php" style="background: rgba(239, 68, 68, 0.9); padding: 6px 12px; border-radius: 6px;">🚪 ออกจากระบบ</a>
        </div>

        <button class="hamburger-btn" onclick="toggleMobileMenu()">☰</button>
    </div>

    <div class="mobile-sidebar-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>
    <div class="mobile-sidebar" id="mobileSidebar">
        <div class="sidebar-header">
            <h3>เมนูหลัก</h3>
            <button class="close-sidebar-btn" onclick="toggleMobileMenu()">✖</button>
        </div>
        
        <div class="sidebar-user-info">
            <div class="sidebar-avatar"><?= h($display_initial) ?></div>
            <div>
                <div style="font-weight: bold; color: var(--text-dark);"><?= h($_SESSION['display_name'] ?? 'ผู้ใช้งาน') ?></div>
                <div style="font-size: 0.8rem; color: var(--text-muted);"><?= strtoupper(h($_SESSION['role'] ?? '')) ?></div>
            </div>
        </div>

        <ul class="sidebar-links">
            <li><a href="<?= $dash_url ?>">🏠 หน้าหลัก (Dashboard)</a></li>
            <li><a href="edit_profile.php">✏️ แก้ไขโปรไฟล์</a></li>
            <?php if(isset($_SESSION['role'])): ?>
                <?php if($_SESSION['role'] === 'student'): ?>
                    <li><a href="manage_timetable.php">📅 ดูตารางเรียน</a></li>
                    <li><a href="lab_realistic.php">🥽 ห้องทดลอง 3D</a></li>
                    <li><a href="leaderboard.php">🏆 หอเกียรติยศ</a></li>
                <?php elseif($_SESSION['role'] === 'teacher'): ?>
                    <li><a href="manage_timetable.php">📅 ดูตารางสอน</a></li>
                    <li><a href="teacher_reports.php">📋 ตรวจรายงานนักเรียน</a></li>
                    <li><a href="create_quest.php">📝 สร้างภารกิจใหม่</a></li>
                    <li><a href="smart_attendance.php">📱 ระบบเช็คชื่อ QR</a></li>
                <?php elseif($_SESSION['role'] === 'developer' || $_SESSION['role'] === 'developer'): ?>
                    <li><a href="manage_timetable.php">🗓️ จัดการตารางสอน (Smart)</a></li>
                    <li><a href="import_timetable.php">📥 นำเข้าตาราง (Excel)</a></li>
                    <li><a href="user_manager.php">👥 จัดการผู้ใช้ในระบบ</a></li>
                    <li><a href="dev_ops_hub.php">⚙️ ตั้งค่าและสำรองข้อมูล</a></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>

        <div class="sidebar-footer">
            <a href="logout.php" class="btn-primary" style="background: var(--danger); text-align: center; display: block;">🚪 ออกจากระบบ</a>
        </div>
    </div>
<?php endif; ?>

<div id="toast-container"></div>

<div class="container">

<script>
    // --- 0. Online Status Heartbeat ---
    function sendHeartbeat() {
        const page = window.location.pathname.split('/').pop() || 'index.php';
        const body = new FormData();
        body.append('page', page);
        fetch('api_heartbeat.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).catch(() => {});
    }
    // ส่ง heartbeat ทันที + ทุก 60 วินาที
    sendHeartbeat();
    setInterval(sendHeartbeat, 60000);

    // --- 1. PWA Service Worker Registration ---
    // SW disabled temporarily

    // --- 2. Mobile Menu Control ---
    function toggleMobileMenu() {
        const sidebar = document.getElementById('mobileSidebar');
        const overlay = document.getElementById('mobileOverlay');
        if(sidebar && overlay) {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            if(sidebar.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }
    }

    // --- 3. Toast Notifications ---
    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if(!container) return;

        const toast = document.createElement('div');
        toast.className = `toast-msg ${type}`;
        
        let icon = '💬';
        if(type === 'success') icon = '✅';
        if(type === 'error') icon = '❌';
        if(type === 'warning') icon = '⚠️';

        toast.innerHTML = `
            <span class="toast-icon">${icon}</span>
            <span style="flex: 1;">${message}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">✖</button>
        `;

        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400); 
        }, 3500);
    }

    // Override alert()
    window.nativeAlert = window.alert; 
    window.alert = function(message) {
        let type = 'info';
        let msgStr = String(message).toLowerCase();
        if(msgStr.includes('สำเร็จ') || msgStr.includes('success')) type = 'success';
        if(msgStr.includes('ผิดพลาด') || msgStr.includes('error') || msgStr.includes('ไม่สามารถ')) type = 'error';
        if(msgStr.includes('เตือน') || msgStr.includes('warning') || msgStr.includes('กรุณา')) type = 'warning';
        showToast(message, type);
    };
</script>