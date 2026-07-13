<?php
require_once 'security.php';
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

try {

// -----------------------------------------------------
// ต้องใช้ POST ในการลบเท่านั้น (ป้องกันลบ via GET)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("❌ Method Not Allowed");
}

// -----------------------------------------------------
// ตรวจสอบ CSRF token
// -----------------------------------------------------
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) 
{
    http_response_code(403);
    exit("❌ Invalid CSRF Token");
}

// -----------------------------------------------------
// รับค่าจาก POST
// -----------------------------------------------------
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    exit("❌ รหัสไม่ถูกต้อง");
}

// -----------------------------------------------------
// ตรวจสอบว่ารายการตารางสอนนี้มีอยู่จริง
// -----------------------------------------------------
$check = $pdo->prepare("SELECT id FROM teacher_schedule WHERE id = ?");
$check->execute([$id]);
$check->execute();

if ($check->rowCount() === 0) {
    exit("❌ ไม่พบข้อมูลตารางสอน");
}


// -----------------------------------------------------
// ลบข้อมูลแบบปลอดภัย
// -----------------------------------------------------
$del = $pdo->prepare("DELETE FROM teacher_schedule WHERE id = ?");
$del->execute([$id]);

if ($del->execute()) {
    header("Location: dev_view_schedule.php?deleted=1");
    exit;
} else {
    header("Location: dev_view_schedule.php?deleted=0");
    exit;
}
    } catch (\PDOException $e) {
        error_log('[db_error] ' . $e->getMessage() . ' in ' . __FILE__ . ':' . __LINE__);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $is_dev = defined('APP_ENV') && APP_ENV === 'development' && defined('APP_DEBUG') && APP_DEBUG;
        $msg = $is_dev ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'กรุณาลองใหม่อีกครั้ง';
        die('<div style="font-family:sans-serif;text-align:center;padding:3rem">'
          . '<h3 style="color:#ef4444">เกิดข้อผิดพลาดในระบบฐานข้อมูล</h3>'
          . '<p style="color:#6b7280">' . $msg . '</p>'
          . '<a href="index.php" style="color:#3b82f6">← กลับหน้าหลัก</a>'
          . '</div>');
    }
