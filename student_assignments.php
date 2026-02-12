<?php
require_once 'auth.php';
requireRole(['student','developer']);
require_once 'db.php';

$user = currentUser();
$my_id = $user['id'];
$my_class = $user['class_level'];

// -------------------------------------------
// Query ดึงงานที่:
// 1. ตรงกับห้อง (class_level)
// 2. หรือ ตรงกับตัวเรา (student_id)
// -------------------------------------------
$sql = "
    SELECT a.id, a.due_date, a.assigned_at, 
           lib.title, lib.description, lib.file_path, lib.file_type,
           u.display_name as teacher_name
    FROM assigned_work a
    JOIN assignment_library lib ON a.library_id = lib.id
    JOIN users u ON a.teacher_id = u.id
    WHERE (a.class_level = ? AND a.student_id IS NULL) 
       OR (a.student_id = ?)
    ORDER BY a.assigned_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
}
$stmt->bind_param("si", $my_class, $my_id);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang='th'>
<head>
<meta charset='UTF-8'>
<title>งานของฉัน</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
body { font-family: 'Sarabun', sans-serif; background:#eef2f7; padding:20px;}
.header { text-align:center; margin-bottom:30px; color:#2c3e50; }
.card-container { display:grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap:20px; max-width:1000px; margin:0 auto; }
.card {
    background:white; padding:20px; border-radius:12px;
    box-shadow:0 5px 15px rgba(0,0,0,0.08); position:relative;
    border-left: 5px solid #3498db; transition:transform 0.2s;
}
.card:hover { transform:translateY(-5px); }
.card h3 { margin-top:0; color:#2980b9; font-size:1.2rem; }
.teacher-name { font-size:0.85rem; color:#7f8c8d; margin-bottom:10px; }
.desc { color:#555; font-size:0.95rem; margin-bottom:15px; min-height:60px; }
.meta { font-size:0.85rem; color:#888; border-top:1px solid #eee; padding-top:10px; margin-top:10px; display:flex; justify-content:space-between; }
.badge { 
    background:#e67e22; color:white; padding:3px 8px; border-radius:12px; font-size:0.75rem; 
    position:absolute; top:15px; right:15px; 
}
.download-btn {
    display:inline-block; text-decoration:none; background:#ecf0f1; color:#2c3e50;
    padding:8px 12px; border-radius:6px; font-size:0.9rem; margin-top:5px;
}
.download-btn:hover { background:#bdc3c7; }
</style>
</head>
<body>

<div class="header">
    <h2>📘 งานที่ต้องส่ง</h2>
    <p>นักเรียน: <?= htmlspecialchars($user['display_name']) ?> | ห้อง: <?= htmlspecialchars($my_class) ?></p>
</div>

<div class="card-container">
    <?php if($result->num_rows === 0): ?>
        <p style="text-align:center; width:100%; color:#999;">🎉 ไม่มีงานค้างส่งในขณะนี้</p>
    <?php endif; ?>

    <?php while($r = $result->fetch_assoc()): ?>
    <div class="card">
        <?php if($r['due_date'] < date('Y-m-d')) echo '<span class="badge" style="background:#c0392b;">เลยกำหนด</span>'; ?>
        
        <h3><?= htmlspecialchars($r['title']) ?></h3>
        <div class="teacher-name">ครูผู้สั่ง: <?= htmlspecialchars($r['teacher_name']) ?></div>
        
        <div class="desc"><?= nl2br(htmlspecialchars($r['description'])) ?></div>

        <?php if (!empty($r['file_path'])): ?>
            <?php $safeFile = basename($r['file_path']); ?>
            <a href="uploads/<?= urlencode($safeFile) ?>" class="download-btn" download>
                📥 ดาวน์โหลดไฟล์แนบ
            </a>
        <?php endif; ?>

        <div class="meta">
            <span>📅 ส่งภายใน: <?= htmlspecialchars($r['due_date']) ?></span>
        </div>
    </div>
    <?php endwhile; ?>
</div>

<div style="text-align:center; margin-top:30px;">
    <a href="dashboard_student.php" style="text-decoration:none; color:#7f8c8d;">⬅ กลับหน้าหลัก</a>
</div>

</body>
</html>