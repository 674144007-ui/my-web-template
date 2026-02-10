<?php
// auth.php - ระบบตรวจสอบสิทธิ์ (ฉบับแก้ Loop หายขาด)

// 1. เริ่ม Session ทันทีถ้ายังไม่มี
if (session_status() === PHP_SESSION_NONE) {
    // บังคับใช้ Cookie ให้ครอบคลุมทุกหน้า
    session_set_cookie_params(0, '/');
    session_start();
}

// 2. ป้องกัน Browser Cache (สำคัญมากเวลาเปลี่ยน Role)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

/**
 * ตรวจสอบว่า Login หรือยัง
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * ตรวจสอบสิทธิ์ (ฉบับหยุด Loop)
 * @param array|string $allowed_roles บทบาทที่อนุญาตให้เข้า
 */
function requireRole($allowed_roles) {
    // 1. ถ้ายังไม่ Login ให้ไปหน้า Login
    if (!isLoggedIn()) {
        header("Location: index.php");
        exit;
    }

    // 2. แปลงให้เป็น Array เสมอ (ป้องกัน Error เวลาส่งค่าเดียว)
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    // 3. ดึง Role ปัจจุบันจาก Session
    $my_role = $_SESSION['role'] ?? 'unknown';

    // 🔥 Special Fix: ให้ Admin มีสิทธิ์เท่ากับ Developer เสมอ
    if ($my_role === 'admin') {
        $my_role = 'developer';
    }

    // 4. ตรวจสอบสิทธิ์ (Check)
    // ถ้า Role ของเรา ไม่อยู่ในกลุ่มที่อนุญาต
    if (!in_array($my_role, $allowed_roles)) {
        
        // ⛔ STOP LOOP: แสดงหน้าจอ Error แทนการ Redirect
        // วิธีนี้จะทำให้คุณไม่เด้งกลับไปหน้าเดิม แต่จะเห็นชัดเจนว่าติดที่ตรงไหน
        http_response_code(403);
        echo "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Access Denied</title>
            <style>
                body { background:#0f172a; color:white; font-family:sans-serif; display:flex; justify-content:center; align-items:center; height:100vh; margin:0; }
                .box { background:#1e293b; padding:40px; border-radius:15px; text-align:center; border:2px solid #ef4444; max-width:500px; box-shadow:0 10px 30px rgba(0,0,0,0.5); }
                h1 { color:#ef4444; font-size:3rem; margin:0 0 20px 0; }
                p { font-size:1.1rem; line-height:1.6; color:#cbd5e1; }
                .role-tag { background:#334155; padding:5px 10px; border-radius:5px; color:#facc15; font-weight:bold; }
                .btn { display:inline-block; margin-top:20px; padding:10px 20px; background:#3b82f6; color:white; text-decoration:none; border-radius:8px; font-weight:bold; }
                .btn:hover { background:#2563eb; }
            </style>
        </head>
        <body>
            <div class='box'>
                <h1>⛔ Access Denied</h1>
                <p>ระบบปฏิเสธการเข้าถึงหน้านี้ เพราะสิทธิ์ของคุณไม่ถูกต้อง</p>
                <div style='margin:20px 0; text-align:left; background:rgba(0,0,0,0.2); padding:15px; border-radius:10px;'>
                    <p>👤 <strong>Role ของคุณ:</strong> <span class='role-tag'>$my_role</span></p>
                    <p>🔒 <strong>Role ที่ต้องการ:</strong> " . implode(", ", $allowed_roles) . "</p>
                </div>
                <p>หากคุณคิดว่านี่คือข้อผิดพลาด โปรดลอง Logout แล้ว Login ใหม่</p>
                <a href='index.php' class='btn'>⬅ กลับหน้าหลัก</a>
            </div>
        </body>
        </html>";
        exit; // หยุดการทำงานทันที (ไม่เด้งกลับ)
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
        'original_role' => $_SESSION['original_role'] ?? null,
        'class_level'   => $_SESSION['class_level'] ?? null,
        'subject_group' => $_SESSION['subject_group'] ?? null,
        'teacher_department' => $_SESSION['teacher_department'] ?? null
    ];
}
?>