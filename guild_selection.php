<?php
// guild_selection.php - ระบบคัดเลือกเข้ากิลด์ (Sorting Ceremony)
session_start();
require_once 'auth.php';
require_once 'db.php';
if (!isLoggedIn()) { header("Location: index.php"); exit; }

$my_id = $_SESSION['user_id'];

// ตรวจสอบว่ามีกิลด์หรือยัง
$u = $conn->query("SELECT guild_id FROM users WHERE id=$my_id")->fetch_assoc();
if (!empty($u['guild_id'])) {
    header("Location: dashboard_student.php"); // ถ้ามีแล้วกลับหน้าหลัก
    exit;
}

// Logic สุ่มกิลด์ (เมื่อกดปุ่ม)
if (isset($_POST['join_guild'])) {
    // สุ่มเลข 1-4
    $g_id = rand(1, 4);
    $conn->query("UPDATE users SET guild_id=$g_id WHERE id=$my_id");
    
    // Animation Delay จำลอง (ใช้ JS ในหน้าถัดไป หรือ Redirect เลย)
    header("Location: guild_selection.php?success=$g_id");
    exit;
}

$new_guild = null;
if (isset($_GET['success'])) {
    $gid = intval($_GET['success']);
    $new_guild = $conn->query("SELECT * FROM guilds WHERE id=$gid")->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Sorting Ceremony</title>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Sarabun:wght@400&display=swap" rel="stylesheet">
<style>
    body { 
        margin:0; background: #0f172a; color: white; font-family: 'Sarabun', sans-serif; 
        height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center;
        overflow: hidden;
        background-image: radial-gradient(circle at 50% 50%, #1e293b 0%, #000000 100%);
    }
    
    .container { text-align: center; z-index: 10; }
    
    h1 { font-family: 'Cinzel', serif; font-size: 3rem; color: #fbbf24; text-shadow: 0 0 20px #fbbf24; margin-bottom: 10px; }
    p { color: #cbd5e1; font-size: 1.2rem; margin-bottom: 40px; }
    
    .magic-circle {
        width: 200px; height: 200px; border: 5px solid rgba(255,255,255,0.2); border-radius: 50%;
        margin: 0 auto 40px; position: relative;
        animation: spin 10s linear infinite;
        box-shadow: 0 0 50px rgba(59, 130, 246, 0.2);
    }
    .magic-circle::before {
        content:''; position:absolute; top:10px; left:10px; right:10px; bottom:10px;
        border: 2px dashed rgba(255,255,255,0.5); border-radius: 50%;
    }
    
    @keyframes spin { 100% { transform: rotate(360deg); } }
    
    .btn-start {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white; padding: 15px 40px; border: none; border-radius: 50px;
        font-size: 1.5rem; font-weight: bold; cursor: pointer;
        box-shadow: 0 0 30px rgba(139, 92, 246, 0.5);
        transition: 0.3s; text-decoration: none;
    }
    .btn-start:hover { transform: scale(1.1); box-shadow: 0 0 50px rgba(139, 92, 246, 0.8); }

    /* Result Modal */
    .result-box {
        position: fixed; top:0; left:0; width:100%; height:100%;
        background: rgba(0,0,0,0.9); display: flex; flex-direction: column;
        justify-content: center; align-items: center; z-index: 100;
        animation: fadeIn 1s;
    }
    .guild-badge {
        width: 150px; height: 150px; border-radius: 50%; 
        display: flex; align-items: center; justify-content: center;
        font-size: 3rem; font-weight: bold; margin-bottom: 20px;
        box-shadow: 0 0 60px currentColor; border: 5px solid white;
    }
    @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
</style>
</head>
<body>

<?php if ($new_guild): ?>
    <div class="result-box">
        <h2 style="color:white; font-size:2rem;">ยินดีต้อนรับสู่...</h2>
        <div class="guild-badge" style="background: <?= $new_guild['color'] ?>; color: <?= $new_guild['color'] ?>;">
            <span style="color:white;">⚔️</span>
        </div>
        <h1 style="color: <?= $new_guild['color'] ?>; font-size:4rem;"><?= htmlspecialchars($new_guild['name']) ?></h1>
        <p><?= htmlspecialchars($new_guild['description']) ?></p>
        <a href="dashboard_student.php" class="btn-start">เข้าสู่ Dashboard</a>
    </div>
<?php else: ?>
    <div class="container">
        <div class="magic-circle"></div>
        <h1>Sorting Ceremony</h1>
        <p>พิธีคัดเลือกเข้าบ้านแห่งธาตุ... ชะตาของคุณถูกกำหนดไว้แล้ว</p>
        
        <form method="post">
            <button type="submit" name="join_guild" class="btn-start">สุ่มเลือกบ้าน</button>
        </form>
    </div>
<?php endif; ?>

</body>
</html>