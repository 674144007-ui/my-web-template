<?php
/**
 * teacher_assignments.php - ระบบจัดการภารกิจและการบ้าน (Assignment Manager)
 * สถาปัตยกรรม: Unified Ecosystem Phase 6 (รองรับการตั้งค่าเกมจาก Virtual Lab JSON)
 */

require_once __DIR__ . '/env_loader.php';
require_once 'config.php';

// 🚨 ป้องกันหน้าเว็บพังหากเชื่อมต่อฐานข้อมูลไม่ได้
require_once 'security.php';
require_once 'auth.php';
require_once __DIR__ . '/db.php';
requireLogin();

// =========================================================================
// 🔐 ตรวจสอบสิทธิ์ (เฉพาะ Admin, Developer และ Teacher เท่านั้น)
// =========================================================================
$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if (!in_array($role, ['developer', 'teacher'])) {
    header("Location: index.php?error=access_denied");
    exit;
}

$page_title = "สั่งการบ้าน / จัดการภารกิจ (Assignments)";
$success_msg = '';
$error_msg = '';

// =========================================================================
// ⚙️ POST REQUEST HANDLER: การสร้างภารกิจใหม่
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 🛠️ Action: Create Assignment
    if ($_POST['action'] === 'create') {
        $title = trim($_POST['title'] ?? '');
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $target_class_id = !empty($_POST['target_class_id']) ? intval($_POST['target_class_id']) : NULL; // NULL = ทุกห้อง
        $reward_points = intval($_POST['reward_points'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $game_type = $_POST['game_type'] ?? 'none';
        $game_settings = trim($_POST['game_settings'] ?? '');
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] . ' 23:59:59' : NULL;

        // Validation พื้นฐาน
        if (empty($title) || $subject_id === 0) {
            $error_msg = "กรุณากรอกชื่อภารกิจและเลือกวิชาให้ครบถ้วน";
        } else {
            // 🚨 Validation: ตรวจสอบความถูกต้องของ JSON กรณีที่เป็น Virtual Lab
            if ($game_type === 'chem_lab') {
                if (empty($game_settings)) {
                    $error_msg = "กรุณาวางโค้ด JSON จากโหมด Sandbox เพื่อตั้งค่าห้องปฏิบัติการ";
                } else {
                    json_decode($game_settings);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $error_msg = "รูปแบบโค้ด JSON ไม่ถูกต้อง กรุณาคัดลอกมาจากโหมด Sandbox ใหม่อีกครั้ง (Error: " . json_last_error_msg() . ")";
                    }
                }
            } else {
                $game_settings = NULL; // ถ้าไม่ใช่เกม ให้เคลียร์ JSON ทิ้ง
            }

            // ถ้าไม่มี Error ทำการ Insert ข้อมูล
            if (empty($error_msg)) {
                try {
                    $stmt_insert = $pdo->prepare("
                        INSERT INTO assignments (subject_id, title, description, target_class_id, created_by, due_date, reward_points, game_type, game_settings, is_active, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $stmt_insert->execute([
                        $subject_id, $title, $description, $target_class_id, $user_id, $due_date, $reward_points, $game_type, $game_settings
                    ]);
                    
                    // บันทึก Log กล่องดำของระบบ
                    $log_stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, details) VALUES (?, 'CREATE_ASSIGNMENT', ?)");
                    $log_stmt->execute([$user_id, "Created assignment: {$title} (Type: {$game_type})"]);

                    $success_msg = "สร้างภารกิจ '".$title."' เรียบร้อยแล้ว!";
                } catch (PDOException $e) {
                    $error_msg = "เกิดข้อผิดพลาดในการบันทึกฐานข้อมูล: " . $e->getMessage();
                }
            }
        }
    }

    // 🛠️ Action: Delete Assignment
    if ($_POST['action'] === 'delete' && isset($_POST['assignment_id'])) {
        $del_id = intval($_POST['assignment_id']);
        try {
            // เช็คสิทธิ์ก่อนลบ (ต้องเป็นคนสร้าง หรือเป็น admin/dev)
            if (in_array($role, ['developer', 'developer'])) {
                $stmt_del = $pdo->prepare("DELETE FROM assignments WHERE id = ?");
                $stmt_del->execute([$del_id]);
            } else {
                $stmt_del = $pdo->prepare("DELETE FROM assignments WHERE id = ? AND created_by = ?");
                $stmt_del->execute([$del_id, $user_id]);
            }
            
            $log_stmt = $pdo->prepare("INSERT INTO system_logs (user_id, action, details) VALUES (?, 'DELETE_ASSIGNMENT', ?)");
            $log_stmt->execute([$user_id, "Deleted assignment ID: {$del_id}"]);

            $success_msg = "ลบภารกิจออกจากระบบเรียบร้อยแล้ว";
        } catch (PDOException $e) {
            $error_msg = "ไม่สามารถลบได้ (อาจมีข้อมูลการส่งงานของนักเรียนผูกอยู่): " . $e->getMessage();
        }
    }
}

// =========================================================================
// 📥 ดึงข้อมูลเพื่อสร้างฟอร์ม (Dropdowns)
// =========================================================================
$subjects = [];
$classes = [];
$assignments_list = [];

try {
    // 1. ดึงรายวิชา (ถ้าเป็นครู ให้ดึงเฉพาะวิชาที่สอน ถ้าแอดมิน ดึงทั้งหมด)
    if (in_array($role, ['developer', 'developer'])) {
        $stmt_sub = $pdo->query("SELECT id, subject_code, subject_name FROM subjects WHERE is_active = 1 ORDER BY subject_name");
        $subjects = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt_sub = $pdo->prepare("
            SELECT DISTINCT s.id, s.subject_code, s.subject_name 
            FROM subjects s 
            JOIN class_schedules cs ON s.id = cs.subject_id 
            WHERE cs.teacher_id = ? AND s.is_active = 1
        ");
        $stmt_sub->execute([$user_id]);
        $subjects = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. ดึงห้องเรียน
    $stmt_cls = $pdo->query("SELECT id, class_name, level, room FROM classes WHERE is_active = 1 ORDER BY level, room");
    $classes = $stmt_cls->fetchAll(PDO::FETCH_ASSOC);

    // 3. ดึงรายการภารกิจที่มีอยู่เพื่อแสดงในตาราง
    if (in_array($role, ['developer', 'developer'])) {
        $stmt_list = $pdo->query("
            SELECT a.*, s.subject_name, c.class_name, u.display_name as teacher_name 
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            LEFT JOIN classes c ON a.target_class_id = c.id
            JOIN users u ON a.created_by = u.id
            ORDER BY a.created_at DESC
        ");
        $assignments_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt_list = $pdo->prepare("
            SELECT a.*, s.subject_name, c.class_name, u.display_name as teacher_name 
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            LEFT JOIN classes c ON a.target_class_id = c.id
            JOIN users u ON a.created_by = u.id
            WHERE a.created_by = ?
            ORDER BY a.created_at DESC
        ");
        $stmt_list->execute([$user_id]);
        $assignments_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    error_log("Teacher Assignments DB Error: " . $e->getMessage());
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=Prompt:wght@300;400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--ac:<?= h($tenant['primary_color'] ?? '#1e3a8a') ?>;}
*{box-sizing:border-box}
.ta-wrap{max-width:1100px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}

/* Hero */
.ta-hero{background:var(--ac);border-radius:18px;padding:28px 32px;margin-bottom:22px;color:#fff;
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;
    position:relative;overflow:hidden}
.ta-hero::after{content:'📝';position:absolute;right:20px;bottom:-8px;font-size:6rem;opacity:.1;pointer-events:none}
.ta-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:8px}
.ta-hero h1{font-size:1.55rem;font-weight:700;margin:0 0 4px}
.ta-hero .sub{font-size:.85rem;opacity:.85;margin:0}

/* Alert */
.ta-alert{padding:12px 18px;border-radius:10px;margin-bottom:18px;font-size:.88rem;font-weight:500}
.ta-alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.ta-alert.err{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca}

/* Section */
.ta-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:24px 0 14px;display:flex;align-items:center;gap:8px}
.ta-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* Form Card */
.ta-form-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:24px}
.ta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:16px}
.ta-fg{display:flex;flex-direction:column;gap:4px}
.ta-fg label{font-size:.78rem;color:#64748b;font-weight:600}
.ta-fg input,.ta-fg select,.ta-fg textarea{
    padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;
    font-family:'Prompt',sans-serif;font-size:.9rem;width:100%;transition:.2s;resize:vertical}
.ta-fg input:focus,.ta-fg select:focus,.ta-fg textarea:focus{
    outline:none;border-color:var(--ac);box-shadow:0 0 0 3px rgba(30,58,138,.07)}
.ta-fg.full{grid-column:1/-1}
.ta-btn{padding:10px 24px;border:none;border-radius:9px;cursor:pointer;
    font-family:'Prompt',sans-serif;font-size:.9rem;font-weight:600;transition:.2s}
.ta-btn.primary{background:var(--ac);color:#fff}
.ta-btn.primary:hover{opacity:.85}
.ta-btn.ghost{background:#f8fafc;color:#64748b;border:1px solid #e2e8f0}
.ta-btn.ghost:hover{background:#f1f5f9}
.ta-btn.danger{background:#ef4444;color:#fff}

/* Assignment list */
.ta-list{display:flex;flex-direction:column;gap:12px}
.ta-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 22px;
    transition:.2s;position:relative;overflow:hidden}
.ta-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--ac)}
.ta-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.ta-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:8px}
.ta-card h3{font-size:.95rem;font-weight:600;color:#0f172a;margin:0}
.ta-card .desc{font-size:.78rem;color:#64748b;margin-top:3px}
.ta-card-bot{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;
    padding-top:12px;border-top:1px solid #f1f5f9}
.ta-chip{font-size:.72rem;background:#f1f5f9;color:#475569;padding:3px 10px;border-radius:20px;border:1px solid #e2e8f0}
.ta-chip.type-lab{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.ta-chip.type-game{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
.ta-chip.type-quiz{background:#fef3c7;color:#92400e;border-color:#fde68a}
.ta-del{margin-left:auto;font-size:.78rem;color:#ef4444;background:none;border:none;
    cursor:pointer;padding:4px 10px;border-radius:6px;transition:.15s}
.ta-del:hover{background:#fee2e2}
.ta-empty{text-align:center;padding:52px 20px;color:#94a3b8;background:#fff;
    border:1.5px solid #e2e8f0;border-radius:14px}
.ta-empty .ei{font-size:3rem;margin-bottom:12px}

@media(max-width:640px){.ta-grid{grid-template-columns:1fr}.ta-wrap{padding:14px 14px 60px}}
</style>

<div class="page-header">
    <div class="ta-wrap">
        <div class="ta-hero">
  <div style="position:relative;z-index:1">
    <div class="ta-tag">📝 Assignment Manager</div>
    <h1>สั่งการบ้าน / จัดการภารกิจ</h1>
    <p class="sub"><?= h($tenant['school_name'] ?? '') ?> · สร้างและจัดการการบ้านนักเรียน</p>
  </div>
</div>
    </div>
</div>

<div class="container mb-5">

    <?php if (!empty($success_msg)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'success', title: 'สำเร็จ!', text: '<?= $success_msg ?>', confirmButtonColor: '#10b981' });
            });
        </script>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: '<?= $error_msg ?>', confirmButtonColor: '#ef4444' });
            });
        </script>
    <?php endif; ?>

    <div class="row g-4">
        
        <div class="col-lg-5">
            <div class="assignment-card">
                <div class="card-header-custom">
                    <span>➕</span> สร้างภารกิจใหม่
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="mb-4">
                            <label class="form-label">หัวข้อภารกิจ / ชื่อการทดลอง <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required placeholder="เช่น แล็บ 10.1: ปฏิกิริยาการสะเทิน">
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">รายวิชา <span class="text-danger">*</span></label>
                                <select class="form-select" name="subject_id" required>
                                    <option value="" disabled selected>-- เลือกรายวิชา --</option>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= h($s['subject_code']) ?> - <?= h($s['subject_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">เป้าหมาย (ห้องเรียน)</label>
                                <select class="form-select" name="target_class_id">
                                    <option value="">ทุกห้องที่เรียนวิชานี้ (All Classes)</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= h($c['class_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">คำอธิบายโจทย์ / วิธีทำ</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="ระบุขั้นตอนการทำการทดลอง หรือคำอธิบายเพิ่มเติม"></textarea>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">ค่าประสบการณ์ (XP Reward)</label>
                                <input type="number" class="form-control" name="reward_points" value="1000" min="0" step="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">กำหนดส่ง (Due Date)</label>
                                <input type="date" class="form-control" name="due_date">
                                <small class="text-muted" style="font-size: 0.75rem;">เว้นว่างไว้ถ้าไม่มีกำหนดส่ง</small>
                            </div>
                        </div>

                        <hr class="my-4" style="border-color:#e2e8f0;">

                        <div class="mb-4">
                            <label class="form-label text-primary">ประเภทของภารกิจ (Assignment Type)</label>
                            <select class="form-select border-primary" name="game_type" id="gameTypeSelect" style="background-color: #f0fdf4;">
                                <option value="none">📝 การบ้านทั่วไป (ส่งไฟล์ / งานเขียน)</option>
                                <option value="chem_lab">🧪 ห้องปฏิบัติการเคมี (Virtual Chemistry Lab)</option>
                            </select>
                        </div>

                        <div class="json-editor-wrapper" id="jsonEditorWrapper">
                            <div class="json-editor-header">
                                <div style="font-family: 'Orbitron'; letter-spacing: 1px;"><span style="color:#ec4899">⚙️</span> Game Settings (JSON)</div>
                                <a href="lab_realistic.php" target="_blank" class="btn-sandbox" title="เปิดห้องแล็บเพื่อสร้างโค้ดอัตโนมัติ">
                                    สร้างโค้ดจาก Sandbox 🚀
                                </a>
                            </div>
                            <p style="color:#94a3b8; font-size:0.85rem; margin-top:-5px; margin-bottom:10px;">
                                ไปที่หน้า Sandbox ผสมสารเคมีให้เรียบร้อย แล้วกดปุ่ม "สร้างโจทย์ (Export)" เพื่อคัดลอกโค้ดมาวางที่นี่
                            </p>
                            <textarea class="textarea-json" name="game_settings" id="jsonInput" placeholder='{\n  "target_chem1": 307,\n  "amount_chem1": 20,\n  "safety_goggles": 1\n}'></textarea>
                            
                            <div id="jsonFeedback" style="margin-top: 10px; font-size: 0.85rem; font-weight: bold;"></div>
                        </div>

                        <div class="mt-4 text-end">
                            <button type="submit" class="btn-create">
                                <i class="bi bi-save"></i> บันทึกและสร้างภารกิจ
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <h4 class="fw-bold mb-3" style="color: #1e293b;">ประวัติการสร้างภารกิจของคุณ</h4>
            
            <?php if(empty($assignments_list)): ?>
                <div class="alert alert-info text-center py-5 border-0" style="border-radius:16px; background:#eff6ff;">
                    <h1 style="font-size: 3rem;">📂</h1>
                    <h5 class="fw-bold mt-3 text-primary">ยังไม่มีภารกิจ</h5>
                    <p class="text-muted">คุณยังไม่ได้สร้างภารกิจใดๆ ให้นักเรียน ลองสร้างภารกิจแรกของคุณดูสิ!</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>ภารกิจ / รายวิชา</th>
                                <th>ห้องเป้าหมาย</th>
                                <th>XP</th>
                                <th class="text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($assignments_list as $row): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 1.05rem;"><?= h($row['title']) ?></div>
                                        <div style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">
                                            วิชา: <?= h($row['subject_name']) ?>
                                            <span class="ms-2 <?= $row['game_type'] === 'chem_lab' ? 'badge-type-lab' : 'badge-type-normal' ?>">
                                                <?= $row['game_type'] === 'chem_lab' ? '🧪 Virtual Lab' : '📝 ทั่วไป' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="align-middle text-muted fw-bold">
                                        <?= $row['class_name'] ? h($row['class_name']) : 'ทุกห้อง (All)' ?>
                                    </td>
                                    <td class="align-middle">
                                        <span style="color:#f59e0b; font-weight:900; font-family:'Orbitron';">
                                            +<?= number_format($row['reward_points']) ?>
                                        </span>
                                    </td>
                                    <td class="align-middle text-center">
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('ยืนยันการลบภารกิจนี้? ข้อมูลการส่งงานของนักเรียนในภารกิจนี้อาจสูญหาย');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="assignment_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius: 8px;">
                                                <i class="bi bi-trash3"></i> ลบ
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const gameTypeSelect = document.getElementById('gameTypeSelect');
    const jsonEditorWrapper = document.getElementById('jsonEditorWrapper');
    const jsonInput = document.getElementById('jsonInput');
    const jsonFeedback = document.getElementById('jsonFeedback');

    // 1. ฟังก์ชันแสดง/ซ่อนกล่อง JSON ตามประเภทของเกม
    function toggleJsonEditor() {
        if (gameTypeSelect.value === 'chem_lab') {
            jsonEditorWrapper.style.display = 'block';
            jsonInput.setAttribute('required', 'required'); // บังคับให้ต้องวางโค้ด
        } else {
            jsonEditorWrapper.style.display = 'none';
            jsonInput.removeAttribute('required');
            jsonInput.value = ''; // เคลียร์ค่าถ้าเปลี่ยนเป็นแบบทั่วไป
            jsonFeedback.innerHTML = '';
        }
    }

    // ทำงานเมื่อโหลดหน้าเว็บ
    toggleJsonEditor();

    // ทำงานเมื่อมีการเปลี่ยนตัวเลือก Dropdown
    gameTypeSelect.addEventListener('change', toggleJsonEditor);

    // 2. ฟังก์ชันตรวจสอบความถูกต้องของโครงสร้าง JSON สดๆ ตอนพิมพ์ (Live Validation)
    jsonInput.addEventListener('input', function() {
        let val = this.value.trim();
        if (val === '') {
            jsonFeedback.innerHTML = '';
            this.style.borderColor = '#334155';
            return;
        }

        try {
            JSON.parse(val); // ลอง Parse ดู
            jsonFeedback.innerHTML = '✅ รูปแบบ JSON ถูกต้องสมบูรณ์';
            jsonFeedback.style.color = '#10b981';
            this.style.borderColor = '#10b981';
        } catch (e) {
            jsonFeedback.innerHTML = '❌ รูปแบบ JSON ผิดพลาด: ' + e.message;
            jsonFeedback.style.color = '#ef4444';
            this.style.borderColor = '#ef4444';
        }
    });

    // 3. ป้องกันการกด Submit ถ้า JSON พัง
    document.querySelector('form').addEventListener('submit', function(e) {
        if (gameTypeSelect.value === 'chem_lab') {
            let val = jsonInput.value.trim();
            try {
                JSON.parse(val);
            } catch (err) {
                e.preventDefault(); // เบรกการส่งฟอร์ม
                Swal.fire({
                    icon: 'error',
                    title: 'โค้ดตั้งค่าพัง!',
                    text: 'กรุณาตรวจสอบรูปแบบ JSON ให้ถูกต้องก่อนบันทึกภารกิจ (ห้ามลบปีกกา {} หรือลูกน้ำ , ผิดตำแหน่ง)',
                    confirmButtonColor: '#ef4444'
                });
            }
        }
    });

});
</script>

<?php require_once 'footer.php'; ?>