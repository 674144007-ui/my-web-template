<?php
// teacher_quests.php - ระบบสร้างภารกิจห้องทดลอง (Lab Quest Creator - Phase 2: AKSORN Integration + Scroll Fix)
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
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
        // รับค่าข้อมูลพื้นฐาน
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $reward_points = intval($_POST['bonus_xp'] ?? 500);
        $class_id = intval($_POST['target_class_id'] ?? 0);
        
        // รับค่าสารเคมีเป้าหมาย
        $target_chem1 = intval($_POST['target_chem1'] ?? 0);
        $target_chem2 = !empty($_POST['target_chem2']) ? intval($_POST['target_chem2']) : null;
        $target_product = trim($_POST['target_product'] ?? 'ไม่ระบุ');

        // รับค่าความสมจริง (Realism & Physics)
        $req_stir = isset($_POST['required_stirring']) ? 1 : 0;
        
        $req_temp_min = (!empty($_POST['req_temp_min'])) ? floatval($_POST['req_temp_min']) : null;
        $req_temp_max = (!empty($_POST['req_temp_max'])) ? floatval($_POST['req_temp_max']) : null;
        
        $strict_amt = isset($_POST['strict_amount']) ? 1 : 0;
        $amt1 = ($strict_amt === 1 && !empty($_POST['amount_chem1'])) ? floatval($_POST['amount_chem1']) : null;
        $amt2 = ($strict_amt === 1 && !empty($_POST['amount_chem2'])) ? floatval($_POST['amount_chem2']) : null;

        // รับค่าความปลอดภัย
        $s_goggles = isset($_POST['safety_goggles']) ? 1 : 0;
        $s_gloves = isset($_POST['safety_gloves']) ? 1 : 0;
        $max_spill = intval($_POST['max_spill_allowed'] ?? 3);
        
        if (empty($title) || empty($desc) || empty($target_chem1)) {
            $message = '❌ กรุณากรอกชื่อภารกิจ รายละเอียด และเลือกสารเคมีเป้าหมายให้ครบถ้วน';
            $msg_type = 'error';
        } else {
            $target = ($class_id === 0) ? NULL : $class_id;
            
            // เตรียมคำสั่ง SQL บันทึกข้อมูลครอบคลุมทุกฟิลด์
            $sql = "INSERT INTO quests (
                        teacher_id, title, description, reward_points, target_class_id, target_chem1, is_active,
                        target_chem2, target_product, required_temp_min, required_temp_max, required_stirring,
                        strict_amount, amount_chem1, amount_chem2, safety_goggles, safety_gloves, max_spill_allowed
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            if($stmt) {
                // "issiiiisddiidddii" = 17 parameters
                $stmt->execute([$teacher_id, $title, $desc, $reward_points, $target, $target_chem1,
                    $target_chem2, $target_product, $req_temp_min, $req_temp_max, $req_stir,
                    $strict_amt, $amt1, $amt2, $s_goggles, $s_gloves, $max_spill]);
                
                if ($stmt->execute()) {
                    $message = '✅ สร้างภารกิจใหม่สำเร็จ! นักเรียนจะเห็นเควสนี้ทันที';
                    $msg_type = 'success';
                    systemLog($teacher_id, 'QUEST_CREATE', "Created quest: $title (Chem ID: $target_chem1)");
                } else {
                    $message = '❌ เกิดข้อผิดพลาดในระบบฐานข้อมูล: ' . $stmt->error;
                    $msg_type = 'error';
                }
                
            } else {
                $message = '❌ SQL Prepare Error: ' . $pdo->error;
                $msg_type = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $quest_id = intval($_POST['quest_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM quests WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$quest_id, $teacher_id]);
        if ($stmt->execute()) {
            $message = '🗑️ ลบภารกิจออกจากระบบเรียบร้อย';
            $msg_type = 'success';
        }
        
    } elseif ($action === 'toggle') {
        $quest_id = intval($_POST['quest_id'] ?? 0);
        $new_status = intval($_POST['new_status'] ?? 0);
        $stmt = $pdo->prepare("UPDATE quests SET is_active = ? WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$new_status, $quest_id, $teacher_id]);
        if ($stmt->execute()) {
            $message = '🔄 อัปเดตสถานะภารกิจเรียบร้อย';
            $msg_type = 'success';
        }
        
    }
}

// =========================================================
// 2. ดึงข้อมูลประกอบหน้าจอ
// =========================================================

// ดึงรายชื่อห้อง
$classes = [];
$res_classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch()) { $classes[] = $row; }
}

// ดึงรายชื่อสารเคมีสำหรับ Dropdown
$chemicals = [];
$res_chem = $pdo->query("SELECT id, name, formula, state, molarity FROM chemicals ORDER BY name ASC");
if ($res_chem) {
    while ($row = $res_chem->fetch()) { $chemicals[] = $row; }
}

// ดึงรายการเควสที่สร้างไว้แล้วของครูคนนี้
$quests = [];
$stmt_q = $pdo->prepare("
    SELECT q.*, c.class_name, ch1.name as target_chem1_name, ch2.name as target_chem2_name
    FROM quests q
    LEFT JOIN classes c ON q.target_class_id = c.id
    LEFT JOIN chemicals ch1 ON q.target_chem1 = ch1.id
    LEFT JOIN chemicals ch2 ON q.target_chem2 = ch2.id
    WHERE q.teacher_id = ?
    ORDER BY q.created_at DESC
");
$stmt_q->execute([$teacher_id]);
$stmt_q->execute();
$res_q = $stmt_q;
while ($row = $res_q->fetch()) {
    $quests[] = $row;
}


// ดึงข้อมูล "คลังภารกิจ สสวท." (IPST Quest Library)
$ipst_quests = [];
$res_ipst = $pdo->query("
    SELECT i.*, ch1.name as chem1_name, ch2.name as chem2_name 
    FROM ipst_quest_library i
    LEFT JOIN chemicals ch1 ON i.target_chem1 = ch1.id
    LEFT JOIN chemicals ch2 ON i.target_chem2 = ch2.id
    ORDER BY i.chapter_name ASC, i.id ASC
");
if ($res_ipst) {
    while ($row = $res_ipst->fetch()) {
        $ipst_quests[$row['chapter_name']][] = $row;
    }
}

// ดึงข้อมูล "คลังภารกิจ อักษรฯ (AKSORN)"
$aksorn_quests = [];
try {
    $res_aksorn = $pdo->query("
        SELECT a.*, ch1.name as chem1_name, ch2.name as chem2_name 
        FROM aksorn_quest_library a
        LEFT JOIN chemicals ch1 ON a.target_chem1 = ch1.id
        LEFT JOIN chemicals ch2 ON a.target_chem2 = ch2.id
        ORDER BY a.unit_name ASC, a.id ASC
    ");
    while ($row = $res_aksorn->fetch()) {
        $aksorn_quests[$row['unit_name']][] = $row;
    }
} catch (PDOException $e) {
    error_log('[teacher_quests] aksorn: ' . $e->getMessage());
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Secular+One&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS หลักสำหรับระบบจัดการเควส
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .quest-wrapper { max-width: 1250px; margin: 30px auto; padding: 0 20px; }

    /* Header & Controls */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #b45309; display: flex; align-items: center; gap: 10px; }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    .control-panel { 
        background: white; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; 
        display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
        flex-wrap: wrap; gap: 15px;
    }
    .control-panel-text h3 { margin: 0 0 5px 0; color: #1e293b; font-size: 1.2rem; }
    .control-panel-text p { margin: 0; color: #64748b; font-size: 0.95rem; }
    
    .btn-group-main { display: flex; gap: 10px; flex-wrap: wrap;}
    
    .btn-create-manual { 
        background: white; color: #0f172a; border: 2px solid #cbd5e1; padding: 12px 20px; 
        border-radius: 10px; font-weight: bold; font-size: 1rem; cursor: pointer; transition: 0.3s; font-family: inherit;
    }
    .btn-create-manual:hover { border-color: #94a3b8; background: #f8fafc; }
    
    .btn-create-ipst { 
        background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: white; border: none; 
        padding: 12px 20px; border-radius: 10px; font-weight: bold; font-size: 1rem; cursor: pointer; 
        display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 15px rgba(2, 132, 199, 0.4); font-family: inherit;
    }
    .btn-create-ipst:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(2, 132, 199, 0.6); }

    /* ปุ่มของ AKSORN */
    .btn-create-aksorn { 
        background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; border: none; 
        padding: 12px 20px; border-radius: 10px; font-weight: bold; font-size: 1rem; cursor: pointer; 
        display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4); font-family: inherit;
    }
    .btn-create-aksorn:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.6); }

    /* Alert Message */
    .alert-box { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; animation: fadeIn 0.5s; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    /* Quest Grid */
    .quests-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 25px; }
    .quest-card { background: white; border-radius: 16px; border: 2px solid #e2e8f0; overflow: hidden; display: flex; flex-direction: column; transition: 0.3s; box-shadow: 0 5px 15px rgba(0,0,0,0.05); position: relative;}
    .quest-card:hover { transform: translateY(-5px); border-color: #fcd34d; box-shadow: 0 15px 30px rgba(245, 158, 11, 0.2); }
    .quest-card.inactive { filter: grayscale(80%); opacity: 0.8; border-color: #cbd5e1; }
    
    .quest-header { background: #0f172a; padding: 20px; color: white; position: relative; }
    .quest-header::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: radial-gradient(circle at top right, rgba(245, 158, 11, 0.3), transparent); pointer-events: none; }
    .quest-target-badge { position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.4); }
    .quest-title { margin: 0 0 10px 0; font-size: 1.25rem; font-weight: 700; color: #fcd34d; z-index: 2; position: relative; line-height: 1.4;}
    .xp-badge { display: inline-flex; align-items: center; gap: 5px; background: linear-gradient(90deg, #8b5cf6, #3b82f6); padding: 5px 12px; border-radius: 8px; font-family: 'Secular One', sans-serif; font-size: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.3); border: 1px solid #c4b5fd;}
    
    .quest-body { padding: 20px; flex: 1; color: #475569; font-size: 0.95rem; line-height: 1.6; }
    .quest-chem-target { margin-top: 15px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem; }
    .quest-chem-target strong { color: #0284c7; display: block; margin-bottom: 5px;}
    .tag-pill { display: inline-block; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; margin-right: 5px; margin-bottom: 5px;}
    
    .quest-footer { background: #f8fafc; padding: 15px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .status-text { font-weight: bold; font-size: 0.9rem; }
    .status-on { color: #10b981; } .status-off { color: #94a3b8; }
    
    .action-group { display: flex; gap: 10px; }
    .btn-icon { background: white; border: 1px solid #cbd5e1; width: 35px; height: 35px; border-radius: 8px; cursor: pointer; transition: 0.2s; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;}
    .btn-icon:hover { background: #f1f5f9; transform: scale(1.1); }
    .btn-delete:hover { border-color: #ef4444; color: #ef4444; background: #fee2e2; }

    /* Modals (General) - คงการแก้ปัญหา Scroll 100% ไว้ */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    
    .modal-box { 
        background: white; width: 100%; border-radius: 16px; 
        box-shadow: 0 20px 50px rgba(0,0,0,0.5); 
        transform: translateY(20px); opacity: 0; transition: 0.3s; 
        display: flex; flex-direction: column; max-height: 90vh; 
    }
    .modal-box.show { transform: translateY(0); opacity: 1; }
    
    .modal-box form { display: flex; flex-direction: column; height: 100%; margin: 0; overflow: hidden; }

    .modal-header { padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;}
    .modal-header h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);}
    .btn-close { background: transparent; color: white; border: none; font-size: 1.5rem; cursor: pointer; opacity: 0.8; transition: 0.2s; }
    .btn-close:hover { opacity: 1; transform: scale(1.2); }

    .modal-body { padding: 25px; overflow-y: auto; max-height: 65vh; flex: 1; scrollbar-width: thin; }
    
    .modal-footer { padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 15px; flex-shrink: 0;}

    /* Form Controls */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; color: #1e293b; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 1rem; box-sizing: border-box; transition: 0.3s; background: #fff;}
    .form-control:focus { border-color: #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1); }
    textarea.form-control { resize: vertical; height: 100px; }
    
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    
    /* Advanced Settings Collapsible */
    details.advanced-settings { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
    details.advanced-settings summary { font-weight: bold; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 10px; list-style: none; outline: none; }
    details.advanced-settings summary::-webkit-details-marker { display: none; }
    details.advanced-settings[open] summary { border-bottom: 1px solid #cbd5e1; padding-bottom: 10px; margin-bottom: 15px; color: #0f172a;}
    
    .checkbox-group { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; background: #fff; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; }
    .checkbox-group input[type="checkbox"] { width: 18px; height: 18px; accent-color: #0284c7; }
    .checkbox-group label { margin: 0; font-weight: 600; cursor: pointer; color: #1e293b; display: flex; align-items: center; gap: 8px;}

    /* Catalog Library Styles (ใช้ร่วมกันทั้ง สสวท. และ อักษรฯ) */
    .catalog-chapter { margin-bottom: 30px; }
    .catalog-chapter-title { font-size: 1.3rem; color: #0f172a; border-bottom: 2px solid; padding-bottom: 10px; margin-bottom: 15px; }
    
    /* Theme ย่อย */
    .theme-ipst .catalog-chapter-title { border-color: #0284c7; }
    .theme-aksorn .catalog-chapter-title { border-color: #b91c1c; }

    .catalog-grid { display: grid; grid-template-columns: 1fr; gap: 15px; }
    @media(min-width: 768px) { .catalog-grid { grid-template-columns: 1fr 1fr; } }
    
    .catalog-card { border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; background: #fff; transition: 0.2s; display: flex; flex-direction: column; }
    .theme-ipst .catalog-card:hover { border-color: #0284c7; box-shadow: 0 4px 15px rgba(2, 132, 199, 0.1); }
    .theme-aksorn .catalog-card:hover { border-color: #b91c1c; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.1); }
    
    .catalog-card h4 { margin: 0 0 10px 0; font-size: 1.1rem; line-height: 1.4; }
    .theme-ipst .catalog-card h4 { color: #0284c7; }
    .theme-aksorn .catalog-card h4 { color: #b91c1c; }
    
    .obj-text { font-size: 0.85rem; color: #0f172a; background: #f1f5f9; padding: 8px; border-radius: 6px; margin-bottom: 10px; border-left: 3px solid #94a3b8; }
    .desc-text { font-size: 0.9rem; color: #64748b; margin: 0 0 15px 0; flex: 1; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;}
    
    .catalog-card-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed #cbd5e1; padding-top: 10px;}
    
    .btn-select-catalog { border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; font-family: inherit;}
    .theme-ipst .btn-select-catalog { background: #e0f2fe; color: #0284c7; }
    .theme-ipst .btn-select-catalog:hover { background: #0284c7; color: #fff; }
    
    .theme-aksorn .btn-select-catalog { background: #fee2e2; color: #b91c1c; }
    .theme-aksorn .btn-select-catalog:hover { background: #b91c1c; color: #fff; }

    /* Empty State */
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
        <div class="control-panel-text">
            <h3>จัดการภารกิจและโจทย์การทดลอง</h3>
            <p>มอบหมายให้นักเรียนทำแล็บเสมือนจริง โดยสามารถเลือกจากคลังมาตรฐาน หรือสร้างเองได้</p>
        </div>
        <div class="btn-group-main">
            <button class="btn-create-manual" onclick="openCreateModal()">
                ✍️ สร้างภารกิจเอง
            </button>
            <button class="btn-create-ipst" onclick="openIpstModal()">
                📘 คลัง สสวท.
            </button>
            <button class="btn-create-aksorn" onclick="openAksornModal()">
                📕 คลัง อักษรฯ (AKSORN)
            </button>
        </div>
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
                        <div class="xp-badge">⚡ +<?= number_format($q['reward_points']) ?> XP</div>
                    </div>
                    <div class="quest-body">
                        <?= nl2br(htmlspecialchars($q['description'])) ?>
                        
                        <div class="quest-chem-target">
                            <strong>🧪 เป้าหมายของภารกิจ:</strong>
                            <?php if(!empty($q['target_chem1_name'])): ?>
                                <span class="tag-pill">สาร 1: <?= htmlspecialchars($q['target_chem1_name']) ?></span>
                            <?php else: ?>
                                <span style="color:#ef4444; font-weight:bold;">[รอระบุสารเคมี]</span>
                            <?php endif; ?>

                            <?php if(!empty($q['target_chem2_name'])): ?>
                                <span class="tag-pill">สาร 2: <?= htmlspecialchars($q['target_chem2_name']) ?></span>
                            <?php endif; ?>
                            
                            <?php if(!empty($q['target_product'])): ?>
                                <span class="tag-pill" style="background:#fef08a; color:#854d0e;">เกิด: <?= htmlspecialchars($q['target_product']) ?></span>
                            <?php endif; ?>

                            <div style="margin-top: 8px;">
                                <?php if($q['required_stirring']) echo '<span title="บังคับคนสาร">🥄</span>'; ?>
                                <?php if($q['strict_amount']) echo '<span title="บังคับปริมาณเป๊ะ">⚖️</span>'; ?>
                                <?php if($q['required_temp_min']) echo '<span title="ควบคุมอุณหภูมิ">🔥</span>'; ?>
                                <?php if($q['safety_goggles']) echo '<span title="บังคับใส่แว่น">🥽</span>'; ?>
                            </div>
                        </div>
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
                <h2 style="margin: 0; color: #1e293b;">ยังไม่มีภารกิจในระบบ</h2>
                <p>คลิกปุ่ม "เลือกจากคลัง" เพื่อเริ่มต้นสร้างห้องแล็บที่สมบูรณ์แบบได้ทันที</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="modal-overlay" id="createModalOverlay">
    <div class="modal-box" id="createModalBox" style="max-width: 650px;">
        <form method="POST" action="teacher_quests.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create">

            <div class="modal-header" id="createModalHeader" style="background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white;">
                <h2 id="createModalTitle">⚔️ สร้างภารกิจการทดลอง</h2>
                <button type="button" class="btn-close" onclick="closeCreateModal()">✖</button>
            </div>
            
            <div class="modal-body">
                <div class="form-group">
                    <label for="target_class_id">🎯 เป้าหมาย (มอบหมายให้ใคร?)</label>
                    <select name="target_class_id" id="form_target_class_id" class="form-control">
                        <option value="0">🌍 ประกาศให้นักเรียนทุกคนเห็น (ทุกชั้นเรียน)</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>">เฉพาะห้อง: <?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="title">📜 ชื่อภารกิจ (Quest Title)</label>
                    <input type="text" name="title" id="form_title" class="form-control" placeholder="เช่น: การไทเทรตหาความเข้มข้น..." required>
                </div>

                <div class="form-group">
                    <label for="description">📝 รายละเอียดและคำใบ้ (Objective)</label>
                    <textarea name="description" id="form_description" class="form-control" placeholder="อธิบายโจทย์ หรือบอกใบ้สารเคมีที่ต้องใช้..." required></textarea>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="target_chem1">🧪 สารตั้งต้นหลัก 1</label>
                        <select name="target_chem1" id="form_target_chem1" class="form-control" required>
                            <option value="">-- เลือกสารเคมี --</option>
                            <?php foreach ($chemicals as $ch): ?>
                                <option value="<?= $ch['id'] ?>">
                                    <?= htmlspecialchars($ch['name']) ?> 
                                    <?= $ch['molarity'] ? " ({$ch['molarity']}M)" : "" ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="target_chem2">🧪 สารตั้งต้นรอง 2 (ถ้ามี)</label>
                        <select name="target_chem2" id="form_target_chem2" class="form-control">
                            <option value="">-- ไม่ต้องการ --</option>
                            <?php foreach ($chemicals as $ch): ?>
                                <option value="<?= $ch['id'] ?>">
                                    <?= htmlspecialchars($ch['name']) ?> 
                                    <?= $ch['molarity'] ? " ({$ch['molarity']}M)" : "" ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="target_product">✨ ผลิตภัณฑ์ที่คาดหวัง</label>
                        <input type="text" name="target_product" id="form_target_product" class="form-control" placeholder="เช่น ตะกอนสีเหลือง, ก๊าซ H2">
                    </div>
                    <div class="form-group">
                        <label for="bonus_xp">⚡ รางวัล XP</label>
                        <input type="number" name="bonus_xp" id="form_bonus_xp" class="form-control" value="1000" min="10" required>
                    </div>
                </div>

                <details class="advanced-settings" id="advancedSettingsBox">
                    <summary>⚙️ ตั้งค่าความสมจริงและเงื่อนไขแล็บขั้นสูง (คลิกเพื่อขยาย)</summary>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="required_stirring" id="form_required_stirring" value="1">
                        <label for="form_required_stirring">🥄 บังคับให้ใช้แท่งแก้วคนสาร (ถ้าไม่คนจะไม่ทำปฏิกิริยา)</label>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" name="strict_amount" id="form_strict_amount" value="1" onchange="toggleAmountFields()">
                        <label for="form_strict_amount">⚖️ บังคับสัดส่วนปริมาตรสาร (Stoichiometry)</label>
                    </div>
                    <div class="grid-2" id="amount_fields" style="display:none; margin-bottom: 15px;">
                        <div>
                            <label style="font-size:0.85rem;">ปริมาณสาร 1 (ml/g)</label>
                            <input type="number" step="0.1" name="amount_chem1" id="form_amount_chem1" class="form-control" placeholder="เช่น 10.0">
                        </div>
                        <div>
                            <label style="font-size:0.85rem;">ปริมาณสาร 2 (ml/g)</label>
                            <input type="number" step="0.1" name="amount_chem2" id="form_amount_chem2" class="form-control" placeholder="เช่น 20.0">
                        </div>
                    </div>

                    <div class="grid-2" style="margin-bottom: 15px;">
                        <div>
                            <label style="font-size:0.85rem;">🔥 อุณหภูมิต่ำสุด (°C)</label>
                            <input type="number" step="0.1" name="req_temp_min" id="form_req_temp_min" class="form-control" placeholder="เช่น 50">
                        </div>
                        <div>
                            <label style="font-size:0.85rem;">🔥 อุณหภูมิสูงสุด (°C)</label>
                            <input type="number" step="0.1" name="req_temp_max" id="form_req_temp_max" class="form-control" placeholder="เช่น 60">
                        </div>
                    </div>

                    <p style="margin: 15px 0 5px 0; font-weight:bold; font-size:0.9rem; color:#1e293b;">🦺 อุปกรณ์ความปลอดภัย</p>
                    <div class="grid-2">
                        <div class="checkbox-group">
                            <input type="checkbox" name="safety_goggles" id="form_safety_goggles" value="1" checked>
                            <label for="form_safety_goggles">🥽 บังคับสวมแว่นตา</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" name="safety_gloves" id="form_safety_gloves" value="1" checked>
                            <label for="form_safety_gloves">🧤 บังคับสวมถุงมือ</label>
                        </div>
                    </div>
                    
                    <div style="margin-top: 10px;">
                        <label style="font-size:0.85rem;">จำนวนครั้งที่ยอมให้ทำสารหกได้สูงสุด (Spill Tolerance)</label>
                        <select name="max_spill_allowed" id="form_max_spill" class="form-control">
                            <option value="0">0 ครั้ง (ห้ามหกเด็ดขาด = ตายทันที)</option>
                            <option value="1">1 ครั้ง</option>
                            <option value="2">2 ครั้ง</option>
                            <option value="3" selected>3 ครั้ง (มาตรฐาน)</option>
                            <option value="99">ไม่จำกัด</option>
                        </select>
                    </div>

                </details>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeCreateModal()">ยกเลิก</button>
                <button type="submit" class="btn-create-ipst" id="btnSubmitForm" style="border-radius:8px;">✅ บันทึกภารกิจ</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="ipstModalOverlay">
    <div class="modal-box" id="ipstModalBox" style="max-width: 900px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: white;">
            <h2>📘 คลังแบบปฏิบัติการเคมี สสวท. (ม.5 เล่ม 3-4)</h2>
            <button type="button" class="btn-close" onclick="closeIpstModal()">✖</button>
        </div>
        
        <div class="modal-body theme-ipst" style="background: #f8fafc;">
            <?php if (empty($ipst_quests)): ?>
                <div class="empty-state">
                    <span>🚧</span>
                    <h3>ยังไม่มีข้อมูลในคลัง สสวท.</h3>
                    <p>กรุณาติดต่อ Developer เพื่อรัน Database Patch</p>
                </div>
            <?php else: ?>
                <?php foreach ($ipst_quests as $chapter => $quests_in_chap): ?>
                    <div class="catalog-chapter">
                        <h3 class="catalog-chapter-title">📖 <?= htmlspecialchars($chapter) ?></h3>
                        <div class="catalog-grid">
                            <?php foreach ($quests_in_chap as $iq): ?>
                                <div class="catalog-card">
                                    <h4><?= htmlspecialchars($iq['quest_title']) ?></h4>
                                    <p class="desc-text"><?= htmlspecialchars($iq['quest_description']) ?></p>
                                    <div class="catalog-card-footer">
                                        <span class="xp-badge" style="transform: scale(0.85); transform-origin: left;">
                                            ⚡ <?= $iq['bonus_xp'] ?> XP
                                        </span>
                                        <button class="btn-select-catalog" onclick='useCatalogTemplate(<?= json_encode($iq, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "ipst")'>
                                            ✨ เลือกใช้ภารกิจนี้
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" id="aksornModalOverlay">
    <div class="modal-box" id="aksornModalBox" style="max-width: 900px; border: 2px solid #ef4444;">
        <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white;">
            <h2>📕 คลังแบบฝึกหัดเคมี อักษรเจริญทัศน์ (AKSORN ม.5)</h2>
            <button type="button" class="btn-close" onclick="closeAksornModal()">✖</button>
        </div>
        
        <div class="modal-body theme-aksorn" style="background: #fef2f2;">
            <?php if (empty($aksorn_quests)): ?>
                <div class="empty-state">
                    <span>🚧</span>
                    <h3>ยังไม่มีข้อมูลในคลัง AKSORN</h3>
                    <p>กรุณาติดต่อ Developer เพื่อรัน Database Patch ระยะที่ 1</p>
                </div>
            <?php else: ?>
                <?php foreach ($aksorn_quests as $unit => $quests_in_unit): ?>
                    <div class="catalog-chapter">
                        <h3 class="catalog-chapter-title">📖 <?= htmlspecialchars($unit) ?></h3>
                        <div class="catalog-grid">
                            <?php foreach ($quests_in_unit as $aq): ?>
                                <div class="catalog-card">
                                    <h4><?= htmlspecialchars($aq['quest_title']) ?></h4>
                                    <p class="obj-text"><strong>🎯 จุดประสงค์:</strong> <?= htmlspecialchars($aq['learning_objective']) ?></p>
                                    <p class="desc-text"><?= htmlspecialchars($aq['quest_description']) ?></p>
                                    
                                    <div class="catalog-card-footer">
                                        <span class="xp-badge" style="transform: scale(0.85); transform-origin: left; background: linear-gradient(90deg, #ef4444, #f59e0b); border-color:#fca5a5;">
                                            ⚡ <?= $aq['bonus_xp'] ?> XP
                                        </span>
                                        <button class="btn-select-catalog" onclick='useCatalogTemplate(<?= json_encode($aq, JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "aksorn")'>
                                            ✨ เลือกใช้การทดลองนี้
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // ==========================================
    // Modal Management
    // ==========================================
    const createOverlay = document.getElementById('createModalOverlay');
    const createBox = document.getElementById('createModalBox');
    
    const ipstOverlay = document.getElementById('ipstModalOverlay');
    const ipstBox = document.getElementById('ipstModalBox');

    const aksornOverlay = document.getElementById('aksornModalOverlay');
    const aksornBox = document.getElementById('aksornModalBox');

    function openCreateModal() {
        document.getElementById('form_title').value = '';
        document.getElementById('form_description').value = '';
        document.getElementById('form_target_chem1').value = '';
        document.getElementById('form_target_chem2').value = '';
        document.getElementById('form_target_product').value = '';
        document.getElementById('form_bonus_xp').value = '1000';
        
        document.getElementById('form_required_stirring').checked = false;
        document.getElementById('form_strict_amount').checked = false;
        toggleAmountFields();
        document.getElementById('form_amount_chem1').value = '';
        document.getElementById('form_amount_chem2').value = '';
        document.getElementById('form_req_temp_min').value = '';
        document.getElementById('form_req_temp_max').value = '';
        
        document.getElementById('form_safety_goggles').checked = true;
        document.getElementById('form_safety_gloves').checked = true;
        document.getElementById('form_max_spill').value = '3';

        document.getElementById('advancedSettingsBox').removeAttribute('open');
        
        document.getElementById('createModalHeader').style.background = 'linear-gradient(135deg, #f59e0b 0%, #ea580c 100%)';
        document.getElementById('createModalTitle').innerHTML = '⚔️ สร้างภารกิจการทดลอง (Manual)';
        document.getElementById('btnSubmitForm').className = 'btn-create-ipst'; 
        document.getElementById('btnSubmitForm').style.background = ''; 

        createOverlay.style.display = 'flex';
        setTimeout(() => { createBox.classList.add('show'); }, 10);
    }

    function closeCreateModal() {
        createBox.classList.remove('show');
        setTimeout(() => { createOverlay.style.display = 'none'; }, 300);
    }

    function openIpstModal() {
        ipstOverlay.style.display = 'flex';
        setTimeout(() => { ipstBox.classList.add('show'); }, 10);
    }

    function closeIpstModal() {
        ipstBox.classList.remove('show');
        setTimeout(() => { ipstOverlay.style.display = 'none'; }, 300);
    }

    function openAksornModal() {
        aksornOverlay.style.display = 'flex';
        setTimeout(() => { aksornBox.classList.add('show'); }, 10);
    }

    function closeAksornModal() {
        aksornBox.classList.remove('show');
        setTimeout(() => { aksornOverlay.style.display = 'none'; }, 300);
    }

    function toggleAmountFields() {
        const strictChk = document.getElementById('form_strict_amount');
        document.getElementById('amount_fields').style.display = strictChk.checked ? 'grid' : 'none';
    }

    // ==========================================
    // Auto-fill Template Function
    // ==========================================
    function useCatalogTemplate(data, source) {
        if (source === 'ipst') closeIpstModal();
        else if (source === 'aksorn') closeAksornModal();

        document.getElementById('form_title').value = data.quest_title;
        document.getElementById('form_description').value = data.quest_description;
        document.getElementById('form_target_chem1').value = data.target_chem1;
        document.getElementById('form_target_chem2').value = data.target_chem2 || '';
        document.getElementById('form_target_product').value = data.target_product || '';
        document.getElementById('form_bonus_xp').value = data.bonus_xp;

        document.getElementById('form_required_stirring').checked = (data.required_stirring == 1);
        
        document.getElementById('form_strict_amount').checked = (data.strict_amount == 1);
        toggleAmountFields();
        document.getElementById('form_amount_chem1').value = data.amount_chem1 || '';
        document.getElementById('form_amount_chem2').value = data.amount_chem2 || '';

        document.getElementById('form_req_temp_min').value = data.required_temp_min || '';
        document.getElementById('form_req_temp_max').value = data.required_temp_max || '';

        document.getElementById('form_safety_goggles').checked = (data.safety_goggles == 1);
        document.getElementById('form_safety_gloves').checked = (data.safety_gloves == 1);
        document.getElementById('form_max_spill').value = data.max_spill_allowed;

        document.getElementById('advancedSettingsBox').setAttribute('open', 'true');
        
        const header = document.getElementById('createModalHeader');
        const title = document.getElementById('createModalTitle');
        const submitBtn = document.getElementById('btnSubmitForm');

        if (source === 'ipst') {
            header.style.background = 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)';
            title.innerHTML = '📘 สร้างภารกิจจากแม่แบบ (สสวท.)';
            submitBtn.style.background = 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)';
        } else if (source === 'aksorn') {
            header.style.background = 'linear-gradient(135deg, #ef4444 0%, #b91c1c 100%)';
            title.innerHTML = '📕 สร้างภารกิจจากแม่แบบ (อักษรฯ)';
            submitBtn.style.background = 'linear-gradient(135deg, #ef4444 0%, #b91c1c 100%)';
        }

        setTimeout(() => {
            createOverlay.style.display = 'flex';
            setTimeout(() => { createBox.classList.add('show'); }, 10);
        }, 300);
    }

    window.onclick = function(event) {
        if(event.target === createOverlay) closeCreateModal();
        if(event.target === ipstOverlay) closeIpstModal();
        if(event.target === aksornOverlay) closeAksornModal();
    }
</script>

<?php require_once 'footer.php'; ?>