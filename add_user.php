<?php
/**
 * add_user.php - เพิ่มผู้ใช้งานใหม่ (Phase 1: Foolproof Class Selection)
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// สงวนสิทธิ์เฉพาะ Developer และ Admin
requireRole(['developer', 'admin']);

$page_title = "เพิ่มผู้ใช้งานใหม่ (Add User)";
$success_msg = "";
$error_msg = "";

// 1. โหลดรายชื่อห้องเรียนทั้งหมดที่ Active อยู่มาเตรียมไว้สำหรับ Dropdown
$classes_list = [];
$stmt_classes = $conn->query("SELECT id, class_name, level FROM classes WHERE is_active = 1 ORDER BY level ASC, room ASC");
if ($stmt_classes) {
    while ($row = $stmt_classes->fetch_assoc()) {
        $classes_list[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจสอบ CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_msg = "CSRF Token ไม่ถูกต้อง กรุณาลองใหม่";
    } else {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $display_name = trim($_POST['display_name']);
        $role = $_POST['role'];
        $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
        $class_level_text = null;

        if (empty($username) || empty($password) || empty($display_name) || empty($role)) {
            $error_msg = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
        } else {
            // เช็คว่า Username ซ้ำหรือไม่
            $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt_check->bind_param("s", $username);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $error_msg = "ชื่อผู้ใช้งาน (Username) '{$username}' นี้มีในระบบแล้ว กรุณาใช้ชื่ออื่น";
            } else {
                // หากเป็นนักเรียน และมีการเลือกห้องเรียน ให้หาชื่อห้องไปใส่ใน class_level เพื่อรองรับระบบเก่า
                if ($role === 'student' && $class_id) {
                    $stmt_class = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
                    $stmt_class->bind_param("i", $class_id);
                    $stmt_class->execute();
                    $res_class = $stmt_class->get_result()->fetch_assoc();
                    if ($res_class) {
                        $class_level_text = $res_class['class_name'];
                    }
                    $stmt_class->close();
                }

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("INSERT INTO users (username, password, display_name, role, class_id, class_level) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssis", $username, $hashed_password, $display_name, $role, $class_id, $class_level_text);
                
                if ($stmt->execute()) {
                    $success_msg = "เพิ่มผู้ใช้งาน '{$display_name}' สำเร็จเรียบร้อยแล้ว!";
                } else {
                    $error_msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $stmt->error;
                }
                $stmt->close();
            }
            $stmt_check->close();
        }
    }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Sarabun', sans-serif; }
    .form-container { max-width: 800px; margin: 40px auto; background: #ffffff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .form-title { font-size: 1.8rem; font-weight: bold; color: #1e293b; margin-bottom: 10px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; display: flex; justify-content: space-between; align-items: center;}
    
    .btn-back { background: #e2e8f0; color: #334155; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-size: 1rem; font-weight: bold; transition: 0.2s; }
    .btn-back:hover { background: #cbd5e1; }

    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 1rem; }
    .form-control, .form-select { width: 100%; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 1rem; color: #1e293b; background: #f8fafc; box-sizing: border-box; transition: 0.3s; }
    .form-control:focus, .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; background: #ffffff; }
    
    .flex-row { display: flex; gap: 20px; }
    .flex-row > div { flex: 1; }

    /* Class Selection Block */
    .class-selection-block { background: #eff6ff; border: 1px solid #bfdbfe; padding: 20px; border-radius: 10px; margin-top: 10px; display: none; animation: fadeIn 0.4s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    .btn-submit { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 15px; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; width: 100%; margin-top: 20px; box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3); transition: 0.3s; }
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 25px rgba(37, 99, 235, 0.4); }

    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    
    /* Info Box */
    .info-box { background: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 10px; margin-bottom: 20px; color: #92400e; font-size: 0.95rem; }
</style>

<div class="form-container">
    <div class="form-title">
        <span>👤 เพิ่มผู้ใช้งานใหม่ (Manual)</span>
        <a href="user_manager.php" class="btn-back">◀ กลับหน้ารายชื่อ</a>
    </div>

    <div class="info-box">
        💡 <strong>ข้อแนะนำ:</strong> หากต้องการเพิ่มนักเรียนจำนวนมาก (เช่น 100+ คน) แนะนำให้รอใช้ฟีเจอร์ <strong>"นำเข้าจาก Excel (CSV)"</strong> ซึ่งจะรวดเร็วกว่าการมานั่งพิมพ์ทีละคนครับ
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success">✅ <?= $success_msg ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger">❌ <?= $error_msg ?></div>
    <?php endif; ?>

    <form method="POST" action="add_user.php">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <div class="flex-row">
            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้งาน (Username) <span style="color:red">*</span></label>
                <input type="text" name="username" class="form-control" placeholder="เช่น stu001 หรือรหัสนักเรียน" required>
            </div>
            <div class="form-group">
                <label class="form-label">รหัสผ่าน (Password) <span style="color:red">*</span></label>
                <input type="password" name="password" class="form-control" required>
            </div>
        </div>

        <div class="flex-row">
            <div class="form-group">
                <label class="form-label">ชื่อ-นามสกุล (Display Name) <span style="color:red">*</span></label>
                <input type="text" name="display_name" class="form-control" placeholder="เช่น ด.ช. รักเรียน ตั้งใจ" required>
            </div>
            <div class="form-group">
                <label class="form-label">บทบาท (Role) <span style="color:red">*</span></label>
                <select name="role" id="roleSelect" class="form-select" onchange="toggleClassSelection()" required>
                    <option value="">-- เลือกบทบาท --</option>
                    <option value="student">นักเรียน (Student)</option>
                    <option value="teacher">ครูผู้สอน (Teacher)</option>
                    <option value="parent">ผู้ปกครอง (Parent)</option>
                    <option value="developer">ผู้พัฒนา (Developer)</option>
                </select>
            </div>
        </div>

        <div class="class-selection-block" id="classSelectionBlock">
            <h4 style="margin-top:0; color:#1e3a8a;">🏫 สังกัดห้องเรียน (สำหรับนักเรียน)</h4>
            <div class="form-group" style="margin-bottom:0;">
                <label class="form-label">เลือกห้องเรียน <span style="color:red">*</span></label>
                <select name="class_id" id="roomSelect" class="form-select">
                    <option value="">-- กรุณาเลือกห้องเรียน --</option>
                    <?php if (empty($classes_list)): ?>
                        <option value="" disabled>❌ ไม่พบข้อมูลห้องเรียนในระบบ</option>
                    <?php else: ?>
                        <?php 
                        $current_level = "";
                        foreach ($classes_list as $c): 
                            // จัดกลุ่มด้วย optgroup เพื่อความสวยงาม
                            if ($current_level !== $c['level']) {
                                if ($current_level !== "") echo "</optgroup>";
                                $current_level = $c['level'];
                                echo "<optgroup label=\"ระดับชั้น {$current_level}\">";
                            }
                        ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn-submit">💾 บันทึกข้อมูลผู้ใช้งาน</button>
    </form>
</div>

<script>
    // เปิด/ปิด กล่องเลือกห้องเรียนตาม Role
    function toggleClassSelection() {
        const role = document.getElementById('roleSelect').value;
        const block = document.getElementById('classSelectionBlock');
        const roomSelect = document.getElementById('roomSelect');
        
        if (role === 'student') {
            block.style.display = 'block';
            roomSelect.required = true; // บังคับว่านักเรียนต้องเลือกห้อง
        } else {
            block.style.display = 'none';
            roomSelect.required = false;
            roomSelect.value = ''; // เคลียร์ค่าถ้าเปลี่ยนเป็น Role อื่น
        }
    }
</script>

<?php require_once 'footer.php'; ?>