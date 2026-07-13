<?php
/**
 * api_get_schedule.php - สมองกลประมวลผลตารางสอน (Smart Timetable Processing API - Phase 3)
 * สถาปัตยกรรม: Dual-Mode (JSON API Endpoint + API Explorer Dashboard)
 * 🚨 กฎเหล็ก: เขียนโค้ดแบบกระจายเต็มรูปแบบ (Unminified) ห้ามย่อบรรทัด ห้ามบีบอัด
 * * หน้าที่หลัก:
 * 1. รับค่า teacher_code หรือ teacher_id, academic_year, term
 * 2. ดึงข้อมูลจากฐานข้อมูล teacher_schedules และ time_slots
 * 3. 🧠 ประมวลผล Matrix Algorithm จัดกลุ่มคาบเรียน และคำนวณคาบควบ (Colspan) อัตโนมัติ
 * 4. ส่งผลลัพธ์ออกไปเป็น JSON หรือแสดงหน้า Dashboard สำหรับนักพัฒนา
 */

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once 'db.php';
require_once __DIR__ . '/rate_limiter.php';
checkApiRateLimit();

// =========================================================================
// 🚨 ตรวจสอบการเชื่อมต่อฐานข้อมูล
// =========================================================================
die("<div style='padding:50px; text-align:center; color:#ef4444; font-family:sans-serif;'><h2>🚨 ข้อผิดพลาดระดับวิกฤต: ไม่พบการเชื่อมต่อฐานข้อมูล (PDO)</h2></div>");
}

// ตรวจสอบระบบ Login (อนุโลมให้ผ่านได้หากเรียกผ่าน API ภายในระบบ)
session_start();
if (!isset($_SESSION['user_id'])) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || isset($_GET['api_mode'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนดึงข้อมูล (Unauthorized Access)']);
        exit;
    }
    header("Location: login.php");
    exit;
}

// =========================================================================
// 📥 1. ระบบรับค่า Parameter (Query Parameters Handling)
// =========================================================================
$teacher_code  = $_GET['teacher_code'] ?? '';
$academic_year = $_GET['year'] ?? '';
$term          = $_GET['term'] ?? '';
$api_mode      = isset($_GET['api_mode']) ? true : false; // ถ้าระบุ api_mode=1 จะบังคับพ่น JSON เท่านั้น

// โหมดการทำงาน (ตรวจสอบว่าเป็น AJAX Request หรือระบุ api_mode หรือไม่)
$is_json_request = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || $api_mode;

// =========================================================================
// 🧠 2. Core Processing Algorithm (ฟังก์ชันคำนวณ Matrix หลัก)
// =========================================================================
function processTimetableData($pdo, $teacher_code, $academic_year, $term) {
    
    $response = [
        'status' => 'error',
        'message' => 'Unknown error',
        'data' => []
    ];

    // ตรวจสอบพารามิเตอร์เบื้องต้น
    if (empty($teacher_code) || empty($academic_year) || empty($term)) {
        $response['message'] = 'ข้อมูลพารามิเตอร์ไม่ครบถ้วน (ต้องการ: teacher_code, year, term)';
        return $response;
    }

    try {
        // ---------------------------------------------------------
        // ขั้นตอนที่ 1: ดึงข้อมูลครู (Teacher Profile)
        // ---------------------------------------------------------
        $stmt_teacher = $pdo->prepare("
            SELECT id, teacher_code, full_name, department 
            FROM teachers 
            WHERE teacher_code = ?
            LIMIT 1
        ");
        $stmt_teacher->execute([$teacher_code]);
        $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC);

        if (!$teacher) {
            $response['message'] = "ไม่พบข้อมูลครูรหัส '{$teacher_code}' ในระบบ";
            return $response;
        }

        // ---------------------------------------------------------
        // ขั้นตอนที่ 2: ดึงโครงสร้างเวลาคาบเรียน (Time Slots)
        // ---------------------------------------------------------
        $stmt_slots = $pdo->query("
            SELECT id, period_number, start_time, end_time, is_break, label 
            FROM time_slots 
            ORDER BY period_number ASC
        ");
        $time_slots = $stmt_slots->fetchAll(PDO::FETCH_ASSOC);

        if (count($time_slots) === 0) {
            $response['message'] = "ไม่พบโครงสร้างตารางเวลา (Time Slots) ในระบบ กรุณาตั้งค่าก่อนใช้งาน";
            return $response;
        }

        // ---------------------------------------------------------
        // ขั้นตอนที่ 3: ดึงข้อมูลตารางสอนดิบ (Raw Schedules)
        // ---------------------------------------------------------
        $stmt_schedules = $pdo->prepare("
            SELECT id, day_of_week, start_period, end_period, subject_code, class_room, room_number, is_activity 
            FROM teacher_schedules 
            WHERE teacher_id = ? AND academic_year = ? AND term = ?
            ORDER BY day_of_week ASC, start_period ASC
        ");
        $stmt_schedules->execute([$teacher['id'], $academic_year, $term]);
        $raw_schedules = $stmt_schedules->fetchAll(PDO::FETCH_ASSOC);

        // ---------------------------------------------------------
        // ขั้นตอนที่ 4: 🧠 ALGORITHM สานตาราง (Matrix Colspan Engine) 🚨
        // ---------------------------------------------------------
        
        // 4.1 กำหนดวันในสัปดาห์ (1 = จันทร์ ถึง 5 = ศุกร์)
        $days_mapping = [
            1 => 'จันทร์',
            2 => 'อังคาร',
            3 => 'พุธ',
            4 => 'พฤหัสบดี',
            5 => 'ศุกร์'
        ];

        // 4.2 สร้าง Matrix 2 มิติว่างๆ เตรียมรอรับข้อมูล
        $matrix = [];
        
        foreach ($days_mapping as $day_num => $day_name) {
            $matrix[$day_num] = [];
            
            foreach ($time_slots as $slot) {
                $period = $slot['period_number'];
                
                // กำหนดโครงสร้างเริ่มต้นของแต่ละช่อง (Cell)
                // ชนิด: 'empty' (ว่าง), 'break' (พัก), 'class' (เรียน), 'skip' (ถูกควบไปแล้ว)
                $matrix[$day_num][$period] = [
                    'type' => $slot['is_break'] ? 'break' : 'empty',
                    'label' => $slot['is_break'] ? $slot['label'] : '',
                    'colspan' => 1,
                    'subject_code' => '',
                    'class_room' => '',
                    'room_number' => '',
                    'is_activity' => 0
                ];
            }
        }

        // 4.3 จัดเรียงข้อมูลการสอนลงใน Matrix
        foreach ($raw_schedules as $sch) {
            
            $d = intval($sch['day_of_week']);
            $start_p = intval($sch['start_period']);
            $end_p = intval($sch['end_period']);
            
            // ป้องกันการ Error หากข้อมูลวันที่หรือคาบเรียนไม่อยู่ในโครงสร้าง
            if (!isset($matrix[$d]) || !isset($matrix[$d][$start_p])) {
                continue; // ข้ามข้อมูลที่ผิดปกติ
            }

            // คำนวณความกว้างของช่อง (Colspan)
            // เช่น เรียนคาบ 1 ถึง 2 -> (2 - 1) + 1 = 2 ช่อง
            $colspan = ($end_p - $start_p) + 1;

            // บรรจุข้อมูลลงในคาบเริ่มต้น
            $matrix[$d][$start_p] = [
                'type' => 'class',
                'colspan' => $colspan,
                'subject_code' => $sch['subject_code'],
                'class_room' => $sch['class_room'],
                'room_number' => $sch['room_number'],
                'is_activity' => intval($sch['is_activity']),
                'label' => ''
            ];

            // 🚨 สำคัญมาก: หากเป็นคาบควบ ต้องบอกระบบให้ "ข้าม (Skip)" คาบถัดไป
            // ตัวอย่าง: ถ้าเรียนคาบ 1-2 (Colspan=2), คาบ 2 ต้องถูกตั้งค่าเป็น 'skip'
            // เพื่อที่ Frontend จะได้ไม่ต้องวาด <td> สำหรับคาบ 2 
            if ($colspan > 1) {
                for ($p = $start_p + 1; $p <= $end_p; $p++) {
                    // ตรวจสอบเพื่อป้องกัน Index Out of Bounds
                    if (isset($matrix[$d][$p])) {
                        $matrix[$d][$p] = [
                            'type' => 'skip',
                            'colspan' => 0,
                            'subject_code' => '',
                            'class_room' => '',
                            'room_number' => '',
                            'is_activity' => 0,
                            'label' => 'Skipped by Colspan'
                        ];
                    }
                }
            }
        }

        // ---------------------------------------------------------
        // ขั้นตอนที่ 5: เตรียมข้อมูลส่งกลับ (Response Payload)
        // ---------------------------------------------------------
        $response['status'] = 'success';
        $response['message'] = 'ประมวลผล Matrix สำเร็จ';
        $response['data'] = [
            'teacher' => $teacher,
            'academic_info' => [
                'year' => $academic_year,
                'term' => $term
            ],
            'time_slots' => $time_slots,
            'days_mapping' => $days_mapping,
            'timetable_matrix' => $matrix,
            'raw_schedules' => $raw_schedules // ส่งค่าดิบไปให้เช็คด้วย
        ];

        return $response;

    } catch (PDOException $e) {
        $response['message'] = 'Database Error: ' . $e->getMessage();
        return $response;
    } catch (Exception $e) {
        $response['message'] = 'System Error: ' . $e->getMessage();
        return $response;
    }
}

// =========================================================================
// 📤 3. ระบบส่งออกข้อมูล (Output Trigger)
// =========================================================================

// ถ้าเรียกใช้งานเป็น API ให้คำนวณและพ่น JSON ทันที
if ($is_json_request) {
    header('Content-Type: application/json; charset=utf-8');
    $result = processTimetableData($pdo, $teacher_code, $academic_year, $term);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// หากไม่ใช่ API Request ให้แสดงผลหน้าจอ Dashboard สำหรับ Debug & Test
// ซึ่งจะมีประโยชน์มากในการพัฒนาระยะที่ 4 (Frontend) 
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Explorer - Timetable Processing Engine (Phase 3)</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@400;700&family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        /* ============================================================
           📍 CSS ARCHITECTURE FOR API EXPLORER
           (Fully Unminified & Detailed)
           ============================================================ */
        :root {
            --bg-base: #020617;
            --panel-dark: #0f172a;
            --panel-light: #1e293b;
            --border-line: #334155;
            
            --api-blue: #38bdf8;
            --api-purple: #8b5cf6;
            --api-green: #10b981;
            --api-pink: #ec4899;
            --api-yellow: #f59e0b;
            --api-red: #ef4444;
            
            --text-main: #f8fafc;
            --text-sub: #94a3b8;
            --text-json-key: #60a5fa;
            --text-json-string: #a78bfa;
            --text-json-number: #f472b6;
            --text-json-boolean: #fbbf24;
        }

        body {
            background-color: var(--bg-base);
            color: var(--text-main);
            font-family: 'Prompt', sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* -------------------------------------------
           🎛️ Sidebar Configuration Panel
           ------------------------------------------- */
        .sidebar {
            width: 350px;
            background-color: var(--panel-dark);
            border-right: 1px solid var(--border-line);
            display: flex;
            flex-direction: column;
            box-shadow: 5px 0 20px rgba(0,0,0,0.5);
            z-index: 100;
        }

        .sidebar-header {
            padding: 25px 20px;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(236, 72, 153, 0.1));
            border-bottom: 1px solid var(--border-line);
        }

        .sidebar-header h1 {
            margin: 0;
            font-family: 'Orbitron', sans-serif;
            font-size: 1.5rem;
            color: var(--api-purple);
            text-shadow: 0 0 10px rgba(139, 92, 246, 0.4);
        }

        .sidebar-header p {
            margin: 5px 0 0 0;
            font-size: 0.85rem;
            color: var(--api-pink);
            letter-spacing: 1px;
        }

        .config-form {
            padding: 25px 20px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
            flex: 1;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-sub);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group input, 
        .form-group select {
            background-color: var(--bg-base);
            border: 1px solid var(--border-line);
            color: var(--text-main);
            padding: 12px 15px;
            border-radius: 8px;
            font-family: 'Share Tech Mono', monospace;
            font-size: 1.1rem;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-group input:focus, 
        .form-group select:focus {
            border-color: var(--api-blue);
            box-shadow: 0 0 15px rgba(56, 189, 248, 0.2);
        }

        .btn-test-api {
            background: linear-gradient(135deg, var(--api-purple), var(--api-blue));
            color: white;
            border: none;
            padding: 15px;
            border-radius: 10px;
            font-family: 'Prompt', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
        }

        .btn-test-api:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.6);
        }

        /* -------------------------------------------
           🖥️ Main Dashboard Area
           ------------------------------------------- */
        .main-dashboard {
            flex: 1;
            display: flex;
            flex-direction: column;
            background-color: var(--bg-base);
            overflow: hidden;
        }

        .dashboard-header {
            padding: 20px 30px;
            background-color: var(--panel-dark);
            border-bottom: 1px solid var(--border-line);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .endpoint-url {
            background-color: var(--bg-base);
            border: 1px solid var(--border-line);
            padding: 10px 20px;
            border-radius: 30px;
            font-family: 'Share Tech Mono', monospace;
            color: var(--api-green);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-method {
            background-color: rgba(56, 189, 248, 0.2);
            color: var(--api-blue);
            padding: 2px 8px;
            border-radius: 5px;
            font-weight: bold;
            font-size: 0.8rem;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--text-sub);
        }

        .status-dot.success {
            background-color: var(--api-green);
            box-shadow: 0 0 10px var(--api-green);
        }

        .status-dot.error {
            background-color: var(--api-red);
            box-shadow: 0 0 10px var(--api-red);
        }

        /* -------------------------------------------
           📑 Tabs System
           ------------------------------------------- */
        .tabs-container {
            display: flex;
            background-color: var(--panel-dark);
            border-bottom: 1px solid var(--border-line);
            padding: 0 30px;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: var(--text-sub);
            padding: 15px 25px;
            font-family: 'Prompt', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .tab-btn:hover {
            color: var(--text-main);
        }

        .tab-btn.active {
            color: var(--api-blue);
            border-bottom-color: var(--api-blue);
        }

        .tab-content {
            display: none;
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .tab-content.active {
            display: block;
        }

        /* -------------------------------------------
           💻 Output Viewers
           ------------------------------------------- */
        .json-viewer {
            background-color: var(--panel-dark);
            border: 1px solid var(--border-line);
            border-radius: 12px;
            padding: 20px;
            font-family: 'Share Tech Mono', monospace;
            font-size: 0.95rem;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-x: auto;
            color: var(--text-main);
        }

        /* Syntax Highlighting for JSON */
        .key { color: var(--text-json-key); font-weight: bold; }
        .string { color: var(--text-json-string); }
        .number { color: var(--text-json-number); }
        .boolean { color: var(--text-json-boolean); font-weight: bold; }
        .null { color: var(--api-red); font-weight: bold; }

        /* -------------------------------------------
           📊 Matrix Visualizer (Visual Debugging)
           ------------------------------------------- */
        .matrix-grid {
            display: grid;
            gap: 15px;
        }

        .matrix-day-row {
            background-color: var(--panel-dark);
            border: 1px solid var(--border-line);
            border-radius: 10px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            overflow-x: auto;
        }

        .day-label {
            min-width: 80px;
            font-weight: bold;
            color: var(--api-yellow);
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .period-cell {
            background-color: var(--bg-base);
            border: 1px solid var(--border-line);
            border-radius: 8px;
            min-width: 80px;
            height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 10px;
            flex-shrink: 0;
            position: relative;
        }

        .period-num {
            position: absolute;
            top: 5px;
            left: 8px;
            font-size: 0.7rem;
            color: var(--text-sub);
            font-family: 'Share Tech Mono';
        }

        .cell-class {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(56, 189, 248, 0.2));
            border-color: var(--api-purple);
        }

        .cell-break {
            background-color: rgba(245, 158, 11, 0.1);
            border-color: var(--api-yellow);
            border-style: dashed;
        }

        .cell-skip {
            background-color: rgba(239, 68, 68, 0.05);
            border-color: transparent;
            opacity: 0.5;
        }

        .cell-act {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(52, 211, 153, 0.2));
            border-color: var(--api-green);
        }

        .subj-code { font-weight: bold; font-family: 'Share Tech Mono'; color: var(--text-main); font-size: 1.1rem;}
        .room-txt { font-size: 0.8rem; color: var(--text-sub); margin-top: 5px;}
        .colspan-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background-color: var(--api-pink);
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            box-shadow: 0 0 10px var(--api-pink);
        }

        /* Scrollbar ปรับแต่ง */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-base); border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: var(--border-line); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--api-blue); }

    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <h1>API Explorer</h1>
            <p>Phase 3: Timetable Matrix Engine</p>
        </div>

        <div class="config-form">
            
            <div style="background: rgba(56, 189, 248, 0.1); border: 1px solid var(--api-blue); padding: 15px; border-radius: 10px; margin-bottom: 10px;">
                <p style="margin:0; font-size:0.85rem; color:#bae6fd; line-height:1.5;">
                    💡 <strong>โหมดนักพัฒนา:</strong> หน้าจอนี้ใช้สำหรับทดสอบการทำงานของ Backend Algorithm ในการจัดกลุ่ม <strong>"คาบควบ"</strong> ก่อนนำไปสร้าง UI จริงในระยะถัดไป
                </p>
            </div>

            <div class="form-group">
                <label for="inputTeacherCode">รหัสครูผู้สอน (Teacher Code)</label>
                <input type="text" id="inputTeacherCode" value="301" placeholder="เช่น 301">
            </div>

            <div class="form-group">
                <label for="inputYear">ปีการศึกษา (Academic Year)</label>
                <input type="number" id="inputYear" value="2564" placeholder="เช่น 2564">
            </div>

            <div class="form-group">
                <label for="inputTerm">ภาคเรียน (Term)</label>
                <select id="inputTerm">
                    <option value="1" selected>ภาคเรียนที่ 1</option>
                    <option value="2">ภาคเรียนที่ 2</option>
                    <option value="3">ภาคเรียนฤดูร้อน</option>
                </select>
            </div>

            <button class="btn-test-api" id="btnRunTest" onclick="executeApiTest()">
                🚀 Execute API Test
            </button>

        </div>
    </div>

    <div class="main-dashboard">
        
        <div class="dashboard-header">
            <div class="endpoint-url" id="endpointDisplay">
                <span class="badge-method">GET</span>
                <span>/api_get_schedule.php?api_mode=1&...</span>
            </div>
            <div class="status-indicator" id="statusIndicator">
                <div class="status-dot"></div>
                <span style="color:var(--text-sub)">Waiting...</span>
            </div>
        </div>

        <div class="tabs-container">
            <button class="tab-btn active" onclick="switchTab('tab-matrix')">📊 Visual Matrix (Processed)</button>
            <button class="tab-btn" onclick="switchTab('tab-json')">📦 Raw JSON Output</button>
        </div>

        <div class="tab-content" id="tab-json">
            <h3 style="margin-top:0; color:var(--api-blue);">Payload Response</h3>
            <p style="color:var(--text-sub); font-size:0.9rem; margin-top:-10px;">ข้อมูล JSON บริสุทธิ์ที่ Frontend (Phase 4) จะได้รับไปประมวลผล</p>
            <div class="json-viewer" id="jsonOutput">
                // รอการ Execute...
            </div>
        </div>

        <div class="tab-content active" id="tab-matrix">
            <h3 style="margin-top:0; color:var(--api-purple);">Colspan Algorithm Visualizer</h3>
            <p style="color:var(--text-sub); font-size:0.9rem; margin-top:-10px;">จำลองการจัดเรียง Array ที่ผ่านกระบวนการยุบรวมคาบควบแล้ว (สังเกตช่องที่ถูกข้าม)</p>
            
            <div class="matrix-grid" id="matrixVisualizer">
                <div style="text-align:center; padding: 50px; color:var(--text-sub); border: 1px dashed var(--border-line); border-radius: 10px;">
                    กรุณากดปุ่ม "Execute API Test" ด้านซ้ายมือเพื่อดึงข้อมูล
                </div>
            </div>
        </div>

    </div>

    <script>
        
        // ---------------------------------------------------------
        // 📑 ระบบสลับแท็บ (Tab Navigation)
        // ---------------------------------------------------------
        function switchTab(tabId) {
            // ล้างคลาส active ทั้งหมด
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            // หาปุ่มที่ถูกกด
            const clickedBtn = Array.from(document.querySelectorAll('.tab-btn')).find(btn => btn.getAttribute('onclick').includes(tabId));
            if (clickedBtn) clickedBtn.classList.add('active');
            
            // โชว์เนื้อหาที่เลือก
            const targetContent = document.getElementById(tabId);
            if (targetContent) targetContent.classList.add('active');
        }

        // ---------------------------------------------------------
        // 🎨 ฟังก์ชันไฮไลต์สี Syntax ให้ JSON อ่านง่ายขึ้น
        // ---------------------------------------------------------
        function syntaxHighlight(json) {
            if (typeof json != 'string') {
                 json = JSON.stringify(json, undefined, 4);
            }
            json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                var cls = 'number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'key';
                    } else {
                        cls = 'string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'boolean';
                } else if (/null/.test(match)) {
                    cls = 'null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        }

        // ---------------------------------------------------------
        // 🚀 ฟังก์ชันหลัก: เรียกใช้งาน API แบบ AJAX
        // ---------------------------------------------------------
        function executeApiTest() {
            
            const btn = document.getElementById('btnRunTest');
            const teacherCode = document.getElementById('inputTeacherCode').value.trim();
            const year = document.getElementById('inputYear').value.trim();
            const term = document.getElementById('inputTerm').value.trim();

            if (!teacherCode || !year || !term) {
                Swal.fire({ icon: 'warning', title: 'ข้อมูลไม่ครบ', text: 'กรุณากรอกข้อมูลในฟอร์มให้ครบถ้วน', background: '#0f172a', color: '#fff' });
                return;
            }

            // อัปเดต UI ให้อยู่ในสถานะ Loading
            btn.disabled = true;
            btn.innerHTML = '⏳ Processing...';
            
            const statusInd = document.getElementById('statusIndicator');
            statusInd.innerHTML = '<div class="status-dot" style="background:#f59e0b; box-shadow:0 0 10px #f59e0b;"></div><span style="color:#f59e0b">Fetching Data...</span>';

            // สร้าง URL (ดึงกลับมาที่ไฟล์นี้เอง แต่เติม api_mode=1)
            // 🚨 นี่คือหัวใจของความรัดกุมในการจำลอง API Request
            const apiUrl = `api_get_schedule.php?api_mode=1&teacher_code=${encodeURIComponent(teacherCode)}&year=${encodeURIComponent(year)}&term=${encodeURIComponent(term)}`;
            
            // อัปเดต Display URL
            document.getElementById('endpointDisplay').innerHTML = `<span class="badge-method">GET</span> <span>${apiUrl}</span>`;

            // ส่ง Fetch Request
            fetch(apiUrl)
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    // 1. แสดงผล JSON
                    document.getElementById('jsonOutput').innerHTML = syntaxHighlight(data);

                    // 2. แสดงผลสถานะ
                    if (data.status === 'success') {
                        statusInd.innerHTML = '<div class="status-dot success"></div><span style="color:var(--api-green)">200 OK - ' + data.message + '</span>';
                        
                        // 3. วาดภาพจำลอง Matrix (Visualizer)
                        drawMatrixVisualizer(data.data.timetable_matrix, data.data.days_mapping);
                        
                    } else {
                        statusInd.innerHTML = '<div class="status-dot error"></div><span style="color:var(--api-red)">Error - ' + data.message + '</span>';
                        document.getElementById('matrixVisualizer').innerHTML = `
                            <div style="text-align:center; padding: 50px; color:var(--api-red); border: 1px dashed var(--api-red); border-radius: 10px; background: rgba(239,68,68,0.05);">
                                <h3>❌ การคำนวณล้มเหลว</h3>
                                <p>${data.message}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusInd.innerHTML = '<div class="status-dot error"></div><span style="color:var(--api-red)">500 Server Error</span>';
                    document.getElementById('jsonOutput').innerHTML = `<span style="color:var(--api-red)">Error executing request: ${error.message}</span>`;
                })
                .finally(() => {
                    // คืนค่าปุ่ม
                    btn.disabled = false;
                    btn.innerHTML = '🚀 Execute API Test';
                });
        }

        // ---------------------------------------------------------
        // 📊 ฟังก์ชันวาด Matrix Visualizer เพื่อให้เห็นการควบคาบชัดเจน
        // ---------------------------------------------------------
        function drawMatrixVisualizer(matrixData, daysMapping) {
            const container = document.getElementById('matrixVisualizer');
            container.innerHTML = ''; // ล้างของเก่า

            // วนลูปวาดแต่ละวัน (Row)
            for (const [dayNum, periods] of Object.entries(matrixData)) {
                
                const dayName = daysMapping[dayNum] || `วันที่ ${dayNum}`;
                
                // สร้างกล่องแถวของวัน
                const rowDiv = document.createElement('div');
                rowDiv.className = 'matrix-day-row';

                // ป้ายกำกับวัน
                const labelDiv = document.createElement('div');
                labelDiv.className = 'day-label';
                labelDiv.innerText = dayName;
                rowDiv.appendChild(labelDiv);

                // วนลูปวาดแต่ละคาบ (Cells)
                for (const [periodNum, cellData] of Object.entries(periods)) {
                    
                    const cellDiv = document.createElement('div');
                    cellDiv.className = 'period-cell';

                    // ตัวเลขมุมซ้ายบน (คาบที่เท่าไหร่)
                    const pNum = document.createElement('div');
                    pNum.className = 'period-num';
                    pNum.innerText = `P${periodNum}`;
                    cellDiv.appendChild(pNum);

                    // 🚨 ตรวจสอบเงื่อนไขตาม "สมองกล Matrix"
                    if (cellData.type === 'class') {
                        // 🟢 ถ้าเป็นวิชาเรียนปกติ หรือ กิจกรรม
                        if (cellData.is_activity === 1) {
                            cellDiv.classList.add('cell-act');
                        } else {
                            cellDiv.classList.add('cell-class');
                        }
                        
                        cellDiv.innerHTML += `
                            <div class="subj-code">${cellData.subject_code}</div>
                            <div class="room-txt">${cellData.class_room} | ${cellData.room_number || '-'}</div>
                        `;

                        // ถ้ามีการควบคาบ (Colspan > 1) ให้ใส่ป้ายกำกับบอกชัดๆ
                        if (cellData.colspan > 1) {
                            cellDiv.innerHTML += `<div class="colspan-badge">Span: ${cellData.colspan}</div>`;
                            // ขยายความกว้างกล่องจำลองให้ยาวขึ้น (80px คือความกว้างพื้นฐาน + 15px คือช่องว่าง)
                            cellDiv.style.minWidth = `${(80 * cellData.colspan) + (15 * (cellData.colspan - 1))}px`;
                        }

                    } else if (cellData.type === 'skip') {
                        // 🔴 ถ้าถูกควบคาบไปแล้ว ระบบจะสั่ง Skip (ข้ามการวาด <td> จริงๆ)
                        cellDiv.classList.add('cell-skip');
                        cellDiv.innerHTML += `<div style="font-size:0.8rem; color:#64748b; font-style:italic;">Skipped</div>`;
                    
                    } else if (cellData.type === 'break') {
                        // 🟡 คาบพัก
                        cellDiv.classList.add('cell-break');
                        cellDiv.innerHTML += `<div style="font-size:0.85rem; font-weight:bold; color:var(--api-yellow);">${cellData.label}</div>`;
                    
                    } else {
                        // ⚪ คาบว่าง
                        cellDiv.innerHTML += `<div style="font-size:0.8rem; color:var(--border-line);">- Empty -</div>`;
                    }

                    rowDiv.appendChild(cellDiv);
                }

                container.appendChild(rowDiv);
            }
        }

    </script>
</body>
</html>