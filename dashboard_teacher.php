<?php
// dashboard_teacher.php - Teacher Dashboard (Update: Safe SQL & Lab Entry)

require_once 'auth.php';
require_once 'db.php';
requireRole(['teacher', 'developer', 'admin']);

$my_id = intval($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
if ($my_id == 0) {
    header("Location: index.php");
    exit;
}

try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    if (!$stmt) throw new Exception("Prepare Statement Failed: " . $conn->error);
    $stmt->bind_param("i", $my_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
} catch (Exception $e) {
    die("Database Error: โปรดติดต่อ Admin (" . $e->getMessage() . ")");
}

if (!$user) {
    die("ไม่พบข้อมูลบุคลากรในระบบ กรุณาล็อกอินใหม่");
}

$is_sim = (isset($_SESSION['dev_simulation_mode']) || (isset($_SESSION['original_role']) && $_SESSION['original_role']=='developer'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Teacher Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { 
        margin: 0; 
        padding: 30px; 
        font-family: "Sarabun", sans-serif; 
        background: #f0f2f5; 
    }
    
    .topbar { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        margin-bottom: 30px; 
        background: white; 
        padding: 15px 25px; 
        border-radius: 15px; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.05); 
    }
    
    .profile-widget { 
        display: flex; 
        align-items: center; 
        gap: 15px; 
    }

    .profile-info { 
        text-align: right; 
    }

    .profile-name { 
        font-weight: bold; 
        color: #1e293b; 
        display: block; 
    }

    .profile-role { 
        font-size: 0.8rem; 
        color: #64748b; 
        background: #e2e8f0; 
        padding: 2px 8px; 
        border-radius: 10px; 
    }
    
    .avatar-wrapper { 
        position: relative; 
        width: 50px; 
        height: 50px; 
        cursor: pointer; 
    }

    .avatar-img { 
        width: 100%; 
        height: 100%; 
        object-fit: cover; 
        border-radius: 50%; 
        border: 2px solid #e2e8f0; 
        transition: 0.2s; 
    }

    .avatar-img:hover { 
        transform: scale(1.05); 
        border-color: #3b82f6; 
    }
    
    .frame-mini { 
        position: absolute; 
        top: -10%; 
        left: -10%; 
        width: 120%; 
        height: 120%; 
        pointer-events: none; 
        z-index: 2; 
        border-radius: 50%; 
    }

    .f-gold { border: 2px solid #fbbf24; box-shadow: 0 0 5px #fbbf24; }
    .f-fire { border: 2px solid #ef4444; box-shadow: 0 0 5px #ef4444; }
    .f-neon { border: 2px solid #06b6d4; box-shadow: 0 0 5px #06b6d4; }

    .logout-btn { 
        color: #ef4444; 
        text-decoration: none; 
        font-weight: bold; 
        font-size: 0.9rem; 
        border: 1px solid #ef4444; 
        padding: 5px 10px; 
        border-radius: 8px; 
        transition: 0.2s; 
        margin-left: 10px; 
    }

    .logout-btn:hover { 
        background: #ef4444; 
        color: white; 
    }

    .card-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
        gap: 25px; 
        margin-top: 20px; 
    }

    .card { 
        background: white; 
        padding: 25px; 
        border-radius: 15px; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.05); 
        transition: 0.3s; 
        text-align: center; 
        border: 1px solid #e2e8f0; 
    }

    .card:hover { 
        transform: translateY(-5px); 
        box-shadow: 0 10px 20px rgba(0,0,0,0.1); 
    }

    .btn { 
        display: block; 
        width: 100%; 
        padding: 10px 0; 
        margin-top: 15px; 
        background: #3b82f6; 
        color: white; 
        text-decoration: none; 
        border-radius: 8px; 
        font-weight: bold; 
        transition: 0.2s; 
    }

    .btn:hover { 
        filter: brightness(1.1); 
    }
    
    .c-profile { 
        background: linear-gradient(135deg, #1e293b, #0f172a); 
        color: white; 
    }

    .c-profile .btn { 
        background: #fbbf24; 
        color: black; 
    }

    .c-mission { 
        background: linear-gradient(135deg, #4f46e5, #312e81); 
        color: white; 
    }
    
    .c-lab { 
        background: linear-gradient(135deg, #0f172a, #334155); 
        color: white; 
        border: 1px solid #38bdf8; 
    }

    .c-lab h3 { 
        color: #38bdf8; 
    }

    .c-lab p { 
        color: #cbd5e1; 
    }

    .c-lab .btn { 
        background: #38bdf8; 
        color: #0f172a; 
        box-shadow: 0 4px 10px rgba(56, 189, 248, 0.4); 
    }

    .sim-bar { 
        background: #ef4444; 
        color: white; 
        padding: 10px; 
        text-align: center; 
        margin-bottom: 20px; 
        border-radius: 8px; 
        font-weight: bold; 
    }

    .btn-exit-sim { 
        background: white; 
        color: #ef4444; 
        padding: 3px 10px; 
        border-radius: 15px; 
        text-decoration: none; 
        margin-left: 10px; 
        font-size: 0.9rem; 
    }
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
        <h2 style="margin:0; color:#1e293b;">Teacher Dashboard</h2>
        <span style="color:#64748b; font-size:0.9rem;">ระบบจัดการห้องเรียนวิทยาศาสตร์</span>
    </div>

    <div class="profile-widget">
        <div class="profile-info">
            <span class="profile-name"><?= htmlspecialchars($user['display_name']) ?></span>
            <span class="profile-role"><?= htmlspecialchars($user['teacher_department'] ?? 'ครูผู้สอน') ?></span>
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
    
    <div class="card c-lab">
        <h3>🧪 Ultimate Survival Lab</h3>
        <p>เข้าห้องทดลองเพื่อทดสอบการผสมสารเคมีด้วยตนเอง</p>
        <a href="mix.php" class="btn">🔥 ทดสอบห้องแล็บ</a>
    </div>

    <div class="card c-mission">
        <h3>⚔️ Mission Control</h3>
        <p>สร้างภารกิจ, เควส, และมอบหมายงานแล็บ</p>
        <a href="create_quest.php" class="btn" style="background:white; color:#312e81;">⚙️ ระบบจัดการเควส</a>
    </div>

    <div class="card">
        <h3>📋 ตรวจงาน/ประวัติ</h3>
        <p>ดูงานและผลการทดลองที่นักเรียนส่งมา</p>
        <a href="teacher_assignments.php" class="btn">ดูงานของนักเรียน</a>
    </div>

    <div class="card">
        <h3>📚 คลังเอกสาร</h3>
        <p>ใบงานและสื่อการสอนของฉัน</p>
        <a href="assignment_library.php" class="btn" style="background:#64748b;">จัดการเอกสาร</a>
    </div>
    
    <div class="card c-profile">
        <h3>👤 ข้อมูลส่วนตัว</h3>
        <p>แก้ไขรูป, กรอบ, แชทกับบุคลากร/นักเรียน</p>
        <div style="display:flex; gap:10px;">
            <a href="profile.php" class="btn">โปรไฟล์</a>
            <a href="chat.php" class="btn" style="background:#10b981; color:white;">💬 แชท</a>
        </div>
    </div>

</div>

</body>
</html>