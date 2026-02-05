<?php
require_once 'auth.php';
requireRole(['parent','developer']);
require_once 'db.php';

// -----------------------------------------------------
// ดึงระดับชั้นของบุตรหลานจาก session
// หมายเหตุ: ต้องใส่ค่า child_class_level ตอน login parent
// -----------------------------------------------------
$child_class = $_SESSION['child_class_level'] ?? "";

// ตรวจสอบระดับชั้นที่ถูกต้อง
$valid_classes = ['ม1','ม2','ม3','ม4','ม5','ม6'];
if (!in_array($child_class, $valid_classes)) {
    exit("❌ ระดับชั้นของบุตรหลานไม่ถูกต้อง หรือยังไม่ได้ตั้งค่าในระบบ");
}

// -----------------------------------------------------
// Query แบบปลอดภัยด้วย Prepared Statement
// -----------------------------------------------------
$stmt = $conn->prepare("
    SELECT a.id, a.due_date, a.assigned_at,
           lib.title, lib.description, lib.file_path, lib.file_type
    FROM assigned_work a
    JOIN assignment_library lib ON a.library_id = lib.id
    WHERE a.class_level = ?
    ORDER BY a.assigned_at DESC
");
$stmt->bind_param("s", $child_class);
$stmt->execute();
$result = $stmt->get_result();

?>
<!DOCTYPE html>
<html lang='th'>
<head>
<meta charset='UTF-8'>
<title>งานของบุตรหลาน</title>
<style>
body { font-family:system-ui;background:#dcfce7;padding:20px;}
.card{
    background:white;padding:18px;border-radius:14px;margin-bottom:14px;
    box-shadow:0 10px 20px rgba(0,0,0,0.1);
}
</style>
</head>
<body>

<h2>📘 งานของบุตรหลาน (ระดับชั้น <?= htmlspecialchars($child_class) ?>)</h2>

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
            📥 ดาวน์โหลดไฟล์
        </a><br>
    <?php endif; ?>

    <small>กำหนดส่ง: <?= htmlspecialchars($r['due_date']) ?></small><br>
    <small>มอบหมายเมื่อ: <?= htmlspecialchars($r['assigned_at']) ?></small>

</div>
<?php endwhile; ?>

<a href="dashboard_parent.php">⬅ กลับ</a>
</body>
</html>
