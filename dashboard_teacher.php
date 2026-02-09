<?php
require_once 'auth.php';
requireRole(['teacher']);
$user = currentUser();

// ตรวจสอบว่าเป็น Dev ที่จำลองมาหรือไม่ (เช็คจาก Session หรือ DB original_role)
$is_sim = (isset($_SESSION['dev_simulation_mode']) || (isset($user['original_role']) && $user['original_role']=='developer'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Teacher Dashboard</title>
<style>
body { margin:0; padding:30px; font-family:"Segoe UI",sans-serif; background:#f0f2f5; }
.topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; }
.card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:20px; }
.card { background:white; padding:20px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.1); }
.btn { display:inline-block; margin-top:10px; padding:8px 12px; background:#2563eb; color:white; text-decoration:none; border-radius:5px; }
.logout { color:red; text-decoration:none; }

/* Simulation Bar */
.sim-bar { background: #ef4444; color: white; padding: 15px; margin: -30px -30px 30px -30px; text-align: center; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.1); position: relative; z-index: 1000; }
.btn-exit-sim { background: white; color: #ef4444; padding: 5px 15px; border-radius: 20px; text-decoration: none; margin-left: 15px; border: 2px solid white; transition: 0.2s; }
.btn-exit-sim:hover { background: #ef4444; color: white; }
</style>
</head>
<body>

<?php if($is_sim): ?>
<div class="sim-bar">
    ⚠️ คุณกำลังใช้งานโหมดจำลอง (Simulation Mode) : ครู
    <a href="switch_mode.php?action=exit" class="btn-exit-sim">🛑 ออกจากโหมดจำลอง</a>
</div>
<?php endif; ?>

<div class="topbar">
    <div>
        <h1>Teacher Dashboard</h1>
        <small>สวัสดี, <?= htmlspecialchars($user['display_name']) ?> (<?= htmlspecialchars($user['subject_group'] ?? '-') ?>)</small>
    </div>
    <a class="logout" href="logout.php">Logout</a>
</div>

<div class="card-grid">
    <div class="card">
        <h3>📚 คลังงานของฉัน</h3>
        <p>สร้างและจัดการ Assignment Library</p>
        <a class="btn" href="assignment_library.php">จัดการคลังงาน</a>
    </div>
    <div class="card">
        <h3>📝 มอบหมายงาน</h3>
        <p>ส่งงานให้นักเรียนจากคลัง</p>
        <a class="btn" href="assign_from_library.php">สั่งงาน</a>
    </div>
    <div class="card">
        <h3>📋 งานที่สั่งแล้ว</h3>
        <p>ดูประวัติการสั่งงานทั้งหมด</p>
        <a class="btn" href="teacher_assignments.php">ดูงาน</a>
    </div>
    <div class="card">
        <h3>📅 ตารางสอน</h3>
        <p>ดูตารางสอนของฉัน</p>
        <a class="btn" href="teacher_schedule.php">ดูตาราง</a>
    </div>
    <div class="card">
        <h3>⚔️ สร้างเควส</h3>
        <p>ระบบ Gamification สร้างภารกิจ</p>
        <a class="btn" href="create_quest.php">สร้างเควส</a>
    </div>
</div>
</body>
</html>