<?php
// ===================================================================================
// FILE: auth.php
// ระบบตรวจสอบตัวตนและ Session (Fix: Duplicate Session Start & Strict Output)
// ===================================================================================

// ป้องกันการเรียก session_start() ซ้ำ ซึ่งจะทำให้เกิด Notice Error
if (session_status() === PHP_SESSION_NONE) {
    // ตั้งค่า Cookie ให้ปลอดภัยขึ้น
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false, // เปลี่ยนเป็น true ถ้าใช้ HTTPS
        'httponly' => true,
        'samesite' => 'Lax' // ป้องกัน CSRF ระดับหนึ่ง
    ]);
    session_start();
}

// ป้องกัน Cache อย่างเด็ดขาด เพื่อไม่ให้กด Back แล้วเจอข้อมูลเก่า
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

// โหลดไฟล์ Database แบบเงียบ (ถ้ามีปัญหาให้หยุดทันที)
require_once 'db.php'; 

/**
 * ตรวจสอบว่า Login หรือยัง
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0;
}

/**
 * ตรวจสอบสถานะและอัปเดตเวลาล่าสุด (FIX: ป้องกันคอขวดโดยอัปเดตทุกๆ 5 นาทีแทนการอัปเดตทุกรีเฟรช)
 */
function checkLoginStatus() {
    global $conn;
    if (isLoggedIn()) {
        $uid = intval($_SESSION['user_id']);
        
        // หน่วงเวลาการอัปเดต Last Activity (300 วินาที = 5 นาที) เพื่อลดภาระ Database
        if (!isset($_SESSION['last_activity_update']) || (time() - $_SESSION['last_activity_update']) > 300) {
            try {
                if ($conn) {
                    $conn->query("UPDATE users SET last_activity = NOW() WHERE id = $uid");
                    $_SESSION['last_activity_update'] = time();
                }
            } catch (Exception $e) {
                // เงียบไว้ ไม่ต้องพ่น Error ออกมา
            }
        }
        return true;
    }
    return false;
}

// เรียกใช้ทันที
checkLoginStatus();

/**
 * ตรวจสอบสิทธิ์ (Permission Check) แบบเข้มงวด
 */
function requireRole($allowed_roles) {
    if (!isLoggedIn()) {
        // ถ้ายังไม่ล็อกอิน ดีดกลับไปหน้าแรก
        header("Location: index.php");
        exit;
    }
    
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    $my_role = $_SESSION['role'] ?? 'guest';
    
    // Admin ถือเป็น Developer
    if ($my_role === 'admin') {
        $my_role = 'developer';
    }

    if (!in_array($my_role, $allowed_roles)) {
        // แสดง Error 403 แบบชัดเจน
        if (!headers_sent()) http_response_code(403);
        echo "<!DOCTYPE html><html><body style='background:#f8f9fa; font-family:sans-serif; display:flex; justify-content:center; align-items:center; height:100vh;'>";
        echo "<div style='background:white; padding:40px; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.1); text-align:center;'>";
        echo "<h1 style='color:#dc2626; font-size:40px; margin:0 0 10px 0;'>🚫 Access Denied</h1>";
        echo "<p style='color:#4b5563; font-size:18px;'>คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (Role: <strong>" . htmlspecialchars($my_role) . "</strong>)</p>";
        echo "<a href='index.php' style='display:inline-block; margin-top:20px; padding:10px 25px; background:#2563eb; color:white; text-decoration:none; border-radius:30px; font-weight:bold;'>กลับหน้าหลัก</a>";
        echo "</div></body></html>";
        exit;
    }
}

/**
 * ดึงข้อมูลผู้ใช้ปัจจุบันแบบปลอดภัย (Safe Fetch)
 */
function currentUser() {
    if (!isLoggedIn()) return null;
    
    // คืนค่า Default ป้องกัน Array Key Missing
    return [
        'id'            => $_SESSION['user_id'] ?? 0,
        'username'      => $_SESSION['username'] ?? 'Unknown',
        'display_name'  => $_SESSION['display_name'] ?? 'User',
        'role'          => $_SESSION['role'] ?? 'student',
        'class_level'   => $_SESSION['class_level'] ?? ''
    ];
}
?>