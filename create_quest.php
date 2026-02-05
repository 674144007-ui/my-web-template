<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

$msg = "";
$msg_type = "";

// -------------------------------
// CSRF Token
// -------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// -------------------------------
// เมื่อส่งฟอร์ม
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ตรวจสอบ CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        http_response_code(403);
        exit("❌ Invalid CSRF Token");
    }

    // รับค่า + sanitize พื้นฐาน
    $quest_name  = trim($_POST['quest_name']);
    $quest_detail = trim($_POST['quest_detail']);
    $reward_gp   = intval($_POST['reward_gp']);
    $class_level = trim($_POST['class_level']);
    $deadline    = trim($_POST['deadline']);
    $teacher_id  = $_SESSION['user_id'];

    // -------------------------------
    // Validate
    // -------------------------------

    // 1) ตรวจชื่อเควส
    if ($quest_name === "") {
        $msg = "❌ ชื่อเควสจำเป็นต้องกรอก";
        $msg_type = "error";
    }

    // 2) reward GP ต้องเป็นเลขบวก
    elseif ($reward_gp <= 0) {
        $msg = "❌ คะแนน GP ต้องมากกว่า 0";
        $msg_type = "error";
    }

    // 3) class_level ต้องเป็นค่าที่ระบบกำหนด
    elseif (!in_array($class_level, ['ม1','ม2','ม3','ม4','ม5','ม6'])) {
        $msg = "❌ ระดับชั้นไม่ถูกต้อง";
        $msg_type = "error";
    }

    // 4) วันที่ต้องไม่ย้อนหลัง
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
        $msg = "❌ วันที่ไม่ถูกต้อง";
        $msg_type = "error";
    } elseif (strtotime($deadline) < strtotime(date("Y-m-d"))) {
        $msg = "❌ กำหนดส่งต้องไม่น้อยกว่าวันปัจจุบัน";
        $msg_type = "error";
    }

    // -------------------------------
    // ถ้าทุกอย่างถูกต้อง → บันทึก
    // -------------------------------
    else {
        $stmt = $conn->prepare("
            INSERT INTO quests
            (quest_name, quest_detail, reward_gp, class_level, deadline, created_by)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            "ssissi",
            $quest_name,
            $quest_detail,
            $reward_gp,
            $class_level,
            $deadline,
            $teacher_id
        );

        if ($stmt->execute()) {
            $msg = "✔ สร้างเควสสำเร็จแล้ว!";
            $msg_type = "success";
        } else {
            $msg = "❌ ผิดพลาด: ไม่สามารถบันทึกข้อมูลได้";
            $msg_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>สร้างภารกิจ</title>
<style>
body { font-family:system-ui; background:#fde68a; padding:25px; }
.card {
    background:white; padding:22px; border-radius:18px;
    max-width:600px; margin:0 auto; box-shadow:0 12px 25px rgba(0,0,0,0.15);
}
input, textarea, select {
    width:100%; padding:10px; border-radius:10px;
    border:1px solid #ccc; margin-top:8px;
}
button {
    padding:12px 18px; background:#16a34a; color:white; border:none;
    border-radius:10px; cursor:pointer; margin-top:15px;
}
button:hover { background:#15803d; }

.msg.success {
    background:#dcfce7; padding:10px; border-radius:10px;
    color:#166534; margin-bottom:12px;
}
.msg.error {
    background:#fecaca; padding:10px; border-radius:10px;
    color:#b91c1c; margin-bottom:12px;
}
</style>
</head>
<body>

<div class="card">
    <h2>🎮 สร้างภารกิจ / เควส</h2>

    <?php if($msg): ?>
        <div class="msg <?= htmlspecialchars($msg_type) ?>">
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <label>ชื่อเควส</label>
        <input type="text" name="quest_name" required>

        <label>รายละเอียดเควส</label>
        <textarea name="quest_detail" rows="4"></textarea>

        <label>คะแนนรางวัล (GP)</label>
        <input type="number" name="reward_gp" value="10" min="1" required>

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
        <input type="date" name="deadline" required>

        <button type="submit">บันทึกเควส</button>
    </form>

    <br>
    <a href="dashboard_teacher.php">⬅ กลับแดชบอร์ดครู</a>
</div>

</body>
</html>
