<?php
// add_user.php - ฟอร์มเพิ่มผู้ใช้งาน (Phase 2 - Grouped Dropdown)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// อนุญาตเฉพาะ Developer
requireRole(['developer']);

$page_title = "เพิ่มผู้ใช้งานใหม่";
$msg = "";
$msg_type = "";
$csrf = generate_csrf_token();

// -----------------------------
// ดึงข้อมูลชั้นเรียนมาจัดกลุ่ม (Group by Level)
// -----------------------------
$grouped_classes = [];
$res_classes = $conn->query("SELECT id, class_name, level FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $lvl = $row['level'] ? $row['level'] : 'อื่นๆ';
        $grouped_classes[$lvl][] = $row;
    }
}

// -----------------------------
// เมื่อกดปุ่ม "บันทึก"
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $username       = trim($_POST['username'] ?? '');
    $password_plain = $_POST['password'] ?? '';
    $display_name   = trim($_POST['display_name'] ?? '');
    $role           = $_POST['role'] ?? 'student';
    $class_id       = intval($_POST['class_id'] ?? 0);

    // 1. Validation เบื้องต้น
    if (empty($username) || empty($password_plain) || empty($display_name)) {
        $msg = "❌ กรุณากรอกข้อมูล Username, Password และชื่อแสดง ให้ครบถ้วน";
        $msg_type = "error";
    } elseif (strlen($username) < 4) {
        $msg = "❌ Username ต้องมีความยาวอย่างน้อย 4 ตัวอักษร";
        $msg_type = "error";
    } elseif (strlen($password_plain) < 6) {
        $msg = "❌ Password ควรมีความยาวอย่างน้อย 6 ตัวอักษรเพื่อความปลอดภัย";
        $msg_type = "error";
    } else {
        // 2. ตรวจสอบ Username ซ้ำในระบบ
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $msg = "❌ Username นี้มีอยู่ในระบบแล้ว กรุณาใช้ชื่ออื่น";
            $msg_type = "error";
        } else {
            // 3. จัดการค่า NULL สำหรับ class_id (ถ้าเลือก "ไม่ระบุ" จะถูกส่งเป็น 0)
            $final_class_id = ($class_id > 0) ? $class_id : NULL;

            // 4. เข้ารหัสผ่าน
            $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);

            // 5. บันทึกลงฐานข้อมูล
            $stmt = $conn->prepare("
                INSERT INTO users (username, password, display_name, role, class_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssi", $username, $password_hashed, $display_name, $role, $final_class_id);

            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $msg = "✔ เพิ่มผู้ใช้งานใหม่ '{$username}' เข้าระบบสำเร็จ!";
                $msg_type = "success";
                systemLog($_SESSION['user_id'], 'CREATE_USER', "Created new user ID: $new_id, Role: $role");
                
                $_POST = []; // ล้างค่าฟอร์ม
            } else {
                error_log("Add User Error: " . $stmt->error);
                $msg = "❌ เกิดข้อผิดพลาดของฐานข้อมูล ไม่สามารถบันทึกได้";
                $msg_type = "error";
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

require_once 'header.php';
?>

<div class="card" style="max-width: 550px; margin: 0 auto;">
    <h2 style="color: #0f172a; margin-top: 0;">➕ เพิ่มผู้ใช้งานใหม่ (Add User)</h2>
    <p style="color: #64748b; margin-bottom: 25px;">สร้างบัญชีสำหรับนักเรียน ครู หรือผู้ปกครอง</p>

    <?php if ($msg): ?>
        <div class="msg <?= h($msg_type) ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <label for="username">Username (สำหรับล็อกอิน) <span style="color:red">*</span></label>
        <input type="text" id="username" name="username" value="<?= h($_POST['username'] ?? '') ?>" required placeholder="เช่น stu002 หรือ mr.pichaya" autocomplete="new-password">

        <label for="password">Password (รหัสผ่านเริ่มต้น) <span style="color:red">*</span></label>
        <input type="text" id="password" name="password" value="<?= h($_POST['password'] ?? '') ?>" required placeholder="อย่างน้อย 6 ตัวอักษร" autocomplete="new-password">

        <label for="display_name">ชื่อ-นามสกุล ที่แสดงบนระบบ <span style="color:red">*</span></label>
        <input type="text" id="display_name" name="display_name" value="<?= h($_POST['display_name'] ?? '') ?>" required placeholder="เช่น ด.ช.สมชาย เรียนดี">

        <div style="display: flex; gap: 15px; margin-bottom: 15px;">
            <div style="flex: 1;">
                <label for="role">บทบาท (Role) <span style="color:red">*</span></label>
                <select id="role" name="role" required onchange="toggleClassField()" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; outline: none;">
                    <option value="student" <?= (isset($_POST['role']) && $_POST['role'] === 'student') ? 'selected' : '' ?>>👨‍🎓 นักเรียน (Student)</option>
                    <option value="teacher" <?= (isset($_POST['role']) && $_POST['role'] === 'teacher') ? 'selected' : '' ?>>👨‍🏫 ครูผู้สอน (Teacher)</option>
                    <option value="parent" <?= (isset($_POST['role']) && $_POST['role'] === 'parent') ? 'selected' : '' ?>>👨‍👩‍👦 ผู้ปกครอง (Parent)</option>
                    <option value="developer" <?= (isset($_POST['role']) && $_POST['role'] === 'developer') ? 'selected' : '' ?>>💻 นักพัฒนา (Developer)</option>
                </select>
            </div>

            <div style="flex: 1;" id="class_container">
                <label for="class_id">ระดับชั้น (ถ้ามี)</label>
                <select id="class_id" name="class_id" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; outline: none; font-family: inherit;">
                    <option value="0">-- ไม่ระบุชั้นเรียน --</option>
                    <?php foreach ($grouped_classes as $lvl => $rooms): ?>
                        <optgroup label="📚 ระดับชั้น <?= h($lvl) ?>">
                            <?php foreach ($rooms as $c): ?>
                                <option value="<?= h($c['id']) ?>" <?= (isset($_POST['class_id']) && $_POST['class_id'] == $c['id']) ? 'selected' : '' ?>>
                                    <?= h($c['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn-primary" style="width: 100%; font-size: 1.1rem; padding: 15px; background: #10b981; margin-top: 10px;">
            💾 บันทึกข้อมูลเข้าฐานข้อมูล
        </button>
    </form>

    <div style="text-align: center; margin-top: 20px;">
        <a href="user_manager.php" style="color: #475569; text-decoration: none; font-weight: bold;">⬅ กลับหน้ารายชื่อผู้ใช้</a>
    </div>
</div>

<script>
    function toggleClassField() {
        const role = document.getElementById('role').value;
        const classBox = document.getElementById('class_container');
        
        if (role === 'student' || role === 'teacher') {
            classBox.style.opacity = '1';
            classBox.style.pointerEvents = 'auto';
        } else {
            classBox.style.opacity = '0.4';
            classBox.style.pointerEvents = 'none';
            document.getElementById('class_id').value = "0"; // รีเซ็ตเป็นไม่ระบุ
        }
    }
    document.addEventListener("DOMContentLoaded", toggleClassField);
</script>

<?php require_once 'footer.php'; ?>