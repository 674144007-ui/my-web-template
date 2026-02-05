<?php
require_once 'auth.php';
requireRole(['student','developer']);
require_once 'db.php';

// --- ดึงระดับชั้นจาก session ---
$student_class = $_SESSION['class_level'] ?? "";

// --- ตรวจสอบรูปแบบระดับชั้น ---
$valid_classes = ['ม1','ม2','ม3','ม4','ม5','ม6'];
if (!in_array($student_class, $valid_classes)) {
    exit("❌ ระดับชั้นไม่ถูกต้อง หรือผู้ใช้ไม่มี class_level");
}

// -------------------------------------------
// Query แบบปลอดภัยด้วย Prepared Statement
// -------------------------------------------
$stmt = $conn->prepare("
    SELECT a.id, a.due_date, a.assigned_at, a.class_level,
           lib.title, lib.description, lib.file_path, lib.file_type
    FROM assigned_work a
    JOIN assignment_library lib ON a.library_id = lib.id
    WHERE a.class_level = ?
    ORDER BY a.assigned_at DESC
");
$stmt->bind_param("s", $student_class);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang='th'>
<head>
<meta charset='UTF-8'>
<title>งานของฉัน</title>
<style>
body { font-family:system-ui;background:#dbeafe;padding:20px;}
.card {
    background:white;padding:18px;border-radius:14px;margin-bottom:14px;
    box-shadow:0 10px 20px rgba(0,0,0,0.1);
}
</style>
</head>
<body>

<h2>📘 งานที่ต้องส่ง (ระดับชั้น <?= htmlspecialchars($student_class) ?>)</h2>

<?php while($r = $result->fetch_assoc()): ?>
<div class="card">

    <h3><?= htmlspecialchars($r['title']) ?></h3>

    <p><?= nl2br(htmlspecialchars($r['description'])) ?></p>

    <?php if (!empty($r['file_path'])): ?>
        <?php 
        // ป้องกัน Path Traversal
        $safeFile = basename($r['file_path']); 
        ?>
        <a href="uploads/<?= urlencode($safeFile) ?>" download>
            📥 ดาวน์โหลดใบงาน
        </a>
    <?php endif; ?>

    <br>
    <small>กำหนดส่ง: <?= htmlspecialchars($r['due_date']) ?></small><br>
    <small>ได้รับงานเมื่อ: <?= htmlspecialchars($r['assigned_at']) ?></small>
</div>
<?php endwhile; ?>

<a href="dashboard_student.php">⬅ กลับ</a>
</body>
</html>
