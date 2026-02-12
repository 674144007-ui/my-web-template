<?php
// auth.php - ระบบตรวจสอบตัวตนและ Session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(0, '/');
    session_start();
}

// ป้องกัน Cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php'; 

/**
 * ✅ ฟังก์ชันตรวจสอบว่า Login หรือยัง (แก้ไข Error ที่นี่)
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * ฟังก์ชันตรวจสอบสถานะ Login และอัปเดตเวลาออนไลน์
 */
function checkLoginStatus() {
    global $conn;
    if (isLoggedIn()) {
        $uid = $_SESSION['user_id'];
        // อัปเดต Heartbeat
        $conn->query("UPDATE users SET last_activity = NOW() WHERE id = $uid");
        return true;
    }
    return false;
}

// เรียกใช้ Check ทันที
checkLoginStatus();

/**
 * ตรวจสอบ Role (Permission Check)
 */
function requireRole($allowed_roles) {
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit;
    }
    
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    $my_role = $_SESSION['role'] ?? '';
    
    // Admin คือ Developer ในระบบนี้
    if ($my_role === 'admin') $my_role = 'developer';

    if (!in_array($my_role, $allowed_roles)) {
        http_response_code(403);
        echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>";
        echo "<h1>⛔ Access Denied</h1>";
        echo "<p>คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (Role: $my_role)</p>";
        echo "<a href='index.php'>กลับหน้าหลัก</a>";
        echo "</div>";
        exit;
    }
}

/**
 * ดึงข้อมูลผู้ใช้ปัจจุบัน
 */
function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'            => $_SESSION['user_id'],
        'username'      => $_SESSION['username'],
        'display_name'  => $_SESSION['display_name'],
        'role'          => $_SESSION['role'],
        'class_level'   => $_SESSION['class_level'] ?? null
    ];
}
?>