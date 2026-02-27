<?php
// teacher_classroom.php - ระบบจัดการชั้นเรียนและการบ้าน (Smart Classroom - Phase 4)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// บังคับสิทธิ์ครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$page_title = "จัดการชั้นเรียน (Smart Classroom)";
$teacher_id = $_SESSION['user_id'];
$csrf = generate_csrf_token();

$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Requests (สร้างวิชา, สั่งงาน, ลบวิชา)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Token Verification Failed.");
    }

    $action = $_POST['action'] ?? '';

    // สร้างห้องเรียน/รายวิชาใหม่
    if ($action === 'create_subject') {
        $subject_code = trim($_POST['subject_code'] ?? '');
        $subject_name = trim($_POST['subject_name'] ?? '');
        $class_id = intval($_POST['class_id'] ?? 0);
        $cover_color = $_POST['cover_color'] ?? '#3b82f6';
        
        if (empty($subject_name) || $class_id === 0) {
            $message = '❌ กรุณากรอกชื่อวิชาและเลือกห้องเรียน';
            $msg_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_id, cover_color) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issis", $teacher_id, $subject_code, $subject_name, $class_id, $cover_color);
            if ($stmt->execute()) {
                $message = '✅ สร้างห้องเรียนสำเร็จ! ระบบดึงนักเรียนเข้าห้องอัตโนมัติแล้ว';
                $msg_type = 'success';
                systemLog($teacher_id, 'CLASS_CREATE', "Created subject: $subject_name for class_id: $class_id");
            }
            $stmt->close();
        }
    } 
    // สร้างการบ้าน/มอบหมายงาน
    elseif ($action === 'create_assignment') {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $due_date = $_POST['due_date'] ?? NULL;
        $class_id = intval($_POST['class_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? 'วิชา');

        if (empty($title) || $subject_id === 0) {
            $message = '❌ กรุณากรอกหัวข้องานให้ครบถ้วน';
            $msg_type = 'error';
        } else {
            // 1. บันทึกลงตารางงานของชั้นเรียน
            $stmt = $conn->prepare("INSERT INTO class_assignments (subject_id, teacher_id, title, description, due_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $subject_id, $teacher_id, $title, $description, $due_date);
            
            if ($stmt->execute()) {
                // 2. ดึงนักเรียนทั้งหมดในห้องนี้ เพื่อสั่งงานข้ามระบบไปยังสมุดจดงาน (To-Do List)
                $stmt_std = $conn->prepare("SELECT id FROM users WHERE class_id = ? AND role = 'student' AND is_deleted = 0");
                $stmt_std->bind_param("i", $class_id);
                $stmt_std->execute();
                $res_std = $stmt_std->get_result();
                
                // ถ้านักเรียนมีอยู่จริง จะแอบไป Insert งานลงตาราง student_tasks
                if ($res_std->num_rows > 0) {
                    $insert_task = $conn->prepare("INSERT INTO student_tasks (student_id, task_text) VALUES (?, ?)");
                    $task_text = "[$subject_name] $title" . ($due_date ? " (กำหนด: ".date('d/m/Y', strtotime($due_date)).")" : "");
                    
                    while ($std = $res_std->fetch_assoc()) {
                        $insert_task->bind_param("is", $std['id'], $task_text);
                        $insert_task->execute();
                    }
                    $insert_task->close();
                }
                $stmt_std->close();

                $message = '✅ มอบหมายงานสำเร็จ! งานถูกส่งไปยังสมุดจดงานของนักเรียนทุกคนแล้ว';
                $msg_type = 'success';
            }
            $stmt->close();
        }
    }
}

// =========================================================
// 2. โหมดแสดงผล (ดูหน้ารวม หรือ ดูเจาะจงรายวิชา)
// =========================================================
$view_mode = 'list';
$current_subject = null;
$subject_students = [];
$subject_assignments = [];

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $view_mode = 'detail';
    $subject_id = intval($_GET['id']);
    
    // ดึงข้อมูลวิชานี้ (และเช็คว่าเป็นของครูคนนี้จริงไหม)
    $stmt_subj = $conn->prepare("SELECT ts.*, c.class_name FROM teacher_subjects ts JOIN classes c ON ts.class_id = c.id WHERE ts.id = ? AND ts.teacher_id = ?");
    $stmt_subj->bind_param("ii", $subject_id, $teacher_id);
    $stmt_subj->execute();
    $res_subj = $stmt_subj->get_result();
    
    if ($res_subj->num_rows > 0) {
        $current_subject = $res_subj->fetch_assoc();
        
        // ดึงรายชื่อนักเรียนที่อยู่ห้องนี้ (Auto-Enrollment)
        $stmt_std = $conn->prepare("SELECT id, display_name, username FROM users WHERE class_id = ? AND role = 'student' AND is_deleted = 0 ORDER BY username ASC");
        $stmt_std->bind_param("i", $current_subject['class_id']);
        $stmt_std->execute();
        $res_std = $stmt_std->get_result();
        while ($row = $res_std->fetch_assoc()) { $subject_students[] = $row; }
        $stmt_std->close();

        // ดึงการบ้านของวิชานี้
        $stmt_ass = $conn->prepare("SELECT * FROM class_assignments WHERE subject_id = ? ORDER BY created_at DESC");
        $stmt_ass->bind_param("i", $subject_id);
        $stmt_ass->execute();
        $res_ass = $stmt_ass->get_result();
        while ($row = $res_ass->fetch_assoc()) { $subject_assignments[] = $row; }
        $stmt_ass->close();

    } else {
        // ถ้าไม่มีสิทธิ์ดู หรือใส่ ID มั่ว ให้เด้งกลับหน้ารวม
        header("Location: teacher_classroom.php");
        exit;
    }
    $stmt_subj->close();
}

// ข้อมูลสำหรับหน้ารวม (List Mode)
$my_subjects = [];
$classes = [];
if ($view_mode === 'list') {
    // ดึงวิชาทั้งหมดที่ครูสอน
    $stmt_list = $conn->prepare("
        SELECT ts.*, c.class_name, 
               (SELECT COUNT(id) FROM users WHERE class_id = ts.class_id AND role='student' AND is_deleted=0) as std_count
        FROM teacher_subjects ts 
        JOIN classes c ON ts.class_id = c.id 
        WHERE ts.teacher_id = ? 
        ORDER BY ts.created_at DESC
    ");
    $stmt_list->bind_param("i", $teacher_id);
    $stmt_list->execute();
    $res_list = $stmt_list->get_result();
    while ($row = $res_list->fetch_assoc()) { $my_subjects[] = $row; }
    $stmt_list->close();

    // ดึงห้องเรียนไว้ทำ Dropdown ตอนสร้างวิชา
    $res_classes = $conn->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
    while ($row = $res_classes->fetch_assoc()) { $classes[] = $row; }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS สำหรับ Smart Classroom (Phase 4)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .class-wrapper { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    /* Header */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #0369a1; display: flex; align-items: center; gap: 10px; }
    .btn-action { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: inherit; font-size: 1rem; display: inline-flex; align-items: center; gap: 8px;}
    .btn-action:hover { background: #2563eb; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4); }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Alert */
    .alert-box { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; animation: fadeIn 0.5s; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    /* =========================================
       หน้ารวมรายวิชา (Card Grid Style)
       ========================================= */
    .subjects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
    .subject-card { background: white; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.05); transition: 0.3s; display: flex; flex-direction: column; text-decoration: none; color: inherit;}
    .subject-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); border-color: #cbd5e1;}
    
    .card-banner { height: 120px; padding: 20px; position: relative; color: white; display: flex; flex-direction: column; justify-content: flex-end; }
    .card-banner::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to top, rgba(0,0,0,0.6), transparent); pointer-events: none; }
    .card-banner h3 { margin: 0; font-size: 1.4rem; font-weight: 700; z-index: 2; position: relative; text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
    .card-banner p { margin: 5px 0 0 0; font-size: 0.95rem; z-index: 2; position: relative; opacity: 0.9; }
    
    .card-body { padding: 20px; flex: 1; display: flex; align-items: center; justify-content: space-between; }
    .stat-badge { background: #f1f5f9; padding: 8px 15px; border-radius: 8px; font-size: 0.9rem; font-weight: bold; color: #475569; display: flex; align-items: center; gap: 8px; }
    .btn-enter { font-size: 1.5rem; color: #cbd5e1; transition: 0.3s; }
    .subject-card:hover .btn-enter { color: #3b82f6; transform: translateX(5px); }

    /* =========================================
       หน้าห้องเรียน (Subject Detail View)
       ========================================= */
    .class-header { height: 200px; border-radius: 16px; margin-bottom: 25px; padding: 30px; color: white; position: relative; display: flex; flex-direction: column; justify-content: flex-end; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2);}
    .class-header::after { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to right, rgba(0,0,0,0.7), transparent); pointer-events: none; }
    .class-header h1 { margin: 0; font-size: 2.5rem; z-index: 2; position: relative; text-shadow: 0 2px 5px rgba(0,0,0,0.8);}
    .class-header h3 { margin: 5px 0 0 0; font-size: 1.2rem; font-weight: 400; z-index: 2; position: relative; opacity: 0.9;}

    .tabs-nav { display: flex; gap: 10px; border-bottom: 2px solid #e2e8f0; margin-bottom: 25px; }
    .tab-btn { background: transparent; border: none; padding: 12px 25px; font-size: 1.1rem; font-weight: 600; color: #64748b; cursor: pointer; transition: 0.3s; border-bottom: 3px solid transparent; font-family: inherit; }
    .tab-btn.active { color: #0369a1; border-bottom-color: #0369a1; }
    .tab-btn:hover:not(.active) { color: #0f172a; border-bottom-color: #cbd5e1; }
    .tab-pane { display: none; animation: fadeIn 0.4s; }
    .tab-pane.active { display: block; }

    /* สไตล์การบ้าน (Assignments) */
    .assignment-list { display: flex; flex-direction: column; gap: 15px; }
    .assignment-card { background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); display: flex; align-items: flex-start; gap: 20px; transition: 0.3s; }
    .assignment-card:hover { border-color: #cbd5e1; box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
    .icon-box { width: 50px; height: 50px; background: #e0f2fe; color: #0284c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
    .ass-content { flex: 1; }
    .ass-title { margin: 0 0 5px 0; font-size: 1.2rem; color: #0f172a; }
    .ass-meta { font-size: 0.85rem; color: #64748b; margin-bottom: 10px; display: flex; gap: 15px;}
    .ass-desc { color: #475569; font-size: 0.95rem; line-height: 1.5; background: #f8fafc; padding: 10px 15px; border-radius: 8px; border-left: 3px solid #cbd5e1; }

    /* สไตล์ผู้คน (People) */
    .people-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; }
    .student-card { background: white; border: 1px solid #e2e8f0; padding: 15px; border-radius: 10px; display: flex; align-items: center; gap: 15px; transition: 0.2s;}
    .student-card:hover { background: #f8fafc; transform: translateY(-2px); }
    .std-avatar { width: 45px; height: 45px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #64748b; font-size: 1.2rem; }
    .std-info h4 { margin: 0; color: #1e293b; font-size: 1rem; }
    .std-info p { margin: 2px 0 0 0; color: #94a3b8; font-size: 0.8rem; font-family: monospace; }

    /* =========================================
       Modal Forms
       ========================================= */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .form-modal { background: white; width: 100%; max-width: 500px; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5); transform: translateY(20px); opacity: 0; transition: 0.3s; }
    .form-modal.show { transform: translateY(0); opacity: 1; }
    .modal-header { background: #0369a1; color: white; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 1.4rem; }
    .btn-close { background: transparent; color: white; border: none; font-size: 1.5rem; cursor: pointer; transition: 0.2s; }
    .btn-close:hover { transform: scale(1.2); }
    .modal-body { padding: 25px; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; color: #1e293b; margin-bottom: 8px; }
    .form-control { width: 100%; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 1rem; box-sizing: border-box; }
    .form-control:focus { border-color: #0369a1; }
    textarea.form-control { resize: vertical; height: 100px; }
    .color-picker { display: flex; gap: 10px; }
    .color-option { width: 35px; height: 35px; border-radius: 50%; cursor: pointer; border: 3px solid transparent; transition: 0.2s; }
    .color-option.selected { border-color: #0f172a; transform: scale(1.1); }
    .modal-footer { padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 15px; }
    .btn-cancel { background: white; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; }
    .btn-save { background: #0ea5e9; color: white; border: none; padding: 10px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1.05rem; }
    .btn-save:hover { background: #0284c7; }

    .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; background: white; border-radius: 12px; border: 2px dashed #cbd5e1; }
</style>

<div class="class-wrapper">

    <?php if ($message): ?>
        <div class="alert-box alert-<?= $msg_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($view_mode === 'list'): ?>
        
        <div class="page-header">
            <h1 class="page-title">📚 ชั้นเรียนของฉัน (Smart Classroom)</h1>
            <div>
                <a href="dashboard_teacher.php" class="btn-back" style="margin-right:10px;">⬅ หน้าหลัก</a>
                <button class="btn-action" onclick="openCreateSubjectModal()">➕ สร้างชั้นเรียนใหม่</button>
            </div>
        </div>

        <div class="subjects-grid">
            <?php if (count($my_subjects) > 0): ?>
                <?php foreach ($my_subjects as $subj): ?>
                    <a href="teacher_classroom.php?id=<?= $subj['id'] ?>" class="subject-card">
                        <div class="card-banner" style="background-color: <?= htmlspecialchars($subj['cover_color']) ?>;">
                            <h3><?= htmlspecialchars($subj['subject_name']) ?></h3>
                            <p><?= htmlspecialchars($subj['subject_code'] ? $subj['subject_code'].' | ' : '') ?>ห้อง <?= htmlspecialchars($subj['class_name']) ?></p>
                        </div>
                        <div class="card-body">
                            <div class="stat-badge">👥 <?= $subj['std_count'] ?> นักเรียน</div>
                            <div class="btn-enter">➔</div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1;">
                    <div class="empty-state">
                        <span style="font-size: 4rem;">🏫</span>
                        <h2 style="margin: 0; color: #1e293b;">คุณยังไม่ได้สร้างชั้นเรียน</h2>
                        <p>กดปุ่ม "สร้างชั้นเรียนใหม่" เพื่อเริ่มจำลองตารางสอนของคุณ</p>
                    </div>
                </div>
            </<?php endif; ?>
        </div>

        <div class="modal-overlay" id="subjectModalOverlay">
            <div class="form-modal" id="subjectModalBox">
                <form method="POST" action="teacher_classroom.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_subject">
                    <input type="hidden" name="cover_color" id="coverColorInput" value="#3b82f6">

                    <div class="modal-header">
                        <h2>➕ สร้างชั้นเรียนใหม่</h2>
                        <button type="button" class="btn-close" onclick="closeSubjectModal()">✖</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>รหัสวิชา (ไม่บังคับ)</label>
                            <input type="text" name="subject_code" class="form-control" placeholder="เช่น ว32221">
                        </div>
                        <div class="form-group">
                            <label>ชื่อรายวิชา *</label>
                            <input type="text" name="subject_name" class="form-control" placeholder="เช่น เคมีเพิ่มเติม 2" required>
                        </div>
                        <div class="form-group">
                            <label>สอนนักเรียนชั้น * (ดึงเด็กเข้าห้องอัตโนมัติ)</label>
                            <select name="class_id" class="form-control" required>
                                <option value="">-- เลือกห้องเรียน --</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ธีมสีหน้าปก</label>
                            <div class="color-picker">
                                <div class="color-option selected" style="background:#3b82f6;" onclick="selectColor(this, '#3b82f6')"></div>
                                <div class="color-option" style="background:#10b981;" onclick="selectColor(this, '#10b981')"></div>
                                <div class="color-option" style="background:#f59e0b;" onclick="selectColor(this, '#f59e0b')"></div>
                                <div class="color-option" style="background:#ef4444;" onclick="selectColor(this, '#ef4444')"></div>
                                <div class="color-option" style="background:#8b5cf6;" onclick="selectColor(this, '#8b5cf6')"></div>
                                <div class="color-option" style="background:#0f766e;" onclick="selectColor(this, '#0f766e')"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeSubjectModal()">ยกเลิก</button>
                        <button type="submit" class="btn-save">สร้างห้องเรียน</button>
                    </div>
                </form>
            </div>
        </div>

    <?php elseif ($view_mode === 'detail'): ?>
        
        <div class="page-header" style="margin-bottom: 10px;">
            <a href="teacher_classroom.php" class="btn-back">⬅ กลับหน้ารวมวิชา</a>
        </div>

        <div class="class-header" style="background-color: <?= htmlspecialchars($current_subject['cover_color']) ?>;">
            <h1><?= htmlspecialchars($current_subject['subject_name']) ?></h1>
            <h3><?= htmlspecialchars($current_subject['subject_code'] ? $current_subject['subject_code'].' | ' : '') ?>ห้อง <?= htmlspecialchars($current_subject['class_name']) ?></h3>
        </div>

        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchTab('tab-assignments', this)">📋 งานในชั้นเรียน (Classwork)</button>
            <button class="tab-btn" onclick="switchTab('tab-people', this)">👥 ผู้คน (People) - <?= count($subject_students) ?> คน</button>
        </div>

        <div id="tab-assignments" class="tab-pane active">
            <div style="margin-bottom: 20px;">
                <button class="btn-action" onclick="openAssignmentModal()">➕ มอบหมายงานใหม่</button>
            </div>

            <div class="assignment-list">
                <?php if (count($subject_assignments) > 0): ?>
                    <?php foreach ($subject_assignments as $ass): ?>
                        <div class="assignment-card">
                            <div class="icon-box">📝</div>
                            <div class="ass-content">
                                <h3 class="ass-title"><?= htmlspecialchars($ass['title']) ?></h3>
                                <div class="ass-meta">
                                    <span>📅 สั่งเมื่อ: <?= date('d/m/Y', strtotime($ass['created_at'])) ?></span>
                                    <?php if($ass['due_date']): ?>
                                        <span style="color: #ef4444; font-weight:bold;">🚨 กำหนดส่ง: <?= date('d/m/Y', strtotime($ass['due_date'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if($ass['description']): ?>
                                    <div class="ass-desc"><?= nl2br(htmlspecialchars($ass['description'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <span style="font-size: 3rem;">📭</span>
                        <h3 style="margin:0; color:#1e293b;">ยังไม่ได้สั่งการบ้านในวิชานี้</h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-people" class="tab-pane">
            <h3 style="color:#0369a1; border-bottom: 2px solid #e2e8f0; padding-bottom:10px;">รายชื่อนักเรียนที่ดึงเข้าระบบอัตโนมัติ (Auto-Enrolled)</h3>
            <div class="people-grid">
                <?php foreach ($subject_students as $std): ?>
                    <div class="student-card">
                        <div class="std-avatar"><?= mb_substr($std['display_name'], 0, 1, 'UTF-8') ?></div>
                        <div class="std-info">
                            <h4><?= htmlspecialchars($std['display_name']) ?></h4>
                            <p>ID: <?= htmlspecialchars($std['username']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if(count($subject_students) == 0): ?>
                    <div style="grid-column: 1/-1; color:#94a3b8; text-align:center; padding:20px;">ไม่พบนักเรียนในชั้นเรียนนี้ในฐานข้อมูล</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="modal-overlay" id="assModalOverlay">
            <div class="form-modal" id="assModalBox">
                <form method="POST" action="teacher_classroom.php?id=<?= $subject_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_assignment">
                    <input type="hidden" name="subject_id" value="<?= $subject_id ?>">
                    <input type="hidden" name="class_id" value="<?= $current_subject['class_id'] ?>">
                    <input type="hidden" name="subject_name" value="<?= htmlspecialchars($current_subject['subject_name']) ?>">

                    <div class="modal-header">
                        <h2>➕ มอบหมายงานใหม่</h2>
                        <button type="button" class="btn-close" onclick="closeAssignmentModal()">✖</button>
                    </div>
                    <div class="modal-body">
                        <div style="background:#eff6ff; color:#1e40af; padding:10px; border-radius:8px; margin-bottom:15px; font-size:0.9rem;">
                            ℹ️ งานนี้จะถูกเพิ่มเข้าไปใน "สมุดจดงาน (To-Do)" ของนักเรียนห้อง <?= htmlspecialchars($current_subject['class_name']) ?> โดยอัตโนมัติ
                        </div>
                        <div class="form-group">
                            <label>หัวข้อการบ้าน / ชิ้นงาน *</label>
                            <input type="text" name="title" class="form-control" placeholder="เช่น สรุปผลการทดลองเรื่องกรด-เบส" required>
                        </div>
                        <div class="form-group">
                            <label>คำอธิบาย (ไม่บังคับ)</label>
                            <textarea name="description" class="form-control" placeholder="อธิบายรายละเอียดการทำรายงาน..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>กำหนดส่ง (Due Date)</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeAssignmentModal()">ยกเลิก</button>
                        <button type="submit" class="btn-save">ส่งงานให้นักเรียน</button>
                    </div>
                </form>
            </div>
        </div>

    <?php endif; ?>

</div>

<script>
    // Script สำหรับหน้ารวมวิชา (List)
    function openSubjectModal() { document.getElementById('subjectModalOverlay').style.display='flex'; setTimeout(()=>document.getElementById('subjectModalBox').classList.add('show'),10); }
    function closeSubjectModal() { document.getElementById('subjectModalBox').classList.remove('show'); setTimeout(()=>document.getElementById('subjectModalOverlay').style.display='none',300); }
    function selectColor(el, color) { document.querySelectorAll('.color-option').forEach(e=>e.classList.remove('selected')); el.classList.add('selected'); document.getElementById('coverColorInput').value = color; }

    // Script สำหรับหน้าเจาะจงวิชา (Detail)
    function switchTab(tabId, btn) {
        document.querySelectorAll('.tab-pane').forEach(e=>e.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(e=>e.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
    }
    function openAssignmentModal() { document.getElementById('assModalOverlay').style.display='flex'; setTimeout(()=>document.getElementById('assModalBox').classList.add('show'),10); }
    function closeAssignmentModal() { document.getElementById('assModalBox').classList.remove('show'); setTimeout(()=>document.getElementById('assModalOverlay').style.display='none',300); }
</script>

<?php require_once 'footer.php'; ?>