<?php
/**
 * lab_realistic.php - ห้องทดลองเคมีเสมือนจริง (Ultimate Layout Fix - Phase 1 Full Version)
 * ระบบบริหารจัดการห้องเรียน โรงเรียนบ้านคาวิทยา
 * * การแก้ไขในระยะที่ 1:
 * 1. ใช้ !important เพื่อพังกำแพงคลาส .container ที่มาจาก header.php
 * 2. ปรับปรุง Body และ Wrapper ให้กว้างเต็มจอ (100vw) เพื่อกำจัดที่ว่างทางซ้าย
 * 3. รวบรวมฟังก์ชัน Particle Engine, Reaction Logic และ UI Interaction แบบเต็มพิกัด
 */

require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// ตรวจสอบสิทธิ์การเข้าใช้งาน
requireLogin();

$page_title = "ห้องทดลองเคมีเสมือนจริง";
$csrf = generate_csrf_token(); // สร้าง Token ป้องกัน CSRF

// --- เริ่มการเตรียมข้อมูลสารเคมีจากฐานข้อมูล ---
$solids = [];
$liquids = [];

// ตรวจสอบว่ามีคอลัมน์ formula หรือไม่ เพื่อป้องกัน Error กรณีฐานข้อมูลยังไม่ได้อัปเดต
$has_formula = false;
$check_col = $conn->query("SHOW COLUMNS FROM chemicals LIKE 'formula'");
if($check_col && $check_col->num_rows > 0) {
    $has_formula = true;
}

// ดึงข้อมูลสารเคมีทั้งหมด แบ่งตามสถานะ ของแข็ง และ ของเหลว
if($has_formula) {
    $res = $conn->query("SELECT id, name, formula, state, color_neutral FROM chemicals ORDER BY name ASC");
} else {
    $res = $conn->query("SELECT id, name, '' as formula, state, color_neutral FROM chemicals ORDER BY name ASC");
}

if ($res) {
    while ($row = $res->fetch_assoc()) {
        if ($row['state'] === 'solid') {
            $solids[] = $row;
        } else {
            $liquids[] = $row; 
        }
    }
}

// เรียกใช้งาน Header (ซึ่งปกติจะมี <div class="container"> ครอบไว้)
require_once 'header.php';
?>

<link rel="icon" href="data:;base64,iVBORw0KGgo=">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@400;700&family=Itim&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* ============================================================
       📍 PHASE 1: STRUCTURAL BREAKOUT (หัวใจของการแก้ปัญหาที่ว่างทางซ้าย)
       ============================================================ */
    
    /**
     * ไฟล์ header.php มักจะกำหนดคลาส .container ให้มี max-width: 1000px และ margin: auto
     * ซึ่งทำให้หน้า Lab ถูกบีบอยู่ตรงกลางและมีพื้นที่ว่างด้านซ้ายมหาศาล
     * เราจะใช้ !important เพื่อบังคับทับคำสั่งเหล่านั้นทั้งหมด
     */
    .container {
        max-width: none !important;    /* ปลดล็อกขีดจำกัดความกว้างเดิม */
        width: 100% !important;        /* สั่งให้กว้างเต็มพื้นที่จอ */
        margin: 0 !important;          /* ยกเลิกการจัดกึ่งกลาง (ทำให้ชิดซ้ายสุด) */
        padding: 0 !important;         /* ลบระยะห่างขอบในเพื่อให้เนื้อหาชนขอบจอ */
    }

    /* รีเซ็ตคุณสมบัติของ Body ให้เหมาะกับแอปพลิเคชันแบบ Full Screen */
    body { 
        background-color: #020617; 
        color: #f8fafc; 
        font-family: 'Itim', cursive, system-ui; 
        margin: 0 !important; 
        padding: 0 !important; 
        overflow: hidden; /* ปิดการเลื่อนของหน้าเว็บหลัก เพื่อไปใช้ระบบเลื่อนใน Workbench แทน */
    }

    /* =========================================
       CSS UI & INTERFACE (การออกแบบส่วนต่อประสาน)
       ========================================= */
    
    /* แถบ Header ย่อยภายในหน้าห้องแล็บ */
    .lab-subheader {
        background: #0f172a; 
        border-bottom: 2px solid #1e293b; 
        padding: 10px 25px;
        display: flex; 
        justify-content: space-between; 
        align-items: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.6); 
        z-index: 1000; 
        position: sticky; 
        top: 0;
    }

    .lab-title { 
        font-size: 1.2rem; 
        font-weight: bold; 
        color: #38bdf8; 
        display: flex; 
        align-items: center; 
        gap: 12px; 
    }

    /* แถบเลือด / HP Status Bar (Gamification System) */
    .hp-wrapper { 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        background: rgba(15, 23, 42, 0.8); 
        padding: 6px 20px; 
        border-radius: 30px; 
        border: 1px solid #334155; 
        width: 380px; 
    }
    .hp-label { font-weight: bold; font-size: 1rem; color: #94a3b8; }
    .hp-bar-bg { 
        flex: 1; 
        height: 12px; 
        background: #1e293b; 
        border-radius: 10px; 
        overflow: hidden; 
        border: 1px solid #000; 
    }
    .hp-bar-fill { 
        height: 100%; 
        width: 100%; 
        background: linear-gradient(90deg, #22c55e, #10b981); 
        transition: width 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275), background 0.3s; 
    }
    .hp-value { 
        font-family: 'Share Tech Mono', monospace; 
        font-size: 1.1rem; 
        color: #22c55e; 
        min-width: 50px; 
        text-align: right; 
    }
    
    /* Animation เอฟเฟกต์หน้าจอสั่นเมื่อโดนดาเมจ */
    @keyframes shakeHP {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-8px); }
        40%, 80% { transform: translateX(8px); }
    }
    .hp-shake { animation: shakeHP 0.4s; }

    /* โครงสร้างพื้นที่ทำงานหลัก (Main Application Layout) */
    .lab-main-container {
        display: flex; 
        height: calc(100vh - 70px); 
        width: 100vw; 
        position: relative; 
        background: #020617;
    }

    /* แถบควบคุมด้านข้าง (Side Panels) */
    .panel-side { 
        width: 320px; 
        height: 100%; 
        z-index: 500; 
        transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        flex-shrink: 0;
    }
    .panel-side.collapsed { width: 0; }
    
    .panel-inner { 
        width: 320px; 
        height: 100%; 
        background: #1e293b; 
        display: flex; 
        flex-direction: column; 
        transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }
    .panel-left .panel-inner { border-right: 2px solid #334155; }
    .panel-right .panel-inner { border-left: 2px solid #334155; }
    .panel-left.collapsed .panel-inner { transform: translateX(-100%); }
    .panel-right.collapsed .panel-inner { transform: translateX(100%); }

    /* เนื้อหาภายใน Panel */
    .panel-content { padding: 20px; overflow-y: auto; flex: 1; }
    .panel-content::-webkit-scrollbar { width: 5px; }
    .panel-content::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }

    /* ปุ่มเปิด-ปิดแถบเมนู (Toggle System) */
    .btn-toggle-panel { 
        position: absolute; 
        top: 20px; 
        width: 40px; 
        height: 50px; 
        background: #3b82f6; 
        color: white; 
        border: none; 
        cursor: pointer; 
        font-size: 1.3rem; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        z-index: 600; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.4); 
        transition: 0.3s; 
    }
    .btn-toggle-left { right: -40px; border-radius: 0 10px 10px 0; }
    .btn-toggle-right { left: -40px; border-radius: 10px 0 0 10px; background: #8b5cf6; }
    .btn-toggle-panel:hover { width: 50px; }

    /* =========================================
       WORKBENCH FIX: พื้นที่โต๊ะทดลองแบบเลื่อนได้ (Scrollable Table)
       ========================================= */
    .workbench-wrapper {
        flex: 1; 
        height: 100%; 
        position: relative; 
        overflow-x: auto; /* อนุญาตให้เลื่อนซ้าย-ขวาเฉพาะในส่วนนี้ */
        overflow-y: hidden; 
        background: radial-gradient(circle at center, #1e293b 0%, #020617 100%);
        scrollbar-width: thin;
        scrollbar-color: #3b82f6 #0f172a;
    }
    
    /* บังคับความกว้างโต๊ะ 1200px เพื่อให้อุปกรณ์วางกระจายตัวได้และชิดซ้าย */
    .workbench-inner {
        min-width: 1200px; 
        width: 100%; 
        height: 100%; 
        position: relative; 
        margin: 0; /* เปลี่ยนจาก margin: 0 auto เป็น 0 เพื่อให้ชิดซ้ายสุดตามต้องการ */
    }

    /* พื้นผิวหน้าโต๊ะปฏิบัติการ */
    .desk-surface { 
        position: absolute; 
        bottom: 0; 
        left: 0; 
        width: 100%; 
        height: 200px; 
        background: linear-gradient(to bottom, #1e293b 0%, #020617 100%); 
        border-top: 6px solid #475569; 
        z-index: 1; 
    }

    /* ระบบจุดยึดพิกัดอุปกรณ์ (The Anchor System) */
    .desk-anchor {
        position: absolute;
        bottom: 0;
        left: 50%; /* อ้างอิงจุดศูนย์กลางของโต๊ะ 1200px (ซึ่งก็คือพิกัด 600px) */
        width: 0;
        height: 100%;
        z-index: 10;
        pointer-events: none;
    }
    .desk-anchor > * { pointer-events: auto; position: absolute; }

    /* =========================================
       LAB APPARATUS: เครื่องแก้วและอุปกรณ์
       ========================================= */
    
    /* 1. บีกเกอร์หลัก (ศูนย์กลางการทำปฏิกิริยา) */
    .main-beaker { 
        bottom: 120px; 
        left: -100px; 
        width: 200px; 
        height: 240px; 
        border: 4px solid rgba(255,255,255,0.7); 
        border-top: none; 
        border-radius: 0 0 30px 30px; 
        background: rgba(255,255,255,0.08); 
        display: flex; 
        align-items: flex-end; 
        overflow: hidden; 
        box-shadow: inset 0 -15px 40px rgba(0,0,0,0.5); 
        z-index: 20; 
        transition: 0.4s; 
    }

    /* 2. เครื่องชั่งดิจิทัล (ฝั่งซ้าย) */
    .digital-scale { 
        bottom: 100px; 
        left: -500px; /* ระยะห่างจากจุดศูนย์กลางไปทางซ้าย 500px */
        width: 220px; 
        height: 140px;
        background: #94a3b8; 
        border-radius: 12px; 
        border: 3px solid #64748b; 
        box-shadow: 0 15px 25px rgba(0,0,0,0.8); 
        display: flex; 
        flex-direction: column; 
        align-items: center; 
        z-index: 15; 
    }

    /* 3. กระบอกตวง (ฝั่งขวา) */
    .cylinder-container { 
        bottom: 100px; 
        left: 280px; /* ระยะห่างจากจุดศูนย์กลางไปทางขวา 280px */
        width: 60px; 
        height: 220px; 
        border: 4px solid rgba(255,255,255,0.5); 
        border-top: none; 
        border-radius: 0 0 30px 30px; 
        background: rgba(255,255,255,0.05); 
        display: flex; 
        align-items: flex-end; 
        overflow: hidden; 
        z-index: 15; 
    }

    /* รายละเอียดเล็กๆ ของอุปกรณ์ */
    .scale-plate { width: 160px; height: 18px; background: #cbd5e1; border-radius: 50%; border: 2px solid #64748b; margin: -10px 0 15px 0; position: relative; z-index: 2; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    .scale-display { 
        background: #020617; 
        color: #22c55e; 
        font-family: 'Share Tech Mono', monospace; 
        font-size: 1.8rem; 
        padding: 5px 15px; 
        border-radius: 8px; 
        width: 85%; 
        text-align: right; 
        margin-bottom: 12px; 
        box-shadow: inset 0 0 15px #000; 
        border: 1px solid #334155; 
        box-sizing: border-box;
    }
    .cylinder-liquid { width: 100%; height: 0%; transition: 0.8s cubic-bezier(0.4, 0, 0.2, 1); }
    .beaker-content { width: 100%; height: 0%; transition: 1.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; }
    
    /* ระบบตะเกียงและการให้ความร้อน */
    .heater-base { bottom: 70px; left: -130px; width: 260px; height: 40px; background: #334155; border-radius: 8px; border: 2px solid #1e293b; box-shadow: 0 12px 20px rgba(0,0,0,0.8); z-index: 10; }
    .flame-container { bottom: 110px; left: -40px; width: 80px; height: 60px; display: none; justify-content: center; align-items: flex-end; z-index: 12; pointer-events: none;}
    .flame { 
        width: 50px; height: 50px; 
        background: radial-gradient(circle at center, #fbbf24 0%, #ef4444 60%, transparent 100%); 
        border-radius: 50% 50% 20% 20%; 
        animation: flicker 0.1s infinite alternate; 
        opacity: 0.9; filter: blur(3px); 
        box-shadow: 0 -15px 30px rgba(239, 68, 68, 0.6); 
    }
    @keyframes flicker { 0% { transform: scale(1) translateY(0); } 100% { transform: scale(1.2) translateY(-10px); } }

    /* แท่งแก้วคนสาร */
    .stirring-rod { 
        position: absolute; 
        top: -100px; 
        left: 45%; 
        width: 10px; 
        height: 350px; 
        background: linear-gradient(to right, rgba(255,255,255,0.9), rgba(255,255,255,0.4)); 
        border-radius: 10px; 
        z-index: 30; 
        display: none; 
        transform-origin: top center; 
        box-shadow: 2px 0 10px rgba(0,0,0,0.3);
    }
    .stirring-anim { display: block; animation: stirAction 0.8s infinite linear; }
    @keyframes stirAction {
        0% { transform: rotate(-10deg) translateX(-20px); }
        50% { transform: rotate(10deg) translateX(20px); }
        100% { transform: rotate(-10deg) translateX(-20px); }
    }

    /* =========================================
       SENSOR & DASHBOARD UI
       ========================================= */
    .sensor-panel { 
        position: absolute; 
        top: 25px; 
        right: 25px; 
        z-index: 400; 
        background: rgba(15, 23, 42, 0.9); 
        border: 1px solid #334155; 
        border-radius: 15px; 
        padding: 20px; 
        display: flex; 
        flex-direction: column; 
        gap: 15px; 
        backdrop-filter: blur(8px); 
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }
    .sensor-box { background: #020617; border: 1px solid #1e293b; padding: 10px 15px; border-radius: 10px; text-align: center; min-width: 120px; }
    .sensor-label { color: #64748b; font-size: 0.8rem; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 1px; }
    .sensor-value { font-family: 'Orbitron', sans-serif; font-size: 1.6rem; font-weight: bold; }
    .val-temp { color: #38bdf8; } 
    .val-temp.hot { color: #ef4444; text-shadow: 0 0 10px rgba(239, 68, 68, 0.5); }
    .val-ph { color: #22c55e; }

    /* คลังสารเคมี (Left Panel) */
    .search-box { position: sticky; top: 0; background: #1e293b; padding-bottom: 15px; border-bottom: 1px solid #334155; z-index: 10; }
    .search-box input { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #475569; background: #0f172a; color: white; margin-bottom: 10px; box-sizing: border-box; font-family: inherit; }
    
    .chem-list { display: flex; flex-direction: column; gap: 8px; margin-top: 15px; }
    .chem-item { 
        background: #0f172a; 
        padding: 12px; 
        border-radius: 10px; 
        cursor: pointer; 
        border: 1px solid #334155; 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        transition: 0.2s; 
    }
    .chem-item:hover { border-color: #38bdf8; transform: translateX(5px); background: #1e293b; }
    .chem-color-indicator { width: 18px; height: 18px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.3); }
    .chem-name { font-size: 1rem; font-weight: bold; flex: 1; }
    .chem-formula { font-family: 'Share Tech Mono'; font-size: 0.8rem; color: #38bdf8; }

    /* สมุดบันทึก (Lab Log Zone) */
    .log-zone { 
        flex: 1; background: #0f172a; 
        border: 1px solid #334155; 
        border-radius: 10px; 
        padding: 15px; 
        overflow-y: auto; 
        font-family: 'Share Tech Mono', monospace; 
        font-size: 0.9rem; 
        color: #cbd5e1; 
        margin: 15px 0;
        scrollbar-width: thin;
    }
    .log-entry { margin-bottom: 8px; border-bottom: 1px solid #1e293b; padding-bottom: 5px; line-height: 1.4; }
    .log-time { color: #64748b; font-size: 0.75rem; margin-right: 8px; }

    /* ปุ่มควบคุมหลัก (Control Buttons) */
    .btn-mix { 
        background: linear-gradient(135deg, #8b5cf6, #6d28d9); 
        color: white; border: none; padding: 15px; 
        border-radius: 12px; font-weight: bold; 
        cursor: pointer; width: 100%; font-size: 1.2rem;
        box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        transition: 0.3s;
    }
    .btn-mix:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(139, 92, 246, 0.6); }
    .btn-mix:disabled { opacity: 0.5; cursor: not-allowed; filter: grayscale(1); }

    /* ป๊อปอัพเครื่องมือ (Fixed-Position Floating Popups) */
    .tool-controls { 
        position: fixed; 
        z-index: 2000; 
        background: rgba(15, 23, 42, 0.98); 
        padding: 0 0 20px 0; 
        border-radius: 15px; 
        border: 2px solid #38bdf8; 
        display: none; 
        width: 300px; 
        box-shadow: 0 20px 50px rgba(0,0,0,0.9); 
    }
    .drag-handle { padding: 12px; background: rgba(56, 189, 248, 0.2); border-radius: 13px 13px 0 0; cursor: move; border-bottom: 1px solid #334155; text-align: center; }
    .btn-transfer { background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px; width: 100%; font-weight: bold; margin-top: 15px; cursor: pointer; }

    /* ระบบแจ้งเตือนและประเมินผล (Modals) */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); z-index: 3000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
    .grade-modal { background: #1e293b; padding: 40px; border-radius: 20px; border: 2px solid #38bdf8; text-align: center; max-width: 400px; width: 90%; }
    .grade-circle { width: 120px; height: 120px; border-radius: 50%; background: #0f172a; margin: 0 auto 20px auto; display: flex; align-items: center; justify-content: center; font-size: 4rem; font-weight: bold; border: 6px solid #8b5cf6; color: #8b5cf6; box-shadow: 0 0 30px rgba(139, 92, 246, 0.4); }

    /* ระบบสารหก (Spill Management) */
    .spill-area { bottom: 40px; left: -150px; width: 300px; height: 60px; background: transparent; border-radius: 50%; z-index: 5; opacity: 0; filter: blur(10px); transition: 0.5s; pointer-events: none; }
    .btn-clean-spill { bottom: 60px; left: -100px; width: 200px; z-index: 50; background: #eab308; color: #1e293b; padding: 12px; border: none; border-radius: 10px; font-weight: bold; cursor: pointer; display: none; animation: pulseClean 1s infinite; }
</style>

<div class="lab-subheader">
    <div class="lab-title">
        <span style="font-size: 1.8rem;">🧪</span> 
        <div>
            <div style="font-weight: 800;">Virtual Chemistry Lab</div>
            <div style="font-size: 0.7rem; color: #64748b; font-family: 'Orbitron';">Powered by Bankha System v2.6.5 [Structural Fix]</div>
        </div>
    </div>
    
    <div class="hp-wrapper" id="hpContainer">
        <div class="hp-label">HP:</div>
        <div class="hp-bar-bg"><div class="hp-bar-fill" id="hpBarFill"></div></div>
        <div class="hp-value" id="hpValueTxt">100%</div>
    </div>

    <div>
        <a href="dashboard_student.php" class="btn-dashboard-back" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid #ef4444; padding: 8px 18px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s;">✕ ออกจากการทดลอง</a>
    </div>
</div>

<div class="lab-main-container">
    
    <div class="panel-side panel-left" id="panelLeft">
        <div class="panel-inner">
            <div class="panel-content">
                <div class="search-box">
                    <input type="text" id="chemSearch" placeholder="🔍 ค้นหาสารเคมี..." onkeyup="filterInventory()">
                    <button onclick="openPeriodicTable()" style="width:100%; padding:10px; background:#3b82f6; border:none; color:white; border-radius:8px; font-weight:bold; cursor:pointer; transition: 0.3s;">⚛️ เปิดตารางธาตุ</button>
                </div>

                <h4 style="color:#94a3b8; margin-top:20px; font-size:0.9rem; text-transform:uppercase; letter-spacing: 1px;">🧊 สถานะของแข็ง</h4>
                <div class="chem-list" id="solidList">
                    <?php foreach ($solids as $s): ?>
                        <div class="chem-item" onclick="prepareChemical(<?= $s['id'] ?>, '<?= h($s['name']) ?>', 'solid', '<?= $s['color_neutral'] ?>')">
                            <div class="chem-color-indicator" style="background-color: <?= h($s['color_neutral']) ?>;"></div>
                            <div style="flex: 1;">
                                <div class="chem-name"><?= h($s['name']) ?></div>
                                <div class="chem-formula"><?= h($s['formula']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h4 style="color:#94a3b8; margin-top:20px; font-size:0.9rem; text-transform:uppercase; letter-spacing: 1px;">💧 สถานะของเหลว</h4>
                <div class="chem-list" id="liquidList">
                    <?php foreach ($liquids as $l): ?>
                        <div class="chem-item" onclick="prepareChemical(<?= $l['id'] ?>, '<?= h($l['name']) ?>', 'liquid', '<?= $l['color_neutral'] ?>')">
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
        <div class="workbench-inner">
            <canvas id="fxCanvas" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:40;"></canvas>
            
            <div class="sensor-panel">
                <div class="sensor-box"><div class="sensor-label">TEMPERATURE</div><div class="sensor-value val-temp" id="valTemp">25.0 °C</div></div>
                <div class="sensor-box"><div class="sensor-label">PH LEVEL</div><div class="sensor-value val-ph" id="valPh">7.00</div></div>
            </div>

            <div class="desk-surface"></div>

            <div class="desk-anchor" id="labAnchor">
                <div class="digital-scale" id="scaleObj">
                    <div class="scale-plate">
                        <div id="scalePowder" style="width:0; height:0; background:transparent; margin:0 auto; border-radius:50%; transition:0.3s;"></div>
                    </div>
                    <div class="scale-display" id="scaleDisplay">0.00 g</div>
                    <button onclick="tareScale()" style="background:#475569; color:white; border:none; padding:5px 15px; border-radius:5px; cursor:pointer; font-weight:bold; font-size: 0.8rem; border: 1px solid #334155;">TARE</button>
                </div>

                <div class="main-beaker" id="beakerObj">
                    <div class="stirring-rod" id="stirRod"></div>
                    <div class="beaker-content" id="beakerFill"></div>
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

                <div style="background:rgba(239, 68, 68, 0.1); border:1px dashed #ef4444; padding:15px; border-radius:10px; margin-bottom:15px;">
                    <label style="display:flex; align-items: center; gap: 10px; margin-bottom:10px; color:#fca5a5; cursor:pointer;">
                        <input type="checkbox" id="chkGoggles" style="width: 18px; height: 18px;"> 🥽 สวมแว่นตานิรภัย
                    </label>
                    <label style="display:flex; align-items: center; gap: 10px; color:#fca5a5; cursor:pointer;">
                        <input type="checkbox" id="chkGloves" style="width: 18px; height: 18px;"> 🧤 สวมถุงมือยาง
                    </label>
                </div>

                <div class="log-zone" id="labLog">
                    <div class="log-entry"><span class="log-time"><?= date('H:i') ?></span> ระบบโครงสร้างปลดล็อกสำเร็จ (Phase 1)</div>
                </div>

                <button class="btn-mix" id="btnProcess" onclick="mixChemicals()" disabled style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); border: 1px solid rgba(255,255,255,0.2);">⚗️ ประมวลผลปฏิกิริยา</button>
                <button onclick="washBeaker()" style="width:100%; background:#475569; color:white; border:none; padding:12px; border-radius:10px; margin-top:10px; cursor:pointer; font-weight: bold; transition: 0.3s;">🗑️ ล้างบีกเกอร์</button>
                
                <hr style="border:none; border-top:1px solid #334155; margin:20px 0;">
                <button class="btn-mix" onclick="submitFinal()" style="background: linear-gradient(135deg, #10b981, #059669); font-size: 1.1rem; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">📄 ส่งรายงานผลการทดลอง</button>
            </div>
        </div>
    </div>

</div>

<div class="tool-controls" id="toolBox">
    <div class="drag-handle" id="toolHandle">
        <span id="toolTitle" style="color:#38bdf8; font-weight:bold;">เลือกปริมาณสาร</span>
    </div>
    <div style="padding:20px;">
        <div id="solidUI" style="display:none;">
            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <button onclick="changeAmt(5)" style="flex:1; padding:12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:white; cursor:pointer; font-weight: bold;">+5 g</button>
                <button onclick="changeAmt(20)" style="flex:1; padding:12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:white; cursor:pointer; font-weight: bold;">+20 g</button>
            </div>
        </div>
        <div id="liquidUI" style="display:none;">
            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <button onclick="changeAmt(10)" style="flex:1; padding:12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:white; cursor:pointer; font-weight: bold;">+10 ml</button>
                <button onclick="changeAmt(50)" style="flex:1; padding:12px; border-radius:8px; border:1px solid #334155; background:#0f172a; color:white; cursor:pointer; font-weight: bold;">+50 ml</button>
            </div>
        </div>
        <button class="btn-transfer" onclick="pourToBeaker()" style="box-shadow: 0 4px 10px rgba(16, 185, 129, 0.4);">⬇️ ถ่ายลงบีกเกอร์</button>
        <button onclick="closeTool()" style="width:100%; background:none; border:none; color:#64748b; margin-top:15px; cursor:pointer; font-weight: bold;">ยกเลิก</button>
    </div>
</div>

<div class="modal-overlay" id="resultModal">
    <div class="grade-modal">
        <h3 style="color: #38bdf8;">สรุปการประเมินปฏิบัติการ</h3>
        <div class="grade-circle" id="finalGrade">A</div>
        <h2 id="finalScore" style="color: #fff;">100 / 100</h2>
        <p id="finalFeedback" style="color:#94a3b8; margin-bottom:20px; line-height: 1.5;"></p>
        <button onclick="location.reload()" class="btn-mix" style="background: #3b82f6;">🔄 กลับไปทดลองอีกครั้ง</button>
    </div>
</div>

<input type="hidden" id="csrf" value="<?= h($csrf) ?>">

<script>
    // --- ตัวแปรควบคุมระบบ (Global State) ---
    let hp = 100;
    let beakerItems = [];
    let totalVol = 0;
    let temp = 25.0;
    let ph = 7.00;
    let currentTool = { id:0, name:'', state:'', color:'', amt:0 };
    let isHeating = false;
    let isStirring = false;
    let isSpilled = false;
    let successCount = 0;
    let particles = [];

    // --- ระบบจัดการ Canvas FX (Particle Engine) ---
    const canvas = document.getElementById('fxCanvas');
    const ctx = canvas.getContext('2d');

    /**
     * ฟังก์ชันปรับขนาด Canvas ให้ตรงกับพื้นที่ Workbench จริง
     * ป้องกันภาพเบี้ยวเมื่อย่อ-ขยายแถบเมนู
     */
    function initCanvas() {
        const wb = document.getElementById('workbenchScroll');
        canvas.width = wb.scrollWidth;
        canvas.height = wb.clientHeight;
    }

    /**
     * คลาสสร้างเม็ดอนุภาค (ควัน, ระเบิด, ก๊าซ)
     */
    class Particle {
        constructor(x, y, type) {
            this.x = x; 
            this.y = y; 
            this.type = type;
            this.life = 1.0; // ค่าอายุของอนุภาค (1.0 = ใหม่, 0.0 = หายไป)
            this.vx = (Math.random() - 0.5) * 4;
            this.vy = type === 'gas' ? -(Math.random() * 2 + 1) : (Math.random() * 5 - 2.5);
            this.size = Math.random() * 8 + 2;
        }
        update() {
            this.x += this.vx; 
            this.y += this.vy;
            this.life -= 0.012; // ค่อยๆ จางหายไป
        }
        draw() {
            ctx.globalAlpha = this.life;
            ctx.fillStyle = this.type === 'explosion' ? '#ff4500' : (this.type === 'steam' ? '#ffffff' : '#a78bfa');
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    /**
     * ฟังก์ชันเรียกใช้อนุภาคตามประเภท
     */
    function createParticles(type, count) {
        // หาพิกัดบีกเกอร์บนโต๊ะแบบ Dynamic
        const beaker = document.getElementById('beakerObj');
        const rect = beaker.getBoundingClientRect();
        const wbRect = document.getElementById('workbenchScroll').getBoundingClientRect();
        
        // คำนวณพิกัดบน Canvas (ที่เลื่อนได้)
        const spawnX = (rect.left - wbRect.left) + (rect.width / 2) + document.getElementById('workbenchScroll').scrollLeft;
        const spawnY = (rect.top - wbRect.top) + (rect.height / 3);

        for(let i = 0; i < count; i++) {
            particles.push(new Particle(spawnX, spawnY, type));
        }
    }

    /**
     * ฟังก์ชัน Loop วนซ้ำเพื่อวาด Frame ของ Canvas
     */
    function animateFX() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        particles = particles.filter(p => p.life > 0);
        particles.forEach(p => { p.update(); p.draw(); });
        
        // สร้างไอน้ำเล็กน้อยเมื่ออุณหภูมิสูง
        if(isHeating && totalVol > 0 && temp > 95) {
            if(Math.random() > 0.85) createParticles('steam', 1);
        }
        requestAnimationFrame(animateFX);
    }

    // --- ระบบ UI Interaction ---

    /**
     * ฟังก์ชันพับเก็บเมนู (Phase 1 Fix: รองรับการขยายเต็มจอ)
     */
    function togglePanel(id) {
        const p = document.getElementById(id);
        p.classList.toggle('collapsed');
        const btn = p.querySelector('.btn-toggle-panel');
        
        if(id === 'panelLeft') {
            btn.innerText = p.classList.contains('collapsed') ? '▶' : '◀';
        } else {
            btn.innerText = p.classList.contains('collapsed') ? '◀' : '▶';
        }
        
        // ปรับขนาด Canvas ใหม่เมื่อพื้นที่เปลี่ยนแปลง
        setTimeout(initCanvas, 400);
    }

    /**
     * ค้นหาและกรองรายการสารเคมีในคลัง
     */
    function filterInventory() {
        const q = document.getElementById('chemSearch').value.toLowerCase();
        const items = document.querySelectorAll('.chem-item');
        items.forEach(it => {
            const name = it.innerText.toLowerCase();
            it.style.display = name.includes(q) ? 'flex' : 'none';
        });
    }

    /**
     * เตรียมสารเคมีเข้าสู่เครื่องมือ (ตาชั่ง/กระบอกตวง)
     */
    function prepareChemical(id, name, state, color) {
        if(isSpilled) return alert("⚠️ กรุณาทำความสะอาดพื้นที่ที่ทำสารหกก่อนดำเนินการต่อ!");
        
        currentTool = { id, name, state, color, amt: 0 };
        const box = document.getElementById('toolBox');
        box.style.display = 'block';
        box.style.left = "300px"; // วางข้างแผงรายการสาร (Phase 1 Fix)
        box.style.top = "150px";
        
        document.getElementById('toolTitle').innerText = name;
        document.getElementById('solidUI').style.display = state === 'solid' ? 'block' : 'none';
        document.getElementById('liquidUI').style.display = state === 'liquid' ? 'block' : 'none';
        
        // รีเซ็ตหน้าจออุปกรณ์ประกอบ
        document.getElementById('scaleDisplay').innerText = "0.00 g";
        document.getElementById('scalePowder').style.width = "0";
        document.getElementById('cylinderFill').style.height = "0";
        
        addLog(`เลือกสาร: ${name}`);
    }

    /**
     * เพิ่มปริมาณสารในเครื่องมือ
     */
    function changeAmt(val) {
        currentTool.amt += val;
        if(currentTool.state === 'solid') {
            document.getElementById('scaleDisplay').innerText = currentTool.amt.toFixed(2) + " g";
            document.getElementById('scalePowder').style.width = Math.min(100, currentTool.amt * 2) + "%";
            document.getElementById('scalePowder').style.height = "12px";
            document.getElementById('scalePowder').style.backgroundColor = currentTool.color;
        } else {
            document.getElementById('cylinderFill').style.height = Math.min(100, currentTool.amt) + "%";
            document.getElementById('cylinderFill').style.backgroundColor = currentTool.color;
        }
    }

    /**
     * เทสารจากเครื่องมือลงในบีกเกอร์หลัก
     */
    function pourToBeaker() {
        if(currentTool.amt <= 0) return alert("กรุณาระบุปริมาณสารก่อน!");
        
        beakerItems.push({...currentTool});
        totalVol += currentTool.amt;
        
        const fill = document.getElementById('beakerFill');
        if(totalVol > 100) {
            fill.style.height = "100%";
            triggerSpill(currentTool.color);
        } else {
            fill.style.height = totalVol + "%";
        }
        fill.style.backgroundColor = currentTool.color;
        
        addLog(`เท ${currentTool.name} ลงบีกเกอร์ (${currentTool.amt} ${currentTool.state === 'solid' ? 'g' : 'ml'})`);
        closeTool();
        
        if(beakerItems.length >= 2) document.getElementById('btnProcess').disabled = false;
        if(totalVol > 5) document.getElementById('stirRod').style.display = 'block';
        
        // แอนิเมชันตอนเท
        createParticles('pour', 15);
    }

    // --- ระบบความปลอดภัย & ความเสียหาย (HP System) ---

    /**
     * อัปเดตค่าเลือด (HP)
     */
    function updateHP(val, reason) {
        hp = Math.max(0, Math.min(100, hp + val));
        document.getElementById('hpValueTxt').innerText = hp + "%";
        document.getElementById('hpBarFill').style.width = hp + "%";
        
        // เอฟเฟกต์การสั่น
        document.getElementById('hpContainer').classList.add('hp-shake');
        setTimeout(() => document.getElementById('hpContainer').classList.remove('hp-shake'), 400);
        
        if(val < 0) {
            addLog(`<span style="color:#ef4444;">🚨 บาดเจ็บ: ${reason}</span>`);
        }
        
        if(hp <= 0) {
            alert("☠️ GAME OVER: " + reason);
            location.reload();
        }
    }

    /**
     * เหตุการณ์สารเคมีหกเลอะเทอะ
     */
    function triggerSpill(color) {
        isSpilled = true;
        const s = document.getElementById('spillEffect');
        s.style.opacity = "1";
        s.style.backgroundColor = color;
        document.getElementById('btnClean').style.display = "block";
        updateHP(-15, "สารเคมีกระเด็นล้นออกจากบีกเกอร์!");
    }

    /**
     * ทำความสะอาดโต๊ะทดลอง
     */
    function cleanSpill() {
        isSpilled = false;
        document.getElementById('spillEffect').style.opacity = "0";
        document.getElementById('btnClean').style.display = "none";
        addLog("ทำความสะอาดพื้นที่เรียบร้อยแล้ว");
    }

    // --- ระบบจำลองสภาพแวดล้อม (Physics Engine) ---

    /**
     * Loop ตรวจจับอุณหภูมิแบบเรียลไทม์
     */
    setInterval(() => {
        if(isHeating) {
            temp += 0.8;
            if(temp > 180) temp = 180;
            document.getElementById('valTemp').classList.add('hot');
        } else {
            if(temp > 25) temp -= 0.2;
            if(temp < 50) document.getElementById('valTemp').classList.remove('hot');
        }
        document.getElementById('valTemp').innerText = temp.toFixed(1) + " °C";
    }, 1000);

    /**
     * เปิด-ปิด ตะเกียงต้มสาร
     */
    function toggleHeater() {
        isHeating = !isHeating;
        const btn = document.getElementById('btnHeater');
        const flame = document.getElementById('flameFire');
        
        if(isHeating) {
            btn.style.background = "#ef4444";
            btn.innerText = "🔥 ปิดตะเกียง";
            flame.style.display = "flex";
            addLog("เปิดระบบให้ความร้อน");
        } else {
            btn.style.background = "#334155";
            btn.innerText = "🔥 ต้มสาร";
            flame.style.display = "none";
            addLog("ปิดระบบให้ความร้อน");
        }
    }

    /**
     * เปิด-ปิด การใช้แท่งแก้วคนสาร
     */
    function toggleStir() {
        isStirring = !isStirring;
        const btn = document.getElementById('btnStir');
        const rod = document.getElementById('stirRod');
        
        if(isStirring) {
            btn.style.background = "#3b82f6";
            rod.classList.add('stirring-anim');
            addLog("เริ่มทำการคนสารเคมี");
        } else {
            btn.style.background = "#334155";
            rod.classList.remove('stirring-anim');
            addLog("หยุดการคนสาร");
        }
    }

    // --- การประมวลผลผ่าน API ---

    /**
     * ส่งข้อมูลไปที่ api_process_lab.php เพื่อคำนวณปฏิกิริยา
     */
    function mixChemicals() {
        const btn = document.getElementById('btnProcess');
        btn.disabled = true;
        btn.innerText = "⏳ กำลังประมวลผล...";

        const payload = {
            action: 'mix',
            chemicals: beakerItems,
            environment: { temp: temp, stirring: isStirring },
            safety: { 
                goggles: document.getElementById('chkGoggles').checked, 
                gloves: document.getElementById('chkGloves').checked 
            },
            csrf_token: document.getElementById('csrf').value
        };

        fetch('api_process_lab.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            btn.innerText = "⚗️ ประมวลผลปฏิกิริยา";
            btn.disabled = false;
            
            ph = data.ph_result || 7.0;
            document.getElementById('valPh').innerText = ph.toFixed(2);
            document.getElementById('beakerFill').style.backgroundColor = data.color;
            
            if(data.is_explosion) {
                createParticles('explosion', 70);
                updateHP(-40, "เกิดการระเบิดในห้องปฏิบัติการ!");
                washBeaker(true); // ล้างบีกเกอร์อัตโนมัติหลังระเบิด
            } else if(data.status === 'success') {
                successCount++;
                addLog(`✅ ค้นพบผลิตภัณฑ์: ${data.product_name}`);
                if(data.gas !== 'ไม่มี') createParticles('gas', 30);
            } else {
                addLog(`ℹ️ ผลลัพธ์: ${data.message}`);
                if(data.damage > 0) updateHP(-data.damage, data.message);
            }
        });
    }

    /**
     * ล้างทำความสะอาดอุปกรณ์
     */
    function washBeaker(auto = false) {
        if(!auto && !confirm("ต้องการล้างบีกเกอร์และทิ้งสารเคมีทั้งหมดใช่หรือไม่?")) return;
        
        beakerItems = [];
        totalVol = 0;
        ph = 7.00;
        document.getElementById('beakerFill').style.height = "0";
        document.getElementById('valPh').innerText = "7.00";
        document.getElementById('btnProcess').disabled = true;
        document.getElementById('stirRod').style.display = "none";
        addLog("ล้างทำความสะอาดอุปกรณ์เรียบร้อย");
    }

    /**
     * ส่งรายงานผลการทดลองสุดท้าย
     */
    function submitFinal() {
        if(beakerItems.length === 0) return alert("คุณยังไม่ได้เริ่มทำการทดลองเลย!");
        
        const reportData = {
            action: 'submit_report',
            hp: hp,
            success_count: successCount,
            csrf_token: document.getElementById('csrf').value
        };

        fetch('api_process_lab.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(reportData)
        })
        .then(res => res.json())
        .then(data => {
            document.getElementById('resultModal').style.display = 'flex';
            document.getElementById('finalGrade').innerText = data.grade;
            document.getElementById('finalScore').innerText = data.score + " / 100";
            document.getElementById('finalFeedback').innerText = data.feedback;
        });
    }

    // --- ฟังก์ชันช่วยเหลือ (Utilities) ---

    function addLog(msg) {
        const log = document.getElementById('labLog');
        const now = new Date();
        const t = now.toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit'});
        log.innerHTML += `<div class="log-entry"><span class="log-time">[${t}]</span> ${msg}</div>`;
        log.scrollTop = log.scrollHeight;
    }

    function closeTool() { document.getElementById('toolBox').style.display = 'none'; }
    
    function tareScale() { 
        document.getElementById('scaleDisplay').innerText = "0.00 g"; 
        document.getElementById('scalePowder').style.width = "0"; 
        addLog("เซตค่าเครื่องชั่งเป็นศูนย์ (TARE)");
    }

    // ระบบลากป๊อปอัพ (Drag & Drop Logic)
    function makeDraggable(el, handle) {
        let p1 = 0, p2 = 0, p3 = 0, p4 = 0;
        handle.onmousedown = (e) => {
            e.preventDefault();
            p3 = e.clientX; p4 = e.clientY;
            document.onmouseup = () => { document.onmouseup = null; document.onmousemove = null; };
            document.onmousemove = (e) => {
                p1 = p3 - e.clientX; p2 = p4 - e.clientY;
                p3 = e.clientX; p4 = e.clientY;
                el.style.top = (el.offsetTop - p2) + "px";
                el.style.left = (el.offsetLeft - p1) + "px";
            };
        };
    }
    makeDraggable(document.getElementById('toolBox'), document.getElementById('toolHandle'));

    // --- เริ่มต้นระบบเมื่อโหลดหน้าจอ ---
    window.onload = () => {
        initCanvas();
        animateFX();
        
        // Phase 1 Fix: เลื่อน Scrollbar ของโต๊ะทดลองมาชิดซ้ายสุด เพื่อกำจัดที่ว่าง
        document.getElementById('workbenchScroll').scrollLeft = 0;
        
        // รองรับการ Resize หน้าจอ
        window.onresize = initCanvas;
    };
</script>

<?php 
// นำเข้า Footer (ซึ่งปกติจะมี </div> ปิดคลาส .container ที่เรา Override ไว้)
require_once 'footer.php'; 
?>