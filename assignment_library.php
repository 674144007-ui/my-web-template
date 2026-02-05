<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$teacher_id = $_SESSION['user_id'];

// --- ดึงข้อมูลแบบ prepared statement ---
$stmt = $conn->prepare("
    SELECT id, title, description, file_path, file_type, created_at 
    FROM assignment_library
    WHERE teacher_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>คลังงานของครู</title>
<style>
body {
    font-family: system-ui;
    background: #e0f2fe;
    padding: 20px;
}

.card {
    background: white;
    padding: 20px;
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    margin-bottom: 15px;
}

.btn {
    padding: 8px 12px;
    background: #10b981;
    color: white;
    border-radius: 8px;
    text-decoration: none;
    margin-top: 10px;
    display: inline-block;
}

.btn:hover { background: #059669; }

.download {
    display:inline-block;
    padding:8px 12px;
    background:#2563eb;
    color:white;
    border-radius:8px;
    text-decoration:none;
}
.download:hover { background:#1e40af; }
</style>
</head>
<body>

<h2>📚 คลังงานของครู</h2>

<?php while ($row = $result->fetch_assoc()): ?>
<div class="card">

    <h3><?= htmlspecialchars($row['title']) ?></h3>

    <p><?= nl2br(htmlspecialchars($row['description'])) ?></p>

    <small>เพิ่มเมื่อ <?= htmlspecialchars($row['created_at']) ?></small>
    <br><br>

    <?php if (!empty($row['file_path'])): ?>

        <?php
        // ป้องกัน Path Traversal โดยแสดงเฉพาะชื่อไฟล์
        $safeDownload = basename($row['file_path']);
        ?>

        <a class="download" 
           href="uploads/<?= urlencode($safeDownload) ?>" 
           download>
           📥 ดาวน์โหลดไฟล์แนบ (<?= strtoupper(htmlspecialchars($row['file_type'])) ?>)
        </a>

        <br><br>
    <?php endif; ?>

    <a class="btn" href="assign_from_library.php?id=<?= urlencode($row['id']) ?>">
        📌 มอบหมายงานนี้
    </a>

</div>
<?php endwhile; ?>

<a href="dashboard_teacher.php">⬅ กลับแดชบอร์ดครู</a>

</body>
</html>
