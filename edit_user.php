<?php
// edit_user.php - ระบบแก้ไขข้อมูลผู้ใช้และจัดการความปลอดภัย (Phase 2 - Grouped Dropdown)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['developer']);

$page_title = "แก้ไขข้อมูลผู้ใช้งาน";
$msg = "";
$msg_type = "";
$new_password_show = "";
$csrf = generate_csrf_token();

$target_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($target_id <= 0) die("❌ ไม่พบรหัสผู้ใช้งาน <br><a href='user_manager.php'>กลับไปหน้าจัดการผู้ใช้</a>");

// ป้องกันแก้ Dev ด้วยกัน
if ($target_id !== $_SESSION['user_id']) {
    $check_dev = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $check_dev->bind_param("i", $target_id);
    $check_dev->execute();
    $res_dev = $check_dev->get_result();
    if ($res_dev->num_rows > 0 && $res_dev->fetch_assoc()['role'] === 'developer') {
        die("❌ ไม่อนุญาตให้แก้ไขข้อมูลของ Developer ท่านอื่น <br><a href='user_manager.php'>กลับไปหน้าจัดการผู้ใช้</a>");
    }
    $check_dev->close();
}

// -----------------------------
// จัดการคำสั่ง POST
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $display_name = trim($_POST['display_name'] ?? '');
        $role = $_POST['role'] ?? 'student';
        $class_id = intval($_POST['class_id'] ?? 0);
        $final_class_id = ($class_id > 0) ? $class_id : NULL;

        if ($role === 'parent' || $role === 'developer') $final_class_id = NULL;

        if (empty($display_name)) {
            $msg = "❌ กรุณากรอกชื่อ-นามสกุล";
            $msg_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE users SET display_name = ?, role = ?, class_id = ? WHERE id = ?");
            $stmt->bind_param("ssii", $display_name, $role, $final_class_id, $target_id);
            if ($stmt->execute()) {
                $msg = "✔ อัปเดตข้อมูลทั่วไปสำเร็จ!";
                $msg_type = "success";
                systemLog($_SESSION['user_id'], 'UPDATE_USER', "Updated info for user ID: $target_id");
            } else {
                $msg = "❌ เกิดข้อผิดพลาดในการอัปเดตข้อมูล";
                $msg_type = "error";
            }
            $stmt->close();
        }
    }
    elseif ($action === 'reset_password') {
        $random_digits = rand(1000, 9999);
        $plain_new_password = "bankha" . $random_digits;
        $hashed_password = password_hash($plain_new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $target_id);
        if ($stmt->execute()) {
            $msg = "✔ รีเซ็ตรหัสผ่านเรียบร้อยแล้ว กรุณาคัดลอกรหัสผ่านด้านล่างส่งให้ผู้ใช้งาน";
            $msg_type = "success";
            $new_password_show = $plain_new_password;
            systemLog($_SESSION['user_id'], 'RESET_PASSWORD', "Reset password for user ID: $target_id");
        }
        $stmt->close();
    }
    elseif ($action === 'toggle_status') {
        $new_status = intval($_POST['new_status'] ?? 0);
        $stmt = $conn->prepare("UPDATE users SET is_deleted = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $target_id);
        if ($stmt->execute()) {
            $msg = $new_status === 1 ? "✔ ระงับบัญชีการใช้งานเรียบร้อยแล้ว" : "✔ กู้คืนบัญชีการใช้งานเรียบร้อยแล้ว";
            $msg_type = "success";
            $log_action = $new_status === 1 ? 'SUSPEND_USER' : 'RESTORE_USER';
            systemLog($_SESSION['user_id'], $log_action, "Status changed for user ID: $target_id");
        }
        $stmt->close();
    }
}

// ดึงข้อมูลล่าสุด
$stmt = $conn->prepare("SELECT username, display_name, role, class_id, is_deleted, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $target_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) die("❌ ไม่พบข้อมูลผู้ใช้งาน");
$user_data = $res->fetch_assoc();
$stmt->close();

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

require_once 'header.php';
?>

<style>
    .edit-container { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
    .edit-panel { background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); flex: 1; min-width: 300px; }
    .panel-header { font-size: 1.2rem; font-weight: bold; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; color: #0f172a; }
    .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.9em; font-weight: bold; }
    .status-active { background: #dcfce7; color: #166534; }
    .status-suspended { background: #fee2e2; color: #991b1b; }
    .password-display { background: #fffbeb; border: 2px dashed #f59e0b; padding: 20px; text-align: center; border-radius: 12px; margin-top: 15px; }
    .password-display .pwd { font-size: 2rem; font-weight: bold; color: #b45309; letter-spacing: 2px; user-select: all; }
</style>

<div style="margin-bottom: 20px;">
    <a href="user_manager.php" style="color: #64748b; text-decoration: none; font-weight: bold;">⬅ กลับหน้ารายชื่อผู้ใช้</a>
</div>

<h2>⚙️ จัดการข้อมูลบัญชีผู้ใช้ (Edit User)</h2>
<p style="color: #64748b; margin-top: -10px; margin-bottom: 20px;">กำลังจัดการบัญชี: <strong><?= h($user_data['username']) ?></strong></p>

<?php if ($msg): ?>
    <div class="msg <?= h($msg_type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<div class="edit-container">
    
    <div class="edit-panel" style="flex: 1.5;">
        <div class="panel-header">📝 ข้อมูลทั่วไป (Profile)</div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update_info">

            <label>Username (ไม่สามารถแก้ไขได้)</label>
            <input type="text" value="<?= h($user_data['username']) ?>" disabled style="background: #f1f5f9; color: #94a3b8; cursor: not-allowed; width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 15px; box-sizing: border-box;">

            <label for="display_name">ชื่อ-นามสกุล ที่แสดงบนระบบ</label>
            <input type="text" id="display_name" name="display_name" value="<?= h($user_data['display_name']) ?>" required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 8px; margin-bottom: 15px; box-sizing: border-box;">

            <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label for="role">บทบาท (Role)</label>
                    <select id="role" name="role" required onchange="toggleClassField()" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; outline: none;">
                        <option value="student" <?= $user_data['role'] === 'student' ? 'selected' : '' ?>>👨‍🎓 นักเรียน (Student)</option>
                        <option value="teacher" <?= $user_data['role'] === 'teacher' ? 'selected' : '' ?>>👨‍🏫 ครูผู้สอน (Teacher)</option>
                        <option value="parent" <?= $user_data['role'] === 'parent' ? 'selected' : '' ?>>👨‍👩‍👦 ผู้ปกครอง (Parent)</option>
                        <option value="developer" <?= $user_data['role'] === 'developer' ? 'selected' : '' ?>>💻 นักพัฒนา (Developer)</option>
                    </select>
                </div>

                <div style="flex: 1;" id="class_container">
                    <label for="class_id">ระดับชั้น (ถ้ามี)</label>
                    <select id="class_id" name="class_id" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; outline: none;">
                        <option value="0">-- ไม่ระบุชั้นเรียน --</option>
                        <?php foreach ($grouped_classes as $lvl => $rooms): ?>
                            <optgroup label="📚 ระดับชั้น <?= h($lvl) ?>">
                                <?php foreach ($rooms as $c): ?>
                                    <option value="<?= h($c['id']) ?>" <?= $user_data['class_id'] == $c['id'] ? 'selected' : '' ?>>
                                        <?= h($c['class_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <p style="font-size: 0.9em; color: #64748b;">วันที่ลงทะเบียน: <?= date('d/m/Y H:i', strtotime($user_data['created_at'])) ?></p>

            <button type="submit" class="btn-primary" style="width: 100%; background: #3b82f6;">💾 บันทึกการเปลี่ยนแปลง</button>
        </form>
    </div>

    <div style="flex: 1; display: flex; flex-direction: column; gap: 20px;">
        <div class="edit-panel">
            <div class="panel-header" style="border-bottom-color: #f59e0b; color: #b45309;">🔑 รีเซ็ตรหัสผ่าน</div>
            <?php if ($new_password_show !== ""): ?>
                <div class="password-display">
                    <p style="margin: 0; color: #b45309; font-weight: bold;">รหัสผ่านใหม่คือ</p>
                    <div class="pwd"><?= h($new_password_show) ?></div>
                </div>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('⚠️ ยืนยันการรีเซ็ตรหัสผ่าน?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn-primary" style="width: 100%; background: #f59e0b; color: #fff;">🔄 สุ่มรหัสใหม่</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="edit-panel">
            <div class="panel-header" style="border-bottom-color: #ef4444; color: #b91c1c;">🛑 สถานะบัญชี</div>
            <div style="margin-bottom: 20px; font-size: 1.1rem;">
                สถานะปัจจุบัน: 
                <?= $user_data['is_deleted'] == 0 ? '<span class="status-badge status-active">✅ ใช้งานปกติ</span>' : '<span class="status-badge status-suspended">🚫 ถูกระงับ</span>' ?>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle_status">
                <?php if ($user_data['is_deleted'] == 0): ?>
                    <input type="hidden" name="new_status" value="1">
                    <button type="submit" class="btn-primary" style="width: 100%; background: #ef4444;" onclick="return confirm('ยืนยันการระงับบัญชี?');">🚫 ระงับบัญชี</button>
                <?php else: ?>
                    <input type="hidden" name="new_status" value="0">
                    <button type="submit" class="btn-primary" style="width: 100%; background: #10b981;" onclick="return confirm('ยืนยันการกู้คืนบัญชี?');">🔓 กู้คืนบัญชี</button>
                <?php endif; ?>
            </form>
        </div>
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
            document.getElementById('class_id').value = "0";
        }
    }
    document.addEventListener("DOMContentLoaded", toggleClassField);
</script>

<?php require_once 'footer.php'; ?>