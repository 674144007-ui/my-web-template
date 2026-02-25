<?php
// ===================================================================================
// FILE: mix.php (FULL VERSION - ULTIMATE SURVIVAL LAB)
// ===================================================================================
// ระบบห้องปฏิบัติการเคมีเสมือนจริง 
// (แก้ไขบัค: ปรับวิธีดึงข้อมูล User Session เพื่อให้นักเรียนและครูเข้าใช้งานได้ 100%)
// ===================================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php'; // โหลดระบบตรวจสอบสิทธิ์ก่อน
require_once 'db.php';   // โหลดการเชื่อมต่อฐานข้อมูล

// 1. ตรวจสอบสิทธิ์ (เปิดให้นักเรียน, ครู, นักพัฒนา และผู้ปกครอง เข้าถึงหน้า Lab ได้)
if (function_exists('requireRole')) {
    requireRole(['student', 'teacher', 'developer', 'admin', 'parent']);
}

// 2. ดึงข้อมูลผู้ใช้งานปัจจุบัน (แก้ไขบัคจาก currentUser() เป็นตัวแปร Session ปกติ)
// รองรับตัวแปร $uid ที่มาจาก auth.php หรือรูปแบบ $_SESSION มาตรฐาน
$user_id = isset($uid) ? $uid : ($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['uid'] ?? 0);
$role = $_SESSION['role'] ?? '';
$class_level = $_SESSION['class_level'] ?? '';

// ดึงข้อมูล Role และ Class Level ที่แน่นอนจากฐานข้อมูลเพื่อความชัวร์ (ป้องกัน Session ไม่ครบ)
if ($user_id > 0) {
    $stmt_user = $conn->prepare("SELECT role, class_level FROM users WHERE id = ?");
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $res_user = $stmt_user->get_result();
        if ($res_user && $res_user->num_rows > 0) {
            $u_data = $res_user->fetch_assoc();
            $role = $u_data['role'];
            $class_level = $u_data['class_level'];
        }
    }
}

// ===================================================================================
// [PART 1] BACKEND API LOGIC (ส่วนประมวลผลการผสมสารเคมีแบบ AJAX)
// ===================================================================================

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    try {
        // --- API: get_chemicals (ดึงรายชื่อสารเคมีไปแสดงใน Dropdown) ---
        if ($_GET['action'] === 'get_chemicals') {
            if ($conn->connect_error) throw new Exception("DB Error: " . $conn->connect_error);

            $sql = "SELECT id, name, formula, type FROM chemicals ORDER BY type, name";
            $result = $conn->query($sql);
            
            if (!$result) throw new Exception("Query Error: " . $conn->error);
            
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $displayName = htmlspecialchars($row['name']);
                if (!empty($row['formula'])) {
                    $displayName .= " (" . htmlspecialchars($row['formula']) . ")";
                }
                $data[] = [
                    'value' => $row['id'],
                    'text' => $displayName
                ];
            }
            echo json_encode($data);
            exit;
        }

        // --- API: mix (คำนวณการทำปฏิกิริยาเคมีและสีที่เปลี่ยนไป) ---
        if ($_GET['action'] === 'mix') {
            
            // Helper: แปลงรหัสสี Hex เป็นชื่อสีภาษาไทยเพื่อให้แสดงผลสวยงาม
            function getThaiColorName($hex) {
                $hex = strtoupper(ltrim($hex, '#'));
                $map = [
                    'FFFFFF' => 'สีขาวใส / ไม่มีสี', '000000' => 'สีดำ / มืด',
                    'FF0000' => 'สีแดงสด', '00FF00' => 'สีเขียวสด', '0000FF' => 'สีน้ำเงิน',
                    'FFFF00' => 'สีเหลือง', 'FFA500' => 'สีส้ม', '800080' => 'สีม่วง',
                    'C0C0C0' => 'สีเงิน / เทา', '3B82F6' => 'สีฟ้าสดใส',
                    'FEF08A' => 'สีเหลืองอ่อน', '1D4ED8' => 'สีน้ำเงินเข้ม'
                ];
                return isset($map[$hex]) ? $map[$hex] : "สีผสม (รหัส: #$hex)";
            }

            // Helper: คำนวณสีผสมแบบเฉลี่ยน้ำหนักตามปริมาตร (Volume)
            function mixColorsWeighted($hex1, $vol1, $hex2, $vol2) {
                $hex1 = ($hex1 && $hex1 != '') ? ltrim($hex1, '#') : 'FFFFFF';
                $hex2 = ($hex2 && $hex2 != '') ? ltrim($hex2, '#') : 'FFFFFF';
                
                if(strlen($hex1)==3) $hex1 = $hex1[0].$hex1[0].$hex1[1].$hex1[1].$hex1[2].$hex1[2];
                if(strlen($hex2)==3) $hex2 = $hex2[0].$hex2[0].$hex2[1].$hex2[1].$hex2[2].$hex2[2];

                $r1 = hexdec(substr($hex1,0,2)); $g1 = hexdec(substr($hex1,2,2)); $b1 = hexdec(substr($hex1,4,2));
                $r2 = hexdec(substr($hex2,0,2)); $g2 = hexdec(substr($hex2,2,2)); $b2 = hexdec(substr($hex2,4,2));

                $totalVol = $vol1 + $vol2;
                if ($totalVol <= 0) return "#" . $hex1;

                $r = round(($r1 * $vol1 + $r2 * $vol2) / $totalVol);
                $g = round(($g1 * $vol1 + $g2 * $vol2) / $totalVol);
                $b = round(($b1 * $vol1 + $b2 * $vol2) / $totalVol);

                return sprintf("#%02x%02x%02x", $r, $g, $b);
            }

            // รับค่า ID และปริมาตรจากผู้ใช้
            $id_a = isset($_GET['a']) ? intval($_GET['a']) : 0;
            $id_b = isset($_GET['b']) ? intval($_GET['b']) : 0;
            $vol_a = isset($_GET['volA']) ? floatval($_GET['volA']) : 0;
            $vol_b = isset($_GET['volB']) ? floatval($_GET['volB']) : 0;

            if ($id_a <= 0 || $id_b <= 0) throw new Exception("กรุณาเลือกสารเคมีให้ถูกต้องทั้ง 2 หลอด");

            // ดึงข้อมูลสารเคมีจากตาราง chemicals
            $stmt = $conn->prepare("SELECT id, name, formula, type, color_neutral, toxicity, state FROM chemicals WHERE id IN (?, ?)");
            $stmt->bind_param("ii", $id_a, $id_b);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $chemicals = [];
            while ($row = $res->fetch_assoc()) {
                $chemicals[$row['id']] = $row;
            }

            if (!isset($chemicals[$id_a])) throw new Exception("ไม่พบข้อมูลสาร A ในคลังข้อมูล (ID: $id_a)");
            if (!isset($chemicals[$id_b])) throw new Exception("ไม่พบข้อมูลสาร B ในคลังข้อมูล (ID: $id_b)");

            $cA = $chemicals[$id_a];
            $cB = $chemicals[$id_b];

            // 1. คำนวณค่าสถานะเบื้องต้นของการผสม (สมมติว่าไม่มีปฏิกิริยา)
            $total_volume = $vol_a + $vol_b;
            $final_temp = 25.0; // อุณหภูมิห้อง
            $result_color = mixColorsWeighted($cA['color_neutral'], $vol_a, $cB['color_neutral'], $vol_b);
            
            $product_name = "สารละลายผสม (" . $cA['name'] . " + " . $cB['name'] . ")";
            $product_formula = "-";
            $precipitate = "ไม่มีตะกอน";
            $gas_result = "ไม่มีแก๊ส";
            $damage_player = round(($cA['toxicity'] + $cB['toxicity']) / 2); // คำนวณพิษเฉลี่ย
            $effect_type = "normal";
            $final_state = "liquid";
            $has_bubbles = false;
            $bubble_color = "#FFFFFF";

            // 2. ตรวจสอบว่ามีสูตรปฏิกิริยาพิเศษใน Database หรือไม่ (เช็คแบบสลับตำแหน่ง A,B และ B,A)
            $sql_react = "SELECT * FROM reactions WHERE (chem1_id=? AND chem2_id=?) OR (chem1_id=? AND chem2_id=?) LIMIT 1";
            $stmt2 = $conn->prepare($sql_react);
            $stmt2->bind_param("iiii", $id_a, $id_b, $id_b, $id_a);
            $stmt2->execute();
            $react = $stmt2->get_result()->fetch_assoc();

            if ($react) {
                // อัปเดตข้อมูลผลิตภัณฑ์จากตาราง reactions
                if (!empty($react['product_name'])) $product_name = $react['product_name'];
                if (!empty($react['result_color'])) $result_color = $react['result_color'];
                if (!empty($react['result_precipitate']) && $react['result_precipitate'] !== 'ไม่มีตะกอน') $precipitate = $react['result_precipitate'];
                
                if (!empty($react['result_gas']) && $react['result_gas'] !== 'ไม่มีแก๊ส') {
                    $gas_result = $react['result_gas'];
                    $has_bubbles = true;
                    if (!empty($react['gas_color'])) $bubble_color = $react['gas_color'];
                }

                $final_temp += floatval($react['heat_level']);
                if ($final_temp >= 100) $final_state = 'gas'; // เดือดกลายเป็นไอ
                
                $damage_player += intval($react['toxicity_bonus']); // โดนดาเมจพิษเพิ่ม
                
                if ($react['is_explosive']) {
                    $effect_type = "explosion";
                    $result_color = "#222222"; // สีดำเขม่าควัน
                    $damage_player = 100; // ระเบิด ดาเมจเต็ม 100
                    $product_name .= " (BOOM! เกิดการระเบิด)";
                } elseif ($damage_player >= 50 && $final_state === 'gas') {
                    // หากมีพิษสูงและฟุ้งกระจายเป็นแก๊ส ถือว่าเป็น Event แก๊สพิษ
                    $effect_type = "toxic_gas";
                }
            } else {
                // กรณีนักเรียนผสมสารชนิดเดียวกัน (เท A ลง A)
                if ($id_a == $id_b) {
                    $product_name = $cA['name'];
                    $product_formula = $cA['formula'];
                }
            }

            // ส่งข้อมูลกลับไปให้ Frontend ไปเล่นแอนิเมชัน 3D และ UI
            echo json_encode([
                "success" => true,
                "product_name" => $product_name,
                "product_formula" => $product_formula,
                "color_name_thai" => getThaiColorName($result_color),
                "special_color" => $result_color,
                "liquid_color" => $result_color,
                "bubble_color" => $bubble_color,
                "has_bubbles" => $has_bubbles,
                "total_volume" => $total_volume,
                "temperature" => $final_temp,
                "final_state" => $final_state,
                "precipitate" => $precipitate,
                "gas" => $gas_result,
                "damage_player" => $damage_player,
                "effect_type" => $effect_type
            ]);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    exit;
}

// ===================================================================================
// [PART 2] FRONTEND UI & QUEST BOARD (หน้าตาและระบบเควสต์)
// ===================================================================================

// กำหนดปุ่มย้อนกลับให้ตรงกับ Role ปัจจุบัน
$dashboard_link = "index.php";
if ($role === 'student') {
    $dashboard_link = "dashboard_student.php";
} elseif ($role === 'teacher') {
    $dashboard_link = "dashboard_teacher.php";
} elseif ($role === 'developer' || $role === 'admin') {
    $dashboard_link = "dashboard_dev.php";
} elseif ($role === 'parent') {
    $dashboard_link = "dashboard_parent.php";
}

$quests = [];

// ดึงข้อมูล Quest สำหรับ 'นักเรียน' ตามระดับชั้น
if ($role === 'student' && !empty($class_level)) {
    $stmt = $conn->prepare("
        SELECT q.*, c.name as target_chem_name 
        FROM quests q 
        LEFT JOIN chemicals c ON q.target_chem_id = c.id
        WHERE q.assigned_class = ? 
        ORDER BY q.created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param("s", $class_level);
        $stmt->execute();
        $quests_result = $stmt->get_result();
        
        while ($row = $quests_result->fetch_assoc()) {
            $q_id = $row['id'];
            // ตรวจสอบความคืบหน้าว่านักเรียนทำผ่านหรือยัง
            $stmt_prog = $conn->prepare("SELECT status FROM student_quest_progress WHERE student_id = ? AND quest_id = ?");
            if ($stmt_prog) {
                $stmt_prog->bind_param("ii", $user_id, $q_id);
                $stmt_prog->execute();
                $prog_res = $stmt_prog->get_result();
                
                if ($prog_res && $prog_res->num_rows > 0) {
                    $prog_row = $prog_res->fetch_assoc();
                    $row['status'] = $prog_row['status'];
                } else {
                    $row['status'] = 'pending';
                }
            }
            $quests[] = $row;
        }
    }
} 
// ดึงข้อมูล Quest สำหรับ 'ครู' (ดึงเควสต์ที่ครูคนนี้สร้างเองมาดูตัวอย่าง)
else if ($role === 'teacher') {
    $stmt = $conn->prepare("
        SELECT q.*, c.name as target_chem_name 
        FROM quests q 
        LEFT JOIN chemicals c ON q.target_chem_id = c.id
        WHERE q.teacher_id = ? 
        ORDER BY q.created_at DESC
    ");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $quests_result = $stmt->get_result();
        
        while ($row = $quests_result->fetch_assoc()) {
            $row['status'] = 'teacher_preview'; // ป้ายกำกับสถานะพิเศษสำหรับครู
            $quests[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate Chemistry Lab Survival</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🧪</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

    <style>
        /* --- CSS หลักของหน้า Lab --- */
        body {
            font-family: 'Itim', cursive;
            margin: 0; 
            padding: 0; 
            min-height: 100vh;
            background-image: url('images_bg.png'); 
            background-color: #1e293b; /* Fallback color */
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding-top: 50px;
            overflow-x: hidden;
        }

        .container {
            width: 90%; 
            max-width: 850px;
            background: rgba(255, 255, 255, 0.95);
            padding: 25px; 
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            position: relative; 
            z-index: 10;
            backdrop-filter: blur(10px);
            margin-bottom: 50px;
        }

        h2 { 
            margin-top: 0; 
            margin-bottom: 10px; 
            color: #1e293b; 
            text-align: center; 
            font-size: 28px;
            text-shadow: 1px 1px 0px #fff;
        }

        .btn-back {
            display: block;
            width: fit-content;
            margin: 0 auto 20px auto;
            padding: 8px 25px;
            background: #ef4444;
            color: white;
            text-decoration: none;
            border-radius: 20px;
            font-size: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s, background 0.2s;
            border: 2px solid rgba(255,255,255,0.5);
        }
        .btn-back:hover { 
            transform: scale(1.05); 
            background: #dc2626; 
            color: white; 
        }

        .control-group { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 20px; 
            margin-bottom: 20px; 
        }
        .input-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(241, 245, 249, 0.8);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
        }
        .input-wrapper label {
            font-weight: bold;
            color: #334155;
        }
        .chem-selector-row {
            display: flex;
            gap: 5px;
            align-items: stretch;
        }
        .ts-wrapper {
            flex-grow: 1; 
        }
        
        .btn-periodic-trigger {
            background: #475569;
            color: white; border: none; border-radius: 8px;
            padding: 0 10px; cursor: pointer; font-size: 14px;
            white-space: nowrap; transition: background 0.2s;
            display: flex; align-items: center;
        }
        .btn-periodic-trigger:hover { background: #334155; }
        
        select, input, button {
            font-family: 'Itim', cursive; 
            width: 100%; 
            padding: 12px;
            border: 2px solid #cbd5e1; 
            border-radius: 8px; 
            font-size: 16px; 
            box-sizing: border-box;
            background: #fff;
        }

        button#mix-button {
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            color: white; 
            border: none; 
            cursor: pointer; 
            font-size: 20px; 
            font-weight: bold;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(168, 85, 247, 0.4);
            padding: 15px;
            border-radius: 12px;
            margin-top: 10px;
        }
        button#mix-button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(168, 85, 247, 0.6); }
        button#mix-button:active { transform: translateY(1px); }
        button:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }

        #viewer3d {
            height: 400px; 
            width: 100%;
            background: radial-gradient(circle, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 12px; 
            border: 2px dashed #94a3b8;
            position: relative; 
            overflow: hidden; 
            margin-top: 25px;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.05);
        }

        #result-box {
            margin-top: 25px; 
            padding: 20px;
            background: #f8fafc; 
            border-radius: 12px; 
            border-left: 6px solid #a855f7;
            font-size: 16px; 
            line-height: 1.6;
            display: none; /* ซ่อนไว้ก่อนจนกว่าจะผสม */
            animation: fadeIn 0.5s ease;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .res-row { 
            display: flex; 
            justify-content: space-between; 
            border-bottom: 1px dashed #cbd5e1; 
            padding: 8px 0; 
        }
        .res-row:last-child { border-bottom: none; }
        .res-val { 
            font-weight: bold; 
            color: #4f46e5; 
        }

        /* --- CSS แถบสถานะฝั่งขวา --- */
        .status-panel {
            position: fixed; top: 20px; right: 20px; width: 260px;
            background: rgba(15, 23, 42, 0.85); padding: 15px; border-radius: 12px;
            color: white; z-index: 1000; box-shadow: 0 5px 15px rgba(0,0,0,0.5); backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .bar-row { margin-bottom: 12px; }
        .bar-label { font-size: 14px; margin-bottom: 6px; display: flex; justify-content: space-between; font-weight: bold;}
        .progress-track { width: 100%; height: 14px; background: #334155; border-radius: 8px; overflow: hidden; border: 1px solid #1e293b; }
        .progress-fill { height: 100%; width: 100%; transition: width 0.5s ease, background-color 0.5s ease; }
        #beaker-bar { background: #38bdf8; box-shadow: 0 0 10px rgba(56,189,248,0.5); }
        #health-bar { background: #4ade80; box-shadow: 0 0 10px rgba(74,222,128,0.5); }
        button.reset-btn {
            background: #ef4444; color: white; border: none; margin-top: 10px; font-size: 15px; padding: 10px; width: 100%; cursor: pointer; border-radius: 8px; font-weight: bold; transition: background 0.2s;
        }
        button.reset-btn:hover { background: #dc2626; }

        /* --- CSS กระดานภารกิจ ฝั่งซ้าย --- */
        .quest-panel {
            position: fixed; top: 20px; left: 20px; width: 280px;
            background: rgba(15, 23, 42, 0.85); padding: 15px; border-radius: 12px;
            color: white; z-index: 1000; box-shadow: 0 5px 15px rgba(0,0,0,0.5); backdrop-filter: blur(8px);
            max-height: 90vh; overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .quest-panel::-webkit-scrollbar { width: 6px; }
        .quest-panel::-webkit-scrollbar-thumb { background: #475569; border-radius: 3px; }
        
        .quest-panel h3 { margin-top: 0; color: #fde047; font-size: 20px; border-bottom: 1px solid #334155; padding-bottom: 10px; display: flex; align-items: center; gap: 8px;}
        .quest-card { background: rgba(255,255,255,0.05); border-radius: 8px; padding: 12px; margin-bottom: 12px; border: 1px solid #334155; transition: transform 0.2s; }
        .quest-card:hover { transform: translateX(5px); background: rgba(255,255,255,0.08); }
        .quest-title { font-weight: bold; font-size: 16px; color: #60a5fa; margin-bottom: 6px; }
        .quest-desc { font-size: 13px; color: #cbd5e1; margin-bottom: 10px; line-height: 1.4; }
        .quest-target { font-size: 14px; font-weight: bold; color: #34d399; margin-bottom: 6px; }
        .quest-rewards { font-size: 13px; color: #fbbf24; margin-bottom: 10px; font-weight: bold; }
        .quest-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .quest-badge.completed { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid #10b981; }
        .quest-badge.pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid #f59e0b; }
        .quest-badge.teacher_preview { background: rgba(99, 102, 241, 0.2); color: #818cf8; border: 1px solid #6366f1; }

        .ts-dropdown { z-index: 99999 !important; font-family: 'Itim', cursive; }

        /* --- CSS Effect หน้าจอแตก/พิษ --- */
        #broken-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: url('https://upload.wikimedia.org/wikipedia/commons/thumb/4/4e/Broken_glass.png/800px-Broken_glass.png'); 
            background-size: cover; pointer-events: none; opacity: 0; transition: opacity 0.1s; z-index: 9999; mix-blend-mode: multiply;
        }
        #toxic-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(circle, transparent 20%, rgba(34, 197, 94, 0.7) 90%);
            pointer-events: none; opacity: 0; transition: opacity 1.5s ease; z-index: 9998; mix-blend-mode: hard-light;
        }
        .shake { animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
        @keyframes shake {
            10%, 90% { transform: translate3d(-4px, 0, 0); }
            20%, 80% { transform: translate3d(6px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-8px, 0, 0); }
            40%, 60% { transform: translate3d(8px, 0, 0); }
        }

        /* =========================================
           CSS สำหรับ Modal ตารางธาตุ
           ========================================= */
        .periodic-modal-overlay {
            display: none; 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(15, 23, 42, 0.9); z-index: 10000;
            justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; overflow: auto;
            backdrop-filter: blur(5px);
        }
        .periodic-modal-content {
            background-color: #1e293b; color: #f8fafc; padding: 30px; border-radius: 16px;
            width: 100%; max-width: 1250px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); position: relative; overflow-x: auto;
            border: 1px solid #334155;
        }
        .periodic-close-btn {
            position: absolute; top: 15px; right: 25px; color: #f87171; font-size: 32px; font-weight: bold; cursor: pointer; transition: color 0.2s;
        }
        .periodic-close-btn:hover { color: #ef4444; }
        .periodic-modal-title { text-align: center; margin-top: 0; margin-bottom: 10px; font-size: 28px; color: #e2e8f0; }
        .periodic-grid {
            display: grid; grid-template-columns: repeat(18, minmax(50px, 1fr));
            grid-template-rows: repeat(7, minmax(50px, auto)) 20px repeat(2, minmax(50px, auto)); gap: 4px; padding: 10px 0; user-select: none;
        }
        .element-cell {
            border: 1px solid rgba(255,255,255,0.1); border-radius: 4px; padding: 2px; display: flex; flex-direction: column;
            justify-content: center; align-items: center; cursor: pointer; transition: all 0.2s;
            aspect-ratio: 1 / 1; position: relative; background-color: #334155;
        }
        .element-cell:hover { transform: scale(1.2); z-index: 10; box-shadow: 0 0 20px rgba(255,255,255,0.4); border-color: #fff; }
        .atom-num { font-size: 10px; position: absolute; top: 2px; left: 4px; opacity: 0.6; }
        .atom-sym { font-size: 18px; font-weight: bold; }
        .atom-name { font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 95%; opacity: 0.8;}
        .empty-cell { pointer-events: none; border: none; background: transparent; }

        /* สีตามกลุ่มธาตุ อิงตามมาตรฐานเคมี */
        .cat-alkali { background-color: #ef4444; color: white; }
        .cat-alkaline-earth { background-color: #f97316; color: white; }
        .cat-transition { background-color: #eab308; color: black; }
        .cat-post-transition { background-color: #84cc16; color: black; }
        .cat-metalloid { background-color: #06b6d4; color: black; }
        .cat-nonmetal { background-color: #3b82f6; color: white; }
        .cat-halogen { background-color: #8b5cf6; color: white; }
        .cat-noble-gas { background-color: #d946ef; color: white; }
        .cat-lanthanide { background-color: #f472b6; color: black; }
        .cat-actinide { background-color: #c084fc; color: black; }

        /* Media Queries สำหรับหน้าจอเล็ก */
        @media (max-width: 1100px) {
            .quest-panel, .status-panel { display: none; } /* ซ่อนแถบด้านข้างเมื่อจอเล็ก */
        }
    </style>

    <script type="importmap">
    {
        "imports": {
            "three": "https://esm.sh/three@0.150.1",
            "three/addons/OrbitControls.js": "https://esm.sh/three@0.150.1/examples/jsm/controls/OrbitControls.js"
        }
    }
    </script>
</head>
<body>

<div class="quest-panel">
    <h3>📜 ภารกิจเคมีประจำแล็บ</h3>
    <?php if (empty($quests)): ?>
        <p style="color:#64748b; font-size:14px; text-align:center; padding: 20px 0;">ยังไม่มีภารกิจให้ทำในขณะนี้</p>
    <?php else: ?>
        <?php foreach ($quests as $q): ?>
            <div class="quest-card">
                <div class="quest-title"><?= htmlspecialchars($q['title']) ?></div>
                <div class="quest-desc"><?= htmlspecialchars($q['description']) ?></div>
                <div class="quest-target">🎯 สร้าง: <?= htmlspecialchars($q['target_chem_name'] ?? 'ไม่ระบุ') ?></div>
                <div class="quest-rewards">
                    ✨ <?= $q['xp_reward'] ?> XP &nbsp;|&nbsp; 💰 <?= $q['gold_reward'] ?> Gold
                </div>
                <?php if($q['status'] === 'completed'): ?>
                    <div class="quest-badge completed">✅ สำเร็จแล้ว</div>
                <?php else if($q['status'] === 'teacher_preview'): ?>
                    <div class="quest-badge teacher_preview">👀 มุมมองครู (Preview)</div>
                <?php else: ?>
                    <div class="quest-badge pending">⏳ กำลังดำเนินการ</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="status-panel">
    <div class="bar-row">
        <span class="bar-label">🧊 ความทนทานบีกเกอร์ <span id="text-beaker">100%</span></span>
        <div class="progress-track"><div id="beaker-bar" class="progress-fill" style="width: 100%;"></div></div>
    </div>
    <div class="bar-row">
        <span class="bar-label">❤️ สุขภาพร่างกาย <span id="text-health">100%</span></span>
        <div class="progress-track"><div id="health-bar" class="progress-fill" style="width: 100%;"></div></div>
    </div>
    <button class="reset-btn" id="btn-reset-all">🔄 เบิกอุปกรณ์ใหม่ (Reset)</button>
</div>

<div id="broken-overlay"></div>
<div id="toxic-overlay"></div>

<div class="container">
    <h2>🧪 Ultimate Chemistry Lab</h2>
    
    <a href="<?= htmlspecialchars($dashboard_link) ?>" class="btn-back">⬅ กลับ Dashboard</a>

    <div class="control-group">
        <div class="input-wrapper">
            <label>สารเคมี A (สารตั้งต้น):</label>
            <div class="chem-selector-row">
                <select id="chemicalA" placeholder="ค้นหาชื่อสารเคมี หรือ ธาตุ..."></select>
                <button class="btn-periodic-trigger" onclick="openPeriodicTable('A')">📅 ตารางธาตุ</button>
            </div>
            <input type="number" id="volA" value="50" min="1" max="1000" placeholder="ปริมาตร (ml)" style="margin-top: 5px;">
        </div>

        <div class="input-wrapper">
            <label>สารเคมี B (ตัวทำปฏิกิริยา):</label>
            <div class="chem-selector-row">
                 <select id="chemicalB" placeholder="ค้นหาชื่อสารเคมี หรือ ธาตุ..."></select>
                 <button class="btn-periodic-trigger" onclick="openPeriodicTable('B')">📅 ตารางธาตุ</button>
            </div>
            <input type="number" id="volB" value="50" min="1" max="1000" placeholder="ปริมาตร (ml)" style="margin-top: 5px;">
        </div>
    </div>

    <button id="mix-button">⚗️ ผสมสารเคมี (Mix It!)</button>

    <div id="viewer3d">
        <div id="viewer3d-fallback" style="text-align:center; padding-top: 180px; color: #94a3b8;">
            กำลังโหลดโมเดล 3D...<br><span style="font-size:12px;">(หากระบบไม่โหลดภาพ 3D กรุณาตรวจสอบไฟล์ js/3d_engine.js)</span>
        </div>
    </div>

    <div id="result-box">
        <div class="res-row"><span>📦 ผลิตภัณฑ์ที่ได้:</span> <span id="res-product" class="res-val">-</span></div>
        <div class="res-row"><span>📝 สูตรเคมี:</span> <span id="res-formula" class="res-val">-</span></div>
        <div class="res-row"><span>🌡️ อุณหภูมิ:</span> <span id="res-temp" class="res-val">-</span></div>
        <div class="res-row"><span>🎨 สีสารละลาย:</span> <span id="res-color" class="res-val">-</span></div>
        <div class="res-row"><span>💧 สถานะ:</span> <span id="res-state" class="res-val">-</span></div>
        <div class="res-row"><span>🧱 ตะกอน:</span> <span id="res-precipitate" class="res-val">-</span></div>
        <div class="res-row"><span>☁️ แก๊สที่เกิด:</span> <span id="res-gas" class="res-val">-</span></div>
        <div style="margin-top: 12px; font-size: 0.9em; text-align: right; color: #64748b;">
            ปริมาตรรวม: <span id="res-volume">0</span> mL
        </div>
    </div>
</div>

<div id="periodicModal" class="periodic-modal-overlay">
    <div class="periodic-modal-content">
        <span class="periodic-close-btn" onclick="closePeriodicTable()">&times;</span>
        <h3 class="periodic-modal-title">ตารางธาตุ (Periodic Table of Elements)</h3>
        <p style="text-align:center; margin-bottom:20px; font-size: 15px; color: #94a3b8;">คลิกที่ธาตุเพื่อเลือก (ระบบจะค้นหาชื่อที่ตรงกับในฐานข้อมูลสารเคมีของโรงเรียน)</p>
        <div id="periodicGridContainer" class="periodic-grid"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script type="module">
    // โหลด 3D Engine
    // หมายเหตุ: ไฟล์นี้ต้องมีอยู่จริงในโฟลเดอร์ js/ ถึงจะแสดง 3D ได้
    import { init3DScene, updateLiquidVisuals } from './js/3d_engine.js';

    let tomA, tomB;
    let hp = 100;
    let beakerHp = 100;
    let currentTargetInput = null;

    document.addEventListener('DOMContentLoaded', () => {
        // เริ่มต้น 3D Scene
        const container = document.getElementById('viewer3d');
        if (container) {
            try {
                init3DScene(container);
                const fallback = document.getElementById('viewer3d-fallback');
                if(fallback) fallback.style.display = 'none';
            } catch (e) {
                console.warn("3D Engine error or not found. Running in 2D mode.", e);
            }
        }

        // โหลดข้อมูล Dropdown
        loadChemicalsAndInitTomSelect();

        // ตั้งค่า Event Listner ให้ปุ่ม
        document.getElementById('mix-button').addEventListener('click', handleMix);
        document.getElementById('btn-reset-all').addEventListener('click', () => {
            if(confirm("ต้องการเคลียร์สารในบีกเกอร์และเริ่มใหม่หรือไม่?")) window.location.reload();
        });

        // วาดตารางธาตุ
        renderPeriodicTable();
    });
    
    async function loadChemicalsAndInitTomSelect() {
        try {
            // เรียกใช้ API จากไฟล์ตัวเองเพื่อดึงรายการสารเคมี
            const response = await fetch('mix.php?action=get_chemicals');
            const data = await response.json();
            
            if (!Array.isArray(data)) throw new Error("รูปแบบข้อมูลจาก Database ไม่ถูกต้อง");

            const config = {
                valueField: 'value', 
                labelField: 'text',  
                searchField: 'text', 
                options: data,       
                maxOptions: 500,
                placeholder: 'พิมพ์ชื่อสารเคมี หรือ สูตรเคมี...',
                dropdownParent: 'body', 
                render: {
                    option: function(data, escape) { 
                        return '<div style="padding: 8px; border-bottom: 1px solid #f1f5f9;">' + escape(data.text) + '</div>'; 
                    },
                    no_results: function(data, escape) { 
                        return '<div class="no-results" style="padding: 10px; color: #ef4444;">ไม่พบข้อมูลสารนี้ในคลังเคมีของโรงเรียน</div>'; 
                    }
                }
            };

            tomA = new TomSelect("#chemicalA", config);
            tomB = new TomSelect("#chemicalB", config);

        } catch (error) {
            console.error("Failed to load chemicals:", error);
            alert("⚠️ ไม่สามารถโหลดรายการสารเคมีได้ (กรุณาตรวจสอบการเชื่อมต่อฐานข้อมูล)");
        }
    }

    // ข้อมูลตารางธาตุ 118 ธาตุ แบบสมบูรณ์
    const periodicTableData = [
        { num: 1, sym: 'H', name: 'Hydrogen', group: 1, period: 1, cat: 'nonmetal' },
        { num: 2, sym: 'He', name: 'Helium', group: 18, period: 1, cat: 'noble-gas' },
        { num: 3, sym: 'Li', name: 'Lithium', group: 1, period: 2, cat: 'alkali' },
        { num: 4, sym: 'Be', name: 'Beryllium', group: 2, period: 2, cat: 'alkaline-earth' },
        { num: 5, sym: 'B', name: 'Boron', group: 13, period: 2, cat: 'metalloid' },
        { num: 6, sym: 'C', name: 'Carbon', group: 14, period: 2, cat: 'nonmetal' },
        { num: 7, sym: 'N', name: 'Nitrogen', group: 15, period: 2, cat: 'nonmetal' },
        { num: 8, sym: 'O', name: 'Oxygen', group: 16, period: 2, cat: 'nonmetal' },
        { num: 9, sym: 'F', name: 'Fluorine', group: 17, period: 2, cat: 'halogen' },
        { num: 10, sym: 'Ne', name: 'Neon', group: 18, period: 2, cat: 'noble-gas' },
        { num: 11, sym: 'Na', name: 'Sodium', group: 1, period: 3, cat: 'alkali' },
        { num: 12, sym: 'Mg', name: 'Magnesium', group: 2, period: 3, cat: 'alkaline-earth' },
        { num: 13, sym: 'Al', name: 'Aluminium', group: 13, period: 3, cat: 'post-transition' },
        { num: 14, sym: 'Si', name: 'Silicon', group: 14, period: 3, cat: 'metalloid' },
        { num: 15, sym: 'P', name: 'Phosphorus', group: 15, period: 3, cat: 'nonmetal' },
        { num: 16, sym: 'S', name: 'Sulfur', group: 16, period: 3, cat: 'nonmetal' },
        { num: 17, sym: 'Cl', name: 'Chlorine', group: 17, period: 3, cat: 'halogen' },
        { num: 18, sym: 'Ar', name: 'Argon', group: 18, period: 3, cat: 'noble-gas' },
        { num: 19, sym: 'K', name: 'Potassium', group: 1, period: 4, cat: 'alkali' },
        { num: 20, sym: 'Ca', name: 'Calcium', group: 2, period: 4, cat: 'alkaline-earth' },
        { num: 21, sym: 'Sc', name: 'Scandium', group: 3, period: 4, cat: 'transition' },
        { num: 22, sym: 'Ti', name: 'Titanium', group: 4, period: 4, cat: 'transition' },
        { num: 23, sym: 'V', name: 'Vanadium', group: 5, period: 4, cat: 'transition' },
        { num: 24, sym: 'Cr', name: 'Chromium', group: 6, period: 4, cat: 'transition' },
        { num: 25, sym: 'Mn', name: 'Manganese', group: 7, period: 4, cat: 'transition' },
        { num: 26, sym: 'Fe', name: 'Iron', group: 8, period: 4, cat: 'transition' },
        { num: 27, sym: 'Co', name: 'Cobalt', group: 9, period: 4, cat: 'transition' },
        { num: 28, sym: 'Ni', name: 'Nickel', group: 10, period: 4, cat: 'transition' },
        { num: 29, sym: 'Cu', name: 'Copper', group: 11, period: 4, cat: 'transition' },
        { num: 30, sym: 'Zn', name: 'Zinc', group: 12, period: 4, cat: 'transition' },
        { num: 31, sym: 'Ga', name: 'Gallium', group: 13, period: 4, cat: 'post-transition' },
        { num: 32, sym: 'Ge', name: 'Germanium', group: 14, period: 4, cat: 'metalloid' },
        { num: 33, sym: 'As', name: 'Arsenic', group: 15, period: 4, cat: 'metalloid' },
        { num: 34, sym: 'Se', name: 'Selenium', group: 16, period: 4, cat: 'nonmetal' },
        { num: 35, sym: 'Br', name: 'Bromine', group: 17, period: 4, cat: 'halogen' },
        { num: 36, sym: 'Kr', name: 'Krypton', group: 18, period: 4, cat: 'noble-gas' },
        { num: 37, sym: 'Rb', name: 'Rubidium', group: 1, period: 5, cat: 'alkali' },
        { num: 38, sym: 'Sr', name: 'Strontium', group: 2, period: 5, cat: 'alkaline-earth' },
        { num: 39, sym: 'Y', name: 'Yttrium', group: 3, period: 5, cat: 'transition' },
        { num: 40, sym: 'Zr', name: 'Zirconium', group: 4, period: 5, cat: 'transition' },
        { num: 41, sym: 'Nb', name: 'Niobium', group: 5, period: 5, cat: 'transition' },
        { num: 42, sym: 'Mo', name: 'Molybdenum', group: 6, period: 5, cat: 'transition' },
        { num: 43, sym: 'Tc', name: 'Technetium', group: 7, period: 5, cat: 'transition' },
        { num: 44, sym: 'Ru', name: 'Ruthenium', group: 8, period: 5, cat: 'transition' },
        { num: 45, sym: 'Rh', name: 'Rhodium', group: 9, period: 5, cat: 'transition' },
        { num: 46, sym: 'Pd', name: 'Palladium', group: 10, period: 5, cat: 'transition' },
        { num: 47, sym: 'Ag', name: 'Silver', group: 11, period: 5, cat: 'transition' },
        { num: 48, sym: 'Cd', name: 'Cadmium', group: 12, period: 5, cat: 'transition' },
        { num: 49, sym: 'In', name: 'Indium', group: 13, period: 5, cat: 'post-transition' },
        { num: 50, sym: 'Sn', name: 'Tin', group: 14, period: 5, cat: 'post-transition' },
        { num: 51, sym: 'Sb', name: 'Antimony', group: 15, period: 5, cat: 'metalloid' },
        { num: 52, sym: 'Te', name: 'Tellurium', group: 16, period: 5, cat: 'metalloid' },
        { num: 53, sym: 'I', name: 'Iodine', group: 17, period: 5, cat: 'halogen' },
        { num: 54, sym: 'Xe', name: 'Xenon', group: 18, period: 5, cat: 'noble-gas' },
        { num: 55, sym: 'Cs', name: 'Cesium', group: 1, period: 6, cat: 'alkali' },
        { num: 56, sym: 'Ba', name: 'Barium', group: 2, period: 6, cat: 'alkaline-earth' },
        { num: 57, sym: 'La', name: 'Lanthanum', group: 3, period: 6, cat: 'lanthanide' },
        { num: 58, sym: 'Ce', name: 'Cerium', group: 3, period: 9, cat: 'lanthanide' }, 
        { num: 59, sym: 'Pr', name: 'Praseodymium', group: 4, period: 9, cat: 'lanthanide' },
        { num: 60, sym: 'Nd', name: 'Neodymium', group: 5, period: 9, cat: 'lanthanide' },
        { num: 61, sym: 'Pm', name: 'Promethium', group: 6, period: 9, cat: 'lanthanide' },
        { num: 62, sym: 'Sm', name: 'Samarium', group: 7, period: 9, cat: 'lanthanide' },
        { num: 63, sym: 'Eu', name: 'Europium', group: 8, period: 9, cat: 'lanthanide' },
        { num: 64, sym: 'Gd', name: 'Gadolinium', group: 9, period: 9, cat: 'lanthanide' },
        { num: 65, sym: 'Tb', name: 'Terbium', group: 10, period: 9, cat: 'lanthanide' },
        { num: 66, sym: 'Dy', name: 'Dysprosium', group: 11, period: 9, cat: 'lanthanide' },
        { num: 67, sym: 'Ho', name: 'Holmium', group: 12, period: 9, cat: 'lanthanide' },
        { num: 68, sym: 'Er', name: 'Erbium', group: 13, period: 9, cat: 'lanthanide' },
        { num: 69, sym: 'Tm', name: 'Thulium', group: 14, period: 9, cat: 'lanthanide' },
        { num: 70, sym: 'Yb', name: 'Ytterbium', group: 15, period: 9, cat: 'lanthanide' },
        { num: 71, sym: 'Lu', name: 'Lutetium', group: 16, period: 9, cat: 'lanthanide' },
        { num: 72, sym: 'Hf', name: 'Hafnium', group: 4, period: 6, cat: 'transition' },
        { num: 73, sym: 'Ta', name: 'Tantalum', group: 5, period: 6, cat: 'transition' },
        { num: 74, sym: 'W', name: 'Tungsten', group: 6, period: 6, cat: 'transition' },
        { num: 75, sym: 'Re', name: 'Rhenium', group: 7, period: 6, cat: 'transition' },
        { num: 76, sym: 'Os', name: 'Osmium', group: 8, period: 6, cat: 'transition' },
        { num: 77, sym: 'Ir', name: 'Iridium', group: 9, period: 6, cat: 'transition' },
        { num: 78, sym: 'Pt', name: 'Platinum', group: 10, period: 6, cat: 'transition' },
        { num: 79, sym: 'Au', name: 'Gold', group: 11, period: 6, cat: 'transition' },
        { num: 80, sym: 'Hg', name: 'Mercury', group: 12, period: 6, cat: 'transition' },
        { num: 81, sym: 'Tl', name: 'Thallium', group: 13, period: 6, cat: 'post-transition' },
        { num: 82, sym: 'Pb', name: 'Lead', group: 14, period: 6, cat: 'post-transition' },
        { num: 83, sym: 'Bi', name: 'Bismuth', group: 15, period: 6, cat: 'post-transition' },
        { num: 84, sym: 'Po', name: 'Polonium', group: 16, period: 6, cat: 'post-transition' },
        { num: 85, sym: 'At', name: 'Astatine', group: 17, period: 6, cat: 'halogen' },
        { num: 86, sym: 'Rn', name: 'Radon', group: 18, period: 6, cat: 'noble-gas' },
        { num: 87, sym: 'Fr', name: 'Francium', group: 1, period: 7, cat: 'alkali' },
        { num: 88, sym: 'Ra', name: 'Radium', group: 2, period: 7, cat: 'alkaline-earth' },
        { num: 89, sym: 'Ac', name: 'Actinium', group: 3, period: 7, cat: 'actinide' },
        { num: 90, sym: 'Th', name: 'Thorium', group: 3, period: 10, cat: 'actinide' }, 
        { num: 91, sym: 'Pa', name: 'Protactinium', group: 4, period: 10, cat: 'actinide' },
        { num: 92, sym: 'U', name: 'Uranium', group: 5, period: 10, cat: 'actinide' },
        { num: 93, sym: 'Np', name: 'Neptunium', group: 6, period: 10, cat: 'actinide' },
        { num: 94, sym: 'Pu', name: 'Plutonium', group: 7, period: 10, cat: 'actinide' },
        { num: 95, sym: 'Am', name: 'Americium', group: 8, period: 10, cat: 'actinide' },
        { num: 96, sym: 'Cm', name: 'Curium', group: 9, period: 10, cat: 'actinide' },
        { num: 97, sym: 'Bk', name: 'Berkelium', group: 10, period: 10, cat: 'actinide' },
        { num: 98, sym: 'Cf', name: 'Californium', group: 11, period: 10, cat: 'actinide' },
        { num: 99, sym: 'Es', name: 'Einsteinium', group: 12, period: 10, cat: 'actinide' },
        { num: 100, sym: 'Fm', name: 'Fermium', group: 13, period: 10, cat: 'actinide' },
        { num: 101, sym: 'Md', name: 'Mendelevium', group: 14, period: 10, cat: 'actinide' },
        { num: 102, sym: 'No', name: 'Nobelium', group: 15, period: 10, cat: 'actinide' },
        { num: 103, sym: 'Lr', name: 'Lawrencium', group: 16, period: 10, cat: 'actinide' },
        { num: 104, sym: 'Rf', name: 'Rutherfordium', group: 4, period: 7, cat: 'transition' },
        { num: 105, sym: 'Db', name: 'Dubnium', group: 5, period: 7, cat: 'transition' },
        { num: 106, sym: 'Sg', name: 'Seaborgium', group: 6, period: 7, cat: 'transition' },
        { num: 107, sym: 'Bh', name: 'Bohrium', group: 7, period: 7, cat: 'transition' },
        { num: 108, sym: 'Hs', name: 'Hassium', group: 8, period: 7, cat: 'transition' },
        { num: 109, sym: 'Mt', name: 'Meitnerium', group: 9, period: 7, cat: 'transition' },
        { num: 110, sym: 'Ds', name: 'Darmstadtium', group: 10, period: 7, cat: 'transition' },
        { num: 111, sym: 'Rg', name: 'Roentgenium', group: 11, period: 7, cat: 'transition' },
        { num: 112, sym: 'Cn', name: 'Copernicium', group: 12, period: 7, cat: 'transition' },
        { num: 113, sym: 'Nh', name: 'Nihonium', group: 13, period: 7, cat: 'post-transition' },
        { num: 114, sym: 'Fl', name: 'Flerovium', group: 14, period: 7, cat: 'post-transition' },
        { num: 115, sym: 'Mc', name: 'Moscovium', group: 15, period: 7, cat: 'post-transition' },
        { num: 116, sym: 'Lv', name: 'Livermorium', group: 16, period: 7, cat: 'post-transition' },
        { num: 117, sym: 'Ts', name: 'Tennessine', group: 17, period: 7, cat: 'halogen' },
        { num: 118, sym: 'Og', name: 'Oganesson', group: 18, period: 7, cat: 'noble-gas' }
    ];

    function renderPeriodicTable() {
        const gridContainer = document.getElementById('periodicGridContainer');
        if (!gridContainer) return;

        for (let row = 1; row <= 10; row++) {
            for (let col = 1; col <= 18; col++) {
                let element = null;
                for (const el of periodicTableData) {
                    if (el.period === row && el.group === col) { element = el; break; }
                }

                const cell = document.createElement('div');
                if (element) {
                    cell.className = `element-cell cat-${element.cat}`;
                    cell.innerHTML = `
                        <span class="atom-num">${element.num}</span>
                        <span class="atom-sym">${element.sym}</span>
                        <span class="atom-name">${element.name}</span>
                    `;
                    cell.style.gridRow = row;
                    cell.style.gridColumn = col;
                    cell.addEventListener('click', () => selectElementFromTable(element.name));
                } else {
                    cell.className = 'empty-cell';
                    cell.style.gridRow = row;
                    cell.style.gridColumn = col;
                }
                gridContainer.appendChild(cell);
            }
        }
    }

    // ฟังก์ชันให้ปุ่มเปิด Modal ตารางธาตุใช้งานได้
    window.openPeriodicTable = function(target) {
        currentTargetInput = target; 
        const modal = document.getElementById('periodicModal');
        if (modal) modal.style.display = 'flex'; 
    }

    window.closePeriodicTable = function() {
        currentTargetInput = null; 
        const modal = document.getElementById('periodicModal');
        if (modal) modal.style.display = 'none'; 
    }

    // ปิดเมื่อคลิกนอกกรอบ
    window.onclick = function(event) {
        const modal = document.getElementById('periodicModal');
        if (event.target == modal) closePeriodicTable();
    }

    function selectElementFromTable(elementName) {
        if (!currentTargetInput) return;
        const targetTom = (currentTargetInput === 'A') ? tomA : tomB;
        let foundId = null;
        
        // ค้นหา ID ที่ตรงกับชื่อธาตุ
        for (const [id, optionData] of Object.entries(targetTom.options)) {
            if (optionData.text.toLowerCase().includes(elementName.toLowerCase())) {
                foundId = id; break;
            }
        }

        if (foundId) {
            targetTom.setValue(foundId);
            closePeriodicTable();
        } else {
            alert(`⚠️ ไม่พบธาตุ "${elementName}" ในฐานข้อมูลสารเคมีของห้องแล็บตอนนี้\n(ครูอาจจะยังไม่ได้เพิ่มสารนี้เข้าไป)`);
        }
    }

    async function handleMix() {
        if(hp <= 0 || beakerHp <= 0) {
            alert("อุปกรณ์พัง หรือ คุณอยู่ในสภาพที่ไม่พร้อมทดลองแล้ว! กรุณากดปุ่ม 'เบิกอุปกรณ์ใหม่'");
            return;
        }

        const chemA = tomA.getValue();
        const chemB = tomB.getValue();
        const volA = document.getElementById('volA').value || 0;
        const volB = document.getElementById('volB').value || 0;

        if (!chemA || !chemB) {
            alert("⚠️ กรุณาเลือกสารเคมีให้ครบทั้ง 2 ตัวก่อนผสมครับ");
            return;
        }

        const btn = document.getElementById('mix-button');
        btn.disabled = true;
        btn.innerHTML = "⏳ กำลังทำปฏิกิริยาเคมี...";

        try {
            // เรียก API ในไฟล์ตัวเอง
            const url = `mix.php?action=mix&a=${chemA}&b=${chemB}&volA=${volA}&volB=${volB}`;
            const response = await fetch(url);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || "Server Error: การคำนวณล้มเหลว");
            }

            // แสดงกล่องผลลัพธ์
            document.getElementById('result-box').style.display = 'block';

            // ถ้ามี 3D ให้เรียกใช้อัปเดต
            if(typeof updateLiquidVisuals === 'function') {
                updateLiquidVisuals(data);
            }

            updateResultBox(data);
            handleSpecialEffects(data);

        } catch (err) {
            console.error(err);
            alert("❌ เกิดข้อผิดพลาด: " + err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = "⚗️ ผสมสารเคมี (Mix It!)";
        }
    }

    function updateResultBox(data) {
        setText('res-product', data.product_name);
        setText('res-formula', data.product_formula || "-");
        setText('res-temp', (data.temperature || 25) + " °C");
        
        const colorHex = data.special_color || '#FFFFFF';
        const colorName = data.color_name_thai || "ไม่ระบุ";
        document.getElementById('res-color').innerHTML = `
            <span style="display:inline-block; width:16px; height:16px; background-color:${colorHex}; border: 1px solid #94a3b8; margin-right:5px; vertical-align:middle; border-radius: 50%;"></span> 
            ${colorName}
        `;

        setText('res-state', translateState(data.final_state));
        setText('res-precipitate', data.precipitate);
        setText('res-gas', data.gas);
        setText('res-volume', data.total_volume);
    }

    function handleSpecialEffects(data) {
        resetEffects();
        if (data.effect_type === 'explosion') {
            triggerExplosion();
            updateBars(50, 50); 
        } else if (data.effect_type === 'toxic_gas') {
            triggerToxic();
            updateBars(30, 5); 
        } else if (data.damage_player > 0) {
            // โดนดาเมจจากสารพิษธรรมดา
            updateBars(data.damage_player, 0);
        }
    }

    function setText(id, text) { const el = document.getElementById(id); if (el) el.innerText = text; }
    function translateState(state) {
        if(state === 'liquid') return 'ของเหลว (Liquid)';
        if(state === 'solid') return 'ของแข็ง (Solid)';
        if(state === 'gas') return 'ก๊าซ (Gas)';
        return state;
    }

    function resetEffects() {
        document.getElementById('broken-overlay').style.opacity = 0;
        document.getElementById('toxic-overlay').style.opacity = 0;
        document.body.classList.remove('shake');
    }

    function triggerExplosion() {
        document.getElementById('broken-overlay').style.opacity = 1;
        document.body.classList.add('shake');
        setTimeout(() => alert("💥 ตู้มมม!!! เกิดการระเบิดอย่างรุนแรง! (บีกเกอร์แตก)"), 100);
    }

    function triggerToxic() {
        document.getElementById('toxic-overlay').style.opacity = 1;
        setTimeout(() => alert("☠️ แค่กๆ! ก๊าซพิษฟุ้งกระจายในห้องแล็บ!"), 100);
    }

    function updateBars(damagePlayer, damageBeaker) {
        hp -= damagePlayer; beakerHp -= damageBeaker;
        if(hp < 0) hp = 0; if(beakerHp < 0) beakerHp = 0;
        
        document.getElementById('health-bar').style.width = hp + "%";
        document.getElementById('text-health').innerText = hp + "%";
        document.getElementById('beaker-bar').style.width = beakerHp + "%";
        document.getElementById('text-beaker').innerText = beakerHp + "%";
        
        if(hp <= 30) document.getElementById('health-bar').style.backgroundColor = "#ef4444"; 
        else document.getElementById('health-bar').style.backgroundColor = "#4ade80";

        if(hp === 0) setTimeout(() => alert("💀 Game Over! คุณได้รับสารพิษมากเกินไป ต้องถูกนำส่งห้องพยาบาลด่วน! (โปรดกดรีเซ็ต)"), 500);
        else if(beakerHp === 0) setTimeout(() => alert("🧪 บีกเกอร์แตกแล้ว! การทดลองล้มเหลว กรุณาเบิกบีกเกอร์ใหม่ (โปรดกดรีเซ็ต)"), 500);
    }
</script>

</body>
</html>