<?php
require_once 'auth.php';
requireRole(['teacher', 'developer']);
require_once 'db.php';

$message = "";
$message_type = "";

// สร้าง CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// --- ดึงรายชื่อห้องเรียนที่มีอยู่จริง (Group by class_level) ---
$rooms = [];
$sql_rooms = "SELECT DISTINCT class_level FROM users WHERE role = 'student' AND class_level IS NOT NULL ORDER BY class_level";
$res_rooms = $conn->query($sql_rooms);
while ($row = $res_rooms->fetch_assoc()) {
    $rooms[] = $row['class_level'];
}

// --- ดึงรายชื่อนักเรียนทั้งหมด (เพื่อใช้เลือกรายบุคคล) ---
$students = [];
$sql_students = "SELECT id, display_name, class_level FROM users WHERE role = 'student' ORDER BY class_level, display_name";
$res_students = $conn->query($sql_students);
while ($row = $res_students->fetch_assoc()) {
    $students[$row['class_level']][] = $row; // จัดกลุ่มตามห้อง
}

// --- ตรวจสอบการ Submit Form ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf) {
        die("❌ Invalid CSRF Token");
    }

    $title        = trim($_POST['title']);
    $desc         = trim($_POST['description']);
    $assign_type  = $_POST['assign_type']; // 'class' หรือ 'individual'
    $target_class = $_POST['target_class'] ?? null;
    $target_std   = $_POST['target_student'] ?? null;
    $due_date     = $_POST['due_date'];
    $teacher_id   = $_SESSION['user_id'];

    // Validation
    if (empty($title)) {
        $message = "❌ กรุณาระบุหัวข้องาน";
        $message_type = "error";
    } elseif (empty($due_date)) {
        $message = "❌ กรุณาระบุกำหนดส่ง";
        $message_type = "error";
    } elseif ($assign_type === 'class' && empty($target_class)) {
        $message = "❌ กรุณาเลือกห้องเรียน";
        $message_type = "error";
    } elseif ($assign_type === 'individual' && empty($target_std)) {
        $message = "❌ กรุณาเลือกนักเรียน";
        $message_type = "error";
    } else {
        $conn->begin_transaction();
        try {
            // 1. บันทึกตัวงานลง Library ก่อน (เป็น Master Data)
            // เช็คว่ามีไฟล์แนบไหม (ในที่นี้ทำแบบเบื้องต้น ถ้าไม่มีก็ข้าม)
            $stmt_lib = $conn->prepare("INSERT INTO assignment_library (teacher_id, title, description) VALUES (?, ?, ?)");
            $stmt_lib->bind_param("iss", $teacher_id, $title, $desc);
            $stmt_lib->execute();
            $library_id = $conn->insert_id;
            $stmt_lib->close();

            // 2. สั่งงานไปยังเป้าหมาย (assigned_work)
            $stmt_assign = $conn->prepare("INSERT INTO assigned_work (library_id, teacher_id, class_level, student_id, due_date) VALUES (?, ?, ?, ?, ?)");

            if ($assign_type === 'class') {
                // สั่งทั้งห้อง: student_id เป็น NULL
                $null_std = null;
                $stmt_assign->bind_param("iisss", $library_id, $teacher_id, $target_class, $null_std, $due_date);
                $stmt_assign->execute();
                $msg_detail = "ห้อง " . htmlspecialchars($target_class);
            } else {
                // สั่งรายบุคคล: class_level เป็น NULL
                $null_class = null;
                $stmt_assign->bind_param("iisss", $library_id, $teacher_id, $null_class, $target_std, $due_date);
                $stmt_assign->execute();
                $msg_detail = "นักเรียนรายบุคคล";
            }
            
            $conn->commit();
            $message = "✔ สั่งงาน ($msg_detail) เรียบร้อยแล้ว!";
            $message_type = "success";

        } catch (Exception $e) {
            $conn->rollback();
            $message = "❌ เกิดข้อผิดพลาด: " . $e->getMessage();
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>มอบหมายงาน - Teacher</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f0f2f5; padding: 20px; }
    .container { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    h2 { color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 10px; }
    .form-group { margin-bottom: 15px; }
    label { display: block; margin-bottom: 5px; font-weight: 600; color: #34495e; }
    input[type="text"], input[type="date"], textarea, select {
        width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font-size: 1rem; box-sizing: border-box;
    }
    .radio-group { display: flex; gap: 20px; margin-top: 5px; }
    .radio-group label { font-weight: normal; cursor: pointer; }
    button {
        width: 100%; padding: 12px; background: #27ae60; color: white; border: none; border-radius: 8px; font-size: 1.1rem; cursor: pointer; transition: 0.3s;
    }
    button:hover { background: #219150; }
    .msg { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    .msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .msg.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .hidden { display: none; }
    .back-link { display: block; text-align: center; margin-top: 15px; text-decoration: none; color: #7f8c8d; }
</style>
<script>
function toggleTarget() {
    const type = document.querySelector('input[name="assign_type"]:checked').value;
    const classSelect = document.getElementById('class_selector');
    const stdSelect = document.getElementById('student_selector');
    
    if (type === 'class') {
        classSelect.style.display = 'block';
        stdSelect.style.display = 'none';
        document.getElementById('sel_class').required = true;
        document.getElementById('sel_std').required = false;
    } else {
        classSelect.style.display = 'none';
        stdSelect.style.display = 'block';
        document.getElementById('sel_class').required = false;
        document.getElementById('sel_std').required = true;
    }
}
</script>
</head>
<body>

<div class="container">
    <h2>📝 สร้างงานมอบหมายใหม่</h2>
    
    <?php if($message): ?>
        <div class="msg <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <div class="form-group">
            <label>หัวข้องาน</label>
            <input type="text" name="title" required placeholder="เช่น การบ้านบทที่ 1">
        </div>

        <div class="form-group">
            <label>รายละเอียด</label>
            <textarea name="description" rows="4" placeholder="ระบุรายละเอียดงาน..."></textarea>
        </div>

        <div class="form-group">
            <label>รูปแบบการมอบหมาย</label>
            <div class="radio-group">
                <label>
                    <input type="radio" name="assign_type" value="class" checked onclick="toggleTarget()"> 
                    ทั้งห้องเรียน
                </label>
                <label>
                    <input type="radio" name="assign_type" value="individual" onclick="toggleTarget()"> 
                    รายบุคคล
                </label>
            </div>
        </div>

        <div class="form-group" id="class_selector">
            <label>เลือกห้องเรียน</label>
            <select name="target_class" id="sel_class">
                <option value="">-- เลือกห้องเรียน --</option>
                <?php foreach($rooms as $r): ?>
                    <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group hidden" id="student_selector" style="display:none;">
            <label>เลือกนักเรียน</label>
            <select name="target_student" id="sel_std">
                <option value="">-- เลือกนักเรียน --</option>
                <?php foreach($students as $class => $stdList): ?>
                    <optgroup label="ห้อง <?= htmlspecialchars($class) ?>">
                        <?php foreach($stdList as $s): ?>
                            <option value="<?= $s['id'] ?>">
                                <?= htmlspecialchars($s['display_name']) ?> (<?= htmlspecialchars($s['class_level']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>กำหนดส่ง</label>
            <input type="date" name="due_date" required>
        </div>

        <button type="submit">บันทึกและมอบหมายงาน</button>
    </form>

    <a href="dashboard_teacher.php" class="back-link">⬅ กลับไปหน้าแดชบอร์ด</a>
</div>

</body>
</html>