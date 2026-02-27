<?php
// logout.php - ระบบออกจากระบบแบบสมบูรณ์ (Clear Session & Cache)
require_once 'config.php';
require_once 'db.php';
require_once 'logger.php';

// 1. เริ่มต้น Session เพื่อให้รู้จักว่าใครกำลังจะออก
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. บันทึกประวัติการออกจากระบบลงระบบ Audit Log (ถ้ามีข้อมูลล็อกอินอยู่)
if (isset($_SESSION['user_id'])) {
    systemLog($_SESSION['user_id'], 'LOGOUT_SUCCESS', "User {$_SESSION['username']} logged out");
}

// 3. ล้างค่าตัวแปร Session ทั้งหมดในหน่วยความจำ
$_SESSION = array();

// 4. ลบคุกกี้ Session ของเบราว์เซอร์ (สำคัญมาก ป้องกันการสวมรอย)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 5. ทำลาย Session ฝั่งเซิร์ฟเวอร์ทิ้งอย่างถาวร
session_destroy();

// 6. ส่ง Header สั่งให้เบราว์เซอร์ "ห้ามจำ" หน้าเว็บนี้
// ป้องกันปัญหาผู้ใช้กดปุ่ม "ย้อนกลับ (Back)" แล้วเห็นหน้า Dashboard เดิม
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 7. พากลับไปหน้าเข้าสู่ระบบ
header("Location: index.php");
exit;
?>