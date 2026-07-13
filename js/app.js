import * as THREE from 'three';
import { OrbitControls } from 'three/addons/OrbitControls.js';
import { init3DScene, updateLiquidVisuals } from './3d_engine.js';

// --- Global Variables ---
let chemicals = [];
let beakerHP = 100;
let playerHP = 100;
let isGameOver = false;

// --- Initialization ---
document.addEventListener('DOMContentLoaded', () => {
    // 1. Load Data
    loadChemicals();
    
    // 2. Init 3D Scene
    const viewer = document.getElementById('viewer3d');
    if (viewer) init3DScene(viewer);
    
    // 3. Bind Events
    const mixBtn = document.getElementById('mix-button');
    if(mixBtn) mixBtn.addEventListener('click', mixChemicals);
});

// --- Function: Load Chemicals ---
async function loadChemicals() {
    try {
        const res = await fetch("load_chemicals.php");
        chemicals = await res.json();
        
        const selectA = document.getElementById('chemicalA');
        const selectB = document.getElementById('chemicalB');
        
        selectA.innerHTML = "";
        selectB.innerHTML = "";

        // Default Options
        selectA.add(new Option("พิมพ์เพื่อค้นหา...", ""));
        selectB.add(new Option("พิมพ์เพื่อค้นหา...", ""));

        chemicals.forEach(c => {
            let label = c.name; 
            if(!label.includes('(') && c.formula) label = `${c.name} (${c.formula})`;
            
            selectA.add(new Option(label, c.id));
            selectB.add(new Option(label, c.id));
        });

        // 🔥 เปิดใช้ Tom Select (Searchable Dropdown)
        initSearchableSelect('#chemicalA');
        initSearchableSelect('#chemicalB');

    } catch (e) {
        console.error("Error:", e);
        document.getElementById('mixResult').innerText = "❌ โหลดข้อมูลล้มเหลว (ตรวจสอบ DB)";
    }
}

// --- Function: Mix Logic ---
async function mixChemicals() {
    if(isGameOver) {
        alert("🛑 ห้องแล็บเสียหายหนัก! กรุณากดปุ่ม 'รีเซ็ตแล็บ' ก่อน");
        return;
    }

    const idA = document.getElementById('chemicalA').value;
    const idB = document.getElementById('chemicalB').value;
    const volA = document.getElementById('volA').value;
    const volB = document.getElementById('volB').value;
    
    if (!idA || !idB || volA <= 0 || volB <= 0) {
        alert("⚠️ กรุณาเลือกสารและระบุปริมาตรให้ถูกต้อง");
        return;
    }

    // Reset Animation Class
    document.body.classList.remove('shake');
    void document.body.offsetWidth; // Force Reflow

    try {
        const params = new URLSearchParams({ a: idA, b: idB, volA: volA, volB: volB });
        const res = await fetch(`mix.php?${params.toString()}`);
        
        if (!res.ok) throw new Error(`Server Error: ${res.status}`);
        
        const result = await res.json();

        if (result.error) {
            document.getElementById('mixResult').innerText = `Error: ${result.error}`;
            return;
        }

        // --- 1. แสดงผลลัพธ์แบบละเอียด (Rich Result Display) ---
        
        let tempColor = "black";
        if(result.temperature > 60) tempColor = "red";
        if(result.temperature < 10) tempColor = "blue";

        // สร้าง HTML ตารางสรุปผล
        let output = `
            <div style="text-align:left; font-size:15px; line-height:1.6;">
                <h4 style="margin-bottom:10px; border-bottom:2px solid #ddd; padding-bottom:5px; color:#333;">📊 สรุปผลการทดลอง</h4>
                
                <strong>⚗️ ผลิตภัณฑ์:</strong> <span style="color:#007bff; font-weight:bold;">${result.product_name}</span><br>
                <strong>📝 สูตรเคมี:</strong> <code style="background:#eee; padding:2px 4px; border-radius:4px;">${result.product_formula}</code><br>
                <strong>⚖️ มวลโมเลกุล:</strong> ${result.product_mass} g/mol<br>
                
                <hr style="margin:8px 0; border:0; border-top:1px dashed #ccc;">
                
                <strong>💧 ปริมาตรสุทธิ:</strong> ${result.total_volume} mL<br>
                <strong>⚖️ น้ำหนักรวม:</strong> ${result.total_weight} g<br>
                
                <hr style="margin:8px 0; border:0; border-top:1px dashed #ccc;">
                
                🌡️ อุณหภูมิ: <span style="color:${tempColor}; font-weight:bold;">${result.temperature}°C</span><br>
                🧬 pH: ${result.final_ph}<br>
                🧊 สถานะ: ${translateState(result.final_state)}<br>
        `;
        
        if(result.precipitate !== "ไม่มีตะกอน") output += `🧱 ตะกอน: <span style="color:brown; font-weight:bold;">${result.precipitate}</span><br>`;
        if(result.gas !== "ไม่มีแก๊ส") output += `☁️ แก๊ส: <span style="color:purple; font-weight:bold;">${result.gas}</span><br>`;
        
        // Damage Display
        if(result.damage_beaker > 0) output += `<br><span style="color:orange">💥 บีกเกอร์เสียหาย -${result.damage_beaker}%</span>`;
        if(result.damage_player > 0) output += `<br><span style="color:red">💀 ผู้เล่นบาดเจ็บ -${result.damage_player}%</span>`;

        output += `</div>`; // Close Div

        document.getElementById('mixResult').innerHTML = output;

        // --- 2. จัดการ Damage & Effect ---
        handleDamage(result);

        // --- 3. อัปเดต 3D ---
        if(!isGameOver) {
            updateLiquidVisuals(result);
        }

    } catch (e) {
        console.error("Mix error:", e);
        document.getElementById('mixResult').innerText = "❌ เกิดข้อผิดพลาดในการคำนวณ";
    }
}

// --- Helper: Damage System ---
function handleDamage(result) {
    const damageB = parseInt(result.damage_beaker) || 0;
    const damageP = parseInt(result.damage_player) || 0;
    
    beakerHP -= damageB;
    playerHP -= damageP;
    
    if(beakerHP < 0) beakerHP = 0;
    if(playerHP < 0) playerHP = 0;

    updateStatusBars();

    // Visual Effects
    const effect = result.effect_type;
    
    if (effect === 'explosion' || damageB >= 50) {
        document.body.classList.add('shake');
        if (damageB >= 100 || beakerHP === 0) showOverlay('broken-overlay');
    } 
    
    if (effect === 'toxic') {
        showOverlay('toxic-overlay');
    }

    // Check Game Over
    if (beakerHP <= 0) {
        isGameOver = true;
        document.getElementById('mixResult').innerHTML += "<br><h3 style='color:red; text-align:center;'>💥 GAME OVER: บีกเกอร์แตก!</h3>";
    } else if (playerHP <= 0) {
        isGameOver = true;
        document.getElementById('mixResult').innerHTML += "<br><h3 style='color:red; text-align:center;'>💀 GAME OVER: คุณหมดสติ!</h3>";
    }
}

// --- UI Helpers ---
function updateStatusBars() {
    const bBar = document.getElementById('beaker-bar');
    const hBar = document.getElementById('health-bar');
    
    if(bBar) {
        bBar.style.width = `${beakerHP}%`;
        bBar.style.background = beakerHP < 30 ? "red" : (beakerHP < 60 ? "orange" : "#00d2ff");
    }
    if(hBar) {
        hBar.style.width = `${playerHP}%`;
        hBar.style.background = playerHP < 30 ? "red" : "#00ff44";
    }

    const txtB = document.getElementById('text-beaker');
    const txtH = document.getElementById('text-health');
    if(txtB) txtB.innerText = `${beakerHP}%`;
    if(txtH) txtH.innerText = `${playerHP}%`;
}

function showOverlay(id) {
    const el = document.getElementById(id);
    if(el) {
        el.style.display = 'block';
        setTimeout(() => el.style.opacity = '1', 10);
    }
}

function translateState(s) {
    if(s==='solid') return 'ของแข็ง (Solid)';
    if(s==='gas') return 'แก๊ส (Gas)';
    return 'ของเหลว (Liquid)';
}

// --- Tom Select Initialization ---
function initSearchableSelect(selector) {
    // Check library
    if (typeof TomSelect === 'undefined') {
        console.warn("TomSelect library not loaded.");
        return;
    }
    
    const el = document.querySelector(selector);
    if (!el) return;
    
    // Destroy old instance if exists
    if (el.tomselect) el.tomselect.destroy();

    new TomSelect(selector, {
        create: false,
        sortField: { field: "text", direction: "asc" },
        placeholder: "🔍 พิมพ์ชื่อสาร...",
        plugins: ['clear_button'],
        maxOptions: 100,
        render: {
            option: function(data, escape) {
                return '<div>' + escape(data.text) + '</div>';
            },
            item: function(data, escape) {
                return '<div>' + escape(data.text) + '</div>';
            }
        }
    });
}

// --- Global Reset ---
window.resetGame = function() {
    beakerHP = 100;
    playerHP = 100;
    isGameOver = false;
    updateStatusBars();
    
    ['broken-overlay', 'toxic-overlay'].forEach(id => {
        const el = document.getElementById(id);
        if(el) { el.style.opacity = '0'; setTimeout(()=>el.style.display='none', 200); }
    });
    
    document.body.classList.remove('shake');
    document.getElementById('mixResult').innerHTML = `<div style="text-align:center; padding:20px; color:#666;">
        <p>🔄 รีเซ็ตแล็บเรียบร้อย</p>
        <p>พร้อมสำหรับการทดลองครั้งใหม่!</p>
    </div>`;
    
    updateLiquidVisuals({
        final_ph: 7, 
        special_color: 'ใส', 
        final_state: 'liquid', 
        temperature: 25,
        precipitate: 'ไม่มีตะกอน'
    });
};