<?php
// dev_manage_schedules.php - จัดการตารางสอน จับคู่ ครู+วิชา+ห้องเรียน (เพิ่มระบบ Import Excel)
// (error display controlled by config.php / APP_ENV)
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';

if (!isset($pdo)) {
    die("<div style='padding:50px; text-align:center; color:#ef4444;'><h2>🚨 ข้อผิดพลาด: ไม่พบการเชื่อมต่อ PDO</h2></div>");
}

require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['developer', 'developer']);

$page_title = "จัดการตารางสอน (Class Schedules)";
$csrf = generate_csrf_token();
$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Requests (เพิ่ม, ลบ, นำเข้า Excel)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("❌ Security Error: CSRF Token ไม่ถูกต้อง");
    }

    $action = $_POST['action'] ?? '';

    // --- เพิ่มตารางสอนแบบ Manual ---
    if ($action === 'add_schedule') {
        $class_id = intval($_POST['class_id']);
        $subject_id = intval($_POST['subject_id']);
        $teacher_id = intval($_POST['teacher_id']);

        if ($class_id <= 0 || $subject_id <= 0 || $teacher_id <= 0) {
            $message = "⚠️ กรุณาเลือกข้อมูลให้ครบทั้ง ห้องเรียน, รายวิชา และผู้สอน";
            $msg_type = "error";
        } else {
            try {
                $chk = $pdo->prepare("SELECT id FROM class_schedules WHERE class_id=? AND subject_id=? AND teacher_id=?");
                $chk->execute([$class_id, $subject_id, $teacher_id]);
                if ($chk->fetch()) {
                    $message = "⚠️ มีการจับคู่นี้ในระบบอยู่แล้วครับ";
                    $msg_type = "warning";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO class_schedules (class_id, subject_id, teacher_id) VALUES (?, ?, ?)");
                    if ($stmt->execute([$class_id, $subject_id, $teacher_id])) {
                        $message = "✅ เพิ่มตารางสอนสำเร็จ นักเรียนในห้องนี้จะเห็นวิชานี้ทันที";
                        $msg_type = "success";
                    }
                }
            } catch (PDOException $e) {
                $message = "❌ ขัดข้อง: " . $e->getMessage();
                $msg_type = "error";
            }
        }
    }
    // --- ลบตารางสอน ---
    elseif ($action === 'delete_schedule') {
        $sched_id = intval($_POST['schedule_id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE id = ?");
            if ($stmt->execute([$sched_id])) {
                $message = "🗑️ ยกเลิกการจับคู่ตารางสอนสำเร็จ";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $message = "❌ ขัดข้อง: " . $e->getMessage();
            $msg_type = "error";
        }
    }
    // 🚨 --- ระบบนำเข้าตารางสอนจาก Excel (CSV) ---
    elseif ($action === 'import_excel') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['csv_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if ($file_ext !== 'csv') {
                $message = "❌ รองรับเฉพาะไฟล์นามสกุล .csv เท่านั้น (กรุณาเซฟ Excel เป็นแบบ CSV UTF-8)";
                $msg_type = "error";
            } else {
                $handle = fopen($file_tmp, "r");
                $success_count = 0;
                $error_count = 0;
                $duplicate_count = 0;
                $row_count = 0;

                // ข้าม Byte Order Mark (BOM) ถ้ามี
                $bom = fread($handle, 3);
                if ($bom !== b"\xEF\xBB\xBF") rewind($handle);

                // เตรียม Statement ล่วงหน้าเพื่อความเร็วในการค้นหา
                $stmt_get_class = $pdo->prepare("SELECT id FROM classes WHERE class_name = ? LIMIT 1");
                $stmt_get_subj = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = ? LIMIT 1");
                $stmt_get_teach = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role IN ('teacher', 'developer') LIMIT 1");
                $stmt_check_map = $pdo->prepare("SELECT id FROM class_schedules WHERE class_id=? AND subject_id=? AND teacher_id=?");
                $stmt_insert_map = $pdo->prepare("INSERT INTO class_schedules (class_id, subject_id, teacher_id) VALUES (?, ?, ?)");

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row_count++;
                    if ($row_count === 1) continue; // ข้ามบรรทัดหัวตาราง (Header)

                    if (count($data) < 3 || empty(trim($data[0])) || empty(trim($data[1])) || empty(trim($data[2]))) {
                        $error_count++;
                        continue;
                    }

                    $c_name = trim($data[0]);
                    $s_code = trim($data[1]);
                    $t_uname = trim($data[2]);

                    // ค้นหา ID จากชื่อ
                    $stmt_get_class->execute([$c_name]); $c_id = $stmt_get_class->fetchColumn();
                    $stmt_get_subj->execute([$s_code]); $s_id = $stmt_get_subj->fetchColumn();
                    $stmt_get_teach->execute([$t_uname]); $t_id = $stmt_get_teach->fetchColumn();

                    if ($c_id && $s_id && $t_id) {
                        // เช็คซ้ำ
                        $stmt_check_map->execute([$c_id, $s_id, $t_id]);
                        if ($stmt_check_map->fetchColumn()) {
                            $duplicate_count++;
                        } else {
                            if ($stmt_insert_map->execute([$c_id, $s_id, $t_id])) {
                                $success_count++;
                            }
                        }
                    } else {
                        $error_count++; // หาข้อมูลในระบบไม่เจอ
                    }
                }
                fclose($handle);
                
                $message = "✅ นำเข้าสำเร็จ: {$success_count} รายการ | ⚠️ ซ้ำ: {$duplicate_count} | ❌ ข้อมูลไม่ถูกต้อง/หาไม่พบ: {$error_count}";
                $msg_type = $success_count > 0 ? "success" : "warning";
                
                if (function_exists('systemLog')) {
                    systemLog($_SESSION['user_id'], 'SCHEDULE_BULK_IMPORT', "Imported {$success_count} class schedules.");
                }
            }
        } else {
            $message = "❌ กรุณาเลือกไฟล์ที่จะอัปโหลด";
            $msg_type = "error";
        }
    }
}

// =========================================================
// 2. ดึงข้อมูลพื้นฐาน (ห้องเรียน, วิชา, ครู) สำหรับ Dropdown
// =========================================================
$classes = []; $subjects = []; $teachers = [];
try {
    $classes = $pdo->query("SELECT id, class_name FROM classes WHERE is_active=1 ORDER BY level ASC, room ASC")->fetchAll(PDO::FETCH_ASSOC);
    $subjects = $pdo->query("SELECT id, subject_code, subject_name FROM subjects WHERE is_active=1 ORDER BY subject_code ASC")->fetchAll(PDO::FETCH_ASSOC);
    $teachers = $pdo->query("SELECT id, display_name FROM users WHERE role IN ('teacher','developer') AND is_deleted=0 ORDER BY display_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// =========================================================
// 3. ดึงข้อมูลตารางสอนทั้งหมดมาแสดง
// =========================================================
$schedules = [];
try {
    $sql = "
        SELECT 
            cs.id as sched_id,
            c.class_name,
            s.subject_code, s.subject_name, s.color_theme, s.icon,
            t.display_name as teacher_name
        FROM class_schedules cs
        JOIN classes c ON cs.class_id = c.id
        JOIN subjects s ON cs.subject_id = s.id
        JOIN users t ON cs.teacher_id = t.id
        ORDER BY c.level ASC, c.room ASC, s.subject_code ASC
    ";
    $schedules = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; -webkit-tap-highlight-color: transparent;}
    .dev-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; box-sizing: border-box; }
    
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; flex-wrap: wrap; gap: 15px;}
    .page-title { margin: 0; font-size: 1.8rem; font-weight: bold; color: #1e3a8a; display: flex; align-items: center; gap: 10px; }
    .page-subtitle { margin: 5px 0 0 0; color: #64748b; font-size: 1rem; width: 100%;}

    .btn-excel { background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 12px 25px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 1rem; transition: 0.3s; font-family: inherit; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);}
    .btn-excel:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(16, 185, 129, 0.4); }

    .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; font-weight: bold; line-height: 1.5;}
    .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert.warning { background: #fefce8; color: #b45309; border: 1px solid #fde047; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Mapping Form Panel */
    .map-panel { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 2px solid #3b82f6; margin-bottom: 30px; }
    .map-panel h3 { margin: 0 0 20px 0; color: #3b82f6; font-size: 1.2rem; }
    .map-grid { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 20px; align-items: end; }
    
    .form-group { display: flex; flex-direction: column; gap: 8px;}
    .form-label { font-weight: bold; color: #475569; font-size: 0.95rem; }
    .form-select { width: 100%; padding: 14px 15px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 16px; box-sizing: border-box; outline: none; background: #f8fafc; transition: 0.3s; }
    .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); background: white;}
    
    .btn-map { background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 14px 30px; border-radius: 10px; font-weight: bold; cursor: pointer; font-size: 1.1rem; transition: 0.3s; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); height: 50px; display: flex; align-items: center;}
    .btn-map:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(59, 130, 246, 0.5); }

    /* Data Table */
    .table-wrapper { background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; overflow-x: auto; }
    .styled-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .styled-table th, .styled-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .styled-table th { background: #f8fafc; color: #475569; font-weight: 600; border-bottom: 2px solid #e2e8f0; position: sticky; top: 0; }
    .styled-table tbody tr:hover { background: #f8fafc; }
    
    .badge-class { background: #e0f2fe; color: #0284c7; padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 5px; border: 1px solid #bae6fd;}
    .badge-teacher { background: #f3e8ff; color: #7e22ce; padding: 6px 12px; border-radius: 20px; font-size: 0.9rem; font-weight: bold; }
    
    .subject-pill { display: flex; align-items: center; gap: 10px; }
    .sp-icon { font-size: 1.5rem; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white;}
    .sp-info { display: flex; flex-direction: column; }
    .sp-code { font-family: monospace; font-size: 0.8rem; color: #64748b; font-weight: bold;}
    .sp-name { font-weight: bold; color: #1e293b; font-size: 1rem;}

    .btn-delete { background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; padding: 8px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-family: inherit;}
    .btn-delete:hover { background: #ef4444; color: white; }

    /* Modal Import */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .modal-box { background: white; width: 100%; max-width: 550px; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.3); transform: translateY(50px); opacity: 0; transition: 0.3s; display: flex; flex-direction: column; max-height: 90vh; }
    .modal-box.show { transform: translateY(0); opacity: 1; }
    
    .modal-header { background: linear-gradient(135deg, #10b981, #059669); padding: 20px 25px; color: white; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 1.3rem; }
    .btn-close { background: transparent; border: none; font-size: 1.8rem; color: rgba(255,255,255,0.7); cursor: pointer; transition: 0.2s; }
    .btn-close:hover { color: white; }
    
    .modal-body { padding: 25px; overflow-y: auto; background: #f8fafc; }
    .modal-footer { padding: 20px 25px; background: white; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; }

    .btn-cancel { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: 0.2s; font-family: inherit;}
    .btn-cancel:hover { background: #e2e8f0; }
    .btn-save { background: #3b82f6; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1.05rem; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); transition: 0.2s; font-family: inherit;}
    .btn-save:hover { background: #2563eb; transform: translateY(-2px); }

    @media (max-width: 992px) {
        .top-bar { flex-direction: column; align-items: stretch; }
        .btn-excel { justify-content: center; }
        .map-grid { grid-template-columns: 1fr; gap: 15px; }
        .btn-map { width: 100%; justify-content: center; }
        .modal-box { height: 100vh; max-height: 100vh; border-radius: 0; }
        .modal-footer { flex-direction: column-reverse; }
        .btn-cancel, .btn-save { width: 100%; }
    }
</style>

<div class="dev-container">
    <div class="top-bar">
        <div>
            <h1 class="page-title">🗓️ จัดการตารางสอน (Class Schedules)</h1>
            <p class="page-subtitle">ผูกรายวิชาเข้ากับห้องเรียนและระบุครูผู้สอน เพื่อให้นักเรียนเข้าถึงได้ใน Classroom</p>
        </div>
        <button class="btn-excel" onclick="openModal('modalImport')">📥 นำเข้าจาก Excel (CSV)</button>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="map-panel">
        <h3>🔗 สร้างการเชื่อมโยงแบบเดี่ยว (Manual Mapping)</h3>
        <form method="POST" class="map-grid">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add_schedule">
            
            <div class="form-group">
                <label class="form-label">🏫 1. เลือกห้องเรียนเป้าหมาย</label>
                <select name="class_id" class="form-select" required>
                    <option value="">-- เลือกห้องเรียน --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>">ห้อง <?= htmlspecialchars($c['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">📚 2. เลือกรายวิชา</label>
                <select name="subject_id" class="form-select" required>
                    <option value="">-- เลือกวิชา --</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= $s['id'] ?>">[<?= htmlspecialchars($s['subject_code']) ?>] <?= htmlspecialchars($s['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">👨‍🏫 3. กำหนดครูผู้สอน</label>
                <select name="teacher_id" class="form-select" required>
                    <option value="">-- เลือกคุณครู --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <button type="submit" class="btn-map">💾 บันทึกตาราง</button>
        </form>
    </div>

    <h3 style="color: #1e293b; margin-bottom: 15px;">📋 ข้อมูลการจัดตารางสอนปัจจุบัน</h3>
    <div class="table-wrapper">
        <table class="styled-table">
            <thead>
                <tr>
                    <th width="20%">เป้าหมาย (Class)</th>
                    <th width="40%">รายวิชาที่เรียน (Subject)</th>
                    <th width="25%">ครูผู้สอน (Teacher)</th>
                    <th width="15%" style="text-align: right;">การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($schedules) > 0): ?>
                    <?php foreach ($schedules as $sc): ?>
                        <tr>
                            <td><span class="badge-class">🏫 ม.<?= htmlspecialchars($sc['class_name']) ?></span></td>
                            <td>
                                <div class="subject-pill">
                                    <div class="sp-icon" style="background: <?= htmlspecialchars($sc['color_theme']) ?>;"><?= htmlspecialchars($sc['icon']) ?></div>
                                    <div class="sp-info">
                                        <span class="sp-code"><?= htmlspecialchars($sc['subject_code']) ?></span>
                                        <span class="sp-name"><?= htmlspecialchars($sc['subject_name']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge-teacher">👨‍🏫 <?= htmlspecialchars($sc['teacher_name']) ?></span></td>
                            <td style="text-align: right;">
                                <form method="POST" onsubmit="return confirm('ยกเลิกการสอนวิชานี้สำหรับห้อง <?= h($sc['class_name']) ?> ใช่หรือไม่?');">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= $sc['sched_id'] ?>">
                                    <button type="submit" class="btn-delete">🗑️ ยกเลิก (Unmap)</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 50px; color: #64748b;">
                            <span style="font-size: 3rem; display:block; margin-bottom: 10px;">📉</span>
                            ยังไม่มีการจัดตารางสอนในระบบ กรุณาตั้งค่าด้านบน
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="modalImport">
    <div class="modal-box" id="boxImport">
        <form method="POST" action="dev_manage_schedules.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_excel">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            
            <div class="modal-header">
                <h2>📥 นำเข้าตารางสอนด้วย Excel</h2>
                <button type="button" class="btn-close" onclick="closeModal('modalImport')">✖</button>
            </div>
            
            <div class="modal-body">
                <div style="background:white; padding:20px; border-radius:12px; border:2px dashed #cbd5e1; text-align:center; margin-bottom:20px;">
                    <span style="font-size:3rem; display:block; margin-bottom:10px;">📄</span>
                    <strong style="color:#1e293b; font-size:1.1rem;">อัปโหลดไฟล์ตารางสอน (.csv) ของคุณที่นี่</strong>
                    <input type="file" name="csv_file" accept=".csv" required style="margin-top:15px; width:100%; border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px;">
                </div>

                <div style="background:#eff6ff; padding:15px; border-radius:8px; color:#1e40af; font-size:0.95rem; border:1px solid #bfdbfe; line-height:1.6;">
                    <strong style="display:block; margin-bottom:5px;">💡 คำแนะนำการใช้งาน:</strong>
                    1. ดาวน์โหลด <a href="download_schedule_template.php" style="color:#2563eb; font-weight:bold; text-decoration:underline;">ไฟล์แม่แบบ (Template)</a><br>
                    2. กรอก <b>ชื่อห้อง</b> (เช่น 4/1), <b>รหัสวิชา</b> (เช่น ว30221) และ <b>Username ของครู</b> (เช่น tea001)<br>
                    3. กด Save As -> <strong>CSV UTF-8 (Comma delimited)</strong><br>
                    4. ระบบจะทำการค้นหา ID และจับคู่ให้อัตโนมัติ
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalImport')">ยกเลิก</button>
                <button type="submit" class="btn-save">🚀 เริ่มการนำเข้าข้อมูล</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        const m = document.getElementById(id);
        const b = m.querySelector('.modal-box');
        m.style.display = 'flex';
        setTimeout(() => b.classList.add('show'), 10);
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        const b = m.querySelector('.modal-box');
        b.classList.remove('show');
        setTimeout(() => m.style.display = 'none', 300);
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay')) {
            const b = event.target.querySelector('.modal-box');
            b.classList.remove('show');
            setTimeout(() => event.target.style.display = 'none', 300);
        }
    }
</script>

<?php require_once 'footer.php'; ?>