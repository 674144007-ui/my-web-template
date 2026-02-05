<?php
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username       = trim($_POST['username']);
    $password_plain = trim($_POST['password']);
    $display_name   = trim($_POST['display_name']);
    $role           = $_POST['role'];

    // ตัวเลือกเฉพาะครู
    $subject_group       = ($role === 'teacher') ? trim($_POST['subject_group']) : NULL;
    $teacher_department  = ($role === 'teacher') ? trim($_POST['teacher_department']) : NULL;

    // hash รหัสผ่าน
    $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users(username, password, display_name, role, subject_group, teacher_department)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->bind_param("ssssss",
        $username,
        $password_hashed,
        $display_name,
        $role,
        $subject_group,
        $teacher_department
    );

    if ($stmt->execute()) {
        $msg = "✔ เพิ่มผู้ใช้สำเร็จ!";
    } else {
        $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เพิ่มผู้ใช้ใหม่</title>
<style>
body {
    font-family: system-ui;
    background: #0A0F24;
    color: white;
    padding: 20px;
}
.card {
    background: rgba(30,41,59,0.6);
    padding: 26px;
    border-radius: 16px;
    max-width: 520px;
    margin: auto;
    box-shadow: 0 15px 30px rgba(0,0,0,0.35);
    backdrop-filter: blur(12px);
}
input, select {
    width: 100%;
    padding: 10px;
    border-radius: 10px;
    margin-bottom: 12px;
    border: 1px solid #475569;
    background: rgba(255,255,255,0.1);
    color: #00bddaff;
}
button {
    width:100%;
    padding:12px;
    background:#22c55e;
    color:#0f172a;
    border:none;
    border-radius:10px;
    font-weight:bold;
    cursor:pointer;
}
button:hover { background:#4ade80; }
.msg {
    padding:10px;
    background:#1e3a8a;
    border-radius:10px;
    margin-bottom:12px;
}
a { color:#60a5fa; text-decoration:none; }
</style>

<script>
function toggleTeacherFields() {
    let role = document.getElementById("role").value;
    let teacherBox = document.getElementById("teacher_fields");
    teacherBox.style.display = (role === "teacher") ? "block" : "none";
}
</script>

</head>
<body>

<div class="card">
    <h2>➕ เพิ่มผู้ใช้ใหม่</h2>

    <?php if($msg): ?><div class="msg"><?= $msg ?></div><?php endif; ?>

    <form method="post">
        <label>Username</label>
        <input type="text" name="username" required>

        <label>Password</label>
        <input type="text" name="password" required>

        <label>ชื่อแสดงบนระบบ</label>
        <input type="text" name="display_name" required>

        <label>บทบาท</label>
        <select name="role" id="role" onchange="toggleTeacherFields()" required>
            <option value="teacher">ครู</option>
            <option value="student">นักเรียน</option>
            <option value="parent">ผู้ปกครอง</option>
            <option value="developer">Developer</option>
        </select>

        <!-- ส่วนที่เพิ่มขึ้นมาเฉพาะครู -->
        <div id="teacher_fields" style="display:none; margin-top:10px;">

            <label>กลุ่มสาระ</label>
            <select name="subject_group">
                <option value="">-- เลือกกลุ่มสาระ --</option>
                <option value="คณิตศาสตร์">คณิตศาสตร์</option>
                <option value="วิทยาศาสตร์">วิทยาศาสตร์</option>
                <option value="ภาษาไทย">ภาษาไทย</option>
                <option value="ภาษาอังกฤษ">ภาษาอังกฤษ</option>
                <option value="สังคมศึกษา">สังคมศึกษา</option>
                <option value="ดนตรี-นาฏศิลป์">ดนตรี-นาฏศิลป์</option>
                <option value="ศิลปะ">ศิลปะ</option>
                <option value="พลศึกษา">พลศึกษา</option>
                <option value="คอมพิวเตอร์">คอมพิวเตอร์</option>
                <option value="การงานอาชีพ">การงานอาชีพ</option>
            </select>

            <label>ฝ่าย / ตำแหน่ง</label>
            <select name="teacher_department">
                <option value="">-- เลือกฝ่าย --</option>
                <option value="ฝ่ายวิชาการ">ฝ่ายวิชาการ</option>
                <option value="ฝ่ายกิจการนักเรียน">ฝ่ายกิจการนักเรียน</option>
                <option value="ฝ่ายธุรการ">ฝ่ายธุรการ</option>
                <option value="ฝ่ายบริหารทั่วไป">ฝ่ายบริหารทั่วไป</option>
                <option value="ฝ่ายเทคโนโลยีสารสนเทศ">ฝ่ายเทคโนโลยีสารสนเทศ</option>
            </select>

        </div>

        <button type="submit">บันทึกผู้ใช้</button>
    </form>

    <br>
    <a href="user_manager.php">⬅ กลับหน้าจัดการผู้ใช้</a>
</div>

<script>
    toggleTeacherFields();
</script>

</body>
</html>
