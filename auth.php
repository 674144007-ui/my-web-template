<?php
// ===================================================================================
// FILE: auth.php
// ระบบตรวจสอบตัวตนและ Session (Fix HTTP 500: รองรับ PHP ทุกเวอร์ชั่น)
// ===================================================================================

// 1. เปิด Buffer อย่างปลอดภัย
if (ob_get_level() == 0) {
    ob_start();
}

// 2. จัดการ Session แบบรองรับ PHP ย้อนหลัง (แก้บัค 500)
if (session_status() === PHP_SESSION_NONE) {
    // ใช้ parameter แถวเรียงแทน Array เพื่อให้รันได้ตั้งแต่ PHP 5.6 - 8.x
    session_set_cookie_params(0, '/', '', false, true);
    session_start();
}

// 3. ป้องกัน Cache
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

// โหลดไฟล์ Database
require_once __DIR__ . '/db.php'; 

// ป้องกันปัญหา Cannot redeclare function หากมีการเรียก auth.php ซ้ำ
if (!function_exists('syncSessionKeys')) {
    function syncSessionKeys() {
        if (isset($_SESSION['id']) && !isset($_SESSION['user_id'])) {
            $_SESSION['user_id'] = $_SESSION['id'];
        } elseif (isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
            $_SESSION['id'] = $_SESSION['user_id'];
        }
    }
}
syncSessionKeys();

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) || 
               (isset($_SESSION['id']) && intval($_SESSION['id']) > 0);
    }
}

if (!function_exists('checkLoginStatus')) {
    function checkLoginStatus() {
        global $conn;
        if (isLoggedIn()) {
            $uid = intval($_SESSION['user_id'] ?? $_SESSION['id']);
            if (!isset($_SESSION['last_activity_update']) || (time() - $_SESSION['last_activity_update']) > 300) {
                try {
                    if (isset($conn) && $conn instanceof mysqli) {
                        $conn->query("UPDATE users SET last_activity = NOW() WHERE id = $uid");
                        $_SESSION['last_activity_update'] = time();
                    }
                } catch (Exception $e) {
                    // ป้องกัน 500 Error ถ้ายังไม่ได้สร้างคอลัมน์ last_activity
                }
            }
            return true;
        }
        return false;
    }
}
checkLoginStatus();

if (!function_exists('requireRole')) {
    function requireRole($allowed_roles) {
        if (!isLoggedIn()) {
            if (!headers_sent()) {
                header("Location: index.php");
            } else {
                echo "<script>window.location.href='index.php';</script>";
            }
            exit;
        }
        
        if (!is_array($allowed_roles)) {
            $allowed_roles = [$allowed_roles];
        }
        
        $my_role = strtolower(trim($_SESSION['role'] ?? 'guest'));
        
        $effective_roles = [$my_role];
        if ($my_role === 'admin') $effective_roles[] = 'developer';
        if ($my_role === 'developer') $effective_roles[] = 'admin';

        $has_access = false;
        foreach ($effective_roles as $role) {
            if (in_array($role, $allowed_roles)) {
                $has_access = true;
                break;
            }
        }

        if (!$has_access) {
            if (!headers_sent()) http_response_code(403);
            echo "<!DOCTYPE html><html lang='th'><head><meta charset='UTF-8'><title>Access Denied</title>";
            echo "<link href='https://fonts.googleapis.com/css2?family=Itim&display=swap' rel='stylesheet'></head>";
            echo "<body style='background:#f0f4f8; font-family: \"Itim\", cursive; display:flex; justify-content:center; align-items:center; height:100vh; margin:0;'>";
            echo "<div style='background:white; padding:40px; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,0.1); text-align:center; max-width:450px; width:90%; border-top: 5px solid #ef4444;'>";
            echo "<div style='font-size: 60px; margin-bottom: 10px;'>🚫</div>";
            echo "<h1 style='color:#dc2626; font-size:32px; margin:0 0 15px 0;'>Access Denied</h1>";
            echo "<p style='color:#4b5563; font-size:18px;'>คุณไม่มีสิทธิ์เข้าถึงหน้านี้<br>ระดับสิทธิ์ของคุณคือ: <strong style='background:#4f46e5; color:white; padding:4px 12px; border-radius:20px;'>" . htmlspecialchars($my_role) . "</strong></p>";
            echo "<a href='index.php' style='display:inline-block; margin-top:25px; padding:12px 30px; background:#ef4444; color:white; text-decoration:none; border-radius:30px; font-size: 18px;'>⬅ กลับหน้าหลัก</a>";
            echo "</div></body></html>";
            exit;
        }
    }
}

if (!function_exists('currentUser')) {
    function currentUser() {
        if (!isLoggedIn()) return null;
        return [
            'id'            => $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0,
            'username'      => $_SESSION['username'] ?? 'Unknown',
            'display_name'  => $_SESSION['display_name'] ?? 'User',
            'role'          => $_SESSION['role'] ?? 'student',
            'class_level'   => $_SESSION['class_level'] ?? ''
        ];
    }
}
?>