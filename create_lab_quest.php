<?php
// create_lab_quest.php - Mission Control Center (Debug Edition)

// 1. เปิดแสดง Error ทันที (ป้องกันหน้าขาว)
if (ob_get_level() == 0) ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'auth.php';
requireRole(['teacher', 'developer']); // อนุญาต Teacher และ Dev
require_once 'db.php';

$teacher_id = $_SESSION['user_id'];
$msg = "";
$msg_type = "";

// --------------------------------------------------------
// 0. ตรวจสอบตาราง Quests ก่อนทำงาน (กัน Error หน้าขาว)
// --------------------------------------------------------
$check_table = $conn->query("SHOW TABLES LIKE 'quests'");
if ($check_table->num_rows == 0) {
    die("<div style='padding:20px; color:red; font-family:sans-serif;'>
            <h1>❌ System Error: Missing Database Table</h1>
            <p>ไม่พบตาราง <b>'quests'</b> ในฐานข้อมูล</p>
            <p>กรุณานำโค้ด SQL ในขั้นตอนที่ 1 ไปรันใน phpMyAdmin ก่อนครับ</p>
            <a href='dashboard_teacher.php'>กลับหน้า Dashboard</a>
         </div>");
}

// --------------------------------------------------------
// 1. บันทึกภารกิจ (Save Quest)
// --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_quest') {
    $title = trim($_POST['title']);
    $desc = trim($_POST['description']);
    $target_chem = intval($_POST['target_chem_id']);
    $class_level = trim($_POST['assigned_class']);
    $difficulty = $_POST['difficulty'];
    $xp = intval($_POST['xp_reward']);
    $gold = intval($_POST['gold_reward']);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;

    if (empty($title) || empty($class_level) || empty($target_chem)) {
        $msg = "❌ กรุณากรอกข้อมูลให้ครบ (ชื่อภารกิจ, เป้าหมาย, ห้องเรียน)";
        $msg_type = "error";
    } else {
        $sql = "INSERT INTO quests (teacher_id, title, description, target_chem_id, assigned_class, difficulty, xp_reward, gold_reward, due_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ississiis", $teacher_id, $title, $desc, $target_chem, $class_level, $difficulty, $xp, $gold, $due_date);
            if ($stmt->execute()) {
                $msg = "✨ ประกาศภารกิจสำเร็จ! ส่งไปยังห้อง $class_level แล้ว";
                $msg_type = "success";
            } else {
                $msg = "❌ Database Error: " . $stmt->error;
                $msg_type = "error";
            }
            $stmt->close();
        } else {
            $msg = "❌ Prepare Failed: " . $conn->error;
            $msg_type = "error";
        }
    }
}

// --------------------------------------------------------
// 2. ดึงข้อมูลสารเคมี (Chemicals)
// --------------------------------------------------------
$chemicals = [];
$chem_res = $conn->query("SELECT id, name, state FROM chemicals ORDER BY name ASC");
if ($chem_res) {
    while ($row = $chem_res->fetch_assoc()) {
        $chemicals[] = $row;
    }
}

// --------------------------------------------------------
// 3. ดึงประวัติภารกิจ (My Quests)
// --------------------------------------------------------
$my_quests = [];
// ใช้ LEFT JOIN ป้องกัน Error ถ้าเคมีถูกลบไปแล้ว
$q_sql = "SELECT q.*, c.name as chem_name 
          FROM quests q 
          LEFT JOIN chemicals c ON q.target_chem_id = c.id 
          WHERE q.teacher_id = ? 
          ORDER BY q.created_at DESC";
$stmt = $conn->prepare($q_sql);
if ($stmt) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $q_res = $stmt->get_result();
    while ($row = $q_res->fetch_assoc()) {
        $my_quests[] = $row;
    }
    $stmt->close();
} else {
    // ถ้า Query ผิดพลาด ให้แจ้งเตือนแต่ไม่หยุดทำงาน
    $msg = "Warning: ไม่สามารถดึงประวัติภารกิจได้ (" . $conn->error . ")";
    $msg_type = "error";
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mission Control Center</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&family=Cinzel:wght@700&display=swap" rel="stylesheet">
<style>
    body {
        margin: 0; padding: 0;
        font-family: 'Sarabun', sans-serif;
        background: #0f172a;
        color: #e2e8f0;
        min-height: 100vh;
        background-image: radial-gradient(circle at 50% 0%, #1e293b 0%, #0f172a 100%);
    }
    .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    
    .header-box { text-align: center; margin-bottom: 30px; padding: 30px 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .header-box h1 {
        font-family: 'Cinzel', serif; font-size: 2.5rem; margin: 0;
        background: linear-gradient(to right, #fbbf24, #d97706);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        text-shadow: 0 0 15px rgba(251,191,36,0.2);
    }
    
    .main-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 20px; }
    @media (max-width: 900px) { .main-grid { grid-template-columns: 1fr; } }

    .glass-panel {
        background: rgba(30, 41, 59, 0.6);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 16px;
        padding: 25px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    }

    /* Form Styles */
    label { display: block; margin-bottom: 5px; font-weight: bold; color: #cbd5e1; }
    input, select, textarea {
        width: 100%; padding: 10px; margin-bottom: 15px;
        background: #1e293b; border: 1px solid #475569;
        border-radius: 8px; color: white; font-family: inherit;
        box-sizing: border-box;
    }
    input:focus, select:focus, textarea:focus { border-color: #3b82f6; outline: none; }

    /* Difficulty Radio */
    .diff-selector { display: flex; gap: 5px; margin-bottom: 15px; }
    .diff-option {
        flex: 1; text-align: center; padding: 8px;
        background: #334155; border-radius: 6px; cursor: pointer;
        font-size: 0.9rem; border: 1px solid transparent;
    }
    input[type="radio"] { display: none; }
    input[type="radio"]:checked + .diff-option.easy { background: #059669; border-color: #34d399; }
    input[type="radio"]:checked + .diff-option.medium { background: #d97706; border-color: #fbbf24; }
    input[type="radio"]:checked + .diff-option.hard { background: #dc2626; border-color: #f87171; }
    input[type="radio"]:checked + .diff-option.legendary { background: #7c3aed; border-color: #a78bfa; box-shadow: 0 0 10px #7c3aed; }

    .btn-submit {
        width: 100%; padding: 12px;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: white; border: none; border-radius: 8px;
        font-weight: bold; cursor: pointer; transition: 0.2s;
        font-size: 1.1rem;
    }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(37,99,235,0.4); }

    /* List Items */
    .quest-item {
        background: rgba(255,255,255,0.05);
        padding: 15px; border-radius: 10px; margin-bottom: 10px;
        border-left: 4px solid #64748b;
    }
    .quest-item.easy { border-color: #34d399; }
    .quest-item.medium { border-color: #fbbf24; }
    .quest-item.hard { border-color: #f87171; }
    .quest-item.legendary { border-color: #a78bfa; }

    .msg { padding: 10px; margin-bottom: 20px; border-radius: 8px; text-align: center; }
    .msg.success { background: #064e3b; color: #6ee7b7; border: 1px solid #059669; }
    .msg.error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }

    .back-link { display: inline-block; margin-bottom: 10px; color: #94a3b8; text-decoration: none; }
    .back-link:hover { color: white; }
</style>
</head>
<body>

<div class="container">
    <a href="dashboard_teacher.php" class="back-link">⬅ กลับหน้า Dashboard</a>

    <div class="header-box">
        <h1>⚔️ Mission Control Center</h1>
        <p>ศูนย์บัญชาการภารกิจห้องแล็บจำลอง</p>
    </div>

    <?php if ($msg): ?>
        <div class="msg <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <div class="main-grid">
        
        <div class="glass-panel">
            <h3 style="margin-top:0; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:10px;">📜 สร้างภารกิจใหม่</h3>
            
            <form method="post">
                <input type="hidden" name="action" value="create_quest">

                <label>ชื่อภารกิจ</label>
                <input type="text" name="title" placeholder="เช่น ปรุงยาล่องหน" required>

                <label>รายละเอียด/คำใบ้</label>
                <textarea name="description" rows="3" placeholder="จงผสมสาร A กับ B..."></textarea>

                <label>🧪 เป้าหมายการผสม (Target)</label>
                <select name="target_chem_id" required>
                    <option value="">-- เลือกสารเคมีที่ต้องผสมให้ได้ --</option>
                    <?php foreach($chemicals as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['state'] ?>)</option>
                    <?php endforeach; ?>
                </select>

                <label>ระดับความยาก</label>
                <div class="diff-selector">
                    <label style="flex:1"><input type="radio" name="difficulty" value="easy" checked><div class="diff-option easy">Easy</div></label>
                    <label style="flex:1"><input type="radio" name="difficulty" value="medium"><div class="diff-option medium">Normal</div></label>
                    <label style="flex:1"><input type="radio" name="difficulty" value="hard"><div class="diff-option hard">Hard</div></label>
                    <label style="flex:1"><input type="radio" name="difficulty" value="legendary"><div class="diff-option legendary">Legend</div></label>
                </div>

                <div style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label>XP</label>
                        <input type="number" name="xp_reward" value="100">
                    </div>
                    <div style="flex:1;">
                        <label>Gold</label>
                        <input type="number" name="gold_reward" value="50">
                    </div>
                </div>

                <label>มอบหมายให้ห้อง (Class)</label>
                <input type="text" name="assigned_class" value="<?= htmlspecialchars($_SESSION['subject_group'] ?? 'ม.6/1') ?>" required>

                <label>กำหนดส่ง (ว่างได้)</label>
                <input type="datetime-local" name="due_date">

                <button type="submit" class="btn-submit">🚀 ประกาศภารกิจ</button>
            </form>
        </div>

        <div class="glass-panel">
            <h3 style="margin-top:0;">📚 ภารกิจปัจจุบัน</h3>
            
            <?php if (count($my_quests) > 0): ?>
                <?php foreach($my_quests as $q): ?>
                    <div class="quest-item <?= $q['difficulty'] ?>">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong><?= htmlspecialchars($q['title']) ?></strong>
                            <span style="font-size:0.8rem; background:rgba(0,0,0,0.3); padding:2px 6px; border-radius:4px;"><?= ucfirst($q['difficulty']) ?></span>
                        </div>
                        <div style="font-size:0.9rem; color:#cbd5e1; margin:5px 0;">
                            เป้าหมาย: <span style="color:#7dd3fc;"><?= htmlspecialchars($q['chem_name']) ?></span>
                            <br>
                            ห้อง: <?= htmlspecialchars($q['assigned_class']) ?>
                        </div>
                        <div style="font-size:0.8rem; color:#94a3b8; margin-top:5px; display:flex; gap:10px;">
                            <span>✨ <?= $q['xp_reward'] ?> XP</span>
                            <span>💰 <?= $q['gold_reward'] ?> Gold</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align:center; color:#64748b; padding:20px;">ยังไม่มีภารกิจ</p>
            <?php endif; ?>
        </div>

    </div>
</div>

</body>
</html>