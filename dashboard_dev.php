<?php
// dashboard_dev.php - Developer Dashboard (รวมทุกฟังก์ชัน)
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
require_once 'db.php';

// ตรวจสอบสิทธิ์ (Admin ให้ถือว่าเป็น Developer)
requireRole(['developer', 'admin']);

$my_id = $_SESSION['user_id'];

// ดึงข้อมูล User ล่าสุด (เพื่อเอารูปโปรไฟล์ที่เป็นปัจจุบัน)
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $my_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Developer Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { margin:0; padding:30px; font-family:"Sarabun", sans-serif; background:#0A0F24; color:#E2E8F0; }
    
    /* Topbar Styling */
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; background: rgba(30,41,59,0.5); padding: 15px 25px; border-radius: 16px; border: 1px solid rgba(148,163,184,0.1); }
    .topbar-left strong { font-size:1.8rem; color: #fff; }
    .topbar-left small { color:#94a3b8; font-size: 1rem; }
    
    /* Profile Widget (มุมขวาบน) */
    .profile-widget { display: flex; align-items: center; gap: 15px; }
    .profile-info { text-align: right; }
    .profile-name { font-weight: bold; color: #f8fafc; display: block; }
    .dev-badge { padding:4px 10px; background:#ef4444; color:white; border-radius:99px; font-size:0.75rem; font-weight:bold; }
    
    .avatar-wrapper { position: relative; width: 50px; height: 50px; cursor: pointer; }
    .avatar-img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; border: 2px solid #ef4444; transition: 0.2s; }
    .avatar-img:hover { transform: scale(1.1); box-shadow: 0 0 10px #ef4444; }
    
    /* Frame Mini */
    .frame-mini { position: absolute; top: -10%; left: -10%; width: 120%; height: 120%; pointer-events: none; z-index: 2; }
    .f-gold { border: 2px solid #fbbf24; border-radius:50%; box-shadow: 0 0 5px #fbbf24; }
    .f-fire { border: 2px solid #ef4444; border-radius:50%; box-shadow: 0 0 5px #ef4444; }
    .f-neon { border: 2px solid #06b6d4; border-radius:50%; box-shadow: 0 0 5px #06b6d4; }

    .logout-btn { color:#ef4444; font-weight:bold; text-decoration:none; border: 1px solid #ef4444; padding: 5px 12px; border-radius: 8px; transition: 0.2s; }
    .logout-btn:hover { background: #ef4444; color: white; }

    /* Card Grid */
    .card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:25px; }
    
    /* Card Styles */
    .card { background:rgba(30,41,59,0.6); padding:25px; border-radius:16px; border:1px solid rgba(148,163,184,0.15); box-shadow:0 15px 30px rgba(0,0,0,0.35); backdrop-filter:blur(12px); transition:0.3s; position: relative; overflow: hidden; }
    .card:hover { transform:translateY(-5px); box-shadow:0 25px 40px rgba(0,0,0,0.45); border-color: rgba(148,163,184,0.3); }
    
    .card h3 { margin-top: 10px; margin-bottom: 5px; color: #f1f5f9; font-size: 1.25rem; }
    .card p { color: #94a3b8; font-size: 0.95rem; margin-bottom: 20px; }
    
    .tag { display:inline-block; padding:3px 10px; border-radius:99px; font-size:0.7rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
    
    /* Buttons */
    .btn { display:inline-block; padding:10px 16px; border-radius:10px; font-weight:bold; text-decoration:none; color:#0f172a; transition:0.25s; text-align: center; }
    .btn-green { background:#22c55e; } .btn-green:hover { background:#4ade80; }
    .btn-blue { background:#3b82f6; color:white; } .btn-blue:hover { background:#60a5fa; }
    .btn-purple { background:#d8b4fe; color:#581c87; } .btn-purple:hover { background:#e9d5ff; }
    .btn-yellow { background:#fbbf24; color:#78350f; } .btn-yellow:hover { background:#fcd34d; }
    .btn-cyan { background:#22d3ee; color:#0e7490; } .btn-cyan:hover { background:#67e8f9; }

    /* Special Cards Colors */
    .card-profile { border-left: 5px solid #22d3ee; }
    .card-lab { border-left: 5px solid #a855f7; }
    .card-sim { border: 1px solid #f59e0b; }
    .card-mission { border-left: 5px solid #fbbf24; }
    
    .btn-group { display: flex; gap: 10px; }
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <strong>Developer Console</strong><br>
        <small>Control Panel & System Status</small>
    </div>
    
    <div class="profile-widget">
        <div class="profile-info">
            <span class="profile-name"><?= htmlspecialchars($user['display_name']) ?></span>
            <span class="dev-badge">SYSTEM ADMIN</span>
        </div>
        
        <a href="profile.php" class="avatar-wrapper" title="แก้ไขโปรไฟล์">
            <img src="<?= (!empty($user['profile_pic']) && file_exists('uploads/'.$user['profile_pic'])) ? 'uploads/'.$user['profile_pic'] : 'logo.png' ?>" class="avatar-img">
            <?php if(!empty($user['profile_frame']) && $user['profile_frame']!='none'): ?>
                <div class="frame-mini f-<?= $user['profile_frame'] ?>"></div>
            <?php endif; ?>
        </a>

        <a class="logout-btn" href="logout.php">Logout</a>
    </div>
</div>

<div class="card-grid">

    <div class="card card-profile">
        <span class="tag" style="background:#0e7490; color:#22d3ee;">New Feature</span>
        <h3>👤 โปรไฟล์ & โซเชียล</h3>
        <p>จัดการข้อมูลส่วนตัว, เปลี่ยนกรอบรูป, ระบบเพื่อนและแชท</p>
        <div class="btn-group">
            <a href="profile.php" class="btn btn-cyan">ดูโปรไฟล์</a>
            <a href="chat.php" class="btn" style="background:#334155; color:white;">💬 Chat</a>
        </div>
    </div>

    <div class="card card-lab">
        <span class="tag" style="background:#7e22ce; color:#d8b4fe;">Simulation</span>
        <h3>🧪 ห้องแล็บเคมี (จำลอง)</h3>
        <p>ระบบจำลองการผสมสารเคมี 3D สำหรับทดสอบ</p>
        <a class="btn btn-purple" style="width:100%; box-sizing:border-box;" href="dev_lab.php">🚀 เปิดห้องแล็บ</a>
    </div>

    <div class="card card-mission">
        <span class="tag" style="background:#b45309; color:#fbbf24;">Game Master</span>
        <h3>⚔️ Mission Control</h3>
        <p>สร้างภารกิจ, กำหนด XP/Gold, และจัดการเควส</p>
        <a class="btn btn-yellow" style="width:100%; box-sizing:border-box;" href="create_quest.php">เข้าสู่ศูนย์บัญชาการ</a>
    </div>

    <div class="card">
        <span class="tag" style="background:#1e293b; color:#94a3b8;">Admin Tools</span>
        <h3>👥 จัดการผู้ใช้</h3>
        <p>เพิ่ม/ลบ User และ <strong>รีเซ็ตรหัสผ่าน</strong></p>
        <a class="btn btn-blue" style="width:100%; box-sizing:border-box;" href="user_manager.php">เปิด User Manager</a>
    </div>

    <div class="card card-sim">
        <span class="tag" style="background:#f59e0b; color:white;">Role Sim</span>
        <h3>👨‍🏫 จำลองเป็น: ครู</h3>
        <p>สวมบทบาทครู จัดตารางสอน ตรวจงาน</p>
        <a class="btn btn-yellow" style="width:100%; box-sizing:border-box;" href="switch_mode.php?role=teacher" onclick="return confirm('สลับไปโหมดครู?');">Start Simulation</a>
    </div>

    <div class="card card-sim">
        <span class="tag" style="background:#f59e0b; color:white;">Role Sim</span>
        <h3>👨‍🎓 จำลองเป็น: นักเรียน</h3>
        <p>สวมบทบาทนักเรียน ทำแล็บ ส่งงาน</p>
        <a class="btn btn-yellow" style="width:100%; box-sizing:border-box;" href="switch_mode.php?role=student" onclick="return confirm('สลับไปโหมดนักเรียน?');">Start Simulation</a>
    </div>

    <div class="card card-sim">
        <span class="tag" style="background:#f59e0b; color:white;">Role Sim</span>
        <h3>👨‍👩‍👧 จำลองเป็น: ผู้ปกครอง</h3>
        <p>ดูเกรดและพฤติกรรมบุตรหลาน</p>
        <a class="btn btn-yellow" style="width:100%; box-sizing:border-box;" href="switch_mode.php?role=parent" onclick="return confirm('สลับไปโหมดผู้ปกครอง?');">Start Simulation</a>
    </div>

    <div class="card">
        <span class="tag" style="background:#1e293b; color:#94a3b8;">System</span>
        <h3>📅 ตารางสอน (Global)</h3>
        <p>แก้ไขตารางสอนของครูทุกคนในระบบ</p>
        <div class="btn-group">
            <a class="btn btn-green" href="dev_add_schedule.php">เพิ่ม</a>
            <a class="btn btn-blue" href="dev_view_schedule.php">ดูทั้งหมด</a>
        </div>
    </div>

</div>

</body>
</html>