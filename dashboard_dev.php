<?php
// dashboard_dev.php - Dev Dashboard + Realtime Stats
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
require_once 'db.php';
requireRole(['developer', 'admin']);

$my_id = $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE id = $my_id")->fetch_assoc();

// --- Real-time & Stats Logic ---
// 1. นับคนออนไลน์ (Active ใน 5 นาทีล่าสุด)
$online_res = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE last_activity > NOW() - INTERVAL 5 MINUTE");
$online_count = $online_res->fetch_assoc()['cnt'];

// 2. นับยอด Login วันนี้
$today_str = date('Y-m-d');
$login_res = $conn->query("SELECT COUNT(*) as cnt FROM login_logs WHERE DATE(login_time) = '$today_str'");
$today_login_count = $login_res->fetch_assoc()['cnt'];

// 3. นับจำนวนนักเรียนทั้งหมด
$stu_res = $conn->query("SELECT COUNT(*) as cnt FROM users WHERE role='student' AND status='active'");
$student_count = $stu_res->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Developer Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { margin:0; padding:30px; font-family:"Sarabun", sans-serif; background:#0A0F24; color:#E2E8F0; }
    
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; background: rgba(30,41,59,0.5); padding: 15px 25px; border-radius: 16px; border: 1px solid rgba(148,163,184,0.1); }
    .profile-widget { display: flex; align-items: center; gap: 15px; }
    .avatar-img { width: 50px; height: 50px; border-radius: 50%; border: 2px solid #ef4444; object-fit: cover; }
    
    /* Stats Widgets */
    .stats-container { display: flex; gap: 20px; margin-bottom: 30px; }
    .stat-box { 
        flex: 1; background: linear-gradient(135deg, #1e293b, #0f172a); 
        padding: 20px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.1);
        display: flex; align-items: center; justify-content: space-between;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    .stat-num { font-size: 2.5rem; font-weight: bold; color: white; line-height: 1; }
    .stat-label { font-size: 0.9rem; color: #94a3b8; }
    .stat-icon { font-size: 2.5rem; opacity: 0.8; }
    
    /* Green Pulse Animation for Online Status */
    .online-dot { width: 10px; height: 10px; background: #22c55e; border-radius: 50%; display: inline-block; margin-right: 5px; box-shadow: 0 0 0 rgba(34, 197, 94, 0.4); animation: pulse 2s infinite; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }

    .card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:25px; }
    .card { background:rgba(30,41,59,0.6); padding:25px; border-radius:16px; border:1px solid rgba(148,163,184,0.15); box-shadow:0 15px 30px rgba(0,0,0,0.35); backdrop-filter:blur(12px); transition:0.3s; position: relative; overflow: hidden; }
    .card:hover { transform:translateY(-5px); border-color: rgba(148,163,184,0.3); }
    .card h3 { margin-top: 5px; color: #f1f5f9; }
    
    .btn { display:inline-block; padding:10px 16px; border-radius:10px; font-weight:bold; text-decoration:none; color:#0f172a; transition:0.25s; text-align: center; }
    .btn-green { background:#22c55e; color:black; } .btn-green:hover { background:#4ade80; }
    .btn-blue { background:#3b82f6; color:white; } .btn-blue:hover { background:#60a5fa; }
    .btn-orange { background:#f97316; color:white; } .btn-orange:hover { background:#fb923c; }
    .btn-purple { background:#d8b4fe; color:#581c87; }
</style>
</head>
<body>

<div class="topbar">
    <div>
        <strong style="font-size:1.5rem;">Developer Console</strong><br>
        <small style="color:#94a3b8;">System Monitor & Management</small>
    </div>
    
    <div class="profile-widget">
        <div style="text-align:right;">
            <div style="font-weight:bold;"><?= htmlspecialchars($user['display_name']) ?></div>
            <div style="font-size:0.8rem; color:#ef4444;">ADMIN</div>
        </div>
        <a href="profile.php"><img src="<?= $user['profile_pic'] ? 'uploads/'.$user['profile_pic'] : 'logo.png' ?>" class="avatar-img"></a>
        <a href="logout.php" style="color:#ef4444; text-decoration:none; border:1px solid #ef4444; padding:5px 10px; border-radius:8px;">Logout</a>
    </div>
</div>

<div class="stats-container">
    <div class="stat-box" style="border-bottom: 4px solid #22c55e;">
        <div>
            <div class="stat-label"><span class="online-dot"></span> Online Now</div>
            <div class="stat-num"><?= $online_count ?></div>
        </div>
        <div class="stat-icon">🟢</div>
    </div>
    <div class="stat-box" style="border-bottom: 4px solid #3b82f6;">
        <div>
            <div class="stat-label">Login วันนี้</div>
            <div class="stat-num"><?= number_format($today_login_count) ?></div>
        </div>
        <div class="stat-icon">📉</div>
    </div>
    <div class="stat-box" style="border-bottom: 4px solid #f59e0b;">
        <div>
            <div class="stat-label">นักเรียนทั้งหมด</div>
            <div class="stat-num"><?= number_format($student_count) ?></div>
        </div>
        <div class="stat-icon">🎓</div>
    </div>
</div>

<div class="card-grid">
    
    <div class="card">
        <h3>👥 จัดการผู้ใช้</h3>
        <p>เพิ่ม/ลบ, รีเซ็ตรหัส, แก้ไขข้อมูล</p>
        <a class="btn btn-blue" style="width:100%; box-sizing:border-box;" href="user_manager.php">Manage Users</a>
    </div>

    <div class="card" style="border-left: 5px solid #ec4899;">
        <span style="background:#ec4899; color:white; padding:2px 8px; border-radius:10px; font-size:0.7rem;">History</span>
        <h3>📜 ประวัติการศึกษา</h3>
        <p>ดูข้อมูลย้อนหลังรายปีการศึกษา</p>
        <a class="btn" style="background:#fbcfe8; color:#be185d; width:100%; box-sizing:border-box;" href="view_history.php">ดูประวัติ</a>
    </div>

    <div class="card" style="border-left: 5px solid #f97316;">
        <h3>🎓 เลื่อนปีการศึกษา</h3>
        <p>เลื่อนชั้นอัตโนมัติ + บันทึกประวัติ</p>
        <a class="btn btn-orange" style="width:100%; box-sizing:border-box;" href="promote_year.php">ระบบเลื่อนชั้น</a>
    </div>

    <div class="card">
        <h3>🎭 Role Simulation</h3>
        <div style="display:flex; gap:5px; margin-top:10px;">
            <a href="switch_mode.php?role=teacher" class="btn btn-green" style="flex:1;">ครู</a>
            <a href="switch_mode.php?role=student" class="btn btn-green" style="flex:1;">นร.</a>
        </div>
    </div>

    <div class="card">
        <h3>🧪 ห้องแล็บจำลอง</h3>
        <a class="btn btn-purple" style="width:100%; box-sizing:border-box;" href="dev_lab.php">เข้าห้องแล็บ</a>
    </div>

    <div class="card">
        <h3>⚔️ Mission Control</h3>
        <a class="btn" style="background:#fbbf24; color:#78350f; width:100%; box-sizing:border-box;" href="create_quest.php">สร้างภารกิจ</a>
    </div>

</div>

</body>
</html>