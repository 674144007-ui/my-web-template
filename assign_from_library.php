<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// ------------------------------------------------------------
// CSRF Token
// ------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ------------------------------------------------------------
// ตรวจสอบ id ที่ส่งมา
// ------------------------------------------------------------
if (!isset($_GET['id'])) {
    exit("❌ ไม่พบรหัสงานจากคลัง");
}

$library_id = intval($_GET['id']);
$teacher_id = $_SESSION['user_id'];

// ------------------------------------------------------------
// ตรวจสอบว่า library_id เป็นของครูคนนี้จริงไหม
// ------------------------------------------------------------
$check = $conn->prepare("
    SELECT id, title, teacher_id 
    FROM assignment_library 
    WHERE id = ? LIMIT 1
");
$check->bind_param("i", $library_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    exit("❌ ไม่พบงานนี้ในคลัง");
}

$check->bind_result($lib_id, $lib_title, $lib_teacher_id);
$check->fetch();

if ($lib_teacher_id != $teacher_id) {
    exit("❌ คุณไม่มีสิทธิ์มอบหมายงานของผู้อื่น");
}

$check->close();

// ------------------------------------------------------------
// เมื่อ POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ตรวจ CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("❌ Invalid CSRF Token");
    }

    $class_level = $_POST['class_level'] ?? '';
    $due_date    = $_POST['due_date'] ?? '';

    // Validate class level
    $valid_levels = ['ม1','ม2','ม3','ม4','ม5','ม6'];
    if (!in_array($class_level, $valid_levels)) {
        $msg = "❌ ระดับชั้นไม่ถูกต้อง";
        $msg_type = "error";
    }
    // Validate due date
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        $msg = "❌ วันที่ไม่ถูกต้อง";
        $msg_type = "error";
    }
    else {
        // Insert
        $stmt = $conn->prepare("
            INSERT INTO assigned_work (library_id, class_level, due_date) 
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iss", $library_id, $class_level, $due_date);

        if ($stmt->execute()) {
            $msg = "✔ มอบหมายงานสำเร็จ!";
            $msg_type = "success";
        } else {
            $msg = "❌ เกิดข้อผิดพลาดในการมอบหมาย";
            $msg_type = "error";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>มอบหมายงาน</title>
<style>
body { font-family:system-ui; background:#dcfce7; padding:20px; }
.card {
    background:white; padding:20px; border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,0.12); max-width:600px; margin:auto;
}
input, select {
    width:100%; padding:12px; border-radius:10px; border:1px solid #ccc;
    margin-bottom:12px;
}
button {
    padding:12px; background:#16a34a; border:none;
    color:white; border-radius:8px; cursor:pointer;
}
button:hover { background:#15803d; }
.msg { padding:10px; border-radius:10px; margin-bottom:12px; }
.msg.success { background:#bbf7d0; color:#166534; }
.msg.error { background:#fecaca; color:#991b1b; }
</style>
</head>
<body>

<div class="card">
    <h2>📌 มอบหมายงาน: <?= htmlspecialchars($lib_title) ?></h2>

    <?php if($msg): ?>
        <div class="msg <?= htmlspecialchars($msg_type) ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>มอบหมายให้ระดับชั้น</label>
        <select name="class_level" required>
            <option value="ม1">ม.1</option>
            <option value="ม2">ม.2</option>
            <option value="ม3">ม.3</option>
            <option value="ม4">ม.4</option>
            <option value="ม5">ม.5</option>
            <option value="ม6">ม.6</option>
        </select>

        <label>กำหนดส่ง</label>
        <input type="date" name="due_date" required>

        <button type="submit">มอบหมายงานนี้</button>
    </form>

    <br>
    <a href="assignment_library.php">⬅ กลับคลังงาน</a>
</div>

</body>
</html>
