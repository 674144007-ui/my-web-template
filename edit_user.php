<?php
// edit_user.php - หน้าแก้ไขข้อมูลผู้ใช้สำหรับ Admin/Dev
if (ob_get_level() == 0) ob_start();
session_start();
require_once 'auth.php';
requireRole(['developer', 'admin']);
require_once 'db.php';

$msg = "";
$msg_type = "";
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ตรวจสอบว่ามี User นี้จริงไหม
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("ไม่พบข้อมูลผู้ใช้");
}

// --- บันทึกข้อมูลเมื่อกดปุ่ม ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $display_name = trim($_POST['display_name']);
    $role = $_POST['role'];
    
    // รับค่าตามบทบาท (ถ้าไม่ได้เลือกบทบาทนั้น ให้เป็น NULL)
    $class_level = ($role == 'student') ? $_POST['class_level'] : NULL;
    $teacher_dept = ($role == 'teacher') ? $_POST['teacher_department'] : NULL;
    
    // อัปเดตข้อมูล (ไม่รวมรหัสผ่าน เพราะมีเมนูรีเซ็ตแยกแล้ว)
    $update_sql = "UPDATE users SET display_name = ?, role = ?, class_level = ?, teacher_department = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssssi", $display_name, $role, $class_level, $teacher_dept, $user_id);
    
    if ($update_stmt->execute()) {
        $msg = "✅ บันทึกการแก้ไขเรียบร้อยแล้ว";
        $msg_type = "success";
        
        // อัปเดตข้อมูลในตัวแปร $user ทันที เพื่อให้ฟอร์มแสดงค่าใหม่
        $user['display_name'] = $display_name;
        $user['role'] = $role;
        $user['class_level'] = $class_level;
        $user['teacher_department'] = $teacher_dept;
    } else {
        $msg = "❌ เกิดข้อผิดพลาด: " . $conn->error;
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>Edit User - <?= htmlspecialchars($user['username']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; padding: 20px; }
    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    h2 { text-align: center; color: #1e293b; margin-top: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; }
    
    .form-group { margin-bottom: 15px; }
    label { display: block; margin-bottom: 5px; font-weight: bold; color: #475569; }
    input, select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; box-sizing: border-box; font-family: inherit; font-size: 1rem; }
    input:focus, select:focus { outline: none; border-color: #3b82f6; }
    
    .readonly-field { background: #f1f5f9; color: #64748b; cursor: not-allowed; }
    
    .btn { width: 100%; padding: 12px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-size: 1rem; }
    .btn-save { background: #3b82f6; color: white; }
    .btn-save:hover { background: #2563eb; }
    .btn-back { background: transparent; color: #64748b; border: 1px solid #cbd5e1; margin-top: 10px; display: block; text-align: center; text-decoration: none; padding: 10px 0; }
    .btn-back:hover { background: #f8fafc; color: #1e293b; }

    .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; font-weight: bold; }
    .success { background: #dcfce7; color: #166534; }
    .error { background: #fee2e2; color: #991b1b; }

    /* ซ่อน Input พิเศษไว้ก่อน */
    #student_options, #teacher_options { display: none; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px dashed #cbd5e1; margin-bottom: 15px; }
</style>
</head>
<body>

<div class="container">
    <h2>✏️ แก้ไขข้อมูลผู้ใช้</h2>
    
    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label>Username / รหัสนักเรียน (แก้ไขไม่ได้)</label>
            <input type="text" value="<?= htmlspecialchars($user['username']) ?>" class="readonly-field" readonly>
        </div>
        
        <div class="form-group">
            <label>ชื่อ-นามสกุล (Display Name)</label>
            <input type="text" name="display_name" value="<?= htmlspecialchars($user['display_name']) ?>" required>
        </div>

        <div class="form-group">
            <label>บทบาท (Role)</label>
            <select name="role" id="roleSelect" onchange="toggleFields()" required>
                <option value="student" <?= $user['role']=='student'?'selected':'' ?>>นักเรียน (Student)</option>
                <option value="teacher" <?= $user['role']=='teacher'?'selected':'' ?>>ครู (Teacher)</option>
                <option value="parent" <?= $user['role']=='parent'?'selected':'' ?>>ผู้ปกครอง (Parent)</option>
                <option value="developer" <?= $user['role']=='developer'?'selected':'' ?>>Developer/Admin</option>
            </select>
        </div>

        <div id="student_options">
            <label style="color:#3b82f6;">🏫 ข้อมูลสำหรับนักเรียน</label>
            <div class="form-group">
                <label>ระดับชั้น/ห้อง</label>
                <select name="class_level">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php 
                    for($m=1; $m<=6; $m++) {
                        for($r=1; $r<=5; $r++) {
                            $val = "ม.$m/$r";
                            $sel = ($user['class_level'] == $val) ? 'selected' : '';
                            echo "<option value='$val' $sel>$val</option>";
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
                    <?php
                    $depts = ["วิทยาศาสตร์และเทคโนโลยี", "คณิตศาสตร์", "ภาษาไทย", "ภาษาต่างประเทศ", "สังคมศึกษาฯ", "สุขศึกษาและพลศึกษา", "ศิลปะ", "การงานอาชีพ", "ฝ่ายบริหาร/วิชาการ"];
                    foreach($depts as $d) {
                        $sel = ($user['teacher_department'] == $d) ? 'selected' : '';
                        echo "<option value='$d' $sel>$d</option>";
                    }
                    ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-save">บันทึกการแก้ไข</button>
        <a href="user_manager.php" class="btn-back">⬅ กลับหน้าจัดการผู้ใช้</a>
    </form>
</div>

<script>
function toggleFields() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('student_options').style.display = (role === 'student') ? 'block' : 'none';
    document.getElementById('teacher_options').style.display = (role === 'teacher') ? 'block' : 'none';
}
// เรียกทำงานครั้งแรกเพื่อแสดงค่าเดิม
toggleFields();
</script>

</body>
</html>