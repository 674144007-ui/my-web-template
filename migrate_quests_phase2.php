<?php
/**
 * migrate_quests_phase2.php
 * * สคริปต์สำหรับผ่าตัดฐานข้อมูล (Database Surgery) ระยะที่ 2
 * หน้าที่หลัก:
 * 1. ตรวจสอบว่ามีตาราง lab_quests หลงเหลืออยู่หรือไม่
 * 2. ย้ายข้อมูลทั้งหมดจาก lab_quests ไปยังตาราง quests
 * 3. อัปเดตตารางที่เกี่ยวข้อง (ถ้ามี)
 * 4. ลบ (DROP) ตาราง lab_quests ทิ้งถาวรเพื่อจบปัญหาโครงสร้างซ้ำซ้อน
 */

require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับว่าต้องเป็น Developer เท่านั้นถึงจะรันสคริปต์นี้ได้ เพื่อความปลอดภัยของฐานข้อมูล
requireRole(['developer']);

$page_title = "Database Surgery - Phase 2 Migration";
$csrf = generate_csrf_token();
$developer_id = $_SESSION['user_id'];

$logs = [];
$status = 'idle'; // idle, success, error

// ฟังก์ชันสำหรับเพิ่ม Log เพื่อแสดงบนหน้าจอ
function addStepLog(&$logs, $message, $type = 'info') {
    $time = date('H:i:s');
    $logs[] = ['time' => $time, 'message' => $message, 'type' => $type];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ Security Error: CSRF Token ไม่ถูกต้อง");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'start_migration') {
        $status = 'processing';
        addStepLog($logs, "เริ่มกระบวนการ Database Surgery (Phase 2)...", "info");

        // ปิด Auto-commit เพื่อใช้ Transaction ป้องกันข้อมูลพังกลางคัน
        $conn->autocommit(FALSE);

        try {
            // 1. ตรวจสอบว่าตาราง lab_quests ยังมีอยู่หรือไม่
            addStepLog($logs, "กำลังตรวจสอบตาราง 'lab_quests'...", "info");
            $check_table = $conn->query("SHOW TABLES LIKE 'lab_quests'");
            
            if ($check_table->num_rows === 0) {
                throw new Exception("ไม่พบตาราง 'lab_quests' ในฐานข้อมูล (อาจถูกลบไปแล้วหรือรันสคริปต์นี้ไปแล้ว)");
            }
            addStepLog($logs, "พบตาราง 'lab_quests' เตรียมดึงข้อมูล", "success");

            // 2. ดึงข้อมูลทั้งหมดจาก lab_quests
            $res = $conn->query("SELECT * FROM lab_quests");
            $total_rows = $res->num_rows;
            addStepLog($logs, "พบข้อมูลภารกิจเก่าจำนวน {$total_rows} รายการ", "info");

            $migrated_count = 0;

            if ($total_rows > 0) {
                // เตรียมคำสั่ง Insert ลงตาราง quests
                // ข้อสังเกต: เราจะไม่ใส่ target_chem1 เพราะของเก่าไม่มี แต่ระบบหน้าบ้านระยะ 1 ป้องกันเควสขยะไว้แล้ว 
                // (เควสที่ target_chem1 เป็น NULL จะไม่แสดงให้นักเรียนเห็นจนกว่าครูจะเข้าไปแก้ไข)
                $stmt_insert = $conn->prepare("
                    INSERT INTO quests 
                    (teacher_id, title, description, reward_points, target_class_id, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmt_insert) {
                    throw new Exception("SQL Prepare Error: " . $conn->error);
                }

                while ($row = $res->fetch_assoc()) {
                    $teacher_id = $row['teacher_id'];
                    $title = $row['title'] . " [รอระบุสารเคมี]"; // เติม Tag ให้ครูรู้ว่าต้องเข้ามาแก้
                    $description = $row['description'];
                    $reward_points = $row['bonus_xp']; // แปลงชื่อฟิลด์จาก bonus_xp เป็น reward_points
                    $target_class_id = $row['target_class_id'];
                    $is_active = 0; // ปิดการใช้งานไว้ก่อน เพื่อบังคับให้ครูเข้าไปอัปเดตสารเคมี
                    $created_at = $row['created_at'];

                    $stmt_insert->bind_param(
                        "issiiis", 
                        $teacher_id, $title, $description, $reward_points, 
                        $target_class_id, $is_active, $created_at
                    );

                    if (!$stmt_insert->execute()) {
                        throw new Exception("Insert Error (Row ID: {$row['id']}): " . $stmt_insert->error);
                    }
                    $migrated_count++;
                }
                $stmt_insert->close();
                addStepLog($logs, "ย้ายข้อมูลสำเร็จ {$migrated_count} รายการ สู่ตาราง 'quests'", "success");
            }

            // 3. ทำการลบตาราง (DROP TABLE)
            addStepLog($logs, "กำลังทำลายตาราง 'lab_quests'...", "warning");
            if (!$conn->query("DROP TABLE lab_quests")) {
                throw new Exception("ไม่สามารถ Drop ตารางได้: " . $conn->error);
            }
            addStepLog($logs, "ลบตาราง 'lab_quests' สำเร็จ โครงสร้างฐานข้อมูลสะอาดเรียบร้อย", "success");

            // 4. บันทึก Log การกระทำของ Developer
            systemLog($developer_id, 'SYSTEM_MIGRATION', "Migrated {$migrated_count} quests and dropped 'lab_quests' table.");

            // 5. Commit ยืนยันการเปลี่ยนแปลง
            $conn->commit();
            $status = 'success';
            addStepLog($logs, "🎉 กระบวนการผ่าตัดฐานข้อมูลเสร็จสมบูรณ์ 100%!", "success");

        } catch (Exception $e) {
            // หากมี Error ให้ Rollback กลับไปเหมือนไม่มีอะไรเกิดขึ้น
            $conn->rollback();
            $status = 'error';
            addStepLog($logs, "🚨 ข้อผิดพลาด: " . $e->getMessage(), "error");
            addStepLog($logs, "ทำการ Rollback ฐานข้อมูลกลับสู่สถานะเดิมเพื่อความปลอดภัย", "warning");
        }

        // เปิด Auto-commit กลับคืนมา
        $conn->autocommit(TRUE);
    }
}

// ตรวจสอบสถานะปัจจุบันว่ายังมีตารางหลงเหลืออยู่ไหม
$table_exists = false;
$check = $conn->query("SHOW TABLES LIKE 'lab_quests'");
if ($check && $check->num_rows > 0) {
    $table_exists = true;
    $count_res = $conn->query("SELECT COUNT(*) as c FROM lab_quests");
    $old_records = $count_res->fetch_assoc()['c'];
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
    body {
        background-color: #020617;
        color: #f8fafc;
        font-family: 'Sarabun', sans-serif;
        margin: 0;
        padding: 0;
    }
    .migration-container {
        max-width: 900px;
        margin: 50px auto;
        padding: 30px;
        background: #0f172a;
        border: 1px solid #334155;
        border-radius: 15px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.5);
    }
    .header-title {
        color: #38bdf8;
        font-family: 'Share Tech Mono', monospace;
        font-size: 2rem;
        margin-top: 0;
        display: flex;
        align-items: center;
        gap: 15px;
        border-bottom: 2px solid #1e293b;
        padding-bottom: 20px;
    }
    .status-panel {
        background: #1e293b;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        border-left: 5px solid #64748b;
    }
    .status-panel.ready { border-left-color: #3b82f6; }
    .status-panel.done { border-left-color: #22c55e; }
    
    .btn-migrate {
        background: linear-gradient(135deg, #ef4444, #b91c1c);
        color: white;
        border: none;
        padding: 15px 30px;
        font-size: 1.2rem;
        font-weight: bold;
        border-radius: 10px;
        cursor: pointer;
        font-family: 'Sarabun', sans-serif;
        box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);
        transition: 0.3s;
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
        justify-content: center;
    }
    .btn-migrate:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 25px rgba(239, 68, 68, 0.5);
    }
    .btn-migrate:disabled {
        background: #475569;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
    }

    .terminal-box {
        background: #000000;
        border: 1px solid #334155;
        border-radius: 10px;
        padding: 20px;
        font-family: 'Share Tech Mono', monospace;
        height: 300px;
        overflow-y: auto;
        margin-top: 25px;
        box-shadow: inset 0 0 20px rgba(0,0,0,0.8);
    }
    .log-line { margin-bottom: 8px; font-size: 0.95rem; line-height: 1.5; }
    .log-time { color: #64748b; margin-right: 10px; }
    .log-info { color: #38bdf8; }
    .log-success { color: #22c55e; }
    .log-warning { color: #f59e0b; }
    .log-error { color: #ef4444; font-weight: bold; }

    .btn-dashboard {
        display: block;
        text-align: center;
        background: #334155;
        color: white;
        text-decoration: none;
        padding: 15px;
        border-radius: 10px;
        margin-top: 20px;
        font-weight: bold;
        transition: 0.3s;
    }
    .btn-dashboard:hover { background: #475569; }
</style>

<div class="migration-container">
    <h1 class="header-title">
        <span>⚙️</span> Database Surgery (Phase 2)
    </h1>

    <div class="status-panel <?= $table_exists ? 'ready' : 'done' ?>">
        <h3 style="margin-top:0; color:#f8fafc;">สถานะโครงสร้างฐานข้อมูลปัจจุบัน:</h3>
        <?php if ($table_exists): ?>
            <p style="color:#cbd5e1; font-size:1.1rem; margin-bottom:5px;">
                ⚠️ ตรวจพบตาราง <strong style="color:#ef4444;">lab_quests</strong> ที่ซ้ำซ้อน
            </p>
            <p style="color:#94a3b8; margin-top:0;">
                จำนวนข้อมูลที่รอการย้าย: <strong><?= $old_records ?> รายการ</strong>
            </p>
            
            <form method="POST" onsubmit="return confirm('⚠️ คำเตือน: การกระทำนี้ไม่สามารถย้อนกลับได้ คุณแน่ใจหรือไม่ที่จะทำการผ่าตัดฐานข้อมูลและ Drop Table?');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="start_migration">
                <button type="submit" class="btn-migrate">
                    🚀 เริ่มการย้ายข้อมูลและลบตารางเก่า (Execute Migration)
                </button>
            </form>
        <?php else: ?>
            <p style="color:#4ade80; font-size:1.2rem; font-weight:bold; margin-bottom:0;">
                ✅ โครงสร้างฐานข้อมูลสะอาดเรียบร้อย (ไม่พบตารางซ้ำซ้อน)
            </p>
            <p style="color:#94a3b8; margin-top:5px;">
                ระบบพร้อมใช้งานร่วมกับโครงสร้างใหม่ (Phase 7) อย่างสมบูรณ์
            </p>
        <?php endif; ?>
    </div>

    <div class="terminal-box" id="terminalBox">
        <div class="log-line">
            <span class="log-time">[<?= date('H:i:s') ?>]</span>
            <span class="log-info">SYSTEM_READY: รอคำสั่งเริ่มกระบวนการ...</span>
        </div>
        
        <?php foreach ($logs as $log): ?>
            <div class="log-line">
                <span class="log-time">[<?= htmlspecialchars($log['time']) ?>]</span>
                <span class="log-<?= htmlspecialchars($log['type']) ?>">
                    <?= htmlspecialchars($log['message']) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <a href="dashboard_dev.php" class="btn-dashboard">⬅ กลับสู่หน้า Dashboard ผู้ดูแลระบบ</a>
</div>

<script>
    // เลื่อน Terminal ลงไปบรรทัดล่าสุดเสมอ
    const terminal = document.getElementById('terminalBox');
    terminal.scrollTop = terminal.scrollHeight;
</script>

<?php require_once 'footer.php'; ?>