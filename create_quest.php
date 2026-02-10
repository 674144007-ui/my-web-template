<?php
// create_quest.php - Mission Control Center

// 1. เริ่ม Buffer และ Error Reporting
if (ob_get_level() == 0) ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'auth.php';

// 🔥 จุดสำคัญ: อนุญาตให้ 'teacher' เข้าได้ (รวมถึง developer และ admin)
// โค้ดนี้จะทำงานร่วมกับ auth.php ใหม่ ถ้าเข้าไม่ได้จะขึ้นจอแดง ไม่เด้งกลับ
requireRole(['teacher', 'developer', 'admin']);

require_once 'db.php';

$teacher_id = $_SESSION['user_id'];
$msg = "";
$msg_type = "";

// 2. ตรวจสอบตาราง Quests (กัน Error หน้าขาว)
$check_table = $conn->query("SHOW TABLES LIKE 'quests'");
if ($check_table->num_rows == 0) {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif;'>
            <h1 style='color:red;'>❌ Database Error</h1>
            <p>ไม่พบตาราง <code>quests</code> ในฐานข้อมูล</p>
            <p>กรุณารันคำสั่ง SQL ในขั้นตอนที่ 3 เพื่อซ่อมฐานข้อมูลครับ</p>
         </div>");
}

// 3. Logic บันทึกข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_quest') {
    $title = trim($_POST['title']);
    $desc = trim($_POST['description']);
    $target_chem = isset($_POST['target_chem_id']) ? intval($_POST['target_chem_id']) : 0;
    $class_level = trim($_POST['assigned_class']);
    $difficulty = $_POST['difficulty'];
    $xp = intval($_POST['xp_reward']);
    $gold = intval($_POST['gold_reward']);
    $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;

    if (empty($title) || empty($class_level)) {
        $msg = "❌ กรุณากรอกชื่อภารกิจและห้องเรียน"; $msg_type = "error";
    } elseif ($target_chem == 0) {
        $msg = "❌ กรุณาเลือกเป้าหมายสารเคมี"; $msg_type = "error";
    } else {
        $sql = "INSERT INTO quests (teacher_id, title, description, target_chem_id, assigned_class, difficulty, xp_reward, gold_reward, due_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ississiis", $teacher_id, $title, $desc, $target_chem, $class_level, $difficulty, $xp, $gold, $due_date);
            if ($stmt->execute()) {
                $msg = "✨ สร้างภารกิจสำเร็จ!"; $msg_type = "success";
            } else {
                $msg = "❌ SQL Error: " . $stmt->error; $msg_type = "error";
            }
            $stmt->close();
        } else {
            $msg = "❌ Prepare Error: " . $conn->error; $msg_type = "error";
        }
    }
}

// 4. ดึงข้อมูลสารเคมี
$chemicals = [];
$chem_res = $conn->query("SELECT * FROM chemicals ORDER BY name ASC");
if ($chem_res) {
    while ($row = $chem_res->fetch_assoc()) {
        $chemicals[] = $row;
    }
}

// 5. ดึงประวัติภารกิจ
$my_quests = [];
$q_sql = "SELECT q.*, c.name as chem_name, c.formula 
          FROM quests q 
          LEFT JOIN chemicals c ON q.target_chem_id = c.id 
          WHERE q.teacher_id = ? 
          ORDER BY q.created_at DESC";
$stmt = $conn->prepare($q_sql);
if ($stmt) {
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $my_quests[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mission Control - Alchemist Lab</title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;700&family=Cinzel:wght@700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #0f172a; color: #e2e8f0; margin:0; padding:20px; }
    .container { max-width: 1200px; margin: 0 auto; }
    .glass-panel { background: rgba(30, 41, 59, 0.6); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 25px; margin-bottom: 20px; }
    input, select, textarea { width: 100%; padding: 10px; margin-bottom: 15px; background: #1e293b; border: 1px solid #475569; border-radius: 8px; color: white; box-sizing: border-box; }
    .btn-submit { width: 100%; padding: 12px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(37,99,235,0.4); }
    .msg { padding: 15px; margin-bottom: 20px; border-radius: 8px; text-align: center; font-weight:bold; }
    .msg.success { background: #064e3b; color: #6ee7b7; border: 1px solid #059669; }
    .msg.error { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
    .header-box h1 { font-family: 'Cinzel', serif; color: #fbbf24; text-align: center; }
    .quest-item { background: rgba(255,255,255,0.05); padding: 15px; border-radius: 10px; margin-bottom: 10px; border-left: 4px solid #64748b; }
    .back-link { display: inline-block; margin-bottom: 15px; color: #94a3b8; text-decoration: none; font-weight: bold; }
    .back-link:hover { color: white; }
    .main-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 20px; }
    @media (max-width: 800px) { .main-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>

<div class="container">
    <a href="dashboard_teacher.php" class="back-link">⬅ กลับหน้า Dashboard</a>
    
    <div class="header-box">
        <h1>⚔️ Mission Control Center</h1>
    </div>

    <?php if ($msg): ?>
        <div class="msg <?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <div class="main-grid">
        <div class="glass-panel">
            <h3>📜 สร้างภารกิจใหม่</h3>
            <form method="post">
                <input type="hidden" name="action" value="create_quest">
                
                <label>ชื่อภารกิจ</label>
                <input type="text" name="title" required placeholder="เช่น ปรุงยาล่องหน">
                
                <label>รายละเอียด</label>
                <textarea name="description" rows="3" placeholder="คำอธิบายเพิ่มเติม..."></textarea>
                
                <label>เป้าหมายการผสม</label>
                <select name="target_chem_id" required>
                    <option value="">-- เลือกสารเคมี --</option>
                    <?php foreach($chemicals as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['name']) ?> 
                            <?= isset($c['formula']) ? "(".htmlspecialchars($c['formula']).")" : "" ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>ห้องเรียน</label>
                <input type="text" name="assigned_class" value="<?= htmlspecialchars($_SESSION['subject_group'] ?? 'ม.6/1') ?>" required>

                <label>ระดับความยาก</label>
                <select name="difficulty">
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                    <option value="legendary">Legendary</option>
                </select>

                <div style="display:flex; gap:10px;">
                    <div style="flex:1"><label>XP</label><input type="number" name="xp_reward" value="100"></div>
                    <div style="flex:1"><label>Gold</label><input type="number" name="gold_reward" value="50"></div>
                </div>

                <label>กำหนดส่ง</label>
                <input type="datetime-local" name="due_date">

                <button type="submit" class="btn-submit">🚀 สร้างภารกิจ</button>
            </form>
        </div>

        <div class="glass-panel">
            <h3>📚 ภารกิจปัจจุบัน</h3>
            <?php foreach($my_quests as $q): ?>
                <div class="quest-item">
                    <strong><?= htmlspecialchars($q['title']) ?></strong>
                    <br>
                    <small style="color:#94a3b8;">
                        เป้าหมาย: <?= htmlspecialchars($q['chem_name']) ?> | ห้อง: <?= htmlspecialchars($q['assigned_class']) ?>
                    </small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>