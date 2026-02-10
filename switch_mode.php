<?php
// switch_mode.php - แก้ปัญหาปุ่ม "ออก" (Exit) และ Invalid Mode

// 1. ตั้งค่าและป้องกัน Cache
if (ob_get_level() == 0) ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'auth.php'; 
require_once 'db.php';

// เช็ค Login พื้นฐาน
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$my_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$role_param = $_GET['role'] ?? '';

// =========================================================
// 2. ฟังก์ชัน "ออก" จากโหมดจำลอง (Exit Simulation)
// ทำงานเมื่อ: ลิงก์มี action=exit หรือ role=developer
// =========================================================
if ($action === 'exit' || $role_param === 'developer') {
    
    // ตรวจสอบสิทธิ์: อนุญาตถ้าเป็น Dev หรือร่างจริงเป็น Dev
    $check = $conn->query("SELECT original_role FROM users WHERE id=$my_id");
    $user_db = $check->fetch_assoc();
    
    $is_authorized_exit = (
        (isset($_SESSION['role']) && $_SESSION['role'] === 'developer') ||
        (isset($_SESSION['original_role']) && $_SESSION['original_role'] === 'developer') ||
        ($user_db && $user_db['original_role'] === 'developer')
    );

    if ($is_authorized_exit) {
        // 1. ล้างค่าใน Database ให้กลับเป็น Developer
        $sql = "UPDATE users SET 
                role='developer', 
                original_role=NULL,
                subject_group=NULL, 
                teacher_department=NULL, 
                class_level=NULL, 
                parent_of=NULL 
                WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $my_id);
        $stmt->execute();

        // 2. ล้างค่าใน Session
        $_SESSION['role'] = 'developer';
        unset($_SESSION['dev_simulation_mode']);
        unset($_SESSION['original_role']);
        unset($_SESSION['subject_group']);
        unset($_SESSION['teacher_department']);
        unset($_SESSION['class_level']);
        unset($_SESSION['parent_of']);

        // 3. บันทึกและส่งกลับ
        session_write_close();
        header("Location: dashboard_dev.php");
        exit;
    } else {
        // ถ้าไม่ใช่ Dev แต่พยายามออก -> กลับหน้า Login
        header("Location: index.php");
        exit;
    }
}

// =========================================================
// 3. ฟังก์ชัน "เข้า" โหมดจำลอง (Start Simulation)
// ทำงานเมื่อ: มีค่า role ส่งมา และไม่ใช่ developer
// =========================================================
if (!empty($role_param)) {
    
    // Security: ต้องเป็น Dev เท่านั้นถึงจะเริ่มจำลองได้
    $is_dev = (isset($_SESSION['role']) && $_SESSION['role'] === 'developer');
    if (!$is_dev) {
        die("❌ Access Denied: คุณไม่ใช่ Developer");
    }

    $target_role = $role_param;
    $sim_data = [];

    // เตรียมข้อมูลจำลอง
    switch ($target_role) {
        case 'teacher':
            $sim_data['subject_group'] = 'วิทยาศาสตร์ (จำลอง)';
            $sim_data['teacher_department'] = 'ฝ่ายวิชาการ';
            break;
        case 'student':
            $sim_data['class_level'] = 'ม.6/1'; 
            break;
        case 'parent':
            $res = $conn->query("SELECT id FROM users WHERE role='student' LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $sim_data['parent_of'] = $row['id'];
            }
            break;
        default:
            die("❌ Invalid Mode: ไม่รู้จักบทบาท '$target_role' (ตรวจสอบ dashboard_dev.php)");
    }

    // อัปเดต Database (เก็บร่างจริงไว้ใน original_role)
    $sql = "UPDATE users SET role=?, original_role='developer'";
    $params = [$target_role];
    $types = "s";

    if (isset($sim_data['subject_group'])) { $sql .= ", subject_group=?"; $params[] = $sim_data['subject_group']; $types .= "s"; }
    if (isset($sim_data['teacher_department'])) { $sql .= ", teacher_department=?"; $params[] = $sim_data['teacher_department']; $types .= "s"; }
    if (isset($sim_data['class_level'])) { $sql .= ", class_level=?"; $params[] = $sim_data['class_level']; $types .= "s"; }
    if (isset($sim_data['parent_of'])) { $sql .= ", parent_of=?"; $params[] = $sim_data['parent_of']; $types .= "i"; }

    $sql .= " WHERE id=?";
    $params[] = $my_id;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    
    if ($stmt->execute()) {
        // อัปเดต Session
        $_SESSION['role'] = $target_role;
        $_SESSION['dev_simulation_mode'] = true;
        $_SESSION['original_role'] = 'developer';
        
        foreach ($sim_data as $k => $v) {
            $_SESSION[$k] = $v;
        }

        session_write_close();
        header("Location: dashboard_{$target_role}.php");
        exit;
    } else {
        die("DB Error: " . $stmt->error);
    }
}

// =========================================================
// 4. กรณีไม่ส่งค่าอะไรมาเลย -> ส่งกลับ Dashboard ตามสิทธิ์ที่มี
// =========================================================
$redirect = 'index.php';
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] == 'developer') $redirect = 'dashboard_dev.php';
    elseif ($_SESSION['role'] == 'teacher') $redirect = 'dashboard_teacher.php';
    elseif ($_SESSION['role'] == 'student') $redirect = 'dashboard_student.php';
    elseif ($_SESSION['role'] == 'parent') $redirect = 'dashboard_parent.php';
}
header("Location: " . $redirect);
exit;
?>