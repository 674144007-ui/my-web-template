<?php
// teacher_announcements.php - ระบบประกาศหน้าเสาธงออนไลน์ (Classroom Broadcast - Phase 5)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับสิทธิ์ครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$page_title = "ระบบประกาศหน้าเสาธงออนไลน์";
$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['display_name'] ?? 'คุณครู';
$csrf = generate_csrf_token();

$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Requests (สร้างประกาศ, ลบประกาศ)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Verification Failed.");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $msg_content = trim($_POST['message'] ?? '');
        $class_id = intval($_POST['target_class_id'] ?? 0);
        
        if (empty($title) || empty($msg_content)) {
            $message = '❌ กรุณากรอกหัวข้อและเนื้อหาประกาศให้ครบถ้วน';
            $msg_type = 'error';
        } else {
            $target = ($class_id === 0) ? NULL : $class_id;
            
            // บันทึกประกาศลงฐานข้อมูล
            $stmt = $conn->prepare("INSERT INTO announcements (teacher_id, title, message, author_name, target_class_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isssi", $teacher_id, $title, $msg_content, $teacher_name, $target);
            
            if ($stmt->execute()) {
                $message = '✅ เผยแพร่ประกาศสำเร็จ! ข้อความจะไปปรากฏบนหน้าจอของนักเรียนทันที';
                $msg_type = 'success';
                systemLog($teacher_id, 'BROADCAST_CREATE', "Broadcasted announcement: $title");
            } else {
                $message = '❌ เกิดข้อผิดพลาดในการบันทึกข้อมูล';
                $msg_type = 'error';
            }
            $stmt->close();
        }
    } 
    elseif ($action === 'delete') {
        $ann_id = intval($_POST['ann_id'] ?? 0);
        
        // ตรวจสอบว่าเป็นประกาศของครูคนนี้จริงๆ ถึงจะให้ลบได้ (หรือถ้าเป็น Developer ก็ลบได้)
        $can_delete = false;
        if ($_SESSION['role'] === 'developer') {
            $can_delete = true;
        } else {
            $check = $conn->prepare("SELECT id FROM announcements WHERE id = ? AND teacher_id = ?");
            $check->bind_param("ii", $ann_id, $teacher_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) $can_delete = true;
            $check->close();
        }

        if ($can_delete) {
            $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->bind_param("i", $ann_id);
            if ($stmt->execute()) {
                $message = '🗑️ ลบประกาศออกจากระบบเรียบร้อยแล้ว';
                $msg_type = 'success';
            }
            $stmt->close();
        } else {
            $message = '❌ คุณไม่มีสิทธิ์ลบประกาศของผู้อื่น';
            $msg_type = 'error';
        }
    }
}

// =========================================================
// 2. ดึงข้อมูลรายชื่อห้อง และ ประกาศทั้งหมด
// =========================================================

// ดึงรายชื่อห้องทั้งหมดมาใส่ Dropdown
$classes = [];
$res_classes = $conn->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) { $classes[] = $row; }
}

// ดึงประกาศทั้งหมด (เรียงจากใหม่ไปเก่า)
$announcements = [];
$stmt_ann = $conn->prepare("
    SELECT a.*, c.class_name 
    FROM announcements a
    LEFT JOIN classes c ON a.target_class_id = c.id
    ORDER BY a.created_at DESC
");
$stmt_ann->execute();
$res_ann = $stmt_ann->get_result();
while ($row = $res_ann->fetch_assoc()) {
    $announcements[] = $row;
}
$stmt_ann->close();

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS สำหรับ Classroom Broadcast (Phase 5)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .broadcast-wrapper { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

    /* --- Page Header --- */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #4338ca; display: flex; align-items: center; gap: 10px; }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Alert Message */
    .alert-box { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; font-weight: bold; animation: fadeIn 0.5s; display: flex; align-items: center; justify-content: space-between;}
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    /* --- Main Layout Grid --- */
    .broadcast-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start; }

    /* 1. Form Panel (ซ้าย) */
    .form-panel { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; position: sticky; top: 30px; }
    .form-panel h3 { margin: 0 0 20px 0; color: #4338ca; border-bottom: 2px solid #e0e7ff; padding-bottom: 10px; display: flex; align-items: center; gap: 8px; }
    
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 0.95rem; }
    .form-control { width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 1rem; box-sizing: border-box; transition: 0.3s; background: #f8fafc; }
    .form-control:focus { border-color: #4338ca; background: white; box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.1); }
    textarea.form-control { resize: vertical; min-height: 150px; line-height: 1.6; }
    
    .btn-submit { background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); color: white; border: none; padding: 15px; border-radius: 8px; font-weight: bold; font-size: 1.1rem; cursor: pointer; width: 100%; font-family: inherit; transition: 0.3s; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4); display: flex; align-items: center; justify-content: center; gap: 8px;}
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(79, 70, 229, 0.6); }

    /* 2. Feed Panel (ขวา) */
    .feed-panel { display: flex; flex-direction: column; gap: 20px; }
    .feed-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 10px; }
    .feed-header h3 { margin: 0; color: #1e293b; font-size: 1.3rem; }
    .feed-count { background: #e0e7ff; color: #4338ca; padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; }

    /* Announcement Card */
    .ann-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: 0.3s; position: relative; overflow: hidden; }
    .ann-card:hover { border-color: #c7d2fe; box-shadow: 0 10px 30px rgba(67, 56, 202, 0.1); transform: translateY(-2px); }
    
    /* แถบสีด้านซ้ายบอกเป้าหมาย */
    .ann-card.target-all { border-left: 5px solid #10b981; }
    .ann-card.target-specific { border-left: 5px solid #f59e0b; }

    .ann-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .ann-title { margin: 0; font-size: 1.3rem; color: #0f172a; font-weight: 700; line-height: 1.4; }
    .ann-target { font-size: 0.85rem; font-weight: bold; padding: 5px 12px; border-radius: 20px; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
    .target-all .ann-target { background: #dcfce7; color: #166534; }
    .target-specific .ann-target { background: #fef3c7; color: #b45309; }

    .ann-body { color: #475569; font-size: 1rem; line-height: 1.6; margin-bottom: 20px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #f1f5f9; white-space: pre-wrap; }
    
    .ann-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #cbd5e1; padding-top: 15px; color: #64748b; font-size: 0.9rem; }
    .ann-author { display: flex; align-items: center; gap: 8px; }
    .author-icon { width: 30px; height: 30px; background: #e0e7ff; color: #4338ca; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.9rem; }
    
    .btn-delete { background: transparent; color: #ef4444; border: 1px solid #ef4444; padding: 6px 15px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: bold; transition: 0.2s; font-family: inherit;}
    .btn-delete:hover { background: #fee2e2; transform: scale(1.05); }

    .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; background: white; border-radius: 16px; border: 2px dashed #cbd5e1; }
    .empty-state span { font-size: 4rem; display: block; margin-bottom: 15px; }

    /* Responsive */
    @media (max-width: 992px) {
        .broadcast-grid { grid-template-columns: 1fr; }
        .form-panel { position: relative; top: 0; }
    }
</style>

<div class="broadcast-wrapper">
    
    <div class="page-header">
        <h1 class="page-title">📢 ระบบประกาศหน้าเสาธงออนไลน์ (Classroom Broadcast)</h1>
        <a href="dashboard_teacher.php" class="btn-back">⬅ กลับหน้าหลัก</a>
    </div>

    <?php if ($message): ?>
        <div class="alert-box alert-<?= $msg_type ?>" id="alertBox">
            <span><?= $message ?></span>
            <span style="cursor:pointer;" onclick="document.getElementById('alertBox').style.display='none'">✖</span>
        </div>
    <?php endif; ?>

    <div class="broadcast-grid">
        
        <div class="form-panel">
            <h3>✍️ เขียนประกาศใหม่</h3>
            <form method="POST" action="teacher_announcements.php" onsubmit="return confirmSubmit()">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-group">
                    <label for="title">📌 หัวข้อประกาศ</label>
                    <input type="text" name="title" id="title" class="form-control" placeholder="เช่น: นัดสอบเก็บคะแนนบทที่ 1" required>
                </div>

                <div class="form-group">
                    <label for="message">📝 เนื้อหาประกาศ</label>
                    <textarea name="message" id="message" class="form-control" placeholder="พิมพ์รายละเอียดที่ต้องการแจ้งให้นักเรียนทราบที่นี่..." required></textarea>
                </div>

                <div class="form-group">
                    <label for="target_class_id">🎯 เป้าหมาย (ประกาศให้ใครเห็น?)</label>
                    <select name="target_class_id" id="target_class_id" class="form-control">
                        <option value="0">🌍 นักเรียนทุกคน (ทุกชั้นเรียน)</option>
                        <optgroup label="เจาะจงเฉพาะห้องเรียน">
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>">ห้อง: <?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <button type="submit" class="btn-submit">
                    🚀 เผยแพร่ประกาศ (Broadcast)
                </button>
            </form>
        </div>

        <div class="feed-panel">
            <div class="feed-header">
                <h3>ประวัติการประกาศข่าวสาร (Feed)</h3>
                <div class="feed-count">ทั้งหมด <?= count($announcements) ?> รายการ</div>
            </div>

            <?php if (count($announcements) > 0): ?>
                <?php foreach ($announcements as $ann): ?>
                    <?php 
                        $is_global = empty($ann['target_class_id']);
                        $card_type = $is_global ? 'target-all' : 'target-specific';
                        $target_label = $is_global ? '🌍 ทุกชั้นเรียน' : '🏫 ห้อง ' . htmlspecialchars($ann['class_name']);
                        
                        // เช็คสิทธิ์ว่าลบได้ไหม (ของตัวเอง หรือเป็น Dev)
                        $can_delete = ($_SESSION['role'] === 'developer' || $ann['teacher_id'] == $teacher_id);
                    ?>
                    <div class="ann-card <?= $card_type ?>">
                        <div class="ann-top">
                            <h4 class="ann-title"><?= htmlspecialchars($ann['title']) ?></h4>
                            <div class="ann-target"><?= $target_label ?></div>
                        </div>
                        
                        <div class="ann-body"><?= htmlspecialchars($ann['message']) ?></div>
                        
                        <div class="ann-footer">
                            <div class="ann-author">
                                <div class="author-icon"><?= mb_substr($ann['author_name'], 0, 1, 'UTF-8') ?></div>
                                <div>
                                    <div style="font-weight:bold; color:#1e293b;"><?= htmlspecialchars($ann['author_name']) ?></div>
                                    <div style="font-size:0.8rem;"><?= date('d/m/Y H:i', strtotime($ann['created_at'])) ?> น.</div>
                                </div>
                            </div>
                            
                            <?php if ($can_delete): ?>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('ยืนยันการลบประกาศนี้? (นักเรียนจะไม่เห็นประกาศนี้อีกต่อไป)');">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="ann_id" value="<?= $ann['id'] ?>">
                                    <button type="submit" class="btn-delete">🗑️ ลบประกาศ</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <span>📭</span>
                    <h2 style="margin: 0; color: #1e293b;">ยังไม่มีประกาศใดๆ</h2>
                    <p>ใช้ฟอร์มด้านซ้ายเพื่อเริ่มต้นแจ้งข่าวสารสำคัญให้นักเรียนทราบ</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
    function confirmSubmit() {
        let target = document.getElementById('target_class_id');
        let targetText = target.options[target.selectedIndex].text;
        return confirm(`คุณกำลังจะส่งประกาศไปยัง: "${targetText}"\nยืนยันการเผยแพร่หรือไม่?`);
    }
</script>

<?php require_once 'footer.php'; ?>