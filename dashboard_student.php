<?php
// ===================================================================================
// FILE: dashboard_student.php 
// อัปเดตทางเข้า Ultimate Lab (เต็มรูปแบบ ห้ามย่อ + ป้องกัน 500 Error)
// ===================================================================================

require_once 'auth.php';
require_once 'db.php';
requireRole(['student', 'developer']);

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
    die("ไม่พบข้อมูลนักเรียนในระบบ กรุณาล็อกอินใหม่");
}

// เช็คกิลด์ (ป้องกัน Error ถ้าคอลัมน์ guild_id ยังไม่มีใน DB)
$has_guild = isset($user['guild_id']) ? !empty($user['guild_id']) : false;
if (!$has_guild && !isset($_GET['skip_sort'])) {
    header("Location: guild_selection.php"); 
    exit;
}

// เช็คว่าเล่น Daily Quest หรือยัง
$daily_played = (isset($user['last_daily_play']) && $user['last_daily_play'] == date('Y-m-d'));

$is_sim = (isset($_SESSION['dev_simulation_mode']) || (isset($_SESSION['original_role']) && $_SESSION['original_role']=='developer'));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Student Dashboard</title>
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

    .xp-badge { 
        font-size: 0.8rem; 
        background: #fbbf24; 
        color: #78350f; 
        padding: 2px 8px; 
        border-radius: 10px; 
        font-weight: bold; 
    }

    .avatar-img { 
        width: 50px; 
        height: 50px; 
        border-radius: 50%; 
        border: 2px solid #e2e8f0; 
        object-fit: cover; 
    }
    
    .logout-btn { 
        color: #ef4444; 
        text-decoration: none; 
        border: 1px solid #ef4444; 
        padding: 5px 10px; 
        border-radius: 8px; 
        font-size: 0.9rem; 
        margin-left: 10px; 
        transition: 0.2s; 
    }

    .logout-btn:hover { 
        background: #ef4444; 
        color: white; 
    }

    .card-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
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
        position: relative; 
        overflow: hidden; 
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
    
    .c-daily { 
        background: linear-gradient(135deg, #7c3aed, #4c1d95); 
        color: white; 
    }

    .c-daily .btn { 
        background: #a78bfa; 
        color: white; 
    }
    
    .c-leader { 
        background: linear-gradient(135deg, #f59e0b, #b45309); 
        color: white; 
    }
    
    .c-lab { 
        background: linear-gradient(135deg, #0f172a, #334155); 
        color: white; 
        border: 1px solid #38bdf8; 
        box-shadow: 0 0 15px rgba(56, 189, 248, 0.2); 
    }

    .c-lab h3 { 
        color: #38bdf8; 
        text-shadow: 0 0 5px rgba(56, 189, 248, 0.5); 
    }

    .c-lab p { 
        color: #cbd5e1; 
    }

    .c-lab .btn { 
        background: linear-gradient(135deg, #38bdf8, #0ea5e9); 
        color: #0f172a; 
        box-shadow: 0 4px 10px rgba(56, 189, 248, 0.4); 
        text-transform: uppercase; 
        letter-spacing: 1px;
    }
    
    .sim-bar { 
        background: #ef4444; 
        color: white; 
        padding: 10px; 
        text-align: center; 
        margin-bottom: 20px; 
        border-radius: 8px; 
    }
</style>
</head>
<body>

<?php if($is_sim): ?>
<div class="sim-bar">
    ⚠️ คุณกำลังใช้งานโหมดจำลอง (Simulation Mode) <a href="switch_mode.php?action=exit" style="color:white; font-weight:bold; text-decoration: underline;">[ออก]</a>
</div>
<?php endif; ?>

<div class="topbar">
    <div>
        <h2 style="margin:0; color:#1e293b;">Student Dashboard</h2>
        <span style="color:#64748b;">Chemistry World</span>
    </div>

    <div class="profile-widget">
        <div class="profile-info">
            <span class="profile-name"><?= htmlspecialchars($user['display_name']) ?></span>
            <span class="xp-badge">LV. <?= floor(($user['total_score'] ?? 0)/100) + 1 ?> (<?= $user['total_score'] ?? 0 ?> XP)</span>
        </div>
        <a href="profile.php"><img src="<?= !empty($user['profile_pic']) ? 'uploads/'.$user['profile_pic'] : 'logo.png' ?>" class="avatar-img"></a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="card-grid">
    
    <div class="card c-lab">
        <h3>🧪 Ultimate Survival Lab</h3>
        <p>ผสมสารเคมี ทำภารกิจ และเอาชีวิตรอดจากห้องทดลอง!</p>
        <a href="mix.php" class="btn">🔥 เข้าห้องแล็บด่วน!</a>
    </div>

    <div class="card c-daily">
        <h3>🔮 Daily Alchemy</h3>
        <p><?= $daily_played ? "✅ ทำภารกิจวันนี้แล้ว" : "ตอบคำถามประจำวัน รับ XP!" ?></p>
        <a href="daily_quest.php" class="btn"><?= $daily_played ? "ดูผล" : "เล่นเลย" ?></a>
    </div>

    <div class="card c-leader">
        <h3>🏆 Hall of Fame</h3>
        <p>อันดับกิลด์และนักเรียนดีเด่น</p>
        <a href="leaderboard.php" class="btn" style="background:rgba(255,255,255,0.2);">ดูอันดับ</a>
    </div>

    <div class="card c-profile">
        <h3>👤 ข้อมูลส่วนตัว</h3>
        <p>กิลด์, กรอบรูป, เพื่อน</p>
        <div style="display:flex; gap:10px;">
            <a href="profile.php" class="btn">โปรไฟล์</a>
            <a href="chat.php" class="btn" style="background:#10b981; color:white;">💬 แชท</a>
        </div>
    </div>

    <div class="card">
        <h3>📝 การบ้าน</h3>
        <p>ส่งงานครู</p>
        <a href="student_assignments.php" class="btn">ดูงาน</a>
    </div>

</div>

</body>
</html>