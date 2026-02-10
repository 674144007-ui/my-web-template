<?php
// daily_quest.php - คำถามประจำวัน
session_start();
require_once 'auth.php';
require_once 'db.php';
if (!isLoggedIn()) { header("Location: index.php"); exit; }

$my_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// เช็คว่าเล่นไปหรือยัง
$u = $conn->query("SELECT last_daily_play, guild_id, total_score FROM users WHERE id=$my_id")->fetch_assoc();
$played = ($u['last_daily_play'] == $today);

// สุ่มคำถาม (ถ้ายังไม่เล่น)
$q = null;
if (!$played) {
    // ใช้ Session เก็บ ID คำถามเดิมไว้จนกว่าจะตอบ (กันรีเฟรชเปลี่ยนโจทย์)
    if (!isset($_SESSION['daily_q_id'])) {
        $rand_q = $conn->query("SELECT * FROM daily_questions ORDER BY RAND() LIMIT 1");
        if ($rand_q->num_rows > 0) {
            $q = $rand_q->fetch_assoc();
            $_SESSION['daily_q_id'] = $q['id'];
        }
    } else {
        $qid = $_SESSION['daily_q_id'];
        $q = $conn->query("SELECT * FROM daily_questions WHERE id=$qid")->fetch_assoc();
    }
}

// ตรวจคำตอบ
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$played) {
    $ans = $_POST['answer'];
    $qid = $_POST['q_id'];
    
    $check = $conn->query("SELECT * FROM daily_questions WHERE id=$qid")->fetch_assoc();
    if ($check && $check['correct_choice'] == $ans) {
        $xp = $check['xp_reward'];
        $gid = $u['guild_id'];
        
        // อัปเดต User
        $conn->query("UPDATE users SET total_score = total_score + $xp, last_daily_play='$today' WHERE id=$my_id");
        // อัปเดต Guild
        if ($gid) $conn->query("UPDATE guilds SET score = score + $xp WHERE id=$gid");
        
        $msg = "✅ ถูกต้อง! คุณได้รับ $xp XP และแต้มเข้าบ้าน";
        $played = true;
    } else {
        // ตอบผิด (ให้เล่นใหม่พรุ่งนี้)
        $conn->query("UPDATE users SET last_daily_play='$today' WHERE id=$my_id");
        $msg = "❌ ผิดครับ! คำตอบที่ถูกคือ (" . strtoupper($check['correct_choice']) . ")";
        $played = true;
    }
    unset($_SESSION['daily_q_id']); // เคลียร์โจทย์
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Daily Alchemy</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { background: #0f172a; color: white; font-family: 'Sarabun', sans-serif; margin:0; padding:20px; display:flex; justify-content:center; align-items:center; min-height:100vh; }
    .card { 
        background: rgba(30,41,59,0.8); backdrop-filter: blur(10px); 
        padding: 40px; border-radius: 20px; border: 1px solid #334155; 
        max-width: 500px; width: 100%; text-align: center;
        box-shadow: 0 0 50px rgba(59, 130, 246, 0.2);
    }
    h1 { color: #fbbf24; margin-top:0; }
    .btn-ans { 
        display: block; width: 100%; padding: 15px; margin: 10px 0; 
        background: #1e293b; border: 1px solid #475569; color: white; 
        border-radius: 10px; cursor: pointer; transition: 0.2s; text-align: left;
        font-size: 1.1rem;
    }
    .btn-ans:hover { background: #3b82f6; border-color: #3b82f6; }
    
    .result-box { font-size: 1.5rem; margin: 20px 0; font-weight: bold; }
    .back-btn { color: #94a3b8; text-decoration: none; margin-top: 20px; display: inline-block; }
</style>
</head>
<body>

<div class="card">
    <h1>🔮 Daily Alchemy</h1>
    
    <?php if ($msg): ?>
        <div class="result-box" style="color: <?= strpos($msg,'ถูก')!==false ? '#4ade80' : '#ef4444' ?>">
            <?= $msg ?>
        </div>
        <a href="dashboard_student.php" class="back-btn">กลับสู่ Dashboard</a>
    <?php elseif ($played): ?>
        <p style="font-size:1.2rem; color:#cbd5e1;">คุณทำภารกิจวันนี้ไปแล้ว<br>กลับมาใหม่พรุ่งนี้นะ!</p>
        <a href="dashboard_student.php" class="back-btn">กลับสู่ Dashboard</a>
    <?php else: ?>
        <p style="margin-bottom:30px; font-size:1.2rem;"><?= htmlspecialchars($q['question']) ?></p>
        
        <form method="post">
            <input type="hidden" name="q_id" value="<?= $q['id'] ?>">
            <button type="submit" name="answer" value="a" class="btn-ans">A. <?= htmlspecialchars($q['choice_a']) ?></button>
            <button type="submit" name="answer" value="b" class="btn-ans">B. <?= htmlspecialchars($q['choice_b']) ?></button>
            <button type="submit" name="answer" value="c" class="btn-ans">C. <?= htmlspecialchars($q['choice_c']) ?></button>
            <button type="submit" name="answer" value="d" class="btn-ans">D. <?= htmlspecialchars($q['choice_d']) ?></button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>