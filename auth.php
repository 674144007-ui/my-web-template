<?php
// auth.php — จัดการ session และระบบสิทธิ์เข้าใช้งานให้ปลอดภัยขึ้น

// --- ตั้งค่า session security ---
if (session_status() === PHP_SESSION_NONE) {

    // ป้องกัน Session Hijacking
    ini_set('session.cookie_httponly', 1); 
    ini_set('session.use_only_cookies', 1);

    // ถ้าเป็น HTTPS แนะนำให้เปิด
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }

    // เริ่ม session
    session_start();

    // ป้องกัน Session Fixation: เริ่มครั้งแรก regenerate id
    if (!isset($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
    }
}

/**
 * สร้าง CSRF Token ประจำ Session (ใช้ป้องกัน Cross-Site Request Forgery)
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * ตรวจสอบความถูกต้องของ CSRF Token ที่ส่งมาจาก Form
 */
function verify_csrf_token($token_from_post) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_from_post)) {
        http_response_code(403);
        die("❌ Security Error: CSRF Token ไม่ถูกต้อง หรือ Session หมดอายุ กรุณารีเฟรชหน้าเว็บแล้วลองใหม่");
    }
}

/**
 * ฟังก์ชันย่อสำหรับป้องกัน XSS (Cross-Site Scripting)
 * ใช้คลุมตัวแปรทุกตัวที่นำมาแสดงผลบน HTML
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * ตรวจว่า login อยู่หรือยัง
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * ดึงข้อมูลผู้ใช้งานปัจจุบัน
 */
function currentUser() {
    if (!isLoggedIn()) return null;

    return [
        'id'                 => $_SESSION['user_id'],
        'username'           => $_SESSION['username'],
        'display_name'       => $_SESSION['display_name'],
        'role'               => $_SESSION['role'],
        'class_level'        => $_SESSION['class_level'] ?? null
    ];
}

/**
 * บังคับให้ต้อง login ก่อนถึงจะเข้าได้
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: index.php"); // เปลี่ยนกลับไปหน้า index (login)
        exit;
    }
}

/**
 * ตรวจสิทธิ์ role เช่น requireRole('teacher') หรือ requireRole(['teacher', 'developer'])
 */
function requireRole($roles) {
    requireLogin();

    $userRole = $_SESSION['role'];

    if (!is_array($roles)) {
        $roles = [$roles];
    }

    if (!in_array($userRole, $roles)) {
        http_response_code(403);

        // แสดงหน้าห้ามเข้าแบบปลอดภัย
        echo "<!DOCTYPE html><html lang='th'><head><meta charset='UTF-8'><title>Access Denied</title>";
        echo "<style>body{font-family:system-ui;text-align:center;padding:50px;background:#fef2f2;color:#991b1b;}</style></head><body>";
        echo "<h2>🛑 403 - ไม่อนุญาตให้เข้าถึง</h2>";
        echo "<p>สิทธิ์ปัจจุบันของคุณคือ: <strong>" . h($userRole) . "</strong></p>";
        echo "<p>หน้านี้ต้องการสิทธิ์ระดับ: " . h(implode(", ", $roles)) . "</p>";
        echo "<br><a href='index.php' style='padding:10px 20px;background:#991b1b;color:white;text-decoration:none;border-radius:8px;'>กลับหน้าหลัก</a>";
        echo "</body></html>";
        exit;
    }
}
?>