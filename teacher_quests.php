<?php
// teacher_quests.php - ระบบสร้างภารกิจห้องทดลอง (Lab Quest Creator - Phase 3)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับสิทธิ์ครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$page_title = "ระบบจัดการเควส (Lab Quests)";
$teacher_id = $_SESSION['user_id'];
$csrf = generate_csrf_token();

$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Requests (สร้าง, ลบ, เปิด/ปิด เควส)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Verification Failed.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $bonus_xp = intval($_POST['bonus_xp'] ?? 500);
        $class_id = intval($_POST['target_class_id'] ?? 0);
        
        if (empty($title) || empty($desc)) {
            $message = '❌ กรุณากรอกชื่อภารกิจและรายละเอียดให้ครบถ้วน';
            $msg_type = 'error';
        } else {
            $target = ($class_id === 0) ? NULL : $class_id;
            
            $stmt = $conn->prepare("INSERT INTO lab_quests (teacher_id, title, description, bonus_xp, target_class_id, is_active) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->bind_param("issii", $teacher_id, $title, $desc, $bonus_xp, $target);
            
            if ($stmt->execute()) {
                $message = '✅ สร้างภารกิจใหม่สำเร็จ! นักเรียนจะเห็นเควสนี้ทันที';
                $msg_type = 'success';
                systemLog($teacher_id, 'QUEST_CREATE', "Created quest: $title");
            } else {
                $message = '❌ เกิดข้อผิดพลาดในระบบฐานข้อมูล';
                $msg_type = 'error';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $quest_id = intval($_POST['quest_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM lab_quests WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ii", $quest_id, $teacher_id);
        if ($stmt->execute()) {
            $message = '🗑️ ลบภารกิจออกจากระบบเรียบร้อย';
            $msg_type = 'success';
        }
        $stmt->close();
    } elseif ($action === 'toggle') {
        $quest_id = intval($_POST['quest_id'] ?? 0);
        $new_status = intval($_POST['new_status'] ?? 0);
        $stmt = $conn->prepare("UPDATE lab_quests SET is_active = ? WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("iii", $new_status, $quest_id, $teacher_id);
        if ($stmt->execute()) {
            $message = '🔄 อัปเดตสถานะภารกิจเรียบร้อย';
            $msg_type = 'success';
        }
        $stmt->close();
    }
}

// =========================================================
// 2. ดึงข้อมูลประกอบหน้าจอ (รายชื่อห้องเรียน และ รายการเควส)
// =========================================================

// ดึงรายชื่อห้อง
$classes = [];
$res_classes = $conn->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) { $classes[] = $row; }
}

// ดึงรายการเควสของครูคนนี้
$quests = [];
$stmt_q = $conn->prepare("
    SELECT q.*, c.class_name 
    FROM lab_quests q
    LEFT JOIN classes c ON q.target_class_id = c.id
    WHERE q.teacher_id = ?
    ORDER BY q.created_at DESC
");
$stmt_q->bind_param("i", $teacher_id);
$stmt_q->execute();
$res_q = $stmt_q->get_result();
while ($row = $res_q->fetch_assoc()) {
    $quests[] = $row;
}
$stmt_q->close();

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Secular+One&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS สำหรับ Lab Quest Creator (Phase 3)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .quest-wrapper { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    /* Header & Controls */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #b45309; display: flex; align-items: center; gap: 10px; }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    .control-panel { background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .control-panel p { margin: 0; color: #64748b; }
    .btn-create-quest { background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 15px rgba(234, 88, 12, 0.4); font-family: inherit;}
    .btn-create-quest:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(234, 88, 12, 0.6); }

    /* Alert Message */
    .alert-box { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; animation: fadeIn 0.5s; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    /* Quest Grid (การ์ดภารกิจแบบสไตล์เกม RPG) */
    .quests-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 25px; }
    
    .quest-card { background: white; border-radius: 16px; border: 2px solid #e2e8f0; overflow: hidden; display: flex; flex-direction: column; transition: 0.3s; box-shadow: 0 5px 15px rgba(0,0,0,0.05); position: relative;}
    .quest-card:hover { transform: translateY(-5px); border-color: #fcd34d; box-shadow: 0 15px 30px rgba(245, 158, 11, 0.2); }
    .quest-card.inactive { filter: grayscale(80%); opacity: 0.8; border-color: #cbd5e1; }
    
    .quest-header { background: #0f172a; padding: 20px; color: white; position: relative; }
    .quest-header::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: radial-gradient(circle at top right, rgba(245, 158, 11, 0.3), transparent); pointer-events: none; }
    
    .quest-target-badge { position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.4); }
    .quest-title { margin: 0 0 10px 0; font-size: 1.3rem; font-weight: 700; color: #fcd34d; text-shadow: 0 2px 5px rgba(0,0,0,0.5); z-index: 2; position: relative;}
    
    .xp-badge { display: inline-flex; align-items: center; gap: 5px; background: linear-gradient(90deg, #8b5cf6, #3b82f6); padding: 5px 12px; border-radius: 8px; font-family: 'Secular One', sans-serif; font-size: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.3); border: 1px solid #c4b5fd;}
    
    .quest-body { padding: 20px; flex: 1; color: #475569; font-size: 0.95rem; line-height: 1.6; }
    
    .quest-footer { background: #f8fafc; padding: 15px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .status-text { font-weight: bold; font-size: 0.9rem; }
    .status-on { color: #10b981; } .status-off { color: #94a3b8; }
    
    .action-group { display: flex; gap: 10px; }
    .btn-icon { background: white; border: 1px solid #cbd5e1; width: 35px; height: 35px; border-radius: 8px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;}
    .btn-icon:hover { background: #f1f5f9; transform: scale(1.1); }
    .btn-delete:hover { border-color: #ef4444; color: #ef4444; background: #fee2e2; }

    /* Modal สร้างภารกิจ */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .quest-modal { background: white; width: 100%; max-width: 550px; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5); transform: translateY(20px); opacity: 0; transition: 0.3s; }
    .quest-modal.show { transform: translateY(0); opacity: 1; }
    
    .modal-header { background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);}
    .btn-close { background: transparent; color: white; border: none; font-size: 1.5rem; cursor: pointer; opacity: 0.8; transition: 0.2s; }
    .btn-close:hover { opacity: 1; transform: scale(1.2); }

    .modal-body { padding: 25px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; color: #1e293b; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 1rem; box-sizing: border-box; transition: 0.3s; }
    .form-control:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1); }
    textarea.form-control { resize: vertical; height: 100px; }

    .xp-input-wrapper { display: flex; align-items: center; gap: 10px; }
    .xp-input-wrapper input { flex: 1; }
    .xp-label { background: #8b5cf6; color: white; padding: 12px 15px; border-radius: 8px; font-weight: bold; font-family: 'Secular One'; }

    .modal-footer { padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 15px; }
    .btn-cancel { background: white; color: #475569; border: 1px solid #cbd5e1; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: inherit; }
    .btn-cancel:hover { background: #f1f5f9; }
    .btn-save { background: #10b981; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: inherit; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); transition: 0.2s; }
    .btn-save:hover { background: #059669; transform: translateY(-2px); }

    .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; background: white; border-radius: 16px; border: 2px dashed #cbd5e1; grid-column: 1 / -1;}
    .empty-state span { font-size: 4rem; display: block; margin-bottom: 15px; }
</style>

<div class="quest-wrapper">
    
    <div class="page-header">
        <h1 class="page-title">⚔️ ระบบสร้างภารกิจห้องทดลอง (Quest Board)</h1>
        <a href="dashboard_teacher.php" class="btn-back">⬅ กลับหน้าหลัก</a>
    </div>

    <?php if ($message): ?>
        <div class="alert-box alert-<?= $msg_type ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="control-panel">
        <p>สร้างโจทย์หรือภารกิจเพื่อกระตุ้นให้นักเรียนเข้าไปทดลองใน Virtual Lab</p>
        <button class="btn-create-quest" onclick="openQuestModal()">
            <span>➕</span> สร้างภารกิจใหม่
        </button>
    </div>

    <div class="quests-grid">
        <?php if (count($quests) > 0): ?>
            <?php foreach ($quests as $q): ?>
                <?php 
                    $is_active = $q['is_active'] == 1;
                    $card_class = $is_active ? '' : 'inactive';
                    $target_name = $q['target_class_id'] ? "ห้อง " . htmlspecialchars($q['class_name']) : "ทุกชั้นเรียน";
                ?>
                <div class="quest-card <?= $card_class ?>">
                    <div class="quest-header">
                        <div class="quest-target-badge">🎯 <?= $target_name ?></div>
                        <h3 class="quest-title"><?= htmlspecialchars($q['title']) ?></h3>
                        <div class="xp-badge">⚡ +<?= number_format($q['bonus_xp']) ?> XP</div>
                    </div>
                    <div class="quest-body">
                        <?= nl2br(htmlspecialchars($q['description'])) ?>
                    </div>
                    <div class="quest-footer">
                        <div class="status-text <?= $is_active ? 'status-on' : 'status-off' ?>">
                            <?= $is_active ? '🟢 กำลังเผยแพร่' : '⚪ ปิดใช้งาน' ?>
                        </div>
                        <div class="action-group">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="quest_id" value="<?= $q['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $is_active ? 0 : 1 ?>">
                                <button type="submit" class="btn-icon" title="<?= $is_active ? 'ระงับชั่วคราว' : 'เปิดใช้งานอีกครั้ง' ?>">
                                    <?= $is_active ? '⏸️' : '▶️' ?>
                                </button>
                            </form>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('ยืนยันการลบภารกิจนี้? (ไม่สามารถกู้คืนได้)');">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="quest_id" value="<?= $q['id'] ?>">
                                <button type="submit" class="btn-icon btn-delete" title="ลบภารกิจ">🗑️</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <span>📜</span>
                <h2 style="margin: 0; color: #1e293b;">ยังไม่มีภารกิจ</h2>
                <p>กดปุ่ม "สร้างภารกิจใหม่" เพื่อมอบหมายโจทย์การทดลองให้นักเรียน</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal-overlay" id="questModalOverlay">
    <div class="quest-modal" id="questModalBox">
        <form method="POST" action="teacher_quests.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create">

            <div class="modal-header">
                <h2>⚔️ สร้างภารกิจใหม่</h2>
                <button type="button" class="btn-close" onclick="closeQuestModal()">✖</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="target_class_id">🎯 เป้าหมาย (มอบหมายให้ใคร?)</label>
                    <select name="target_class_id" id="target_class_id" class="form-control">
                        <option value="0">🌍 ประกาศให้นักเรียนทุกคนเห็น (ทุกชั้นเรียน)</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>">เฉพาะห้อง: <?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="title">📜 ชื่อภารกิจ (Quest Title)</label>
                    <input type="text" name="title" id="title" class="form-control" placeholder="เช่น: สกัดก๊าซไฮโดรเจนห้ามระเบิด!" required>
                </div>

                <div class="form-group">
                    <label for="description">📝 รายละเอียดและเงื่อนไข (Objective)</label>
                    <textarea name="description" id="description" class="form-control" placeholder="อธิบายโจทย์ให้นักเรียนเข้าใจ เช่น ต้องผสมสารอะไรเข้าด้วยกัน และต้องรักษา HP ไม่ให้ต่ำกว่า 50%" required></textarea>
                </div>

                <div class="form-group">
                    <label for="bonus_xp">⚡ รางวัลโบนัส (XP Reward)</label>
                    <div class="xp-input-wrapper">
                        <input type="number" name="bonus_xp" id="bonus_xp" class="form-control" value="500" min="100" max="5000" step="100" required>
                        <div class="xp-label">XP</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeQuestModal()">ยกเลิก</button>
                <button type="submit" class="btn-save">✅ เผยแพร่ภารกิจ</button>
            </div>
        </form>
    </div>
</div>

<script>
    const overlay = document.getElementById('questModalOverlay');
    const modalBox = document.getElementById('questModalBox');

    function openQuestModal() {
        overlay.style.display = 'flex';
        setTimeout(() => { modalBox.classList.add('show'); }, 10);
    }

    function closeQuestModal() {
        modalBox.classList.remove('show');
        setTimeout(() => { overlay.style.display = 'none'; }, 300);
    }

    // ปิด Modal เมื่อคลิกพื้นที่ว่างข้างนอก
    overlay.addEventListener('click', function(e) {
        if(e.target === overlay) closeQuestModal();
    });
</script>

<?php require_once 'footer.php'; ?>