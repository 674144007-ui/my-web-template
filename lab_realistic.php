<?php
/**
 * lab_realistic.php - ห้องทดลองเคมีเสมือนจริง (Ultimate Full Version - Phase 7 Environment & Lighting)
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 */

require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
requireLogin();

$page_title = "ห้องทดลองเคมีเสมือนจริง";
$csrf = generate_csrf_token(); 

// ดึงข้อมูลสารเคมีแยกของแข็งและของเหลว
$solids = []; $liquids = [];
$check_col = $conn->query("SHOW COLUMNS FROM chemicals LIKE 'formula'");
$has_formula = ($check_col && $check_col->num_rows > 0);
$res = $conn->query("SELECT id, name, " . ($has_formula ? "formula" : "'' as formula") . ", state, color_neutral FROM chemicals ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if ($row['state'] === 'solid') $solids[] = $row; else $liquids[] = $row;
    }
}
require_once 'header.php';
?>

<link rel="icon" href="data:;base64,iVBORw0KGgo=">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@400;700&family=Itim&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<style>
    /* ============================================================
       📍 CORE STRUCTURE & UI 
       ============================================================ */
    .container { max-width: none !important; width: 100% !important; margin: 0 !important; padding: 0 !important; }
    body { background-color: #020617; color: #f8fafc; font-family: 'Itim', cursive, system-ui; margin: 0 !important; padding: 0 !important; overflow: hidden; user-select: none; }
    
    .lab-subheader { background: #0f172a; border-bottom: 2px solid #1e293b; padding: 10px 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 20px rgba(0,0,0,0.6); z-index: 1000; position: sticky; top: 0; }
    .lab-title { font-size: 1.2rem; font-weight: bold; color: #38bdf8; display: flex; align-items: center; gap: 12px; }

    .btn-audio { background: #334155; color: #38bdf8; border: 1px solid #475569; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-family: 'Itim'; font-size: 1rem; display: flex; align-items: center; gap: 8px; transition: 0.3s; }
    .btn-audio:hover { background: #475569; }
    .btn-audio.muted { color: #ef4444; border-color: #ef4444; }

    .hp-wrapper { display: flex; align-items: center; gap: 15px; background: rgba(15, 23, 42, 0.8); padding: 6px 20px; border-radius: 30px; border: 1px solid #334155; width: 250px; }
    .hp-label { font-weight: bold; font-size: 1rem; color: #94a3b8; }
    .hp-bar-bg { flex: 1; height: 12px; background: #1e293b; border-radius: 10px; overflow: hidden; border: 1px solid #000; }
    .hp-bar-fill { height: 100%; width: 100%; background: linear-gradient(90deg, #22c55e, #10b981); transition: width 0.5s, background 0.3s; }
    .hp-value { font-family: 'Share Tech Mono', monospace; font-size: 1.1rem; color: #22c55e; min-width: 45px; text-align: right; }
    @keyframes shakeHP { 0%, 100% { transform: translateX(0); } 20%, 60% { transform: translateX(-8px); } 40%, 80% { transform: translateX(8px); } }
    .hp-shake { animation: shakeHP 0.4s; }

    .lab-main-container { display: flex; height: calc(100vh - 70px); width: 100vw; position: relative; background: #020617; }
    .panel-side { width: 320px; height: 100%; z-index: 500; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); flex-shrink: 0; }
    .panel-side.collapsed { width: 0; }
    .panel-inner { width: 320px; height: 100%; background: #1e293b; display: flex; flex-direction: column; transition: transform 0.4s; position: relative; }
    .panel-left .panel-inner { border-right: 2px solid #334155; }
    .panel-right .panel-inner { border-left: 2px solid #334155; }
    .panel-left.collapsed .panel-inner { transform: translateX(-100%); }
    .panel-right.collapsed .panel-inner { transform: translateX(100%); }
    .panel-content { padding: 20px; overflow-y: auto; flex: 1; scrollbar-width: thin; }
    
    .btn-toggle-panel { position: absolute; top: 20px; width: 40px; height: 50px; background: #3b82f6; color: white; border: none; cursor: pointer; font-size: 1.3rem; display: flex; align-items: center; justify-content: center; z-index: 600; box-shadow: 0 4px 15px rgba(0,0,0,0.4); transition: 0.3s; }
    .btn-toggle-left { right: -40px; border-radius: 0 10px 10px 0; }
    .btn-toggle-right { left: -40px; border-radius: 10px 0 0 10px; background: #8b5cf6; }

    /* ============================================================
       🛠️ PHYSICS ALIGNMENT & PHASE 7 ENVIRONMENT
       ============================================================ */
    .workbench-wrapper { flex: 1; height: 100%; position: relative; overflow-x: auto; overflow-y: hidden; background: radial-gradient(circle at center, #1e293b 0%, #020617 100%); scrollbar-width: thin; }
    .workbench-inner { min-width: 1200px; width: 100%; height: 100%; position: relative; margin: 0; }
    
    /* Phase 7: Ambient Lighting */
    .ambient-lighting { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; transition: background 1s, opacity 1s; opacity: 0; background: radial-gradient(circle at 50% 80%, rgba(251, 146, 60, 0.15) 0%, transparent 50%); }
    .heating-active .ambient-lighting { opacity: 1; }

    /* โต๊ะทดลอง (Phase 6.5 Anchor System) */
    .desk-surface { position: absolute; bottom: 0; left: 0; width: 100%; height: 25vh; min-height: 180px; background: linear-gradient(to bottom, #1e293b 0%, #020617 100%); border-top: 6px solid #475569; z-index: 1; transition: box-shadow 1s; }
    .heating-active .desk-surface { box-shadow: inset 0 60px 100px -20px rgba(249, 115, 22, 0.1); }
    
    .desk-anchor { position: absolute; bottom: calc(25vh - 30px); left: 50%; width: 0; height: 0; z-index: 10; pointer-events: none; }
    .desk-anchor > * { pointer-events: auto; position: absolute; }

    /* Phase 7: Fume Hood Vent Graphic */
    .fume-hood-vent { position: absolute; top: -400px; right: -200px; width: 200px; height: 50px; background: #0f172a; border: 3px solid #334155; border-radius: 8px; z-index: 5; display: flex; gap: 8px; padding: 8px; box-shadow: 0 20px 40px rgba(0,0,0,0.8); opacity: 0.5; transition: 0.3s; transform: perspective(500px) rotateX(-20deg); }
    .fume-hood-vent.active { opacity: 1; border-color: #38bdf8; box-shadow: 0 20px 40px rgba(56, 189, 248, 0.2), inset 0 0 20px rgba(56, 189, 248, 0.2); }
    .vent-slit { flex: 1; background: #020617; border-radius: 3px; box-shadow: inset 0 5px 10px #000; }

    /* 1. ฐานตะเกียง */
    .heater-base { bottom: 0px; left: -130px; width: 260px; height: 40px; background: #334155; border-radius: 8px; border: 2px solid #1e293b; box-shadow: 0 12px 20px rgba(0,0,0,0.8); z-index: 10; }
    
    /* 2. เปลวไฟ */
    .flame-container { bottom: 40px; left: -40px; width: 80px; height: 60px; display: none; justify-content: center; align-items: flex-end; z-index: 12; pointer-events: none;}
    .flame { width: 50px; height: 50px; background: radial-gradient(circle at center, #fbbf24 0%, #ef4444 60%, transparent 100%); border-radius: 50% 50% 20% 20%; animation: flicker 0.1s infinite alternate; opacity: 0.9; filter: blur(3px); box-shadow: 0 -15px 30px rgba(239, 68, 68, 0.6); }
    @keyframes flicker { 0% { transform: scale(1) translateY(0); } 100% { transform: scale(1.2) translateY(-10px); } }

    /* 3. บีกเกอร์หลัก */
    .main-beaker { bottom: 40px; left: -100px; width: 200px; height: 240px; border: 4px solid rgba(255,255,255,0.7); border-top: none; border-radius: 0 0 30px 30px; background: rgba(255,255,255,0.08); display: flex; align-items: flex-end; overflow: hidden; box-shadow: inset 0 -15px 40px rgba(0,0,0,0.5); z-index: 20; transition: border-color 0.3s, transform 0.3s, box-shadow 1s; cursor: pointer; }
    .main-beaker:hover { border-color: #38bdf8; box-shadow: 0 0 20px rgba(56, 189, 248, 0.4), inset 0 -15px 40px rgba(0,0,0,0.5); }
    .main-beaker.drag-over { border-color: #4ade80; background: rgba(74, 222, 128, 0.2); transform: scale(1.05); } 
    .heating-active .main-beaker { box-shadow: inset 0 -15px 40px rgba(0,0,0,0.5), 0 15px 40px rgba(249, 115, 22, 0.4); border-bottom-color: rgba(251, 146, 60, 0.8); } /* Phase 7 Light */
    
    /* ของเหลวและเอฟเฟกต์ในบีกเกอร์ */
    .liquid-canvas { position: absolute; bottom: 0; left: 0; width: 100%; height: 100%; z-index: 2; border-radius: 0 0 25px 25px; pointer-events: none; }
    .residue-layer { width: 100%; height: 0px; background: repeating-linear-gradient(45deg, #475569, #475569 5px, #334155 5px, #334155 10px); position: absolute; bottom: 0; left: 0; z-index: 3; transition: 0.8s; opacity: 0; border-radius: 0 0 25px 25px; pointer-events: none; }
    
    /* Phase 7: Wipable Frost Effect */
    .beaker-frost { 
        position: absolute; top:0; left:0; width: 100%; height: 100%; 
        background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.4"><path d="M10,10 L20,20 M80,80 L90,90 M20,80 L30,70" stroke="white" stroke-width="2"/></svg>'); 
        background-size: 40px 40px; opacity: 0; transition: opacity 1.5s; z-index: 25; pointer-events: none; 
        -webkit-mask-image: radial-gradient(circle at var(--wipe-x, -100px) var(--wipe-y, -100px), transparent var(--wipe-size, 0px), black calc(var(--wipe-size, 0px) + 30px));
        mask-image: radial-gradient(circle at var(--wipe-x, -100px) var(--wipe-y, -100px), transparent var(--wipe-size, 0px), black calc(var(--wipe-size, 0px) + 30px));
    }
    .beaker-frost.active { opacity: 1; filter: drop-shadow(0 0 10px rgba(255,255,255,0.5)); }

    /* แท่งแก้วคนสาร */
    .stirring-rod { position: absolute; top: -120px; left: 45%; width: 10px; height: 350px; background: linear-gradient(to right, rgba(255,255,255,0.9), rgba(255,255,255,0.4)); border-radius: 10px; z-index: 30; display: none; transform-origin: top center; box-shadow: 2px 0 10px rgba(0,0,0,0.3); }
    .stirring-anim { display: block; animation: stirAction 0.6s infinite linear; }
    @keyframes stirAction { 0% { transform: rotate(-12deg) translateX(-25px); } 50% { transform: rotate(12deg) translateX(25px); } 100% { transform: rotate(-12deg) translateX(-25px); } }

    /* 🌡️ Visual Sensors */
    .visual-thermometer { bottom: 40px; left: -140px; width: 16px; height: 200px; background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.5); border-radius: 10px; z-index: 15; display: flex; flex-direction: column; justify-content: flex-end; padding: 2px; }
    .thermo-mercury { width: 100%; height: 15%; background: linear-gradient(to top, #ef4444, #f87171); border-radius: 8px; transition: height 0.5s; }
    .thermo-bulb { position: absolute; bottom: -15px; left: -6px; width: 24px; height: 24px; background: #ef4444; border-radius: 50%; border: 2px solid rgba(255,255,255,0.5); box-shadow: 0 0 10px rgba(239,68,68,0.5); }
    
    .visual-ph-strip { bottom: 40px; left: 120px; width: 20px; height: 150px; background: #fef08a; border: 1px solid #ca8a04; border-radius: 2px; z-index: 15; overflow: hidden; display: flex; flex-direction: column; justify-content: flex-end; box-shadow: 2px 5px 10px rgba(0,0,0,0.3); }
    .ph-color-indicator { width: 100%; height: 30%; background: #22c55e; transition: background 1s, height 1s; opacity: 0.9; }

    /* 4. ตาชั่งดิจิตอล */
    .digital-scale { bottom: -20px; left: -400px; width: 220px; height: 140px; background: #94a3b8; border-radius: 12px; border: 3px solid #64748b; box-shadow: 0 15px 25px rgba(0,0,0,0.8); display: flex; flex-direction: column; align-items: center; z-index: 15; cursor: pointer; }
    .scale-plate { width: 160px; height: 18px; background: #cbd5e1; border-radius: 50%; border: 2px solid #64748b; margin: -10px 0 15px 0; position: relative; z-index: 2; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    .scale-display { background: #020617; color: #22c55e; font-family: 'Share Tech Mono', monospace; font-size: 1.8rem; padding: 5px 15px; border-radius: 8px; width: 85%; text-align: right; margin-bottom: 12px; box-shadow: inset 0 0 15px #000; border: 1px solid #334155; box-sizing: border-box; }
    
    /* 5. กระบอกตวง */
    .cylinder-container { bottom: -20px; left: 240px; width: 60px; height: 220px; border: 4px solid rgba(255,255,255,0.5); border-top: none; border-radius: 0 0 30px 30px; background: rgba(255,255,255,0.05); display: flex; align-items: flex-end; overflow: hidden; z-index: 15; }
    .cylinder-liquid { width: 100%; height: 0%; transition: 0.8s cubic-bezier(0.4, 0, 0.2, 1); }

    /* 6. คราบสารหก */
    .spill-area { bottom: -40px; left: -150px; width: 300px; height: 60px; background: transparent; border-radius: 50%; z-index: 5; opacity: 0; filter: blur(10px); transition: 0.5s; pointer-events: none; }
    .btn-clean-spill { bottom: -20px; left: -100px; width: 200px; z-index: 50; background: #eab308; color: #1e293b; padding: 12px; border: none; border-radius: 10px; font-weight: bold; cursor: pointer; display: none; }

    /* ============================================================
       📍 UI COMPONENTS (Sensors, Inventory, Logs)
       ============================================================ */
    .sensor-panel { position: absolute; top: 25px; right: 25px; z-index: 400; background: rgba(15, 23, 42, 0.9); border: 1px solid #334155; border-radius: 15px; padding: 20px; display: flex; flex-direction: column; gap: 15px; backdrop-filter: blur(8px); }
    .sensor-box { background: #020617; border: 1px solid #1e293b; padding: 10px 15px; border-radius: 10px; text-align: center; min-width: 120px; }
    .sensor-label { color: #64748b; font-size: 0.8rem; margin-bottom: 5px; text-transform: uppercase; }
    .sensor-value { font-family: 'Orbitron', sans-serif; font-size: 1.6rem; font-weight: bold; transition: color 0.5s; }
    .val-temp { color: #38bdf8; } .val-temp.hot { color: #ef4444; text-shadow: 0 0 15px rgba(239, 68, 68, 0.7); } .val-temp.cold { color: #6ee7b7; text-shadow: 0 0 15px rgba(110, 231, 183, 0.7); }
    .val-ph { color: #22c55e; }

    .search-box { position: sticky; top: 0; background: #1e293b; padding-bottom: 15px; border-bottom: 1px solid #334155; z-index: 10; }
    .search-box input { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #475569; background: #0f172a; color: white; margin-bottom: 10px; box-sizing: border-box; }
    .chem-list { display: flex; flex-direction: column; gap: 8px; margin-top: 15px; }
    
    /* Draggable items */
    .chem-item { background: #0f172a; padding: 12px; border-radius: 10px; cursor: grab; border: 1px solid #334155; display: flex; align-items: center; gap: 15px; transition: 0.2s; }
    .chem-item:hover { border-color: #38bdf8; transform: translateX(5px); background: #1e293b; }
    .chem-item:active { cursor: grabbing; transform: scale(0.95); }
    .chem-color-indicator { width: 18px; height: 18px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.3); }
    .chem-name { font-size: 1rem; font-weight: bold; flex: 1; pointer-events: none; }
    .chem-formula { font-family: 'Share Tech Mono'; font-size: 0.8rem; color: #38bdf8; pointer-events: none; }

    .log-zone { flex: 1; background: #0f172a; border: 1px solid #334155; border-radius: 10px; padding: 15px; overflow-y: auto; font-family: 'Share Tech Mono', monospace; font-size: 0.9rem; color: #cbd5e1; margin: 15px 0; }
    .log-entry { margin-bottom: 8px; border-bottom: 1px solid #1e293b; padding-bottom: 5px; }
    .log-time { color: #64748b; font-size: 0.75rem; margin-right: 8px; }
    
    .btn-mix { background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; border: none; padding: 15px; border-radius: 12px; font-weight: bold; cursor: pointer; width: 100%; font-size: 1.2rem; transition: 0.3s; }
    .btn-mix:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(139, 92, 246, 0.6); }
    .btn-mix:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(1); }

    /* Modals & Tools */
    .tool-controls { position: fixed; z-index: 2000; background: rgba(15, 23, 42, 0.98); padding: 0 0 20px 0; border-radius: 15px; border: 2px solid #38bdf8; display: none; width: 300px; box-shadow: 0 20px 50px rgba(0,0,0,0.9); }
    .drag-handle { padding: 12px; background: rgba(56, 189, 248, 0.2); border-radius: 13px 13px 0 0; cursor: move; border-bottom: 1px solid #334155; text-align: center; }
    .btn-transfer { background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px; width: 100%; font-weight: bold; margin-top: 15px; cursor: pointer; }

    /* Quest System */
    .btn-quest-board { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; border: none; padding: 8px 18px; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: 'Itim'; font-size: 1rem; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.4); display: flex; align-items: center; gap: 8px; transition: 0.3s; }
    .btn-quest-board:hover { transform: scale(1.05); }

    .quest-modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 4000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(8px); }
    .quest-modal-content { background: #1e293b; width: 90%; max-width: 800px; max-height: 85vh; border-radius: 20px; border: 2px solid #f59e0b; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.8); }
    .quest-modal-header { background: #0f172a; padding: 20px; font-size: 1.5rem; font-weight: bold; color: #fcd34d; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
    .quest-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
    .quest-card { background: #0f172a; border: 1px solid #334155; border-radius: 12px; padding: 20px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; transition: 0.3s; }
    .quest-card:hover { border-color: #f59e0b; background: #172033; transform: translateX(5px); }
    .qc-info h3 { margin: 0 0 5px 0; color: #fff; font-size: 1.2rem; }
    .qc-info p { margin: 0; color: #94a3b8; font-size: 0.95rem; }
    .qc-badge { background: rgba(245, 158, 11, 0.2); color: #fbbf24; padding: 4px 10px; border-radius: 6px; font-size: 0.8rem; font-family: 'Share Tech Mono'; margin-top: 10px; display: inline-block; }
    .btn-accept-quest { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem; }
    
    .quest-hud { position: absolute; top: 25px; left: 340px; width: 280px; background: rgba(15, 23, 42, 0.85); border: 2px solid #f59e0b; border-radius: 12px; padding: 15px; z-index: 300; box-shadow: 0 10px 30px rgba(0,0,0,0.6); backdrop-filter: blur(8px); display: none; transition: 0.4s; }
    .qh-header { border-bottom: 1px dashed #ca8a04; padding-bottom: 10px; margin-bottom: 12px; }
    .qh-title { color: #fef08a; font-weight: bold; font-size: 1.1rem; margin-bottom: 5px; }
    .qh-reward { color: #38bdf8; font-family: 'Share Tech Mono'; font-size: 0.9rem; }
    .qh-checklist { list-style: none; padding: 0; margin: 0; }
    .qh-item { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; font-size: 0.9rem; color: #cbd5e1; transition: 0.3s; }
    .qh-icon { font-size: 1.1rem; flex-shrink: 0; }
    .qh-item.done { color: #4ade80; opacity: 0.8; }
    .qh-item.done .qh-icon::before { content: '✅'; }
    .qh-item.pending .qh-icon::before { content: '⬜'; }
    .qh-item.failed { color: #ef4444; }
    .qh-item.failed .qh-icon::before { content: '❌'; }
    
    .btn-submit-quest { background: linear-gradient(135deg, #22c55e, #16a34a); color: white; border: none; width: 100%; padding: 12px; border-radius: 8px; margin-top: 15px; font-weight: bold; cursor: pointer; font-family: 'Itim'; font-size: 1.1rem; box-shadow: 0 4px 15px rgba(34, 197, 94, 0.4); display: none; animation: pulseBtn 1.5s infinite; }
    @keyframes pulseBtn { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
    
    /* Victory Modal Phase 4 */
    .modal-victory { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(2, 6, 23, 0.95); z-index: 5000; display: none; flex-direction: column; align-items: center; justify-content: center; backdrop-filter: blur(10px); }
    .victory-box { background: #0f172a; border: 2px solid #38bdf8; border-radius: 20px; padding: 40px; text-align: center; max-width: 500px; box-shadow: 0 0 50px rgba(56, 189, 248, 0.3); transform: scale(0.8); opacity: 0; transition: 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
    .victory-box.show { transform: scale(1); opacity: 1; }
    .grade-stamp { font-family: 'Orbitron'; font-size: 5rem; font-weight: bold; margin: 20px 0; text-shadow: 0 0 20px currentColor; }
    .grade-A { color: #f59e0b; } .grade-B { color: #38bdf8; } .grade-C { color: #22c55e; } .grade-D { color: #a855f7; } .grade-F { color: #ef4444; }
</style>

<div class="lab-subheader">
    <div class="lab-title">
        <span style="font-size: 1.8rem;">🧪</span> 
        <div>
            <div style="font-weight: 800;">Virtual Chemistry Lab</div>
            <div style="font-size: 0.7rem; color: #64748b; font-family: 'Orbitron';">Powered by Bankha System v2.6.5 [Phase 7: Environment & FX]</div>
        </div>
    </div>
    
    <div style="display: flex; gap: 15px; align-items: center;">
        <button class="btn-audio" id="btnToggleAudio" onclick="toggleGlobalAudio()">🔊 ระบบเสียง</button>
        <button class="btn-quest-board" onclick="openQuestBoard()">📋 กระดานภารกิจ</button>
        
        <div class="hp-wrapper" id="hpContainer">
            <div class="hp-label">HP:</div>
            <div class="hp-bar-bg"><div class="hp-bar-fill" id="hpBarFill"></div></div>
            <div class="hp-value" id="hpValueTxt">100%</div>
        </div>

        <a href="dashboard_student.php" class="btn-dashboard-back" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s;">✕ ออก</a>
    </div>
</div>

<div class="lab-main-container">
    
    <div class="panel-side panel-left" id="panelLeft">
        <div class="panel-inner">
            <div class="panel-content">
                <div class="search-box">
                    <input type="text" id="chemSearch" placeholder="🔍 ค้นหาสารเคมี..." onkeyup="filterInventory()">
                </div>

                <h4 style="color:#94a3b8; margin-top:20px; font-size:0.9rem;">🧊 สถานะของแข็ง (ลากเพื่อใช้งาน)</h4>
                <div class="chem-list" id="solidList">
                    <?php foreach ($solids as $s): ?>
                        <div class="chem-item" draggable="true" ondragstart="dragChem(event, <?= $s['id'] ?>, '<?= h($s['name']) ?>', 'solid', '<?= $s['color_neutral'] ?>')" onclick="prepareChemical(<?= $s['id'] ?>, '<?= h($s['name']) ?>', 'solid', '<?= $s['color_neutral'] ?>')">
                            <div class="chem-color-indicator" style="background-color: <?= h($s['color_neutral']) ?>;"></div>
                            <div style="flex: 1;">
                                <div class="chem-name"><?= h($s['name']) ?></div>
                                <div class="chem-formula"><?= h($s['formula']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h4 style="color:#94a3b8; margin-top:20px; font-size:0.9rem;">💧 สถานะของเหลว (ลากเพื่อใช้งาน)</h4>
                <div class="chem-list" id="liquidList">
                    <?php foreach ($liquids as $l): ?>
                        <div class="chem-item" draggable="true" ondragstart="dragChem(event, <?= $l['id'] ?>, '<?= h($l['name']) ?>', 'liquid', '<?= $l['color_neutral'] ?>')" onclick="prepareChemical(<?= $l['id'] ?>, '<?= h($l['name']) ?>', 'liquid', '<?= $l['color_neutral'] ?>')">
                            <div class="chem-color-indicator" style="background-color: <?= h($l['color_neutral']) ?>;"></div>
                            <div style="flex: 1;">
                                <div class="chem-name"><?= h($l['name']) ?></div>
                                <div class="chem-formula"><?= h($l['formula']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button class="btn-toggle-panel btn-toggle-left" onclick="togglePanel('panelLeft')">◀</button>
    </div>

    <div class="workbench-wrapper" id="workbenchScroll">
        <div class="ambient-lighting" id="ambientLight"></div> <div class="workbench-inner">
            <canvas id="fxCanvas" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:40;"></canvas>
            
            <div class="quest-hud" id="questHUD">
                <div class="qh-header">
                    <div class="qh-title" id="hudTitle">ชื่อภารกิจ</div>
                    <div class="qh-reward" id="hudReward">XP: 0</div>
                </div>
                <ul class="qh-checklist" id="hudChecklist"></ul>
                <button class="btn-submit-quest" id="btnSubmitQuest" onclick="submitQuestReport()">🚀 ส่งผลการทดลอง!</button>
                <button class="btn-abandon" onclick="abandonQuest()" style="background:none; border:1px solid #ef4444; color:#ef4444; width:100%; padding:8px; border-radius:6px; margin-top:10px;">ยกเลิกภารกิจ</button>
            </div>

            <div class="sensor-panel">
                <div class="sensor-box"><div class="sensor-label">TEMPERATURE</div><div class="sensor-value val-temp" id="valTemp">25.0 °C</div></div>
                <div class="sensor-box"><div class="sensor-label">PH LEVEL</div><div class="sensor-value val-ph" id="valPh">7.00</div></div>
            </div>

            <div class="desk-surface"></div>

            <div class="desk-anchor" id="labAnchor">
                
                <div class="fume-hood-vent" id="fumeHoodVent">
                    <div class="vent-slit"></div><div class="vent-slit"></div><div class="vent-slit"></div><div class="vent-slit"></div><div class="vent-slit"></div>
                </div>

                <div class="visual-thermometer" id="visualThermo">
                    <div class="thermo-mercury" id="thermoBar"></div>
                    <div class="thermo-bulb"></div>
                </div>
                
                <div class="visual-ph-strip" id="visualPh">
                    <div class="ph-color-indicator" id="phStripBar"></div>
                </div>

                <div class="digital-scale" id="scaleObj" onclick="playAudio('click')">
                    <div class="scale-plate"><div id="scalePowder" style="width:0; height:0; background:transparent; margin:0 auto; border-radius:50%; transition:0.3s;"></div></div>
                    <div class="scale-display" id="scaleDisplay">0.00 g</div>
                    <button onclick="event.stopPropagation(); tareScale();" style="background:#475569; color:white; border:none; padding:5px 15px; border-radius:5px; cursor:pointer; font-weight:bold; font-size: 0.8rem;">TARE</button>
                </div>

                <div class="main-beaker" id="beakerObj" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropChem(event)" onclick="playAudio('glassClink')">
                    <div class="beaker-frost" id="beakerFrost"></div>
                    <div class="residue-layer" id="residueLayer"></div>
                    <div class="stirring-rod" id="stirRod"></div>
                    
                    <canvas id="liquidCanvas" class="liquid-canvas" width="192" height="240"></canvas>
                </div>

                <div class="flame-container" id="flameFire"><div class="flame"></div></div>
                <div class="heater-base"></div>

                <div class="spill-area" id="spillEffect"></div>
                <button class="btn-clean-spill" id="btnClean" onclick="cleanSpill()">🧻 เช็ดคราบสารหก</button>

                <div class="cylinder-container" id="cylinderObj">
                    <div class="cylinder-liquid" id="cylinderFill"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="panel-side panel-right" id="panelRight">
        <button class="btn-toggle-panel btn-toggle-right" onclick="togglePanel('panelRight')">▶</button>
        <div class="panel-inner">
            <div class="panel-content">
                <h3 style="color:#38bdf8; margin:0 0 15px 0; border-bottom: 1px solid #334155; padding-bottom: 10px;">🎮 Lab Controls</h3>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:15px;">
                    <button class="btn-mix" id="btnHeater" onclick="toggleHeater()" style="background:#334155; font-size:1rem;">🔥 ต้มสาร</button>
                    <button class="btn-mix" id="btnStir" onclick="toggleStir()" style="background:#334155; font-size:1rem;">🥄 คนสาร</button>
                </div>

                <button class="btn-mix" id="btnFan" onclick="toggleFan()" style="background:#334155; font-size:1rem; margin-bottom:15px;">💨 เปิดพัดลมดูดควัน</button> <div style="background:rgba(239, 68, 68, 0.1); border:1px dashed #ef4444; padding:15px; border-radius:10px; margin-bottom:15px;">
                    <label style="display:flex; align-items: center; gap: 10px; margin-bottom:10px; color:#fca5a5; cursor:pointer;">
                        <input type="checkbox" id="chkGoggles" style="width: 18px; height: 18px;" onchange="audio.play('click'); updateQuestHUD();"> 🥽 สวมแว่นตานิรภัย
                    </label>
                    <label style="display:flex; align-items: center; gap: 10px; color:#fca5a5; cursor:pointer;">
                        <input type="checkbox" id="chkGloves" style="width: 18px; height: 18px;" onchange="audio.play('click'); updateQuestHUD();"> 🧤 สวมถุงมือยาง
                    </label>
                </div>

                <div class="log-zone" id="labLog">
                    <div class="log-entry"><span class="log-time"><?= date('H:i') ?></span> ระบบแสงและสภาพแวดล้อมทำงาน (Phase 7)</div>
                </div>

                <button class="btn-mix" id="btnProcess" onclick="mixChemicals()" disabled style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">⚗️ ประมวลผลปฏิกิริยา</button>
                <button onclick="washBeaker()" style="width:100%; background:#475569; color:white; border:none; padding:12px; border-radius:10px; margin-top:10px; cursor:pointer; font-weight: bold;">🗑️ เททิ้งและล้างบีกเกอร์</button>
            </div>
        </div>
    </div>
</div>

<div class="tool-controls" id="toolBox">
    <div class="drag-handle" id="toolHandle"><span id="toolTitle" style="color:#38bdf8; font-weight:bold;">เลือกปริมาณสาร</span></div>
    <div style="padding:20px;">
        <div id="solidUI" style="display:none;">
            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <button onclick="changeAmt(1)" style="flex:1; padding:10px; border-radius:8px; background:#0f172a; color:white; cursor:pointer;">+1 g</button>
                <button onclick="changeAmt(5)" style="flex:1; padding:10px; border-radius:8px; background:#0f172a; color:white; cursor:pointer;">+5 g</button>
                <button onclick="changeAmt(10)" style="flex:1; padding:10px; border-radius:8px; background:#0f172a; color:white; cursor:pointer;">+10 g</button>
            </div>
        </div>
        <div id="liquidUI" style="display:none;">
            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <button onclick="changeAmt(5)" style="flex:1; padding:10px; border-radius:8px; background:#0f172a; color:white; cursor:pointer;">+5 ml</button>
                <button onclick="changeAmt(10)" style="flex:1; padding:10px; border-radius:8px; background:#0f172a; color:white; cursor:pointer;">+10 ml</button>
                <button onclick="changeAmt(50)" style="flex:1; padding:10px; border-radius:8px; background:#0f172a; color:white; cursor:pointer;">+50 ml</button>
            </div>
        </div>
        <button class="btn-transfer" onclick="pourToBeaker()">⬇️ ถ่ายลงบีกเกอร์</button>
        <button onclick="closeTool()" style="width:100%; background:none; border:none; color:#64748b; margin-top:15px; cursor:pointer;">ยกเลิก</button>
    </div>
</div>

<div class="quest-modal-overlay" id="questBoardModal">
    <div class="quest-modal-content">
        <div class="quest-modal-header">
            <span>📋 กระดานรับภารกิจ (Quest Board)</span>
            <button onclick="closeQuestBoard()" style="background:none; border:none; color:#94a3b8; font-size:1.5rem; cursor:pointer;">✕</button>
        </div>
        <div class="quest-modal-body" id="questListBody"></div>
    </div>
</div>

<div class="modal-victory" id="victoryModal">
    <div class="victory-box" id="victoryBox">
        <h2 style="color:white; font-size:2rem; margin-top:0;">🎉 ภารกิจสำเร็จ! 🎉</h2>
        <p style="color:#94a3b8; font-size:1.1rem;">บันทึกรายงานการทดลองและส่งให้ครูผู้สอนเรียบร้อยแล้ว</p>
        <div class="grade-stamp grade-A" id="finalGrade">A</div>
        <div style="background:#1e293b; padding:15px; border-radius:10px; margin-bottom:20px;">
            <div style="color:#38bdf8; font-size:1.5rem; font-family:'Share Tech Mono';">ได้รับ XP: +<span id="finalXP">0</span></div>
        </div>
        <button onclick="location.href='dashboard_student.php'" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color:white; border:none; padding:15px 30px; border-radius:10px; font-size:1.2rem; font-weight:bold; cursor:pointer; width:100%;">กลับสู่หน้าหลัก</button>
    </div>
</div>

<input type="hidden" id="csrf" value="<?= h($csrf) ?>">

<script>
    /* ============================================================
       🎧 PHASE 5: THE AUDIO ENGINE
       ============================================================ */
    class AudioManager {
        constructor() {
            this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            this.masterGain = this.ctx.createGain();
            this.masterGain.gain.value = 0.5;
            this.masterGain.connect(this.ctx.destination);
            this.enabled = true;
            this.loops = { fire: null, boil: null, stir: null, fan: null };
            document.body.addEventListener('click', () => { if(this.ctx.state === 'suspended') this.ctx.resume(); }, { once: true });
        }
        toggleMute() {
            this.enabled = !this.enabled;
            this.masterGain.gain.setTargetAtTime(this.enabled ? 0.5 : 0, this.ctx.currentTime, 0.1);
            return this.enabled;
        }
        createNoiseBuffer(lengthSec = 2) {
            const bufferSize = this.ctx.sampleRate * lengthSec;
            const buffer = this.ctx.createBuffer(1, bufferSize, this.ctx.sampleRate);
            const output = buffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) output[i] = Math.random() * 2 - 1;
            return buffer;
        }
        play(type) {
            if (!this.enabled || this.ctx.state === 'suspended') return;
            const t = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.connect(gain); gain.connect(this.masterGain);

            switch(type) {
                case 'click': osc.type='sine'; osc.frequency.setValueAtTime(600, t); osc.frequency.exponentialRampToValueAtTime(800, t+0.1); gain.gain.setValueAtTime(0.3, t); gain.gain.exponentialRampToValueAtTime(0.01, t+0.1); osc.start(t); osc.stop(t+0.1); break;
                case 'glassClink': osc.type='sine'; osc.frequency.setValueAtTime(1500, t); gain.gain.setValueAtTime(0.5, t); gain.gain.exponentialRampToValueAtTime(0.01, t+0.3); osc.start(t); osc.stop(t+0.3); break;
                case 'error': osc.type='sawtooth'; osc.frequency.setValueAtTime(150, t); osc.frequency.setValueAtTime(100, t+0.1); gain.gain.setValueAtTime(0.4, t); gain.gain.linearRampToValueAtTime(0, t+0.3); osc.start(t); osc.stop(t+0.3); break;
                case 'victory': const freqs = [440, 554.37, 659.25, 880]; freqs.forEach((f, i) => { const o = this.ctx.createOscillator(); const g = this.ctx.createGain(); o.type = 'sine'; o.frequency.value = f; g.gain.setValueAtTime(0, t); g.gain.setValueAtTime(0.3, t + i * 0.1); g.gain.exponentialRampToValueAtTime(0.01, t + i * 0.1 + 0.5); o.connect(g); g.connect(this.masterGain); o.start(t + i * 0.1); o.stop(t + i * 0.1 + 0.5); }); break;
                case 'pourLiquid': const lNoise = this.ctx.createBufferSource(); lNoise.buffer = this.createNoiseBuffer(1.5); const lFilter = this.ctx.createBiquadFilter(); lFilter.type = 'lowpass'; lFilter.frequency.value = 800; gain.gain.setValueAtTime(0, t); gain.gain.linearRampToValueAtTime(0.4, t + 0.2); gain.gain.linearRampToValueAtTime(0, t + 1.2); lNoise.connect(lFilter); lFilter.connect(gain); gain.connect(this.masterGain); lNoise.start(t); break;
                case 'pourSolid': const sNoise = this.ctx.createBufferSource(); sNoise.buffer = this.createNoiseBuffer(1.5); const sFilter = this.ctx.createBiquadFilter(); sFilter.type = 'bandpass'; sFilter.frequency.value = 3000; gain.gain.setValueAtTime(0, t); gain.gain.linearRampToValueAtTime(0.5, t + 0.1); gain.gain.linearRampToValueAtTime(0, t + 0.8); sNoise.connect(sFilter); sFilter.connect(gain); gain.connect(this.masterGain); sNoise.start(t); break;
                case 'explosion': const eNoise = this.ctx.createBufferSource(); eNoise.buffer = this.createNoiseBuffer(3); const eFilter = this.ctx.createBiquadFilter(); eFilter.type = 'lowpass'; eFilter.frequency.value = 1000; gain.gain.setValueAtTime(1.0, t); gain.gain.exponentialRampToValueAtTime(0.01, t + 2.5); eNoise.connect(eFilter); eFilter.connect(gain); gain.connect(this.masterGain); eNoise.start(t); break;
                case 'wipe': const wNoise = this.ctx.createBufferSource(); wNoise.buffer = this.createNoiseBuffer(0.5); const wFilter = this.ctx.createBiquadFilter(); wFilter.type = 'bandpass'; wFilter.frequency.value = 2000; gain.gain.setValueAtTime(0, t); gain.gain.linearRampToValueAtTime(0.3, t+0.1); gain.gain.linearRampToValueAtTime(0, t+0.4); wNoise.connect(wFilter); wFilter.connect(gain); gain.connect(this.masterGain); wNoise.start(t); break;
            }
        }
        startLoop(type) {
            if (!this.enabled || this.ctx.state === 'suspended' || this.loops[type]) return;
            const t = this.ctx.currentTime;
            if (type === 'fire') { const src = this.ctx.createBufferSource(); src.buffer = this.createNoiseBuffer(2); src.loop = true; const filter = this.ctx.createBiquadFilter(); filter.type = 'lowpass'; filter.frequency.value = 300; const gain = this.ctx.createGain(); gain.gain.setValueAtTime(0, t); gain.gain.linearRampToValueAtTime(0.4, t + 1); src.connect(filter); filter.connect(gain); gain.connect(this.masterGain); src.start(t); this.loops.fire = { src, gain }; } 
            else if (type === 'stir') { this.loops.stir = setInterval(() => { this.play('glassClink'); }, 400); }
            else if (type === 'fan') { const src = this.ctx.createBufferSource(); src.buffer = this.createNoiseBuffer(2); src.loop = true; const filter = this.ctx.createBiquadFilter(); filter.type = 'lowpass'; filter.frequency.value = 800; const gain = this.ctx.createGain(); gain.gain.setValueAtTime(0, t); gain.gain.linearRampToValueAtTime(0.15, t + 1); src.connect(filter); filter.connect(gain); gain.connect(this.masterGain); src.start(t); this.loops.fan = { src, gain }; } 
        }
        stopLoop(type) {
            if (!this.loops[type]) return;
            if (type === 'fire' || type === 'fan') { const t = this.ctx.currentTime; this.loops[type].gain.gain.linearRampToValueAtTime(0, t + 0.5); setTimeout(() => { if(this.loops[type]) this.loops[type].src.stop(); this.loops[type] = null; }, 500); } 
            else if (type === 'stir') { clearInterval(this.loops.stir); this.loops.stir = null; }
        }
    }
    const audio = new AudioManager();
    function toggleGlobalAudio() { const btn = document.getElementById('btnToggleAudio'); if (audio.toggleMute()) { btn.innerHTML = '🔊 ระบบเสียง'; btn.classList.remove('muted'); audio.play('click'); } else { btn.innerHTML = '🔇 ปิดเสียง'; btn.classList.add('muted'); } }

    /* ============================================================
       🌊 PHASE 6: LIQUID CANVAS PHYSICS ENGINE 
       ============================================================ */
    function hexToRgb(hex) {
        let result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? [parseInt(result[1], 16), parseInt(result[2], 16), parseInt(result[3], 16)] : [255, 255, 255];
    }

    class LiquidPhysics {
        constructor() {
            this.canvas = document.getElementById('liquidCanvas');
            this.ctx = this.canvas.getContext('2d');
            this.numSprings = 60;
            this.springs = [];
            this.targetHeight = 0; 
            this.currentHeight = 0;
            this.color = [255,255,255,0.85];
            this.targetColor = [255,255,255,0.85];
            this.tension = 0.025;
            this.dampening = 0.025;
            this.spread = 0.25;
            this.isStirring = false;
            this.vortexPhase = 0;
            for(let i=0; i<this.numSprings; i++) { this.springs.push({ p: 0, v: 0 }); }
        }

        splash(index, speed) { if(index >= 0 && index < this.numSprings) this.springs[index].v = speed; }
        setColor(hexColor) { let rgb = hexToRgb(hexColor); this.targetColor = [rgb[0], rgb[1], rgb[2], 0.85]; }

        update() {
            let targetH = (totalVol / 100) * this.canvas.height;
            this.currentHeight += (targetH - this.currentHeight) * 0.1;

            for(let c=0; c<3; c++) { this.color[c] += (this.targetColor[c] - this.color[c]) * 0.05; }

            for(let i=0; i<this.numSprings; i++) {
                let extension = this.springs[i].p - this.targetHeight;
                let force = -this.tension * extension - this.dampening * this.springs[i].v;
                this.springs[i].v += force;
                this.springs[i].p += this.springs[i].v;
            }

            let leftDeltas = new Array(this.numSprings).fill(0);
            let rightDeltas = new Array(this.numSprings).fill(0);

            for (let passes = 0; passes < 8; passes++) {
                for(let i=0; i<this.numSprings; i++) {
                    if(i > 0) { leftDeltas[i] = this.spread * (this.springs[i].p - this.springs[i-1].p); this.springs[i-1].v += leftDeltas[i]; }
                    if(i < this.numSprings-1) { rightDeltas[i] = this.spread * (this.springs[i].p - this.springs[i+1].p); this.springs[i+1].v += rightDeltas[i]; }
                }
                for(let i=0; i<this.numSprings; i++) {
                    if(i > 0) this.springs[i-1].p += leftDeltas[i];
                    if(i < this.numSprings-1) this.springs[i+1].p += rightDeltas[i];
                }
            }

            if(this.isStirring && this.currentHeight > 20) {
                this.vortexPhase += 0.2;
                let center = Math.floor(this.numSprings / 2);
                let depth = Math.min(40, this.currentHeight * 0.5);
                for(let i=0; i<this.numSprings; i++) {
                    let dist = Math.abs(i - center);
                    let influence = Math.max(0, 1 - dist/15);
                    let wobble = Math.sin(this.vortexPhase + i*0.5) * 5 * influence;
                    this.springs[i].p -= (depth * influence * 0.1); 
                    this.springs[i].p += wobble * 0.1;
                }
            }
        }

        draw() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            if(this.currentHeight <= 1) return;

            let w = this.canvas.width / (this.numSprings - 1);
            let baseColor = `rgba(${Math.round(this.color[0])}, ${Math.round(this.color[1])}, ${Math.round(this.color[2])}, ${this.color[3]})`;
            
            this.ctx.fillStyle = baseColor;
            this.ctx.beginPath();
            this.ctx.moveTo(0, this.canvas.height);
            for(let i=0; i<this.numSprings; i++) {
                let x = i * w; let y = this.canvas.height - this.currentHeight + this.springs[i].p;
                this.ctx.lineTo(x, y);
            }
            this.ctx.lineTo(this.canvas.width, this.canvas.height);
            this.ctx.closePath();
            this.ctx.fill();

            this.ctx.strokeStyle = "rgba(255,255,255,0.4)";
            this.ctx.lineWidth = 2;
            this.ctx.beginPath();
            for(let i=0; i<this.numSprings; i++) {
                let x = i * w; let y = this.canvas.height - this.currentHeight + this.springs[i].p;
                if(i==0) this.ctx.moveTo(x,y); else this.ctx.lineTo(x,y);
            }
            this.ctx.stroke();
        }
    }
    
    const liquidSim = new LiquidPhysics();

    /* ============================================================
       📍 GLOBAL STATE & PHYSICS ENGINE
       ============================================================ */
    let hp = 100, beakerItems = [], totalVol = 0, temp = 25.0, targetTemp = 25.0, ph = 7.00;
    let currentTool = { id:0, name:'', state:'', color:'', amt:0 }, isHeating = false, isStirring = false, isSpilled = false;
    let activeQuest = null, questSpillCount = 0, unwashedResidues = [];
    let logHistory = [];
    let isFanOn = false; // Phase 7: Fan state

    // Particle Engine
    const fxCanvas = document.getElementById('fxCanvas');
    const fxCtx = fxCanvas.getContext('2d');
    let particles = [];
    function initCanvas() { fxCanvas.width = document.getElementById('workbenchScroll').scrollWidth; fxCanvas.height = document.getElementById('workbenchScroll').clientHeight; }
    
    class Particle {
        constructor(x, y, type) {
            this.x = x; this.y = y; this.type = type; this.life = 1.0;
            this.vx = (Math.random() - 0.5) * 4;
            this.vy = type === 'gas' ? -(Math.random() * 2 + 1) : (Math.random() * 5 - 2.5);
            this.size = Math.random() * 8 + 2;
        }
        update() { 
            // Phase 7: Wind from Fume Hood
            if (isFanOn && (this.type === 'gas' || this.type === 'steam')) {
                this.vx += 0.08; // Blow to the right
                this.vy -= 0.05; // Suck upwards
                this.life -= 0.015; // Dissipate faster
            }
            this.x += this.vx; this.y += this.vy; this.life -= 0.012; 
        }
        draw() {
            fxCtx.globalAlpha = this.life;
            fxCtx.fillStyle = this.type === 'explosion' ? '#ff4500' : (this.type === 'steam' ? '#ffffff' : '#a78bfa');
            fxCtx.beginPath(); fxCtx.arc(this.x, this.y, this.size, 0, Math.PI * 2); fxCtx.fill();
        }
    }

    function createParticles(type, count) {
        const rect = document.getElementById('beakerObj').getBoundingClientRect();
        const wbRect = document.getElementById('workbenchScroll').getBoundingClientRect();
        const spawnX = (rect.left - wbRect.left) + (rect.width / 2) + document.getElementById('workbenchScroll').scrollLeft;
        const spawnY = (rect.top - wbRect.top) + (rect.height / 3);
        for(let i=0; i<count; i++) particles.push(new Particle(spawnX, spawnY, type));
    }

    function animateMain() {
        fxCtx.clearRect(0, 0, fxCanvas.width, fxCanvas.height);
        particles = particles.filter(p => p.life > 0);
        particles.forEach(p => { p.update(); p.draw(); });
        
        if(totalVol > 0 && temp > 95) {
            if(Math.random() > 0.6) createParticles('steam', 1);
            if(Math.random() > 0.9) audio.play('pourLiquid'); 
        }
        
        liquidSim.update();
        liquidSim.draw();

        requestAnimationFrame(animateMain);
    }

    // Phase 7: Frost Wiper Logic
    let wipeSize = 0;
    document.getElementById('beakerObj').addEventListener('mousemove', (e) => {
        if(temp < 15 && e.buttons === 1) { // Left click and drag
            const rect = document.getElementById('beakerObj').getBoundingClientRect();
            let wipeX = e.clientX - rect.left;
            let wipeY = e.clientY - rect.top;
            wipeSize = 50;
            document.getElementById('beakerFrost').style.setProperty('--wipe-x', wipeX + 'px');
            document.getElementById('beakerFrost').style.setProperty('--wipe-y', wipeY + 'px');
            document.getElementById('beakerFrost').style.setProperty('--wipe-size', wipeSize + 'px');
            if(Math.random() > 0.8) audio.play('wipe'); 
        }
    });

    // Temp & Sensor Loop
    setInterval(() => {
        if(isHeating) targetTemp = Math.min(180, targetTemp + 1.2); 
        else { if (targetTemp > 25) targetTemp -= 0.3; else if (targetTemp < 25) targetTemp += 0.2; }

        if(temp < targetTemp) temp = Math.min(targetTemp, temp + 0.5);
        if(temp > targetTemp) temp = Math.max(targetTemp, temp - 0.5);

        const tempUI = document.getElementById('valTemp');
        if(temp > 60) { tempUI.classList.add('hot'); tempUI.classList.remove('cold'); }
        else if(temp < 15) { tempUI.classList.add('cold'); tempUI.classList.remove('hot'); }
        else tempUI.classList.remove('hot', 'cold');
        tempUI.innerText = temp.toFixed(1) + " °C";

        // Visual Thermometer Update
        let thermoHeight = Math.max(5, Math.min(100, (temp / 150) * 100)); 
        document.getElementById('thermoBar').style.height = thermoHeight + "%";

        // Phase 7: Frost Management
        if(temp < 15) {
            document.getElementById('beakerFrost').classList.add('active');
            if(wipeSize > 0) wipeSize -= 0.5; // Frost creeps back slowly
            document.getElementById('beakerFrost').style.setProperty('--wipe-size', wipeSize + 'px');
        } else {
            document.getElementById('beakerFrost').classList.remove('active');
        }

        // Boiling evaporation
        if(temp > 100 && totalVol > 0) {
            totalVol -= 0.5;
            if(totalVol <= 0) { addLog("⚠️ น้ำระเหยจนแห้ง!"); totalVol = 0; }
        }

        if(activeQuest) updateQuestHUD();
    }, 1000);

    /* ============================================================
       🖱️ DRAG AND DROP SYSTEM
       ============================================================ */
    function dragChem(ev, id, name, state, color) {
        ev.dataTransfer.setData("id", id);
        ev.dataTransfer.setData("name", name);
        ev.dataTransfer.setData("state", state);
        ev.dataTransfer.setData("color", color);
    }
    function allowDrop(ev) { ev.preventDefault(); document.getElementById('beakerObj').classList.add('drag-over'); }
    function leaveDrop(ev) { document.getElementById('beakerObj').classList.remove('drag-over'); }
    function dropChem(ev) {
        ev.preventDefault(); document.getElementById('beakerObj').classList.remove('drag-over');
        const id = ev.dataTransfer.getData("id");
        if (id) prepareChemical(id, ev.dataTransfer.getData("name"), ev.dataTransfer.getData("state"), ev.dataTransfer.getData("color"));
    }

    /* ============================================================
       📍 UI & INTERACTION LOGIC
       ============================================================ */
    function togglePanel(id) { audio.play('click'); const p = document.getElementById('panel'+id.substring(5)); p.classList.toggle('collapsed'); setTimeout(initCanvas, 400); }
    function addLog(msg) { const t = new Date().toLocaleTimeString('th-TH'); document.getElementById('labLog').innerHTML += `<div class="log-entry"><span class="log-time">[${t}]</span> ${msg}</div>`; document.getElementById('labLog').scrollTop = document.getElementById('labLog').scrollHeight; logHistory.push(`[${t}] ${msg.replace(/<[^>]*>?/gm, '')}`); }
    function updateHP(val, reason) { hp = Math.max(0, Math.min(100, hp + val)); document.getElementById('hpValueTxt').innerText = hp + "%"; document.getElementById('hpBarFill').style.width = hp + "%"; if(val < 0) { document.getElementById('hpContainer').classList.add('hp-shake'); setTimeout(()=>document.getElementById('hpContainer').classList.remove('hp-shake'), 400); addLog(`<span style="color:#ef4444;">🚨 บาดเจ็บ: ${reason}</span>`); } if(hp <= 0) { audio.play('explosion'); alert("☠️ GAME OVER: " + reason); location.reload(); } }
    function filterInventory() { const q = document.getElementById('chemSearch').value.toLowerCase(); document.querySelectorAll('.chem-item').forEach(it => { it.style.display = it.innerText.toLowerCase().includes(q) ? 'flex' : 'none'; }); }
    
    function prepareChemical(id, name, state, color) {
        audio.play('click');
        if(isSpilled) { audio.play('error'); return alert("⚠️ ทำความสะอาดสารหกก่อน!"); }
        currentTool = { id, name, state, color, amt: 0 };
        document.getElementById('toolBox').style.display = 'block'; document.getElementById('toolBox').style.left = "340px"; document.getElementById('toolBox').style.top = "100px";
        document.getElementById('toolTitle').innerText = name;
        document.getElementById('solidUI').style.display = state === 'solid' ? 'block' : 'none';
        document.getElementById('liquidUI').style.display = state === 'liquid' ? 'block' : 'none';
        document.getElementById('scaleDisplay').innerText = "0.00 g"; document.getElementById('scalePowder').style.width = "0"; document.getElementById('cylinderFill').style.height = "0";
    }

    function changeAmt(val) {
        audio.play('click'); currentTool.amt += val;
        if(currentTool.state === 'solid') { document.getElementById('scaleDisplay').innerText = currentTool.amt.toFixed(2) + " g"; document.getElementById('scalePowder').style.width = Math.min(100, currentTool.amt * 2) + "%"; document.getElementById('scalePowder').style.backgroundColor = currentTool.color;
        } else { document.getElementById('cylinderFill').style.height = Math.min(100, currentTool.amt) + "%"; document.getElementById('cylinderFill').style.backgroundColor = currentTool.color; }
    }

    function pourToBeaker() {
        if(currentTool.amt <= 0) { audio.play('error'); return alert("ระบุปริมาณก่อน!"); }
        if (currentTool.state === 'solid') audio.play('pourSolid'); else audio.play('pourLiquid');

        beakerItems.push({...currentTool}); totalVol += currentTool.amt;
        if(totalVol > 100) { triggerSpill(currentTool.color); } 
        
        liquidSim.splash(Math.floor(Math.random() * liquidSim.numSprings), 50); 
        liquidSim.setColor(currentTool.color);
        
        addLog(`เท ${currentTool.name} (${currentTool.amt} ${currentTool.state === 'solid' ? 'g':'ml'})`);
        closeTool(); createParticles('pour', 15);
        document.getElementById('btnProcess').disabled = false;
        if(totalVol > 5) document.getElementById('stirRod').style.display = 'block';
        updateQuestHUD();
    }

    function triggerSpill(color) { isSpilled = true; questSpillCount++; audio.play('error'); document.getElementById('spillEffect').style.opacity = "1"; document.getElementById('spillEffect').style.backgroundColor = color; document.getElementById('btnClean').style.display = "block"; updateHP(-15, "สารเคมีหก!"); updateQuestHUD(); }
    function cleanSpill() { audio.play('wipe'); isSpilled = false; document.getElementById('spillEffect').style.opacity = "0"; document.getElementById('btnClean').style.display = "none"; addLog("เช็ดคราบสำเร็จ"); }

    function toggleHeater() {
        audio.play('click'); isHeating = !isHeating; const btn = document.getElementById('btnHeater');
        btn.style.background = isHeating ? "#ef4444" : "#334155"; btn.innerText = isHeating ? "🔥 ปิดตะเกียง" : "🔥 ต้มสาร";
        document.getElementById('flameFire').style.display = isHeating ? "flex" : "none";
        
        // Phase 7: Toggle Ambient Light Class
        if (isHeating) {
            document.body.classList.add('heating-active');
            audio.startLoop('fire'); 
        } else {
            document.body.classList.remove('heating-active');
            audio.stopLoop('fire'); 
        }
    }

    function toggleStir() {
        audio.play('click'); isStirring = !isStirring; const btn = document.getElementById('btnStir');
        btn.style.background = isStirring ? "#3b82f6" : "#334155";
        liquidSim.isStirring = isStirring; 
        if(isStirring) { document.getElementById('stirRod').classList.add('stirring-anim'); audio.startLoop('stir');
        } else { document.getElementById('stirRod').classList.remove('stirring-anim'); audio.stopLoop('stir'); }
        updateQuestHUD();
    }

    // Phase 7: Fume Hood Controller
    function toggleFan() {
        audio.play('click');
        isFanOn = !isFanOn;
        const btn = document.getElementById('btnFan');
        const vent = document.getElementById('fumeHoodVent');
        btn.style.background = isFanOn ? "#38bdf8" : "#334155";
        btn.innerText = isFanOn ? "💨 ปิดพัดลมดูดควัน" : "💨 เปิดพัดลมดูดควัน";
        
        if(isFanOn) {
            audio.startLoop('fan');
            vent.classList.add('active');
            addLog("เปิดระบบดูดควันทำงาน");
        } else {
            audio.stopLoop('fan');
            vent.classList.remove('active');
            addLog("ปิดระบบดูดควัน");
        }
    }

    function closeTool() { audio.play('click'); document.getElementById('toolBox').style.display = 'none'; }
    function tareScale() { audio.play('click'); document.getElementById('scaleDisplay').innerText = "0.00 g"; document.getElementById('scalePowder').style.width = "0"; }

    /* ============================================================
       📍 QUEST & REPORT LOGIC (Phase 4)
       ============================================================ */
    function openQuestBoard() { audio.play('click'); document.getElementById('questBoardModal').style.display = 'flex'; fetch('api_student_quest.php', { method: 'POST', body: JSON.stringify({ action: 'get_available_quests', csrf_token: document.getElementById('csrf').value }) }).then(r => r.json()).then(res => { let html = ''; res.data.forEach(q => { html += `<div class="quest-card"><div><h3>${q.title}</h3><p>${q.description}</p></div><button class="btn-accept-quest" onclick='startQuest(${JSON.stringify(q)})'>รับภารกิจ</button></div>`; }); document.getElementById('questListBody').innerHTML = html; }); }
    function closeQuestBoard() { audio.play('click'); document.getElementById('questBoardModal').style.display = 'none'; }
    function startQuest(q) { audio.play('victory'); activeQuest = q; questSpillCount = 0; washBeaker(true, true); closeQuestBoard(); document.getElementById('questHUD').style.display = 'block'; document.getElementById('hudTitle').innerText = q.title; addLog(`เริ่มภารกิจ: ${q.title}`); updateQuestHUD(); }
    function abandonQuest() { audio.play('error'); activeQuest = null; document.getElementById('questHUD').style.display = 'none'; addLog("ยกเลิกภารกิจ"); }
    
    function updateQuestHUD() {
        if(!activeQuest) return;
        const cl = document.getElementById('hudChecklist'); let html = ''; let allDone = true;
        let beakerSummary = {}; beakerItems.forEach(it => { if(!beakerSummary[it.id]) beakerSummary[it.id]=0; beakerSummary[it.id]+=it.amt; });

        const c1ID = parseInt(activeQuest.target_chem1); let hasC1 = beakerSummary[c1ID] !== undefined; let c1Status = hasC1 ? 'done' : 'pending';
        if (activeQuest.strict_amount == 1 && activeQuest.amount_chem1 > 0 && hasC1) { let diff = Math.abs(activeQuest.amount_chem1 - beakerSummary[c1ID]) / activeQuest.amount_chem1; c1Status = (diff <= 0.05) ? 'done' : 'failed'; }
        if(c1Status !== 'done') allDone = false; html += `<li class="qh-item ${c1Status}">✔️ ใส่ ${activeQuest.chem1_name}</li>`;

        if (activeQuest.safety_goggles == 1) { if(!document.getElementById('chkGoggles').checked) { c1Status = 'failed'; allDone = false; } else c1Status = 'done'; html += `<li class="qh-item ${c1Status}">✔️ แว่นตานิรภัย</li>`; }
        
        cl.innerHTML = html; document.getElementById('btnSubmitQuest').style.display = (allDone && beakerItems.length > 0) ? 'block' : 'none';
    }

    function mixChemicals() {
        audio.play('click'); const btn = document.getElementById('btnProcess'); btn.disabled = true; btn.innerText = "⏳ กำลังประมวลผล...";

        fetch('api_process_lab.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mix', chemicals: beakerItems, environment: { temp: temp, stirring: isStirring }, safety: { goggles: document.getElementById('chkGoggles').checked, gloves: document.getElementById('chkGloves').checked }, residue: unwashedResidues, csrf_token: document.getElementById('csrf').value }) })
        .then(res => res.json())
        .then(data => {
            btn.innerText = "⚗️ ประมวลผลปฏิกิริยา"; btn.disabled = false;
            
            // อัปเดต pH Strip
            ph = data.ph_result;
            document.getElementById('valPh').innerText = ph.toFixed(2);
            let phHue = (14 - ph) * 20;
            document.getElementById('phStripBar').style.backgroundColor = `hsl(${phHue}, 100%, 40%)`;

            // อัปเดตสีผิวน้ำ
            liquidSim.setColor(data.color);
            targetTemp = data.final_temp;
            
            if(data.is_explosion) {
                audio.play('explosion'); createParticles('explosion', 70); updateHP(-40, "เกิดการระเบิด!"); washBeaker(true);
            } else {
                audio.play('pourLiquid'); addLog(`✅ ผลลัพธ์: ${data.product_name} | ${data.message}`);
                liquidSim.splash(30, 80); 
                if(data.gas !== 'ไม่มี') createParticles('gas', 40);
                if(data.damage > 0) { audio.play('error'); updateHP(-data.damage, data.message); }
                
                if(data.residue_generated && data.residue_generated.length > 0) {
                    unwashedResidues = data.residue_generated;
                    const resLayer = document.getElementById('residueLayer'); resLayer.style.opacity = 1; resLayer.style.height = Math.min(30, unwashedResidues[0].amt * 2) + "px";
                }
            }
        });
    }

    function washBeaker(force = false, clearResidue = false) {
        if(!force && !confirm("ต้องการเทสารทิ้งใช่หรือไม่?")) return;
        audio.play('pourLiquid'); beakerItems = []; totalVol = 0;
        document.getElementById('btnProcess').disabled = true; 
        if (isStirring) toggleStir();
        
        if(clearResidue) {
            unwashedResidues = [];
            document.getElementById('residueLayer').style.opacity = 0; document.getElementById('residueLayer').style.height = "0";
            addLog("ล้างอุปกรณ์สะอาดหมดจด");
        } else addLog("เทสารทิ้งแล้ว");
    }

    function submitQuestReport() {
        audio.play('click'); if(!confirm("คุณมั่นใจในผลการทดลองนี้และต้องการส่งรายงานใช่หรือไม่?")) return;
        document.getElementById('btnSubmitQuest').innerText = "⏳ กำลังส่งข้อมูล...";
        fetch('api_submit_report.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({quest_id: activeQuest.id, hp_remaining: hp, spill_count: questSpillCount, logs: logHistory, csrf_token: document.getElementById('csrf').value}) })
        .then(res => res.json()).then(data => {
            if (data.status === 'success') {
                audio.play('victory'); document.getElementById('victoryModal').style.display = 'flex'; setTimeout(() => document.getElementById('victoryBox').classList.add('show'), 100);
                const gradeEl = document.getElementById('finalGrade'); gradeEl.innerText = data.grade; gradeEl.className = `grade-stamp grade-${data.grade}`;
                document.getElementById('finalXP').innerText = data.earned_xp;
                confetti({ particleCount: 150, spread: 100, origin: { y: 0.6 }, colors: ['#f59e0b', '#38bdf8', '#22c55e'] });
            } else { audio.play('error'); alert("Error: " + data.message); document.getElementById('btnSubmitQuest').innerText = "🚀 ส่งผลการทดลอง!"; }
        });
    }

    function makeDraggable(el, handle) { let p1=0, p2=0, p3=0, p4=0; handle.onmousedown = (e) => { e.preventDefault(); p3 = e.clientX; p4 = e.clientY; document.onmouseup = () => { document.onmouseup = null; document.onmousemove = null; }; document.onmousemove = (e) => { p1 = p3 - e.clientX; p2 = p4 - e.clientY; p3 = e.clientX; p4 = e.clientY; el.style.top = (el.offsetTop - p2) + "px"; el.style.left = (el.offsetLeft - p1) + "px"; }; }; }
    makeDraggable(document.getElementById('toolBox'), document.getElementById('toolHandle'));
    makeDraggable(document.getElementById('questHUD'), document.getElementById('hudTitle'));

    window.onload = () => { initCanvas(); animateMain(); document.getElementById('workbenchScroll').scrollLeft = 0; window.onresize = initCanvas; };
</script>

<?php require_once 'footer.php'; ?>