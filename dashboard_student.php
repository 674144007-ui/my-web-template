<?php
require_once 'auth.php';
requireRole(['student']);
$user = currentUser();
$is_sim = (isset($_SESSION['dev_simulation_mode']) || (isset($user['original_role']) && $user['original_role']=='developer'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Student Dashboard</title>
<style>
body { margin:0; padding:30px; font-family:"Segoe UI",sans-serif; background:#f0f2f5; }
.topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; }
.card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:20px; }
.card { background:white; padding:20px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
.btn { display:inline-block; margin-top:10px; padding:8px 12px; background:#10b981; color:white; text-decoration:none; border-radius:5px; }
.logout { color:red; text-decoration:none; }

.sim-bar { background: #ef4444; color: white; padding: 15px; margin: -30px -30px 30px -30px; text-align: center; font-weight: bold; position: relative; z-index: 1000; }
.btn-exit-sim { background: white; color: #ef4444; padding: 5px 15px; border-radius: 20px; text-decoration: none; margin-left: 15px; border: 2px solid white; transition: 0.2s; }
.btn-exit-sim:hover { background: #ef4444; color: white; }
</style>
</head>
<body>

<?php if($is_sim): ?>
<div class="sim-bar">
    ⚠️ คุณกำลังใช้งานโหมดจำลอง (Simulation Mode) : นักเรียน
    <a href="switch_mode.php?action=exit" class="btn-exit-sim">🛑 ออกจากโหมดจำลอง</a>
</div>
<?php endif; ?>

<div class="topbar">
    <div>
        <h1>Student Dashboard</h1>
        <small>สวัสดี, <?= htmlspecialchars($user['display_name']) ?> (<?= htmlspecialchars($user['class_level'] ?? '-') ?>)</small>
    </div>
    <a class="logout" href="logout.php">Logout</a>
</div>

<div class="card-grid">
    <div class="card">
        <h3>📖 การบ้านของฉัน</h3>
        <p>ดูรายการงานที่ได้รับมอบหมาย</p>
        <a class="btn" href="student_assignments.php">ดูงาน</a>
    </div>
    <div class="card">
        <h3>⚔️ เควส & GP</h3>
        <p>ดูภารกิจและคะแนนสะสม</p>
        <a class="btn" href="#">ดูเควส (Coming Soon)</a>
    </div>
    <div class="card">
        <h3>📅 ตารางเรียน</h3>
        <p>ดูตารางเรียนของห้อง</p>
        <a class="btn" href="#">ดูตารางเรียน</a>
    </div>
</div>
</body>
</html>