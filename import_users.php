<?php
// import_users.php - ระบบนำเข้าข้อมูลผู้ใช้งานจากไฟล์ CSV (Phase 4: Bulk Operations & Validation)
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'security.php'; // โหลดระบบเข้ารหัสผ่านใหม่จาก Phase 1

// ตรวจสอบสิทธิ์ (เฉพาะผู้ดูแลระบบ)
requireRole(['developer']);

$page_title = "นำเข้าข้อมูลผู้ใช้งาน (Import Users) - Phase 4";
require_once 'header.php';

$upload_result = null;
$import_logs = [];
$success_count = 0;
$error_count = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
    // ตรวจสอบ CSRF Token
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $file = $_FILES['csv_file'];
    
    // ตรวจสอบข้อผิดพลาดในการอัปโหลด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_result = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error Code: ' . $file['error'] . ')'];
    } else {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext != 'csv') {
            $upload_result = ['status' => 'error', 'message' => 'กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้น'];
        } else {
            // เปิดไฟล์ CSV เพื่ออ่านข้อมูล
            $handle = fopen($file['tmp_name'], "r");
            if ($handle !== FALSE) {
                // ข้ามบรรทัดแรก (Header)
                $header = fgetcsv($handle, 1000, ",");
                
                // เตรียม Statement สำหรับเช็ค Username ซ้ำ
                $stmt_check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                
                // เตรียม Statement สำหรับ Insert
                $stmt_insert = $pdo->prepare("INSERT INTO users (username, password, display_name, role, class_id, parent_of, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                
                $row_number = 1; // เริ่มนับบรรทัดข้อมูล (ไม่รวม Header)
                
                // เริ่ม Transaction เพื่อความปลอดภัย
                $pdo->beginTransaction();
                
                try {
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_number++;
                        
                        // คาดหวังโครงสร้าง CSV: [0]username, [1]password, [2]display_name, [3]role, [4]class_id, [5]parent_of
                        $username = trim($data[0] ?? '');
                        $raw_password = trim($data[1] ?? '');
                        $display_name = trim($data[2] ?? '');
                        $role = strtolower(trim($data[3] ?? 'student'));
                        $class_id = trim($data[4] ?? '');
                        $parent_of = trim($data[5] ?? '');
                        
                        // 1. ตรวจสอบข้อมูลบังคับ
                        if (empty($username) || empty($raw_password) || empty($display_name)) {
                            $import_logs[] = ['row' => $row_number, 'status' => 'error', 'msg' => "ข้อมูลไม่ครบถ้วน (Username, Password, Name ห้ามว่าง)"];
                            $error_count++;
                            continue;
                        }
                        
                        // 2. ตรวจสอบ Role
                        $valid_roles = ['student', 'teacher', 'parent', 'developer'];
                        if (!in_array($role, $valid_roles)) {
                            $import_logs[] = ['row' => $row_number, 'status' => 'error', 'msg' => "สิทธิ์ผู้ใช้งาน (Role) ไม่ถูกต้อง ('$role')"];
                            $error_count++;
                            continue;
                        }
                        
                        // 3. ตรวจสอบว่า Username ซ้ำหรือไม่
                        $stmt_check->execute([$username]);
                        if ($stmt_check->fetch()) {
                            $import_logs[] = ['row' => $row_number, 'status' => 'error', 'msg' => "ชื่อผู้ใช้ '$username' มีในระบบแล้ว"];
                            $error_count++;
                            continue;
                        }
                        
                        // จัดการค่า Null ของ Foreign Keys
                        $final_class_id = (is_numeric($class_id) && $class_id > 0) ? intval($class_id) : null;
                        $final_parent_of = (is_numeric($parent_of) && $parent_of > 0) ? intval($parent_of) : null;
                        
                        // เข้ารหัสรหัสผ่านขั้นสูง (ใช้ฟังก์ชันจาก security.php)
                        $hashed_password = generate_secure_password($raw_password);
                        
                        // นำเข้าข้อมูล
                        $stmt_insert->execute([$username, $hashed_password, $display_name, $role, $final_class_id, $final_parent_of]);
                        $success_count++;
                        $import_logs[] = ['row' => $row_number, 'status' => 'success', 'msg' => "นำเข้า '$display_name' สำเร็จ"];
                    }
                    
                    // หากมีข้อผิดพลาดเกิน 50% ให้ยกเลิกการนำเข้าทั้งหมด (Rollback) เพื่อป้องกันข้อมูลเละ
                    if ($row_number > 1 && ($error_count / ($row_number - 1)) > 0.5) {
                        $pdo->rollBack();
                        $upload_result = ['status' => 'error', 'message' => 'พบข้อผิดพลาดเกิน 50% ของข้อมูลทั้งหมด ระบบได้ยกเลิกการนำเข้าทั้งหมดเพื่อความปลอดภัย กรุณาแก้ไขไฟล์ CSV แล้วลองใหม่'];
                        $success_count = 0; // รีเซ็ตเพราะโดน Rollback
                    } else {
                        $pdo->commit(); // บันทึกข้อมูลถาวร
                        
                        // บันทึก Log เข้าระบบ
                        $stmt_sys_log = $pdo->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, 'IMPORT_USERS', ?, NOW())");
                        $stmt_sys_log->execute([$_SESSION['user_id'], "นำเข้าสำเร็จ: $success_count รายการ, ล้มเหลว: $error_count รายการ"]);
                        
                        $upload_result = ['status' => 'success', 'message' => "ดำเนินการเสร็จสิ้น! นำเข้าสำเร็จ $success_count รายการ, ล้มเหลว $error_count รายการ"];
                    }
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $upload_result = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดระดับฐานข้อมูล: ' . $e->getMessage()];
                }
                
                fclose($handle);
            } else {
                $upload_result = ['status' => 'error', 'message' => 'ไม่สามารถเปิดไฟล์เพื่ออ่านข้อมูลได้'];
            }
        }
    }
}
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; color: #0f172a; font-family: 'Prompt', sans-serif; }
    .import-container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
    .panel { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; margin-bottom: 30px; }
    .panel h2 { margin-top: 0; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; display: flex; align-items: center; gap: 10px; }
    
    .upload-zone { border: 2px dashed #3b82f6; border-radius: 12px; padding: 40px 20px; text-align: center; background: rgba(59, 130, 246, 0.05); transition: 0.3s; cursor: pointer; }
    .upload-zone:hover { background: rgba(59, 130, 246, 0.1); border-color: #2563eb; }
    .upload-icon { font-size: 3rem; color: #3b82f6; margin-bottom: 15px; }
    
    input[type="file"] { display: none; }
    
    .btn-submit { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; font-size: 1.1rem; cursor: pointer; transition: 0.3s; margin-top: 20px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4); display: none; }
    .btn-submit.show { display: inline-block; }
    .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5); }
    
    .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: bold; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid #10b981; }
    .alert-error { background: rgba(239, 68, 68, 0.1); color: #b91c1c; border: 1px solid #ef4444; }
    
    .log-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; margin-top: 20px; }
    .log-table th, .log-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    .log-table th { background: #f1f5f9; color: #475569; font-weight: 600; }
    .log-table tr:hover { background: #f8fafc; }
    .status-badge { padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; }
    .badge-success { background: rgba(16, 185, 129, 0.2); color: #059669; }
    .badge-error { background: rgba(239, 68, 68, 0.2); color: #b91c1c; }
    
    .instruction-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; margin-top: 20px; font-size: 0.95rem; color: #475569; }
    .code-style { font-family: 'Share Tech Mono', monospace; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; color: #0f172a; }
</style>

<div class="import-container">
    
    <?php if ($upload_result): ?>
        <div class="alert alert-<?= $upload_result['status'] ?>">
            <span><?= $upload_result['status'] === 'success' ? '✅' : '❌' ?></span>
            <?= h($upload_result['message']) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>📥 นำเข้าผู้ใช้งานด้วยไฟล์ CSV (Bulk Import)</h2>
        
        <form action="import_users.php" method="POST" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            
            <label class="upload-zone" id="dropZone" for="csv_file">
                <div class="upload-icon">📄</div>
                <h3 id="fileNameDisplay" style="margin: 0 0 10px 0; color: #1e293b;">คลิกเพื่อเลือกไฟล์ CSV หรือลากไฟล์มาวางที่นี่</h3>
                <p style="margin: 0; color: #64748b;">รองรับไฟล์ .csv ขนาดไม่เกิน 5MB</p>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required onchange="handleFileSelect(this)">
            </label>
            
            <div style="text-align: center;">
                <button type="submit" class="btn-submit" id="btnSubmit">📤 ยืนยันการนำเข้าข้อมูล</button>
            </div>
        </form>

        <div class="instruction-box">
            <strong style="color: #1e293b;">📌 โครงสร้างไฟล์ CSV ที่ระบบรองรับ (ลำดับคอลัมน์ต้องเรียงตามนี้):</strong><br><br>
            <table style="width: 100%; border-collapse: collapse; background: white; border: 1px solid #cbd5e1; margin-bottom: 15px;">
                <tr style="background: #e2e8f0;">
                    <th style="padding: 8px; border: 1px solid #cbd5e1;">username (บังคับ)</th>
                    <th style="padding: 8px; border: 1px solid #cbd5e1;">password (บังคับ)</th>
                    <th style="padding: 8px; border: 1px solid #cbd5e1;">display_name (บังคับ)</th>
                    <th style="padding: 8px; border: 1px solid #cbd5e1;">role (บังคับ)</th>
                    <th style="padding: 8px; border: 1px solid #cbd5e1;">class_id</th>
                    <th style="padding: 8px; border: 1px solid #cbd5e1;">parent_of</th>
                </tr>
                <tr>
                    <td style="padding: 8px; border: 1px solid #cbd5e1;">std67001</td>
                    <td style="padding: 8px; border: 1px solid #cbd5e1;">pass1234</td>
                    <td style="padding: 8px; border: 1px solid #cbd5e1;">ด.ช. สมชาย รักเรียน</td>
                    <td style="padding: 8px; border: 1px solid #cbd5e1;">student</td>
                    <td style="padding: 8px; border: 1px solid #cbd5e1;">1</td>
                    <td style="padding: 8px; border: 1px solid #cbd5e1;"></td>
                </tr>
            </table>
            <ul style="margin: 0; padding-left: 20px;">
                <li><span class="code-style">role</span> อนุญาตเฉพาะ: <span class="code-style">student</span>, <span class="code-style">teacher</span>, <span class="code-style">parent</span>, <span class="code-style">developer</span></li>
                <li><span class="code-style">class_id</span> (รหัสห้องเรียน) และ <span class="code-style">parent_of</span> (รหัส ID ของนักเรียนที่เป็นบุตรหลาน) ให้เว้นว่างไว้ได้หากไม่มีข้อมูล</li>
                <li>บรรทัดแรกสุดของไฟล์ (Header) จะถูกข้ามอัตโนมัติ ห้ามเอาข้อมูลนักเรียนไว้บรรทัดแรกสุด</li>
            </ul>
        </div>
    </div>

    <?php if (!empty($import_logs)): ?>
    <div class="panel">
        <h2>📋 สรุปผลการทำงาน (Import Logs)</h2>
        <div style="max-height: 400px; overflow-y: auto;">
            <table class="log-table">
                <thead>
                    <tr>
                        <th style="width: 10%;">บรรทัดที่</th>
                        <th style="width: 15%;">สถานะ</th>
                        <th>รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($import_logs as $log): ?>
                        <tr>
                            <td><?= $log['row'] ?></td>
                            <td>
                                <span class="status-badge <?= $log['status'] === 'success' ? 'badge-success' : 'badge-error' ?>">
                                    <?= $log['status'] === 'success' ? '✅ สำเร็จ' : '❌ ล้มเหลว' ?>
                                </span>
                            </td>
                            <td style="color: <?= $log['status'] === 'error' ? '#b91c1c' : '#475569' ?>;">
                                <?= h($log['msg']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
    function handleFileSelect(input) {
        const display = document.getElementById('fileNameDisplay');
        const btn = document.getElementById('btnSubmit');
        const dropZone = document.getElementById('dropZone');
        
        if (input.files && input.files.length > 0) {
            const fileName = input.files[0].name;
            display.innerHTML = `<span style="color:#10b981;">📄 เลือกไฟล์แล้ว: ${fileName}</span>`;
            dropZone.style.borderColor = '#10b981';
            dropZone.style.background = 'rgba(16, 185, 129, 0.05)';
            btn.classList.add('show');
        } else {
            display.innerHTML = 'คลิกเพื่อเลือกไฟล์ CSV หรือลากไฟล์มาวางที่นี่';
            dropZone.style.borderColor = '#3b82f6';
            dropZone.style.background = 'rgba(59, 130, 246, 0.05)';
            btn.classList.remove('show');
        }
    }
</script>

<?php require_once 'footer.php'; ?>