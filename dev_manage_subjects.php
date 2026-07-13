<?php
// dev_manage_subjects.php - จัดการรายวิชาทั้งหมดในระบบ (Phase 2: LMS Core)
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireRole(['developer', 'developer']);

$page_title = "จัดการรายวิชา (Subjects Management)";
$csrf = generate_csrf_token();
$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Requests (เพิ่ม, แก้ไข, ลบ/ซ่อนวิชา)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ Security Error: CSRF Token ไม่ถูกต้อง");
    }

    $action = $_POST['action'] ?? '';

    // --- เพิ่มรายวิชาใหม่ ---
    if ($action === 'add_subject') {
        $code = trim($_POST['subject_code'] ?? '');
        $name = trim($_POST['subject_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $color = $_POST['color_theme'] ?? '#3b82f6';
        $icon = trim($_POST['icon'] ?? '📚');

        if (empty($code) || empty($name)) {
            $message = "⚠️ กรุณากรอกรหัสวิชาและชื่อวิชาให้ครบถ้วน";
            $msg_type = "error";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, description, color_theme, icon, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                if ($stmt->execute([$code, $name, $desc, $color, $icon])) {
                    $message = "✅ เพิ่มรายวิชา '{$code} {$name}' สำเร็จ";
                    $msg_type = "success";
                }
            } catch (PDOException $e) {
                $message = "❌ ขัดข้อง: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
    // --- แก้ไขรายวิชา ---
    elseif ($action === 'edit_subject') {
        $sub_id = intval($_POST['subject_id']);
        $code = trim($_POST['subject_code'] ?? '');
        $name = trim($_POST['subject_name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $color = $_POST['color_theme'] ?? '#3b82f6';
        $icon = trim($_POST['icon'] ?? '📚');

        if (empty($code) || empty($name) || $sub_id <= 0) {
            $message = "⚠️ ข้อมูลไม่ครบถ้วน";
            $msg_type = "error";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE subjects SET subject_code=?, subject_name=?, description=?, color_theme=?, icon=? WHERE id=?");
                if ($stmt->execute([$code, $name, $desc, $color, $icon, $sub_id])) {
                    $message = "✅ อัปเดตรายวิชา '{$code} {$name}' สำเร็จ";
                    $msg_type = "success";
                }
            } catch (PDOException $e) {
                $message = "❌ ขัดข้อง: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
    // --- เปิด/ปิดสถานะวิชา (Toggle Active) ---
    elseif ($action === 'toggle_active') {
        $sub_id = intval($_POST['subject_id']);
        $new_status = intval($_POST['new_status']);
        try {
            $stmt = $pdo->prepare("UPDATE subjects SET is_active=? WHERE id=?");
            if ($stmt->execute([$new_status, $sub_id])) {
                $status_txt = $new_status == 1 ? "เปิดใช้งาน" : "ปิดใช้งาน/ซ่อน";
                $message = "✅ {$status_txt} รายวิชาเรียบร้อยแล้ว";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $message = "❌ ขัดข้อง: " . $e->getMessage();
            $msg_type = "error";
        }
    }
}

// =========================================================
// 2. ดึงข้อมูลรายวิชามาแสดง
// =========================================================
$subjects = [];
try {
    $stmt = $pdo->query("SELECT * FROM subjects ORDER BY is_active DESC, subject_code ASC");
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "❌ ไม่สามารถดึงข้อมูลรายวิชาได้: " . $e->getMessage();
    $msg_type = "error";
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .dev-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; box-sizing: border-box; }
    
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: bold; color: #1e3a8a; display: flex; align-items: center; gap: 10px; }
    .btn-add { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 12px 25px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 1rem; transition: 0.3s; font-family: inherit; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);}
    .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(16, 185, 129, 0.4); }

    .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; font-weight: bold; }
    .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Subject Cards Grid */
    .subject-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
    .subject-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden; transition: 0.3s; display: flex; flex-direction: column; }
    .subject-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .subject-card.inactive { opacity: 0.6; filter: grayscale(80%); }
    
    .sc-header { padding: 20px; color: white; display: flex; justify-content: space-between; align-items: flex-start; }
    .sc-icon { font-size: 2.5rem; background: rgba(255,255,255,0.2); width: 60px; height: 60px; display: flex; justify-content: center; align-items: center; border-radius: 12px; }
    .sc-code { background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; font-family: monospace; backdrop-filter: blur(4px); border: 1px solid rgba(255,255,255,0.3); }
    
    .sc-body { padding: 20px; flex: 1; }
    .sc-name { margin: 0 0 10px 0; font-size: 1.25rem; font-weight: bold; color: #1e293b; }
    .sc-desc { margin: 0; font-size: 0.9rem; color: #64748b; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    
    .sc-footer { padding: 15px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .status-badge { font-size: 0.8rem; font-weight: bold; padding: 4px 10px; border-radius: 20px; }
    .status-on { background: #dcfce7; color: #166534; } .status-off { background: #f1f5f9; color: #64748b; }
    
    .btn-edit { background: none; border: none; color: #3b82f6; font-size: 1rem; cursor: pointer; padding: 5px; transition: 0.2s; font-weight: bold;}
    .btn-edit:hover { color: #2563eb; transform: scale(1.1); }
    .btn-toggle { background: none; border: none; color: #ef4444; font-size: 1rem; cursor: pointer; padding: 5px; transition: 0.2s; font-weight: bold;}
    .btn-toggle.turn-on { color: #10b981; }
    .btn-toggle:hover { transform: scale(1.1); }

    /* Modals (Mobile Friendly) */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .modal-box { background: white; width: 100%; max-width: 500px; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.3); transform: translateY(50px); opacity: 0; transition: 0.3s; display: flex; flex-direction: column; max-height: 90vh; }
    .modal-box.show { transform: translateY(0); opacity: 1; }
    .modal-header { padding: 20px 25px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 1.3rem; color: #0f172a; }
    .btn-close { background: transparent; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; transition: 0.2s; }
    .btn-close:hover { color: #ef4444; }
    .modal-body { padding: 25px; overflow-y: auto; }
    .modal-footer { padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; }

    .form-group { margin-bottom: 15px; }
    .form-label { display: block; margin-bottom: 5px; font-weight: bold; color: #475569; font-size: 0.95rem; }
    .form-control { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 16px; box-sizing: border-box; outline: none; transition: 0.3s; }
    .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
    textarea.form-control { resize: vertical; min-height: 80px; }

    .btn-save { background: #3b82f6; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.2s; font-family: inherit;}
    .btn-save:hover { background: #2563eb; }
    .btn-cancel { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: inherit;}

    @media (max-width: 768px) {
        .top-bar { flex-direction: column; align-items: stretch; }
        .btn-add { justify-content: center; }
        .modal-box { height: 100vh; max-height: 100vh; border-radius: 0; }
        .modal-footer { flex-direction: column-reverse; }
        .btn-save, .btn-cancel { width: 100%; }
    }
</style>

<div class="dev-container">
    <div class="top-bar">
        <h1 class="page-title">📚 จัดการรายวิชาทั้งหมด (LMS Core)</h1>
        <button class="btn-add" onclick="openModal('addModal')">➕ เพิ่มรายวิชาใหม่</button>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="subject-grid">
        <?php if (count($subjects) > 0): ?>
            <?php foreach ($subjects as $sub): ?>
                <?php 
                    $is_active = $sub['is_active'] == 1;
                    $card_class = $is_active ? '' : 'inactive';
                    $theme = htmlspecialchars($sub['color_theme']);
                ?>
                <div class="subject-card <?= $card_class ?>">
                    <div class="sc-header" style="background: linear-gradient(135deg, <?= $theme ?>, #0f172a);">
                        <div class="sc-icon"><?= htmlspecialchars($sub['icon']) ?></div>
                        <div class="sc-code"><?= htmlspecialchars($sub['subject_code']) ?></div>
                    </div>
                    <div class="sc-body">
                        <h3 class="sc-name"><?= htmlspecialchars($sub['subject_name']) ?></h3>
                        <p class="sc-desc"><?= htmlspecialchars($sub['description'] ?? 'ไม่มีคำอธิบาย') ?></p>
                    </div>
                    <div class="sc-footer">
                        <?php if($is_active): ?>
                            <span class="status-badge status-on">● เปิดใช้งาน</span>
                        <?php else: ?>
                            <span class="status-badge status-off">○ ซ่อนวิชา</span>
                        <?php endif; ?>
                        
                        <div style="display:flex; gap:10px;">
                            <button class="btn-edit" title="แก้ไข" onclick="openEditModal(<?= $sub['id'] ?>, '<?= h($sub['subject_code']) ?>', '<?= h($sub['subject_name']) ?>', '<?= h($sub['description']) ?>', '<?= h($sub['color_theme']) ?>', '<?= h($sub['icon']) ?>')">✏️ แก้ไข</button>
                            
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $is_active ? '0' : '1' ?>">
                                <?php if($is_active): ?>
                                    <button type="submit" class="btn-toggle" title="ระงับการใช้งาน" onclick="return confirm('ซ่อนวิชานี้ จะทำให้นักเรียนมองไม่เห็นในการบ้าน ยืนยันหรือไม่?');">🛑 ซ่อน</button>
                                <?php else: ?>
                                    <button type="submit" class="btn-toggle turn-on" title="เปิดใช้งาน">✅ เปิด</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px; background: white; border-radius: 16px; border: 2px dashed #cbd5e1; color: #64748b;">
                <span style="font-size: 4rem;">📭</span>
                <h2>ยังไม่มีรายวิชาในระบบ</h2>
                <p>กดปุ่ม "เพิ่มรายวิชาใหม่" เพื่อเริ่มต้นสร้างโครงสร้างหลักสูตร</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="addModal">
    <div class="modal-box" id="boxAdd">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_subject">
            <div class="modal-header">
                <h2>➕ เพิ่มรายวิชาใหม่</h2>
                <button type="button" class="btn-close" onclick="closeModal('addModal')">✖</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">รหัสวิชา *</label>
                    <input type="text" name="subject_code" class="form-control" required placeholder="เช่น ว30221">
                </div>
                <div class="form-group">
                    <label class="form-label">ชื่อวิชา *</label>
                    <input type="text" name="subject_name" class="form-control" required placeholder="เช่น เคมีเพิ่มเติม 1">
                </div>
                <div class="form-group">
                    <label class="form-label">คำอธิบายรายวิชา (ย่อ)</label>
                    <textarea name="description" class="form-control" placeholder="เรียนรู้เกี่ยวกับ..."></textarea>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label class="form-label">ไอคอน (Emoji)</label>
                        <input type="text" name="icon" class="form-control" value="📚" placeholder="เช่น 🧪, ⚛️, 📐">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ธีมสี (Hex Color)</label>
                        <input type="color" name="color_theme" class="form-control" value="#3b82f6" style="padding: 5px; height: 48px; cursor: pointer;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">ยกเลิก</button>
                <button type="submit" class="btn-save">💾 บันทึกรายวิชา</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box" id="boxEdit">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="edit_subject">
            <input type="hidden" name="subject_id" id="edit_sub_id">
            <div class="modal-header">
                <h2>✏️ แก้ไขรายวิชา</h2>
                <button type="button" class="btn-close" onclick="closeModal('editModal')">✖</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">รหัสวิชา *</label>
                    <input type="text" name="subject_code" id="edit_code" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">ชื่อวิชา *</label>
                    <input type="text" name="subject_name" id="edit_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">คำอธิบายรายวิชา (ย่อ)</label>
                    <textarea name="description" id="edit_desc" class="form-control"></textarea>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label class="form-label">ไอคอน (Emoji)</label>
                        <input type="text" name="icon" id="edit_icon" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ธีมสี (Hex Color)</label>
                        <input type="color" name="color_theme" id="edit_color" class="form-control" style="padding: 5px; height: 48px; cursor: pointer;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">ยกเลิก</button>
                <button type="submit" class="btn-save">💾 อัปเดตข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(modalId) {
        const m = document.getElementById(modalId);
        const b = m.querySelector('.modal-box');
        m.style.display = 'flex';
        setTimeout(() => b.classList.add('show'), 10);
    }

    function closeModal(modalId) {
        const m = document.getElementById(modalId);
        const b = m.querySelector('.modal-box');
        b.classList.remove('show');
        setTimeout(() => m.style.display = 'none', 300);
    }

    function openEditModal(id, code, name, desc, color, icon) {
        document.getElementById('edit_sub_id').value = id;
        document.getElementById('edit_code').value = code;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_desc').value = desc;
        document.getElementById('edit_color').value = color;
        document.getElementById('edit_icon').value = icon;
        openModal('editModal');
    }
</script>

<?php require_once 'footer.php'; ?>