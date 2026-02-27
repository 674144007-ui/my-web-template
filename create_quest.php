<?php
// create_quest.php - ระบบสร้างภารกิจการทดลองสำหรับครู
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// เฉพาะครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$msg = "";
$msg_type = "";
$page_title = "สร้างภารกิจการทดลอง (Quest)";
$csrf = generate_csrf_token();
$teacher_id = $_SESSION['user_id'];

// ดึงรายชื่อสารเคมีทั้งหมดมาแสดงใน Dropdown
$chemicals = [];
$chem_query = $conn->query("SELECT id, name FROM chemicals ORDER BY name ASC");
if ($chem_query) {
    while ($row = $chem_query->fetch_assoc()) {
        $chemicals[] = $row;
    }
}

// ------------------------
// บันทึกภารกิจใหม่
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_quest') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $target_chem1 = intval($_POST['target_chem1'] ?? 0);
    $target_chem2 = intval($_POST['target_chem2'] ?? 0);
    $target_product = trim($_POST['target_product'] ?? '');
    $reward_points = intval($_POST['reward_points'] ?? 10);

    if (empty($title)) {
        $msg = "❌ กรุณากรอกชื่อภารกิจ";
        $msg_type = "error";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO quests (teacher_id, title, description, target_chem1, target_chem2, target_product, reward_points) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issiisi", $teacher_id, $title, $description, $target_chem1, $target_chem2, $target_product, $reward_points);

        if ($stmt->execute()) {
            $msg = "✔ สร้างภารกิจสำเร็จ! ให้นักเรียนเข้ามาทดลองได้เลย";
            $msg_type = "success";
            systemLog($teacher_id, 'CREATE_QUEST', "Created quest: $title");
        } else {
            $msg = "❌ เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $stmt->error;
            $msg_type = "error";
        }
        $stmt->close();
    }
}

// ดึงภารกิจที่ครูคนนี้สร้างไว้
$quests = [];
$q_stmt = $conn->prepare("SELECT * FROM quests WHERE teacher_id = ? ORDER BY created_at DESC");
$q_stmt->bind_param("i", $teacher_id);
$q_stmt->execute();
$res = $q_stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $quests[] = $row;
}
$q_stmt->close();

require_once 'header.php';
?>

<div style="display: flex; flex-wrap: wrap; gap: 20px;">
    
    <div class="card" style="flex: 1; min-width: 350px;">
        <h2>🎯 สร้างภารกิจห้องแล็บ (New Quest)</h2>
        <p style="color: #64748b;">ตั้งโจทย์ให้นักเรียนไขปริศนาทางเคมี</p>

        <?php if ($msg): ?>
            <div class="msg <?= h($msg_type) ?>"><?= h($msg) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_quest">

            <label>ชื่อภารกิจ (Quest Title) <span style="color:red">*</span></label>
            <input type="text" name="title" required placeholder="เช่น ปริศนาก๊าซลอยฟ้า">

            <label>คำใบ้ / รายละเอียดภารกิจ</label>
            <textarea name="description" rows="3" placeholder="จงผสมสารเคมีเพื่อให้เกิดก๊าซไฮโดรเจน..."></textarea>

            <label>สารเคมีเป้าหมายที่ 1 (ตัวแปรต้น - ไม่บังคับ)</label>
            <select name="target_chem1" style="width:100%; padding:10px; border-radius:8px;">
                <option value="0">-- ไม่ระบุ (ให้นักเรียนหาเอง) --</option>
                <?php foreach ($chemicals as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label style="margin-top:10px;">สารเคมีเป้าหมายที่ 2 (ตัวแปรตาม - ไม่บังคับ)</label>
            <select name="target_chem2" style="width:100%; padding:10px; border-radius:8px;">
                <option value="0">-- ไม่ระบุ (ให้นักเรียนหาเอง) --</option>
                <?php foreach ($chemicals as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label style="margin-top:10px;">ชื่อผลิตภัณฑ์ที่ต้องได้ (ผลลัพธ์การทำปฏิกิริยา)</label>
            <input type="text" name="target_product" placeholder="เช่น Hydrogen Gas">

            <label>คะแนนรางวัล (EXP)</label>
            <input type="number" name="reward_points" value="10" min="1" max="100" required>

            <button type="submit" class="btn-primary" style="width: 100%; background: #8b5cf6; margin-top: 15px;">
                ✨ สร้างภารกิจ
            </button>
        </form>
    </div>

    <div class="card" style="flex: 1.5; min-width: 400px;">
        <h2>📜 ภารกิจของคุณ</h2>
        
        <?php if (count($quests) > 0): ?>
            <div style="display: grid; gap: 15px;">
                <?php foreach ($quests as $q): ?>
                    <div style="border: 1px solid #e2e8f0; padding: 15px; border-radius: 12px; background: #f8fafc; border-left: 5px solid #8b5cf6;">
                        <h3 style="margin: 0 0 5px 0; color: #0f172a;"><?= h($q['title']) ?> <span style="font-size: 0.8em; background:#f59e0b; color:white; padding: 2px 8px; border-radius: 20px;">+<?= $q['reward_points'] ?> EXP</span></h3>
                        <p style="margin: 0; font-size: 0.9em; color: #64748b;"><?= h($q['description']) ?></p>
                        <div style="margin-top: 10px; font-size: 0.85em; color: #475569;">
                            <strong>เป้าหมายผลลัพธ์:</strong> <?= $q['target_product'] ? h($q['target_product']) : 'ไม่ระบุ' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color: #64748b; text-align: center; padding: 30px;">คุณยังไม่ได้สร้างภารกิจใดๆ</p>
        <?php endif; ?>
    </div>

</div>

<?php require_once 'footer.php'; ?>