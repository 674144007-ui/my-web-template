<?php
// ===================================================================================
// FILE: mix.php (FULL VERSION)
// ===================================================================================
// รวมระบบคำนวณ (API) และหน้าจอผู้ใช้ (UI) ไว้ในไฟล์เดียว
// รองรับการทำงานเหมือน dev_lab.php
// ===================================================================================

session_start();
require_once 'db.php';
require_once 'auth.php';

// อนุญาตให้ Student, Teacher, Developer เข้าใช้งานได้
if (function_exists('requireRole')) {
    requireRole(['student', 'teacher', 'developer']);
}

// ===================================================================================
// [PART 1] BACKEND API LOGIC
// ===================================================================================

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    error_reporting(E_ALL);

    try {
        // --- API: get_chemicals (ดึงรายชื่อสารเคมี) ---
        if ($_GET['action'] === 'get_chemicals') {
            if ($conn->connect_error) throw new Exception("DB Error: " . $conn->connect_error);

            $sql = "SELECT id, name, type FROM chemicals ORDER BY type, name";
            $result = $conn->query($sql);
            
            if (!$result) throw new Exception("Query Error: " . $conn->error);
            
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = [
                    'value' => $row['id'],
                    'text' => htmlspecialchars($row['name']) . " (" . ucfirst($row['type']) . ")"
                ];
            }
            echo json_encode($data);
            exit;
        }

        // --- API: mix (คำนวณการผสมสาร) ---
        if ($_GET['action'] === 'mix') {
            
            // Helper: แปลงสี Hex เป็นชื่อไทย
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

            // Helper: คำนวณสีผสม
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

            // รับค่า Input
            $id_a = isset($_GET['a']) ? intval($_GET['a']) : 0;
            $id_b = isset($_GET['b']) ? intval($_GET['b']) : 0;
            $vol_a = isset($_GET['volA']) ? floatval($_GET['volA']) : 0;
            $vol_b = isset($_GET['volB']) ? floatval($_GET['volB']) : 0;

            if ($id_a <= 0 || $id_b <= 0) throw new Exception("กรุณาเลือกสารเคมีให้ถูกต้อง");

            // ดึงข้อมูล
            $stmt = $conn->prepare("SELECT id, name, type, color_neutral, toxicity, state FROM chemicals WHERE id IN (?, ?)");
            $stmt->bind_param("ii", $id_a, $id_b);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $chemicals = [];
            while ($row = $res->fetch_assoc()) {
                $chemicals[$row['id']] = $row;
            }

            // ตรวจสอบข้อมูล (รองรับกรณี ID ซ้ำ)
            if (!isset($chemicals[$id_a])) throw new Exception("ไม่พบข้อมูลสาร A (ID: $id_a)");
            if (!isset($chemicals[$id_b])) throw new Exception("ไม่พบข้อมูลสาร B (ID: $id_b)");

            $cA = $chemicals[$id_a];
            $cB = $chemicals[$id_b];

            // คำนวณเบื้องต้น
            $total_volume = $vol_a + $vol_b;
            $final_temp = 25.0;
            $result_color = mixColorsWeighted($cA['color_neutral'], $vol_a, $cB['color_neutral'], $vol_b);
            
            // ค่า Default
            $product_name = "สารละลายผสม (" . $cA['name'] . " + " . $cB['name'] . ")";
            $product_formula = "-";
            $precipitate = "ไม่มีตะกอน";
            $gas_result = "ไม่มีแก๊ส";
            $damage_player = round(($cA['toxicity'] + $cB['toxicity']) / 2);
            $effect_type = "normal";
            $final_state = "liquid";
            $has_bubbles = false;
            $bubble_color = "#FFFFFF";

            // ตรวจสอบ Reaction
            $sql_react = "SELECT * FROM reactions WHERE (chem1_id=? AND chem2_id=?) OR (chem1_id=? AND chem2_id=?) LIMIT 1";
            $stmt2 = $conn->prepare($sql_react);
            $stmt2->bind_param("iiii", $id_a, $id_b, $id_b, $id_a);
            $stmt2->execute();
            $react = $stmt2->get_result()->fetch_assoc();

            if ($react) {
                if (!empty($react['product_name'])) $product_name = $react['product_name'];
                if (!empty($react['result_color'])) $result_color = $react['result_color'];
                if (!empty($react['result_precipitate']) && $react['result_precipitate'] !== 'ไม่มีตะกอน') $precipitate = $react['result_precipitate'];
                
                if (!empty($react['result_gas']) && $react['result_gas'] !== 'ไม่มีแก๊ส') {
                    $gas_result = $react['result_gas'];
                    $has_bubbles = true;
                    if (!empty($react['gas_color'])) $bubble_color = $react['gas_color'];
                }

                $final_temp += floatval($react['heat_level']);
                if ($final_temp >= 100) $final_state = 'gas';
                
                $damage_player += intval($react['toxicity_bonus']);
                
                if ($react['is_explosive']) {
                    $effect_type = "explosion";
                    $result_color = "#222222";
                    $damage_player = 100;
                    $product_name .= " (ระเบิด!)";
                }
            } else {
                // กรณีผสมตัวเดียวกัน
                if ($id_a == $id_b) {
                    $product_name = $cA['name'];
                }
            }

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
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🧪 ห้องปฏิบัติการเคมี (Lab)</title>

    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&family=Itim&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

    <style>
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --text-main: #334155;
            --border-color: #e2e8f0;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            margin: 0; padding: 0;
            background-color: var(--bg-color);
            background-image: radial-gradient(#cbd5e1 1px, transparent 1px);
            background-size: 24px 24px;
            min-height: 100vh;
            color: var(--text-main);
        }

        .main-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }

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
        .back-btn {
            display: inline-block;
            margin-top: 10px;
            padding: 6px 15px;
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            text-decoration: none;
            color: #64748b;
            font-size: 0.9rem;
            transition: 0.2s;
        }
        .back-btn:hover { background: #f1f5f9; color: var(--text-main); }

        .control-panel {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
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
        }
        .station-label {
            font-weight: bold; font-size: 1.1rem; margin-bottom: 15px;
            display: flex; align-items: center; gap: 8px;
        }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.85rem; color: #64748b; margin-bottom: 5px; }
        .input-row { display: flex; gap: 10px; }
        
        .btn-periodic {
            background: #475569; color: white; border: none; border-radius: 6px;
            padding: 0 12px; cursor: pointer; white-space: nowrap; font-size: 0.9rem;
        }

        .mix-action-area {
            grid-column: span 2; text-align: center; margin-top: 10px;
        }
        .btn-mix {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white; border: none; padding: 15px 50px;
            font-size: 1.25rem; font-weight: bold; border-radius: 50px;
            cursor: pointer; box-shadow: 0 10px 15px -3px rgba(59,130,246,0.4);
            transition: transform 0.2s;
        }
        .btn-mix:hover { transform: translateY(-2px); }
        .btn-mix:disabled { background: #cbd5e1; cursor: not-allowed; transform: none; }

        .viewer-container {
            margin-top: 30px; background: #fff; border-radius: 16px; height: 400px;
            border: 2px dashed var(--border-color); overflow: hidden;
        }

        .result-panel {
            margin-top: 30px; background: var(--card-bg); border-radius: 16px;
            padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border-left: 5px solid var(--primary-color);
            display: none; animation: slideUp 0.5s ease-out;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        
        .result-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 20px; border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }
        .result-title { font-size: 1.4rem; font-weight: bold; margin: 0; }
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: bold; background: #eee; margin-left:5px; }
        
        .result-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;
        }
        .result-item label { font-size: 0.85rem; color: #64748b; display: block; }
        .result-item span { font-size: 1.1rem; font-weight: 600; color: var(--text-main); }

        /* Modal */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.9);
        }
        .modal-content {
            background-color: #1e293b; color: #f1f5f9; margin: 2% auto; padding: 25px;
            width: 95%; max-width: 1200px; border-radius: 12px; height: 90vh; overflow-y: auto;
        }
        .periodic-grid {
            display: grid; grid-template-columns: repeat(18, 1fr); gap: 4px; padding: 20px 0;
        }
        .element-cell {
            aspect-ratio: 1; border: 1px solid rgba(255,255,255,0.1); border-radius: 4px;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            cursor: pointer; background: #334155; position: relative;
        }
        .element-cell:hover { transform: scale(1.2); z-index: 100; border-color: #fff; background: #475569; }
        .element-symbol { font-size: 1.2vw; font-weight: bold; }
        .element-number { font-size: 0.6vw; position: absolute; top: 2px; left: 4px; opacity: 0.7; }
        .empty-cell { pointer-events: none; background: transparent; border: none; }
        
        .cat-alkali { background: #ef4444; color: white; }
        .cat-alkaline { background: #f97316; color: white; }
        .cat-transition { background: #eab308; color: black; }
        .cat-basic { background: #84cc16; color: black; }
        .cat-semi { background: #06b6d4; color: black; }
        .cat-nonmetal { background: #3b82f6; color: white; }
        .cat-halogen { background: #8b5cf6; color: white; }
        .cat-noble { background: #d946ef; color: white; }

        #explosion-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.8); opacity: 0; pointer-events: none; z-index: 9999;
            transition: opacity 0.5s;
        }
        #loading-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,255,255,0.8); display: none;
            justify-content: center; align-items: center; z-index: 50; border-radius: 16px;
        }
        .spinner {
            width: 40px; height: 40px; border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color); border-radius: 50%;
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
            <h1>⚗️ ห้องปฏิบัติการเคมี (Lab)</h1>
            <p>ผสมสารเคมีเพื่อการเรียนรู้</p>
            <a href="dashboard_student.php" class="back-btn">⬅ กลับสู่ Dashboard</a>
        </div>

        <div class="control-panel">
            <div id="loading-overlay"><div class="spinner"></div></div>

            <div class="station-card">
                <div class="station-label"><span style="font-size:1.5rem;">🧪</span> สารตั้งต้น (A)</div>
                <div class="form-group">
                    <label>เลือกสารเคมี:</label>
                    <div class="input-row">
                        <select id="chemA" placeholder="ค้นหา..."></select>
                        <button class="btn-periodic" onclick="openPeriodicTable('A')">📅 ตารางธาตุ</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>ปริมาตร (ml):</label>
                    <input type="number" id="volA" class="form-control" value="50" min="1" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <div class="station-card">
                <div class="station-label"><span style="font-size:1.5rem;">⚗️</span> ตัวทำปฏิกิริยา (B)</div>
                <div class="form-group">
                    <label>เลือกสารเคมี:</label>
                    <div class="input-row">
                        <select id="chemB" placeholder="ค้นหา..."></select>
                        <button class="btn-periodic" onclick="openPeriodicTable('B')">📅 ตารางธาตุ</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>ปริมาตร (ml):</label>
                    <input type="number" id="volB" class="form-control" value="50" min="1" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                </div>
            </div>

            <div class="mix-action-area">
                <button class="btn-mix" id="btn-mix" onclick="startMixing()">🔥 ผสมสารเคมี</button>
            </div>
        </div>

        <div class="viewer-container">
            <div id="viewer3d" style="width:100%; height:100%;"></div>
        </div>

        <div class="result-panel" id="result-panel">
            <div class="result-header">
                <div>
                    <h3 class="result-title" id="res-name">-</h3>
                    <div style="font-size:0.9rem; color:#64748b; margin-top:5px;" id="res-desc">-</div>
                </div>
                <div class="result-badges" id="res-badges"></div>
            </div>
            
            <div class="result-grid">
                <div class="result-item"><label>🎨 สี</label><span id="res-color">-</span></div>
                <div class="result-item"><label>💧 สถานะ</label><span id="res-state">-</span></div>
                <div class="result-item"><label>🧱 ตะกอน</label><span id="res-precipitate">-</span></div>
                <div class="result-item"><label>☁️ แก๊ส</label><span id="res-gas">-</span></div>
                <div class="result-item"><label>🌡️ อุณหภูมิ</label><span id="res-temp">-</span></div>
                <div class="result-item"><label>☠️ อันตราย</label><span id="res-toxic">-</span></div>
            </div>
        </div>

    </div>

    <div id="periodicModal" class="modal">
        <div class="modal-content">
            <span onclick="closePeriodicTable()" style="float:right; cursor:pointer; font-size:24px;">&times;</span>
            <h2 style="text-align:center;">ตารางธาตุ</h2>
            <div id="periodic-grid-container" class="periodic-grid"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

    <script type="module">
        import { init3DScene, updateLiquidVisuals } from './js/3d_engine.js';

        let tomA, tomB;
        let currentTargetInput = null;

        document.addEventListener('DOMContentLoaded', () => {
            init3D();
            initChemicals();
            renderPeriodicTable();
        });

        function init3D() {
            const container = document.getElementById('viewer3d');
            try { init3DScene(container); } 
            catch (e) { console.error("3D Error", e); container.innerHTML = "<p style='text-align:center; padding-top:180px;'>Error Loading 3D Engine</p>"; }
        }

        async function initChemicals() {
            try {
                // เรียก API จากไฟล์ตัวเอง (mix.php)
                const response = await fetch('mix.php?action=get_chemicals');
                const data = await response.json();
                if (data.error) throw new Error(data.error);

                const config = {
                    valueField: 'value', labelField: 'text', searchField: 'text',
                    options: data, maxOptions: 200, placeholder: 'ค้นหา...',
                    render: { option: (d,e) => `<div style="padding:5px;">${e(d.text)}</div>` }
                };
                tomA = new TomSelect('#chemA', config);
                tomB = new TomSelect('#chemB', config);
            } catch (err) {
                alert("โหลดข้อมูลล้มเหลว: " + err.message);
            }
        }

        window.startMixing = async function() {
            const idA = tomA.getValue();
            const idB = tomB.getValue();
            const volA = document.getElementById('volA').value;
            const volB = document.getElementById('volB').value;

            if (!idA || !idB) { alert("กรุณาเลือกสารเคมีให้ครบ"); return; }

            const btn = document.getElementById('btn-mix');
            const overlay = document.getElementById('loading-overlay');
            btn.disabled = true; btn.innerHTML = "⏳ กำลังประมวลผล...";
            overlay.style.display = 'flex';

            try {
                // เรียก API mix จากไฟล์ตัวเอง (mix.php)
                const url = `mix.php?action=mix&a=${idA}&b=${idB}&volA=${volA}&volB=${volB}`;
                const response = await fetch(url);
                const data = await response.json();

                if (!data.success) throw new Error(data.error);

                showResult(data);
                updateLiquidVisuals(data);

                if (data.effect_type === 'explosion') {
                    const boom = document.getElementById('explosion-overlay');
                    boom.style.opacity = 1;
                    setTimeout(() => { alert("💥 ระเบิด!!"); boom.style.opacity = 0; }, 500);
                }
            } catch (err) {
                alert("เกิดข้อผิดพลาด: " + err.message);
            } finally {
                btn.disabled = false; btn.innerHTML = "🔥 ผสมสารเคมี";
                overlay.style.display = 'none';
            }
        };

        function showResult(data) {
            document.getElementById('result-panel').style.display = 'block';
            document.getElementById('res-name').innerText = data.product_name;
            document.getElementById('res-desc').innerText = `ปริมาตรรวม: ${data.total_volume} ml`;
            
            const colorHtml = `<span style="display:inline-block; width:15px; height:15px; border-radius:50%; background:${data.special_color}; border:1px solid #999; margin-right:5px;"></span> ${data.color_name_thai}`;
            document.getElementById('res-color').innerHTML = colorHtml;

            document.getElementById('res-state').innerText = data.final_state;
            document.getElementById('res-precipitate').innerText = data.precipitate;
            document.getElementById('res-gas').innerText = data.gas;
            document.getElementById('res-temp').innerText = data.temperature + " °C";
            
            const toxicEl = document.getElementById('res-toxic');
            toxicEl.innerText = data.damage_player + " / 100";
            toxicEl.style.color = data.damage_player > 50 ? 'red' : 'inherit';

            const badges = document.getElementById('res-badges');
            badges.innerHTML = '';
            if(data.effect_type === 'explosion') badges.innerHTML += `<span class="badge" style="background:#fee2e2; color:#991b1b;">💥 ระเบิด</span>`;
            if(data.has_bubbles) badges.innerHTML += `<span class="badge" style="background:#e0f2fe; color:#075985;">🫧 มีฟอง</span>`;
            if(data.precipitate !== 'ไม่มีตะกอน') badges.innerHTML += `<span class="badge" style="background:#f1f5f9; color:#475569;">🧱 มีตะกอน</span>`;
        }

        // Periodic Table Logic (Simplified for UI)
        const periodicData = [
            1,'H','Hydrogen','nonmetal', 2,'He','Helium','noble',
            3,'Li','Lithium','alkali', 4,'Be','Beryllium','alkaline', 5,'B','Boron','semi', 6,'C','Carbon','nonmetal', 7,'N','Nitrogen','nonmetal', 8,'O','Oxygen','nonmetal', 9,'F','Fluorine','halogen', 10,'Ne','Neon','noble',
            11,'Na','Sodium','alkali', 12,'Mg','Magnesium','alkaline', 13,'Al','Aluminum','basic', 14,'Si','Silicon','semi', 15,'P','Phosphorus','nonmetal', 16,'S','Sulfur','nonmetal', 17,'Cl','Chlorine','halogen', 18,'Ar','Argon','noble',
            19,'K','Potassium','alkali', 20,'Ca','Calcium','alkaline', 26,'Fe','Iron','transition', 29,'Cu','Copper','transition', 30,'Zn','Zinc','transition', 47,'Ag','Silver','transition', 79,'Au','Gold','transition'
        ];
        
        window.renderPeriodicTable = function() {
            const container = document.getElementById('periodic-grid-container');
            container.innerHTML = '';
            const layout = [
                {n:1,r:1,c:1}, {n:2,r:1,c:18},
                {n:3,r:2,c:1}, {n:4,r:2,c:2}, {n:5,r:2,c:13}, {n:6,r:2,c:14}, {n:7,r:2,c:15}, {n:8,r:2,c:16}, {n:9,r:2,c:17}, {n:10,r:2,c:18},
                {n:11,r:3,c:1}, {n:12,r:3,c:2}, {n:13,r:3,c:13}, {n:14,r:3,c:14}, {n:15,r:3,c:15}, {n:16,r:3,c:16}, {n:17,r:3,c:17}, {n:18,r:3,c:18},
                {n:19,r:4,c:1}, {n:20,r:4,c:2}, {n:26,r:4,c:8}, {n:29,r:4,c:11}, {n:30,r:4,c:12}, {n:47,r:5,c:11}, {n:79,r:6,c:11}
            ];

            for(let r=1; r<=7; r++) {
                for(let c=1; c<=18; c++) {
                    const cell = document.createElement('div');
                    cell.style.gridRow = r; cell.style.gridColumn = c;
                    const atom = layout.find(l => l.r===r && l.c===c);
                    if(atom) {
                        const idx = periodicData.indexOf(atom.n);
                        if(idx !== -1) {
                            const sym = periodicData[idx+1]; const name = periodicData[idx+2]; const cat = periodicData[idx+3];
                            cell.className = `element-cell cat-${cat}`;
                            cell.innerHTML = `<span class="element-number">${atom.n}</span><span class="element-symbol">${sym}</span>`;
                            cell.title = name;
                            cell.onclick = () => selectElement(name);
                        }
                    } else { cell.className = 'empty-cell'; }
                    container.appendChild(cell);
                }
            }
        };

        window.openPeriodicTable = (t) => { currentTargetInput = t; document.getElementById('periodicModal').style.display = 'block'; };
        window.closePeriodicTable = () => { document.getElementById('periodicModal').style.display = 'none'; };

        function selectElement(name) {
            const tom = (currentTargetInput === 'A') ? tomA : tomB;
            for (const [id, opt] of Object.entries(tom.options)) {
                if (opt.text.toLowerCase().includes(name.toLowerCase())) {
                    tom.setValue(id);
                    closePeriodicTable();
                    return;
                }
            }
            alert(`⚠️ ไม่พบข้อมูลธาตุ "${name}" ใน Database`);
        }
    </script>
</body>
</html>