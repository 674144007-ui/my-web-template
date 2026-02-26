<?php
// เริ่ม session ก่อนทำลาย
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตั้งค่า cookie หมดอายุเพื่อลบ session จาก client
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000, // หมดอายุ
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// ทำลาย session ฝั่ง server
session_unset();
session_destroy();

// ป้องกัน browser cache หน้าก่อน logout
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// เปลี่ยน session id ใหม่ (ป้องกัน fixation)
session_start();
session_regenerate_id(true);

// Redirect ไปหน้า login
header("Location: login.php");
exit;
