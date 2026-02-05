<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$user_id = $_SESSION['user_id'];

// -----------------------------------------
// ดึงเฉพาะงานที่ครูคนนี้มอบหมายจริง ๆ เท่านั้น
// -----------------------------------------
$stmt = $conn->prepare("
    SELECT a.id, a.class_level, a.due_date, a.assigned_at,
           lib.title, lib.description, lib.file_path, lib.file_type
    FROM assigned_work a
    JOIN assignment_library lib ON a.library_id = lib.id
    WHERE lib.teacher_id = ?
    ORDER BY a.assigned_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang='th'>
<head>
<meta charset='UTF-8'>
<title>งานที่ครูมอบหมาย</title>
<style>
body { font-family:system-ui; background:#fef9c3; padding:20px;}
.card {
    background:white; padding:18px; margin-bottom:14px;
    border-radius:14px; box-shadow:0 10px 20px rgba(0,0,0,0.15);
}
.delete-btn {
    display:inline-block;margin-top:10px;
    color:#ef4444;font-weight:bold;text-decoration:none;
}
.delete-btn:hover { color:#b91c1c; }
</style>
</head>
<body>

<?php if (isset($_GET['deleted'])): ?>
<div style="background:#dcfce7;padding:10px;border-radius:8px;color:#166534;margin-bottom:15px;">
    ✔ ลบงานเรียบร้อยแล้ว
</div>
<?php endif; ?>

<h2>📘 งานที่ครูมอบหมาย</h2>

<?php while($r = $result->fetch_assoc()): ?>
<div class="card">

    <h3><?= htmlspecialchars($r['title']) ?> 
        (ระดับชั้น <?= htmlspecialchars($r['class_level']) ?>)
    </h3>

    <p><?= nl2br(htmlspecialchars($r['description'])) ?></p>

    <?php if (!empty($r['file_path'])): ?>
        <?php
        // ป้องกัน Path Traversal
        $safeFile = basename($r['file_path']);
        ?>
        <a href="uploads/<?= urlencode($safeFile) ?>" download>
            📥 ดาวน์โหลดไฟล์แนบ
        </a><br>
    <?php endif; ?>

    <small>กำหนดส่ง: <?= htmlspecialchars($r['due_date']) ?></small><br>
    <small>มอบหมายเมื่อ: <?= htmlspecialchars($r['assigned_at']) ?></small>

    <a class="delete-btn"
       href="delete_assigned_work.php?id=<?= urlencode($r['id']) ?>"
       onclick="return confirm('ต้องการลบงานนี้หรือไม่?');">
       ❌ ลบงานนี้
    </a>

</div>
<?php endwhile; ?>

<a href="dashboard_teacher.php">⬅ กลับหน้าแดชบอร์ด</a>

</body>
</html>
