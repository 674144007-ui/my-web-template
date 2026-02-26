<?php
/**
 * ===================================================================================
 * FILE: auth.php (ADVANCED AUTHENTICATION & SESSION GATEWAY)
 * ===================================================================================
 * โปรเจกต์: ระบบบริหารจัดการโรงเรียนบ้านคาวิทยา (Bankha Withaya School)
 * หน้าที่: ควบคุมการเข้าถึง, ตรวจสอบตัวตน และจัดการลำดับความสำคัญของบทบาท (Role Priority)
 * แก้ไขปัญหา: สิทธิ์ Developer/Admin สับสน และการ Redirect ผิดพลาดไปยังแดชบอร์ดครู
 * ===================================================================================
 */

// [SECTION 1] - การจัดการ OUTPUT BUFFER & SESSION
// -----------------------------------------------------------------------------------
// ใช้ ob_start เพื่อป้องกัน Error "Headers already sent" เมื่อมีการ Redirect
if (ob_get_level() == 0) {
    ob_start();
}

// ตั้งค่า Session ให้รองรับ PHP ทุกเวอร์ชัน และมีความปลอดภัยสูงสุด
if (session_status() === PHP_SESSION_NONE) {
    // กำหนดค่าพารามิเตอร์ของ Cookie (รองรับ PHP 5.6 จนถึง 8.x)
    // อายุ 0 หมายถึงจนกว่าจะปิด Browser, Path '/' คือทั้งโดเมน, HttpOnly ป้องกัน JS ขโมย Cookie
    session_set_cookie_params(0, '/', '', false, true);
    session_start();
}

// [SECTION 2] - การป้องกัน CACHE เพื่อความปลอดภัยของข้อมูลส่วนตัว
// -----------------------------------------------------------------------------------
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

// โหลดไฟล์เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/db.php'; 

/**
 * ===================================================================================
 * [FUNCTION] - syncSessionKeys()
 * หน้าที่: ตรวจสอบและซิงค์คีย์ของ Session ระหว่าง 'id' และ 'user_id'
 * ป้องกันปัญหาความสับสนระหว่างระบบเก่าและระบบใหม่
 * ===================================================================================
 */
if (!function_exists('syncSessionKeys')) {
    function syncSessionKeys() {
        if (isset($_SESSION['id']) && !isset($_SESSION['user_id'])) {
            $_SESSION['user_id'] = $_SESSION['id'];
        } elseif (isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
            $_SESSION['id'] = $_SESSION['user_id'];
        }
        
        // 🔥 จุดที่เพิ่มเข้ามา: บังคับแก้สิทธิ์ ADMIN เป็น DEVELOPER ทันทีในระดับ Global Session
        if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin') {
            $_SESSION['role'] = 'developer';
        }
    }
}
syncSessionKeys();

/**
 * ===================================================================================
 * [FUNCTION] - isLoggedIn()
 * ตรวจสอบสถานะว่าผู้ใช้อยู่ในระบบหรือไม่
 * ===================================================================================
 */
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) || 
               (isset($_SESSION['id']) && intval($_SESSION['id']) > 0);
    }
}

/**
 * ===================================================================================
 * [FUNCTION] - checkLoginStatus()
 * อัปเดตเวลาการใช้งานล่าสุด (Last Activity) ของผู้ใช้ลงในฐานข้อมูล
 * ===================================================================================
 */
if (!function_exists('checkLoginStatus')) {
    function checkLoginStatus() {
        global $conn;
        if (isLoggedIn()) {
            $uid = intval($_SESSION['user_id'] ?? $_SESSION['id']);
            // อัปเดตทุกๆ 5 นาที (300 วินาที) เพื่อลดภาระของฐานข้อมูล
            if (!isset($_SESSION['last_activity_update']) || (time() - $_SESSION['last_activity_update']) > 300) {
                try {
                    if (isset($conn) && $conn instanceof mysqli) {
                        $stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                        $stmt->bind_param("i", $uid);
                        $stmt->execute();
                        $stmt->close();
                        $_SESSION['last_activity_update'] = time();
                    }
                } catch (Exception $e) {
                    // ป้องกันความผิดพลาดในกรณีที่ตารางฐานข้อมูลยังไม่มีคอลัมน์นี้
                }
            }
            return true;
        }
        return false;
    }
}
checkLoginStatus();

/**
 * ===================================================================================
 * [FUNCTION] - requireRole($allowed_roles)
 * ระบบรักษาความปลอดภัยชั้นสูงที่คัดกรองสิทธิ์ผู้ใช้งาน
 * แก้ไขปัญหา: บังคับให้ Developer เข้าถึงได้ทุกที่ และ Admin ต้องถูกมองเป็น Developer
 * ===================================================================================
 */
if (!function_exists('requireRole')) {
    function requireRole($allowed_roles) {
        if (!isLoggedIn()) {
            // หากยังไม่ล็อกอิน ให้ดีดไปหน้า Login
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
        
        // ดึงสิทธิ์ปัจจุบันและทำความสะอาดค่า (ล้างช่องว่างและแปลงเป็นตัวเล็ก)
        $my_role = strtolower(trim($_SESSION['role'] ?? 'guest'));
        
        // 🔥 HARD REDIRECT FOR ADMIN:
        if ($my_role === 'admin') {
            $my_role = 'developer';
            $_SESSION['role'] = 'developer';
        }

        // รายการสิทธิ์ที่เทียบเท่ากัน
        $effective_roles = [$my_role];
        if ($my_role === 'developer') {
            $effective_roles[] = 'admin';
            $effective_roles[] = 'teacher'; // Dev สามารถจำลองเป็นครูได้
        }

        $has_access = false;
        foreach ($effective_roles as $role) {
            if (in_array($role, $allowed_roles)) {
                $has_access = true;
                break;
            }
        }

        // กรณีเป็น Developer ให้ผ่านได้ทุกกรณี (God Mode)
        if ($my_role === 'developer') {
            $has_access = true;
        }

        if (!$has_access) {
            // หน้าแจ้งเตือนการปฏิเสธการเข้าถึงแบบสวยงาม
            if (!headers_sent()) http_response_code(403);
            ?>
            <!DOCTYPE html>
            <html lang="th">
            <head>
                <meta charset="UTF-8">
                <title>Access Denied - สิทธิ์การเข้าถึงไม่เพียงพอ</title>
                <link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
                <style>
                    body { background:#0f172a; font-family: 'Itim', cursive; display:flex; justify-content:center; align-items:center; height:100vh; margin:0; color:#fff; }
                    .alert-box { background:#1e293b; padding:50px; border-radius:24px; text-align:center; max-width:500px; width:90%; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
                    .icon { font-size: 80px; margin-bottom: 20px; }
                    h1 { color:#ef4444; font-size:36px; margin:0 0 10px 0; }
                    p { color:#94a3b8; font-size:20px; margin-bottom: 30px; }
                    .btn { display:inline-block; padding:15px 40px; background:#3b82f6; color:white; text-decoration:none; border-radius:50px; font-weight:bold; transition:0.3s; }
                    .btn:hover { background:#2563eb; transform:scale(1.05); }
                </style>
            </head>
            <body>
                <div class="alert-box">
                    <div class="icon">🚫</div>
                    <h1>Access Denied</h1>
                    <p>คุณไม่มีสิทธิ์เข้าถึงหน้านี้<br>ระดับสิทธิ์ของคุณคือ: <strong style="color:#60a5fa;"><?= strtoupper($my_role) ?></strong></p>
                    <a href="index.php" class="btn">⬅ กลับสู่หน้าหลัก</a>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

/**
 * ===================================================================================
 * [FUNCTION] - currentUser()
 * หน้าที่: คืนค่า Array ข้อมูลผู้ใช้ปัจจุบันที่ดึงมาจาก Session
 * แก้ไข: บังคับให้คืนค่า Role ที่ถูกต้องที่สุด (Developer)
 * ===================================================================================
 */
if (!function_exists('currentUser')) {
    function currentUser() {
        if (!isLoggedIn()) return null;
        
        $role = strtolower(trim($_SESSION['role'] ?? 'student'));
        
        // 🔥 บังคับ Logic ในระดับข้อมูล User Object
        if ($role === 'admin') {
            $role = 'developer';
        }

        return [
            'id'             => $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0,
            'username'       => $_SESSION['username'] ?? 'Unknown',
            'display_name'   => $_SESSION['display_name'] ?? 'User',
            'role'           => $role,
            'class_level'    => $_SESSION['class_level'] ?? '',
            'subject_group'  => $_SESSION['subject_group'] ?? '',
            'department'     => $_SESSION['teacher_department'] ?? ''
        ];
    }
}
?>