<?php
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// ------------------------------------------------------
// โหลดรายชื่อครู (แบบ prepared)
// ------------------------------------------------------
$teacher_stmt = $conn->prepare("SELECT id, display_name FROM users WHERE role='teacher' ORDER BY display_name");
$teacher_stmt->execute();
$teachers = $teacher_stmt->get_result();

// ------------------------------------------------------
// สร้าง CSRF Token ถ้ายังไม่มี
// ------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ------------------------------------------------------
// เมื่อส่งฟอร์ม
// ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ตรวจสอบ CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("❌ Invalid CSRF Token");
    }

    // รับข้อมูล + sanitize
    $teacher_id = intval($_POST['teacher_id']);
    $day_of_week = $_POST['day_of_week'];
    $subject = trim($_POST['subject']);
    $class_name = trim($_POST['class_name']);
    $time_start = trim($_POST['time_start']);
    $time_end = trim($_POST['time_end']);

    // Validate วัน
    $valid_days = ['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'];
    if (!in_array($day_of_week, $valid_days)) {
        $msg = "❌ วันไม่ถูกต้อง";
        $msg_type = "error";
    }

    // Validate ครู
    elseif ($teacher_id <= 0) {
        $msg = "❌ เลือกครูไม่ถูกต้อง";
        $msg_type = "error";
    }

    // Validate เวลา
    elseif (!preg_match('/^\d{2}:\d{2}$/', $time_start) || !preg_match('/^\d{2}:\d{2}$/', $time_end)) {
        $msg = "❌ เวลาไม่ถูกต้อง";
        $msg_type = "error";
    }
    elseif (strtotime($time_start) >= strtotime($time_end)) {
        $msg = "❌ เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด";
        $msg_type = "error";
    }

    // ถ้าทุกอย่างถูกต้อง → Insert
    else {
        $dev_id = $_SESSION['user_id'];

        $stmt = $conn->prepare("
            INSERT INTO teacher_schedule 
            (teacher_id, day_of_week, subject, class_name, time_start, time_end, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isssssi",
            $teacher_id,
            $day_of_week,
            $subject,
            $class_name,
            $time_start,
            $time_end,
            $dev_id
        );

        if ($stmt->execute()) {
            $msg = "✔ เพิ่มตารางสอนสำเร็จแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "❌ เกิดข้อผิดพลาดในการเพิ่มข้อมูล";
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่มตารางสอนให้ครู</title>
<style>
body { font-family:system-ui;background:#0f172a;color:white;padding:20px;}
.card { max-width:600px;margin:auto;background:#1e293b;padding:20px;border-radius:14px; }
input, select {
    width:100%; padding:10px; margin-bottom:12px; border-radius:8px;
}
button {
    width:100%; padding:12px; background:#22c55e; border:none; color:#0f172a;
    font-weight:bold; border-radius:8px; cursor:pointer;
}
button:hover { background:#4ade80; }
.msg.success { padding:10px;background:#1d4ed8;border-radius:8px;margin-bottom:12px; }
.msg.error { padding:10px;background:#b91c1c;border-radius:8px;margin-bottom:12px; }
a { color:#60a5fa; text-decoration:none; }
</style>
</head>
<body>

<div class="card">
    <h2>➕ เพิ่มตารางสอนให้ครู</h2>

    <?php if ($msg): ?>
        <div class="msg <?= htmlspecialchars($msg_type) ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>เลือกครู</label>
        <select name="teacher_id" required>
            <option value="">-- เลือกครู --</option>
            <?php while($t = $teachers->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($t['id']) ?>">
                    <?= htmlspecialchars($t['display_name']) ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label>วันในสัปดาห์</label>
        <select name="day_of_week" required>
            <?php foreach(['จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์'] as $d): ?>
                <option><?= htmlspecialchars($d) ?></option>
            <?php endforeach; ?>
        </select>

        <label>ชื่อวิชา</label>
        <input type="text" name="subject" required>

        <label>ชั้นเรียน (เช่น ม.1/2)</label>
        <input type="text" name="class_name" required>

        <label>เวลาเริ่ม</label>
        <input type="time" name="time_start" required>

        <label>เวลาสิ้นสุด</label>
        <input type="time" name="time_end" required>

        <button type="submit">บันทึกตารางสอน</button>
    </form>

    <br>
    <a href="dashboard_dev.php">⬅ กลับ Dev Dashboard</a>
</div>

</body>
</html>
