<?php
// dev_lab.php
// ตรวจสอบสิทธิ์ผู้ใช้ อนุญาตให้ Developer, Student และ Teacher เข้าใช้งานได้
require_once 'auth.php';
require_once 'db.php'; // ดึงการเชื่อมต่อฐานข้อมูล
requireRole(['developer', 'student', 'teacher', 'admin', 'parent']); 

// ==========================================
// ส่วนของการดึงข้อมูลเควสต์ (ภารกิจ) สำหรับผู้ใช้คนนี้
// ==========================================
$user = currentUser();
$class_level = $user['class_level'] ?? '';
$user_id = $user['id'] ?? 0;

$quests = [];
if (!empty($class_level)) {
    // ดึงข้อมูลเควสต์ที่มอบหมายให้ห้องเรียนนี้
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
            
            // เช็คสถานะความคืบหน้าของนักเรียนคนนี้กับเควสต์นั้นๆ
            $stmt_prog = $conn->prepare("SELECT status FROM student_quest_progress WHERE student_id = ? AND quest_id = ?");
            if ($stmt_prog) {
                $stmt_prog->bind_param("ii", $user_id, $q_id);
                $stmt_prog->execute();
                $prog_res = $stmt_prog->get_result();
                
                if ($prog_res->num_rows > 0) {
                    $prog_row = $prog_res->fetch_assoc();
                    $row['status'] = $prog_row['status'];
                } else {
                    $row['status'] = 'pending';
                }
                $stmt_prog->close();
            }
            $quests[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ultimate Chemistry Lab Survival (Dev Mode + Periodic Table)</title>

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
            background-color: #f0f4f8; 
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
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative; 
            z-index: 10;
            backdrop-filter: blur(5px);
            margin-bottom: 50px;
        }

        h2 { 
            margin-top: 0; 
            margin-bottom: 10px; 
            color: #333; 
            text-align: center; 
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
            transition: transform 0.2s;
            border: 2px solid rgba(255,255,255,0.5);
        }
        .btn-back:hover { 
            transform: scale(1.05); 
            background: #dc2626; 
            color:white; 
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
            background: #64748b;
            color: white; border: none; border-radius: 8px;
            padding: 0 10px; cursor: pointer; font-size: 14px;
            white-space: nowrap; transition: 0.2s;
            display: flex; align-items: center;
        }
        .btn-periodic-trigger:hover { background: #475569; }
        
        select, input, button {
            font-family: 'Itim', cursive; 
            width: 100%; 
            padding: 12px;
            border: 2px solid #ddd; 
            border-radius: 8px; 
            font-size: 16px; 
            box-sizing: border-box;
        }

        button#mix-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            border: none; 
            cursor: pointer; 
            font-size: 18px; 
            transition: transform 0.2s;
            box-shadow: 0 4px 10px rgba(118, 75, 162, 0.3);
            padding: 15px;
        }
        button#mix-button:hover { transform: scale(1.02); }
        button#mix-button:active { transform: scale(0.98); }
        button:disabled { opacity: 0.7; cursor: not-allowed; }

        #viewer3d {
            height: 400px; 
            width: 100%;
            background: radial-gradient(circle, #ffffff 0%, #e6e9f0 100%);
            border-radius: 12px; 
            border: 2px dashed #ccc;
            position: relative; 
            overflow: hidden; 
            margin-top: 20px;
        }

        #result-box {
            margin-top: 20px; 
            padding: 15px;
            background: #f8f9fa; 
            border-radius: 8px; 
            border-left: 5px solid #764ba2;
            font-size: 16px; 
            line-height: 1.6;
            display: none;
        }
        .res-row { 
            display: flex; 
            justify-content: space-between; 
            border-bottom: 1px dashed #ddd; 
            padding: 5px 0; 
        }
        .res-val { 
            font-weight: bold; 
            color: #667eea; 
        }

        /* --- CSS แถบสถานะ --- */
        .status-panel {
            position: fixed; top: 20px; right: 20px; width: 260px;
            background: rgba(30, 30, 30, 0.9); padding: 15px; border-radius: 12px;
            color: white; z-index: 1000; box-shadow: 0 5px 15px rgba(0,0,0,0.5); backdrop-filter: blur(5px);
        }
        .bar-row { margin-bottom: 12px; }
        .bar-label { font-size: 14px; margin-bottom: 4px; display: flex; justify-content: space-between;}
        .progress-track { width: 100%; height: 12px; background: #444; border-radius: 6px; overflow: hidden; border: 1px solid #555; }
        .progress-fill { height: 100%; width: 100%; transition: width 0.5s; }
        #beaker-bar { background: #00d2ff; box-shadow: 0 0 10px #00d2ff; }
        #health-bar { background: #00ff44; box-shadow: 0 0 10px #00ff44; }
        button.reset-btn {
            background: #ff4757; color: white; border: none; margin-top: 5px; font-size: 14px; padding: 8px; width: 100%; cursor: pointer; border-radius: 5px;
        }

        /* --- CSS กระดานภารกิจ (Quest Board) --- */
        .quest-panel {
            position: fixed; top: 20px; left: 20px; width: 280px;
            background: rgba(30, 30, 30, 0.9); padding: 15px; border-radius: 12px;
            color: white; z-index: 1000; box-shadow: 0 5px 15px rgba(0,0,0,0.5); backdrop-filter: blur(5px);
            max-height: 90vh; overflow-y: auto;
        }
        .quest-panel h3 { margin-top: 0; color: #facc15; font-size: 20px; border-bottom: 1px solid #555; padding-bottom: 10px; }
        .quest-card { background: rgba(255,255,255,0.1); border-radius: 8px; padding: 12px; margin-bottom: 10px; border: 1px solid #444; }
        .quest-title { font-weight: bold; font-size: 16px; color: #60a5fa; margin-bottom: 5px; }
        .quest-desc { font-size: 13px; color: #ccc; margin-bottom: 8px; }
        .quest-target { font-size: 14px; font-weight: bold; color: #34d399; margin-bottom: 5px; }
        .quest-rewards { font-size: 13px; color: #fbbf24; margin-bottom: 8px; }
        .quest-badge { display: inline-block; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
        .quest-badge.completed { background: #10b981; color: white; }
        .quest-badge.pending { background: #f59e0b; color: white; }

        /* ปรับ z-index ของ Dropdown ให้สูงกว่า Overlay ต่างๆ */
        .ts-dropdown { z-index: 99999 !important; }

        /* --- CSS Effect หน้าจอแตก/พิษ --- */
        #broken-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-image: url('https://upload.wikimedia.org/wikipedia/commons/thumb/4/4e/Broken_glass.png/800px-Broken_glass.png'); 
            background-size: cover; pointer-events: none; opacity: 0; transition: opacity 0.1s; z-index: 9999; mix-blend-mode: multiply;
        }
        #toxic-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: radial-gradient(circle, transparent 20%, rgba(0, 255, 0, 0.6) 90%);
            pointer-events: none; opacity: 0; transition: opacity 1.5s ease; z-index: 9998;
        }
        .shake { animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
        @keyframes shake {
            10%, 90% { transform: translate3d(-4px, 0, 0); }
            20%, 80% { transform: translate3d(6px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-8px, 0, 0); }
            40%, 60% { transform: translate3d(8px, 0, 0); }
        }

        /* =========================================
           CSS สำหรับ Modal และ ตารางธาตุ
           ========================================= */
        .periodic-modal-overlay {
            display: none; 
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.8);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            padding: 20px;
            box-sizing: border-box;
            overflow: auto;
        }
        .periodic-modal-content {
            background-color: #1a1a2e; 
            color: #e0e0e0;
            padding: 25px;
            border-radius: 12px;
            width: 100%;
            max-width: 1200px; 
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
            position: relative;
            overflow-x: auto; 
        }
        .periodic-close-btn {
            position: absolute;
            top: 15px; right: 20px;
            color: #ff6b6b; font-size: 28px; font-weight: bold;
            cursor: pointer; transition: 0.2s;
        }
        .periodic-close-btn:hover { color: #ff0000; }
        .periodic-modal-title { text-align: center; margin-bottom: 20px; font-size: 24px; }

        .periodic-grid {
            display: grid;
            grid-template-columns: repeat(18, minmax(50px, 1fr));
            grid-template-rows: repeat(7, minmax(50px, auto)) 20px repeat(2, minmax(50px, auto));
            gap: 6px;
            padding: 10px;
            user-select: none;
        }

        .element-cell {
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 6px;
            padding: 4px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.1s, background-color 0.2s;
            aspect-ratio: 1 / 1; 
            position: relative;
            background-color: #333; 
        }
        .element-cell:hover {
            transform: scale(1.15);
            z-index: 10;
            box-shadow: 0 0 15px rgba(255,255,255,0.3);
            border-color: white;
        }

        .atom-num { font-size: 10px; position: absolute; top: 2px; left: 4px; opacity: 0.7; }
        .atom-sym { font-size: 18px; font-weight: bold; }
        .atom-name { font-size: 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; opacity: 0.9;}
        .empty-cell { pointer-events: none; }

        .cat-alkali { background-color: #ff6666; color: black; }
        .cat-alkaline-earth { background-color: #ffdead; color: black; }
        .cat-transition { background-color: #87ceeb; color: black; }
        .cat-post-transition { background-color: #90ee90; color: black; }
        .cat-metalloid { background-color: #dda0dd; color: black; }
        .cat-nonmetal { background-color: #ffff99; color: black; }
        .cat-halogen { background-color: #f4a460; color: black; }
        .cat-noble-gas { background-color: #e6e6fa; color: black; }
        .cat-lanthanide { background-color: #ffb6c1; color: black; }
        .cat-actinide { background-color: #d8bfd8; color: black; }
        
        @media (max-width: 1100px) {
            .quest-panel, .status-panel { display: none; }
        }
    </style>
</head>
<body>

<div class="quest-panel">
    <h3>📜 ภารกิจจากห้องเรียน</h3>
    <?php if (empty($quests)): ?>
        <p style="color:#aaa; font-size:14px; text-align:center;">ยังไม่มีภารกิจในขณะนี้</p>
    <?php else: ?>
        <?php foreach ($quests as $q): ?>
            <div class="quest-card">
                <div class="quest-title"><?= htmlspecialchars($q['title']) ?></div>
                <div class="quest-desc"><?= htmlspecialchars($q['description']) ?></div>
                <div class="quest-target">🎯 เป้าหมาย: <?= htmlspecialchars($q['target_chem_name'] ?? 'ไม่ระบุ') ?></div>
                <div class="quest-rewards">
                    ✨ <?= $q['xp_reward'] ?> XP | 💰 <?= $q['gold_reward'] ?> Gold
                </div>
                <?php if($q['status'] === 'completed'): ?>
                    <div class="quest-badge completed">✅ สำเร็จแล้ว</div>
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
    <button class="reset-btn" id="btn-reset-all">🔄 รีเซ็ตแล็บ (Reset)</button>
</div>

<div id="broken-overlay"></div>
<div id="toxic-overlay"></div>

<div class="container">
    <h2>🧪 Survival Chemistry Lab (Dev Mode)</h2>
    
    <a href="index.php" class="btn-back">⬅ กลับ Dashboard</a>

    <div class="control-group">
        
        <div class="input-wrapper">
            <label>สารเคมี A (ตั้งต้น):</label>
            <div class="chem-selector-row">
                <select id="chemicalA" placeholder="ค้นหาชื่อสาร/ธาตุ..."></select>
                <button class="btn-periodic-trigger" onclick="openPeriodicTable('A')">📅 เลือกจากตาราง</button>
            </div>
            <input type="number" id="volA" value="50" placeholder="ปริมาตร (ml)" style="margin-top: 5px;">
        </div>

        <div class="input-wrapper">
            <label>สารเคมี B (ตัวทำปฏิกิริยา):</label>
            <div class="chem-selector-row">
                 <select id="chemicalB" placeholder="ค้นหาชื่อสาร/ธาตุ..."></select>
                 <button class="btn-periodic-trigger" onclick="openPeriodicTable('B')">📅 เลือกจากตาราง</button>
            </div>
            <input type="number" id="volB" value="50" placeholder="ปริมาตร (ml)" style="margin-top: 5px;">
        </div>
    </div>

    <button id="mix-button">⚗️ ผสมสารเคมี (Mix It!)</button>

    <div id="viewer3d">
        <div id="viewer3d-fallback" style="text-align:center; padding-top: 180px; color: #94a3b8;">
            กำลังโหลดโมเดล 3D...
        </div>
    </div>

    <div id="result-box">
        <div class="res-row"><span>📦 ผลิตภัณฑ์:</span> <span id="res-product" class="res-val">-</span></div>
        <div class="res-row"><span>📝 สูตรเคมี:</span> <span id="res-formula" class="res-val">-</span></div>
        <div class="res-row"><span>🌡️ อุณหภูมิ:</span> <span id="res-temp" class="res-val">-</span></div>
        
        <div class="res-row"><span>🎨 สีสารละลาย:</span> <span id="res-color" class="res-val">-</span></div>
        
        <div class="res-row"><span>💧 สถานะ:</span> <span id="res-state" class="res-val">-</span></div>
        <div class="res-row"><span>🧱 ตะกอน:</span> <span id="res-precipitate" class="res-val">-</span></div>
        <div class="res-row"><span>☁️ แก๊ส:</span> <span id="res-gas" class="res-val">-</span></div>
        
        <div style="margin-top: 10px; font-size: 0.9em; text-align: right; color: #888;">
            Volume: <span id="res-volume">0</span> mL
        </div>
    </div>
</div>

<div id="periodicModal" class="periodic-modal-overlay">
    <div class="periodic-modal-content">
        <span class="periodic-close-btn" onclick="closePeriodicTable()">&times;</span>
        <h3 class="periodic-modal-title">ตารางธาตุ (Periodic Table of Elements)</h3>
        <p style="text-align:center; margin-bottom:15px; font-size: 14px; color: #ccc;">คลิกที่ธาตุเพื่อเลือก (ชื่อธาตุต้องตรงกับในฐานข้อมูล)</p>
        <div id="periodicGridContainer" class="periodic-grid"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script type="module">
    try {
        const engine = await import('./js/3d_engine.js');
        window.hookInit3D = engine.init3DScene;
        window.hookUpdateVisuals = engine.updateLiquidVisuals;
        
        const container = document.getElementById('viewer3d');
        if (container && window.hookInit3D) {
            window.hookInit3D(container);
            const fallback = document.getElementById('viewer3d-fallback');
            if(fallback) fallback.style.display = 'none';
        }
    } catch(e) {
        console.warn("⚠️ ไม่สามารถโหลด 3D Engine ได้ ระบบจะทำงานในโหมด 2D", e);
        const fallback = document.getElementById('viewer3d-fallback');
        if(fallback) fallback.innerHTML = "โหมดแสดงผล 2D (ไม่พบไฟล์ 3D)";
    }
</script>

<script type="text/javascript">
    let tomA, tomB;
    let hp = 100;
    let beakerHp = 100;
    let currentTargetInput = null;

    document.addEventListener('DOMContentLoaded', () => {
        // โหลดข้อมูลสารเคมีและสร้าง Dropdown
        loadChemicalsAndInitTomSelect();

        // ผูก Event ปุ่มต่างๆ
        document.getElementById('mix-button').addEventListener('click', handleMix);
        document.getElementById('btn-reset-all').addEventListener('click', () => window.location.reload());

        // สร้างตารางธาตุเตรียมไว้ใน Modal
        renderPeriodicTable();
    });
    
    async function loadChemicalsAndInitTomSelect() {
        try {
            // 🔴 FIX 3: ดึงข้อมูลผ่าน mix.php API ที่ปลอดภัยกว่า get_chemicals.php เปล่าๆ
            const response = await fetch('mix.php?action=get_chemicals');
            const responseText = await response.text();
            const data = JSON.parse(responseText);
            
            if (!Array.isArray(data)) throw new Error("Invalid Data format from API");

            const config = {
                valueField: 'value',
                labelField: 'text', 
                searchField: 'text',
                options: data,      
                maxOptions: 200,
                placeholder: 'พิมพ์เพื่อค้นหา...',
                dropdownParent: 'body',
                render: {
                    option: function(data, escape) {
                        return '<div style="padding: 8px; border-bottom: 1px solid #f1f5f9;">' + escape(data.text) + '</div>';
                    },
                    no_results: function(data, escape) {
                        return '<div class="no-results" style="padding: 10px; color: #ef4444;">ไม่พบข้อมูลสารเคมีนี้</div>';
                    }
                }
            };

            tomA = new TomSelect("#chemicalA", config);
            tomB = new TomSelect("#chemicalB", config);

        } catch (error) {
            console.error("Failed to load chemicals:", error);
            alert("⚠️ ไม่สามารถโหลดรายการสารเคมีได้ กรุณาตรวจสอบการเชื่อมต่อฐานข้อมูล");
        }
    }

    // =========================================
    // ฟังก์ชันจัดการตารางธาตุ
    // =========================================
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
                    if (el.period === row && el.group === col) {
                        element = el;
                        break;
                    }
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

    // Global Functions สำหรับ Modal
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

    window.onclick = function(event) {
        const modal = document.getElementById('periodicModal');
        if (event.target == modal) closePeriodicTable();
    }

    function selectElementFromTable(elementName) {
        if (!currentTargetInput) return;
        const targetTom = (currentTargetInput === 'A') ? tomA : tomB;
        let foundId = null;
        for (const [id, optionData] of Object.entries(targetTom.options)) {
            if (optionData.text.toLowerCase().includes(elementName.toLowerCase())) {
                foundId = id; 
                break;
            }
        }

        if (foundId) {
            targetTom.setValue(foundId);
            closePeriodicTable();
        } else {
            alert(`⚠️ ไม่พบธาตุ "${elementName}" ในฐานข้อมูลของคุณ`);
        }
    }

    // =========================================
    // ฟังก์ชันหลักในการผสมสาร (แก้ไขการส่ง API)
    // =========================================
    async function handleMix() {
        if(hp <= 0 || beakerHp <= 0) {
            alert("อุปกรณ์พัง หรือ คุณอยู่ในสภาพที่ไม่พร้อมทดลองแล้ว! กรุณากดปุ่ม 'รีเซ็ตแล็บ'");
            return;
        }

        const chemA = tomA.getValue();
        const chemB = tomB.getValue();
        const volA = document.getElementById('volA').value || 0;
        const volB = document.getElementById('volB').value || 0;

        if (!chemA || !chemB) {
            alert("⚠️ กรุณาเลือกสารเคมีให้ครบทั้ง 2 ตัวครับ");
            return;
        }

        const btn = document.getElementById('mix-button');
        btn.disabled = true;
        btn.innerHTML = "⏳ กำลังทำปฏิกิริยา...";

        try {
            // 🔴 FIX 1: เพิ่ม action=mix ใน URL เพื่อให้ API ใน mix.php ส่งค่ากลับมาถูกต้อง (และไม่ไปดึงหน้า HTML มา)
            const url = `mix.php?action=mix&a=${chemA}&b=${chemB}&volA=${volA}&volB=${volB}`;
            const response = await fetch(url);
            
            // อ่านค่าเป็นข้อความก่อนเผื่อเกิด Error จากฝั่ง PHP
            const responseText = await response.text();
            
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (jsonErr) {
                console.error("Not a valid JSON:", responseText);
                throw new Error("ระบบประมวลผลหลังบ้านขัดข้อง กรุณาติดต่อ Developer");
            }

            if (!data.success) {
                throw new Error(data.error || "Unknown Error from server");
            }

            // แสดงกล่องผลลัพธ์
            document.getElementById('result-box').style.display = 'block';

            // ถ้า 3D โหลดสำเร็จ ให้วาดภาพน้ำด้วย
            if(typeof window.hookUpdateVisuals === 'function') {
                window.hookUpdateVisuals(data);
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
            <span style="display:inline-block; width:15px; height:15px; background-color:${colorHex}; border: 1px solid #999; margin-right:5px; vertical-align:middle; border-radius: 50%;"></span> 
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
            updateBars(20, 5); 
        } else if (data.damage_player > 0) {
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
        setTimeout(() => alert("💥 ตู้มมม!!! เกิดการระเบิด! (บีกเกอร์แตก)"), 100);
    }
    
    function triggerToxic() {
        document.getElementById('toxic-overlay').style.opacity = 1;
        setTimeout(() => alert("☠️ แค่กๆ! ก๊าซพิษฟุ้งกระจาย!"), 100);
    }
    
    function updateBars(damagePlayer, damageBeaker) {
        hp -= damagePlayer; beakerHp -= damageBeaker;
        if(hp < 0) hp = 0; if(beakerHp < 0) beakerHp = 0;
        
        document.getElementById('health-bar').style.width = hp + "%";
        document.getElementById('text-health').innerText = hp + "%";
        document.getElementById('beaker-bar').style.width = beakerHp + "%";
        document.getElementById('text-beaker').innerText = beakerHp + "%";
        
        if(hp < 30) document.getElementById('health-bar').style.backgroundColor = "#ff4757"; 
        else document.getElementById('health-bar').style.backgroundColor = "#00ff44";

        if(hp === 0) setTimeout(() => alert("💀 Game Over! คุณได้รับสารพิษมากเกินไป"), 500);
        if(beakerHp === 0) setTimeout(() => alert("🧪 บีกเกอร์แตกแล้ว! การทดลองล้มเหลว"), 500);
    }
</script>

</body>
</html>