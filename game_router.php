<?php
// game_router.php - ระบบนำทางมินิเกมอัจฉริยะ (Phase 5: Modular Game Engine Router)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
if (!isset($pdo)) {
    die("<div style='padding:50px; text-align:center; color:#ef4444;'><h2>🚨 ข้อผิดพลาด: ไม่พบการเชื่อมต่อ PDO</h2></div>");
}

require_once 'auth.php';
requireLogin();

$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];
$class_id = $_SESSION['class_id'] ?? 0;

if ($assignment_id <= 0) {
    header("Location: dashboard_student.php?error=invalid_assignment");
    exit;
}

try {
    // 1. ดึงข้อมูลการบ้านว่าใช้เกมประเภทไหนและเป็นของวิชาอะไร
    $stmt = $pdo->prepare("SELECT a.game_type, a.subject_id, s.subject_name FROM assignments a JOIN subjects s ON a.subject_id = s.id WHERE a.id = ?");
    $stmt->execute([$assignment_id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        header("Location: dashboard_student.php?error=assignment_not_found");
        exit;
    }

    $game_type = $assignment['game_type'];
    $subject_id = $assignment['subject_id'];

    // 2. ตรวจสอบสิทธิ์การเข้าถึงวิชานี้ (Access Control)
    $has_access = false;
    if ($username === 'stu001' || in_array($role, ['developer', 'developer', 'teacher'])) {
        $has_access = true; // God Mode / Admin Access
    } else {
        // เช็คว่านักเรียนลงทะเบียนเรียนวิชานี้ไหม
        $stmt_check = $pdo->prepare("SELECT id FROM class_schedules WHERE class_id = ? AND subject_id = ?");
        $stmt_check->execute([$class_id, $subject_id]);
        if ($stmt_check->fetch()) {
            $has_access = true;
        }
    }

    if (!$has_access) {
        header("Location: dashboard_student.php?error=access_denied");
        exit;
    }

    // 3. Routing: ส่งนักเรียนไปยังเกมที่ถูกต้อง (Modular Structure)
    switch ($game_type) {
        case 'chem_lab':
            // ไปยังห้องทดลองเคมี (Lab ที่เราสร้างไว้ใน Phase 4)
            header("Location: lab_realistic.php?assignment_id=" . $assignment_id);
            exit;
            
        case 'physics_lab':
            // 🚀 เตรียมพร้อมสำหรับอนาคต! ถ้ามีเกมฟิสิกส์ให้ส่งมาหน้านี้
            // header("Location: games/physics_lab/index.php?assignment_id=" . $assignment_id);
            die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h2>🚧 ห้องทดลองฟิสิกส์กำลังอยู่ในระหว่างการพัฒนา</h2><a href='student_classroom.php?subject_id={$subject_id}'>กลับหน้าชั้นเรียน</a></div>");
            
        case 'bio_lab':
            // 🚀 เตรียมพร้อมสำหรับเกมชีววิทยา
            die("<div style='padding:50px; text-align:center; font-family:sans-serif;'><h2>🚧 ห้องทดลองชีววิทยากำลังอยู่ในระหว่างการพัฒนา</h2><a href='student_classroom.php?subject_id={$subject_id}'>กลับหน้าชั้นเรียน</a></div>");
            
        case 'none':
        default:
            // ถ้าไม่มีเกม ให้กลับไปหน้าชั้นเรียน
            header("Location: student_classroom.php?subject_id=" . $subject_id . "&msg=no_game_engine");
            exit;
    }

} catch (PDOException $e) {
    error_log("Game Router Error: " . $e->getMessage());
    die("System Error: " . $e->getMessage());
}
?>