<?php
// promote_year.php - เลื่อนชั้น + บันทึกประวัติ
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
requireRole(['developer', 'admin']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// ดึงปีการศึกษาปัจจุบัน
$year_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_name = 'academic_year'");
$current_year = ($year_res->num_rows > 0) ? intval($year_res->fetch_assoc()['setting_value']) : 2568;
$next_year = $current_year + 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_promote'])) {
    $m6_action = $_POST['m6_action'];
    $count_promoted = 0;
    
    $students = $conn->query("SELECT id, class_level FROM users WHERE role = 'student' AND status = 'active'");
    
    $conn->begin_transaction();
    try {
        // เตรียมคำสั่งบันทึกประวัติ
        $stmt_hist = $conn->prepare("INSERT INTO student_history (user_id, academic_year, old_class_level) VALUES (?, ?, ?)");

        while ($s = $students->fetch_assoc()) {
            $id = $s['id'];
            $class = trim($s['class_level']); 

            // 1. บันทึกประวัติก่อนเปลี่ยนแปลง (Snapshot)
            if (!empty($class)) {
                $stmt_hist->bind_param("iis", $id, $current_year, $class);
                $stmt_hist->execute();
            }

            // 2. คำนวณเลื่อนชั้น
            if (preg_match('/^ม\.(\d+)\/(\d+)$/', $class, $matches)) {
                $level = intval($matches[1]);
                $room = $matches[2];
                
                if ($level < 6) {
                    $new_level = $level + 1;
                    $conn->query("UPDATE users SET class_level = 'ม.{$new_level}/{$room}' WHERE id = $id");
                    $count_promoted++;
                } elseif ($level == 6) {
                    if ($m6_action == 'delete') {
                        $conn->query("DELETE FROM users WHERE id = $id");
                    } else {
                        $conn->query("UPDATE users SET status = 'graduated', class_level = 'จบการศึกษา', graduated_year = '$current_year' WHERE id = $id");
                    }
                }
            }
        }
        
        $conn->query("UPDATE system_settings SET setting_value = '$next_year' WHERE setting_name = 'academic_year'");
        $conn->commit();
        
        $current_year = $next_year;
        $msg = "✅ บันทึกประวัติปีการศึกษาเดิม และเลื่อนชั้นเรียนสำเร็จ!";
        $msg_type = "success";
        
    } catch (Exception $e) {
        $conn->rollback();
        $msg = "Error: " . $e->getMessage(); $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Promote & History</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f8fafc; padding: 20px; }
    .container { max-width: 700px; margin: 0 auto; background: white; padding: 40px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); text-align: center; }
    .btn { padding: 15px 30px; border-radius: 10px; border: none; font-size: 1.1rem; font-weight: bold; cursor: pointer; transition: 0.3s; width: 100%; }
    .btn-promote { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; margin-top:20px; }
    .info-box { background:#e0f2fe; padding:15px; border-radius:10px; color:#0369a1; margin:20px 0; text-align:left; }
    .alert { padding:15px; border-radius:8px; margin-bottom:20px; }
    .success { background:#dcfce7; color:#166534; } .error { background:#fee2e2; color:#991b1b; }
</style>
</head>
<body>
<div class="container">
    <h2>🎓 เลื่อนปีการศึกษา</h2>
    
    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <h1 style="color:#3b82f6; font-size:3rem; margin:10px 0;"><?= $current_year ?> ➔ <?= $next_year ?></h1>
    
    <div class="info-box">
        <strong>💾 ระบบบันทึกอัตโนมัติ:</strong><br>
        ก่อนทำการเลื่อนชั้น ระบบจะสำรองข้อมูลห้องเรียนปัจจุบันของนักเรียนทุกคนลงใน "ประวัติการศึกษา" 
        เพื่อให้คุณสามารถเรียกดูย้อนหลังได้ว่า ปี <?= $current_year ?> ใครอยู่ห้องไหน
    </div>

    <form method="post" onsubmit="return confirm('ยืนยันเลื่อนชั้น? ระบบจะบันทึกประวัติทันที');">
        <label style="display:block; text-align:left; font-weight:bold; margin-bottom:10px;">จัดการนักเรียน ม.6:</label>
        <select name="m6_action" style="width:100%; padding:10px; margin-bottom:10px;">
            <option value="graduate">🎓 จบการศึกษา (เก็บประวัติ)</option>
            <option value="delete">🗑️ ลบออกจากระบบ</option>
        </select>
        <button type="submit" name="confirm_promote" class="btn btn-promote">ยืนยันการเลื่อนปีและบันทึกประวัติ</button>
        <a href="dashboard_dev.php" style="display:block; margin-top:15px; color:#64748b; text-decoration:none;">ย้อนกลับ</a>
    </form>
</div>
</body>
</html>