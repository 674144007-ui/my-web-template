<?php
/**
 * ===================================================================================
 * [CORE ROUTING ENGINE] FILE: index.php
 * ===================================================================================
 * เวอร์ชัน: 4.0.0 (Ultimate Developer Edition)
 * หน้าที่: ตัดสินใจทิศทางของผู้ใช้ (Traffic Controller)
 * การแก้ไข: แก้ไขปัญหา Infinite Login Loop และการไม่นำทางไปหน้า Developer Dashboard
 * ===================================================================================
 */

// 1. เริ่มการจัดการ Output Buffer เพื่อป้องกันความผิดพลาดของ Header
// หากมีข้อความใดๆ พ่นออกมาก่อน header() ระบบจะพังทันที เราจึงต้องใช้ ob_start
if (ob_get_level() == 0) {
    ob_start();
}

// 2. เปิดการแสดง Error ทั้งหมด (เฉพาะในโหมดพัฒนาระบบ)
// เพื่อให้เราเห็นว่ามันตายตรงบรรทัดไหน แทนที่จะเห็นหน้าขาว (WSOD)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 3. ระบบจัดการ Session (หัวใจสำคัญของปัญหา Login)
// เราต้องมั่นใจว่า Session เริ่มต้นอย่างสมบูรณ์และไม่มีอักขระแปลกปลอมนำหน้า
if (session_status() === PHP_SESSION_NONE) {
    // ตั้งค่า Session ให้มีความปลอดภัยสูงสุด
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false, // เปลี่ยนเป็น true หากใช้ https
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 4. โหลดไฟล์ทรัพยากรหลัก
// ใช้ require_once เพื่อป้องกันการโหลดซ้ำ และใช้ __DIR__ เพื่ออ้างอิง Path ที่แน่นอน
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// [DEBUG BLOCK] - ส่วนตรวจสอบสถานะ Session เบื้องต้น
// หากคุณรันหน้านี้แล้วเห็นข้อความเหล่านี้ แสดงว่าข้อมูลใน Session มีปัญหา
$session_user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'MISSING';
$session_role = $_SESSION['role'] ?? 'MISSING';

/**
 * ===================================================================================
 * [CRITICAL CHECK] ฟังก์ชันตรวจสอบสถานะ Login
 * ===================================================================================
 * หากฟังก์ชัน isLoggedIn() ใน auth.php คืนค่าเป็นเท็จ 
 * ระบบจะทำลาย Session ที่อาจจะ "ค้าง" หรือ "พัง" ทิ้ง แล้วส่งไป Login ใหม่
 */
if (!isLoggedIn()) {
    // ล้างค่าทิ้งเพื่อเริ่มต้นใหม่ ป้องกัน Loop
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    // ส่งกลับไปหน้า Login พร้อมส่ง Code เพื่อบอกสาเหตุ (ถ้าต้องการ)
    header("Location: login.php?reason=not_logged_in");
    exit;
}

/**
 * 5. ดึงข้อมูล User จากหน่วยความจำ Session อย่างปลอดภัย
 */
$user = currentUser();

// กรณีฉุกเฉิน: มี Session แต่หา User ในฐานข้อมูลไม่เจอ (เช่น User โดนลบไปแล้ว)
if (!$user) {
    session_destroy();
    header("Location: login.php?error=user_not_found");
    exit;
}

// ทำความสะอาดค่า Role เพื่อป้องกัน Case-Sensitive (เช่น developer vs Developer)
$current_role = isset($user['role']) ? strtolower(trim($user['role'])) : 'guest';

/**
 * ===================================================================================
 * [ROUTING LOGIC] การนำทางตามบทบาท (Role-Based Redirection)
 * ===================================================================================
 * ส่วนนี้คือจุดที่คุณแจ้งว่า "ไอดี dev แต่ไปหน้าครู" 
 * ผมได้ทำการจัดลำดับความสำคัญ (Priority) ให้ Developer อยู่บนสุด
 */

switch ($current_role) {

    // --- สิทธิ์สูงสุด: DEVELOPER / ADMIN ---
    case 'developer':
    case 'admin':
    case 'dev':
        // แก้ไข: บังคับไปที่หน้า dashboard_dev.php โดยตรง
        header("Location: dashboard_dev.php");
        exit;
        break;

    // --- สิทธิ์: TEACHER (ครู) ---
    case 'teacher':
    case 'instructor':
        header("Location: dashboard_teacher.php");
        exit;
        break;

    // --- สิทธิ์: STUDENT (นักเรียน) ---
    case 'student':
    case 'pupil':
        header("Location: dashboard_student.php");
        exit;
        break;

    // --- สิทธิ์: PARENT (ผู้ปกครอง) ---
    case 'parent':
    case 'guardian':
        header("Location: dashboard_parent.php");
        exit;
        break;

    // --- กรณีที่ระบบหา Role ไม่เจอหรือไม่รู้จัก ---
    default:
        // ตรวจสอบว่าใน Session มีค่าอะไรหลุดมา
        $error_msg = urlencode("Unknown Role: " . $current_role);
        header("Location: profile.php?error=" . $error_msg);
        exit;
        break;
}

/**
 * ===================================================================================
 * [FALLBACK HTML] ส่วนแสดงผลกรณีการ Redirect ล้มเหลว
 * ===================================================================================
 */
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Routing | ระบบกำลังนำทาง...</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0; padding: 0;
            background: #0f172a;
            color: #f1f5f9;
            font-family: 'Prompt', sans-serif;
            display: flex; justify-content: center; align-items: center;
            height: 100vh; overflow: hidden;
        }
        .routing-card {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 50px;
            border-radius: 24px;
            text-align: center;
            backdrop-filter: blur(10px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            max-width: 500px; width: 90%;
        }
        .spinner {
            width: 50px; height: 50px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-left-color: #3b82f6;
            border-radius: 50%;
            margin: 0 auto 30px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h1 { font-size: 22px; margin-bottom: 10px; }
        p { color: #94a3b8; font-size: 15px; line-height: 1.6; }
        .debug-console {
            margin-top: 30px;
            padding: 15px;
            background: #000;
            color: #4ade80;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            text-align: left;
            border-radius: 10px;
            border: 1px solid #1e293b;
        }
        .btn-manual {
            display: inline-block;
            margin-top: 25px;
            padding: 12px 30px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn-manual:hover { background: #2563eb; transform: translateY(-2px); }
    </style>
</head>
<body>

<div class="routing-card">
    <div class="spinner"></div>
    <h1>กำลังเปลี่ยนเส้นทาง...</h1>
    <p>ระบบตรวจพบสิทธิ์การใช้งานของคุณเป็นระดับ <strong>[<?= strtoupper($current_role) ?>]</strong> และกำลังนำคุณไปยังหน้าที่เหมาะสม</p>
    
    <a href="dashboard_dev.php" class="btn-manual">กดที่นี่หากหน้าเว็บไม่เปลี่ยนอัตโนมัติ</a>

    <div class="debug-console">
        > system_init: Success<br>
        > session_check: Valid<br>
        > user_id: <?= $session_user_id ?><br>
        > role_detected: <?= $current_role ?><br>
        > redirect_to: dashboard_<?= $current_role ?>.php
    </div>
</div>

<script>
    // ป้องกันกรณี Server-side Redirect ล้มเหลวด้วย Client-side JavaScript
    setTimeout(function() {
        const role = "<?= $current_role ?>";
        if (role === 'developer' || role === 'admin' || role === 'dev') {
            window.location.href = "dashboard_dev.php";
        } else if (role !== 'guest') {
            window.location.href = "dashboard_" + role + ".php";
        }
    }, 1500);
</script>

</body>
</html>
<?php
// พ่นข้อมูลออกจาก Buffer และสิ้นสุดการทำงาน
ob_end_flush();
exit;
?>