<?php
// เริ่ม Buffer บรรทัดแรกสุด
if (ob_get_level() == 0) ob_start();

require_once 'auth.php';
requireRole(['student']); // ตรวจสิทธิ์ (ถ้าไม่ผ่านจะหยุดทันที ไม่วนลูป)

$user = currentUser();
$is_sim = (isset($_SESSION['dev_simulation_mode']) || (isset($user['original_role']) && $user['original_role']=='developer'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Student Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
body { margin:0; padding:20px; font-family: 'Sarabun', sans-serif; background:#f0f2f5; }
.header { display:flex; justify-content:space-between; align-items:center; background:white; padding:15px 25px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:20px; }
.welcome { font-size:1.2rem; font-weight:bold; color:#1e293b; }
.logout { color:#ef4444; text-decoration:none; font-weight:bold; border:2px solid #ef4444; padding:5px 15px; border-radius:20px; transition:0.3s; }
.logout:hover { background:#ef4444; color:white; }
.card-container { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px; }
.card { background:white; padding:25px; border-radius:15px; box-shadow:0 4px 6px rgba(0,0,0,0.05); transition:transform 0.2s; }
.card:hover { transform:translateY(-5px); }
.card h3 { margin-top:0; color:#334155; }
.btn { display:inline-block; padding:10px 20px; background:#3b82f6; color:white; text-decoration:none; border-radius:8px; margin-top:15px; }
.btn:hover { background:#2563eb; }
</style>
</head>
<body>

    <div class="header">
        <div class="welcome">
            👋 สวัสดี, <?= htmlspecialchars($user['display_name']) ?> 
            <span style="font-size:0.9rem; color:#64748b;">(<?= htmlspecialchars($user['class_level'] ?? 'ไม่ระบุชั้น') ?>)</span>
        </div>
        <a href="logout.php" class="logout">ออกจากระบบ</a>
    </div>

    <?php if($is_sim): ?>
    <div style="background:#fff7ed; border:1px solid #fdba74; color:#c2410c; padding:10px; border-radius:8px; margin-bottom:20px; text-align:center;">
        ⚠️ คุณกำลังอยู่ในโหมดจำลอง (Simulation Mode)
        <a href="switch_mode.php?action=exit" style="margin-left:10px; color:#c2410c; font-weight:bold;">[ออกจากโหมด]</a>
    </div>
    <?php endif; ?>

    <div class="card-container">
        <div class="card">
            <h3>📚 การบ้านของฉัน</h3>
            <p>ดูงานที่ได้รับมอบหมายและส่งงาน</p>
            <a href="student_assignments.php" class="btn">เปิดดูงาน</a>
        </div>
        
        <div class="card">
            <h3>🏆 คะแนน & เควส</h3>
            <p>ดูคะแนนสะสมและภารกิจประจำวัน</p>
            <a href="#" class="btn" style="background:#10b981;">ดูคะแนน</a>
        </div>

        <div class="card">
            <h3>📅 ตารางเรียน</h3>
            <p>ตรวจสอบตารางเรียนของห้องเรียน</p>
            <a href="#" class="btn" style="background:#f59e0b;">ดูตาราง</a>
        </div>
    </div>

</body>
</html>