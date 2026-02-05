<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$message = "";
$message_type = "";

//-------------------------
// สร้าง CSRF token
//-------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

//-------------------------
// เมื่อส่งฟอร์ม
//-------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ตรวจสอบ CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("❌ Invalid CSRF Token");
    }

    // เก็บตัวแปรแบบ sanitize
    $title       = trim($_POST['title']);
    $desc        = trim($_POST['description']);
    $class_level = trim($_POST['class_level']);
    $due_date    = trim($_POST['due_date']);
    $teacher_id  = $_SESSION['user_id'];

    //-------------------------
    // ตรวจสอบ input
    //-------------------------

    // 1. หัวข้องานต้องไม่ว่าง
    if ($title === "") {
        $message = "❌ โปรดระบุชื่องาน";
        $message_type = "error";
    }

    // 2. ตรวจ class level
    elseif (!in_array($class_level, ['ม1','ม2','ม3','ม4','ม5','ม6'])) {
        $message = "❌ ระดับชั้นไม่ถูกต้อง";
        $message_type = "error";
    }

    // 3. ตรวจ due date format
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        $message = "❌ รูปแบบวันที่ไม่ถูกต้อง";
        $message_type = "error";
    }

    // 4. Due date ต้องไม่ย้อนหลัง
    elseif (strtotime($due_date) < strtotime(date("Y-m-d"))) {
        $message = "❌ กำหนดส่งต้องไม่ย้อนหลัง";
        $message_type = "error";
    }

    // 5. ถ้าทุกอย่างถูกต้อง → บันทึก
    else {
        $stmt = $conn->prepare("
            INSERT INTO assignments(title, description, class_level, due_date, created_by)
            VALUES (?,?,?,?,?)
        ");
        $stmt->bind_param("ssssi", $title, $desc, $class_level, $due_date, $teacher_id);

        if ($stmt->execute()) {
            $message = "✔ งานมอบหมายถูกสร้างเรียบร้อยแล้ว!";
            $message_type = "success";
        } else {
            $message = "❌ เกิดข้อผิดพลาดในการบันทึกข้อมูล";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สร้างงานมอบหมาย</title>

<style>
body { font-family:system-ui; background:#fef9c3; padding:20px; }

.card {
    background:white; padding:20px; border-radius:16px;
    max-width:600px; margin:0 auto;
    box-shadow:0 10px 25px rgba(0,0,0,0.15);
}

input, textarea, select {
    width:100%; padding:10px;
    border-radius:10px; border:1px solid #ccc; margin-top:8px;
}

button {
    padding:12px 18px; background:#f59e0b; color:white; border:none;
    border-radius:10px; cursor:pointer; margin-top:15px;
}
button:hover { background:#d97706; }

.msg.success {
    background:#dcfce7; padding:10px; border-radius:10px;
    color:#166534; margin-bottom:10px;
}
.msg.error {
    background:#fee2e2; padding:10px; border-radius:10px;
    color:#b91c1c; margin-bottom:10px;
}

</style>
</head>

<body>

<div class="card">
    <h2>📘 สร้างงานมอบหมาย</h2>

    <?php if($message): ?>
        <div class="msg <?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>ชื่องาน</label>
        <input type="text" name="title" required>

        <label>รายละเอียด</label>
        <textarea name="description" rows="4"></textarea>

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

        <button type="submit">บันทึกงานมอบหมาย</button>
    </form>

    <br>
    <a href="dashboard_teacher.php">⬅ กลับแดชบอร์ดครู</a>
</div>

</body>
</html>
