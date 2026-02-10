<?php
// leaderboard.php - กระดานคะแนน (House & Student Rankings)
session_start();
require_once 'db.php';

// ดึงคะแนนกิลด์
$guilds = $conn->query("SELECT * FROM guilds ORDER BY score DESC");

// ดึงคะแนนนักเรียน Top 10
$top_students = $conn->query("SELECT u.display_name, u.total_score, u.profile_pic, g.name as gname, g.color 
                               FROM users u 
                               LEFT JOIN guilds g ON u.guild_id = g.id 
                               WHERE u.role='student' 
                               ORDER BY u.total_score DESC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Leaderboard</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { background: #0f172a; color: white; font-family: 'Sarabun', sans-serif; margin:0; padding:20px; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { text-align: center; color: #fbbf24; text-shadow: 0 0 10px #fbbf24; margin-bottom: 40px; }
    
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
    @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
    
    /* Guild Card */
    .guild-card { 
        background: rgba(30,41,59,0.5); padding: 20px; border-radius: 15px; 
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 15px; border: 1px solid rgba(255,255,255,0.1);
        transition: 0.3s;
    }
    .guild-card:hover { transform: scale(1.02); background: rgba(30,41,59,0.8); }
    .g-score { font-size: 1.5rem; font-weight: bold; }
    
    /* Student List */
    .student-row {
        display: flex; align-items: center; padding: 12px; 
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .rank { width: 30px; font-weight: bold; color: #94a3b8; }
    .s-avatar { width: 40px; height: 40px; border-radius: 50%; margin-right: 15px; object-fit: cover; }
    .s-info { flex: 1; }
    .s-score { font-weight: bold; color: #fbbf24; }
    
    .back-btn { display: inline-block; margin-bottom: 20px; color: #94a3b8; text-decoration: none; }
</style>
</head>
<body>

<div class="container">
    <a href="javascript:history.back()" class="back-btn">⬅ กลับ</a>
    <h1>🏆 HALL OF FAME</h1>
    
    <div class="grid">
        <div>
            <h2 style="border-bottom: 2px solid #3b82f6; padding-bottom: 10px;">สงคราม 4 ธาตุ</h2>
            <?php while($g = $guilds->fetch_assoc()): ?>
            <div class="guild-card" style="border-left: 5px solid <?= $g['color'] ?>">
                <div>
                    <h3 style="margin:0; color:<?= $g['color'] ?>"><?= htmlspecialchars($g['name']) ?></h3>
                    <small style="color:#94a3b8">ธาตุ: <?= ucfirst($g['element']) ?></small>
                </div>
                <div class="g-score"><?= number_format($g['score']) ?> 💎</div>
            </div>
            <?php endwhile; ?>
        </div>
        
        <div>
            <h2 style="border-bottom: 2px solid #10b981; padding-bottom: 10px;">สุดยอดนักเล่นแร่แปรธาตุ</h2>
            <div style="background: rgba(30,41,59,0.5); border-radius: 15px; padding: 10px;">
                <?php $rank=1; while($s = $top_students->fetch_assoc()): ?>
                <div class="student-row">
                    <div class="rank">#<?= $rank++ ?></div>
                    <img src="<?= $s['profile_pic'] ? 'uploads/'.$s['profile_pic'] : 'logo.png' ?>" class="s-avatar">
                    <div class="s-info">
                        <div><?= htmlspecialchars($s['display_name']) ?></div>
                        <small style="color:<?= $s['color'] ?>"><?= $s['gname'] ?? 'ไม่มีสังกัด' ?></small>
                    </div>
                    <div class="s-score"><?= number_format($s['total_score']) ?> XP</div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>