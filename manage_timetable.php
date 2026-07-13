<?php
/**
 * manage_timetable.php - ระบบตารางสอนอัจฉริยะ (Clean Architecture)
 * ────────────────────────────────────────────────────────────
 * Bug ที่แก้:
 *   - เปลี่ยน teacher_schedules → schedules (ตารางที่มีอยู่จริงใน DB)
 *   - schedules.teacher_id → FK users.id (ไม่ใช่ teachers.id)
 *   - JOIN teachers t ON t.user_id = s.teacher_id (ผ่าน teachers table)
 *   - ลบ error_reporting / isset($pdo) / hardcode username guard
 *   - เปลี่ยน class_room → ดึงจาก classes table ผ่าน class_id
 * ────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireRole(['developer', 'teacher']);

$role     = $_SESSION['role'] ?? '';
$is_admin = in_array($role, ['developer', 'teacher']);

// ── AJAX Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'];

    // ── AJAX 1: ดึงตารางสอน ──────────────────────────────────
    if ($action === 'fetch_timetable') {
        $search_mode = trim($_POST['search_mode'] ?? 'teacher');
        $search_val  = trim($_POST['search_value'] ?? '');
        $year        = trim($_POST['year'] ?? '');
        $term        = trim($_POST['term'] ?? '');

        if (empty($search_val) || empty($year) || empty($term)) {
            echo json_encode(['status' => 'error', 'message' => 'พารามิเตอร์ไม่ครบถ้วน']);
            exit;
        }

        try {
            $time_slots = $pdo->query('SELECT * FROM time_slots ORDER BY period_number ASC')->fetchAll();

            if ($search_mode === 'teacher') {
                // ค้นหาตาม teacher_code (ผ่าน teachers → users)
                $stmt = $pdo->prepare('
                    SELECT s.*, t.teacher_code, t.full_name,
                           c.class_name AS class_room
                    FROM schedules s
                    JOIN teachers t ON t.user_id = s.teacher_id
                    LEFT JOIN classes c ON c.id = s.class_id
                    WHERE t.teacher_code = ?
                      AND s.academic_year = ?
                      AND s.term = ?
                    ORDER BY s.day_of_week ASC, s.start_period ASC
                ');
                $stmt->execute([$search_val, $year, $term]);
                $schedules = $stmt->fetchAll();

                $stmt_t = $pdo->prepare('SELECT full_name FROM teachers WHERE teacher_code = ? LIMIT 1');
                $stmt_t->execute([$search_val]);
                $teacher_name = $stmt_t->fetchColumn() ?: $search_val;
                $header_title = "{$search_val} {$teacher_name} ภาคเรียนที่ {$term}/{$year}";

            } else {
                // ค้นหาตาม class_id
                $stmt = $pdo->prepare('
                    SELECT s.*, t.teacher_code, t.full_name,
                           c.class_name AS class_room
                    FROM schedules s
                    JOIN teachers t ON t.user_id = s.teacher_id
                    LEFT JOIN classes c ON c.id = s.class_id
                    WHERE s.class_id = ?
                      AND s.academic_year = ?
                      AND s.term = ?
                    ORDER BY s.day_of_week ASC, s.start_period ASC
                ');
                $stmt->execute([$search_val, $year, $term]);
                $schedules = $stmt->fetchAll();

                $stmt_c = $pdo->prepare('SELECT class_name FROM classes WHERE id = ? LIMIT 1');
                $stmt_c->execute([$search_val]);
                $class_name   = $stmt_c->fetchColumn() ?: $search_val;
                $header_title = "ตารางเรียนชั้น {$class_name} ภาคเรียนที่ {$term}/{$year}";
            }

            // สร้าง Matrix
            $matrix = [];
            for ($d = 1; $d <= 5; $d++) {
                $matrix[$d] = [];
                foreach ($time_slots as $slot) {
                    $p = $slot['period_number'];
                    $matrix[$d][$p] = [
                        'schedule_id'  => null,
                        'type'         => $slot['is_break'] ? 'break' : 'empty',
                        'label'        => $slot['is_break'] ? $slot['label'] : '',
                        'colspan'      => 1,
                        'subject_code' => '',
                        'class_room'   => '',
                        'room_number'  => '',
                        'is_activity'  => 0,
                        'teacher_name' => '',
                        'start_period' => $p,
                        'end_period'   => $p,
                    ];
                }
            }

            foreach ($schedules as $sch) {
                $d  = (int)$sch['day_of_week'];
                $sp = (int)$sch['start_period'];
                $ep = (int)$sch['end_period'];
                if (!isset($matrix[$d][$sp])) continue;

                $colspan = ($ep - $sp) + 1;
                $matrix[$d][$sp] = [
                    'schedule_id'  => $sch['id'],
                    'type'         => 'class',
                    'colspan'      => $colspan,
                    'subject_code' => $sch['subject_code'],
                    'class_room'   => $sch['class_room'] ?? '',
                    'room_number'  => $sch['room_number'] ?? '',
                    'is_activity'  => (int)$sch['is_activity'],
                    'teacher_name' => $sch['full_name'],
                    'start_period' => $sp,
                    'end_period'   => $ep,
                    'label'        => '',
                ];

                for ($i = $sp + 1; $i <= $ep; $i++) {
                    if (isset($matrix[$d][$i])) $matrix[$d][$i]['type'] = 'skip';
                }
            }

            echo json_encode([
                'status'       => 'success',
                'header_title' => $header_title,
                'time_slots'   => $time_slots,
                'matrix'       => $matrix,
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // ── AJAX 2: บันทึก/อัปเดตคาบ ─────────────────────────────
    if ($action === 'save_schedule') {
        if (!$is_admin) { echo json_encode(['status' => 'error', 'message' => 'Access Denied']); exit; }

        $id           = (int)($_POST['schedule_id'] ?? 0);
        $teacher_code = trim($_POST['teacher_code'] ?? '');
        $year         = trim($_POST['year'] ?? '');
        $term         = trim($_POST['term'] ?? '');
        $day          = (int)($_POST['day'] ?? 1);
        $start_p      = (int)($_POST['start_period'] ?? 1);
        $end_p        = (int)($_POST['end_period'] ?? 1);
        $subject      = trim($_POST['subject_code'] ?? '');
        $class_id     = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $room         = trim($_POST['room_number'] ?? '');
        $is_act       = (int)($_POST['is_activity'] ?? 0);

        if (empty($teacher_code) || empty($subject)) {
            echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกรหัสครูและรหัสวิชา']);
            exit;
        }

        try {
            // หา user_id ของครูผ่าน teachers table
            $stmt_t = $pdo->prepare('SELECT user_id FROM teachers WHERE teacher_code = ? LIMIT 1');
            $stmt_t->execute([$teacher_code]);
            $teacher_user_id = $stmt_t->fetchColumn();

            if (!$teacher_user_id) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสครูนี้ในระบบ']);
                exit;
            }

            if ($id > 0) {
                $pdo->prepare('
                    UPDATE schedules
                    SET start_period=?, end_period=?, subject_code=?, class_id=?, room_number=?, is_activity=?
                    WHERE id=?
                ')->execute([$start_p, $end_p, $subject, $class_id, $room, $is_act, $id]);
            } else {
                $pdo->prepare('
                    INSERT INTO schedules
                        (teacher_id, academic_year, term, day_of_week, start_period, end_period, subject_code, class_id, room_number, is_activity)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([$teacher_user_id, $year, $term, $day, $start_p, $end_p, $subject, $class_id, $room, $is_act]);
            }

            echo json_encode(['status' => 'success', 'message' => 'บันทึกข้อมูลเรียบร้อย']);
            exit;

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // ── AJAX 3: ลบคาบ ────────────────────────────────────────
    if ($action === 'delete_schedule') {
        if (!$is_admin) { echo json_encode(['status' => 'error', 'message' => 'Access Denied']); exit; }
        $id = (int)($_POST['schedule_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM schedules WHERE id = ?')->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'ลบคาบเรียนสำเร็จ']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบ ID']);
        }
        exit;
    }
}

// ── Dropdown สำหรับค้นหา ─────────────────────────────────────
$teachers_list = [];
$classes_list  = [];
try {
    $teachers_list = $pdo->query('
        SELECT t.teacher_code, t.full_name
        FROM teachers t
        ORDER BY t.teacher_code ASC
    ')->fetchAll();

    $classes_list = $pdo->query('
        SELECT DISTINCT c.id, c.class_name
        FROM classes c
        JOIN schedules s ON s.class_id = c.id
        ORDER BY c.class_name ASC
    ')->fetchAll();

} catch (PDOException $e) {
    error_log('[manage_timetable] dropdown: ' . $e->getMessage());
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* ============================================================
       📍 CSS ARCHITECTURE (Phase 5 - Interactive & Print Perfect)
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
        
        /* สีกระดาษตารางสอน (Print Mode) */
        --tt-bg: #ffffff;
        --tt-text: #000000;
        --tt-border: #000000;
        
        /* สีประจำวันของไทย (Pastel สำหรับจอ Screen) */
        --day-mon: #fef08a; /* จันทร์ - เหลือง */
        --day-tue: #fbcfe8; /* อังคาร - ชมพู */
        --day-wed: #bbf7d0; /* พุธ - เขียว */
        --day-thu: #fed7aa; /* พฤหัส - ส้ม */
        --day-fri: #bfdbfe; /* ศุกร์ - ฟ้า */
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
       🎛️ Control Panel (แถบเครื่องมือค้นหา 2 ระบบ)
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

    .search-toggle {
        display: flex;
        background: #020617;
        border-radius: 10px;
        padding: 5px;
        border: 1px solid var(--border-color);
        width: 100%;
        margin-bottom: -10px;
    }

    .toggle-btn {
        flex: 1;
        background: transparent;
        color: var(--text-muted);
        border: none;
        padding: 10px;
        border-radius: 8px;
        cursor: pointer;
        font-family: 'Prompt', sans-serif;
        font-weight: 600;
        transition: all 0.3s;
    }

    .toggle-btn.active {
        background: var(--primary);
        color: white;
        box-shadow: 0 0 10px var(--primary-glow);
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
    }

    .btn-action {
        background: linear-gradient(135deg, var(--primary), #2563eb);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-family: 'Prompt', sans-serif;
        font-weight: bold;
        font-size: 1.1rem;
        cursor: pointer;
        height: 47px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 5px 15px rgba(56, 189, 248, 0.3);
        transition: transform 0.3s;
    }

    .btn-action:hover {
        transform: translateY(-2px);
    }

    .btn-print {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    /* -------------------------------------------
       📊 Timetable Board (กระดานตารางสอน)
       ------------------------------------------- */
    .timetable-board {
        background-color: var(--tt-bg);
        border-radius: 10px;
        padding: 40px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.8);
        overflow-x: auto;
        display: none; 
        animation: fadeIn 0.5s ease-out forwards;
        position: relative;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .board-header {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        margin-bottom: 20px;
        color: var(--tt-text);
        gap: 20px;
    }

    /* 🚨 ปรับ CSS ของตราโรงเรียนใหม่ให้รองรับการแสดงรูปภาพแบบเต็มใบ */
    .school-logo {
        width: 80px;
        height: 80px;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .school-logo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .teacher-info-title {
        font-family: 'Sarabun', sans-serif;
        font-size: 2rem;
        font-weight: bold;
        margin: 0;
        letter-spacing: 1px;
    }

    /* ตารางโครงสร้างหลัก */
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
        position: relative;
    }

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

    .day-column {
        font-weight: bold;
        font-size: 1.5rem;
        width: 60px;
    }

    /* Interactive Cell Effects */
    .cell-content {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 3px;
        min-height: 80px;
        width: 100%;
        height: 100%;
        cursor: pointer;
        transition: background 0.2s;
    }

    <?php if($is_admin): ?>
    .cell-content:hover {
        background-color: rgba(0,0,0,0.05);
        outline: 2px dashed var(--primary);
        outline-offset: -2px;
    }
    .cell-empty:hover {
        background-color: rgba(56, 189, 248, 0.1);
        cursor: pointer;
    }
    <?php endif; ?>

    .subj-code { font-size: 1.2rem; font-weight: bold; letter-spacing: 0.5px; }
    .class-room { font-size: 1.1rem; font-weight: 600; }
    .room-number { font-size: 1.1rem; }
    .teacher-subtext { font-size: 0.9rem; color: #475569; font-style: italic; }

    .is-activity .subj-code { font-size: 1.1rem; }
    .is-activity .class-room { font-size: 1.1rem; }
    .cell-empty { background-color: #ffffff; }

    .day-1 { background-color: var(--day-mon) !important; }
    .day-2 { background-color: var(--day-tue) !important; }
    .day-3 { background-color: var(--day-wed) !important; }
    .day-4 { background-color: var(--day-thu) !important; }
    .day-5 { background-color: var(--day-fri) !important; }

    .loading-state {
        display: none;
        text-align: center;
        padding: 50px;
        color: var(--primary);
        font-size: 1.5rem;
        font-weight: bold;
    }

    /* -------------------------------------------
       🖨️ PRINT CSS (พิมพ์ A4 แนวนอน สมบูรณ์แบบ)
       ------------------------------------------- */
    @media print {
        body { background-color: #fff; }
        .control-panel, .lab-subheader, .swal2-container, .navbar, .floating-btns, .sidebar-overlay, .mobile-sidebar { display: none !important; }
        
        .timetable-board {
            position: absolute;
            left: 0; top: 0;
            width: 100%;
            margin: 0; padding: 0;
            box-shadow: none; border-radius: 0;
            display: block !important;
        }

        @page {
            size: A4 landscape;
            margin: 10mm;
        }

        .timetable-grid th, .timetable-grid td {
            border: 2px solid #000 !important;
            padding: 5px !important;
        }
        
        .cell-content:hover { outline: none !important; background-color: transparent !important; }
    }

</style>

<div class="main-wrapper">

    <div class="control-panel">
        
        <div class="search-toggle">
            <button class="toggle-btn active" id="btnModeTeacher" onclick="switchSearchMode('teacher')">👨‍🏫 ค้นหาตามครูผู้สอน</button>
            <button class="toggle-btn" id="btnModeClass" onclick="switchSearchMode('class')">🏫 ค้นหาตามห้องเรียน</button>
        </div>

        <div class="input-group" style="flex: 2; margin-top: 15px;" id="divSearchTeacher">
            <label>เลือกครูผู้สอน</label>
            <select id="searchTeacher">
                <option value="">-- กรุณาเลือกครูผู้สอน --</option>
                <?php foreach ($teachers_list as $t): ?>
                    <option value="<?= htmlspecialchars($t['teacher_code']) ?>">รหัส <?= htmlspecialchars($t['teacher_code']) ?> - <?= htmlspecialchars($t['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group" style="flex: 2; margin-top: 15px; display: none;" id="divSearchClass">
            <label>เลือกห้องเรียน</label>
            <select id="searchClass">
                <option value="">-- กรุณาเลือกชั้น/ห้องเรียน --</option>
                <?php foreach ($classes_list as $c): ?>
                    <option value="<?= htmlspecialchars($c['class_room']) ?>">ชั้น <?= htmlspecialchars($c['class_room']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group" style="margin-top: 15px;">
            <label>ปีการศึกษา</label>
            <input type="number" id="searchYear" value="<?= date('Y') + 543 ?>" min="2560" max="2600">
        </div>

        <div class="input-group" style="margin-top: 15px;">
            <label>ภาคเรียน</label>
            <select id="searchTerm">
                <option value="1" selected>ภาคเรียนที่ 1</option>
                <option value="2">ภาคเรียนที่ 2</option>
            </select>
        </div>

        <button class="btn-action" onclick="fetchTimetableData()" style="margin-top: 15px;">
            🔍 ค้นหาตาราง
        </button>

        <button class="btn-action btn-print" onclick="window.print()" id="btnPrint" style="display: none; margin-top: 15px;">
            🖨️ พิมพ์ (Print)
        </button>

    </div>

    <div class="loading-state" id="loadingState">
        กำลังวาดโครงสร้างตารางและคำนวณ Matrix... ⏳
    </div>

    <div class="timetable-board" id="timetableBoard">
        <div class="board-header">
            <div class="school-logo">
                <img src="logo.png" alt="ตราโรงเรียนบ้านคาวิทยา">
            </div>
            <h1 class="teacher-info-title" id="ttTitle">รอการค้นหา...</h1>
        </div>

        <table class="timetable-grid" id="mainTimetable">
            <thead id="ttHead"></thead>
            <tbody id="ttBody"></tbody>
        </table>
    </div>

</div>

<script>
    
    // โหมดค้นหาปัจจุบัน ('teacher' หรือ 'class')
    let currentSearchMode = 'teacher';
    // ตัวแปรเก็บข้อมูลครูที่กำลังค้นหา (เผื่อใช้ตอน Edit)
    let currentTeacherCode = ''; 

    // ดึง Elements
    const divSearchTeacher = document.getElementById('divSearchTeacher');
    const divSearchClass = document.getElementById('divSearchClass');
    const btnModeTeacher = document.getElementById('btnModeTeacher');
    const btnModeClass = document.getElementById('btnModeClass');
    
    const board = document.getElementById('timetableBoard');
    const loading = document.getElementById('loadingState');
    const btnPrint = document.getElementById('btnPrint');
    const ttTitle = document.getElementById('ttTitle');
    const ttHead = document.getElementById('ttHead');
    const ttBody = document.getElementById('ttBody');

    // ---------------------------------------------------------
    // 🔄 สลับโหมดการค้นหา (ครู vs ห้องเรียน)
    // ---------------------------------------------------------
    function switchSearchMode(mode) {
        currentSearchMode = mode;
        if (mode === 'teacher') {
            btnModeTeacher.classList.add('active');
            btnModeClass.classList.remove('active');
            divSearchTeacher.style.display = 'flex';
            divSearchClass.style.display = 'none';
        } else {
            btnModeClass.classList.add('active');
            btnModeTeacher.classList.remove('active');
            divSearchClass.style.display = 'flex';
            divSearchTeacher.style.display = 'none';
        }
    }

    // ---------------------------------------------------------
    // 📡 ดึงข้อมูลจาก Backend (AJAX)
    // ---------------------------------------------------------
    function fetchTimetableData() {
        
        let searchValue = '';
        if (currentSearchMode === 'teacher') {
            searchValue = document.getElementById('searchTeacher').value;
            currentTeacherCode = searchValue; // เก็บไว้ใช้ตอน Add new schedule
        } else {
            searchValue = document.getElementById('searchClass').value;
        }

        const year = document.getElementById('searchYear').value;
        const term = document.getElementById('searchTerm').value;

        if (!searchValue || !year || !term) {
            Swal.fire({ icon: 'warning', title: 'แจ้งเตือน', text: 'กรุณาเลือกข้อมูลให้ครบถ้วนก่อนค้นหา', background: '#0f172a', color: '#fff' });
            return;
        }

        board.style.display = 'none';
        btnPrint.style.display = 'none';
        loading.style.display = 'block';

        // สร้าง FormData เพื่อยิงเข้า API ในหน้าเดียวกัน
        let formData = new FormData();
        formData.append('ajax_action', 'fetch_timetable');
        formData.append('search_mode', currentSearchMode);
        formData.append('search_value', searchValue);
        formData.append('year', year);
        formData.append('term', term);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                renderTimetable(data);
            } else {
                loading.style.display = 'none';
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message, background: '#0f172a', color: '#fff' });
            }
        })
        .catch(error => {
            loading.style.display = 'none';
            Swal.fire({ icon: 'error', title: 'ระบบขัดข้อง', text: 'การเชื่อมต่อผิดพลาด', background: '#0f172a', color: '#fff' });
        });
    }

    // ---------------------------------------------------------
    // 🎨 วาดตาราง (Rendering Engine) พร้อมใส่ระบบ onClick Edit
    // ---------------------------------------------------------
    function renderTimetable(payload) {
        
        const timeSlots = payload.time_slots;
        const matrix = payload.matrix;
        
        ttTitle.innerText = payload.header_title;

        // วาดหัวตาราง <thead>
        ttHead.innerHTML = '';
        let trPeriods = document.createElement('tr');
        let trTimes = document.createElement('tr');

        let thCorner = document.createElement('th');
        thCorner.rowSpan = 2;
        thCorner.innerHTML = '<span style="font-size:1.5rem; letter-spacing:2px;">วัน/เวลา</span>';
        thCorner.style.width = '100px';
        trPeriods.appendChild(thCorner);

        timeSlots.forEach(slot => {
            let thP = document.createElement('th');
            thP.innerHTML = `<span class="period-number">${slot.period_number}</span>`;
            trPeriods.appendChild(thP);

            let thT = document.createElement('th');
            thT.innerHTML = `<span class="time-range">${slot.start_time} - ${slot.end_time}</span>`;
            trTimes.appendChild(thT);
        });

        ttHead.appendChild(trPeriods);
        ttHead.appendChild(trTimes);

        // วาดไส้ในตาราง <tbody>
        ttBody.innerHTML = '';
        const dayShortNames = { 1: 'จ.', 2: 'อ.', 3: 'พ.', 4: 'พฤ.', 5: 'ศ.' };
        const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

        for (let dayNum = 1; dayNum <= 5; dayNum++) {
            let trDay = document.createElement('tr');
            
            let tdDay = document.createElement('td');
            tdDay.className = `day-column day-${dayNum}`;
            tdDay.innerText = dayShortNames[dayNum] || dayNum;
            trDay.appendChild(tdDay);

            if (matrix[dayNum]) {
                timeSlots.forEach(slot => {
                    let pNum = slot.period_number;
                    let cellData = matrix[dayNum][pNum];

                    if (!cellData) return;
                    if (cellData.type === 'skip') return; // ข้ามช่องที่โดน Colspan ควบไปแล้ว

                    let tdCell = document.createElement('td');
                    if (cellData.colspan > 1) {
                        tdCell.colSpan = cellData.colspan;
                    }

                    // สร้าง Content ภายในช่อง
                    let cellHtml = '';
                    if (cellData.type === 'class') {
                        let actClass = cellData.is_activity === 1 ? 'is-activity' : '';
                        
                        // ถ้าค้นหาโหมดห้องเรียน ให้โชว์ชื่อครูแทนชื่อห้องในกล่อง
                        let extraInfo = currentSearchMode === 'class' 
                            ? `<div class="teacher-subtext">ครู${cellData.teacher_name}</div>`
                            : `<div class="room-number">${cellData.room_number || ''}</div>`;

                        cellHtml = `
                            <div class="cell-content ${actClass}">
                                <div class="subj-code">${cellData.subject_code}</div>
                                <div class="class-room">${currentSearchMode === 'class' ? cellData.room_number : cellData.class_room}</div>
                                ${extraInfo}
                            </div>
                        `;
                    } else if (cellData.type === 'break') {
                        cellHtml = `<div class="cell-content" style="color:var(--border-color); font-weight:bold;">${cellData.label || 'พัก'}</div>`;
                    } else {
                        tdCell.className = 'cell-empty';
                        cellHtml = `<div class="cell-content" style="opacity:0.3;">+</div>`;
                    }

                    tdCell.innerHTML = cellHtml;

                    // 🚨 ระบบ Interactive Edit (คลิกเพื่อแก้ไข/เพิ่มข้อมูล)
                    if (isAdmin && cellData.type !== 'break') {
                        tdCell.onclick = () => openEditModal(dayNum, pNum, cellData);
                    }

                    trDay.appendChild(tdCell);
                });
            }
            ttBody.appendChild(trDay);
        }

        loading.style.display = 'none';
        board.style.display = 'block';
        btnPrint.style.display = 'flex';
        board.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ---------------------------------------------------------
    // 🛠️ ระบบเปิดหน้าต่างแก้ไขข้อมูล (SweetAlert2 Custom Form)
    // ---------------------------------------------------------
    function openEditModal(dayNum, periodNum, cellData) {
        
        // ถ้าค้นหาแบบห้องเรียนอยู่ ไม่ให้เพิ่ม/แก้ตรงนี้ (ให้บังคับแก้ผ่านโหมดครู เพื่อความถูกต้องของรหัสครู)
        if (currentSearchMode === 'class') {
            Swal.fire('ℹ️ ข้อมูล', 'หากต้องการแก้ไขคาบเรียน กรุณาสลับไปค้นหาตารางตาม "ชื่อครู" เพื่อความถูกต้องของระบบฐานข้อมูล', 'info');
            return;
        }

        if (!currentTeacherCode) {
            alert("ไม่พบรหัสครู"); return;
        }

        const isEdit = cellData.type === 'class';
        const modalTitle = isEdit ? '✏️ แก้ไขคาบเรียน' : '➕ เพิ่มคาบเรียนใหม่';
        
        const subjCode = isEdit ? cellData.subject_code : '';
        const classRoom = isEdit ? cellData.class_room : '';
        const roomNum = isEdit ? cellData.room_number : '';
        const startP = isEdit ? cellData.start_period : periodNum;
        const endP = isEdit ? cellData.end_period : periodNum;
        const isAct = (isEdit && cellData.is_activity === 1) ? 'checked' : '';
        const schId = isEdit ? cellData.schedule_id : 0;

        Swal.fire({
            title: modalTitle,
            html: `
                <div style="text-align:left; font-size:0.9rem;">
                    <div style="margin-bottom:10px;">
                        <label>รหัสวิชา / กิจกรรม</label>
                        <input id="swalSubj" class="swal2-input" value="${subjCode}" style="margin-top:5px; height:40px; font-size:1rem;" placeholder="เช่น ว32223 หรือ ชุมนุม">
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:10px;">
                        <div style="flex:1;">
                            <label>ชั้นเรียน</label>
                            <input id="swalClass" class="swal2-input" value="${classRoom}" style="margin-top:5px; height:40px; font-size:1rem;" placeholder="เช่น 5/1">
                        </div>
                        <div style="flex:1;">
                            <label>ห้องเรียน</label>
                            <input id="swalRoom" class="swal2-input" value="${roomNum}" style="margin-top:5px; height:40px; font-size:1rem;" placeholder="เช่น 536">
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <div style="flex:1;">
                            <label>คาบเริ่ม</label>
                            <input id="swalStart" type="number" class="swal2-input" value="${startP}" style="margin-top:5px; height:40px;" min="1" max="10">
                        </div>
                        <div style="flex:1;">
                            <label>คาบสิ้นสุด (ควบคาบ)</label>
                            <input id="swalEnd" type="number" class="swal2-input" value="${endP}" style="margin-top:5px; height:40px;" min="1" max="10">
                        </div>
                    </div>
                    <div>
                        <label style="cursor:pointer; display:flex; align-items:center; gap:10px; color:var(--primary);">
                            <input type="checkbox" id="swalAct" ${isAct} style="width:20px; height:20px;"> 📌 ระบุว่าเป็นกิจกรรมพิเศษ
                        </label>
                    </div>
                </div>
            `,
            showCancelButton: true,
            showDenyButton: isEdit,
            confirmButtonText: '💾 บันทึก',
            cancelButtonText: 'ยกเลิก',
            denyButtonText: '🗑️ ลบคาบนี้',
            confirmButtonColor: '#10b981',
            denyButtonColor: '#ef4444',
            background: '#0f172a',
            color: '#f8fafc',
            preConfirm: () => {
                return {
                    subject: document.getElementById('swalSubj').value.trim(),
                    classroom: document.getElementById('swalClass').value.trim(),
                    room: document.getElementById('swalRoom').value.trim(),
                    start: document.getElementById('swalStart').value,
                    end: document.getElementById('swalEnd').value,
                    act: document.getElementById('swalAct').checked ? 1 : 0
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const data = result.value;
                if (!data.subject) {
                    Swal.fire('ข้อผิดพลาด', 'กรุณาระบุรหัสวิชา', 'error');
                    return;
                }
                saveScheduleData(schId, currentTeacherCode, dayNum, data);
            } else if (result.isDenied) {
                deleteScheduleData(schId);
            }
        });
    }

    // ---------------------------------------------------------
    // 💾 บันทึกข้อมูลเข้าฐานข้อมูลผ่าน AJAX
    // ---------------------------------------------------------
    function saveScheduleData(scheduleId, teacherCode, day, data) {
        const year = document.getElementById('searchYear').value;
        const term = document.getElementById('searchTerm').value;

        let formData = new FormData();
        formData.append('ajax_action', 'save_schedule');
        formData.append('schedule_id', scheduleId);
        formData.append('teacher_code', teacherCode);
        formData.append('year', year);
        formData.append('term', term);
        formData.append('day', day);
        formData.append('start_period', data.start);
        formData.append('end_period', data.end);
        formData.append('subject_code', data.subject);
        formData.append('class_room', data.classroom);
        formData.append('room_number', data.room);
        formData.append('is_activity', data.act);

        fetch('', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(resData => {
            if (resData.status === 'success') {
                Swal.fire({ icon: 'success', title: 'สำเร็จ', text: resData.message, timer: 1000, showConfirmButton: false, background: '#0f172a', color: '#fff' });
                fetchTimetableData(); // โหลดตารางใหม่เพื่อให้เห็นการเปลี่ยนแปลงทันที
            } else {
                Swal.fire('ข้อผิดพลาด', resData.message, 'error');
            }
        });
    }

    // ---------------------------------------------------------
    // 🗑️ ลบข้อมูลคาบเรียน
    // ---------------------------------------------------------
    function deleteScheduleData(scheduleId) {
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: 'คุณต้องการลบคาบเรียนนี้ใช่หรือไม่?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'ใช่, ลบทิ้งเลย',
            background: '#0f172a',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                let formData = new FormData();
                formData.append('ajax_action', 'delete_schedule');
                formData.append('schedule_id', scheduleId);

                fetch('', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(resData => {
                    if (resData.status === 'success') {
                        fetchTimetableData(); // โหลดตารางใหม่
                    } else {
                        Swal.fire('ข้อผิดพลาด', resData.message, 'error');
                    }
                });
            }
        });
    }

</script>

<?php require_once 'footer.php'; ?>