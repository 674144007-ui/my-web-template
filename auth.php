<?php
// auth.php - Session Manager (No Loop Version)

// 1. เริ่ม Buffer ทันที
if (ob_get_level() == 0) ob_start();

// 2. ป้องกัน Browser Cache หน้าเว็บ (สาเหตุหลักของ Loop)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 3. เริ่ม Session
if (session_status() === PHP_SESSION_NONE) {
    // บังคับ Cookie Path ให้ใช้ได้ทั้งเว็บ
    session_set_cookie_params(0, '/');
    
    // ตั้งชื่อ Session ใหม่ หนีค่าเก่าที่ค้างใน Browser
    session_name('NO_LOOP_SYSTEM');
    
    session_start();
}

/**
 * ฟังก์ชันตรวจสอบสถานะ Login
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * ดึงข้อมูลผู้ใช้
 */
function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'            => $_SESSION['user_id'],
        'username'      => $_SESSION['username'],
        'display_name'  => $_SESSION['display_name'],
        'role'          => $_SESSION['role'],
        'class_level'   => $_SESSION['class_level'] ?? null,
        'subject_group' => $_SESSION['subject_group'] ?? null,
        'teacher_department' => $_SESSION['teacher_department'] ?? null
    ];
}

/**
 * ฟังก์ชันตรวจสอบสิทธิ์ (Stop Loop Logic)
 */
function requireRole($roles) {
    // 1. ถ้ายังไม่ล็อกอิน
    if (!isLoggedIn()) {
        // ดีดกลับหน้า Login ทันที
        header("Location: index.php");
        exit;
    }

    if (!is_array($roles)) $roles = [$roles];
    $myRole = $_SESSION['role'] ?? '';

    // 2. ถ้าล็อกอินแล้ว แต่ Role ผิด (Access Denied)
    // ❌ ห้าม Redirect กลับ index.php เพราะจะทำให้เกิด Loop ❌
    // ✅ ให้แสดงข้อความ Error และหยุดทำงานแทน
    if (!in_array($myRole, $roles)) {
        http_response_code(403);
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Access Denied</title></head>";
        echo "<body style='background:#1a1a1a; color:white; font-family:sans-serif; text-align:center; padding-top:50px;'>";
        echo "<h1 style='color:#ff4444; font-size:3rem;'>⛔ 403 Access Denied</h1>";
        echo "<h2>คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h2>";
        echo "<p>Role ของคุณคือ: <strong style='color:#ffff00;'>" . htmlspecialchars($myRole) . "</strong></p>";
        echo "<p>หน้านี้ต้องการ: <strong>" . implode(", ", $roles) . "</strong></p>";
        echo "<br><a href='index.php' style='background:white; color:black; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>กลับหน้า Login</a>";
        echo "<br><br><a href='logout.php' style='color:#ff8888;'>ออกจากระบบ</a>";
        echo "</body></html>";
        exit;
    }
}
?>