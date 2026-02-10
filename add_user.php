<?php
// add_user.php - เพิ่มผู้ใช้ใหม่แบบละเอียด (แยกห้อง/หมวดวิชา)
session_start();
require_once 'auth.php';
requireRole(['developer', 'admin']);
require_once 'db.php';

$msg = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $display_name = trim($_POST['display_name']);
    $role = $_POST['role'];
    
    // รับค่าข้อมูลเสริมตาม Role
    $class_level = ($role == 'student') ? $_POST['class_level'] : null;
    $department = ($role == 'teacher') ? $_POST['teacher_department'] : null;

    if (empty($username) || empty($password) || empty($display_name)) {
        $msg = "กรุณากรอกข้อมูลที่จำเป็นให้ครบ";
        $msg_type = "error";
    } else {
        // เช็คว่า Username ซ้ำไหม
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $msg = "Username นี้มีผู้ใช้แล้ว";
            $msg_type = "error";
        } else {
            // Hash Password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert ลง Database
            $sql = "INSERT INTO users (username, password, display_name, role, class_level, teacher_department) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssss", $username, $hashed_password, $display_name, $role, $class_level, $department);
            
            if ($stmt->execute()) {
                $msg = "✅ เพิ่มผู้ใช้เรียบร้อยแล้ว";
                $msg_type = "success";
            } else {
                $msg = "เกิดข้อผิดพลาด: " . $stmt->error;
                $msg_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Add New User</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; padding: 20px; }
    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    h2 { text-align: center; color: #1e293b; margin-top: 0; }
    
    .form-group { margin-bottom: 15px; }
    label { display: block; margin-bottom: 5px; font-weight: bold; color: #475569; }
    input, select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-family: inherit; }
    
    .btn { width: 100%; padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 1rem; }
    .btn:hover { background: #2563eb; }
    .btn-back { background: transparent; color: #64748b; border: 1px solid #cbd5e1; margin-top: 10px; }
    .btn-back:hover { background: #f1f5f9; color: #1e293b; }

    .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; font-weight: bold; }
    .success { background: #dcfce7; color: #166534; }
    .error { background: #fee2e2; color: #991b1b; }

    /* ซ่อน Input พิเศษไว้ก่อน */
    #student_options, #teacher_options { display: none; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed #cbd5e1; margin-bottom: 15px; }
</style>
</head>
<body>

<div class="container">
    <h2>เพิ่มผู้ใช้ใหม่</h2>
    
    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label>Username (รหัสนักเรียน/ชื่อผู้ใช้)</label>
            <input type="text" name="username" required placeholder="เช่น 66001, teacher01">
        </div>
        
        <div class="form-group">
            <label>Password</label>
            <input type="text" name="password" required placeholder="รหัสผ่านเริ่มต้น">
        </div>

        <div class="form-group">
            <label>ชื่อ-นามสกุล (Display Name)</label>
            <input type="text" name="display_name" required placeholder="เช่น ด.ช.รักเรียน ขยันมาก">
        </div>

        <div class="form-group">
            <label>บทบาท (Role)</label>
            <select name="role" id="roleSelect" onchange="toggleFields()" required>
                <option value="student">นักเรียน (Student)</option>
                <option value="teacher">ครู (Teacher)</option>
                <option value="parent">ผู้ปกครอง (Parent)</option>
                <option value="developer">Developer/Admin</option>
            </select>
        </div>

        <div id="student_options">
            <label style="color:#3b82f6;">🏫 ข้อมูลสำหรับนักเรียน</label>
            <div class="form-group">
                <label>ระดับชั้น/ห้อง</label>
                <select name="class_level">
                    <option value="">-- เลือกห้องเรียน --</option>
                    <?php 
                    // สร้างตัวเลือก ม.1 - ม.6 / ห้อง 1-5
                    for($m=1; $m<=6; $m++) {
                        for($r=1; $r<=5; $r++) {
                            echo "<option value='ม.$m/$r'>ม.$m/$r</option>";
                        }
                    }
                    ?>
                </select>
            </div>
        </div>

        <div id="teacher_options">
            <label style="color:#ef4444;">👩‍🏫 ข้อมูลสำหรับครู</label>
            <div class="form-group">
                <label>กลุ่มสาระการเรียนรู้</label>
                <select name="teacher_department">
                    <option value="">-- เลือกกลุ่มสาระ --</option>
                    <option value="วิทยาศาสตร์และเทคโนโลยี">วิทยาศาสตร์และเทคโนโลยี</option>
                    <option value="คณิตศาสตร์">คณิตศาสตร์</option>
                    <option value="ภาษาไทย">ภาษาไทย</option>
                    <option value="ภาษาต่างประเทศ">ภาษาต่างประเทศ</option>
                    <option value="สังคมศึกษา ศาสนา และวัฒนธรรม">สังคมศึกษาฯ</option>
                    <option value="สุขศึกษาและพลศึกษา">สุขศึกษาและพลศึกษา</option>
                    <option value="ศิลปะ">ศิลปะ</option>
                    <option value="การงานอาชีพ">การงานอาชีพ</option>
                    <option value="ฝ่ายบริหาร/วิชาการ">ฝ่ายบริหาร/วิชาการ</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn">บันทึกข้อมูล</button>
        <a href="user_manager.php"><button type="button" class="btn btn-back">ยกเลิก / กลับ</button></a>
    </form>
</div>

<script>
function toggleFields() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('student_options').style.display = (role === 'student') ? 'block' : 'none';
    document.getElementById('teacher_options').style.display = (role === 'teacher') ? 'block' : 'none';
}
// เรียกทำงานครั้งแรกเผื่อ browser จำค่าเดิม
toggleFields();
</script>

</body>
</html>