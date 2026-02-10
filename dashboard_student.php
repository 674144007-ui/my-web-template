<?php
// dashboard_student.php - Student Dashboard (Update: Profile Widget)
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
require_once 'db.php';

requireRole(['student', 'developer']);

// ดึงข้อมูล User ล่าสุด
$my_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $my_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$is_sim = (isset($_SESSION['dev_simulation_mode']) || (isset($_SESSION['original_role']) && $_SESSION['original_role']=='developer'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Student Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { margin:0; padding:30px; font-family:"Sarabun",sans-serif; background:#f0f2f5; }
    
    .topbar { 
        display:flex; justify-content:space-between; align-items:center; 
        margin-bottom:30px; background: white; padding: 15px 25px; 
        border-radius: 15px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }
    
    .profile-widget { display: flex; align-items: center; gap: 15px; }
    .profile-info { text-align: right; }
    .profile-name { font-weight: bold; color: #1e293b; display: block; }
    .profile-role { font-size: 0.8rem; color: #64748b; background: #e2e8f0; padding: 2px 8px; border-radius: 10px; }
    
    .avatar-wrapper { position: relative; width: 50px; height: 50px; cursor: pointer; }
    .avatar-img { 
        width: 100%; height: 100%; object-fit: cover; border-radius: 50%; 
        border: 2px solid #e2e8f0; transition: 0.2s;
    }
    .avatar-img:hover { transform: scale(1.05); border-color: #3b82f6; }
    
    .frame-mini { position: absolute; top: -10%; left: -10%; width: 120%; height: 120%; pointer-events: none; z-index: 2; border-radius: 50%; }
    .f-gold { border: 2px solid #fbbf24; box-shadow: 0 0 5px #fbbf24; }
    .f-fire { border: 2px solid #ef4444; box-shadow: 0 0 5px #ef4444; }
    .f-neon { border: 2px solid #06b6d4; box-shadow: 0 0 5px #06b6d4; }

    .logout-btn { 
        color: #ef4444; text-decoration: none; font-weight: bold; font-size: 0.9rem; 
        border: 1px solid #ef4444; padding: 5px 10px; border-radius: 8px; transition: 0.2s;
        margin-left: 10px;
    }
    .logout-btn:hover { background: #ef4444; color: white; }

    .card-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:25px; margin-top: 20px; }
    .card { background:white; padding:25px; border-radius:15px; box-shadow:0 4px 10px rgba(0,0,0,0.05); transition:0.3s; text-align:center; border:1px solid #e2e8f0; }
    .card:hover { transform:translateY(-5px); box-shadow:0 10px 20px rgba(0,0,0,0.1); }
    .btn { display:block; width:100%; padding:10px 0; margin-top:15px; background:#3b82f6; color:white; text-decoration:none; border-radius:8px; font-weight:bold; }
    
    .c-profile { background: linear-gradient(135deg, #1e293b, #0f172a); color:white; }
    .c-profile .btn { background:#fbbf24; color:black; }

    .sim-bar { background: #ef4444; color: white; padding: 10px; text-align: center; margin-bottom: 20px; border-radius: 8px; font-weight: bold; }
    .btn-exit-sim { background: white; color: #ef4444; padding: 3px 10px; border-radius: 15px; text-decoration: none; margin-left: 10px; font-size: 0.9rem; }
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
        <h2 style="margin:0; color:#1e293b;">Student Dashboard</h2>
        <span style="color:#64748b; font-size:0.9rem;">ห้องเรียนออนไลน์</span>
    </div>

    <div class="profile-widget">
        <div class="profile-info">
            <span class="profile-name"><?= htmlspecialchars($user['display_name']) ?></span>
            <span class="profile-role"><?= htmlspecialchars($user['class_level'] ?? 'นักเรียน') ?></span>
        </div>
        
        <a href="profile.php" class="avatar-wrapper" title="ดูโปรไฟล์">
            <img src="<?= (!empty($user['profile_pic']) && file_exists('uploads/'.$user['profile_pic'])) ? 'uploads/'.$user['profile_pic'] : 'logo.png' ?>" class="avatar-img">
            <?php if(!empty($user['profile_frame']) && $user['profile_frame']!='none'): ?>
                <div class="frame-mini f-<?= $user['profile_frame'] ?>"></div>
            <?php endif; ?>
        </a>

        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="card-grid">
    <div class="card c-profile">
        <h3>👤 โปรไฟล์ & โซเชียล</h3>
        <p>แก้ไขรูป, กรอบ, แอดเพื่อน</p>
        <div style="display:flex; gap:10px;">
            <a href="profile.php" class="btn">ดูโปรไฟล์</a>
            <a href="chat.php" class="btn" style="background:#10b981; color:white;">💬 แชท</a>
        </div>
    </div>

    <div class="card">
        <h3>⚗️ เข้าห้องแล็บ</h3>
        <p>ทำการทดลองเคมีเสมือนจริง</p>
        <a href="mix.php" class="btn">เริ่มทดลอง</a>
    </div>

    <div class="card">
        <h3>📝 การบ้านของฉัน</h3>
        <p>ดูรายการงานที่ได้รับมอบหมาย</p>
        <a href="student_assignments.php" class="btn">ดูงาน</a>
    </div>
</div>

</body>
</html>