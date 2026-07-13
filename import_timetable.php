<?php
/**
 * import_timetable.php - ระบบนำเข้าตารางสอนผ่านไฟล์ Excel (Phase 2)
 * สถาปัตยกรรม: Smart Frontend Parsing (SheetJS) + API Backend Receiver
 * 🚨 กฎเหล็ก: เขียนโค้ดแบบกระจายเต็มรูปแบบ (Unminified) ห้ามย่อบรรทัด ห้ามบีบอัด
 */

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';

// =========================================================================
// 🚨 ตรวจสอบการเชื่อมต่อฐานข้อมูล
// =========================================================================
require_once 'security.php';
require_once 'auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

// =========================================================================
// 🔐 UNIFIED MIDDLEWARE: ตรวจสอบสิทธิ์ผู้ใช้งาน (เฉพาะ Admin/ผู้พัฒนา)
// =========================================================================
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';

$is_admin = false;
if (in_array($role, ['developer', 'teacher'])) {
    $is_admin = true; 
}

if (!$is_admin) {
    die("
        <div style='padding:50px; text-align:center; color:#ef4444; font-family:sans-serif;'>
            <h2>🚨 ข้อผิดพลาด: คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h2>
            <p>ระบบนำเข้าตารางสอนสงวนไว้สำหรับฝ่ายวิชาการหรือผู้ดูแลระบบเท่านั้น</p>
        </div>
    ");
}

// =========================================================================
// 📥 BACKEND API: ส่วนประมวลผลข้อมูลที่ถูกส่งมาจาก JavaScript (AJAX POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ตั้งค่า Header ให้คืนค่าเป็น JSON
    header('Content-Type: application/json; charset=utf-8');
    
    // อ่านข้อมูล JSON ที่ส่งมาจาก Frontend
    $json_data = file_get_contents('php://input');
    $payload = json_decode($json_data, true);
    
    // ตรวจสอบความถูกต้องของ Payload
    if (!$payload || !isset($payload['academic_year']) || !isset($payload['term']) || !isset($payload['schedules'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'ข้อมูลที่ส่งมาไม่ครบถ้วน หรือรูปแบบ JSON ไม่ถูกต้อง'
        ]);
        exit;
    }
    
    $academic_year = trim($payload['academic_year']);
    $term = trim($payload['term']);
    $schedules = $payload['schedules'];
    
    if (empty($schedules)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'ไม่พบข้อมูลตารางสอนในไฟล์ Excel'
        ]);
        exit;
    }

    try {
        // เริ่ม Transaction เพื่อความปลอดภัย หาก Error ตรงไหน ข้อมูลจะถูก Rollback ทั้งหมด
        $pdo->beginTransaction();
        
        // อาเรย์สำหรับเก็บ ID ของครูที่เราจัดการไปแล้วในรอบนี้ เพื่อลบตารางเก่าทิ้งแค่ครั้งเดียว
        $processed_teachers = [];
        
        $inserted_count = 0;
        
        foreach ($schedules as $row) {
            
            $t_code = trim($row['teacher_code'] ?? '');
            $t_name = trim($row['teacher_name'] ?? '');
            $day    = intval($row['day'] ?? 1);
            $start  = intval($row['start_period'] ?? 1);
            $end    = intval($row['end_period'] ?? $start);
            $subj   = trim($row['subject_code'] ?? '');
            $class  = trim($row['class_room'] ?? '');
            $room   = trim($row['room_number'] ?? '');
            $is_act = intval($row['is_activity'] ?? 0);
            
            // ข้ามแถวที่ไม่มีรหัสครูหรือไม่มีรหัสวิชา
            if (empty($t_code) || empty($subj)) {
                continue; 
            }
            
            // 1. ตรวจสอบว่ามีครูคนนี้ในระบบหรือยัง (ถ้าไม่มีให้ Insert ใหม่, ถ้ามีให้อัปเดตชื่อ)
            $stmt_check_teacher = $pdo->prepare("SELECT id FROM teachers WHERE teacher_code = ?");
            $stmt_check_teacher->execute([$t_code]);
            $teacher_id = $stmt_check_teacher->fetchColumn();
            
            if (!$teacher_id) {
                // สร้างครูใหม่
                $stmt_insert_teacher = $pdo->prepare("
                    INSERT INTO teachers (teacher_code, full_name, department) 
                    VALUES (?, ?, ?)
                ");
                $stmt_insert_teacher->execute([$t_code, $t_name, 'นำเข้าจากระบบ Excel']);
                $teacher_id = $pdo->lastInsertId();
            } else {
                // อัปเดตชื่อครูเผื่อมีการเปลี่ยนแปลง
                $stmt_update_teacher = $pdo->prepare("
                    UPDATE teachers 
                    SET full_name = ? 
                    WHERE id = ?
                ");
                $stmt_update_teacher->execute([$t_name, $teacher_id]);
            }
            
            // 2. ถ้าเพิ่งเจอครูคนนี้เป็นครั้งแรกในรอบบิลนี้ ให้ลบตารางสอนเก่าของเทอมนี้ทิ้งก่อน
            if (!in_array($teacher_id, $processed_teachers)) {
                $stmt_clear_schedule = $pdo->prepare("
                    DELETE FROM teacher_schedules 
                    WHERE teacher_id = ? 
                    AND academic_year = ? 
                    AND term = ?
                ");
                $stmt_clear_schedule->execute([$teacher_id, $academic_year, $term]);
                $processed_teachers[] = $teacher_id;
            }
            
            // 3. บันทึกคาบเรียนลงฐานข้อมูล
            $stmt_insert_schedule = $pdo->prepare("
                INSERT INTO teacher_schedules (
                    teacher_id, academic_year, term, day_of_week, 
                    start_period, end_period, subject_code, class_room, 
                    room_number, is_activity
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt_insert_schedule->execute([
                $teacher_id,
                $academic_year,
                $term,
                $day,
                $start,
                $end,
                $subj,
                $class,
                $room,
                $is_act
            ]);
            
            $inserted_count++;
        }
        
        // ยืนยันการบันทึกข้อมูลทั้งหมด
        $pdo->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => "นำเข้าข้อมูลตารางสอนเรียบร้อยแล้ว จำนวน {$inserted_count} คาบ",
            'count' => $inserted_count
        ]);
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode([
            'status' => 'error',
            'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()
        ]);
        exit;
    }
}

// =========================================================================
// 🖥️ FRONTEND UI: ส่วนแสดงผลหน้าเว็บ
// =========================================================================
$page_title = "นำเข้าตารางสอน - Smart School Plus";
require_once 'header.php';
?>

<script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=Prompt:wght@400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">

<style>
    /* ============================================================
       📍 CSS ARCHITECTURE (Fully Unminified)
       ============================================================ */
    
    :root {
        --bg-main: #020617;
        --panel-bg: #0f172a;
        --panel-border: #1e293b;
        --primary: #8b5cf6;
        --primary-hover: #7c3aed;
        --primary-glow: rgba(139, 92, 246, 0.4);
        --success: #10b981;
        --danger: #ef4444;
        --text-bright: #f8fafc;
        --text-muted: #94a3b8;
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-bright);
        font-family: 'Prompt', sans-serif;
        margin: 0;
        padding: 0;
    }

    .import-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 0 20px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid var(--panel-border);
        padding-bottom: 20px;
        margin-bottom: 30px;
    }

    .page-title {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .page-title h1 {
        margin: 0;
        font-family: 'Orbitron', sans-serif;
        color: var(--primary);
        font-size: 2rem;
        text-shadow: 0 0 15px var(--primary-glow);
    }

    .page-title p {
        margin: 5px 0 0 0;
        color: var(--text-muted);
        font-size: 1rem;
    }

    .btn-download-template {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 10px;
        font-family: 'Prompt', sans-serif;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(16, 185, 129, 0.4);
    }

    .btn-download-template:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.6);
    }

    /* 🎛️ Settings Panel */
    .settings-panel {
        background-color: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 30px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }

    .input-group {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .input-group label {
        font-weight: 600;
        color: var(--primary);
        font-size: 1.1rem;
    }

    .input-group input, 
    .input-group select {
        background-color: var(--bg-main);
        border: 1px solid var(--panel-border);
        color: var(--text-bright);
        padding: 15px;
        border-radius: 10px;
        font-family: 'Share Tech Mono', 'Prompt', monospace;
        font-size: 1.2rem;
        outline: none;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .input-group input:focus, 
    .input-group select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 15px var(--primary-glow);
    }

    /* 📂 Dropzone Area */
    .dropzone-container {
        background-color: var(--panel-bg);
        border: 2px dashed var(--primary);
        border-radius: 20px;
        padding: 50px 20px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }

    .dropzone-container:hover, 
    .dropzone-container.drag-active {
        background-color: rgba(139, 92, 246, 0.1);
        border-color: #a78bfa;
        box-shadow: 0 0 30px var(--primary-glow);
    }

    .dropzone-icon {
        font-size: 4rem;
        margin-bottom: 15px;
        filter: drop-shadow(0 5px 15px var(--primary-glow));
    }

    .dropzone-text {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--text-bright);
        margin: 0 0 10px 0;
    }

    .dropzone-subtext {
        color: var(--text-muted);
        font-size: 1rem;
        margin: 0;
    }

    .dropzone-input {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }

    /* 📊 Preview Table */
    .preview-section {
        display: none;
        background-color: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 15px;
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        animation: fadeIn 0.5s ease-out forwards;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .preview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .preview-header h2 {
        margin: 0;
        color: var(--primary);
        font-size: 1.5rem;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
        border-radius: 10px;
        border: 1px solid var(--panel-border);
    }

    .styled-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .styled-table th, 
    .styled-table td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--panel-border);
    }

    .styled-table th {
        background-color: #1e293b;
        color: var(--primary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.9rem;
    }

    .styled-table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }

    .styled-table tbody tr:last-child td {
        border-bottom: none;
    }

    .badge-act {
        background-color: rgba(239, 68, 68, 0.2);
        color: var(--danger);
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
        border: 1px solid var(--danger);
    }

    .badge-normal {
        background-color: rgba(56, 189, 248, 0.2);
        color: #38bdf8;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
        border: 1px solid #38bdf8;
    }

    /* 🚀 Action Buttons */
    .action-bar {
        display: flex;
        justify-content: flex-end;
        gap: 15px;
        margin-top: 25px;
    }

    .btn-cancel {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid var(--panel-border);
        padding: 12px 25px;
        border-radius: 10px;
        font-family: 'Prompt', sans-serif;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-cancel:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .btn-confirm {
        background: linear-gradient(135deg, var(--primary), var(--primary-hover));
        color: white;
        border: none;
        padding: 12px 35px;
        border-radius: 10px;
        font-family: 'Prompt', sans-serif;
        font-weight: 600;
        font-size: 1.1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px var(--primary-glow);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .btn-confirm:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px var(--primary-glow);
    }

    .btn-confirm:disabled {
        background: #334155;
        color: #94a3b8;
        box-shadow: none;
        cursor: not-allowed;
        transform: none;
    }

</style>

<div class="import-container">
    
    <div class="page-header">
        <div class="page-title">
            <div class="setup-icon">📅</div>
            <div>
                <h1>Excel Timetable Import</h1>
                <p>นำเข้าตารางสอนครูแบบอัจฉริยะ (รองรับคาบควบและกิจกรรม)</p>
            </div>
        </div>
        <button class="btn-download-template" onclick="downloadTemplate()">
            📥 ดาวน์โหลด Template (Excel)
        </button>
    </div>

    <div class="settings-panel">
        <div class="input-group">
            <label for="selectYear">ปีการศึกษา (Academic Year)</label>
            <input type="number" id="selectYear" value="<?= date('Y') + 543 ?>" min="2560" max="2600">
        </div>
        <div class="input-group">
            <label for="selectTerm">ภาคเรียน (Term)</label>
            <select id="selectTerm">
                <option value="1">ภาคเรียนที่ 1</option>
                <option value="2">ภาคเรียนที่ 2</option>
                <option value="3">ภาคเรียนฤดูร้อน (Summer)</option>
            </select>
        </div>
    </div>

    <div class="dropzone-container" id="dropzone">
        <div class="dropzone-icon">📁</div>
        <h3 class="dropzone-text">ลากไฟล์ Excel มาวางที่นี่</h3>
        <p class="dropzone-subtext">หรือคลิกเพื่อเลือกไฟล์ (รองรับเฉพาะไฟล์ .xlsx และ .xls)</p>
        <input type="file" id="fileInput" class="dropzone-input" accept=".xlsx, .xls">
    </div>

    <div class="preview-section" id="previewSection">
        <div class="preview-header">
            <h2>🔎 พรีวิวข้อมูลก่อนบันทึก</h2>
            <span id="rowCountBadge" style="background:var(--panel-border); padding:5px 15px; border-radius:20px; font-size:0.9rem; color:var(--text-muted);">
                พบ 0 รายการ
            </span>
        </div>
        
        <div class="table-responsive">
            <table class="styled-table" id="previewTable">
                <thead>
                    <tr>
                        <th>รหัสครู</th>
                        <th>ชื่อ-สกุล</th>
                        <th>วัน</th>
                        <th>คาบที่</th>
                        <th>รหัสวิชา</th>
                        <th>ชั้น/กลุ่ม</th>
                        <th>ห้อง</th>
                        <th>ประเภท</th>
                    </tr>
                </thead>
                <tbody id="previewBody">
                    </tbody>
            </table>
        </div>

        <div class="action-bar">
            <button class="btn-cancel" onclick="resetImport()">✖ ยกเลิก</button>
            <button class="btn-confirm" id="btnConfirm" onclick="uploadDataToServer()">
                💾 ยืนยันการบันทึกข้อมูล
            </button>
        </div>
    </div>

</div>

<script>
    
    // ตัวแปรสำหรับเก็บข้อมูลที่อ่านได้จาก Excel
    let parsedSchedules = [];

    // ดึง Elements ที่จำเป็น
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    const previewSection = document.getElementById('previewSection');
    const previewBody = document.getElementById('previewBody');
    const rowCountBadge = document.getElementById('rowCountBadge');
    const btnConfirm = document.getElementById('btnConfirm');
    const selectYear = document.getElementById('selectYear');
    const selectTerm = document.getElementById('selectTerm');

    // ==========================================
    // 🎨 UI Event Listeners (จัดการ Drag & Drop)
    // ==========================================
    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.classList.add('drag-active');
    });

    dropzone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropzone.classList.remove('drag-active');
    });

    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('drag-active');
        
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            handleFile(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
            handleFile(e.target.files[0]);
        }
    });

    // ==========================================
    // 🧠 Core Logic: ฟังก์ชันอ่านและประมวลผลไฟล์
    // ==========================================
    function handleFile(file) {
        
        // ตรวจสอบนามสกุลไฟล์เบื้องต้น
        const fileName = file.name;
        const fileExt = fileName.split('.').pop().toLowerCase();
        
        if (fileExt !== 'xlsx' && fileExt !== 'xls') {
            Swal.fire({
                icon: 'error',
                title: 'ไฟล์ไม่ถูกต้อง',
                text: 'กรุณาอัปโหลดเฉพาะไฟล์ Excel (.xlsx หรือ .xls) เท่านั้น',
                background: '#0f172a',
                color: '#f8fafc'
            });
            return;
        }

        // แสดง Loading
        Swal.fire({
            title: 'กำลังประมวลผลไฟล์...',
            text: 'ระบบกำลังอ่านข้อมูลจาก Excel',
            background: '#0f172a',
            color: '#f8fafc',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        // ใช้ FileReader เพื่ออ่านไฟล์เป็น Binary
        const reader = new FileReader();
        
        reader.onload = (e) => {
            try {
                const data = e.target.result;
                
                // ใช้ SheetJS (XLSX) เพื่ออ่านข้อมูล Binary
                const workbook = XLSX.read(data, { type: 'binary' });
                
                // ดึงข้อมูลจาก Sheet แรกสุด
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                // แปลงข้อมูลใน Sheet เป็น JSON Array (โดยบรรทัดแรกจะเป็น Key)
                const jsonData = XLSX.utils.sheet_to_json(worksheet, { defval: "" });
                
                // นำ JSON ดิบ ไปเข้ากระบวนการตรวจสอบ (Validation)
                validateAndRenderData(jsonData);
                
                Swal.close();
                
            } catch (error) {
                console.error("Excel Parsing Error: ", error);
                Swal.fire({
                    icon: 'error',
                    title: 'อ่านไฟล์ล้มเหลว',
                    text: 'โครงสร้างไฟล์อาจเกิดความเสียหาย โปรดตรวจสอบ',
                    background: '#0f172a',
                    color: '#f8fafc'
                });
            }
        };

        reader.readAsBinaryString(file);
    }

    // ==========================================
    // 🔍 ฟังก์ชันตรวจสอบความถูกต้องของข้อมูล (Validation)
    // ==========================================
    function validateAndRenderData(data) {
        
        parsedSchedules = [];
        let hasError = false;

        // วนลูปอ่านข้อมูลทีละแถว
        data.forEach((row, index) => {
            
            // ข้ามแถวที่ว่างเปล่า (ไม่มีรหัสวิชา หรือ รหัสครู)
            if (!row['รหัสครู'] || !row['รหัสวิชา']) return;

            // แปลงค่า วันที่ จากข้อความให้เป็นตัวเลข 1-5 เพื่อบันทึกลง Database
            let rawDay = String(row['วัน']).trim();
            let dayNum = 1;
            
            if (rawDay === '1' || rawDay.includes('จันทร์')) dayNum = 1;
            else if (rawDay === '2' || rawDay.includes('อังคาร')) dayNum = 2;
            else if (rawDay === '3' || rawDay.includes('พุธ')) dayNum = 3;
            else if (rawDay === '4' || rawDay.includes('พฤหัส')) dayNum = 4;
            else if (rawDay === '5' || rawDay.includes('ศุกร์')) dayNum = 5;

            // ตรวจสอบคาบเริ่ม และคาบจบ
            let startPeriod = parseInt(row['คาบเริ่ม']) || 1;
            let endPeriod = parseInt(row['คาบสิ้นสุด']) || startPeriod;

            // หากกรอกผิดพลาด ให้คาบจบเท่ากับคาบเริ่ม (เรียนคาบเดียว)
            if (endPeriod < startPeriod) {
                endPeriod = startPeriod;
            }

            // จัดเตรียม Object ให้ตรงกับ Backend API
            let scheduleData = {
                teacher_code: String(row['รหัสครู']).trim(),
                teacher_name: String(row['ชื่อ-สกุล']).trim(),
                day: dayNum,
                start_period: startPeriod,
                end_period: endPeriod,
                subject_code: String(row['รหัสวิชา']).trim(),
                class_room: String(row['ชั้นเรียน'] || '').trim(),
                room_number: String(row['ห้องเรียน'] || '').trim(),
                is_activity: (row['เป็นกิจกรรม'] == '1' || String(row['เป็นกิจกรรม']).trim().toLowerCase() == 'yes') ? 1 : 0
            };

            parsedSchedules.push(scheduleData);
        });

        // หากไม่มีข้อมูลที่ใช้ได้เลย
        if (parsedSchedules.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'ไม่พบข้อมูลที่ถูกต้อง',
                text: 'โปรดตรวจสอบชื่อคอลัมน์ (หัวตาราง) ใน Excel ว่าตรงกับ Template หรือไม่',
                background: '#0f172a',
                color: '#f8fafc'
            });
            return;
        }

        // แสดงผลตาราง Preview
        renderPreviewTable();
    }

    // ==========================================
    // 📊 ฟังก์ชันวาดตาราง Preview
    // ==========================================
    function renderPreviewTable() {
        
        previewBody.innerHTML = '';
        
        const dayNames = ['ไม่ระบุ', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'];

        parsedSchedules.forEach(item => {
            
            // จัดการข้อความคาบเรียน (เช่น คาบ 1-2 หรือ คาบ 3)
            let periodText = item.start_period === item.end_period 
                             ? item.start_period 
                             : `${item.start_period} - ${item.end_period}`;

            // ป้ายกำกับประเภทวิชา
            let badgeClass = item.is_activity === 1 ? 'badge-act' : 'badge-normal';
            let badgeText = item.is_activity === 1 ? 'กิจกรรม' : 'วิชาเรียน';

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-family:'Share Tech Mono'; color:var(--primary); font-weight:bold;">${item.teacher_code}</td>
                <td>${item.teacher_name}</td>
                <td>${dayNames[item.day] || item.day}</td>
                <td style="font-weight:bold;">${periodText}</td>
                <td style="font-family:'Share Tech Mono';">${item.subject_code}</td>
                <td>${item.class_room || '-'}</td>
                <td>${item.room_number || '-'}</td>
                <td><span class="${badgeClass}">${badgeText}</span></td>
            `;
            
            previewBody.appendChild(tr);
        });

        // อัปเดตจำนวนและแสดงส่วน Preview
        rowCountBadge.innerText = `พบข้อมูลพร้อมบันทึก ${parsedSchedules.length} คาบเรียน`;
        previewSection.style.display = 'block';
        
        // เลื่อนหน้าจอลงมาที่ตาราง
        previewSection.scrollIntoView({ behavior: 'smooth' });
    }

    // ==========================================
    // ✖️ ฟังก์ชันยกเลิก
    // ==========================================
    function resetImport() {
        parsedSchedules = [];
        fileInput.value = '';
        previewSection.style.display = 'none';
        previewBody.innerHTML = '';
    }

    // ==========================================
    // 📤 ฟังก์ชันส่งข้อมูลไปยัง Backend (PHP)
    // ==========================================
    function uploadDataToServer() {
        
        if (parsedSchedules.length === 0) return;

        const year = selectYear.value;
        const term = selectTerm.value;

        Swal.fire({
            title: 'ยืนยันการบันทึก?',
            text: `คุณกำลังจะบันทึกตารางสอนจำนวน ${parsedSchedules.length} คาบ ในปีการศึกษา ${year} ภาคเรียนที่ ${term}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#8b5cf6',
            cancelButtonColor: '#ef4444',
            confirmButtonText: 'ยืนยันการบันทึก',
            cancelButtonText: 'ยกเลิก',
            background: '#0f172a',
            color: '#f8fafc'
        }).then((result) => {
            
            if (result.isConfirmed) {
                
                // ปิดปุ่มป้องกันการกดซ้ำซ้อน
                btnConfirm.disabled = true;
                btnConfirm.innerHTML = '⏳ กำลังบันทึกข้อมูล...';

                const payload = {
                    academic_year: year,
                    term: term,
                    schedules: parsedSchedules
                };

                // ส่งคำขอไปยังไฟล์นี้เอง (import_timetable.php)
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'บันทึกสำเร็จ!',
                            text: data.message,
                            background: '#0f172a',
                            color: '#f8fafc'
                        }).then(() => {
                            // โหลดหน้าใหม่เพื่อความสะอาด
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: data.message,
                            background: '#0f172a',
                            color: '#f8fafc'
                        });
                        btnConfirm.disabled = false;
                        btnConfirm.innerHTML = '💾 ยืนยันการบันทึกข้อมูล';
                    }
                })
                .catch(error => {
                    console.error('Upload Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'ไม่สามารถติดต่อกับเซิร์ฟเวอร์ได้ โปรดลองอีกครั้ง',
                        background: '#0f172a',
                        color: '#f8fafc'
                    });
                    btnConfirm.disabled = false;
                    btnConfirm.innerHTML = '💾 ยืนยันการบันทึกข้อมูล';
                });
            }
        });
    }

    // ==========================================
    // 📥 ฟังก์ชันสร้างและดาวน์โหลด Template Excel ทันที
    // ==========================================
    function downloadTemplate() {
        
        // กำหนดหัวตาราง
        const header = ["รหัสครู", "ชื่อ-สกุล", "วัน", "คาบเริ่ม", "คาบสิ้นสุด", "รหัสวิชา", "ชั้นเรียน", "ห้องเรียน", "เป็นกิจกรรม"];
        
        // ใส่ข้อมูลตัวอย่าง 1 แถวเพื่อเป็น Guideline ให้ครูดู
        const sampleData = [
            ["301", "นางสาวพิมพ์ประกาย บุญสะอาด", "1", "1", "2", "ว32223", "5/1", "536", "0"]
        ];

        // สร้าง Worksheet
        const ws = XLSX.utils.aoa_to_sheet([header, ...sampleData]);
        
        // ปรับความกว้างคอลัมน์ให้อ่านง่าย
        ws['!cols'] = [
            { wch: 10 }, { wch: 30 }, { wch: 10 }, { wch: 10 }, 
            { wch: 10 }, { wch: 15 }, { wch: 15 }, { wch: 15 }, { wch: 15 }
        ];

        // สร้าง Workbook
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Timetable_Template");

        // สั่งดาวน์โหลด
        XLSX.writeFile(wb, "timetable_template.xlsx");
    }

</script>

<?php require_once 'footer.php'; ?>