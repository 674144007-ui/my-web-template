<?php
// lab_realistic.php - ห้องทดลองเคมี (Ultimate Anchor Fix - หายขาด 100%)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

requireLogin();

$page_title = "ห้องทดลองเคมีเสมือนจริง";
$csrf = generate_csrf_token();

$solids = [];
$liquids = [];

// เช็คคอลัมน์ formula เพื่อรองรับระบบสูตรเคมี
$has_formula = false;
$check_col = $conn->query("SHOW COLUMNS FROM chemicals LIKE 'formula'");
if($check_col && $check_col->num_rows > 0) $has_formula = true;

if($has_formula) {
    $res = $conn->query("SELECT id, name, formula, state, color_neutral FROM chemicals ORDER BY name ASC");
} else {
    $res = $conn->query("SELECT id, name, '' as formula, state, color_neutral FROM chemicals ORDER BY name ASC");
}

while ($row = $res->fetch_assoc()) {
    if ($row['state'] === 'solid') {
        $solids[] = $row;
    } else {
        $liquids[] = $row; 
    }
}

require_once 'header.php';
?>

<link rel="icon" href="data:;base64,iVBORw0KGgo=">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* =========================================
       CSS แก้อาการหน้าจอเบี้ยว (Fixed Anchor System)
       ========================================= */
    body { 
        background-color: #020617; color: #f8fafc; 
        font-family: 'Itim', cursive, system-ui; 
        margin: 0; padding: 0; overflow: hidden; 
    }
    
    /* Sub-header */
    .lab-subheader {
        background: #0f172a; border-bottom: 2px solid #1e293b; padding: 8px 20px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 4px 15px rgba(0,0,0,0.5); z-index: 100; position: relative;
    }
    .lab-title { font-size: 1.1rem; font-weight: bold; color: #38bdf8; display: flex; align-items: center; gap: 10px; }
    .btn-dashboard-back { background: rgba(56, 189, 248, 0.1); color: #38bdf8; border: 1px solid #38bdf8; padding: 6px 15px; border-radius: 6px; text-decoration: none; font-size: 0.9rem; transition: 0.3s; }
    .btn-dashboard-back:hover { background: #38bdf8; color: #0f172a; }

    /* HP */
    .hp-wrapper { display: flex; align-items: center; gap: 15px; background: rgba(255,255,255,0.05); padding: 5px 20px; border-radius: 20px; border: 1px solid #334155; width: 350px; }
    .hp-label { font-weight: bold; font-size: 1rem; color: #cbd5e1;}
    .hp-bar-bg { flex: 1; height: 10px; background: #1e293b; border-radius: 10px; overflow: hidden; }
    .hp-bar-fill { height: 100%; width: 100%; background: linear-gradient(90deg, #22c55e, #10b981); transition: width 0.3s ease, background 0.3s ease; }
    .hp-value { font-family: 'Share Tech Mono', monospace; font-size: 1rem; color: #22c55e; min-width: 45px; text-align: right;}
    .hp-shake { animation: shakeBar 0.3s; }
    @keyframes shakeBar { 0% { transform: translateX(0); } 25% { transform: translateX(-5px); } 50% { transform: translateX(5px); } 75% { transform: translateX(-5px); } 100% { transform: translateX(0); } }

    /* Flex Container หลัก */
    .lab-main-container {
        display: flex; height: calc(100vh - 120px); width: 100vw; position: relative; overflow: hidden;
    }

    /* Sidebars */
    .panel-side { width: 320px; height: 100%; position: relative; z-index: 80; transition: width 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
    .panel-side.collapsed { width: 0; }
    .panel-inner { width: 320px; height: 100%; background: #1e293b; display: flex; flex-direction: column; transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
    .panel-left .panel-inner { border-right: 2px solid #334155; box-shadow: 5px 0 15px rgba(0,0,0,0.3); }
    .panel-right .panel-inner { border-left: 2px solid #334155; box-shadow: -5px 0 15px rgba(0,0,0,0.3); }
    .panel-left.collapsed .panel-inner { transform: translateX(-100%); }
    .panel-right.collapsed .panel-inner { transform: translateX(100%); }

    .panel-content { padding: 15px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; }
    .panel-content::-webkit-scrollbar { width: 5px; } .panel-content::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }

    /* Toggle Buttons */
    .btn-toggle-panel { position: absolute; top: 15px; width: 35px; height: 45px; background: #3b82f6; color: white; border: none; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; z-index: 90; box-shadow: 0 4px 10px rgba(0,0,0,0.3); transition: 0.3s; }
    .btn-toggle-left { right: -35px; border-radius: 0 8px 8px 0; }
    .panel-left.collapsed .btn-toggle-left { right: -35px; }
    .btn-toggle-left:hover { width: 45px; right: -45px; background: #2563eb; }
    .btn-toggle-right { left: -35px; border-radius: 8px 0 0 8px; background: #8b5cf6; }
    .panel-right.collapsed .btn-toggle-right { left: -35px; }
    .btn-toggle-right:hover { width: 45px; left: -45px; background: #7c3aed; }

    /* 3. โต๊ะทดลอง (Workbench) */
    .workbench-wrapper {
        flex: 1; height: 100%; position: relative; overflow-x: auto; overflow-y: hidden; background: radial-gradient(circle at center, #334155 0%, #0f172a 100%);
    }
    .workbench-inner {
        min-width: 900px; /* ล็อกขนาดขั้นต่ำ ป้องกันอุปกรณ์ชนกัน */
        width: 100%; height: 100%; position: relative; margin: 0 auto;
    }

    @media (max-width: 1100px) {
        .panel-side { position: absolute; }
        .panel-left { left: 0; }
        .panel-right { right: 0; }
        .workbench-wrapper { width: 100vw; flex: none; }
    }

    /* =========================================
       🌟 หัวใจสำคัญ: ระบบยึดศูนย์กลางตายตัว (Fixed Anchor System)
       ========================================= */
    .desk-surface { position: absolute; bottom: 0; left: 0; width: 100%; height: 180px; background: linear-gradient(to bottom, #1e293b 0%, #020617 100%); border-top: 5px solid #475569; z-index: 1; }
    #fxCanvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 45; }
    .fume-hood-glass { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to bottom, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.02) 100%); backdrop-filter: blur(1px); border-bottom: 8px solid #94a3b8; transform: translateY(-90%); transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1); z-index: 40; pointer-events: none; }
    .fume-hood-glass.closed { transform: translateY(-30%); }

    /* จุดกึ่งกลางตายตัว กว้าง 0px สูงเต็มจอ */
    .desk-anchor {
        position: absolute;
        bottom: 0;
        left: 50%; /* อยู่ตรงกลางเสมอ */
        width: 0;
        height: 100%;
        z-index: 10;
        pointer-events: none; /* คลิกทะลุได้ */
    }

    .desk-anchor > * { pointer-events: auto; position: absolute; }

    /* 📌 อุปกรณ์กลาง (บีกเกอร์, ตะเกียง, สารหก) */
    .main-beaker { bottom: 100px; left: -90px; width: 180px; height: 220px; border: 4px solid rgba(255,255,255,0.6); border-top: none; border-radius: 0 0 20px 20px; background: rgba(255,255,255,0.05); display: flex; align-items: flex-end; overflow: hidden; box-shadow: inset 0 -10px 30px rgba(0,0,0,0.4); transition: 0.5s; z-index: 6;}
    .heater-base { bottom: 60px; left: -110px; width: 220px; height: 30px; background: #334155; border-radius: 5px; border: 2px solid #1e293b; box-shadow: 0 10px 15px rgba(0,0,0,0.8); z-index: 4; }
    .flame-container { bottom: 90px; left: -30px; width: 60px; height: 40px; display: none; justify-content: center; align-items: flex-end; z-index: 5;}
    .spill-area { bottom: 30px; left: -125px; width: 250px; height: 50px; background: transparent; border-radius: 50%; z-index: 3; opacity: 0; transition: 0.5s; pointer-events: none; }
    .btn-clean-spill { bottom: 40px; left: -80px; width: 160px; z-index: 100; background: #eab308; color: #fff; padding: 10px 0; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; display: none; box-shadow: 0 5px 15px rgba(0,0,0,0.5); animation: pulse 1s infinite; text-align: center;}

    /* 📌 อุปกรณ์ซ้าย (ตาชั่ง) - ขยับไปซ้าย 350px จากกึ่งกลาง */
    .digital-scale { bottom: 80px; left: -350px; width: 180px; background: #94a3b8; border-radius: 8px 8px 0 0; border: 2px solid #64748b; box-shadow: 0 10px 15px rgba(0,0,0,0.8); display: flex; flex-direction: column; align-items: center; padding-top: 15px; z-index: 5;}
    
    /* 📌 อุปกรณ์ขวา (กระบอกตวง) - ขยับไปขวา 180px จากกึ่งกลาง */
    .cylinder-container { bottom: 80px; left: 180px; width: 50px; height: 200px; border: 3px solid rgba(255,255,255,0.4); border-top: none; border-radius: 0 0 25px 25px; background: rgba(255,255,255,0.05); display: flex; align-items: flex-end; overflow: hidden; box-shadow: inset 0 0 10px rgba(255,255,255,0.1); z-index: 5;}

    /* Details */
    .scale-plate { width: 140px; height: 15px; background: #cbd5e1; border-radius: 50%; border: 2px solid #64748b; margin-bottom: 15px; position: relative; }
    .scale-display { background: #020617; color: #22c55e; font-family: 'Share Tech Mono', monospace; font-size: 1.5rem; padding: 5px 10px; border-radius: 5px; width: 80%; text-align: right; margin-bottom: 10px; box-shadow: inset 0 0 10px #000; box-sizing: border-box;}
    .cylinder-liquid { width: 100%; height: 0%; background: transparent; transition: height 0.5s ease-in-out, background 0.5s; }
    .flame { width: 40px; height: 40px; background: radial-gradient(circle at center, #fbbf24 0%, #ef4444 60%, transparent 100%); border-radius: 50% 50% 20% 20%; animation: flicker 0.1s infinite alternate; opacity: 0.9; filter: blur(2px); box-shadow: 0 -10px 20px rgba(239, 68, 68, 0.5); }
    @keyframes flicker { 0% { transform: scale(1) translateY(0); } 100% { transform: scale(1.15) translateY(-8px); opacity: 1; filter: blur(3px); } }
    .beaker-content { width: 100%; height: 0%; background: transparent; transition: height 1s ease-in-out, background-color 1.5s ease-in-out; position: relative; }
    .stirring-rod { position: absolute; top: -50px; left: 40%; width: 8px; height: 320px; background: linear-gradient(to right, rgba(255,255,255,0.8), rgba(255,255,255,0.4)); border-radius: 10px; z-index: 7; display: none; transform-origin: top center; box-shadow: inset 2px 0 5px rgba(255,255,255,0.5); }
    .stirring-anim { display: block; animation: stir 0.6s infinite linear; }
    @keyframes stir { 0% { transform: rotate(-12deg) translateX(-15px); } 50% { transform: rotate(12deg) translateX(15px); } 100% { transform: rotate(-12deg) translateX(-15px); } }
    @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }

    .sensor-panel { position: absolute; top: 20px; right: 20px; z-index: 30; background: rgba(15, 23, 42, 0.85); border: 1px solid #334155; border-radius: 10px; padding: 15px; display: flex; flex-direction: column; gap: 15px; backdrop-filter: blur(5px); }
    .sensor-box { background: #020617; border: 1px solid #1e293b; padding: 8px 12px; border-radius: 8px; text-align: center; }
    .sensor-label { color: #94a3b8; font-size: 0.75rem; margin-bottom: 3px; text-transform: uppercase; }
    .sensor-value { font-family: 'Orbitron', sans-serif; font-size: 1.5rem; transition: color 0.5s; }
    .val-temp { color: #38bdf8; } .val-temp.hot { color: #ef4444; }
    .val-ph { color: #22c55e; } .val-ph.acid { color: #ef4444; } .val-ph.base { color: #8b5cf6; }

    /* คลังสารเคมี (Left Panel Content) */
    .search-box { margin-bottom: 10px; position: sticky; top: 0; z-index: 10; background: #1e293b; padding-bottom: 10px; border-bottom: 1px solid #334155;}
    .search-box input { width: 100%; padding: 10px 12px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: white; outline: none; margin-bottom: 8px; box-sizing: border-box;}
    .search-box input:focus { border-color: #38bdf8; }
    .btn-pt { width: 100%; padding: 10px; background: linear-gradient(90deg, #8b5cf6, #3b82f6); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer;}
    .inventory-title { font-size: 1rem; color: #94a3b8; border-bottom: 1px solid #334155; padding-bottom: 5px; margin: 10px 0; }
    .chem-list { display: flex; flex-direction: column; gap: 6px; }
    .chem-item { background: #0f172a; padding: 10px; border-radius: 6px; cursor: pointer; border: 1px solid #334155; display: flex; align-items: center; gap: 10px;}
    .chem-item:hover { border-color: #38bdf8; background: #1e293b; }
    .chem-color-indicator { width: 15px; height: 15px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.2); flex-shrink: 0; }
    .chem-name { font-size: 0.9rem; flex: 1; word-break: break-word; }
    .chem-formula { font-family: monospace; font-size: 0.7rem; color: #38bdf8; }

    /* สมุดบันทึก & กราฟ (Right Panel Content) */
    .tabs-nav { display: flex; background: #0f172a; border-bottom: 2px solid #334155; }
    .tab-btn { flex: 1; padding: 12px; background: transparent; border: none; color: #94a3b8; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: inherit; border-bottom: 2px solid transparent;}
    .tab-btn.active { color: #38bdf8; border-bottom-color: #38bdf8; background: #1e293b; }
    .tab-content { display: none; flex-direction: column; flex: 1; overflow-y: auto; padding: 15px; }
    .tab-content.active { display: flex; }

    .control-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
    .env-btn { background: #334155; border: 1px solid #475569; color: white; padding: 10px; border-radius: 6px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 5px; font-size: 0.9rem;}
    .env-btn.active-heat { background: #ef4444; border-color: #b91c1c; }
    .env-btn.active-stir { background: #3b82f6; border-color: #2563eb; }
    .env-btn.active-hood { background: #10b981; border-color: #059669; }
    
    .safety-zone { background: rgba(239, 68, 68, 0.1); padding: 10px; border-radius: 6px; border: 1px dashed #ef4444; margin-bottom: 10px; font-size: 0.9rem; }
    .safety-zone label { display: flex; align-items: center; gap: 8px; color: #fca5a5; cursor: pointer; margin-bottom: 5px;}
    
    .log-zone { flex: 1; background: #0f172a; border: 1px solid #334155; border-radius: 6px; padding: 10px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; margin-bottom: 10px; color:#e2e8f0; }
    .log-entry { border-bottom: 1px dashed #1e293b; padding-bottom: 5px; margin-bottom: 5px;}
    .log-time { color: #64748b; font-size: 0.75rem; margin-right: 5px;}
    
    .btn-mix { background: #8b5cf6; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; margin-bottom: 8px; font-size: 1.1rem;}
    .btn-mix:hover { background: #7c3aed; }
    .btn-mix:disabled { background: #334155; color: #64748b; cursor: not-allowed;}
    .btn-reset { background: #334155; color: white; border: 1px solid #475569; padding: 10px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; margin-bottom: 8px;}
    .btn-submit-lab { background: #10b981; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; width: 100%; font-size: 1.1rem;}

    .graph-zone { flex: 1; position: relative; min-height: 200px; background: #0f172a; border-radius: 8px; border: 1px solid #334155; padding: 5px;}

    /* Popups (Z-Index ลอยเหนือสุด) */
    .tool-controls { position: absolute; z-index: 1000 !important; background: rgba(2, 6, 23, 0.95); padding: 0 0 15px 0; border-radius: 8px; border: 1px solid #38bdf8; display: none; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.9); width: 280px; }
    .drag-handle { padding: 10px; background: rgba(56, 189, 248, 0.1); border-bottom: 1px solid #334155; border-radius: 8px 8px 0 0; cursor: grab; display: flex; justify-content: center; align-items: center; margin-bottom: 15px; }
    .drag-handle h4 { margin: 0; color: #38bdf8; font-size: 1rem; pointer-events: none; }
    .tool-body { padding: 0 15px; }
    .btn-action { background: #334155; border: 1px solid #475569; color: white; padding: 6px 12px; border-radius: 6px; margin: 3px; cursor: pointer; font-size: 0.9rem;}
    .btn-transfer { background: #10b981; border: none; color: white; padding: 10px; border-radius: 6px; margin-top: 10px; cursor: pointer; width: 100%; font-weight:bold; box-sizing: border-box;}
    
    .modal-overlay { position: fixed; top:0; left:0; width:100vw; height:100vh; background: rgba(0,0,0,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
    .waste-modal, .game-over-modal, .grade-modal { background: rgba(15, 23, 42, 0.98); padding: 25px; border-radius: 12px; border: 1px solid #38bdf8; text-align: center; box-shadow: 0 10px 30px #000; width: 320px; }
    .btn-waste { display: block; width: 100%; padding: 12px; margin-bottom: 10px; border-radius: 8px; font-weight: bold; border: none; cursor: pointer; box-sizing: border-box;}
    .btn-waste-sink { background: #3b82f6; color: white; } .btn-waste-bin { background: #eab308; color: #1e293b; }
    .grade-circle { width: 100px; height: 100px; border-radius: 50%; background: #1e293b; margin: 0 auto 15px auto; display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: bold; border: 5px solid #8b5cf6; }

    /* ⚛️ Periodic Table Modal */
    .pt-modal { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(2, 6, 23, 0.98); z-index: 9999; display: none; align-items: center; justify-content: center; flex-direction: column; padding: 20px; box-sizing: border-box; }
    .pt-header { color: #38bdf8; font-size: 1.5rem; font-weight: bold; margin-bottom: 20px; display: flex; justify-content: space-between; width: 100%; max-width: 1200px; }
    .btn-close-pt { background: #ef4444; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight:bold;}
    .pt-container { display: grid; grid-template-columns: repeat(18, 1fr); gap: 4px; max-width: 1200px; width: 100%; }
    .pt-element { background: #1e293b; border: 1px solid #334155; border-radius: 4px; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2px; cursor: pointer; aspect-ratio: 1/1.1; }
    .pt-element:hover { transform: scale(1.5); z-index: 10; box-shadow: 0 10px 20px #000; }
    .pt-num { font-size: 0.5rem; color: #94a3b8; } .pt-sym { font-size: 1rem; font-weight: bold; font-family: 'Orbitron'; } .pt-name { font-size: 0.5rem; color: #cbd5e1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 95%; text-align: center;}
    .type-alkali { background: rgba(239, 68, 68, 0.15); border-color: #ef4444; color: #fca5a5; } .type-alkaline-earth { background: rgba(249, 115, 22, 0.15); border-color: #f97316; color: #fdba74; } .type-transition { background: rgba(234, 179, 8, 0.15); border-color: #eab308; color: #fde047; } .type-post-transition { background: rgba(132, 204, 22, 0.15); border-color: #84cc16; color: #bef264; } .type-metalloid { background: rgba(16, 185, 129, 0.15); border-color: #10b981; color: #6ee7b7; } .type-nonmetal { background: rgba(6, 182, 212, 0.15); border-color: #06b6d4; color: #67e8f9; } .type-halogen { background: rgba(59, 130, 246, 0.15); border-color: #3b82f6; color: #93c5fd; } .type-noble-gas { background: rgba(139, 92, 246, 0.15); border-color: #8b5cf6; color: #c4b5fd; } .type-lanthanide { background: rgba(217, 70, 239, 0.15); border-color: #d946ef; color: #f0abfc; } .type-actinide { background: rgba(236, 72, 153, 0.15); border-color: #ec4899; color: #f9a8d4; } .type-unknown { background: rgba(100, 116, 139, 0.15); border-color: #64748b; color: #cbd5e1; }

    .explosion-flash { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: white; z-index: 9999; pointer-events: none; opacity: 0; }
    .flash-anim { animation: explodeFlash 1s ease-out forwards; }
    @keyframes explodeFlash { 0% { opacity: 1; background: white; } 10% { background: #ef4444; opacity: 0.8; } 100% { opacity: 0; background: transparent; } }
    @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
</style>

<div class="lab-subheader">
    <div class="lab-title">
        <span style="font-size: 1.5rem;">🧪</span> Bankha Virtual Lab (Ultimate Center Fix)
    </div>
    
    <div class="hp-wrapper" id="hpContainer">
        <div class="hp-label">HP:</div>
        <div class="hp-bar-bg"><div class="hp-bar-fill" id="hpBarFill"></div></div>
        <div class="hp-value" id="hpValueTxt">100%</div>
    </div>

    <div>
        <?php if ($_SESSION['role'] === 'developer' || $_SESSION['role'] === 'teacher'): ?>
            <a href="dashboard_teacher.php" class="btn-dashboard-back">⬅ กลับ Dashboard ครู</a>
        <?php else: ?>
            <a href="dashboard_student.php" class="btn-dashboard-back">⬅ กลับ Dashboard</a>
        <?php endif; ?>
    </div>
</div>

<div class="lab-main-container">
    
    <div class="panel-side panel-left" id="panelLeft">
        <div class="panel-inner">
            <div class="panel-content">
                <div class="search-box">
                    <input type="text" id="chemSearchInput" placeholder="🔍 ค้นหาชื่อ หรือ สูตรเคมี..." onkeyup="filterChemicals()">
                    <button class="btn-pt" onclick="openPeriodicTable()">⚛️ เปิดตารางธาตุ</button>
                </div>

                <div class="inventory-title">🧊 ของแข็ง (Solid)</div>
                <div class="chem-list" id="solidList">
                    <?php foreach ($solids as $s): ?>
                        <div class="chem-item" onclick="selectChemical(<?= $s['id'] ?>, '<?= h($s['name']) ?>', 'solid', '<?= $s['color_neutral'] ?>')">
                            <div class="chem-color-indicator" style="background-color: <?= h($s['color_neutral']) ?>;"></div>
                            <div class="chem-info-group">
                                <div class="chem-name"><?= h($s['name']) ?></div>
                                <?php if(!empty($s['formula'])): ?><div class="chem-formula"><?= h($s['formula']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="inventory-title">💧 ของเหลว (Liquid)</div>
                <div class="chem-list" id="liquidList">
                    <?php foreach ($liquids as $l): ?>
                        <div class="chem-item" onclick="selectChemical(<?= $l['id'] ?>, '<?= h($l['name']) ?>', 'liquid', '<?= $l['color_neutral'] ?>')">
                            <div class="chem-color-indicator" style="background-color: <?= h($l['color_neutral']) ?>;"></div>
                            <div class="chem-info-group">
                                <div class="chem-name"><?= h($l['name']) ?></div>
                                <?php if(!empty($l['formula'])): ?><div class="chem-formula"><?= h($l['formula']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button class="btn-toggle-panel btn-toggle-left" onclick="togglePanel('panelLeft', '▶', '◀')" title="พับเก็บ/กางออก">◀</button>
    </div>

    <div class="workbench-wrapper" id="workbench">
        <div class="workbench-inner">
            <canvas id="fxCanvas"></canvas>
            <div class="fume-hood-glass" id="fumeHoodGlass"></div>

            <div class="sensor-panel">
                <div class="sensor-box"><div class="sensor-label">Temp</div><div class="sensor-value val-temp" id="valTemp">25.0 °C</div></div>
                <div class="sensor-box"><div class="sensor-label">pH Level</div><div class="sensor-value val-ph" id="valPh">7.00</div></div>
            </div>

            <div class="desk-surface"></div>

            <div class="tool-controls" id="toolControls">
                <div class="drag-handle" id="toolControlsHandle">
                    <span style="margin-right:10px; color:#94a3b8;">⣿</span>
                    <h4 id="toolChemName" style="margin:0; color:#38bdf8; font-size:1.1rem;">เลือกสาร...</h4>
                </div>
                <div class="tool-body">
                    <div id="solidControls" style="display:none;">
                        <button class="btn-action" onclick="addAmount(5)">ตัก 5 g</button>
                        <button class="btn-action" onclick="addAmount(20)">ตัก 20 g</button>
                        <button class="btn-action" style="background:#ef4444;" onclick="resetTool()">เททิ้ง</button>
                    </div>
                    <div id="liquidControls" style="display:none;">
                        <button class="btn-action" onclick="addAmount(10)">เท 10 ml</button>
                        <button class="btn-action" onclick="addAmount(30)">เท 30 ml</button>
                        <button class="btn-action" style="background:#ef4444;" onclick="resetTool()">เททิ้ง</button>
                    </div>
                    <button class="btn-transfer" onclick="transferToBeaker()">⬇️ ถ่ายลงบีกเกอร์</button>
                    <button class="btn-action" style="width:100%; margin-top:10px; background:transparent; border:none; color:#94a3b8;" onclick="cancelTool()">[ ปิด ]</button>
                </div>
            </div>

            <div class="desk-anchor">
                
                <div class="digital-scale" id="scaleObj">
                    <div class="scale-plate"><div id="scalePowder" style="width: 0%; height: 5px; background: transparent; margin: 0 auto; border-radius: 50%; position: relative; top: -5px; transition: 0.3s;"></div></div>
                    <div class="scale-display" id="scaleDisplay">0.00 g</div>
                    <button class="btn-action" style="width: 80%; background:#ef4444; border:none; margin-bottom: 10px;" onclick="tareScale()">TARE</button>
                </div>
                
                <div class="main-beaker" id="mainBeaker">
                    <div class="stirring-rod" id="stirRod"></div>
                    <div class="beaker-content" id="beakerContent"></div>
                </div>
                <div class="flame-container" id="flameFire"><div class="flame"></div></div>
                <div class="heater-base"></div>
                
                <div class="spill-area" id="spillArea"></div>
                <button class="btn-clean-spill" id="btnCleanSpill" onclick="cleanSpill()">🧻 ซับทำความสะอาดสารหก</button>

                <div class="cylinder-container" id="cylinderObj">
                    <div class="cylinder-liquid" id="cylinderLiquid"></div>
                    <div style="position: absolute; width:100%; height:100%; top:0; left:0; pointer-events:none; background-image: repeating-linear-gradient(to bottom, transparent, transparent 19px, rgba(255,255,255,0.4) 19px, rgba(255,255,255,0.4) 20px);"></div>
                </div>

            </div>
        </div>
    </div>

    <div class="panel-side panel-right" id="panelRight">
        <button class="btn-toggle-panel btn-toggle-right" onclick="togglePanel('panelRight', '◀', '▶')" title="พับเก็บ/กางออก">▶</button>
        <div class="panel-inner">
            <div class="tabs-nav">
                <button class="tab-btn active" onclick="switchTab('tabLog')">📝 Controls</button>
                <button class="tab-btn" onclick="switchTab('tabGraph')">📈 Live Graph</button>
            </div>

            <div class="tab-content active" id="tabLog">
                <div class="control-grid">
                    <button class="env-btn" id="btnHeater" onclick="toggleHeater()"><span style="font-size:1.5rem;">🔥</span><span id="txtHeater">ต้มสาร</span></button>
                    <button class="env-btn" id="btnStir" onclick="toggleStir()"><span style="font-size:1.5rem;">🥄</span><span id="txtStir">คนสาร</span></button>
                    <button class="env-btn" id="btnHood" onclick="toggleHood()" style="grid-column: span 2;"><span style="font-size:1.5rem;">🌫️</span><span id="txtHood">เลื่อนกระจกตู้ดูดควัน</span></button>
                </div>

                <div class="safety-zone">
                    <label><input type="checkbox" id="chkGoggles" onchange="playClick()"> 🥽 แว่นตา (Goggles)</label>
                    <label><input type="checkbox" id="chkGloves" onchange="playClick()"> 🧤 ถุงมือ (Gloves)</label>
                </div>

                <div class="log-zone" id="systemLog"><div class="log-entry"><span class="log-time"><?= date('H:i:s') ?></span> [System] Fixed Anchor Center Ready.</div></div>

                <button class="btn-mix" id="btnMix" onclick="executeReaction()" disabled>⚗️ ประมวลผลปฏิกิริยา</button>
                <div style="display:flex; gap:10px; margin-bottom:8px;">
                    <button class="btn-reset" onclick="requestWash()" style="margin:0;">🗑️ ทิ้งของเสีย</button>
                </div>
                <button class="btn-submit-lab" onclick="submitFinalReport()">📄 ส่งรายงานให้ครู (Submit)</button>
            </div>

            <div class="tab-content" id="tabGraph">
                <h4 style="margin:0 0 10px 0; color:#38bdf8;">📊 กราฟวิเคราะห์</h4>
                <div class="graph-zone"><canvas id="liveChart"></canvas></div>
            </div>
        </div>
    </div>

</div>

<div class="modal-overlay" id="wasteModalOverlay">
    <div class="waste-modal">
        <h4 style="color:#38bdf8; margin:0 0 15px 0;">🗑️ การกำจัดของเสีย</h4>
        <button class="btn-waste btn-waste-sink" onclick="submitWaste('sink')">🚰 เทลงอ่างล้างจาน (Sink)</button>
        <button class="btn-waste btn-waste-bin" onclick="submitWaste('bin')">☣️ ทิ้งลงถังขยะอันตราย (Hazard Bin)</button>
        <button class="btn-action" style="width:100%; background:transparent; border:none; color:#94a3b8;" onclick="cancelWaste()">[ ยกเลิก ]</button>
    </div>
</div>

<div class="modal-overlay" id="gameOverModalOverlay">
    <div class="game-over-modal">
        <h2 style="color:#ef4444; margin:0 0 10px 0;">☠️ GAME OVER</h2>
        <p style="color:#fca5a5; margin-bottom: 20px;">คุณทำผิดกฎความปลอดภัยร้ายแรงจนเสียชีวิต</p>
        <button class="btn-waste btn-waste-sink" style="background:#ef4444;" onclick="location.reload()">🔄 เริ่มต้นใหม่</button>
    </div>
</div>

<div class="modal-overlay" id="gradeModalOverlay">
    <div class="grade-modal">
        <h3 style="color:#f8fafc; margin-top:0;">📝 สรุปผลการประเมิน</h3>
        <div class="grade-circle grade-A" id="finalGrade">A</div>
        <h2 style="margin:0 0 5px 0; color:#38bdf8;">คะแนน: <span id="finalScore">100</span> / 100</h2>
        <p id="gradeFeedback" style="color:#94a3b8; line-height:1.5; margin-bottom:20px;">...</p>
        <button class="btn-transfer" onclick="location.reload()">🔄 จบการทดลองและเริ่มใหม่</button>
    </div>
</div>

<div class="pt-modal" id="ptModal">
    <div class="pt-header">
        <div>⚛️ Interactive Periodic Table</div>
        <button class="btn-action" style="background:#ef4444; border:none;" onclick="closePeriodicTable()">ปิด ✖</button>
    </div>
    <div class="pt-container" id="ptContainer"></div>
</div>

<div class="explosion-flash" id="flashOverlay"></div>
<input type="hidden" id="csrfToken" value="<?= h($csrf) ?>">

<script>
    // =========================================
    // 🌟 ระบบสลับ Tabs ด้านขวา
    // =========================================
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        event.currentTarget.classList.add('active');
    }

    // =========================================
    // 🌟 ระบบพับเก็บหน้าจอ (True Slide)
    // =========================================
    function togglePanel(panelId, iconCollapsed, iconExpanded) {
        const panel = document.getElementById(panelId);
        const btn = panel.querySelector('.btn-toggle-panel');
        panel.classList.toggle('collapsed');
        if(panel.classList.contains('collapsed')) {
            btn.innerHTML = iconCollapsed;
        } else {
            btn.innerHTML = iconExpanded;
        }
        setTimeout(resizeCanvas, 360); 
    }

    window.addEventListener('DOMContentLoaded', () => {
        if(window.innerWidth <= 1100) {
            document.getElementById('panelLeft').classList.add('collapsed');
            document.querySelector('.btn-toggle-left').innerHTML = '▶';
            document.getElementById('panelRight').classList.add('collapsed');
            document.querySelector('.btn-toggle-right').innerHTML = '◀';
        }
    });

    // =========================================
    // 🕹️ ระบบลากหน้าต่างเครื่องมือ (แก้บัคกระตุก 100%)
    // =========================================
    dragElement(document.getElementById("toolControls"));
    function dragElement(elmnt) {
        var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
        var header = document.getElementById(elmnt.id + "Handle");
        if (header) { header.onmousedown = dragMouseDown; header.ontouchstart = dragTouchStart; } 
        else { elmnt.onmousedown = dragMouseDown; elmnt.ontouchstart = dragTouchStart; }

        function dragMouseDown(e) { e = e || window.event; e.preventDefault(); pos3 = e.clientX; pos4 = e.clientY; document.onmouseup = closeDragElement; document.onmousemove = elementDrag; }
        function dragTouchStart(e) { e = e || window.event; pos3 = e.touches[0].clientX; pos4 = e.touches[0].clientY; document.ontouchend = closeDragElement; document.ontouchmove = elementTouchDrag; }
        function elementDrag(e) { e = e || window.event; e.preventDefault(); pos1 = pos3 - e.clientX; pos2 = pos4 - e.clientY; pos3 = e.clientX; pos4 = e.clientY; elmnt.style.top = (elmnt.offsetTop - pos2) + "px"; elmnt.style.left = (elmnt.offsetLeft - pos1) + "px"; }
        function elementTouchDrag(e) { e = e || window.event; pos1 = pos3 - e.touches[0].clientX; pos2 = pos4 - e.touches[0].clientY; pos3 = e.touches[0].clientX; pos4 = e.touches[0].clientY; elmnt.style.top = (elmnt.offsetTop - pos2) + "px"; elmnt.style.left = (elmnt.offsetLeft - pos1) + "px"; }
        function closeDragElement() { document.onmouseup = null; document.onmousemove = null; document.ontouchend = null; document.ontouchmove = null; }
    }

    // =========================================
    // ⚛️ การสร้างตารางธาตุ 118 ธาตุ
    // =========================================
    const ptData = [{n:1,s:'H',en:'Hydrogen',t:'nonmetal',r:1,c:1},{n:2,s:'He',en:'Helium',t:'noble-gas',r:1,c:18},{n:3,s:'Li',en:'Lithium',t:'alkali',r:2,c:1},{n:4,s:'Be',en:'Beryllium',t:'alkaline-earth',r:2,c:2},{n:5,s:'B',en:'Boron',t:'metalloid',r:2,c:13},{n:6,s:'C',en:'Carbon',t:'nonmetal',r:2,c:14},{n:7,s:'N',en:'Nitrogen',t:'nonmetal',r:2,c:15},{n:8,s:'O',en:'Oxygen',t:'nonmetal',r:2,c:16},{n:9,s:'F',en:'Fluorine',t:'halogen',r:2,c:17},{n:10,s:'Ne',en:'Neon',t:'noble-gas',r:2,c:18},{n:11,s:'Na',en:'Sodium',t:'alkali',r:3,c:1},{n:12,s:'Mg',en:'Magnesium',t:'alkaline-earth',r:3,c:2},{n:13,s:'Al',en:'Aluminum',t:'post-transition',r:3,c:13},{n:14,s:'Si',en:'Silicon',t:'metalloid',r:3,c:14},{n:15,s:'P',en:'Phosphorus',t:'nonmetal',r:3,c:15},{n:16,s:'S',en:'Sulfur',t:'nonmetal',r:3,c:16},{n:17,s:'Cl',en:'Chlorine',t:'halogen',r:3,c:17},{n:18,s:'Ar',en:'Argon',t:'noble-gas',r:3,c:18},{n:19,s:'K',en:'Potassium',t:'alkali',r:4,c:1},{n:20,s:'Ca',en:'Calcium',t:'alkaline-earth',r:4,c:2},{n:21,s:'Sc',en:'Scandium',t:'transition',r:4,c:3},{n:22,s:'Ti',en:'Titanium',t:'transition',r:4,c:4},{n:23,s:'V',en:'Vanadium',t:'transition',r:4,c:5},{n:24,s:'Cr',en:'Chromium',t:'transition',r:4,c:6},{n:25,s:'Mn',en:'Manganese',t:'transition',r:4,c:7},{n:26,s:'Fe',en:'Iron',t:'transition',r:4,c:8},{n:27,s:'Co',en:'Cobalt',t:'transition',r:4,c:9},{n:28,s:'Ni',en:'Nickel',t:'transition',r:4,c:10},{n:29,s:'Cu',en:'Copper',t:'transition',r:4,c:11},{n:30,s:'Zn',en:'Zinc',t:'transition',r:4,c:12},{n:31,s:'Ga',en:'Gallium',t:'post-transition',r:4,c:13},{n:32,s:'Ge',en:'Germanium',t:'metalloid',r:4,c:14},{n:33,s:'As',en:'Arsenic',t:'metalloid',r:4,c:15},{n:34,s:'Se',en:'Selenium',t:'nonmetal',r:4,c:16},{n:35,s:'Br',en:'Bromine',t:'halogen',r:4,c:17},{n:36,s:'Kr',en:'Krypton',t:'noble-gas',r:4,c:18}];
    const ptContainer = document.getElementById('ptContainer');
    ptData.forEach(el => {
        let div = document.createElement('div');
        div.className = `pt-element type-${el.t}`; div.style.gridColumn = el.c; div.style.gridRow = el.r; div.title = el.en;
        div.onclick = () => { closePeriodicTable(); document.getElementById('chemSearchInput').value = el.s; filterChemicals(); if(document.getElementById('panelLeft').classList.contains('collapsed')) togglePanel('panelLeft', '▶', '◀'); addLog(`🔍 กรองคลังสารเคมีโดยใช้ธาตุ: <b style="color:#38bdf8;">${el.s}</b>`); };
        div.innerHTML = `<div class="pt-num">${el.n}</div><div class="pt-sym">${el.s}</div><div class="pt-name">${el.en}</div>`;
        ptContainer.appendChild(div);
    });
    function openPeriodicTable() { playClick(); document.getElementById('ptModal').style.display = 'flex'; }
    function closePeriodicTable() { playClick(); document.getElementById('ptModal').style.display = 'none'; }

    function filterChemicals() {
        let input = document.getElementById('chemSearchInput').value.toLowerCase();
        let items = document.querySelectorAll('.chem-item');
        items.forEach(item => {
            let nameText = item.querySelector('.chem-name').innerText.toLowerCase();
            let formulaText = item.querySelector('.chem-formula') ? item.querySelector('.chem-formula').innerText.toLowerCase() : "";
            if(nameText.includes(input) || formulaText.includes(input)) item.style.display = 'flex'; else item.style.display = 'none';
        });
    }

    // =========================================
    // 🎵 ระบบเสียง
    // =========================================
    const AudioContext = window.AudioContext || window.webkitAudioContext; let audioCtx = new AudioContext();
    function initAudio() { if (audioCtx.state === 'suspended') audioCtx.resume(); } document.body.addEventListener('click', initAudio, { once: true });
    function playClick() { if(!audioCtx) return; const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.type = 'sine'; osc.frequency.setValueAtTime(800, audioCtx.currentTime); osc.frequency.exponentialRampToValueAtTime(300, audioCtx.currentTime + 0.1); gain.gain.setValueAtTime(0.1, audioCtx.currentTime); gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.1); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(); osc.stop(audioCtx.currentTime + 0.1); }
    function playPourSound() { if(!audioCtx) return; const bs = audioCtx.sampleRate * 1.5; const buf = audioCtx.createBuffer(1, bs, audioCtx.sampleRate); const d = buf.getChannelData(0); for(let i=0;i<bs;i++) d[i] = Math.random()*2-1; const noise = audioCtx.createBufferSource(); noise.buffer = buf; const f = audioCtx.createBiquadFilter(); f.type='lowpass'; f.frequency.value=1000; const g = audioCtx.createGain(); g.gain.setValueAtTime(0, audioCtx.currentTime); g.gain.linearRampToValueAtTime(0.3, audioCtx.currentTime+0.2); g.gain.linearRampToValueAtTime(0, audioCtx.currentTime+1.5); noise.connect(f); f.connect(g); g.connect(audioCtx.destination); noise.start(); }
    function playSuccessSound() { if(!audioCtx) return; const freqs = [523.25, 659.25, 783.99, 1046.50]; freqs.forEach((fq, i) => { setTimeout(() => { const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.type = 'sine'; osc.frequency.value = fq; gain.gain.setValueAtTime(0, audioCtx.currentTime); gain.gain.linearRampToValueAtTime(0.2, audioCtx.currentTime+0.1); gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime+1); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(); osc.stop(audioCtx.currentTime+1); }, i*100); }); }
    function playErrorSound() { if(!audioCtx) return; const osc = audioCtx.createOscillator(); const gain = audioCtx.createGain(); osc.type = 'sawtooth'; osc.frequency.setValueAtTime(150, audioCtx.currentTime); osc.frequency.linearRampToValueAtTime(80, audioCtx.currentTime+0.5); gain.gain.setValueAtTime(0.3, audioCtx.currentTime); gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime+0.5); osc.connect(gain); gain.connect(audioCtx.destination); osc.start(); osc.stop(audioCtx.currentTime+0.5); }
    let fireAudioNode = null; function toggleFireSound(start) { if(!audioCtx) return; if(start) { const bs = audioCtx.sampleRate*2; const buf = audioCtx.createBuffer(1, bs, audioCtx.sampleRate); const d = buf.getChannelData(0); for(let i=0;i<bs;i++) d[i] = Math.random()*2-1; fireAudioNode = audioCtx.createBufferSource(); fireAudioNode.buffer = buf; fireAudioNode.loop = true; const f = audioCtx.createBiquadFilter(); f.type='lowpass'; f.frequency.value=400; const g = audioCtx.createGain(); g.gain.value=0.5; fireAudioNode.connect(f); f.connect(g); g.connect(audioCtx.destination); fireAudioNode.start(); } else if(fireAudioNode) { fireAudioNode.stop(); fireAudioNode = null; } }
    function playExplosionSound() { if(!audioCtx) return; const bs = audioCtx.sampleRate*3; const buf = audioCtx.createBuffer(1, bs, audioCtx.sampleRate); const d = buf.getChannelData(0); for(let i=0;i<bs;i++) d[i] = Math.random()*2-1; const noise = audioCtx.createBufferSource(); noise.buffer = buf; const f = audioCtx.createBiquadFilter(); f.type='lowpass'; f.frequency.setValueAtTime(100, audioCtx.currentTime); f.frequency.exponentialRampToValueAtTime(1000, audioCtx.currentTime+0.1); f.frequency.exponentialRampToValueAtTime(50, audioCtx.currentTime+2); const g = audioCtx.createGain(); g.gain.setValueAtTime(2.0, audioCtx.currentTime); g.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime+2.5); noise.connect(f); f.connect(g); g.connect(audioCtx.destination); noise.start(); }

    // =========================================
    // 🎨 HTML5 CANVAS PARTICLE ENGINE (ผูกกับ Workbench)
    // =========================================
    const canvas = document.getElementById('fxCanvas'); const ctx = canvas.getContext('2d'); let particles = [];
    function resizeCanvas() { 
        const wb = document.querySelector('.workbench-wrapper'); 
        canvas.width = wb.clientWidth; 
        canvas.height = wb.clientHeight; 
    }
    window.addEventListener('resize', resizeCanvas); resizeCanvas();

    class Particle {
        constructor(x, y, type, color) {
            this.x = x; this.y = y; this.type = type; this.color = color; this.life = 1.0;
            if(type==='gas'){ this.vx=(Math.random()-0.5)*2; this.vy=-(Math.random()*3+1); this.size=Math.random()*10+5; this.decay=Math.random()*0.01+0.005; }
            else if(type==='precipitate'){ this.vx=(Math.random()-0.5)*1; this.vy=(Math.random()*2+1); this.size=Math.random()*3+1; this.decay=Math.random()*0.005+0.002; }
            else if(type==='explosion'){ const a=Math.random()*Math.PI*2; const s=Math.random()*15+5; this.vx=Math.cos(a)*s; this.vy=Math.sin(a)*s; this.size=Math.random()*5+2; this.decay=Math.random()*0.02+0.01; }
            else if(type==='bubble'){ this.vx=(Math.random()-0.5)*0.5; this.vy=-(Math.random()*5+2); this.size=Math.random()*6+2; this.decay=0.02; }
        }
        update() { this.x+=this.vx; this.y+=this.vy; if(this.type==='gas'||this.type==='bubble')this.size+=0.1; if(this.type==='explosion')this.vy+=0.5; this.life-=this.decay; }
        draw() { ctx.globalAlpha=Math.max(0,this.life); ctx.fillStyle=this.color; ctx.beginPath(); ctx.arc(this.x,this.y,this.size,0,Math.PI*2); ctx.fill(); ctx.globalAlpha=1.0; }
    }
    function spawnParticles(type, count, colorHex) {
        const wb = document.querySelector('.workbench-wrapper');
        // เนื่องจากใช้ Anchor System จุดปล่อยจึงอยู่ตรงกลางหน้าจอเป๊ะๆ
        const startX = wb.clientWidth / 2; 
        const startY = wb.clientHeight - 150; 
        
        let r = parseInt(colorHex.slice(1,3), 16) || 255; let g = parseInt(colorHex.slice(3,5), 16) || 255; let b = parseInt(colorHex.slice(5,7), 16) || 255;
        let rgbaColor = `rgba(${r},${g},${b},0.6)`; if(type==='explosion') rgbaColor='#ef4444'; if(type==='bubble') rgbaColor='rgba(255,255,255,0.7)';
        for(let i=0; i<count; i++) particles.push(new Particle(startX, startY, type, rgbaColor));
    }
    function animateEngine() { ctx.clearRect(0,0,canvas.width,canvas.height); for(let i=particles.length-1; i>=0; i--){ let p=particles[i]; p.update(); p.draw(); if(p.life<=0) particles.splice(i,1); } requestAnimationFrame(animateEngine); }
    animateEngine();

    // =========================================
    // 📈 กราฟ Chart.js (Live Graph)
    // =========================================
    let liveChart;
    document.addEventListener("DOMContentLoaded", function() {
        const ctxChart = document.getElementById('liveChart').getContext('2d');
        liveChart = new Chart(ctxChart, {
            type: 'line',
            data: {
                labels: [0],
                datasets: [
                    { label: 'อุณหภูมิ (°C)', data: [25.0], borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', borderWidth: 2, yAxisID: 'y', fill: true, tension: 0.4 },
                    { label: 'pH Level', data: [7.0], borderColor: '#38bdf8', backgroundColor: 'rgba(56, 189, 248, 0.1)', borderWidth: 2, yAxisID: 'y1', fill: true, tension: 0.4 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { grid: { color: '#334155' }, ticks: { color: '#94a3b8' } },
                    y: { type: 'linear', display: true, position: 'left', min: 0, max: 120, grid: { color: '#334155' }, ticks: { color: '#ef4444' } },
                    y1: { type: 'linear', display: true, position: 'right', min: 0, max: 14, grid: { drawOnChartArea: false }, ticks: { color: '#38bdf8' } }
                },
                plugins: { legend: { labels: { color: '#f8fafc' } } },
                animation: { duration: 0 } 
            }
        });
    });

    // =========================================
    // 🧪 PHYSICS & LOGIC
    // =========================================
    let beakerContents = []; let totalBeakerVolume = 0; 
    let currentToolState = { id: null, name: '', state: '', color: '', amount: 0 };
    let env_temperature = 25.0; let env_ph = 7.00;
    let is_heater_on = false; let is_stirring = false; let is_boiling = false; let is_hood_closed = false; let is_spilled = false;
    let safety_hp = 100; let count_success = 0; let count_mistakes = 0; let game_over = false;
    let lab_time = 0;

    function takeDamage(amt, reason) {
        if(amt<=0 || game_over) return; safety_hp-=amt; count_mistakes++; if(safety_hp<0) safety_hp=0;
        document.getElementById('hpValueTxt').innerText = safety_hp + "%"; document.getElementById('hpBarFill').style.width = safety_hp + "%";
        let hpC = document.getElementById('hpContainer'); hpC.classList.add('hp-shake'); setTimeout(()=>hpC.classList.remove('hp-shake'),300);
        if(safety_hp>60) document.getElementById('hpBarFill').style.background = "linear-gradient(90deg, #22c55e, #10b981)"; else if(safety_hp>30) document.getElementById('hpBarFill').style.background = "linear-gradient(90deg, #eab308, #f59e0b)"; else document.getElementById('hpBarFill').style.background = "linear-gradient(90deg, #ef4444, #b91c1c)";
        playErrorSound(); if(safety_hp<=0){ game_over=true; document.getElementById('gameOverModalOverlay').style.display='flex'; addLog(`<span style="color:#ef4444; font-weight:bold;">☠️ GAME OVER: ${reason}</span>`); }
    }

    setInterval(() => {
        if(game_over) return;
        if(is_heater_on) { env_temperature += (env_temperature<100)?1.5:0; if(env_temperature>=95 && totalBeakerVolume>0 && !is_boiling){ is_boiling=true; spawnParticles('bubble',2,'#ffffff'); } document.getElementById('valTemp').classList.add('hot'); } 
        else { env_temperature -= (env_temperature>25)?0.5:0; if(env_temperature<95 && is_boiling) is_boiling=false; if(env_temperature<50) document.getElementById('valTemp').classList.remove('hot'); }
        document.getElementById('valTemp').innerText = env_temperature.toFixed(1) + " °C";
        let phDOM = document.getElementById('valPh'); phDOM.innerText = env_ph.toFixed(2);
        if(env_ph<6) phDOM.className="sensor-value val-ph acid"; else if(env_ph>8) phDOM.className="sensor-value val-ph base"; else phDOM.className="sensor-value val-ph";

        lab_time += 0.5;
        if(typeof liveChart !== 'undefined') {
            if(liveChart.data.labels.length > 60) { liveChart.data.labels.shift(); liveChart.data.datasets[0].data.shift(); liveChart.data.datasets[1].data.shift(); }
            liveChart.data.labels.push(lab_time); liveChart.data.datasets[0].data.push(env_temperature); liveChart.data.datasets[1].data.push(env_ph);
            liveChart.update();
        }
    }, 500);

    function toggleHeater() { playClick(); is_heater_on = !is_heater_on; let btn = document.getElementById('btnHeater'); let flame = document.getElementById('flameFire'); if (is_heater_on) { btn.classList.add('active-heat'); document.getElementById('txtHeater').innerText = "ปิดไฟ"; flame.style.display = "flex"; toggleFireSound(true); addLog("🔥 เปิดไฟ"); } else { btn.classList.remove('active-heat'); document.getElementById('txtHeater').innerText = "ต้มสาร"; flame.style.display = "none"; toggleFireSound(false); addLog("💨 ปิดไฟ"); } }
    function toggleStir() { playClick(); is_stirring = !is_stirring; let btn = document.getElementById('btnStir'); let rod = document.getElementById('stirRod'); if (is_stirring) { btn.classList.add('active-stir'); document.getElementById('txtStir').innerText = "หยุดคน"; rod.classList.add('stirring-anim'); addLog("🥄 คนสาร..."); } else { btn.classList.remove('active-stir'); document.getElementById('txtStir').innerText = "คนสาร"; rod.classList.remove('stirring-anim'); addLog("⏸️ หยุดคนสาร"); } }
    function toggleHood() { playClick(); is_hood_closed = !is_hood_closed; let btn = document.getElementById('btnHood'); let glass = document.getElementById('fumeHoodGlass'); if (is_hood_closed) { btn.classList.add('active-hood'); document.getElementById('txtHood').innerText = "ยกกระจกขึ้น"; glass.classList.add('closed'); addLog("🛡️ ดึงตู้ดูดควันลงปลอดภัย"); } else { btn.classList.remove('active-hood'); document.getElementById('txtHood').innerText = "เลื่อนตู้ดูดควันลง"; glass.classList.remove('closed'); addLog("⚠️ ยกกระจกขึ้น"); } }

    function selectChemical(id, name, state, color) { 
        playClick(); if(is_spilled || game_over) return alert("ไม่สามารถทำได้!"); 
        currentToolState = { id: id, name: name, state: state, color: color, amount: 0 }; 
        let modal = document.getElementById('toolControls');
        const wb = document.querySelector('.workbench-wrapper');
        
        // ให้หน้าต่างโผล่ตรงกลางจอเสมอ โดยลบ transform ออกจะได้ลากไม่กระตุก
        modal.style.display = 'block'; 
        modal.style.left = (wb.clientWidth / 2 - 140) + 'px'; // 140 คือครึ่งนึงของ 280px (ความกว้างหน้าต่าง)
        modal.style.top = (wb.clientHeight / 2 - 150) + 'px'; 
        
        document.getElementById('toolChemName').innerText = name; 
        document.getElementById('solidControls').style.display = state==='solid' ? 'block' : 'none'; 
        document.getElementById('liquidControls').style.display = state==='solid' ? 'none' : 'block'; 
        updateToolVisuals(); 
    }
    function addAmount(val) { playClick(); if(!currentToolState.id) return; currentToolState.amount+=val; updateToolVisuals(); }
    function resetTool() { playClick(); currentToolState.amount=0; updateToolVisuals(); }
    function cancelTool() { playClick(); document.getElementById('toolControls').style.display='none'; resetTool(); }
    function tareScale() { playClick(); if(currentToolState.state==='solid'){ currentToolState.amount=0; updateToolVisuals(); } }
    function updateToolVisuals() { if(currentToolState.state==='solid'){ document.getElementById('scaleDisplay').innerText=currentToolState.amount.toFixed(2)+" g"; let pw=document.getElementById('scalePowder'); if(currentToolState.amount>0){pw.style.width=Math.min(100,currentToolState.amount*3)+"%";pw.style.height=Math.min(30,currentToolState.amount*1)+"px";pw.style.backgroundColor=currentToolState.color;}else{pw.style.width="0%";pw.style.backgroundColor="transparent";} }else{ let lq=document.getElementById('cylinderLiquid'); lq.style.height=Math.min(100,currentToolState.amount)+"%"; lq.style.backgroundColor=currentToolState.color; } }

    function transferToBeaker() {
        if(currentToolState.amount<=0) return; playPourSound();
        beakerContents.push({ id: currentToolState.id, name: currentToolState.name, amount: currentToolState.amount, state: currentToolState.state, color: currentToolState.color });
        totalBeakerVolume += currentToolState.amount; addLog(`เท <b style="color:${currentToolState.color}">${currentToolState.name}</b>`);
        let content = document.getElementById('beakerContent');
        if(totalBeakerVolume>100){ content.style.height="100%"; is_spilled=true; let sp=document.getElementById('spillArea'); sp.style.opacity="1"; sp.style.backgroundColor=currentToolState.color; document.getElementById('btnCleanSpill').style.display="block"; takeDamage(5,"สารหกล้นบีกเกอร์!"); addLog(`<span style="color:#ef4444;">⚠️ สารหกล้น!</span>`); }
        else{ content.style.height=totalBeakerVolume+"%"; content.style.backgroundColor=currentToolState.color; }
        if(totalBeakerVolume>10) document.getElementById('stirRod').style.display='block';
        cancelTool(); if(beakerContents.length>=2 && !is_spilled) document.getElementById('btnMix').disabled=false;
    }
    function cleanSpill() { playClick(); is_spilled=false; totalBeakerVolume=100; document.getElementById('spillArea').style.opacity="0"; document.getElementById('btnCleanSpill').style.display="none"; addLog("🧻 ซับทำความสะอาดแล้ว"); if(beakerContents.length>=2) document.getElementById('btnMix').disabled=false; }

    function executeReaction() {
        playClick(); document.getElementById('btnMix').disabled=true; addLog("⏳ ประมวลผล...");
        let payload = { csrf_token: document.getElementById('csrfToken').value, action: 'mix', safety: { goggles: document.getElementById('chkGoggles').checked, gloves: document.getElementById('chkGloves').checked, fume_hood: is_hood_closed }, environment: { temperature: env_temperature, is_stirred: is_stirring }, chemicals: beakerContents };
        fetch('api_process_lab.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) })
        .then(res=>res.json()).then(data=>{
            let content=document.getElementById('beakerContent'); content.style.backgroundColor=data.color;
            env_ph=data.ph_result; if(data.temperature_change>0) env_temperature+=data.temperature_change;
            if(data.damage>0) takeDamage(data.damage, data.message);
            if(data.status==='danger'||data.is_explosion){ if(data.is_explosion){ playExplosionSound(); spawnParticles('explosion',100,'#ef4444'); spawnParticles('gas',50,'#111827'); document.getElementById('flashOverlay').classList.add('flash-anim'); content.style.height="0%"; totalBeakerVolume=0; setTimeout(()=>document.getElementById('flashOverlay').classList.remove('flash-anim'),1000); } document.getElementById('mainBeaker').classList.add('shaking'); setTimeout(()=>document.getElementById('mainBeaker').classList.remove('shaking'),500); addLog(`<span style="color:#ef4444;font-weight:bold;">💥 อันตราย: ${data.message}</span>`); }
            else if(data.status==='warning'){ addLog(`<span style="color:#f59e0b;font-weight:bold;">⚠️ ${data.message}</span>`); document.getElementById('btnMix').disabled=false; }
            else if(data.status==='success'){ playSuccessSound(); count_success++; addLog(`<span style="color:#10b981;font-weight:bold;">✅ สำเร็จ: ${data.product_name}</span>`); if(data.gas!=='ไม่มี') spawnParticles('gas',40,data.color); if(data.precipitate!=='ไม่มี') spawnParticles('precipitate',60,'#cbd5e1'); }
            else{ addLog(`ℹ️ ${data.message}`); }
        }).catch(err=>{ console.error(err); addLog('<span style="color:#ef4444;">❌ Server Error</span>'); });
    }

    function requestWash() { playClick(); if(beakerContents.length===0) return addLog("บีกเกอร์ว่างเปล่า"); document.getElementById('wasteModalOverlay').style.display='flex'; }
    function cancelWaste() { playClick(); document.getElementById('wasteModalOverlay').style.display='none'; }
    function submitWaste(method) {
        playClick(); document.getElementById('wasteModalOverlay').style.display='none';
        fetch('api_process_lab.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({csrf_token:document.getElementById('csrfToken').value,action:'dispose',method:method,ph:env_ph,chemicals:beakerContents}) })
        .then(res=>res.json()).then(data=>{
            if(data.damage>0) takeDamage(data.damage,"ทิ้งของเสียผิดวิธี!");
            if(data.status==='danger') addLog(`<span style="color:#ef4444;font-weight:bold;">❌ ${data.message}</span>`); else if(data.status==='warning') addLog(`<span style="color:#f59e0b;font-weight:bold;">⚠️ ${data.message}</span>`); else { playSuccessSound(); addLog(`<span style="color:#10b981;font-weight:bold;">♻️ ${data.message}</span>`); }
            beakerContents=[]; totalBeakerVolume=0; env_temperature=25.0; env_ph=7.00; is_boiling=false; if(is_heater_on) toggleHeater(); if(is_stirring) toggleStir(); 
            document.getElementById('beakerContent').style.height="0%"; document.getElementById('stirRod').style.display="none"; document.getElementById('btnMix').disabled=true;
        });
    }

    function submitFinalReport() {
        if(game_over) return alert("คุณทำแล็บล้มเหลวไปแล้ว"); playClick();
        if(count_success===0 && safety_hp===100) return alert("ยังไม่ได้ทำการทดลองเลย");
        if(!confirm("ส่งรายงานเพื่อประเมินคะแนน?")) return;
        addLog("📄 กำลังส่งข้อมูล...");
        fetch('api_process_lab.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({csrf_token:document.getElementById('csrfToken').value,action:'submit_report',hp:safety_hp,success_count:count_success,mistakes_count:count_mistakes}) })
        .then(res=>res.json()).then(data=>{
            if(data.status==='success'){ playSuccessSound(); document.getElementById('gradeModalOverlay').style.display='flex'; document.getElementById('finalScore').innerText=data.score; document.getElementById('finalGrade').innerText=data.grade; document.getElementById('finalGrade').className="grade-circle grade-"+data.grade; document.getElementById('gradeFeedback').innerText=data.feedback; game_over=true; }
        });
    }
    function addLog(text) { let logZone=document.getElementById('systemLog'); let t=new Date().toLocaleTimeString('th-TH'); logZone.innerHTML+=`<div class="log-entry"><span class="log-time">[${t}]</span> ${text}</div>`; logZone.scrollTop=logZone.scrollHeight; }
</script>

<?php require_once 'footer.php'; ?>