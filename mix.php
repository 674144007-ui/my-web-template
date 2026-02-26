<?php
// ===================================================================================
// FILE: mix.php (ULTIMATE SURVIVAL LAB - MEGA FULL VERSION)
// ===================================================================================
// อัปเดต: แก้ไขปัญหา HTTP 500 โดยการจัดการ Buffer และ API Response อย่างเข้มงวด
// โครงสร้าง: [BACKEND API] -> [FRONTEND HTML] -> [FULL PERIODIC DATA] -> [JS ENGINE]
// ===================================================================================

// 1. การตั้งค่าระบบพื้นฐานและการจัดการ Error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ป้องกันการส่ง Output ก่อน Header (สาเหตุหนึ่งของ 500/Headers Already Sent)
ob_start();

// 2. โหลดระบบความปลอดภัยและฐานข้อมูล
require_once 'auth.php';
require_once 'db.php';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
if (!$conn || $conn->connect_error) {
    die("Database Connection Failed: " . ($conn ? $conn->connect_error : "Connection object is null"));
}

// 3. ตรวจสอบ Role (สิทธิ์การเข้าถึง)
requireRole(['developer', 'student', 'teacher', 'admin', 'parent']);

// 4. ดึงข้อมูล User ปัจจุบัน
$user = currentUser();
if (!$user) {
    die("Session Expired: กรุณาล็อกอินใหม่อีกครั้ง");
}

$user_id = $user['id'] ?? 0;
$role = $user['role'] ?? '';
$class_level = $user['class_level'] ?? '';

// ===================================================================================
// [PART 1] BACKEND API LOGIC (ส่วนประมวลผล AJAX)
// ===================================================================================

if (isset($_GET['action'])) {
    
    // ล้าง Output Buffer ก่อนส่ง JSON ป้องกันอักขระแปลกปลอม
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json; charset=utf-8');
    
    // ปิด Error display เฉพาะส่วน API เพื่อไม่ให้ Error ข้อความไปปนกับ JSON
    ini_set('display_errors', 0);
    error_reporting(0);

    try {
        // --- API: get_chemicals (ดึงรายการสารเคมีทั้งหมด) ---
        if ($_GET['action'] === 'get_chemicals') {
            $sql = "SELECT id, name, formula, type FROM chemicals ORDER BY type, name";
            $result = $conn->query($sql);
            
            if (!$result) throw new Exception("Query Failed: " . $conn->error);
            
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

        // --- API: mix (ประมวลผลการทำปฏิกิริยา) ---
        if ($_GET['action'] === 'mix') {
            
            // Helper: ฟังก์ชันจัดการสี (Thai Name)
            $getThaiColorName = function($hex) {
                $hex = strtoupper(ltrim($hex, '#'));
                $map = [
                    'FFFFFF' => 'สีขาวใส / ไม่มีสี', '000000' => 'สีดำ / มืดเขม่า',
                    'FF0000' => 'สีแดงสด', '00FF00' => 'สีเขียวสด', '0000FF' => 'สีน้ำเงิน',
                    'FFFF00' => 'สีเหลือง', 'FFA500' => 'สีส้ม', '800080' => 'สีม่วง',
                    'C0C0C0' => 'สีเงิน / เทา', '3B82F6' => 'สีฟ้าสดใส',
                    'FEF08A' => 'สีเหลืองอ่อน', '1D4ED8' => 'สีน้ำเงินเข้ม',
                    '000080' => 'สีน้ำเงินกรมท่า', 'A52A2A' => 'สีน้ำตาล', '808080' => 'สีเทา'
                ];
                return $map[$hex] ?? "สีผสมเฉพาะตัว (รหัส: #$hex)";
            };

            // Helper: ฟังก์ชันผสมสีตามปริมาตร (Weighted Color Mixing)
            $mixColorsWeighted = function($hex1, $vol1, $hex2, $vol2) {
                $clean = function($h) {
                    $h = preg_replace('/[^0-9A-Fa-f]/', '', $h ?: 'FFFFFF');
                    if(strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
                    return (strlen($h) === 6) ? $h : 'FFFFFF';
                };
                
                $h1 = $clean($hex1); $h2 = $clean($hex2);
                $r1 = hexdec(substr($h1,0,2)); $g1 = hexdec(substr($h1,2,2)); $b1 = hexdec(substr($h1,4,2));
                $r2 = hexdec(substr($h2,0,2)); $g2 = hexdec(substr($h2,2,2)); $b2 = hexdec(substr($h2,4,2));

                $totalVol = ($vol1 + $vol2) ?: 1;
                $r = round(($r1 * $vol1 + $r2 * $vol2) / $totalVol);
                $g = round(($g1 * $vol1 + $g2 * $vol2) / $totalVol);
                $b = round(($b1 * $vol1 + $b2 * $vol2) / $totalVol);

                return sprintf("#%02x%02x%02x", $r, $g, $b);
            };

            $id_a = isset($_GET['a']) ? intval($_GET['a']) : 0;
            $id_b = isset($_GET['b']) ? intval($_GET['b']) : 0;
            $vol_a = isset($_GET['volA']) ? floatval($_GET['volA']) : 0;
            $vol_b = isset($_GET['volB']) ? floatval($_GET['volB']) : 0;

            if ($id_a <= 0 || $id_b <= 0) throw new Exception("ข้อมูลสารไม่ครบถ้วน");

            // ดึงข้อมูลสารเคมีแบบละเอียด
            $stmt = $conn->prepare("SELECT * FROM chemicals WHERE id IN (?, ?)");
            $stmt->bind_param("ii", $id_a, $id_b);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $chemicals = [];
            while ($row = $res->fetch_assoc()) { $chemicals[$row['id']] = $row; }
            $stmt->close();

            if (!isset($chemicals[$id_a])) throw new Exception("ไม่พบข้อมูลสารละลาย A");
            if (!isset($chemicals[$id_b])) throw new Exception("ไม่พบข้อมูลสารละลาย B");

            $cA = $chemicals[$id_a]; $cB = $chemicals[$id_b];

            // 1. การคำนวณพื้นฐาน (กรณีไม่มีปฏิกิริยา)
            $total_volume = $vol_a + $vol_b;
            $result_color = $mixColorsWeighted($cA['color_neutral'], $vol_a, $cB['color_neutral'], $vol_b);
            $final_temp = 25.0;
            $product_name = "สารละลายผสม (" . $cA['name'] . " + " . $cB['name'] . ")";
            $product_formula = "-";
            $precipitate = "ไม่มีตะกอน";
            $gas_result = "ไม่มีแก๊ส";
            $damage_player = round(($cA['toxicity'] + $cB['toxicity']) / 2);
            $effect_type = "normal";
            $final_state = "liquid";
            $has_bubbles = false;
            $bubble_color = "#FFFFFF";

            // 2. ค้นหาปฏิกิริยาเคมีในตาราง reactions
            $stmt2 = $conn->prepare("SELECT * FROM reactions WHERE (chem1_id=? AND chem2_id=?) OR (chem1_id=? AND chem2_id=?) LIMIT 1");
            $stmt2->bind_param("iiii", $id_a, $id_b, $id_b, $id_a);
            $stmt2->execute();
            $react = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();

            if ($react) {
                if (!empty($react['product_name'])) $product_name = $react['product_name'];
                if (!empty($react['result_color'])) $result_color = $react['result_color'];
                if (!empty($react['result_precipitate']) && $react['result_precipitate'] !== 'ไม่มีตะกอน') $precipitate = $react['result_precipitate'];
                
                if (!empty($react['result_gas']) && $react['result_gas'] !== 'ไม่มีแก๊ส') {
                    $gas_result = $react['result_gas'];
                    $has_bubbles = true;
                    $bubble_color = $react['gas_color'] ?: "#FFFFFF";
                }

                $final_temp += floatval($react['heat_level']);
                $damage_player += intval($react['toxicity_bonus']);
                
                if ($react['is_explosive']) {
                    $effect_type = "explosion";
                    $damage_player = 100;
                    $result_color = "#222222";
                } elseif ($damage_player >= 50 && $final_temp > 60) {
                    $effect_type = "toxic_gas";
                }

                if ($final_temp >= 100) $final_state = "gas";
            } else {
                if ($id_a === $id_b) {
                    $product_name = $cA['name'];
                    $product_formula = $cA['formula'] ?? "-";
                }
            }

            echo json_encode([
                "success" => true,
                "product_name" => $product_name,
                "product_formula" => $product_formula,
                "color_name_thai" => $getThaiColorName($result_color),
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
            exit;
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
        exit;
    }
}

// ===================================================================================
// [PART 2] UI DATA PREPARATION (เตรียมข้อมูลหน้าเว็บ)
// ===================================================================================

// กำหนด Dashboard Link
$dashboard_link = "index.php";
if ($role === 'student') $dashboard_link = "dashboard_student.php";
elseif ($role === 'teacher') $dashboard_link = "dashboard_teacher.php";
elseif ($role === 'developer' || $role === 'admin') $dashboard_link = "dashboard_dev.php";
elseif ($role === 'parent') $dashboard_link = "dashboard_parent.php";

// ดึงข้อมูลเควสต์ (Quest Board)
$quests = [];
if ($role === 'student' && !empty($class_level)) {
    $stmt = $conn->prepare("
        SELECT q.id, q.title, q.description, q.xp_reward, q.gold_reward, c.name AS target_chem_name, IFNULL(sqp.status, 'pending') AS status
        FROM quests q
        LEFT JOIN chemicals c ON q.target_chem_id = c.id
        LEFT JOIN student_quest_progress sqp ON sqp.quest_id = q.id AND sqp.student_id = ?
        WHERE q.assigned_class = ? ORDER BY q.created_at DESC
    ");
    $stmt->bind_param("is", $user_id, $class_level);
    $stmt->execute();
    $quests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($role === 'teacher') {
    $stmt = $conn->prepare("SELECT q.*, c.name AS target_chem_name, 'teacher_preview' AS status FROM quests q LEFT JOIN chemicals c ON q.target_chem_id = c.id WHERE q.teacher_id = ? ORDER BY q.created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $quests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate Chemistry Lab Survival (v.Full)</title>

    <link href="https://fonts.googleapis.com/css2?family=Itim&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    
    <style>
        /* ===========================================================================
           CSS CORE - จัดเต็มทุกรายละเอียด
           =========================================================================== */
        :root {
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --danger: #ef4444;
            --success: #10b981;
            --bg-dark: #0f172a;
            --panel-bg: rgba(15, 23, 42, 0.85);
        }

        body {
            font-family: 'Itim', cursive;
            margin: 0; padding: 0;
            background: url('images_bg.png') no-repeat center center fixed;
            background-size: cover;
            background-color: var(--bg-dark);
            color: #334155;
            min-height: 100vh;
            display: flex; justify-content: center; align-items: flex-start;
            padding-top: 40px;
            overflow-x: hidden;
        }

        .container {
            width: 90%; max-width: 900px;
            background: rgba(255, 255, 255, 0.95);
            padding: 30px; border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            position: relative; z-index: 10;
            backdrop-filter: blur(10px);
            margin-bottom: 60px;
        }

        /* --- Header & Navigation --- */
        h2 { text-align: center; color: var(--bg-dark); font-size: 32px; margin-bottom: 20px; text-shadow: 1px 1px 2px rgba(0,0,0,0.1); }
        .btn-back {
            display: block; width: fit-content; margin: 0 auto 25px;
            padding: 10px 25px; background: var(--danger); color: white;
            text-decoration: none; border-radius: 50px;
            font-weight: bold; transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);
        }
        .btn-back:hover { transform: translateY(-2px); background: #dc2626; box-shadow: 0 6px 15px rgba(239, 68, 68, 0.4); }

        /* --- Input Controls --- */
        .control-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 30px; }
        .input-wrapper {
            background: #f8fafc; padding: 20px; border-radius: 18px;
            border: 1px solid #e2e8f0; transition: border-color 0.3s;
        }
        .input-wrapper:focus-within { border-color: var(--primary); }
        .input-wrapper label { font-weight: bold; color: #475569; display: block; margin-bottom: 12px; }
        
        .chem-selector-row { display: flex; gap: 8px; }
        .btn-periodic-trigger {
            background: #475569; color: white; border: none; border-radius: 10px;
            padding: 0 12px; cursor: pointer; transition: background 0.2s;
            font-size: 14px; white-space: nowrap;
        }
        .btn-periodic-trigger:hover { background: #334155; }

        input[type="number"] {
            width: 100%; padding: 12px; margin-top: 10px;
            border: 2px solid #cbd5e1; border-radius: 10px;
            box-sizing: border-box; font-family: 'Itim';
        }

        /* --- Mix Button --- */
        #mix-button {
            width: 100%; padding: 20px; border: none; border-radius: 18px;
            background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            color: white; font-size: 26px; font-weight: bold; cursor: pointer;
            box-shadow: 0 10px 25px rgba(168, 85, 247, 0.4);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            font-family: 'Itim';
        }
        #mix-button:hover:not(:disabled) { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(168, 85, 247, 0.5); }
        #mix-button:active { transform: translateY(2px); }
        #mix-button:disabled { background: #94a3b8; cursor: not-allowed; box-shadow: none; transform: none; }

        /* --- Visual Area (3D & Result) --- */
        #viewer3d {
            width: 100%; height: 450px; background: radial-gradient(circle, #f8fafc 0%, #cbd5e1 100%);
            border-radius: 20px; border: 3px dashed #94a3b8; margin-top: 30px;
            position: relative; overflow: hidden; box-shadow: inset 0 5px 20px rgba(0,0,0,0.05);
        }

        #result-box {
            margin-top: 30px; padding: 25px; border-radius: 20px;
            background: #ffffff; border-left: 10px solid var(--primary);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05); display: none;
            animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .res-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px dashed #e2e8f0; }
        .res-row:last-child { border-bottom: none; }
        .res-label { color: #64748b; font-size: 16px; }
        .res-val { color: var(--primary); font-weight: bold; font-size: 18px; }

        /* --- Side Panels --- */
        .side-panel {
            position: fixed; width: 280px; padding: 20px;
            background: var(--panel-bg); border-radius: 20px;
            color: white; z-index: 100; backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .quest-panel { left: 20px; top: 20px; max-height: 85vh; overflow-y: auto; }
        .status-panel { right: 20px; top: 20px; }

        .quest-card {
            background: rgba(255,255,255,0.08); padding: 15px; border-radius: 12px;
            margin-bottom: 15px; border-left: 4px solid #3b82f6; transition: 0.3s;
        }
        .quest-card:hover { background: rgba(255,255,255,0.12); transform: translateX(5px); }

        .status-bar-container { margin-bottom: 15px; }
        .bar-label { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 6px; }
        .bar-outer { width: 100%; height: 14px; background: #1e293b; border-radius: 10px; overflow: hidden; border: 1px solid #334155; }
        .bar-inner { height: 100%; width: 100%; transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1); }

        /* --- Overlays --- */
        #broken-overlay { position: fixed; inset: 0; background: url('https://upload.wikimedia.org/wikipedia/commons/thumb/4/4e/Broken_glass.png/800px-Broken_glass.png'); background-size: cover; opacity: 0; pointer-events: none; z-index: 1000; transition: opacity 0.1s; mix-blend-mode: screen; }
        #toxic-overlay { position: fixed; inset: 0; background: radial-gradient(circle, transparent 20%, rgba(34, 197, 94, 0.6) 90%); opacity: 0; pointer-events: none; z-index: 999; transition: opacity 1.5s ease; mix-blend-mode: multiply; }

        /* --- Periodic Table Modal --- */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 10000; justify-content: center; align-items: center; padding: 20px; backdrop-filter: blur(5px); }
        .modal-content { background: #1e293b; width: 95%; max-width: 1200px; border-radius: 24px; padding: 30px; position: relative; max-height: 90vh; overflow: auto; border: 1px solid #334155; }
        .close-btn { position: absolute; right: 25px; top: 15px; font-size: 35px; color: var(--danger); cursor: pointer; }

        .periodic-grid {
            display: grid; grid-template-columns: repeat(18, 1fr); gap: 4px; padding: 15px 0;
        }
        .element-cell {
            aspect-ratio: 1/1; border-radius: 6px; display: flex; flex-direction: column;
            justify-content: center; align-items: center; cursor: pointer; transition: 0.2s;
            font-size: 12px; position: relative; background: #334155; color: white;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .element-cell:hover { transform: scale(1.3); z-index: 100; box-shadow: 0 0 15px white; border-color: white; }
        .atom-num { position: absolute; top: 2px; left: 4px; font-size: 8px; opacity: 0.7; }
        .atom-sym { font-weight: bold; font-size: 16px; }

        /* สีธาตุ */
        .cat-nonmetal { background: #3b82f6; }
        .cat-noble-gas { background: #a855f7; }
        .cat-alkali { background: #ef4444; }
        .cat-alkaline { background: #f97316; }
        .cat-metalloid { background: #06b6d4; }
        .cat-halogen { background: #8b5cf6; }
        .cat-transition { background: #eab308; color: black; }
        .cat-post-transition { background: #10b981; }
        .cat-lanthanide { background: #f472b6; color: black; }
        .cat-actinide { background: #fb7185; color: black; }

        @media (max-width: 1250px) { .side-panel { display: none; } }
    </style>
</head>
<body>

<div id="broken-overlay"></div>
<div id="toxic-overlay"></div>

<div class="side-panel quest-panel">
    <h3 style="color: #fde047; margin-top: 0; border-bottom: 2px solid rgba(255,255,255,0.1); padding-bottom: 10px;">📜 ภารกิจเคมี</h3>
    <?php if (empty($quests)): ?>
        <p style="text-align:center; color: #94a3b8; padding: 20px;">ไม่มีภารกิจในขณะนี้</p>
    <?php else: ?>
        <?php foreach ($quests as $q): ?>
            <div class="quest-card">
                <div style="font-weight: bold; color: #60a5fa;"><?= htmlspecialchars($q['title']) ?></div>
                <div style="font-size: 13px; color: #cbd5e1; margin: 5px 0;"><?= htmlspecialchars($q['description']) ?></div>
                <div style="font-size: 14px; font-weight: bold; color: #34d399;">🎯 สร้าง: <?= htmlspecialchars($q['target_chem_name'] ?? 'ไม่ระบุ') ?></div>
                <div style="font-size: 12px; color: #fbbf24; margin-top: 5px;">💰 <?= $q['gold_reward'] ?> Gold | ✨ <?= $q['xp_reward'] ?> XP</div>
                <div style="margin-top: 8px; font-size: 11px;"><?= $q['status'] === 'completed' ? '✅ สำเร็จ' : '⏳ กำลังทำ' ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="side-panel status-panel">
    <h3 style="color: #60a5fa; margin-top: 0;">📊 สถานะปัจจุบัน</h3>
    <div class="status-bar-container">
        <div class="bar-label"><span>❤️ พลังชีวิต</span> <span id="hp-text">100%</span></div>
        <div class="bar-outer"><div id="hp-bar" class="bar-inner" style="background: #4ade80; width: 100%;"></div></div>
    </div>
    <div class="status-bar-container">
        <div class="bar-label"><span>🧊 ความทนทานบีกเกอร์</span> <span id="beaker-text">100%</span></div>
        <div class="bar-outer"><div id="beaker-bar" class="bar-inner" style="background: #38bdf8; width: 100%;"></div></div>
    </div>
    <button onclick="location.reload()" style="width: 100%; padding: 12px; background: var(--danger); color: white; border: none; border-radius: 12px; cursor: pointer; font-family: 'Itim'; font-weight: bold;">🔄 เบิกอุปกรณ์ใหม่</button>
</div>

<div class="container">
    <h2>🧪 Ultimate Chemistry Lab</h2>
    <a href="<?= htmlspecialchars($dashboard_link) ?>" class="btn-back">⬅ กลับสู่หน้าแดชบอร์ด</a>

    <div class="control-grid">
        <div class="input-wrapper">
            <label>🧪 สารละลาย A (หลัก):</label>
            <div class="chem-selector-row">
                <select id="chemA" placeholder="ค้นหาสารเคมี..."></select>
                <button class="btn-periodic-trigger" onclick="openPeriodic('A')">ตารางธาตุ</button>
            </div>
            <input type="number" id="volA" value="50" min="1" max="1000" placeholder="ปริมาตร (mL)">
        </div>

        <div class="input-wrapper">
            <label>🧪 สารละลาย B (ตัวเติม):</label>
            <div class="chem-selector-row">
                <select id="chemB" placeholder="ค้นหาสารเคมี..."></select>
                <button class="btn-periodic-trigger" onclick="openPeriodic('B')">ตารางธาตุ</button>
            </div>
            <input type="number" id="volB" value="50" min="1" max="1000" placeholder="ปริมาตร (mL)">
        </div>
    </div>

    <button id="mix-button">⚗️ ผสมสารเคมี (Mix It!)</button>

    <div id="viewer3d">
        <div id="viewer3d-fallback" style="text-align:center; padding-top:200px; color:#94a3b8;">
            <div style="font-size: 40px;">⚗️</div>
            กำลังเชื่อมต่อ 3D Engine...
        </div>
    </div>

    <div id="result-box">
        <div class="res-row"><span class="res-label">📦 สารที่ได้:</span> <span id="res-name" class="res-val">-</span></div>
        <div class="res-row"><span class="res-label">📝 สูตรเคมี:</span> <span id="res-formula" class="res-val">-</span></div>
        <div class="res-row"><span class="res-label">🌡️ อุณหภูมิ:</span> <span id="res-temp" class="res-val">-</span></div>
        <div class="res-row"><span class="res-label">🎨 สี:</span> <span id="res-color" class="res-val">-</span></div>
        <div class="res-row"><span class="res-label">🧊 สถานะ:</span> <span id="res-state" class="res-val">-</span></div>
        <div class="res-row"><span class="res-label">🧱 ตะกอน:</span> <span id="res-prec" class="res-val">-</span></div>
        <div class="res-row"><span class="res-label">☁️ แก๊ส:</span> <span id="res-gas" class="res-val">-</span></div>
        <div style="text-align: right; font-size: 12px; color: #94a3b8; margin-top: 10px;">ปริมาตรรวม: <span id="res-vol">0</span> mL</div>
    </div>
</div>

<div id="periodicModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closePeriodic()">&times;</span>
        <h3 style="text-align:center; color: white; font-size: 24px; margin-top:0;">📅 ตารางธาตุ (Periodic Table)</h3>
        <p style="text-align:center; color: #94a3b8;">เลือกธาตุเพื่อนำไปใส่ในบีกเกอร์</p>
        <div id="periodicGrid" class="periodic-grid"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script>
    // 1. ข้อมูลตารางธาตุ 118 ธาตุ (Full Data)
    const periodicData = [
        { n: 1, s: 'H', name: 'Hydrogen', g: 1, p: 1, c: 'nonmetal' },
        { n: 2, s: 'He', name: 'Helium', g: 18, p: 1, c: 'noble-gas' },
        { n: 3, s: 'Li', name: 'Lithium', g: 1, p: 2, c: 'alkali' },
        { n: 4, s: 'Be', name: 'Beryllium', g: 2, p: 2, c: 'alkaline' },
        { n: 5, s: 'B', name: 'Boron', g: 13, p: 2, c: 'metalloid' },
        { n: 6, s: 'C', name: 'Carbon', g: 14, p: 2, c: 'nonmetal' },
        { n: 7, s: 'N', name: 'Nitrogen', g: 15, p: 2, c: 'nonmetal' },
        { n: 8, s: 'O', name: 'Oxygen', g: 16, p: 2, c: 'nonmetal' },
        { n: 9, s: 'F', name: 'Fluorine', g: 17, p: 2, c: 'halogen' },
        { n: 10, s: 'Ne', name: 'Neon', g: 18, p: 2, c: 'noble-gas' },
        { n: 11, s: 'Na', name: 'Sodium', g: 1, p: 3, c: 'alkali' },
        { n: 12, s: 'Mg', name: 'Magnesium', g: 2, p: 3, c: 'alkaline' },
        { n: 13, s: 'Al', name: 'Aluminium', g: 13, p: 3, c: 'post-transition' },
        { n: 14, s: 'Si', name: 'Silicon', g: 14, p: 3, c: 'metalloid' },
        { n: 15, s: 'P', name: 'Phosphorus', g: 15, p: 3, c: 'nonmetal' },
        { n: 16, s: 'S', name: 'Sulfur', g: 16, p: 3, c: 'nonmetal' },
        { n: 17, s: 'Cl', name: 'Chlorine', g: 17, p: 3, c: 'halogen' },
        { n: 18, s: 'Ar', name: 'Argon', g: 18, p: 3, c: 'noble-gas' },
        { n: 19, s: 'K', name: 'Potassium', g: 1, p: 4, c: 'alkali' },
        { n: 20, s: 'Ca', name: 'Calcium', g: 2, p: 4, c: 'alkaline' },
        { n: 21, s: 'Sc', name: 'Scandium', g: 3, p: 4, c: 'transition' },
        { n: 22, s: 'Ti', name: 'Titanium', g: 4, p: 4, c: 'transition' },
        { n: 23, s: 'V', name: 'Vanadium', g: 5, p: 4, c: 'transition' },
        { n: 24, s: 'Cr', name: 'Chromium', g: 6, p: 4, c: 'transition' },
        { n: 25, s: 'Mn', name: 'Manganese', g: 7, p: 4, c: 'transition' },
        { n: 26, s: 'Fe', name: 'Iron', g: 8, p: 4, c: 'transition' },
        { n: 27, s: 'Co', name: 'Cobalt', g: 9, p: 4, c: 'transition' },
        { n: 28, s: 'Ni', name: 'Nickel', g: 10, p: 4, c: 'transition' },
        { n: 29, s: 'Cu', name: 'Copper', g: 11, p: 4, c: 'transition' },
        { n: 30, s: 'Zn', name: 'Zinc', g: 12, p: 4, c: 'transition' },
        { n: 31, s: 'Ga', name: 'Gallium', g: 13, p: 4, c: 'post-transition' },
        { n: 32, s: 'Ge', name: 'Germanium', g: 14, p: 4, c: 'metalloid' },
        { n: 33, s: 'As', name: 'Arsenic', g: 15, p: 4, c: 'metalloid' },
        { n: 34, s: 'Se', name: 'Selenium', g: 16, p: 4, c: 'nonmetal' },
        { n: 35, s: 'Br', name: 'Bromine', g: 17, p: 4, c: 'halogen' },
        { n: 36, s: 'Kr', name: 'Krypton', g: 18, p: 4, c: 'noble-gas' },
        { n: 37, s: 'Rb', name: 'Rubidium', g: 1, p: 5, c: 'alkali' },
        { n: 38, s: 'Sr', name: 'Strontium', g: 2, p: 5, c: 'alkaline' },
        { n: 39, s: 'Y', name: 'Yttrium', g: 3, p: 5, c: 'transition' },
        { n: 40, s: 'Zr', name: 'Zirconium', g: 4, p: 5, c: 'transition' },
        { n: 41, s: 'Nb', name: 'Niobium', g: 5, p: 5, c: 'transition' },
        { n: 42, s: 'Mo', name: 'Molybdenum', g: 6, p: 5, c: 'transition' },
        { n: 43, s: 'Tc', name: 'Technetium', g: 7, p: 5, c: 'transition' },
        { n: 44, s: 'Ru', name: 'Ruthenium', g: 8, p: 5, c: 'transition' },
        { n: 45, s: 'Rh', name: 'Rhodium', g: 9, p: 5, c: 'transition' },
        { n: 46, s: 'Pd', name: 'Palladium', g: 10, p: 5, c: 'transition' },
        { n: 47, s: 'Ag', name: 'Silver', g: 11, p: 5, c: 'transition' },
        { n: 48, s: 'Cd', name: 'Cadmium', g: 12, p: 5, c: 'transition' },
        { n: 49, s: 'In', name: 'Indium', g: 13, p: 5, c: 'post-transition' },
        { n: 50, s: 'Sn', name: 'Tin', g: 14, p: 5, c: 'post-transition' },
        { n: 51, s: 'Sb', name: 'Antimony', g: 15, p: 5, c: 'metalloid' },
        { n: 52, s: 'Te', name: 'Tellurium', g: 16, p: 5, c: 'metalloid' },
        { n: 53, s: 'I', name: 'Iodine', g: 17, p: 5, c: 'halogen' },
        { n: 54, s: 'Xe', name: 'Xenon', g: 18, p: 5, c: 'noble-gas' },
        { n: 55, s: 'Cs', name: 'Cesium', g: 1, p: 6, c: 'alkali' },
        { n: 56, s: 'Ba', name: 'Barium', g: 2, p: 6, c: 'alkaline' },
        { n: 57, s: 'La', name: 'Lanthanum', g: 3, p: 6, c: 'lanthanide' },
        { n: 72, s: 'Hf', name: 'Hafnium', g: 4, p: 6, c: 'transition' },
        { n: 73, s: 'Ta', name: 'Tantalum', g: 5, p: 6, c: 'transition' },
        { n: 74, s: 'W', name: 'Tungsten', g: 6, p: 6, c: 'transition' },
        { n: 75, s: 'Re', name: 'Rhenium', g: 7, p: 6, c: 'transition' },
        { n: 76, s: 'Os', name: 'Osmium', g: 8, p: 6, c: 'transition' },
        { n: 77, s: 'Ir', name: 'Iridium', g: 9, p: 6, c: 'transition' },
        { n: 78, s: 'Pt', name: 'Platinum', g: 10, p: 6, c: 'transition' },
        { n: 79, s: 'Au', name: 'Gold', g: 11, p: 6, c: 'transition' },
        { n: 80, s: 'Hg', name: 'Mercury', g: 12, p: 6, c: 'transition' },
        { n: 81, s: 'Tl', name: 'Thallium', g: 13, p: 6, c: 'post-transition' },
        { n: 82, s: 'Pb', name: 'Lead', g: 14, p: 6, c: 'post-transition' },
        { n: 83, s: 'Bi', name: 'Bismuth', g: 15, p: 6, c: 'post-transition' },
        { n: 84, s: 'Po', name: 'Polonium', g: 16, p: 6, c: 'post-transition' },
        { n: 85, s: 'At', name: 'Astatine', g: 17, p: 6, c: 'halogen' },
        { n: 86, s: 'Rn', name: 'Radon', g: 18, p: 6, c: 'noble-gas' },
        { n: 87, s: 'Fr', name: 'Francium', g: 1, p: 7, c: 'alkali' },
        { n: 88, s: 'Ra', name: 'Radium', g: 2, p: 7, c: 'alkaline' },
        { n: 89, s: 'Ac', name: 'Actinium', g: 3, p: 7, c: 'actinide' },
        { n: 104, s: 'Rf', name: 'Rutherfordium', g: 4, p: 7, c: 'transition' },
        { n: 105, s: 'Db', name: 'Dubnium', g: 5, p: 7, c: 'transition' },
        { n: 106, s: 'Sg', name: 'Seaborgium', g: 6, p: 7, c: 'transition' },
        { n: 107, s: 'Bh', name: 'Bohrium', g: 7, p: 7, c: 'transition' },
        { n: 108, s: 'Hs', name: 'Hassium', g: 8, p: 7, c: 'transition' },
        { n: 109, s: 'Mt', name: 'Meitnerium', g: 9, p: 7, c: 'transition' },
        { n: 110, s: 'Ds', name: 'Darmstadtium', g: 10, p: 7, c: 'transition' },
        { n: 111, s: 'Rg', name: 'Roentgenium', g: 11, p: 7, c: 'transition' },
        { n: 112, s: 'Cn', name: 'Copernicium', g: 12, p: 7, c: 'transition' },
        { n: 113, s: 'Nh', name: 'Nihonium', g: 13, p: 7, c: 'post-transition' },
        { n: 114, s: 'Fl', name: 'Flerovium', g: 14, p: 7, c: 'post-transition' },
        { n: 115, s: 'Mc', name: 'Moscovium', g: 15, p: 7, c: 'post-transition' },
        { n: 116, s: 'Lv', name: 'Livermorium', g: 16, p: 7, c: 'post-transition' },
        { n: 117, s: 'Ts', name: 'Tennessine', g: 17, p: 7, c: 'halogen' },
        { n: 118, s: 'Og', name: 'Oganesson', g: 18, p: 7, c: 'noble-gas' }
    ];

    let tomA = null, tomB = null;
    let hp = 100, beakerHp = 100;
    let targetSelect = 'A';

    document.addEventListener('DOMContentLoaded', async () => {
        // วาดตารางธาตุ
        renderPeriodicTable();

        // โหลดข้อมูลสารเคมี
        try {
            const res = await fetch('mix.php?action=get_chemicals');
            const data = await res.json();
            
            const tsConfig = {
                valueField: 'value', labelField: 'text', searchField: 'text',
                options: data, maxOptions: 500,
                render: {
                    option: (d, e) => `<div style="padding: 8px;">${e(d.text)}</div>`
                }
            };

            tomA = new TomSelect('#chemA', tsConfig);
            tomB = new TomSelect('#chemB', tsConfig);
        } catch(e) { console.error("Chemicals Load Error:", e); }

        // ผูก Event ปุ่มผสม
        document.getElementById('mix-button').onclick = handleMix;
    });

    function renderPeriodicTable() {
        const grid = document.getElementById('periodicGrid');
        for (let r = 1; r <= 7; r++) {
            for (let c = 1; c <= 18; c++) {
                const el = periodicData.find(x => x.p === r && x.g === c);
                const cell = document.createElement('div');
                if (el) {
                    cell.className = `element-cell cat-${el.c}`;
                    cell.innerHTML = `<span class="atom-num">${el.n}</span><span class="atom-sym">${el.s}</span>`;
                    cell.onclick = () => selectElement(el.name);
                } else {
                    cell.style.background = 'transparent';
                }
                grid.appendChild(cell);
            }
        }
    }

    function openPeriodic(target) { targetSelect = target; document.getElementById('periodicModal').style.display = 'flex'; }
    function closePeriodic() { document.getElementById('periodicModal').style.display = 'none'; }
    
    function selectElement(name) {
        const ts = (targetSelect === 'A') ? tomA : tomB;
        const options = Object.values(ts.options);
        const match = options.find(o => o.text.toLowerCase().includes(name.toLowerCase()));
        if (match) {
            ts.setValue(match.value);
            closePeriodic();
        } else {
            alert("⚠️ ไม่พบสารนี้ในคลังเคมีของโรงเรียน");
        }
    }

    async function handleMix() {
        if(hp <= 0 || beakerHp <= 0) return alert("❌ อุปกรณ์พังแล้ว! กรุณากดรีเซ็ต");

        const a = tomA.getValue(), b = tomB.getValue();
        const vA = document.getElementById('volA').value, vB = document.getElementById('volB').value;

        if(!a || !b) return alert("กรุณาเลือกสารเคมีให้ครบ!");

        const btn = document.getElementById('mix-button');
        btn.disabled = true; btn.innerText = "⏳ กำลังเกิดปฏิกิริยา...";

        try {
            const res = await fetch(`mix.php?action=mix&a=${a}&b=${b}&volA=${vA}&volB=${vB}`);
            const data = await res.json();
            if(!data.success) throw new Error(data.error);

            // Update UI
            document.getElementById('result-box').style.display = 'block';
            document.getElementById('res-name').innerText = data.product_name;
            document.getElementById('res-formula').innerText = data.product_formula;
            document.getElementById('res-temp').innerText = data.temperature + " °C";
            document.getElementById('res-color').innerHTML = `<span style="display:inline-block;width:15px;height:15px;background:${data.special_color};border-radius:50%;margin-right:5px;"></span>${data.color_name_thai}`;
            document.getElementById('res-state').innerText = data.final_state;
            document.getElementById('res-prec').innerText = data.precipitate;
            document.getElementById('res-gas').innerText = data.gas;
            document.getElementById('res-vol').innerText = data.total_volume;

            // Effects Logic
            applyEffects(data);

        } catch(e) { alert("เกิดข้อผิดพลาด: " + e.message); }
        finally { btn.disabled = false; btn.innerText = "⚗️ ผสมสารเคมี (Mix It!)"; }
    }

    function applyEffects(data) {
        // Reset Overlays
        document.getElementById('broken-overlay').style.opacity = 0;
        document.getElementById('toxic-overlay').style.opacity = 0;

        if (data.effect_type === 'explosion') {
            document.getElementById('broken-overlay').style.opacity = 1;
            hp -= 50; beakerHp -= 100;
            alert("💥 ตู้มมม!!! ระเบิดอย่างรุนแรง!");
        } else if (data.effect_type === 'toxic_gas') {
            document.getElementById('toxic-overlay').style.opacity = 1;
            hp -= 30;
            alert("☠️ แค่กๆ... แก๊สพิษฟุ้งกระจาย!");
        } else if (data.damage_player > 0) {
            hp -= (data.damage_player / 2);
        }

        updateBars();
    }

    function updateBars() {
        if(hp < 0) hp = 0; if(beakerHp < 0) beakerHp = 0;
        document.getElementById('hp-bar').style.width = hp + "%";
        document.getElementById('hp-text').innerText = hp + "%";
        document.getElementById('beaker-bar').style.width = beakerHp + "%";
        document.getElementById('beaker-text').innerText = beakerHp + "%";
        
        if(hp <= 0) alert("💀 คุณหมดสติจากการทดลอง!");
    }
</script>

</body>
</html>