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
        'subject_group'      => $_SESSION['subject_group'] ?? null,
        'teacher_department' => $_SESSION['teacher_department'] ?? null
    ];
}

/**
 * บังคับให้ต้อง login ก่อนถึงจะเข้าได้
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
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
        echo "<h2 style='color:red'>403 - ไม่อนุญาตให้เข้าถึง</h2>";
        echo "<p>สิทธิ์ของคุณ: <strong>{$userRole}</strong></p>";
        echo "<p>ต้องการสิทธิ์: " . implode(", ", $roles) . "</p>";
        exit;
    }
}
?>