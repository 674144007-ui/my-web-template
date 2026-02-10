<?php
// auth.php - เพิ่มระบบ Tracking & Logging
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(0, '/');
    session_start();
}

// ป้องกัน Cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'db.php'; // เรียกใช้ connection

/**
 * ฟังก์ชันตรวจสอบสถานะ Login และอัปเดตเวลาออนไลน์ (Real-time Check)
 */
function checkLoginStatus() {
    global $conn;
    if (isset($_SESSION['user_id'])) {
        $uid = $_SESSION['user_id'];
        // อัปเดตเวลาล่าสุดที่ใช้งาน (Heartbeat)
        $conn->query("UPDATE users SET last_activity = NOW() WHERE id = $uid");
        return true;
    }
    return false;
}

// เรียกใช้ทันทีทุกครั้งที่มีการโหลดหน้าเว็บที่ include auth.php
checkLoginStatus();

/**
 * ตรวจสอบ Role
 */
function requireRole($allowed_roles) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    $my_role = $_SESSION['role'] ?? '';
    if ($my_role === 'admin') $my_role = 'developer';

    if (!in_array($my_role, $allowed_roles)) {
        http_response_code(403);
        die("⛔ Access Denied: คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
    }
}

/**
 * บันทึกประวัติการ Login (เรียกใช้ตอน Login สำเร็จ)
 */
function logLogin($user_id, $role) {
    global $conn;
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO login_logs (user_id, role, ip_address) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $user_id, $role, $ip);
    $stmt->execute();
}

/**
 * ดึงข้อมูลผู้ใช้ปัจจุบัน
 */
function currentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    return [
        'id'            => $_SESSION['user_id'],
        'username'      => $_SESSION['username'],
        'display_name'  => $_SESSION['display_name'],
        'role'          => $_SESSION['role'],
        'class_level'   => $_SESSION['class_level'] ?? null
    ];
}
?>