<?php
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

// ------------------------------------------------------
// ตรวจสอบ ID
// ------------------------------------------------------
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("SELECT * FROM teacher_schedule WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    exit("❌ ไม่พบข้อมูลตารางสอนที่ต้องการแก้ไข");
}

$msg = "";
$msg_type = "";

// ------------------------------------------------------
// สร้าง CSRF Token
// ------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ------------------------------------------------------
// เมื่อ submit ฟอร์ม
// ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF Check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("❌ Invalid CSRF token");
    }

    // รับค่า + sanitize
    $day = $_POST['day_of_week'];
    $subject = trim($_POST['subject']);
    $class = trim($_POST['class_name']);
    $start = trim($_POST['time_start']);
    $end = trim($_POST['time_end']);

    // ตรวจวันว่าถูกต้องตามกำหนด
    $valid_days = ['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'];
    if (!in_array($day, $valid_days)) {
        $msg = "❌ วันไม่ถูกต้อง";
        $msg_type = "error";
    }
    // Validate เวลา
    elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
        $msg = "❌ เวลาไม่ถูกต้อง";
        $msg_type = "error";
    }
    // เวลาเริ่มต้อง < เวลาสิ้นสุด
    elseif (strtotime($start) >= strtotime($end)) {
        $msg = "❌ เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด";
        $msg_type = "error";
    }
    else {
        // อัปเดตข้อมูล
        $update = $conn->prepare("
            UPDATE teacher_schedule 
            SET day_of_week=?, subject=?, class_name=?, time_start=?, time_end=?
            WHERE id=?
        ");
        $update->bind_param("sssssi", $day, $subject, $class, $start, $end, $id);

        if ($update->execute()) {
            $msg = "✔ บันทึกการแก้ไขสำเร็จแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "❌ เกิดข้อผิดพลาดในการบันทึก";
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แก้ไขตารางสอน</title>
<style>
body { font-family:system-ui;background:#0f172a;color:white;padding:20px;}
.card { max-width:500px;margin:auto;background:#1e293b;padding:20px;border-radius:14px; }
input, select { width:100%; padding:10px; border-radius:8px; margin-bottom:12px; }
button { width:100%; padding:12px; background:#22c55e;border:none;color:#0f172a;border-radius:8px;font-weight:bold;}
.msg.success { background:#1d4ed8;padding:10px;border-radius:8px;margin-bottom:10px; }
.msg.error { background:#b91c1c;padding:10px;border-radius:8px;margin-bottom:10px; }
a { color:#60a5fa;text-decoration:none; }
</style>
</head>
<body>

<div class="card">

    <h2>แก้ไขตารางสอน</h2>

    <?php if($msg): ?>
        <div class="msg <?= htmlspecialchars($msg_type) ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>วัน</label>
        <select name="day_of_week" required>
            <?php foreach(['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'] as $day): ?>
            <option value="<?= $day ?>" 
                <?= ($day == $data['day_of_week']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($day) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label>วิชา</label>
        <input type="text" name="subject" 
               value="<?= htmlspecialchars($data['subject']) ?>" required>

        <label>ชั้นเรียน</label>
        <input type="text" name="class_name" 
               value="<?= htmlspecialchars($data['class_name']) ?>" required>

        <label>เวลาเริ่ม</label>
        <input type="time" name="time_start" 
               value="<?= htmlspecialchars($data['time_start']) ?>" required>

        <label>เวลาสิ้นสุด</label>
        <input type="time" name="time_end" 
               value="<?= htmlspecialchars($data['time_end']) ?>" required>

        <button type="submit">บันทึก</button>
    </form>

    <br>
    <a href="dev_view_schedule.php">⬅ กลับ</a>

</div>

</body>
</html>
