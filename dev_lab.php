<?php
// ===================================================================================
// DEV_LAB.PHP - Ultimate Chemistry Lab Simulator (Full Version)
// ===================================================================================
// ไฟล์นี้รวมการทำงานทุกอย่าง: 
// 1. ระบบหลังบ้าน (API) สำหรับคำนวณการผสมสาร
// 2. หน้าจอผู้ใช้ (UI) พร้อมกราฟิกและตารางธาตุ
// 3. การเชื่อมต่อฐานข้อมูลและการจัดการ Error
// ===================================================================================

session_start();
require_once 'db.php';
require_once 'auth.php';

// ตรวจสอบสิทธิ์การเข้าถึง (ให้ Developer, Teacher, Student เข้าได้)
// หากฟังก์ชัน requireRole ยังไม่ได้นิยาม สามารถคอมเมนต์บรรทัดนี้ออกเพื่อทดสอบก่อนได้
if (function_exists('requireRole')) {
    requireRole(['developer', 'teacher', 'student']);
}

// ===================================================================================
// [PART 1] BACKEND API LOGIC
// ทำงานเมื่อมีการเรียก URL แบบมี Parameter ?action=...
// ===================================================================================

if (isset($_GET['action'])) {
    // ตั้งค่า Header ให้เป็น JSON เพื่อให้ JS อ่านค่าได้ถูกต้อง
    header('Content-Type: application/json');
    // ปิดการแสดง Error แบบ HTML แทรกเข้ามาใน JSON เพื่อป้องกัน JSON Parse Error
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    try {
        // -----------------------------------------------------------------------
        // API ACTION: get_chemicals
        // ดึงรายชื่อสารเคมีทั้งหมดเพื่อไปแสดงใน Dropdown
        // -----------------------------------------------------------------------
        if ($_GET['action'] === 'get_chemicals') {
            // เช็คว่า Connection ฐานข้อมูลยังอยู่ดีไหม
            if ($conn->connect_error) {
                throw new Exception("Database Connection Failed: " . $conn->connect_error);
            }

            // เลือกเฉพาะคอลัมน์ที่จำเป็น เรียงตามประเภทและชื่อ
            $sql = "SELECT id, name, type FROM chemicals ORDER BY type, name";
            $result = $conn->query($sql);
            
            if (!$result) {
                throw new Exception("Query Failed: " . $conn->error);
            }
            
            $data = [];
            while ($row = $result->fetch_assoc()) {
                // จัดรูปแบบข้อมูลสำหรับ TomSelect Library (Value, Text)
                $data[] = [
                    'value' => $row['id'],
                    'text' => htmlspecialchars($row['name']) . " (" . ucfirst($row['type']) . ")"
                ];
            }
            
            // ส่งข้อมูลกลับเป็น JSON
            echo json_encode($data);
            exit; // จบการทำงานของ PHP ทันทีเมื่อส่ง JSON เสร็จ
        }

        // -----------------------------------------------------------------------
        // API ACTION: mix
        // คำนวณผลลัพธ์การผสมสารเคมี 2 ตัว
        // -----------------------------------------------------------------------
        if ($_GET['action'] === 'mix') {
            
            // --- Helper Function: แปลงสี Hex เป็นชื่อไทย (เพื่อให้แสดงผลสวยงาม) ---
            function getThaiColorName($hex) {
                $hex = strtoupper(ltrim($hex, '#'));
                // รายชื่อสีพื้นฐานและการเทียบเคียง
                $colorMap = [
                    'FFFFFF' => 'สีขาวใส / ไม่มีสี',
                    '000000' => 'สีดำ / มืด',
                    'FF0000' => 'สีแดงสด',
                    '00FF00' => 'สีเขียวสด',
                    '0000FF' => 'สีน้ำเงิน',
                    'FFFF00' => 'สีเหลือง',
                    'FFA500' => 'สีส้ม',
                    '800080' => 'สีม่วง',
                    'C0C0C0' => 'สีเงิน / เทา',
                    '808080' => 'สีเทาเข้ม',
                    'A52A2A' => 'สีน้ำตาล',
                    'FFC0CB' => 'สีชมพู',
                    '3B82F6' => 'สีฟ้าสดใส',
                    'FEF08A' => 'สีเหลืองอ่อน',
                    '1D4ED8' => 'สีน้ำเงินเข้ม',
                    'CBD5E1' => 'สีควันบุหรี่'
                ];
                
                // คืนค่าถ้าตรงเป๊ะ
                if (isset($colorMap[$hex])) return $colorMap[$hex];
                
                // ถ้าไม่ตรง ให้คืนค่าเป็นรหัสสี
                return "สีผสม (รหัส: #$hex)";
            }

            // --- Helper Function: คำนวณการผสมสีแบบถ่วงน้ำหนัก (Weighted Average) ---
            function mixColorsWeighted($hex1, $vol1, $hex2, $vol2) {
                // ถ้าไม่มีสี ให้ใช้สีขาวเป็น Default
                $hex1 = ($hex1 && $hex1 != '') ? ltrim($hex1, '#') : 'FFFFFF';
                $hex2 = ($hex2 && $hex2 != '') ? ltrim($hex2, '#') : 'FFFFFF';
                
                // แปลง Short Hex (เช่น FFF) เป็น Full Hex (FFFFFF)
                if(strlen($hex1) == 3) $hex1 = $hex1[0].$hex1[0].$hex1[1].$hex1[1].$hex1[2].$hex1[2];
                if(strlen($hex2) == 3) $hex2 = $hex2[0].$hex2[0].$hex2[1].$hex2[1].$hex2[2].$hex2[2];

                // แปลงเป็น RGB Decimal
                $r1 = hexdec(substr($hex1,0,2)); $g1 = hexdec(substr($hex1,2,2)); $b1 = hexdec(substr($hex1,4,2));
                $r2 = hexdec(substr($hex2,0,2)); $g2 = hexdec(substr($hex2,2,2)); $b2 = hexdec(substr($hex2,4,2));

                $totalVol = $vol1 + $vol2;
                if ($totalVol <= 0) return "#" . $hex1;

                // คำนวณค่าเฉลี่ยถ่วงน้ำหนักตามปริมาตร
                $r = round(($r1 * $vol1 + $r2 * $vol2) / $totalVol);
                $g = round(($g1 * $vol1 + $g2 * $vol2) / $totalVol);
                $b = round(($b1 * $vol1 + $b2 * $vol2) / $totalVol);

                return sprintf("#%02x%02x%02x", $r, $g, $b);
            }

            // 1. รับค่า Input จาก URL
            $id_a = isset($_GET['a']) ? intval($_GET['a']) : 0;
            $id_b = isset($_GET['b']) ? intval($_GET['b']) : 0;
            $vol_a = isset($_GET['volA']) ? floatval($_GET['volA']) : 0;
            $vol_b = isset($_GET['volB']) ? floatval($_GET['volB']) : 0;

            // ตรวจสอบความถูกต้องของ Input
            if ($id_a <= 0 || $id_b <= 0) {
                throw new Exception("รหัสสารเคมีไม่ถูกต้อง (ID ต้องมากกว่า 0)");
            }

            // 2. ดึงข้อมูลจาก Database
            // ใช้ WHERE id IN (?, ?) เพื่อดึงข้อมูลทีเดียว
            $stmt = $conn->prepare("SELECT id, name, type, color_neutral, toxicity, state FROM chemicals WHERE id IN (?, ?)");
            if (!$stmt) throw new Exception("Prepare Failed: " . $conn->error);
            
            $stmt->bind_param("ii", $id_a, $id_b);
            $stmt->execute();
            $res = $stmt->get_result();
            
            // เก็บผลลัพธ์ลง Array โดยใช้ ID เป็น Key เพื่อให้เรียกใช้ง่าย
            $chemicals = [];
            while ($row = $res->fetch_assoc()) {
                $chemicals[$row['id']] = $row;
            }

            // 3. ตรวจสอบว่าเจอข้อมูลครบไหม (แก้ Bug เดิมที่นับจำนวนแถวแล้วพังเมื่อเลือกสารเดียวกัน)
            // เราเช็คทีละตัวเลยว่า ID A มีไหม และ ID B มีไหม
            // วิธีนี้รองรับกรณี A และ B เป็นตัวเดียวกัน (ID เดียวกัน) ได้แน่นอน
            if (!isset($chemicals[$id_a])) {
                throw new Exception("ไม่พบข้อมูลสารเคมี A (ID: $id_a) อาจถูกลบไปแล้ว");
            }
            if (!isset($chemicals[$id_b])) {
                throw new Exception("ไม่พบข้อมูลสารเคมี B (ID: $id_b) อาจถูกลบไปแล้ว");
            }

            $cA = $chemicals[$id_a];
            $cB = $chemicals[$id_b];

            // 4. คำนวณผลลัพธ์เบื้องต้น (Physical Mixing - การผสมทางกายภาพ)
            $total_volume = $vol_a + $vol_b;
            $final_temp = 25.0; // อุณหภูมิห้องเริ่มต้น
            $result_color = mixColorsWeighted($cA['color_neutral'], $vol_a, $cB['color_neutral'], $vol_b);
            
            // ค่า Default ของผลลัพธ์ (สมมติว่าแค่ผสมกันเฉยๆ)
            $product_name = "สารละลายผสม (" . $cA['name'] . " + " . $cB['name'] . ")";
            $product_formula = "-"; // สูตรเคมีผสม
            $precipitate = "ไม่มีตะกอน";
            $gas_result = "ไม่มีแก๊ส";
            $damage_player = round(($cA['toxicity'] + $cB['toxicity']) / 2); // ความอันตรายเฉลี่ย
            $effect_type = "normal"; // normal, explosion, toxic_gas
            $final_state = "liquid"; // liquid, solid, gas
            $has_bubbles = false;
            $bubble_color = "#FFFFFF";

            // 5. ตรวจสอบปฏิกิริยาเคมี (Chemical Reaction) จากตาราง reactions
            // เช็คทั้งสองทาง: A+B หรือ B+A เพราะปฏิกิริยาสลับที่ได้
            $sql_react = "SELECT * FROM reactions WHERE (chem1_id=? AND chem2_id=?) OR (chem1_id=? AND chem2_id=?) LIMIT 1";
            $stmt2 = $conn->prepare($sql_react);
            $stmt2->bind_param("iiii", $id_a, $id_b, $id_b, $id_a);
            $stmt2->execute();
            $react_res = $stmt2->get_result();
            $react = $react_res->fetch_assoc();

            if ($react) {
                // --- พบปฏิกิริยาเคมี! ใช้ค่าจากตาราง reactions มาแสดง ---
                
                // ชื่อสารผลิตภัณฑ์
                if (!empty($react['product_name'])) $product_name = $react['product_name'];
                
                // สีผลลัพธ์
                if (!empty($react['result_color'])) $result_color = $react['result_color'];
                
                // ตะกอน
                if (!empty($react['result_precipitate']) && $react['result_precipitate'] !== 'ไม่มีตะกอน') {
                    $precipitate = $react['result_precipitate'];
                }
                
                // แก๊ส
                if (!empty($react['result_gas']) && $react['result_gas'] !== 'ไม่มีแก๊ส') {
                    $gas_result = $react['result_gas'];
                    $has_bubbles = true;
                    if (!empty($react['gas_color'])) $bubble_color = $react['gas_color'];
                }

                // ความร้อนและสถานะ
                $final_temp += floatval($react['heat_level']);
                if ($final_temp >= 100) $final_state = 'gas'; // ถ้าร้อนเกิน 100 องศา ให้กลายเป็นไอ
                
                // ความอันตรายเพิ่มเติม
                $damage_player += intval($react['toxicity_bonus']);
                
                // เอฟเฟกต์พิเศษ (ระเบิด)
                if ($react['is_explosive']) {
                    $effect_type = "explosion";
                    $result_color = "#222222"; // สีดำจากการระเบิด/เขม่า
                    $damage_player = 100; // เจ็บหนัก
                    $product_name .= " (ระเบิด!)";
                }
            } else {
                // --- ไม่พบปฏิกิริยา (ตรวจสอบกรณีพิเศษด้วย Hardcode Logic ได้ที่นี่) ---
                
                // ตัวอย่าง: ถ้าผสมสารตัวเดียวกัน (น้ำ+น้ำ) ให้แค่รวมปริมาตร ไม่ใช่สารผสม
                if ($id_a == $id_b) {
                    $product_name = $cA['name'];
                }
            }

            // 6. ส่งผลลัพธ์กลับเป็น JSON
            echo json_encode([
                "success" => true,
                "product_name" => $product_name,
                "product_formula" => $product_formula,
                "color_name_thai" => getThaiColorName($result_color),
                "special_color" => $result_color,
                "liquid_color" => $result_color, // ใช้สำหรับ 3D Engine
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
        // กรณีเกิด Error ให้ส่ง JSON พร้อมข้อความ Error กลับไป
        http_response_code(500);
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
    
    // จบการทำงานส่วน API
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧪 Ultimate Chemistry Lab (Dev Mode)</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&family=Itim&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

    <style>
        /* ========================================= */
        /* CSS STYLESHEET (เต็มรูปแบบ)               */
        /* ========================================= */
        
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --text-main: #334155;
            --text-sub: #64748b;
            --border-color: #e2e8f0;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--bg-color);
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 24px 24px;
            min-height: 100vh;
            color: var(--text-main);
        }

        /* Container หลัก */
        .main-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

        /* Header */
        .lab-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .lab-header h1 {
            font-family: 'Itim', cursive;
            font-size: 2.5rem;
            color: var(--text-main);
            margin: 0;
            text-shadow: 2px 2px 0px #fff;
        }
        .lab-header p {
            color: var(--text-sub);
            margin-top: 5px;
        }
        .back-btn {
            display: inline-block;
            margin-top: 10px;
            padding: 6px 15px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            text-decoration: none;
            color: var(--text-sub);
            font-size: 0.9rem;
            transition: 0.2s;
        }
        .back-btn:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }

        /* Control Panel (ส่วนเลือกสาร) */
        .control-panel {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            position: relative;
            z-index: 10;
        }

        .station-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            position: relative;
        }
        .station-label {
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 15px;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .station-icon {
            font-size: 1.5rem;
        }

        /* Input Controls */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-sub);
            margin-bottom: 5px;
        }
        .input-row {
            display: flex;
            gap: 10px;
        }
        
        /* ปุ่มเปิดตารางธาตุ */
        .btn-periodic {
            background: #475569;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0 12px;
            cursor: pointer;
            transition: 0.2s;
            white-space: nowrap;
            font-size: 0.9rem;
        }
        .btn-periodic:hover {
            background: #334155;
        }

        /* ปุ่ม Mix (อยู่ตรงกลางข้างล่าง) */
        .mix-action-area {
            grid-column: span 2;
            text-align: center;
            margin-top: 10px;
        }
        .btn-mix {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border: none;
            padding: 15px 50px;
            font-size: 1.25rem;
            font-weight: bold;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-mix:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 20px -3px rgba(59, 130, 246, 0.5);
        }
        .btn-mix:active {
            transform: translateY(1px);
        }
        .btn-mix:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* 3D Viewer Area */
        .viewer-container {
            margin-top: 30px;
            background: #fff;
            border-radius: 16px;
            height: 400px;
            border: 2px dashed var(--border-color);
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .viewer-placeholder {
            color: var(--text-sub);
            font-style: italic;
        }
        #viewer3d canvas {
            outline: none;
        }

        /* Result Display Area */
        .result-panel {
            margin-top: 30px;
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-left: 5px solid var(--primary-color);
            display: none; /* ซ่อนไว้ก่อน */
            animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }
        .result-title {
            font-size: 1.4rem;
            font-weight: bold;
            color: var(--text-main);
            margin: 0;
        }
        .result-badges {
            display: flex;
            gap: 5px;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            background: #eee;
        }
        
        .result-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .result-item label {
            font-size: 0.85rem;
            color: var(--text-sub);
            display: block;
        }
        .result-item span {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-main);
        }

        /* Overlays (Effect พิเศษ) */
        #explosion-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8) url('https://upload.wikimedia.org/wikipedia/commons/7/79/Operation_Upshot-Knothole_-_Badger_001.jpg') no-repeat center center;
            background-size: cover;
            opacity: 0; pointer-events: none; z-index: 9999;
            transition: opacity 0.5s;
            mix-blend-mode: hard-light;
        }

        /* Modal ตารางธาตุ */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background-color: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(5px);
        }
        .modal-content {
            background-color: #1e293b;
            color: #f1f5f9;
            margin: 2% auto;
            padding: 25px;
            border: 1px solid #334155;
            width: 95%;
            max-width: 1200px;
            border-radius: 12px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .close-btn {
            color: #94a3b8;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-btn:hover { color: #fff; }

        /* Periodic Table Grid */
        .periodic-grid {
            display: grid;
            grid-template-columns: repeat(18, 1fr);
            gap: 4px;
            padding: 20px 0;
            user-select: none;
        }
        .element-cell {
            aspect-ratio: 1;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #334155;
            position: relative;
        }
        .element-cell:hover {
            transform: scale(1.2);
            z-index: 100;
            border-color: #fff;
            box-shadow: 0 0 15px rgba(255,255,255,0.3);
        }
        .element-symbol { font-size: 1.2vw; font-weight: bold; }
        .element-number { font-size: 0.6vw; position: absolute; top: 2px; left: 4px; opacity: 0.7; }
        .element-name { font-size: 0.5vw; display: none; }
        .empty-cell { background: transparent; border: none; pointer-events: none; }

        /* สีหมวดหมู่ธาตุ */
        .cat-alkali { background: #ef4444; color: white; }
        .cat-alkaline { background: #f97316; color: white; }
        .cat-transition { background: #eab308; color: black; }
        .cat-basic { background: #84cc16; color: black; }
        .cat-semi { background: #06b6d4; color: black; }
        .cat-nonmetal { background: #3b82f6; color: white; }
        .cat-halogen { background: #8b5cf6; color: white; }
        .cat-noble { background: #d946ef; color: white; }

        /* Loading Overlay */
        #loading-overlay {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,255,255,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 50;
            border-radius: 16px;
        }
        .spinner {
            width: 40px; height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

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

    <div id="explosion-overlay"></div>

    <div class="main-container">
        
        <div class="lab-header">
            <h1>⚗️ Ultimate Chemistry Lab</h1>
            <p>ห้องปฏิบัติการเคมีจำลอง (Developer Mode)</p>
            <a href="dashboard_dev.php" class="back-btn">⬅ กลับสู่ Dashboard</a>
        </div>

        <div class="control-panel">
            <div id="loading-overlay"><div class="spinner"></div></div>

            <div class="station-card">
                <div class="station-label">
                    <span class="station-icon">🧪</span> สารตั้งต้น (A)
                </div>
                <div class="form-group">
                    <label>เลือกสารเคมี:</label>
                    <div class="input-row">
                        <select id="chemA" placeholder="พิมพ์ชื่อสารเพื่อค้นหา..."></select>
                        <button class="btn-periodic" onclick="openPeriodicTable('A')">📅 ตารางธาตุ</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>ปริมาตร (ml):</label>
                    <input type="number" id="volA" class="form-control" value="50" min="1" step="1" style="width: 100%; padding: 8px; border-radius: 6px; border:1px solid #e2e8f0;">
                </div>
            </div>

            <div class="station-card">
                <div class="station-label">
                    <span class="station-icon">⚗️</span> ตัวทำปฏิกิริยา (B)
                </div>
                <div class="form-group">
                    <label>เลือกสารเคมี:</label>
                    <div class="input-row">
                        <select id="chemB" placeholder="พิมพ์ชื่อสารเพื่อค้นหา..."></select>
                        <button class="btn-periodic" onclick="openPeriodicTable('B')">📅 ตารางธาตุ</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>ปริมาตร (ml):</label>
                    <input type="number" id="volB" class="form-control" value="50" min="1" step="1" style="width: 100%; padding: 8px; border-radius: 6px; border:1px solid #e2e8f0;">
                </div>
            </div>

            <div class="mix-action-area">
                <button class="btn-mix" id="btn-mix" onclick="startMixing()">🔥 ผสมสารเคมี (Mix)</button>
            </div>
        </div>

        <div class="viewer-container" id="viewer-container">
            <div id="viewer3d" style="width:100%; height:100%;"></div>
        </div>

        <div class="result-panel" id="result-panel">
            <div class="result-header">
                <div>
                    <h3 class="result-title" id="res-name">Sodium Chloride</h3>
                    <div style="font-size: 0.9rem; color:#64748b; margin-top:5px;" id="res-desc">
                        เกิดจาก: Sodium + Chlorine
                    </div>
                </div>
                <div class="result-badges" id="res-badges">
                    </div>
            </div>
            
            <div class="result-grid">
                <div class="result-item">
                    <label>🎨 ลักษณะ/สี</label>
                    <span id="res-color">-</span>
                </div>
                <div class="result-item">
                    <label>💧 สถานะ</label>
                    <span id="res-state">-</span>
                </div>
                <div class="result-item">
                    <label>🧱 ตะกอน</label>
                    <span id="res-precipitate">-</span>
                </div>
                <div class="result-item">
                    <label>☁️ แก๊ส/ฟอง</label>
                    <span id="res-gas">-</span>
                </div>
                <div class="result-item">
                    <label>🌡️ อุณหภูมิ</label>
                    <span id="res-temp">-</span>
                </div>
                <div class="result-item">
                    <label>☠️ ความอันตราย</label>
                    <span id="res-toxic">-</span>
                </div>
            </div>
        </div>

    </div>

    <div id="periodicModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closePeriodicTable()">&times;</span>
            <h2 style="text-align:center; margin-bottom:10px;">ตารางธาตุ (Periodic Table)</h2>
            <p style="text-align:center; color:#94a3b8; font-size:0.9rem;">คลิกที่ธาตุเพื่อเลือก (ธาตุต้องมีในฐานข้อมูลจึงจะเลือกได้)</p>
            <div id="periodic-grid-container" class="periodic-grid">
                </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

    <script type="module">
        import { init3DScene, updateLiquidVisuals } from './js/3d_engine.js';

        // ตัวแปร Global
        let tomA, tomB;
        let currentTargetInput = null;

        // เมื่อโหลดหน้าเว็บเสร็จ
        document.addEventListener('DOMContentLoaded', () => {
            // 1. เริ่มต้น 3D Scene
            init3D();
            
            // 2. โหลดข้อมูลสารเคมีและสร้าง Dropdown
            initChemicals();

            // 3. สร้างตารางธาตุ
            renderPeriodicTable();
        });

        // ฟังก์ชันเริ่ม 3D (เรียกไฟล์ 3d_engine.js ที่คุณอัปโหลดมา)
        function init3D() {
            const container = document.getElementById('viewer3d');
            try {
                init3DScene(container);
            } catch (e) {
                console.error("3D Engine Init Failed:", e);
                container.innerHTML = "<p style='text-align:center; padding-top:180px;'>⚠️ ไม่สามารถโหลด 3D Engine ได้ (ไฟล์ js/3d_engine.js อาจหายไป)</p>";
            }
        }

        // ฟังก์ชันโหลดรายชื่อสารเคมี
        async function initChemicals() {
            try {
                // เรียก API get_chemicals จากไฟล์ตัวเอง
                const response = await fetch('dev_lab.php?action=get_chemicals');
                const data = await response.json();

                if (data.error) throw new Error(data.error);

                // ตั้งค่า TomSelect (Dropdown สวยๆ)
                const config = {
                    valueField: 'value',
                    labelField: 'text',
                    searchField: 'text',
                    options: data,
                    maxOptions: 200,
                    placeholder: 'พิมพ์เพื่อค้นหา...',
                    render: {
                        option: function(data, escape) {
                            return '<div style="padding: 5px;">' + escape(data.text) + '</div>';
                        },
                        no_results: function(data, escape) {
                            return '<div class="no-results" style="padding: 10px;">ไม่พบข้อมูล</div>';
                        }
                    }
                };

                tomA = new TomSelect('#chemA', config);
                tomB = new TomSelect('#chemB', config);

            } catch (err) {
                alert("เกิดข้อผิดพลาดในการโหลดสารเคมี: " + err.message);
                console.error(err);
            }
        }

        // ฟังก์ชันเริ่มผสมสาร (Main Action)
        window.startMixing = async function() {
            const idA = tomA.getValue();
            const idB = tomB.getValue();
            const volA = document.getElementById('volA').value;
            const volB = document.getElementById('volB').value;

            if (!idA || !idB) {
                alert("⚠️ กรุณาเลือกสารเคมีให้ครบทั้ง 2 ชนิด");
                return;
            }

            // แสดง Loading
            const btn = document.getElementById('btn-mix');
            const overlay = document.getElementById('loading-overlay');
            btn.disabled = true;
            btn.innerHTML = "⏳ กำลังประมวลผล...";
            overlay.style.display = 'flex';

            try {
                // เรียก API mix
                const url = `dev_lab.php?action=mix&a=${idA}&b=${idB}&volA=${volA}&volB=${volB}`;
                const response = await fetch(url);
                const data = await response.json();

                if (!data.success) throw new Error(data.error);

                // --- อัปเดต UI เมื่อสำเร็จ ---
                showResult(data);
                
                // --- อัปเดต 3D ---
                updateLiquidVisuals(data);

                // --- Effect พิเศษ ---
                if (data.effect_type === 'explosion') {
                    triggerExplosion();
                }

            } catch (err) {
                alert("❌ การผสมล้มเหลว: " + err.message);
                console.error(err);
            } finally {
                // ปิด Loading
                btn.disabled = false;
                btn.innerHTML = "🔥 ผสมสารเคมี (Mix)";
                overlay.style.display = 'none';
            }
        };

        // ฟังก์ชันแสดงผลลัพธ์
        function showResult(data) {
            const panel = document.getElementById('result-panel');
            panel.style.display = 'block';

            // ข้อความ
            document.getElementById('res-name').innerText = data.product_name;
            document.getElementById('res-desc').innerText = `ปริมาตรรวม: ${data.total_volume} ml`;
            
            // สี
            const colorBox = `<span style="display:inline-block; width:15px; height:15px; border-radius:50%; background:${data.special_color}; border:1px solid #999; margin-right:5px; vertical-align:middle;"></span>`;
            document.getElementById('res-color').innerHTML = colorBox + " " + data.color_name_thai;

            // ค่าอื่นๆ
            document.getElementById('res-state').innerText = translateState(data.final_state);
            document.getElementById('res-precipitate').innerText = data.precipitate;
            document.getElementById('res-gas').innerText = data.gas;
            document.getElementById('res-temp').innerText = data.temperature + " °C";
            document.getElementById('res-toxic').innerText = data.damage_player + " / 100";

            // ปรับสีตัวอักษรความอันตราย
            const toxicEl = document.getElementById('res-toxic');
            if (data.damage_player > 50) toxicEl.style.color = 'red';
            else toxicEl.style.color = '#334155';

            // Badges
            const badges = document.getElementById('res-badges');
            badges.innerHTML = '';
            if (data.effect_type === 'explosion') badges.innerHTML += `<span class="badge" style="background:#fee2e2; color:#991b1b;">💥 ระเบิด</span>`;
            if (data.has_bubbles) badges.innerHTML += `<span class="badge" style="background:#e0f2fe; color:#075985;">🫧 มีฟองแก๊ส</span>`;
            if (data.precipitate !== 'ไม่มีตะกอน') badges.innerHTML += `<span class="badge" style="background:#f1f5f9; color:#475569;">🧱 มีตะกอน</span>`;
        }

        // แปลงสถานะเป็นภาษาไทย
        function translateState(state) {
            if (state === 'solid') return 'ของแข็ง';
            if (state === 'liquid') return 'ของเหลว';
            if (state === 'gas') return 'แก๊ส/ไอ';
            return state;
        }

        // Effect ระเบิด
        function triggerExplosion() {
            const overlay = document.getElementById('explosion-overlay');
            overlay.style.opacity = 1;
            setTimeout(() => {
                alert("💥 ตู้มมมม!!! การทดลองผิดพลาดอย่างรุนแรง!");
                overlay.style.opacity = 0;
            }, 500);
        }

        // --- Periodic Table Logic ---
        
        // ข้อมูลตารางธาตุแบบย่อ (เลขอะตอม, สัญลักษณ์, ชื่อ)
        const periodicData = [
            1,'H','Hydrogen','nonmetal', 2,'He','Helium','noble',
            3,'Li','Lithium','alkali', 4,'Be','Beryllium','alkaline', 5,'B','Boron','semi', 6,'C','Carbon','nonmetal', 7,'N','Nitrogen','nonmetal', 8,'O','Oxygen','nonmetal', 9,'F','Fluorine','halogen', 10,'Ne','Neon','noble',
            11,'Na','Sodium','alkali', 12,'Mg','Magnesium','alkaline', 13,'Al','Aluminum','basic', 14,'Si','Silicon','semi', 15,'P','Phosphorus','nonmetal', 16,'S','Sulfur','nonmetal', 17,'Cl','Chlorine','halogen', 18,'Ar','Argon','noble',
            19,'K','Potassium','alkali', 20,'Ca','Calcium','alkaline', 26,'Fe','Iron','transition', 29,'Cu','Copper','transition', 30,'Zn','Zinc','transition', 47,'Ag','Silver','transition', 79,'Au','Gold','transition'
        ];
        // หมายเหตุ: นี่เป็นเพียงข้อมูลบางส่วนสำหรับการสาธิต หากต้องการครบ 118 ธาตุ สามารถเพิ่ม Array ได้

        // ฟังก์ชันสร้าง Grid ตารางธาตุ
        window.renderPeriodicTable = function() {
            const container = document.getElementById('periodic-grid-container');
            container.innerHTML = '';

            // สร้างตารางเปล่า 7 แถว 18 คอลัมน์ (แบบง่าย)
            // เราจะ map ข้อมูลลงไปตามเลขอะตอม (Logic อย่างง่ายสำหรับการสาธิต)
            // เพื่อความสมจริง ควรใช้ Grid Layout ที่ Map ตำแหน่งเป๊ะๆ แต่เพื่อความกระชับของโค้ดในส่วน UI จะใช้ Flex Wrap หรือ Grid แบบเรียง
            
            // ใช้ Mapping แบบ Manual เพื่อความสวยงาม (เฉพาะบางธาตุ)
            const layout = [
                {n:1,r:1,c:1}, {n:2,r:1,c:18},
                {n:3,r:2,c:1}, {n:4,r:2,c:2}, {n:5,r:2,c:13}, {n:6,r:2,c:14}, {n:7,r:2,c:15}, {n:8,r:2,c:16}, {n:9,r:2,c:17}, {n:10,r:2,c:18},
                {n:11,r:3,c:1}, {n:12,r:3,c:2}, {n:13,r:3,c:13}, {n:14,r:3,c:14}, {n:15,r:3,c:15}, {n:16,r:3,c:16}, {n:17,r:3,c:17}, {n:18,r:3,c:18},
                {n:19,r:4,c:1}, {n:20,r:4,c:2}, {n:26,r:4,c:8}, {n:29,r:4,c:11}, {n:30,r:4,c:12}, {n:47,r:5,c:11}, {n:79,r:6,c:11}
            ];

            // สร้าง Grid เปล่า
            for(let r=1; r<=7; r++) {
                for(let c=1; c<=18; c++) {
                    const cell = document.createElement('div');
                    cell.style.gridRow = r;
                    cell.style.gridColumn = c;
                    
                    // หาว่าช่องนี้มีธาตุไหม
                    const atom = layout.find(l => l.r === r && l.c === c);
                    if(atom) {
                        // หาข้อมูลธาตุ
                        const idx = periodicData.indexOf(atom.n);
                        if(idx !== -1) {
                            const sym = periodicData[idx+1];
                            const name = periodicData[idx+2];
                            const cat = periodicData[idx+3];
                            
                            cell.className = `element-cell cat-${cat}`;
                            cell.innerHTML = `
                                <span class="element-number">${atom.n}</span>
                                <span class="element-symbol">${sym}</span>
                            `;
                            cell.title = name;
                            cell.onclick = () => selectElement(name);
                        }
                    } else {
                        cell.className = 'empty-cell';
                    }
                    container.appendChild(cell);
                }
            }
        };

        window.openPeriodicTable = function(target) {
            currentTargetInput = target;
            document.getElementById('periodicModal').style.display = 'block';
        };

        window.closePeriodicTable = function() {
            document.getElementById('periodicModal').style.display = 'none';
        };

        function selectElement(name) {
            // เลือก Dropdown เป้าหมาย
            const tom = (currentTargetInput === 'A') ? tomA : tomB;
            
            // ค้นหา ID จากชื่อธาตุ
            let found = false;
            for (const [id, opt] of Object.entries(tom.options)) {
                if (opt.text.toLowerCase().includes(name.toLowerCase())) {
                    tom.setValue(id);
                    found = true;
                    break;
                }
            }
            
            if (!found) {
                alert(`⚠️ ไม่พบข้อมูลธาตุ "${name}" ในคลังสารเคมี (คุณต้องเพิ่มใน Database ก่อน)`);
            } else {
                closePeriodicTable();
            }
        }
        
    </script>
</body>
</html>