<?php
/**
 * edit_user.php - แก้ไขข้อมูลผู้ใช้งาน (Phase 3: Dynamic Class Integration)
 */
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

requireRole(['developer', 'developer']);

$page_title = "แก้ไขผู้ใช้งาน (Edit User)";
$success_msg = "";
$error_msg = "";

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: user_manager.php");
    exit;
}

$target_user_id = intval($_GET['id']);

// จัดการการอัปเดตข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_msg = "CSRF Token ไม่ถูกต้อง";
    } else {
        $display_name = trim($_POST['display_name']);
        $role = $_POST['role'];
        $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
        $class_level_text = null;
        
        if (empty($display_name) || empty($role)) {
            $error_msg = "กรุณากรอกข้อมูลที่จำเป็นให้ครบ";
        } else {
            // ดึงชื่อห้องไปเก็บเผื่อระบบเก่า
            if ($role === 'student' && $class_id) {
                $stmt_class = $pdo->prepare("SELECT class_name FROM classes WHERE id = ?");
                $stmt_class->execute([$class_id]);
                $stmt_class->execute();
                $res_class = $stmt_class->fetch();
                if ($res_class) { $class_level_text = $res_class['class_name']; }
                
            } else {
                $class_id = null; // ถ้าไม่ใช่ Role นักเรียน ให้เคลียร์ห้องทิ้ง
            }

            // ถ้าระบุรหัสผ่านใหม่
            if (!empty($_POST['password'])) {
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET display_name=?, role=?, class_id=?, class_level=?, password=? WHERE id=?");
                $stmt->execute([$display_name, $role, $class_id, $class_level_text, $hashed_password, $target_user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET display_name=?, role=?, class_id=?, class_level=? WHERE id=?");
                $stmt->execute([$display_name, $role, $class_id, $class_level_text, $target_user_id]);
            }

            if ($stmt->execute()) {
                $success_msg = "อัปเดตข้อมูลผู้ใช้งานสำเร็จ!";
            } else {
                $error_msg = "เกิดข้อผิดพลาด: " . $stmt->error;
            }
            
        }
    }
}

// ดึงข้อมูลผู้ใช้งานปัจจุบันมาแสดง
$user_data = null;
// Join ตาราง classes เพื่อเอา level ออกมาใช้ pre-select ใน dropdown
$sql = "SELECT u.*, c.level as current_level FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$target_user_id]);
$stmt->execute();
$res = $stmt;
if ($res->rowCount() > 0) {
    $user_data = $res->fetch();
} else {
    echo "ไม่พบผู้ใช้งานนี้"; exit;
}


require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
    /* ใช้ CSS เดียวกับ add_user.php เพื่อความสะอาด */
    body { background-color: #f8fafc; font-family: 'Sarabun', sans-serif; }
    .form-container { max-width: 800px; margin: 40px auto; background: #ffffff; padding: 40px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .form-title { font-size: 1.8rem; font-weight: bold; color: #1e293b; margin-bottom: 10px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; display:flex; justify-content: space-between; align-items:center; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 1rem; }
    .form-control, .form-select { width: 100%; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 10px; font-family: inherit; font-size: 1rem; color: #1e293b; background: #f8fafc; box-sizing: border-box; transition: 0.3s; }
    .form-control:focus, .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; background: #ffffff; }
    .flex-row { display: flex; gap: 20px; } .flex-row > div { flex: 1; }
    .class-selection-block { background: #eff6ff; border: 1px solid #bfdbfe; padding: 20px; border-radius: 10px; margin-top: 10px; display: none; animation: fadeIn 0.4s ease; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    .btn-submit { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; border: none; padding: 15px; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; width: 100%; margin-top: 20px; box-shadow: 0 10px 20px rgba(245, 158, 11, 0.3); transition: 0.3s; }
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 25px rgba(245, 158, 11, 0.4); }
    .btn-back { background: #e2e8f0; color: #334155; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-size: 1rem; }
    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
</style>

<div class="form-container">
    <div class="form-title">
        <span>✏️ แก้ไขข้อมูล (<?= htmlspecialchars($user_data['username']) ?>)</span>
        <a href="user_manager.php" class="btn-back">◀ กลับหน้ารายชื่อ</a>
    </div>

    <?php if ($success_msg): ?> <div class="alert alert-success">✅ <?= $success_msg ?></div> <?php endif; ?>
    <?php if ($error_msg): ?> <div class="alert alert-danger">❌ <?= $error_msg ?></div> <?php endif; ?>

    <form method="POST" action="edit_user.php?id=<?= $target_user_id ?>">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <div class="flex-row">
            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้งาน (แก้ไขไม่ได้)</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user_data['username']) ?>" disabled style="background:#e2e8f0; cursor:not-allowed;">
            </div>
            <div class="form-group">
                <label class="form-label">เปลี่ยนรหัสผ่าน (ปล่อยว่างถ้าไม่ต้องการเปลี่ยน)</label>
                <input type="password" name="password" class="form-control" placeholder="รหัสผ่านใหม่...">
            </div>
        </div>

        <div class="flex-row">
            <div class="form-group">
                <label class="form-label">ชื่อ-นามสกุล (Display Name) <span style="color:red">*</span></label>
                <input type="text" name="display_name" class="form-control" value="<?= htmlspecialchars($user_data['display_name']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">บทบาท (Role) <span style="color:red">*</span></label>
                <select name="role" id="roleSelect" class="form-select" onchange="toggleClassSelection()" required>
                    <option value="student" <?= $user_data['role']==='student'?'selected':'' ?>>นักเรียน (Student)</option>
                    <option value="teacher" <?= $user_data['role']==='teacher'?'selected':'' ?>>ครูผู้สอน (Teacher)</option>
                    <option value="parent" <?= $user_data['role']==='parent'?'selected':'' ?>>ผู้ปกครอง (Parent)</option>
                    <option value="developer" <?= $user_data['role']==='developer'?'selected':'' ?>>ผู้พัฒนา (Developer)</option>
                </select>
            </div>
        </div>

        <div class="class-selection-block" id="classSelectionBlock">
            <h4 style="margin-top:0; color:#1e3a8a;">🏫 สังกัดห้องเรียน</h4>
            <div class="flex-row">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">ระดับชั้น</label>
                    <select id="levelSelect" class="form-select" onchange="loadRooms(null)">
                        <option value="">-- เลือกระดับชั้น --</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">ห้องเรียน</label>
                    <select name="class_id" id="roomSelect" class="form-select" disabled>
                        <option value="">-- กรุณาเลือกระดับชั้นก่อน --</option>
                    </select>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-submit">💾 บันทึกการเปลี่ยนแปลง</button>
    </form>
</div>

<script>
    const savedLevel = "<?= $user_data['current_level'] ?? '' ?>";
    const savedClassId = "<?= $user_data['class_id'] ?? '' ?>";

    document.addEventListener('DOMContentLoaded', () => {
        toggleClassSelection(); // เช็ค Role ตอนโหลดหน้าทันที
    });

    function toggleClassSelection() {
        const role = document.getElementById('roleSelect').value;
        const block = document.getElementById('classSelectionBlock');
        const roomSelect = document.getElementById('roomSelect');
        
        if (role === 'student') {
            block.style.display = 'block';
            loadLevels(savedLevel); // โหลดและพยายาม Pre-select
        } else {
            block.style.display = 'none';
            roomSelect.value = ''; 
        }
    }

    async function loadLevels(preselectLevel = null) {
        const levelSelect = document.getElementById('levelSelect');
        if(levelSelect.options.length > 1) return; // ถ้าโหลดแล้วไม่ต้องซ้ำ

        try {
            const response = await fetch('api_classes.php?action=get_levels');
            const result = await response.json();
            
            if (result.status === 'success') {
                let options = '<option value="">-- เลือกระดับชั้น --</option>';
                result.data.forEach(level => {
                    const isSelected = (level === preselectLevel) ? 'selected' : '';
                    options += `<option value="${level}" ${isSelected}>${level}</option>`;
                });
                levelSelect.innerHTML = options;

                // ถ้ามีการ Pre-select ไว้ ให้วิ่งไปดึงข้อมูลห้องต่อเลย
                if(preselectLevel) {
                    loadRooms(savedClassId);
                }
            }
        } catch (error) {
            console.error('Error loading levels:', error);
        }
    }

    async function loadRooms(preselectRoomId = null) {
        const level = document.getElementById('levelSelect').value;
        const roomSelect = document.getElementById('roomSelect');
        
        if (!level) {
            roomSelect.innerHTML = '<option value="">-- กรุณาเลือกระดับชั้นก่อน --</option>';
            roomSelect.disabled = true;
            return;
        }

        roomSelect.innerHTML = '<option value="">⏳ กำลังโหลด...</option>';
        roomSelect.disabled = true;

        try {
            const response = await fetch(`api_classes.php?action=get_rooms&level=${encodeURIComponent(level)}`);
            const result = await response.json();
            
            if (result.status === 'success') {
                let options = '<option value="">-- เลือกห้องเรียน --</option>';
                result.data.forEach(room => {
                    const isSelected = (parseInt(room.class_id) === parseInt(preselectRoomId)) ? 'selected' : '';
                    options += `<option value="${room.class_id}" ${isSelected}>${room.class_name}</option>`;
                });
                roomSelect.innerHTML = options;
                roomSelect.disabled = false;
            }
        } catch (error) {
            roomSelect.innerHTML = '<option value="">❌ โหลดไม่สำเร็จ</option>';
        }
    }
</script>

<?php require_once 'footer.php'; ?>