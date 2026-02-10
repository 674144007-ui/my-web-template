<?php
// fix_access.php - สคริปต์แก้สิทธิ์ฉุกเฉิน (Run once then delete)
require_once 'db.php';
session_start();

// 1. บังคับแก้ Database
$username = 'PichayaY.';
$sql = "UPDATE users SET role = 'developer', original_role = 'developer' WHERE username = '$username'";

if ($conn->query($sql) === TRUE) {
    echo "<h1>✅ 1. Database Updated!</h1>";
} else {
    echo "<h1>❌ Database Error: " . $conn->error . "</h1>";
}

// 2. บังคับแก้ Session เดี๋ยวนี้
$_SESSION['role'] = 'developer';
$_SESSION['original_role'] = 'developer';
// ล้างค่า Role จำลองออก (ถ้ามี)
unset($_SESSION['simulated_role']); 

echo "<h1>✅ 2. Session Forced to 'developer'</h1>";
echo "<h3>Current Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<hr>";
echo "<h2>🎉 เสร็จสิ้น! ตอนนี้คุณคือ Developer เต็มตัวแล้ว</h2>";
echo "<a href='index.php' style='font-size:20px; font-weight:bold; color:green;'>คลิกที่นี่เพื่อกลับหน้าหลัก</a>";
?>