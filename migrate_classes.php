<?php
/**
 * migrate_classes.php - ระบบโอนย้ายข้อมูลโครงสร้างชั้นเรียน (Phase 5: Data Migration & Cleanup)
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */

require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// สงวนสิทธิ์เฉพาะ Developer และ Admin เท่านั้น (ฟังก์ชันนี้อันตรายหากให้คนอื่นเข้าถึง)
requireRole(['developer', 'admin']);

$page_title = "โอนย้ายข้อมูลชั้นเรียน (Data Migration)";
$csrf = generate_csrf_token();

$migration_results = null;

// =========================================================================
// 🔍 1. PRE-FLIGHT CHECK (ตรวจสอบข้อมูลก่อนโอนย้าย)
// =========================================================================

// นับจำนวนนักเรียนที่ยังไม่ได้ผูก class_id แต่อยู่ในระบบเก่า (มี class_level)
$stmt_pending = $conn->query("SELECT COUNT(id) as pending_count FROM users WHERE role = 'student' AND class_id IS NULL AND class_level IS NOT NULL AND class_level != '' AND is_deleted = 0");
$pending_users = $stmt_pending->fetch_assoc()['pending_count'];

// นับจำนวนนักเรียนที่ผูก class_id เรียบร้อยแล้ว (ระบบใหม่)
$stmt_completed = $conn->query("SELECT COUNT(id) as completed_count FROM users WHERE role = 'student' AND class_id IS NOT NULL AND is_deleted = 0");
$completed_users = $stmt_completed->fetch_assoc()['completed_count'];

// =========================================================================
// 🚀 2. MIGRATION ENGINE (เอนจินประมวลผลการโอนย้าย)
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    
    // ตรวจสอบความปลอดภัย CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("ระบบความปลอดภัยปฏิเสธการทำงาน (CSRF Mismatch)");
    }

    $success_count = 0;
    $error_count = 0;
    $auto_class_count = 0;
    $action_logs = [];

    // ดึงนักเรียนที่ต้องโอนย้ายทั้งหมด
    $sql_get_users = "SELECT id, username, display_name, class_level FROM users WHERE role = 'student' AND class_id IS NULL AND class_level IS NOT NULL AND class_level != '' AND is_deleted = 0";
    $result_users = $conn->query($sql_get_users);

    if ($result_users && $result_users->num_rows > 0) {
        
        // เตรียม Prepared Statements เพื่อความรวดเร็วและปลอดภัยใน Loop
        $stmt_check_class = $conn->prepare("SELECT id FROM classes WHERE level = ? AND room = ? LIMIT 1");
        $stmt_insert_class = $conn->prepare("INSERT INTO classes (class_name, level, room, is_active) VALUES (?, ?, ?, 1)");
        $stmt_update_user = $conn->prepare("UPDATE users SET class_id = ? WHERE id = ?");

        // เริ่ม Transaction เพื่อป้องกันข้อมูลพังกลางคัน
        $conn->begin_transaction();

        try {
            while ($user = $result_users->fetch_assoc()) {
                $user_id = $user['id'];
                $old_level_text = trim($user['class_level']);
                $username = $user['username'];

                // 🧠 SMART PARSER: แยกระดับชั้นและห้องออกจากข้อความเก่า (เช่น "ม.1/1" หรือ "ป.6 / 2")
                $level = '';
                $room = 0;

                // ใช้ Regex จับรูปแบบ "ตัวอักษร" ตามด้วย "เครื่องหมาย / หรือช่องว่าง" ตามด้วย "ตัวเลข"
                if (preg_match('/^(.*?)\s*\/?\s*([0-9]+)$/u', $old_level_text, $matches)) {
                    $level = trim($matches[1]);
                    $room = intval(trim($matches[2]));
                } else {
                    // กรณีที่เขียนมาแปลกๆ เช่น "ม.1" เฉยๆ ไม่มีทับห้อง
                    $parts = explode('/', $old_level_text);
                    if (count($parts) >= 2) {
                        $level = trim($parts[0]);
                        $room = intval(trim($parts[1]));
                    } else {
                        $level = trim($old_level_text);
                        $room = 1; // อนุโลมให้เป็นห้อง 1 ไปก่อน หากไม่ได้ระบุ
                    }
                }

                // หากสกัด Level ไม่ได้ ให้ข้ามไปและบันทึก Error
                if (empty($level) || $room <= 0) {
                    $error_count++;
                    $action_logs[] = "⚠️ ข้าม: ผู้ใช้ {$username} มีรูปแบบชั้นเรียนเก่าที่อ่านไม่ได้ ({$old_level_text})";
                    continue;
                }

                // 🔍 ค้นหาห้องเรียนในระบบใหม่
                $target_class_id = null;
                $stmt_check_class->bind_param("si", $level, $room);
                $stmt_check_class->execute();
                $res_class = $stmt_check_class->get_result();

                if ($res_class->num_rows > 0) {
                    // เจอห้องนี้ในระบบแล้ว
                    $target_class_id = $res_class->fetch_assoc()['id'];
                } else {
                    // ไม่เจอห้องนี้ -> 🏗️ สร้างห้องใหม่ให้อัตโนมัติ (Auto-Create)
                    $new_class_name = "{$level}/{$room}";
                    $stmt_insert_class->bind_param("ssi", $new_class_name, $level, $room);
                    if ($stmt_insert_class->execute()) {
                        $target_class_id = $stmt_insert_class->insert_id;
                        $auto_class_count++;
                        $action_logs[] = "✨ สร้างห้องเรียนใหม่อัตโนมัติ: {$new_class_name}";
                    } else {
                        $error_count++;
                        $action_logs[] = "❌ ล้มเหลว: ไม่สามารถสร้างห้อง {$new_class_name} ให้ {$username} ได้";
                        continue;
                    }
                }

                // 💾 อัปเดตข้อมูลนักเรียนให้ผูกกับ ID ห้องเรียนใหม่
                if ($target_class_id !== null) {
                    $stmt_update_user->bind_param("ii", $target_class_id, $user_id);
                    if ($stmt_update_user->execute()) {
                        $success_count++;
                        // ปิดการแจ้งเตือนรายคนเพื่อไม่ให้ Log ยาวเกินไป แต่สามารถเปิดได้ถ้าต้องการ
                        // $action_logs[] = "✅ โอนย้าย {$username} ไปยังห้อง ID:{$target_class_id} สำเร็จ";
                    } else {
                        $error_count++;
                        $action_logs[] = "❌ ล้มเหลว: ไม่สามารถอัปเดตข้อมูลให้ {$username} ได้";
                    }
                }
            } // End While

            // ยืนยันการเปลี่ยนแปลงข้อมูล
            $conn->commit();

            $migration_results = [
                'status' => 'success',
                'success_count' => $success_count,
                'error_count' => $error_count,
                'auto_class_count' => $auto_class_count,
                'logs' => $action_logs
            ];

            // อัปเดตตัวเลข Pre-flight ใหม่เพื่อแสดงผลหลังทำเสร็จ
            $stmt_pending = $conn->query("SELECT COUNT(id) as pending_count FROM users WHERE role = 'student' AND class_id IS NULL AND class_level IS NOT NULL AND class_level != '' AND is_deleted = 0");
            $pending_users = $stmt_pending->fetch_assoc()['pending_count'];
            $stmt_completed = $conn->query("SELECT COUNT(id) as completed_count FROM users WHERE role = 'student' AND class_id IS NOT NULL AND is_deleted = 0");
            $completed_users = $stmt_completed->fetch_assoc()['completed_count'];

        } catch (Exception $e) {
            $conn->rollback();
            $migration_results = [
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดร้ายแรง ระบบได้ทำการ Rollback ข้อมูลทั้งหมดกลับสู่สภาพเดิม: ' . $e->getMessage()
            ];
        } finally {
            $stmt_check_class->close();
            $stmt_insert_class->close();
            $stmt_update_user->close();
        }
    } else {
        $migration_results = [
            'status' => 'info',
            'message' => 'ไม่มีข้อมูลนักเรียนเก่าที่ต้องโอนย้าย ทุกอย่างเป็นระบบใหม่ 100% แล้ว! 🎉'
        ];
    }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">

<style>
    /* ============================================================
       🎨 CSS STYLING FOR MIGRATION DASHBOARD
       ============================================================ */
    body { background-color: #f8fafc; font-family: 'Sarabun', sans-serif; }
    .migration-container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
    
    .page-header { background: linear-gradient(135deg, #1e293b, #0f172a); color: white; padding: 30px 40px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: space-between; }
    .page-header h1 { margin: 0; font-size: 2.2rem; color: #38bdf8; font-weight: bold; }
    .page-header p { margin: 10px 0 0 0; font-size: 1.1rem; color: #94a3b8; }
    .header-icon { font-size: 4rem; filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.5)); animation: float 3s ease-in-out infinite; }
    
    @keyframes float { 0% { transform: translateY(0px); } 50% { transform: translateY(-10px); } 100% { transform: translateY(0px); } }

    /* Dashboard Cards */
    .dashboard-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; }
    
    .stat-card { background: white; border-radius: 16px; padding: 25px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; overflow: hidden; }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 5px; }
    .stat-card.warning::before { background: #f59e0b; }
    .stat-card.success::before { background: #10b981; }
    
    .stat-title { font-size: 1.1rem; font-weight: bold; color: #64748b; margin-bottom: 10px; }
    .stat-number { font-size: 4rem; font-family: 'Orbitron', sans-serif; font-weight: bold; line-height: 1; margin-bottom: 5px; }
    .stat-card.warning .stat-number { color: #f59e0b; }
    .stat-card.success .stat-number { color: #10b981; }

    /* Migration Section */
    .action-card { background: white; border-radius: 16px; padding: 40px; border: 1px solid #e2e8f0; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; margin-bottom: 30px; }
    .action-icon { font-size: 3rem; margin-bottom: 20px; display: block; }
    .action-title { font-size: 1.5rem; font-weight: bold; color: #1e293b; margin-bottom: 15px; }
    .action-desc { color: #64748b; font-size: 1.1rem; margin-bottom: 30px; line-height: 1.6; max-width: 700px; margin-left: auto; margin-right: auto; }

    .btn-run { background: linear-gradient(135deg, #ef4444, #b91c1c); color: white; border: none; padding: 18px 40px; border-radius: 12px; font-size: 1.3rem; font-weight: bold; cursor: pointer; box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4); transition: 0.3s; display: inline-flex; align-items: center; gap: 10px; }
    .btn-run:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(239, 68, 68, 0.5); }
    .btn-run:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }
    .btn-run.disabled-safe { background: #10b981; pointer-events: none; box-shadow: none; }

    /* Results Log */
    .results-panel { background: #0f172a; border-radius: 16px; padding: 30px; box-shadow: 0 15px 40px rgba(0,0,0,0.2); animation: slideUp 0.5s ease; border: 2px solid #38bdf8; }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    
    .results-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #334155; padding-bottom: 15px; margin-bottom: 20px; }
    .results-header h2 { margin: 0; color: #f8fafc; font-size: 1.5rem; }
    
    .result-stats { display: flex; gap: 20px; margin-bottom: 20px; }
    .r-stat { background: #1e293b; padding: 10px 20px; border-radius: 8px; font-family: 'Share Tech Mono', monospace; font-size: 1.1rem; border: 1px solid #334155; }
    .r-stat span { font-weight: bold; font-size: 1.3rem; }
    .rs-success span { color: #4ade80; }
    .rs-error span { color: #f87171; }
    .rs-auto span { color: #38bdf8; }

    .terminal-log { background: #020617; padding: 20px; border-radius: 8px; font-family: 'Share Tech Mono', monospace; color: #cbd5e1; font-size: 0.9rem; max-height: 300px; overflow-y: auto; border: 1px solid #1e293b; }
    .log-line { margin-bottom: 5px; padding-bottom: 5px; border-bottom: 1px solid #0f172a; }
</style>

<div class="migration-container">
    <div class="page-header">
        <div>
            <h1>📦 Data Migration & Cleanup</h1>
            <p>เครื่องมือโอนย้ายและจัดระเบียบโครงสร้างชั้นเรียนอัตโนมัติ (Phase 5)</p>
        </div>
        <div class="header-icon">🔄</div>
    </div>

    <div class="dashboard-grid">
        <div class="stat-card warning">
            <div class="stat-title">⏳ นักเรียนที่ตกค้างในระบบเก่า (รอโอนย้าย)</div>
            <div class="stat-number"><?= number_format($pending_users) ?></div>
            <div style="color: #64748b; font-size: 0.9rem;">ผู้ใช้งานที่มี Text Class แต่ไม่มี Class ID</div>
        </div>
        <div class="stat-card success">
            <div class="stat-title">✅ นักเรียนในระบบใหม่ (สมบูรณ์)</div>
            <div class="stat-number"><?= number_format($completed_users) ?></div>
            <div style="color: #64748b; font-size: 0.9rem;">ผู้ใช้งานที่มี Class ID ผูกกับโครงสร้างเรียบร้อย</div>
        </div>
    </div>

    <?php if ($migration_results): ?>
        <?php if ($migration_results['status'] === 'error' || $migration_results['status'] === 'info'): ?>
            <div style="background: <?= $migration_results['status'] === 'error' ? '#fee2e2' : '#e0f2fe' ?>; color: <?= $migration_results['status'] === 'error' ? '#991b1b' : '#0369a1' ?>; padding: 20px; border-radius: 12px; margin-bottom: 30px; font-size: 1.1rem; font-weight: bold; border: 1px solid <?= $migration_results['status'] === 'error' ? '#fca5a5' : '#bae6fd' ?>; text-align:center;">
                <?= htmlspecialchars($migration_results['message']) ?>
            </div>
        <?php else: ?>
            <div class="results-panel">
                <div class="results-header">
                    <h2>📊 สรุปผลการประมวลผล (Migration Report)</h2>
                    <a href="user_manager.php" style="background:#3b82f6; color:white; padding:8px 15px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.9rem;">ไปหน้ารายชื่อผู้ใช้ ▶</a>
                </div>
                
                <div class="result-stats">
                    <div class="r-stat rs-success">✅ สำเร็จ: <span><?= $migration_results['success_count'] ?></span></div>
                    <div class="r-stat rs-error">❌ ล้มเหลว/ข้าม: <span><?= $migration_results['error_count'] ?></span></div>
                    <div class="r-stat rs-auto">✨ สร้างห้องอัตโนมัติ: <span><?= $migration_results['auto_class_count'] ?></span></div>
                </div>

                <?php if (count($migration_results['logs']) > 0): ?>
                    <div class="terminal-log">
                        <?php foreach ($migration_results['logs'] as $log): ?>
                            <div class="log-line">> <?= htmlspecialchars($log) ?></div>
                        <?php endforeach; ?>
                        <div class="log-line">> Process Completed. Database is synced.</div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="action-card">
        <span class="action-icon">⚙️</span>
        <h2 class="action-title">ระบบสกัดข้อมูลและเชื่อมโยงอัตโนมัติ (Smart Relational Linker)</h2>
        <p class="action-desc">
            ระบบจะทำการสแกนรายชื่อนักเรียนที่ตกค้าง อ่านค่าจากข้อความ (เช่น "ม.1/1") <br>
            แล้วนำไปผูกกับตาราง <strong>Classes</strong> หากระบบไม่พบห้องเรียนดังกล่าว ระบบจะ <strong>สร้างห้องเรียนใหม่ให้โดยอัตโนมัติ</strong> เพื่อป้องกันข้อมูลสูญหาย
        </p>

        <?php if ($pending_users > 0): ?>
            <form method="POST" action="migrate_classes.php" id="migrationForm" onsubmit="return confirmRun()">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="run_migration" value="1">
                <button type="submit" class="btn-run" id="btnRun">
                    ▶️ เริ่มกระบวนการโอนย้ายข้อมูลทันที!
                </button>
            </form>
        <?php else: ?>
            <button class="btn-run disabled-safe" disabled>
                🎉 ข้อมูลสมบูรณ์ 100% ไม่มีรายการต้องโอนย้าย
            </button>
        <?php endif; ?>
    </div>

</div>

<script>
    function confirmRun() {
        const isConfirmed = confirm("⚠️ คำเตือน: กระบวนการนี้จะทำการเปลี่ยนโครงสร้างข้อมูลในฐานข้อมูล\n\nโปรดตรวจสอบให้แน่ใจว่าไม่ได้ใช้งานระบบในช่วงที่มีการอัปเดต\nคุณต้องการดำเนินการต่อใช่หรือไม่?");
        if (isConfirmed) {
            const btn = document.getElementById('btnRun');
            btn.disabled = true;
            btn.innerHTML = '⏳ กำลังประมวลผลฐานข้อมูล โปรดรอสักครู่... (ห้ามปิดหน้านี้)';
            return true;
        }
        return false;
    }
</script>

<?php require_once 'footer.php'; ?>