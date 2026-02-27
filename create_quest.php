<?php
// create_quest.php - ระบบสร้างและจัดการเควสห้องทดลอง (Quest Builder)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// บังคับให้เฉพาะครูและผู้พัฒนาเข้าได้
requireRole(['teacher', 'developer']);

$page_title = "สร้างภารกิจห้องทดลอง (Quest Builder)";
$csrf = generate_csrf_token();
$teacher_id = $_SESSION['user_id'];

// 1. ดึงข้อมูลสารเคมีทั้งหมดเพื่อทำ Dropdown
$chemicals = [];
$res_chem = $conn->query("SELECT id, name, formula, state FROM chemicals ORDER BY name ASC");
if ($res_chem) {
    while ($row = $res_chem->fetch_assoc()) {
        $chemicals[] = $row;
    }
}

// 2. ดึงข้อมูลห้องเรียนของครูคนนี้เพื่อมอบหมายงาน
$classes = [];
$stmt_class = $conn->prepare("SELECT id, class_name FROM classes WHERE teacher_id = ? ORDER BY class_name ASC");
$stmt_class->bind_param("i", $teacher_id);
$stmt_class->execute();
$res_class = $stmt_class->get_result();
while ($row = $res_class->fetch_assoc()) {
    $classes[] = $row;
}
$stmt_class->close();

// 3. ดึงเควสเก่าที่เคยสร้างไว้
$my_quests = [];
$stmt_quests = $conn->prepare("
    SELECT q.*, c1.name as chem1_name, c2.name as chem2_name, cl.class_name
    FROM quests q
    LEFT JOIN chemicals c1 ON q.target_chem1 = c1.id
    LEFT JOIN chemicals c2 ON q.target_chem2 = c2.id
    LEFT JOIN classes cl ON q.target_class_id = cl.id
    WHERE q.teacher_id = ?
    ORDER BY q.created_at DESC
");
$stmt_quests->bind_param("i", $teacher_id);
$stmt_quests->execute();
$res_quests = $stmt_quests->get_result();
while ($row = $res_quests->fetch_assoc()) {
    $my_quests[] = $row;
}
$stmt_quests->close();

// โหลด Header
require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Orbitron:wght@700&display=swap" rel="stylesheet">
<style>
    /* ===================== CSS STYLING ===================== */
    body {
        background-color: #f8fafc;
        font-family: 'Sarabun', sans-serif;
    }

    .quest-builder-container {
        max-width: 1200px;
        margin: 30px auto;
        padding: 0 20px;
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 30px;
    }

    /* 📱 Responsive Design */
    @media (max-width: 992px) {
        .quest-builder-container { grid-template-columns: 1fr; }
    }

    .card {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
        overflow: hidden;
        margin-bottom: 25px;
    }

    .card-header {
        background: linear-gradient(135deg, #1e293b, #0f172a);
        color: white;
        padding: 20px 25px;
        font-size: 1.25rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-body { padding: 30px 25px; }

    /* Form Controls */
    .form-group { margin-bottom: 20px; }
    .form-label {
        display: block;
        font-weight: 600;
        color: #334155;
        margin-bottom: 8px;
        font-size: 0.95rem;
    }
    .form-control, .form-select {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        font-family: inherit;
        font-size: 1rem;
        color: #1e293b;
        background: #f8fafc;
        transition: 0.3s;
        box-sizing: border-box;
    }
    .form-control:focus, .form-select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        outline: none;
        background: #ffffff;
    }

    textarea.form-control { resize: vertical; min-height: 100px; }

    /* Dynamic Logic Blocks */
    .logic-block {
        background: #f1f5f9;
        border-left: 4px solid #3b82f6;
        padding: 20px;
        border-radius: 0 10px 10px 0;
        margin-top: 15px;
        display: none; /* ซ่อนไว้ก่อน จะเปิดด้วย JS */
        animation: fadeIn 0.4s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .flex-row { display: flex; gap: 15px; }
    .flex-row > div { flex: 1; }

    /* Checkbox & Switches */
    .toggle-wrapper {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 15px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 10px;
        cursor: pointer;
        transition: 0.2s;
    }
    .toggle-wrapper:hover { background: #f8fafc; border-color: #cbd5e1; }
    .toggle-wrapper input[type="checkbox"] { width: 20px; height: 20px; accent-color: #3b82f6; cursor: pointer; }
    .toggle-text strong { display: block; color: #1e293b; font-size: 1rem; }
    .toggle-text small { color: #64748b; font-size: 0.85rem; }

    /* Buttons */
    .btn-submit {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 12px;
        font-size: 1.15rem;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3);
        transition: 0.3s;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
    }
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 25px rgba(37, 99, 235, 0.4); }
    .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }

    /* Quest List Table */
    .quest-list { list-style: none; padding: 0; margin: 0; }
    .quest-item {
        padding: 15px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .quest-item:last-child { border-bottom: none; }
    .quest-title { font-weight: 600; color: #1e293b; font-size: 1.05rem; }
    .quest-meta { font-size: 0.85rem; color: #64748b; display: flex; gap: 10px; flex-wrap: wrap; }
    .badge {
        background: #e0f2fe; color: #0369a1; padding: 3px 8px; 
        border-radius: 6px; font-weight: 600; font-size: 0.75rem;
    }
    .badge.active { background: #dcfce7; color: #166534; }
    .badge.inactive { background: #fee2e2; color: #991b1b; }

    .quest-actions { display: flex; gap: 8px; margin-top: 10px; }
    .btn-sm {
        padding: 6px 12px; font-size: 0.85rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; transition: 0.2s;
    }
    .btn-toggle { background: #f1f5f9; color: #475569; }
    .btn-toggle:hover { background: #e2e8f0; }
    .btn-delete { background: #fee2e2; color: #dc2626; }
    .btn-delete:hover { background: #fca5a5; }

    /* Toast Notification */
    #toast {
        position: fixed; top: 20px; right: 20px; background: #10b981; color: white;
        padding: 15px 25px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        transform: translateX(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        z-index: 9999; font-weight: 600; display: flex; align-items: center; gap: 10px;
    }
    #toast.show { transform: translateX(0); }
    #toast.error { background: #ef4444; }
</style>

<div class="quest-builder-container">
    
    <div class="main-form-area">
        <form id="questForm" onsubmit="submitQuest(event)">
            
            <div class="card">
                <div class="card-header">📝 1. ข้อมูลพื้นฐานภารกิจ</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">ชื่อภารกิจ (Quest Title) <span style="color:red">*</span></label>
                        <input type="text" id="q_title" class="form-control" placeholder="เช่น ภารกิจระเบิดไฮโดรเจน, การไทเทรตขั้นพื้นฐาน" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">คำอธิบาย / คำใบ้ (Description) <span style="color:red">*</span></label>
                        <textarea id="q_desc" class="form-control" placeholder="อธิบายให้นักเรียนฟังว่าต้องทำอะไร หรือให้คำใบ้เป็นสมการเคมี..." required></textarea>
                    </div>

                    <div class="flex-row">
                        <div class="form-group">
                            <label class="form-label">มอบหมายให้ห้องเรียน</label>
                            <select id="q_class" class="form-select">
                                <option value="">🌐 ทุกห้องเรียนที่ฉันสอน</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">คะแนนรางวัล (XP) <span style="color:red">*</span></label>
                            <input type="number" id="q_reward" class="form-control" value="500" min="10" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">🧪 2. สารเคมีและการทำปฏิกิริยา</div>
                <div class="card-body">
                    <div class="flex-row">
                        <div class="form-group">
                            <label class="form-label">สารตั้งต้นที่ 1 (Reactant 1) <span style="color:red">*</span></label>
                            <select id="q_chem1" class="form-select" required>
                                <option value="">-- เลือกสารเคมี --</option>
                                <?php foreach ($chemicals as $ch): ?>
                                    <option value="<?= $ch['id'] ?>">
                                        <?= htmlspecialchars($ch['name']) ?> 
                                        <?= $ch['formula'] ? "({$ch['formula']})" : "" ?> 
                                        [<?= $ch['state'] ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">สารตั้งต้นที่ 2 (Reactant 2 - ตัวเลือก)</label>
                            <select id="q_chem2" class="form-select">
                                <option value="">-- ไม่ต้องการ (ปฏิกิริยาเดี่ยว) --</option>
                                <?php foreach ($chemicals as $ch): ?>
                                    <option value="<?= $ch['id'] ?>">
                                        <?= htmlspecialchars($ch['name']) ?> 
                                        <?= $ch['formula'] ? "({$ch['formula']})" : "" ?> 
                                        [<?= $ch['state'] ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">ชื่อผลิตภัณฑ์ที่คาดหวัง (Expected Product) <span style="color:red">*</span></label>
                        <input type="text" id="q_product" class="form-control" placeholder="เช่น ก๊าซไฮโดรเจน, ตะกอนสีเหลือง" required>
                    </div>

                    <label class="toggle-wrapper" onclick="toggleLogicBlock('logic-amount')">
                        <input type="checkbox" id="q_strict_amount">
                        <div class="toggle-text">
                            <strong>⚖️ บังคับสัดส่วนมวลสาร (Stoichiometry)</strong>
                            <small>หากเปิดใช้งาน นักเรียนจะต้องชั่ง/ตวงสารให้ได้ปริมาณที่กำหนดเป๊ะๆ ถึงจะผ่าน</small>
                        </div>
                    </label>

                    <div class="logic-block" id="logic-amount">
                        <div class="flex-row">
                            <div class="form-group">
                                <label class="form-label">ปริมาณสารที่ 1 (กรัม / มิลลิลิตร)</label>
                                <input type="number" step="0.1" id="q_amt1" class="form-control" placeholder="เช่น 5.0">
                            </div>
                            <div class="form-group">
                                <label class="form-label">ปริมาณสารที่ 2 (กรัม / มิลลิลิตร)</label>
                                <input type="number" step="0.1" id="q_amt2" class="form-control" placeholder="เช่น 20.0">
                            </div>
                        </div>
                        <small style="color:#64748b;">* ระบบจะอนุโลมความคลาดเคลื่อนให้ +/- 5% อัตโนมัติ</small>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">🌡️ 3. สภาพแวดล้อมและความปลอดภัย (Realism)</div>
                <div class="card-body">
                    
                    <label class="toggle-wrapper" onclick="toggleLogicBlock('logic-temp')">
                        <input type="checkbox" id="chk_temp">
                        <div class="toggle-text">
                            <strong>🔥 ควบคุมอุณหภูมิ (Temperature Control)</strong>
                            <small>ต้องต้มสารด้วยตะเกียงแอลกอฮอล์ให้อยู่ในช่วงอุณหภูมิที่กำหนด</small>
                        </div>
                    </label>
                    <div class="logic-block" id="logic-temp">
                        <div class="flex-row">
                            <div class="form-group">
                                <label class="form-label">อุณหภูมิต่ำสุด (°C)</label>
                                <input type="number" step="0.1" id="q_temp_min" class="form-control" placeholder="เช่น 80">
                            </div>
                            <div class="form-group">
                                <label class="form-label">อุณหภูมิสูงสุด (°C)</label>
                                <input type="number" step="0.1" id="q_temp_max" class="form-control" placeholder="เช่น 100">
                            </div>
                        </div>
                    </div>

                    <label class="toggle-wrapper">
                        <input type="checkbox" id="q_stirring">
                        <div class="toggle-text">
                            <strong>🥄 บังคับคนสารเคมี (Require Stirring)</strong>
                            <small>นักเรียนต้องกดใช้แท่งแก้วคนสารขณะประมวลผล ไม่เช่นนั้นสารจะไม่ทำปฏิกิริยากัน</small>
                        </div>
                    </label>

                    <hr style="border-top:1px solid #e2e8f0; margin: 20px 0;">

                    <h4 style="margin-top:0; color:#1e293b; font-size:1rem;">อุปกรณ์ความปลอดภัยพื้นฐาน</h4>
                    <div class="flex-row" style="margin-bottom: 20px;">
                        <label class="toggle-wrapper" style="flex:1;">
                            <input type="checkbox" id="q_goggles" checked>
                            <div class="toggle-text"><strong>🥽 แว่นตานิรภัย</strong></div>
                        </label>
                        <label class="toggle-wrapper" style="flex:1;">
                            <input type="checkbox" id="q_gloves">
                            <div class="toggle-text"><strong>🧤 ถุงมือยาง</strong></div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label">จำนวนครั้งที่ยอมให้ทำสารหกได้ (Spill Tolerance)</label>
                        <select id="q_spills" class="form-select">
                            <option value="0">0 ครั้ง (ห้ามหกเด็ดขาด = สอบตกทันที)</option>
                            <option value="1">1 ครั้ง</option>
                            <option value="3" selected>3 ครั้ง (มาตรฐาน)</option>
                            <option value="99">ไม่จำกัด (โหมดฝึกซ้อม)</option>
                        </select>
                    </div>

                </div>
            </div>

            <button type="submit" class="btn-submit" id="btnSubmit">
                <span>🚀 บันทึกและสร้างภารกิจ</span>
            </button>
        </form>
    </div>

    <div class="sidebar-area">
        <div class="card" style="position: sticky; top: 20px;">
            <div class="card-header" style="background: #3b82f6; font-size: 1.1rem;">
                📚 ภารกิจของฉัน (My Quests)
            </div>
            <div class="card-body" style="padding: 0; max-height: 700px; overflow-y: auto;">
                <?php if (empty($my_quests)): ?>
                    <div style="padding: 30px; text-align: center; color: #64748b;">
                        ยังไม่มีภารกิจ ลองสร้างภารกิจแรกของคุณดูสิ!
                    </div>
                <?php else: ?>
                    <ul class="quest-list">
                        <?php foreach ($my_quests as $q): ?>
                            <li class="quest-item" id="quest-item-<?= $q['id'] ?>">
                                <div class="quest-title"><?= htmlspecialchars($q['title']) ?></div>
                                <div class="quest-meta">
                                    <span class="badge <?= $q['is_active'] ? 'active' : 'inactive' ?>" id="status-<?= $q['id'] ?>">
                                        <?= $q['is_active'] ? '🟢 เปิดใช้งาน' : '🔴 ปิดอยู่' ?>
                                    </span>
                                    <span class="badge">XP: <?= $q['reward_points'] ?></span>
                                    <?php if($q['class_name']): ?>
                                        <span class="badge" style="background:#f3e8ff; color:#7e22ce;"> <?= htmlspecialchars($q['class_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: #64748b;">
                                    ผสม: <?= htmlspecialchars($q['chem1_name']) ?> 
                                    <?= $q['chem2_name'] ? " + " . htmlspecialchars($q['chem2_name']) : "" ?>
                                </div>
                                <div class="quest-actions">
                                    <button class="btn-sm btn-toggle" onclick="toggleQuest(<?= $q['id'] ?>, <?= $q['is_active'] ? 0 : 1 ?>)">
                                        <?= $q['is_active'] ? 'ปิดชั่วคราว' : 'เปิดใช้งาน' ?>
                                    </button>
                                    <button class="btn-sm btn-delete" onclick="deleteQuest(<?= $q['id'] ?>)">ลบทิ้ง</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrf_token" value="<?= $csrf ?>">

<div id="toast">
    <span id="toast-icon">✅</span>
    <span id="toast-msg">Success!</span>
</div>

<script>
    // --- 1. UI Logic ---

    // เปิด/ปิด การแสดงผลกล่องเงื่อนไขพิเศษ
    function toggleLogicBlock(blockId) {
        // ใช้ setTimeout เล็กน้อยเพื่อให้ checkbox update state ก่อน
        setTimeout(() => {
            const block = document.getElementById(blockId);
            if (blockId === 'logic-amount') {
                const isChecked = document.getElementById('q_strict_amount').checked;
                block.style.display = isChecked ? 'block' : 'none';
            } else if (blockId === 'logic-temp') {
                const isChecked = document.getElementById('chk_temp').checked;
                block.style.display = isChecked ? 'block' : 'none';
            }
        }, 50);
    }

    // แสดง Toast Notification
    function showToast(message, isError = false) {
        const toast = document.getElementById('toast');
        document.getElementById('toast-msg').innerText = message;
        document.getElementById('toast-icon').innerText = isError ? '❌' : '✅';
        
        if (isError) {
            toast.classList.add('error');
        } else {
            toast.classList.remove('error');
        }

        toast.classList.add('show');
        setTimeout(() => { toast.classList.remove('show'); }, 3500);
    }

    // --- 2. API Logic ---

    // ฟังก์ชันรวบรวมข้อมูลและส่งไปบันทึก
    async function submitQuest(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmit');
        btn.disabled = true;
        btn.innerHTML = '⏳ กำลังบันทึกข้อมูล...';

        // ดึงข้อมูลทั้งหมดจาก Form
        const payload = {
            action: 'create_quest',
            csrf_token: document.getElementById('csrf_token').value,
            title: document.getElementById('q_title').value,
            description: document.getElementById('q_desc').value,
            target_class_id: document.getElementById('q_class').value,
            reward_points: document.getElementById('q_reward').value,
            
            target_chem1: document.getElementById('q_chem1').value,
            target_chem2: document.getElementById('q_chem2').value,
            target_product: document.getElementById('q_product').value,
            
            strict_amount: document.getElementById('q_strict_amount').checked,
            amount_chem1: document.getElementById('q_amt1').value,
            amount_chem2: document.getElementById('q_amt2').value,

            required_temp_min: document.getElementById('chk_temp').checked ? document.getElementById('q_temp_min').value : '',
            required_temp_max: document.getElementById('chk_temp').checked ? document.getElementById('q_temp_max').value : '',
            required_stirring: document.getElementById('q_stirring').checked,

            safety_goggles: document.getElementById('q_goggles').checked,
            safety_gloves: document.getElementById('q_gloves').checked,
            max_spill_allowed: document.getElementById('q_spills').value
        };

        try {
            const response = await fetch('api_teacher_lab.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                setTimeout(() => { location.reload(); }, 1500); // รีเฟรชหน้าเพื่ออัปเดตตาราง
            } else {
                showToast(result.message, true);
                btn.disabled = false;
                btn.innerHTML = '🚀 บันทึกและสร้างภารกิจ';
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', true);
            btn.disabled = false;
            btn.innerHTML = '🚀 บันทึกและสร้างภารกิจ';
        }
    }

    // ฟังก์ชันเปิด-ปิดสถานะของเควส
    async function toggleQuest(questId, newStatus) {
        const payload = {
            action: 'toggle_active',
            csrf_token: document.getElementById('csrf_token').value,
            quest_id: questId,
            is_active: newStatus
        };

        try {
            const response = await fetch('api_teacher_lab.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                // อัปเดต UI ทันทีโดยไม่ต้องรีเฟรช
                const badge = document.getElementById(`status-${questId}`);
                const btn = document.querySelector(`#quest-item-${questId} .btn-toggle`);
                
                if (newStatus === 1) {
                    badge.className = 'badge active';
                    badge.innerText = '🟢 เปิดใช้งาน';
                    btn.innerText = 'ปิดชั่วคราว';
                    btn.setAttribute('onclick', `toggleQuest(${questId}, 0)`);
                } else {
                    badge.className = 'badge inactive';
                    badge.innerText = '🔴 ปิดอยู่';
                    btn.innerText = 'เปิดใช้งาน';
                    btn.setAttribute('onclick', `toggleQuest(${questId}, 1)`);
                }
            } else {
                showToast(result.message, true);
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดเครือข่าย', true);
        }
    }

    // ฟังก์ชันลบเควส
    async function deleteQuest(questId) {
        if (!confirm('คุณแน่ใจหรือไม่ว่าต้องการลบภารกิจนี้? ข้อมูลการทำภารกิจของนักเรียนที่เกี่ยวข้องอาจได้รับผลกระทบ')) return;

        const payload = {
            action: 'delete_quest',
            csrf_token: document.getElementById('csrf_token').value,
            quest_id: questId
        };

        try {
            const response = await fetch('api_teacher_lab.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                // ซ่อน element ที่ลบไปแล้วแบบมีแอนิเมชัน
                const item = document.getElementById(`quest-item-${questId}`);
                item.style.opacity = '0';
                setTimeout(() => { item.remove(); }, 300);
            } else {
                showToast(result.message, true);
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดเครือข่าย', true);
        }
    }
</script>

<?php require_once 'footer.php'; ?>