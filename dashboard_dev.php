<?php
require_once 'auth.php';
requireRole(['developer']);
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Developer Dashboard</title>
<style>
    body { margin:0; padding:30px; font-family:"Segoe UI",sans-serif; background:#0A0F24; color:#E2E8F0; }
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; }
    .topbar-left strong { font-size:2rem; }
    .topbar-left small { color:#94a3b8; }
    .logout { color:#FF6060; font-weight:bold; text-decoration:none; margin-left:12px; }
    .logout:hover { color:#ff8c8c; }
    .card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:20px; }
    .card { background:rgba(30,41,59,0.6); padding:22px; border-radius:16px; border:1px solid rgba(148,163,184,0.15); box-shadow:0 15px 30px rgba(0,0,0,0.35); backdrop-filter:blur(12px); transition:0.3s; }
    .card:hover { transform:translateY(-5px); box-shadow:0 25px 40px rgba(0,0,0,0.45); }
    .tag { display:inline-block; padding:3px 10px; background:#3b82f6; border-radius:999px; color:white; font-size:0.7rem; margin-bottom:8px; }
    .btn { display:inline-block; margin-top:10px; padding:10px 14px; border-radius:10px; font-weight:bold; text-decoration:none; color:#0f172a; background:#22c55e; transition:0.25s; }
    .btn:hover { background:#4ade80; }
    .dev-badge { padding:6px 12px; background:#3b82f6; color:white; border-radius:999px; font-weight:bold; margin-right:12px; }

    /* Styles สำหรับปุ่ม Simulation */
    .tag-sim { background: #f59e0b; color: #fff; }
    .btn-sim { background: #fbbf24; color: #78350f; width: 100%; text-align: center; box-sizing: border-box; }
    .btn-sim:hover { background: #fcd34d; }
    .sim-desc { font-size: 0.85rem; color: #cbd5e1; margin-bottom: 15px; display: block; }
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <strong>Developer Dashboard</strong><br>
        <small>ยินดีต้อนรับ <?= htmlspecialchars($user['display_name']) ?></small>
    </div>
    <div>
        <span class="dev-badge">Developer Mode</span>
        <a class="logout" href="logout.php">Logout</a>
    </div>
</div>

<div class="card-grid">

    <div class="card" style="border-left: 5px solid #a855f7;">
        <span class="tag" style="background:#a855f7;">Simulation</span>
        <h3>🧪 ห้องแล็บเคมี (จำลอง)</h3>
        <p>ระบบจำลองการผสมสารเคมี 3D</p>
        <a class="btn" href="dev_lab.php" style="background:#d8b4fe; color:#581c87;">เปิดห้องแล็บ</a>
    </div>

    <div class="card" style="border: 1px solid #f59e0b;">
        <span class="tag tag-sim">Role Simulation</span>
        <h3>👨‍🏫 จำลองเป็น: ครู</h3>
        <span class="sim-desc">ใช้ ID ของคุณสวมบทบาทเป็นครู สามารถสร้างงาน จัดตารางสอนได้จริง</span>
        <a class="btn btn-sim" href="switch_mode.php?role=teacher" onclick="return confirm('ยืนยันการสลับไปใช้โหมดครู?');">🚀 เริ่มจำลอง (Start)</a>
    </div>

    <div class="card" style="border: 1px solid #f59e0b;">
        <span class="tag tag-sim">Role Simulation</span>
        <h3>👨‍🎓 จำลองเป็น: นักเรียน</h3>
        <span class="sim-desc">สวมบทบาทเป็นนักเรียนชั้น ม.6/1 เพื่อทดสอบการส่งงานและดูเกรด</span>
        <a class="btn btn-sim" href="switch_mode.php?role=student" onclick="return confirm('ยืนยันการสลับไปใช้โหมดนักเรียน?');">🚀 เริ่มจำลอง (Start)</a>
    </div>

    <div class="card" style="border: 1px solid #f59e0b;">
        <span class="tag tag-sim">Role Simulation</span>
        <h3>👨‍👩‍👧 จำลองเป็น: ผู้ปกครอง</h3>
        <span class="sim-desc">สวมบทบาทผู้ปกครอง (ระบบจะสุ่มเชื่อมโยงกับนักเรียน 1 คน)</span>
        <a class="btn btn-sim" href="switch_mode.php?role=parent" onclick="return confirm('ยืนยันการสลับไปใช้โหมดผู้ปกครอง?');">🚀 เริ่มจำลอง (Start)</a>
    </div>

    <div class="card">
        <h3>👥 จัดการผู้ใช้</h3>
        <p>เพิ่ม แก้ไข ลบ ผู้ใช้ทั้งหมดในระบบ</p>
        <a class="btn" href="user_manager.php">เปิด User Manager</a>
    </div>

    <div class="card">
        <h3>📅 ตารางสอน (Admin)</h3>
        <p>แก้ไขตารางสอนให้ครูทุกคน</p>
        <div style="display:flex; gap:10px;">
            <a class="btn" href="dev_add_schedule.php">เพิ่ม</a>
            <a class="btn" href="dev_view_schedule.php" style="background:#3b82f6; color:white;">ดูทั้งหมด</a>
        </div>
    </div>

</div>
</body>
</html>