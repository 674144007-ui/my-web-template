<?php
require_once 'security.php';
require_once 'auth.php';
requireRole(['student','developer']);
require_once 'db.php';

// --- ดึงระดับชั้นจาก session ---
$student_class = $_SESSION['class_id'] ?? "";

// --- ตรวจสอบรูปแบบระดับชั้น ---
$valid_classes = ['ม1','ม2','ม3','ม4','ม5','ม6'];
if (!in_array($student_class, $valid_classes)) {
    exit("❌ ระดับชั้นไม่ถูกต้อง หรือผู้ใช้ไม่มี class_level");
}

// -------------------------------------------
// Query แบบปลอดภัยด้วย Prepared Statement
// -------------------------------------------
$stmt = $pdo->prepare("
    SELECT a.id, a.due_date, a.assigned_at, a.class_level,
           lib.title, lib.description, lib.file_path, lib.file_type
    FROM assigned_work a
    JOIN assignment_library lib ON a.library_id = lib.id
    WHERE a.class_level = ?
    ORDER BY a.assigned_at DESC
");
$stmt->execute([$student_class]);
$stmt->execute();
$result = $stmt;

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

<?php while($r = $result->fetch()): ?>
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
<?php endwhile; 
    } catch (\PDOException $e) {
        error_log('[db_error] ' . $e->getMessage() . ' in ' . __FILE__ . ':' . __LINE__);
        if (!headers_sent()) {
            http_response_code(500);
        }
        $is_dev = defined('APP_ENV') && APP_ENV === 'development' && defined('APP_DEBUG') && APP_DEBUG;
        $msg = $is_dev ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') : 'กรุณาลองใหม่อีกครั้ง';
        die('<div style="font-family:sans-serif;text-align:center;padding:3rem">'
          . '<h3 style="color:#ef4444">เกิดข้อผิดพลาดในระบบฐานข้อมูล</h3>'
          . '<p style="color:#6b7280">' . $msg . '</p>'
          . '<a href="index.php" style="color:#3b82f6">← กลับหน้าหลัก</a>'
          . '</div>');
    }

?>

try {

<a href="dashboard_student.php">⬅ กลับ</a>
</body>
</html>
