<?php
/**
 * view_timetable.php - ระบบแสดงผลตารางสอน (Smart Timetable UI - Phase 4)
 * สถาปัตยกรรม: AJAX Fetch + Dynamic DOM Rendering + Print Media CSS
 * 🚨 กฎเหล็ก: เขียนโค้ดแบบกระจายเต็มรูปแบบ (Unminified) ห้ามย่อบรรทัด
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
// 🔐 UNIFIED MIDDLEWARE: ตรวจสอบสิทธิ์ และแยกบทบาท
// =========================================================================
$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';
$class_id = $_SESSION['class_id'] ?? 0;

$is_admin = false;
if (in_array($role, ['developer', 'teacher'])) {
    $is_admin = true; 
} else {
    try {
        $stmt_check = $pdo->prepare("
            SELECT COUNT(cs.id) 
            FROM class_schedules cs 
            JOIN subjects s ON cs.subject_id = s.id 
            WHERE cs.class_id = ? AND (s.subject_name LIKE '%เคมี%' OR s.subject_code LIKE '%ว30221%')
        ");
        $stmt_check->execute([$class_id]);
        if ($stmt_check->fetchColumn() == 0) {
            header("Location: dashboard_student.php?error=access_denied");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Access Check Error: " . $e->getMessage());
    }
}

// =========================================================================
// 📥 ดึงรายชื่อครูทั้งหมดเพื่อมาใส่ใน Dropdown ให้แอดมินเลือกดู
// =========================================================================
$teachers_list = [];
try {
    $stmt_teachers = $pdo->query("
        SELECT teacher_code, full_name 
        FROM teachers 
        ORDER BY full_name ASC
    ");
    if ($stmt_teachers) {
        $teachers_list = $stmt_teachers->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Fetch Teachers Error: " . $e->getMessage());
}

$page_title = "ดูตารางสอนครู - Smart School Plus";
require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    /* ============================================================
       📍 CSS ARCHITECTURE FOR TIMETABLE (Phase 4)
       ============================================================ */
    :root {
        --bg-main: #0f172a;
        --bg-panel: #1e293b;
        --border-color: #334155;
        --primary: #38bdf8;
        --primary-glow: rgba(56, 189, 248, 0.4);
        --text-light: #f8fafc;
        --text-muted: #94a3b8;
        --accent: #8b5cf6;
        
        /* สีตารางสอน (อิงกระดาษขาวดำ / สไตล์โรงเรียน) */
        --tt-bg: #ffffff;
        --tt-text: #000000;
        --tt-border: #000000;
        
        /* สีประจำวันของไทย */
        --day-mon: #fef08a; /* เหลือง */
        --day-tue: #fbcfe8; /* ชมพู */
        --day-wed: #bbf7d0; /* เขียว */
        --day-thu: #fed7aa; /* ส้ม */
        --day-fri: #bfdbfe; /* ฟ้า */
    }

    body {
        background-color: var(--bg-main);
        color: var(--text-light);
        font-family: 'Prompt', sans-serif;
        margin: 0;
        padding: 0;
    }

    .main-wrapper {
        max-width: 1400px;
        margin: 30px auto;
        padding: 0 20px;
    }

    /* -------------------------------------------
       🎛️ Control Panel (แถบเครื่องมือค้นหา)
       ------------------------------------------- */
    .control-panel {
        background-color: var(--bg-panel);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 30px;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: flex-end;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }

    .input-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
        flex: 1;
        min-width: 200px;
    }

    .input-group label {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--primary);
        letter-spacing: 0.5px;
    }

    .input-group select, 
    .input-group input {
        background-color: var(--bg-main);
        border: 1px solid var(--border-color);
        color: var(--text-light);
        padding: 12px 15px;
        border-radius: 8px;
        font-family: 'Prompt', sans-serif;
        font-size: 1rem;
        outline: none;
        transition: all 0.3s ease;
    }

    .input-group select:focus, 
    .input-group input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 10px var(--primary-glow);
    }

    .btn-search {
        background: linear-gradient(135deg, var(--primary), #2563eb);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-family: 'Prompt', sans-serif;
        font-weight: bold;
        font-size: 1.1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        height: 47px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 5px 15px rgba(56, 189, 248, 0.3);
    }

    .btn-search:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(56, 189, 248, 0.5);
    }

    .btn-print {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 8px;
        font-family: 'Prompt', sans-serif;
        font-weight: bold;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        height: 47px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-print:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
    }

    /* -------------------------------------------
       📊 Timetable Board (กระดานตารางสอน)
       ------------------------------------------- */
    .timetable-board {
        background-color: var(--tt-bg); /* พื้นหลังขาว */
        border-radius: 10px;
        padding: 40px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.8);
        overflow-x: auto;
        display: none; /* ซ่อนไว้ก่อนจนกว่าจะกดค้นหา */
        animation: fadeIn 0.5s ease-out forwards;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* หัวกระดาษตารางสอน */
    .board-header {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        margin-bottom: 20px;
        color: var(--tt-text);
        gap: 20px;
    }

    .school-logo {
        width: 80px;
        height: 80px;
        border: 2px solid var(--tt-border);
        border-radius: 5px;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 2rem;
        background: #f1f5f9;
    }

    .teacher-info-title {
        font-family: 'Sarabun', sans-serif;
        font-size: 2rem;
        font-weight: bold;
        margin: 0;
        letter-spacing: 1px;
    }

    /* โครงสร้างตารางหลัก */
    .timetable-grid {
        width: 100%;
        border-collapse: collapse;
        font-family: 'Sarabun', sans-serif;
        color: var(--tt-text);
    }

    .timetable-grid th, 
    .timetable-grid td {
        border: 2px solid var(--tt-border);
        text-align: center;
        padding: 8px 5px;
        vertical-align: middle;
    }

    /* ตกแต่งหัวตาราง (Thead) */
    .timetable-grid thead th {
        font-weight: bold;
        background-color: #f8fafc;
    }

    .period-number {
        font-size: 1.5rem;
        font-weight: 700;
        display: block;
        margin-bottom: 5px;
    }

    .time-range {
        font-size: 0.85rem;
        font-family: 'Share Tech Mono', 'Sarabun', monospace;
        letter-spacing: -0.5px;
        color: #475569;
    }

    /* ตกแต่งแถวข้อมูล (Tbody) */
    .day-column {
        font-weight: bold;
        font-size: 1.5rem;
        width: 60px;
    }

    .cell-content {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 3px;
        min-height: 80px;
    }

    .subj-code {
        font-size: 1.2rem;
        font-weight: bold;
        letter-spacing: 0.5px;
    }

    .class-room {
        font-size: 1.1rem;
        font-weight: 600;
    }

    .room-number {
        font-size: 1.1rem;
    }

    /* กรณีที่เป็นกิจกรรม */
    .is-activity .subj-code {
        font-size: 1.1rem;
    }
    .is-activity .class-room {
        font-size: 1.1rem;
    }

    /* กรณีช่องว่าง */
    .cell-empty {
        background-color: #ffffff;
    }

    /* สีประจำวัน */
    .day-1 { background-color: var(--day-mon) !important; }
    .day-2 { background-color: var(--day-tue) !important; }
    .day-3 { background-color: var(--day-wed) !important; }
    .day-4 { background-color: var(--day-thu) !important; }
    .day-5 { background-color: var(--day-fri) !important; }

    /* โหมดแสดงสถานะกำลังโหลด */
    .loading-state {
        display: none;
        text-align: center;
        padding: 50px;
        color: var(--primary);
        font-size: 1.5rem;
        font-weight: bold;
    }

    /* -------------------------------------------
       🖨️ PRINT CSS (สำหรับการปริ้นต์กระดาษ A4)
       ------------------------------------------- */
    @media print {
        /* ซ่อนทุกอย่างที่ไม่เกี่ยวกับตาราง */
        body * {
            visibility: hidden;
        }
        
        /* นำตารางออกมาโชว์และจัดตำแหน่งให้เต็มหน้า */
        .timetable-board, .timetable-board * {
            visibility: visible;
        }
        
        .timetable-board {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            margin: 0;
            padding: 0;
            box-shadow: none;
            border-radius: 0;
        }

        /* ปรับขนาดฟอนต์ให้พอดีกระดาษแนวนอน (Landscape) */
        @page {
            size: A4 landscape;
            margin: 1cm;
        }

        .timetable-grid th, 
        .timetable-grid td {
            border: 2px solid #000 !important;
            padding: 5px !important;
        }
    }

</style>

<div class="main-wrapper">

    <div class="control-panel">
        
        <div class="input-group" style="flex: 2;">
            <label for="searchTeacher">👨‍🏫 เลือกครูผู้สอน</label>
            <select id="searchTeacher">
                <option value="">-- กรุณาเลือกครูผู้สอน --</option>
                <?php foreach ($teachers_list as $t): ?>
                    <option value="<?= htmlspecialchars($t['teacher_code']) ?>">
                        รหัส <?= htmlspecialchars($t['teacher_code']) ?> - <?= htmlspecialchars($t['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group">
            <label for="searchYear">📅 ปีการศึกษา</label>
            <input type="number" id="searchYear" value="<?= date('Y') + 543 ?>" min="2560" max="2600">
        </div>

        <div class="input-group">
            <label for="searchTerm">ภาคเรียน</label>
            <select id="searchTerm">
                <option value="1" selected>ภาคเรียนที่ 1</option>
                <option value="2">ภาคเรียนที่ 2</option>
                <option value="3">ภาคเรียนฤดูร้อน</option>
            </select>
        </div>

        <button class="btn-search" onclick="fetchTimetableData()">
            🔍 ค้นหาตารางสอน
        </button>

        <button class="btn-print" onclick="window.print()" id="btnPrint" style="display: none;">
            🖨️ พิมพ์ตาราง
        </button>

    </div>

    <div class="loading-state" id="loadingState">
        กำลังดึงข้อมูลและประมวลผล Colspan Matrix... ⏳
    </div>

    <div class="timetable-board" id="timetableBoard">
        
        <div class="board-header">
            <div class="school-logo">🏫</div>
            <h1 class="teacher-info-title" id="ttTitle">
                รอการค้นหาข้อมูล...
            </h1>
        </div>

        <table class="timetable-grid" id="mainTimetable">
            <thead id="ttHead">
                </thead>
            <tbody id="ttBody">
                </tbody>
        </table>

    </div>

</div>

<script>
    
    // ดึง Elements
    const board = document.getElementById('timetableBoard');
    const loading = document.getElementById('loadingState');
    const btnPrint = document.getElementById('btnPrint');
    const ttTitle = document.getElementById('ttTitle');
    const ttHead = document.getElementById('ttHead');
    const ttBody = document.getElementById('ttBody');

    // ==========================================
    // 🚀 ฟังก์ชันเรียก API ไปยังระยะที่ 3
    // ==========================================
    function fetchTimetableData() {
        
        const teacherCode = document.getElementById('searchTeacher').value;
        const year = document.getElementById('searchYear').value;
        const term = document.getElementById('searchTerm').value;

        if (!teacherCode || !year || !term) {
            Swal.fire({
                icon: 'warning',
                title: 'ข้อมูลไม่ครบถ้วน',
                text: 'กรุณาเลือกครูผู้สอนและปีการศึกษาให้ครบก่อนค้นหา',
                background: '#0f172a',
                color: '#fff'
            });
            return;
        }

        // ซ่อนตาราง โชว์ Loading
        board.style.display = 'none';
        btnPrint.style.display = 'none';
        loading.style.display = 'block';

        // 🚨 สังเกตการเรียกใช้ API: ไปที่ api_get_schedule.php (Phase 3)
        const apiUrl = `api_get_schedule.php?api_mode=1&teacher_code=${encodeURIComponent(teacherCode)}&year=${encodeURIComponent(year)}&term=${encodeURIComponent(term)}`;

        fetch(apiUrl)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    // ประมวลผลสำเร็จ วาดตาราง!
                    renderTimetable(data.data);
                } else {
                    // ไม่พบข้อมูล หรือ Error
                    loading.style.display = 'none';
                    Swal.fire({
                        icon: 'error',
                        title: 'ไม่พบข้อมูล',
                        text: data.message,
                        background: '#0f172a',
                        color: '#fff'
                    });
                }
            })
            .catch(error => {
                console.error('Fetch Error:', error);
                loading.style.display = 'none';
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดระบบ',
                    text: 'ไม่สามารถติดต่อ API เพื่อดึงข้อมูลได้',
                    background: '#0f172a',
                    color: '#fff'
                });
            });
    }

    // ==========================================
    // 🎨 ฟังก์ชันวาดตารางลง DOM (Dynamic Render)
    // ==========================================
    function renderTimetable(payload) {
        
        // 1. ดึงตัวแปรจาก Payload
        const teacher = payload.teacher;
        const timeSlots = payload.time_slots;
        const matrix = payload.timetable_matrix;
        const daysMap = payload.days_mapping;
        const acYear = payload.academic_info.year;
        const acTerm = payload.academic_info.term;

        // 2. อัปเดตหัวกระดาษ
        ttTitle.innerText = `${teacher.teacher_code} ${teacher.full_name} ภาคเรียนที่ ${acTerm}/${acYear}`;

        // 3. วาด <thead> (แถว 1: คาบที่, แถว 2: ช่วงเวลา)
        ttHead.innerHTML = '';
        
        let trPeriods = document.createElement('tr');
        let trTimes = document.createElement('tr');

        // คอลัมน์แรกสุด (เว้นว่างไว้สำหรับใส่คำว่า วัน จ. อ. พ. พฤ. ศ.)
        let thCorner = document.createElement('th');
        thCorner.rowSpan = 2; // ผสาน 2 แถว
        thCorner.innerHTML = '<span style="font-size:1.5rem; letter-spacing:2px;">วัน / คาบ</span>';
        thCorner.style.width = '100px';
        trPeriods.appendChild(thCorner);

        // ลูปสร้างหัวตารางแต่ละคาบ
        timeSlots.forEach(slot => {
            
            // แถวคาบเรียน (1, 2, 3...)
            let thP = document.createElement('th');
            thP.innerHTML = `<span class="period-number">${slot.period_number}</span>`;
            trPeriods.appendChild(thP);

            // แถวช่วงเวลา (08.30-09.20...)
            let thT = document.createElement('th');
            thT.innerHTML = `<span class="time-range">${slot.start_time} - ${slot.end_time}</span>`;
            trTimes.appendChild(thT);

        });

        ttHead.appendChild(trPeriods);
        ttHead.appendChild(trTimes);

        // 4. วาด <tbody> (แถววัน จันทร์-ศุกร์)
        ttBody.innerHTML = '';

        // ตัวอักษรย่อของวัน
        const dayShortNames = {
            1: 'จ.',
            2: 'อ.',
            3: 'พ.',
            4: 'พฤ.',
            5: 'ศ.'
        };

        for (let dayNum = 1; dayNum <= 5; dayNum++) {
            
            let trDay = document.createElement('tr');

            // 4.1 วาดคอลัมน์แรก (ชื่อย่อวัน)
            let tdDay = document.createElement('td');
            tdDay.className = `day-column day-${dayNum}`;
            tdDay.innerText = dayShortNames[dayNum] || dayNum;
            trDay.appendChild(tdDay);

            // 4.2 วาดช่องตารางของแต่ละคาบในวันนั้นๆ
            // 🚨 หัวใจหลักของการทำ Colspan อยู่ตรงนี้!
            
            if (matrix[dayNum]) {
                
                timeSlots.forEach(slot => {
                    
                    let pNum = slot.period_number;
                    let cellData = matrix[dayNum][pNum];

                    if (!cellData) return; // กันพังถ้าไม่มีข้อมูลคาบนี้ใน matrix

                    // 🚨 ถ้าชนิดเป็น 'skip' แปลว่ามันถูกผสาน (Colspan) จากช่องก่อนหน้าไปแล้ว 
                    // ดังนั้น "ห้ามสร้าง <td>" เด็ดขาด
                    if (cellData.type === 'skip') {
                        return; // ข้ามลูปไปคาบถัดไปเลย
                    }

                    let tdCell = document.createElement('td');

                    // ใส่ Colspan ถ้ามี
                    if (cellData.colspan > 1) {
                        tdCell.colSpan = cellData.colspan;
                    }

                    // จัดการเนื้อหาตามประเภท
                    if (cellData.type === 'class') {
                        
                        // เป็นวิชาเรียน หรือ กิจกรรม
                        let actClass = cellData.is_activity === 1 ? 'is-activity' : '';
                        
                        tdCell.innerHTML = `
                            <div class="cell-content ${actClass}">
                                <div class="subj-code">${cellData.subject_code}</div>
                                <div class="class-room">${cellData.class_room || ''}</div>
                                <div class="room-number">${cellData.room_number || ''}</div>
                            </div>
                        `;

                    } else if (cellData.type === 'break') {
                        
                        // คาบพัก
                        tdCell.innerHTML = `
                            <div class="cell-content" style="color:var(--border-color); font-weight:bold;">
                                ${cellData.label || 'พัก'}
                            </div>
                        `;

                    } else {
                        // คาบว่าง (Empty)
                        tdCell.className = 'cell-empty';
                        tdCell.innerHTML = `<div class="cell-content"></div>`;
                    }

                    trDay.appendChild(tdCell);
                });
            }

            ttBody.appendChild(trDay);
        }

        // 5. ปิด Loading และโชว์ตาราง
        loading.style.display = 'none';
        board.style.display = 'block';
        btnPrint.style.display = 'flex'; // โชว์ปุ่มปริ้นต์

        // เลื่อนหน้าจอลงมาให้เห็นตารางเต็มๆ
        board.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

</script>

<?php require_once 'footer.php'; ?>