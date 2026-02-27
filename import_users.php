<?php
/**
 * import_users.php - ระบบนำเข้าผู้ใช้งานด้วยไฟล์ CSV อัจฉริยะ (Phase 2: Smart Bulk Import)
 * รองรับระดับ 1000+ คน พร้อมสร้างห้องเรียนอัตโนมัติ และจัดการรหัสผ่านเริ่มต้น
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */

// ขยายเวลาประมวลผลของ Server ป้องกันเว็บ Timeout เวลานำเข้าคนจำนวนมาก
set_time_limit(300); 

require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// สงวนสิทธิ์เฉพาะ Developer และ Admin เท่านั้น
requireRole(['developer', 'admin']);

$page_title = "นำเข้าผู้ใช้งาน (Smart CSV Import)";

// =========================================================================
// 📥 ส่วนที่ 1: ระบบสร้างและดาวน์โหลดไฟล์ Template (CSV)
// =========================================================================
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Bankha_User_Import_Template.csv');
    $output = fopen('php://output', 'w');
    
    // ใส่ BOM (Byte Order Mark) เพื่อให้เปิดใน Excel ภาษาไทยได้ไม่เป็นภาษาต่างดาว
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // หัวตาราง (Header) แบบเข้าใจง่าย
    fputcsv($output, ['Username', 'Password', 'DisplayName', 'Role', 'Level', 'Room']);
    
    // ข้อมูลตัวอย่างแถวที่ 1-3 เพื่อให้ครูดูเป็นตัวอย่าง
    fputcsv($output, ['66001', '', 'ด.ช. สมชาย รักเรียน', 'student', 'ม.1', '1']);
    fputcsv($output, ['66002', '123456', 'ด.ญ. สมหญิง ตั้งใจ', 'student', 'ม.1', '2']);
    fputcsv($output, ['tea01', 'password', 'ครู ใจดี มีสุข', 'teacher', '', '']);
    
    fclose($output);
    exit;
}

// =========================================================================
// ⚙️ ส่วนที่ 2: เอนจินประมวลผลไฟล์ CSV (Smart Import Engine)
// =========================================================================
$import_results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    
    // ตรวจสอบความปลอดภัยด้วย CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $import_results = ['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้องเพื่อความปลอดภัยกรุณารีเฟรชหน้าเว็บ'];
    } 
    // ตรวจสอบว่ามีการอัปโหลดไฟล์มาและไม่มี Error
    else if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($file_ext !== 'csv') {
            $import_results = ['status' => 'error', 'message' => 'กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้น (หากใช้ Excel ให้กด Save As เป็น CSV UTF-8)'];
        } else {
            $handle = fopen($file_tmp, "r");
            
            // ข้าม BOM (Byte Order Mark) ในกรณีที่มีติดมากับไฟล์
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

            $success_count = 0;
            $error_count = 0;
            $auto_class_count = 0;
            $errors_log = [];
            $row_num = 1;

            // ⚡ เตรียมคำสั่ง SQL (Prepared Statements) ไว้นอก Loop เพื่อความเร็วระดับมิลลิวินาที
            $stmt_check_user = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt_insert_user = $conn->prepare("INSERT INTO users (username, password, display_name, role, class_id, class_level) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_check_class = $conn->prepare("SELECT id FROM classes WHERE level = ? AND room = ? AND is_active = 1");
            $stmt_insert_class = $conn->prepare("INSERT INTO classes (class_name, level, room, is_active) VALUES (?, ?, ?, 1)");

            // อ่านข้อมูลทีละบรรทัด (รองรับแถวละ 1000 ตัวอักษร คั่นด้วยลูกน้ำ)
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                
                // ข้ามบรรทัดแรกถ้าเป็นชื่อคอลัมน์ (Header)
                if ($row_num === 1 && strtolower(trim($data[0])) === 'username') {
                    $row_num++;
                    continue;
                }

                // ป้องกันกรณีบรรทัดว่าง หรือมีคอลัมน์ไม่ครบ
                if (count($data) < 4 || empty(implode('', $data))) {
                    if(!empty(implode('', $data))) {
                        $error_count++;
                        $errors_log[] = "บรรทัดที่ {$row_num}: รูปแบบคอลัมน์ไม่ครบถ้วน (ต้องการอย่างน้อย 4 คอลัมน์แรก)";
                    }
                    $row_num++;
                    continue;
                }

                $username = trim($data[0]);
                $password = trim($data[1]);
                $display_name = trim($data[2]);
                $role = strtolower(trim($data[3]));
                $level = isset($data[4]) ? trim($data[4]) : '';
                $room = isset($data[5]) ? intval(trim($data[5])) : 0;

                // ข้ามหากข้อมูลหลักว่างเปล่า
                if (empty($username) || empty($display_name) || empty($role)) {
                    $error_count++;
                    $errors_log[] = "บรรทัดที่ {$row_num}: กรอกข้อมูลบังคับ (Username/Name/Role) ไม่ครบ";
                    $row_num++;
                    continue;
                }

                // เช็คความถูกต้องของ Role
                if (!in_array($role, ['student', 'teacher', 'parent', 'developer'])) {
                    $error_count++;
                    $errors_log[] = "บรรทัดที่ {$row_num}: บทบาท (Role) ไม่ถูกต้อง แนะนำให้ใช้ student หรือ teacher";
                    $row_num++;
                    continue;
                }

                // เช็คว่า Username ซ้ำในระบบหรือไม่
                $stmt_check_user->bind_param("s", $username);
                $stmt_check_user->execute();
                if ($stmt_check_user->get_result()->num_rows > 0) {
                    $error_count++;
                    $errors_log[] = "บรรทัดที่ {$row_num}: Username '{$username}' มีใช้งานอยู่ในระบบแล้ว (ข้ามการบันทึก)";
                    $row_num++;
                    continue;
                }

                // 🧠 ลอจิกจัดการรหัสผ่าน (Smart Password)
                // ถ้ารหัสผ่านว่างเปล่า ให้ใช้ Username เป็นรหัสผ่าน (โรงเรียนนิยมใช้รหัสนักเรียนเป็นรหัสผ่านเริ่มต้น)
                if (empty($password)) {
                    $password = $username; 
                }

                // 🧠 ลอจิกจัดการห้องเรียนอัจฉริยะ (Smart Auto-Create Class)
                $class_id = null;
                $class_level_text = null;

                if ($role === 'student') {
                    if (!empty($level) && $room > 0) {
                        $stmt_check_class->bind_param("si", $level, $room);
                        $stmt_check_class->execute();
                        $res_class = $stmt_check_class->get_result();

                        if ($res_class->num_rows > 0) {
                            // เจอห้องเรียนนี้ในระบบ
                            $class_row = $res_class->fetch_assoc();
                            $class_id = $class_row['id'];
                            $class_level_text = "{$level}/{$room}";
                        } else {
                            // ไม่เจอห้องเรียน -> สร้างห้องใหม่ให้ทันที!
                            $class_name = "{$level}/{$room}";
                            $stmt_insert_class->bind_param("ssi", $class_name, $level, $room);
                            if ($stmt_insert_class->execute()) {
                                $class_id = $stmt_insert_class->insert_id;
                                $class_level_text = $class_name;
                                $auto_class_count++;
                            }
                        }
                    } else {
                        // นักเรียนแต่ไม่ระบุห้อง
                        $error_count++;
                        $errors_log[] = "บรรทัดที่ {$row_num}: {$username} เป็นนักเรียนแต่ไม่ได้ระบุ ระดับชั้น (Level) หรือ ห้อง (Room)";
                        $row_num++;
                        continue;
                    }
                }

                // ทำการ Hash รหัสผ่านและ Insert ลง Database
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt_insert_user->bind_param("ssssis", $username, $hashed_password, $display_name, $role, $class_id, $class_level_text);
                
                if ($stmt_insert_user->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors_log[] = "บรรทัดที่ {$row_num}: บันทึกข้อมูลล้มเหลว Database Error (" . $stmt_insert_user->error . ")";
                }

                $row_num++;
            } // End While Loop

            fclose($handle);
            
            // คืนค่า Memory
            $stmt_check_user->close();
            $stmt_insert_user->close();
            $stmt_check_class->close();
            $stmt_insert_class->close();

            $import_results = [
                'status' => 'success',
                'success_count' => $success_count,
                'error_count' => $error_count,
                'auto_class_count' => $auto_class_count,
                'errors_log' => $errors_log
            ];
        }
    } else {
        $import_results = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการรับไฟล์จากเบราว์เซอร์'];
    }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Share+Tech+Mono&family=Orbitron:wght@700&display=swap" rel="stylesheet">
<style>
    /* ============================================================
       🎨 CSS STYLING FOR SMART IMPORT DASHBOARD
       ============================================================ */
    body { background-color: #f8fafc; font-family: 'Sarabun', sans-serif; }
    .import-container { max-width: 950px; margin: 40px auto; padding: 0 20px; }
    
    .page-header { background: linear-gradient(135deg, #1e293b, #0f172a); color: white; padding: 35px 40px; border-radius: 16px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: space-between; }
    .page-header h1 { margin: 0; font-size: 2rem; color: #38bdf8; font-family: 'Orbitron', sans-serif; }
    .header-icon { font-size: 4rem; filter: drop-shadow(0 0 10px rgba(56, 189, 248, 0.4)); }
    
    .card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; padding: 30px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    
    /* Drag & Drop Zone */
    .file-upload-wrapper { position: relative; border: 3px dashed #cbd5e1; border-radius: 15px; padding: 60px 20px; text-align: center; background: #f8fafc; transition: all 0.3s ease; cursor: pointer; margin-bottom: 25px; }
    .file-upload-wrapper:hover { border-color: #3b82f6; background: #eff6ff; transform: translateY(-2px); box-shadow: 0 10px 20px rgba(59, 130, 246, 0.1); }
    .file-upload-wrapper.dragover { border-color: #10b981; background: #dcfce7; transform: scale(1.02); }
    .file-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
    
    .upload-icon { font-size: 4rem; margin-bottom: 15px; display: block; transition: 0.3s; }
    .file-upload-wrapper:hover .upload-icon { transform: translateY(-5px); }
    .upload-text { font-size: 1.2rem; color: #334155; font-weight: bold; }
    .upload-subtext { color: #64748b; font-size: 0.9rem; margin-top: 5px; }
    
    .file-name-display { margin-top: 20px; font-weight: bold; color: #1e40af; display: none; background: #dbeafe; padding: 8px 20px; border-radius: 30px; border: 1px solid #bfdbfe; font-size: 1.1rem; }

    /* Action Buttons */
    .btn-submit { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 18px 30px; border-radius: 12px; font-size: 1.2rem; font-weight: bold; cursor: pointer; width: 100%; box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3); transition: 0.3s; display: flex; justify-content: center; align-items: center; gap: 10px; }
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 25px rgba(37, 99, 235, 0.4); }
    .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }

    /* Template Guidance */
    .template-box { background: #fffbeb; border: 1px solid #fde68a; padding: 25px; border-radius: 16px; margin-bottom: 30px; display: flex; gap: 20px; align-items: flex-start; }
    .template-icon { font-size: 3rem; line-height: 1; }
    .template-content { flex: 1; }
    .template-content h3 { margin-top: 0; color: #92400e; margin-bottom: 10px; }
    .template-content ul { color: #78350f; padding-left: 20px; margin-bottom: 15px; line-height: 1.6; }
    .btn-download { background: #f59e0b; color: white; padding: 10px 25px; border-radius: 8px; text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.3); }
    .btn-download:hover { background: #d97706; transform: translateY(-2px); }

    /* Status Dashboard Results */
    .result-box { padding: 30px; border-radius: 16px; margin-bottom: 30px; border: 2px solid #e2e8f0; background: white; box-shadow: 0 10px 30px rgba(0,0,0,0.05); animation: slideIn 0.5s ease-out; }
    @keyframes slideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    
    .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 25px; }
    .stat-card { padding: 25px 20px; border-radius: 12px; text-align: center; font-weight: bold; position: relative; overflow: hidden; }
    .stat-card::before { content: ''; position: absolute; top:0; left:0; width:100%; height:5px; }
    
    .stat-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .stat-success::before { background: #22c55e; }
    
    .stat-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .stat-error::before { background: #ef4444; }
    
    .stat-auto { background: #eff6ff; color: #075985; border: 1px solid #bae6fd; }
    .stat-auto::before { background: #3b82f6; }
    
    .stat-title { font-size: 1rem; margin-bottom: 10px; }
    .stat-num { font-size: 3rem; font-family: 'Share Tech Mono', monospace; display: block; line-height: 1; text-shadow: 1px 1px 0px rgba(255,255,255,0.5); }

    .error-log { background: #1e293b; color: #f8fafc; padding: 20px; border-radius: 12px; max-height: 300px; overflow-y: auto; margin-top: 30px; font-size: 0.95rem; border: 1px solid #0f172a; box-shadow: inset 0 5px 15px rgba(0,0,0,0.5); }
    .log-title { color: #fca5a5; border-bottom: 1px dashed #475569; padding-bottom: 10px; margin-top: 0; font-family: 'Share Tech Mono'; }
    .log-item { margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #334155; }
    .log-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
</style>

<div class="import-container">
    
    <div class="page-header">
        <div>
            <h1>📥 Smart CSV Import</h1>
            <p>ระบบนำเข้าข้อมูลนักเรียนและครูระดับ Enterprise (ความเร็วสูง)</p>
        </div>
        <div class="header-icon">🚀</div>
    </div>

    <?php if ($import_results): ?>
        <?php if ($import_results['status'] === 'error'): ?>
            <div class="result-box" style="background: #fef2f2; border-color: #fca5a5;">
                <h3 style="color: #991b1b; margin-top:0; display:flex; align-items:center; gap:10px;">
                    <span style="font-size:2rem;">🚨</span> เกิดข้อผิดพลาดในการทำงาน
                </h3>
                <p style="font-size:1.1rem; font-weight:bold; color:#7f1d1d;"><?= htmlspecialchars($import_results['message']) ?></p>
                <button onclick="window.location.href='import_users.php'" class="btn-submit" style="background:#ef4444; width:auto; padding:10px 20px; font-size:1rem;">ลองใหม่อีกครั้ง</button>
            </div>
        <?php else: ?>
            <div class="result-box">
                <h2 style="margin-top:0; color:#1e293b; text-align:center;">📊 สรุปผลการนำเข้าข้อมูล (Import Report)</h2>
                
                <div class="stat-grid">
                    <div class="stat-card stat-success">
                        <div class="stat-title">✅ นำเข้าสำเร็จ</div>
                        <span class="stat-num"><?= number_format($import_results['success_count']) ?></span>
                        <div style="font-size:0.8rem; margin-top:10px;">ผู้ใช้งาน (Users)</div>
                    </div>
                    <div class="stat-card stat-error">
                        <div class="stat-title">❌ ล้มเหลว / ข้าม</div>
                        <span class="stat-num"><?= number_format($import_results['error_count']) ?></span>
                        <div style="font-size:0.8rem; margin-top:10px;">รายการ (Rows)</div>
                    </div>
                    <div class="stat-card stat-auto">
                        <div class="stat-title">🏫 สร้างห้องเรียนอัตโนมัติ</div>
                        <span class="stat-num"><?= number_format($import_results['auto_class_count']) ?></span>
                        <div style="font-size:0.8rem; margin-top:10px;">ห้องเรียนใหม่ (Classes)</div>
                    </div>
                </div>

                <?php if (count($import_results['errors_log']) > 0): ?>
                    <div class="error-log">
                        <h3 class="log-title">⚠️ รายการข้อผิดพลาด (โปรดตรวจสอบข้อมูลในไฟล์):</h3>
                        <?php foreach ($import_results['errors_log'] as $log): ?>
                            <div class="log-item">👉 <?= htmlspecialchars($log) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div style="text-align:center; margin-top: 30px; display:flex; gap:15px; justify-content:center;">
                    <a href="user_manager.php" style="background:#334155; color:white; padding:15px 30px; border-radius:10px; text-decoration:none; font-weight:bold; transition:0.2s;">👥 ไปยังหน้ารายชื่อทั้งหมด</a>
                    <a href="import_users.php" style="background:#e2e8f0; color:#334155; padding:15px 30px; border-radius:10px; text-decoration:none; font-weight:bold; transition:0.2s;">นำเข้าไฟล์อื่นต่อ</a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="template-box">
        <div class="template-icon">💡</div>
        <div class="template-content">
            <h3>คำแนะนำสำหรับการนำเข้าจำนวนมาก</h3>
            <ul>
                <li>โปรดใช้ไฟล์ <strong>.CSV</strong> ในการอัปโหลด (ใน Excel ไปที่เมนู File > Save As > เลือก CSV UTF-8)</li>
                <li><strong>ระบบรหัสผ่านอัจฉริยะ:</strong> หากท่านเว้นคอลัมน์รหัสผ่านว่างไว้ ระบบจะใช้ <b>Username เป็นรหัสผ่าน</b> ให้อัตโนมัติ (เช่น Username=66001, Password=66001)</li>
                <li><strong>ระบบสร้างห้องอัจฉริยะ:</strong> หากระบุระดับชั้นและห้องที่ยังไม่มีในระบบ (เช่น ม.4 ห้อง 12) ระบบจะสร้างห้องเรียนใหม่ให้เอง ไม่ต้องกังวล!</li>
            </ul>
            <a href="import_users.php?download_template=1" class="btn-download">
                <span style="font-size:1.2rem;">📥</span> ดาวน์โหลดไฟล์ CSV ต้นแบบ (Template)
            </a>
        </div>
    </div>

    <div class="card">
        <form action="import_users.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="import_csv" value="1">
            
            <div class="file-upload-wrapper" id="dropZone">
                <input type="file" name="csv_file" id="csvFileInput" class="file-input" accept=".csv" required>
                <span class="upload-icon">📄</span>
                <div class="upload-text">คลิกเพื่อเลือกไฟล์ หรือ ลากไฟล์ CSV มาวางที่นี่</div>
                <div class="upload-subtext">รองรับขนาดไฟล์สูงสุด 10MB (ประมาณ 50,000 รายชื่อ)</div>
                <div class="file-name-display" id="fileNameDisplay"></div>
            </div>

            <button type="submit" class="btn-submit" id="btnSubmitUpload" disabled>
                <span>🚀 เริ่มการประมวลผลข้อมูล (Start Import)</span>
            </button>
        </form>
    </div>
</div>

<script>
    /* ============================================================
       🖱️ JAVASCRIPT: DRAG & DROP AND UI LOGIC
       ============================================================ */
    const fileInput = document.getElementById('csvFileInput');
    const dropZone = document.getElementById('dropZone');
    const fileNameDisplay = document.getElementById('fileNameDisplay');
    const btnSubmit = document.getElementById('btnSubmitUpload');
    const uploadForm = document.getElementById('uploadForm');

    // 1. จัดการเมื่อผู้ใช้กดเลือกไฟล์ผ่าน Dialog
    fileInput.addEventListener('change', function(e) {
        handleFiles(this.files);
    });

    // 2. ป้องกันพฤติกรรม Default ของ Browser เวลามีการลากไฟล์
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults(e) { 
        e.preventDefault(); 
        e.stopPropagation(); 
    }

    // 3. เพิ่ม/ลด Class CSS เวลาลากไฟล์เข้ามาในกรอบ
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
    });

    // 4. จัดการเวลาปล่อยไฟล์ (Drop)
    dropZone.addEventListener('drop', (e) => {
        let dt = e.dataTransfer;
        let files = dt.files;
        fileInput.files = files; // ยัดไฟล์ที่ลากเข้าช่อง input type="file"
        handleFiles(files);
    });

    // 5. อัปเดตหน้าตา UI ให้รู้ว่าไฟล์เข้ามาแล้ว
    function handleFiles(files) {
        if (files && files.length > 0) {
            const fileName = files[0].name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            if (fileExt !== 'csv') {
                alert("⚠️ กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้นครับ");
                fileInput.value = ''; // เคลียร์ไฟล์ทิ้ง
                fileNameDisplay.style.display = 'none';
                btnSubmit.disabled = true;
                return;
            }

            fileNameDisplay.innerHTML = '✔️ เตรียมไฟล์: ' + fileName;
            fileNameDisplay.style.display = 'inline-block';
            btnSubmit.disabled = false;
        } else {
            fileNameDisplay.style.display = 'none';
            btnSubmit.disabled = true;
        }
    }

    // 6. Loading State ป้องกันการกดเบิ้ลเวลาไฟล์ใหญ่
    uploadForm.addEventListener('submit', () => {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '⏳ กำลังประมวลผลข้อมูล... ห้ามปิดหน้าต่างนี้เด็ดขาด!';
        btnSubmit.style.background = '#94a3b8';
        btnSubmit.style.boxShadow = 'none';
        dropZone.style.opacity = '0.5';
        dropZone.style.pointerEvents = 'none';
    });
</script>

<?php require_once 'footer.php'; ?>