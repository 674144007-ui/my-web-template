<?php
/**
 * dev_manage_classes.php - ระบบจัดการโครงสร้างชั้นเรียน (Class Generator Dashboard)
 * สำหรับ Developer / Admin ใช้สร้าง แก้ไข และลบห้องเรียนแบบกลุ่ม (Bulk)
 */

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';

// บังคับสิทธิ์การเข้าถึงเฉพาะ Developer และ Admin
requireRole(['developer', 'developer']);

$page_title = "จัดการโครงสร้างชั้นเรียน (Class Management)";
$csrf = generate_csrf_token();

// โหลดรายชื่อครูทั้งหมดมาไว้ทำ Dropdown สำหรับเลือกครูที่ปรึกษา (ในโหมด Edit)
$teachers = [];
$stmt_t = $pdo->query("SELECT id, display_name FROM users WHERE role = 'teacher' AND is_deleted = 0 ORDER BY display_name ASC");
if ($stmt_t) {
    while ($row = $stmt_t->fetch()) {
        $teachers[] = $row;
    }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&family=Orbitron:wght@700&display=swap" rel="stylesheet">

<style>
    /* ============================================================
       🎨 CSS STYLING FOR CLASS MANAGEMENT DASHBOARD
       ============================================================ */
    body {
        background-color: #f8fafc;
        font-family: 'Sarabun', sans-serif;
    }

    .dashboard-container {
        max-width: 1300px;
        margin: 30px auto;
        padding: 0 20px;
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 30px;
    }

    @media (max-width: 992px) {
        .dashboard-container { grid-template-columns: 1fr; }
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
        font-size: 1.2rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-body { padding: 25px; }

    /* Form UI */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; color: #334155; margin-bottom: 8px; font-size: 0.95rem; }
    .form-control, .form-select {
        width: 100%; padding: 12px 15px; border: 1px solid #cbd5e1; border-radius: 10px;
        font-family: inherit; font-size: 1rem; color: #1e293b; background: #f8fafc; transition: 0.3s; box-sizing: border-box;
    }
    .form-control:focus, .form-select:focus {
        border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; background: #ffffff;
    }

    .flex-row { display: flex; gap: 15px; }
    .flex-row > div { flex: 1; }

    /* Buttons */
    .btn-submit {
        background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 15px; border-radius: 12px;
        font-size: 1.1rem; font-weight: 600; cursor: pointer; width: 100%; box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3); transition: 0.3s;
    }
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 15px 25px rgba(16, 185, 129, 0.4); }
    .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; transform: none; box-shadow: none; }

    /* Class Display Grouping */
    .level-group {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 20px; overflow: hidden;
    }
    .level-header {
        background: #e2e8f0; color: #1e293b; padding: 12px 20px; font-size: 1.1rem; font-weight: bold;
        display: flex; justify-content: space-between; align-items: center;
    }
    .level-count { background: #3b82f6; color: white; padding: 2px 10px; border-radius: 20px; font-size: 0.85rem; }
    
    .room-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; padding: 20px;
    }
    
    /* Room Card */
    .room-card {
        background: white; border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px;
        display: flex; flex-direction: column; justify-content: space-between; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02);
    }
    .room-card:hover { border-color: #3b82f6; box-shadow: 0 5px 15px rgba(59, 130, 246, 0.15); transform: translateY(-2px); }
    .room-card.inactive { opacity: 0.6; filter: grayscale(0.5); }
    
    .room-title { font-size: 1.25rem; font-weight: bold; color: #0f172a; margin-bottom: 5px; font-family: 'Orbitron', sans-serif; }
    .room-meta { font-size: 0.85rem; color: #64748b; margin-bottom: 15px; min-height: 20px; }
    
    .room-actions { display: flex; gap: 8px; justify-content: flex-end; border-top: 1px solid #f1f5f9; padding-top: 10px; }
    .btn-action {
        padding: 6px 12px; font-size: 0.85rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; transition: 0.2s; font-family: inherit;
    }
    .btn-edit { background: #e0f2fe; color: #0284c7; }
    .btn-edit:hover { background: #bae6fd; }
    .btn-toggle { background: #fef3c7; color: #ca8a04; }
    .btn-toggle:hover { background: #fde047; }
    .btn-delete { background: #fee2e2; color: #dc2626; }
    .btn-delete:hover { background: #fca5a5; }

    /* Modal Styling */
    .modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.8);
        display: none; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(5px);
        opacity: 0; transition: opacity 0.3s ease;
    }
    .modal-overlay.show { display: flex; opacity: 1; }
    .modal-box {
        background: white; border-radius: 16px; width: 100%; max-width: 500px; box-shadow: 0 25px 50px rgba(0,0,0,0.5);
        transform: translateY(-20px); transition: transform 0.3s ease; overflow: hidden;
    }
    .modal-overlay.show .modal-box { transform: translateY(0); }
    .modal-header { padding: 20px 25px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 1.2rem; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
    .modal-close { background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; }
    .modal-body { padding: 25px; }
    .modal-footer { padding: 15px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; }

    /* Toast Notification */
    #toast {
        position: fixed; bottom: 30px; right: 30px; background: #10b981; color: white; padding: 15px 25px; border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2); transform: translateY(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        z-index: 9999; font-weight: 600; display: flex; align-items: center; gap: 10px;
    }
    #toast.show { transform: translateY(0); }
    #toast.error { background: #ef4444; }
</style>

<div class="dashboard-container">
    
    <div class="generator-area">
        <div class="card" style="position: sticky; top: 20px;">
            <div class="card-header">⚡ สร้างห้องเรียนรวดเร็ว (Bulk Generator)</div>
            <div class="card-body">
                <form id="bulkForm" onsubmit="submitBulkCreate(event)">
                    <div class="form-group">
                        <label class="form-label">ระดับชั้น (Level) <span style="color:red">*</span></label>
                        <input type="text" id="gen_level" class="form-control" placeholder="เช่น ม.1 หรือ ป.6" required>
                        <small style="color:#64748b; font-size: 0.8rem; margin-top:5px; display:block;">
                            * ระบบจะนำค่านี้ไปเป็นคำนำหน้าชื่อห้อง
                        </small>
                    </div>

                    <div class="flex-row">
                        <div class="form-group">
                            <label class="form-label">เริ่มที่ห้อง (Start) <span style="color:red">*</span></label>
                            <input type="number" id="gen_start" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">ถึงห้อง (End) <span style="color:red">*</span></label>
                            <input type="number" id="gen_end" class="form-control" value="10" min="1" required>
                        </div>
                    </div>
                    
                    <div style="background:#eff6ff; border:1px dashed #38bdf8; padding:15px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; color:#0369a1;">
                        <strong>💡 ตัวอย่างผลลัพธ์:</strong><br>
                        หากระบุ "ม.1" ห้อง "1" ถึง "10"<br>
                        ระบบจะสร้าง ม.1/1, ม.1/2 ... ไปจนถึง ม.1/10 อัตโนมัติ
                    </div>

                    <button type="submit" class="btn-submit" id="btnGenerate">
                        <span>🚀 สร้างโครงสร้างห้องเรียน</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="display-area">
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                🏫 โครงสร้างชั้นเรียนทั้งหมดในระบบ
            </div>
            <div class="card-body" id="classDisplayArea" style="min-height: 400px; background: #f1f5f9;">
                <div style="text-align:center; padding:50px; color:#64748b;">
                    กำลังโหลดข้อมูลโครงสร้างชั้นเรียน...
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <span>✏️ แก้ไขข้อมูลห้องเรียน</span>
            <button class="modal-close" onclick="closeEditModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="edit_class_id">
            
            <div class="form-group">
                <label class="form-label">ชื่อห้องเรียน (แสดงผล) <span style="color:red">*</span></label>
                <input type="text" id="edit_class_name" class="form-control" required>
                <small style="color:#64748b; font-size: 0.8rem; margin-top:5px; display:block;">เช่น ม.4/1 (GIFTED)</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">ครูที่ปรึกษา</label>
                <select id="edit_teacher_id" class="form-select">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="closeEditModal()" class="btn-action" style="background:#e2e8f0; color:#334155; padding:10px 20px;">ยกเลิก</button>
            <button onclick="submitEditClass()" class="btn-action" style="background:#3b82f6; color:white; padding:10px 20px;">💾 บันทึกการเปลี่ยนแปลง</button>
        </div>
    </div>
</div>

<input type="hidden" id="csrf_token" value="<?= $csrf ?>">

<div id="toast">
    <span id="toast-icon">✅</span>
    <span id="toast-msg">Success!</span>
</div>

<script>
    /* ============================================================
       📍 JAVASCRIPT LOGIC (Fetch API & Rendering)
       ============================================================ */

    // 1. Initial Load
    document.addEventListener('DOMContentLoaded', () => {
        loadAllClasses();
    });

    // 2. ฟังก์ชันโหลดและวาด HTML ห้องเรียนทั้งหมด
    async function loadAllClasses() {
        const displayArea = document.getElementById('classDisplayArea');
        try {
            const response = await fetch(`api_classes.php?action=get_all_classes`);
            const result = await response.json();

            if (result.status === 'success') {
                renderClasses(result.data);
            } else {
                displayArea.innerHTML = `<div style="color:red; text-align:center; padding:30px;">Error: ${result.message}</div>`;
            }
        } catch (error) {
            displayArea.innerHTML = `<div style="color:red; text-align:center; padding:30px;">Network Error: ไม่สามารถเชื่อมต่อฐานข้อมูลได้</div>`;
        }
    }

    // 3. ฟังก์ชันจัดกลุ่ม (Grouping) และวาด HTML (Render)
    function renderClasses(classesData) {
        const displayArea = document.getElementById('classDisplayArea');
        
        if (classesData.length === 0) {
            displayArea.innerHTML = `
                <div style="text-align:center; padding:50px; color:#64748b;">
                    <span style="font-size:3rem; display:block; margin-bottom:15px;">🏗️</span>
                    ยังไม่มีโครงสร้างชั้นเรียนในระบบ<br>กรุณาสร้างจากแผงควบคุมด้านซ้ายมือ
                </div>`;
            return;
        }

        // จัดกลุ่มข้อมูลตาม 'level' (เช่น เอา ม.1 ไปรวมกัน)
        const grouped = classesData.reduce((acc, obj) => {
            let key = obj.level;
            if (!acc[key]) acc[key] = [];
            acc[key].push(obj);
            return acc;
        }, {});

        let html = '';

        // วนลูปสร้าง HTML ตามกลุ่ม
        for (const [level, rooms] of Object.entries(grouped)) {
            html += `
            <div class="level-group">
                <div class="level-header">
                    <span>📚 ระดับชั้น: ${level}</span>
                    <span class="level-count">${rooms.length} ห้อง</span>
                </div>
                <div class="room-grid">
            `;

            rooms.forEach(room => {
                const isActive = parseInt(room.is_active) === 1;
                const statusColor = isActive ? '#10b981' : '#94a3b8';
                const statusDot = `<span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:${statusColor}; margin-right:5px;"></span>`;
                
                const teacherName = room.teacher_name ? `👨‍🏫 ${room.teacher_name}` : `<i>(ยังไม่ระบุครูที่ปรึกษา)</i>`;

                html += `
                <div class="room-card ${isActive ? '' : 'inactive'}" id="card-${room.id}">
                    <div>
                        <div class="room-title">${statusDot} ${room.class_name}</div>
                        <div class="room-meta">${teacherName}</div>
                    </div>
                    <div class="room-actions">
                        <button class="btn-action btn-edit" title="แก้ไข" 
                            onclick="openEditModal(${room.id}, '${room.class_name}', '${room.teacher_id || ''}')">✏️ Edit</button>
                        
                        <button class="btn-action btn-toggle" title="${isActive ? 'ปิดใช้งาน' : 'เปิดใช้งาน'}" 
                            onclick="toggleStatus(${room.id}, ${isActive ? 0 : 1})">
                            ${isActive ? '⏸️ Disable' : '▶️ Enable'}
                        </button>
                        
                        <button class="btn-action btn-delete" title="ลบถาวร" 
                            onclick="deleteClass(${room.id}, '${room.class_name}')">🗑️ Delete</button>
                    </div>
                </div>
                `;
            });

            html += `</div></div>`; // ปิด room-grid และ level-group
        }

        displayArea.innerHTML = html;
    }

    // 4. ฟังก์ชันส่งข้อมูลฟอร์ม Bulk Create ไปหา API
    async function submitBulkCreate(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGenerate');
        btn.disabled = true;
        btn.innerHTML = '⏳ กำลังสร้าง...';

        const payload = {
            action: 'create_bulk_classes',
            csrf_token: document.getElementById('csrf_token').value,
            level: document.getElementById('gen_level').value.trim(),
            start_room: parseInt(document.getElementById('gen_start').value),
            end_room: parseInt(document.getElementById('gen_end').value)
        };

        try {
            const response = await fetch('api_classes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                loadAllClasses(); // รีเฟรชตารางขวามือทันที
                
                // ล้างฟอร์ม
                document.getElementById('gen_start').value = "1";
                document.getElementById('gen_end').value = "10";
            } else {
                showToast(result.message, true);
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์', true);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '🚀 สร้างโครงสร้างห้องเรียน';
        }
    }

    // 5. เปิด-ปิดสถานะ (Toggle Active)
    async function toggleStatus(classId, newStatus) {
        const payload = {
            action: 'toggle_active',
            csrf_token: document.getElementById('csrf_token').value,
            class_id: classId,
            is_active: newStatus
        };

        try {
            const response = await fetch('api_classes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                loadAllClasses(); // รีโหลดเพื่อวาด UI ใหม่ให้ถูกต้อง
            } else {
                showToast(result.message, true);
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', true);
        }
    }

    // 6. ลบห้องเรียนถาวร
    async function deleteClass(classId, className) {
        if (!confirm(`คุณแน่ใจหรือไม่ว่าต้องการลบห้อง "${className}" ถาวร?\n\n*ระบบจะอนุญาตให้ลบก็ต่อเมื่อไม่มีนักเรียนหลงเหลืออยู่ในห้องนี้แล้วเท่านั้น`)) return;

        const payload = {
            action: 'delete_class',
            csrf_token: document.getElementById('csrf_token').value,
            class_id: classId
        };

        try {
            const response = await fetch('api_classes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                // ทำให้ Card หายไปแบบสวยงามก่อนรีโหลด
                const card = document.getElementById(`card-${classId}`);
                card.style.transform = "scale(0)";
                setTimeout(() => loadAllClasses(), 300);
            } else {
                showToast(result.message, true);
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', true);
        }
    }

    // 7. ระบบ Modal สำหรับ Edit ชื่อและครูที่ปรึกษา
    function openEditModal(id, currentName, teacherId) {
        document.getElementById('edit_class_id').value = id;
        document.getElementById('edit_class_name').value = currentName;
        document.getElementById('edit_teacher_id').value = teacherId;
        
        const modal = document.getElementById('editModal');
        modal.classList.add('show');
    }

    function closeEditModal() {
        const modal = document.getElementById('editModal');
        modal.classList.remove('show');
    }

    async function submitEditClass() {
        const classId = document.getElementById('edit_class_id').value;
        const className = document.getElementById('edit_class_name').value;
        const teacherId = document.getElementById('edit_teacher_id').value;

        if(!className) {
            showToast('กรุณาระบุชื่อห้องเรียน', true); return;
        }

        const payload = {
            action: 'update_class',
            csrf_token: document.getElementById('csrf_token').value,
            class_id: classId,
            class_name: className,
            teacher_id: teacherId
        };

        try {
            const response = await fetch('api_classes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.status === 'success') {
                showToast(result.message);
                closeEditModal();
                loadAllClasses();
            } else {
                showToast(result.message, true);
            }
        } catch (error) {
            showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', true);
        }
    }

    // 8. Toast Notification Utility
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
        setTimeout(() => { toast.classList.remove('show'); }, 3000);
    }
</script>

<?php require_once 'footer.php'; ?>