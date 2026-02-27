<?php
/**
 * export_users.php - ระบบส่งออกข้อมูลผู้ใช้งาน (Phase 3: Export & Distribution)
 * รองรับการดึงข้อมูลตาม Filter และรองรับภาษาไทยใน Excel (BOM)
 */

require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// สงวนสิทธิ์เฉพาะ Developer และ Admin
requireRole(['developer', 'admin']);

// รับค่า Filter ที่ส่งมาจากหน้า user_manager.php
$filter_role = $_GET['role'] ?? '';
$filter_class_id = !empty($_GET['class_id']) ? intval($_GET['class_id']) : '';

// สร้างคำสั่ง SQL แบบ Dynamic
$sql = "SELECT u.id, u.username, u.display_name, u.role, u.created_at, c.class_name, c.level, c.room 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.is_deleted = 0";
$params = [];
$types = "";

if ($filter_role) {
    $sql .= " AND u.role = ?";
    $params[] = $filter_role;
    $types .= "s";
}
if ($filter_class_id) {
    $sql .= " AND u.class_id = ?";
    $params[] = $filter_class_id;
    $types .= "i";
}

$sql .= " ORDER BY u.role ASC, c.level ASC, c.room ASC, u.display_name ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();

// ตั้งค่า Header สำหรับดาวน์โหลดไฟล์ CSV
$filename = "User_Export_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// เปิด Output Buffer
$output = fopen('php://output', 'w');

// สอดไส้ BOM (Byte Order Mark) เพื่อบังคับให้ Microsoft Excel อ่านภาษาไทย UTF-8 ได้ถูกต้อง 100%
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// เขียนหัวตาราง (Header Row)
fputcsv($output, [
    'ลำดับ (No.)', 
    'รหัสผู้ใช้งาน (Username)', 
    'รหัสผ่านเริ่มต้น (Default Password)', 
    'ชื่อ-นามสกุล (Display Name)', 
    'บทบาท (Role)', 
    'ระดับชั้น (Level)', 
    'ห้องเรียน (Room)',
    'วันที่สร้างบัญชี (Created At)'
]);

$counter = 1;
if ($users->num_rows > 0) {
    while ($u = $users->fetch_assoc()) {
        // จัดรูปแบบข้อมูล
        $role_th = '';
        switch($u['role']) {
            case 'student': $role_th = 'นักเรียน'; break;
            case 'teacher': $role_th = 'ครูผู้สอน'; break;
            case 'parent': $role_th = 'ผู้ปกครอง'; break;
            case 'developer': $role_th = 'ผู้พัฒนา'; break;
            default: $role_th = $u['role'];
        }

        // คำแนะนำรหัสผ่าน (เนื่องจากรหัสจริงถูก Hash ไว้)
        // ตามลอจิกของระบบเรา รหัสผ่านเริ่มต้นมักจะตรงกับ Username
        $password_hint = "ตรงกับ Username"; 

        fputcsv($output, [
            $counter,
            $u['username'],
            $password_hint,
            $u['display_name'],
            $role_th,
            $u['level'] ?? '-',
            $u['room'] ?? '-',
            date('d/m/Y H:i', strtotime($u['created_at']))
        ]);
        $counter++;
    }
} else {
    // กรณีไม่มีข้อมูล
    fputcsv($output, ['ไม่พบข้อมูลผู้ใช้งานที่ตรงกับเงื่อนไข']);
}

fclose($output);
$stmt->close();
exit;
?>