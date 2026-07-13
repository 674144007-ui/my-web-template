<?php
// teacher_classroom.php - ระบบจัดการชั้นเรียนและการบ้าน (Smart Classroom - Phase 4)
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
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
            $stmt = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_code, subject_name, class_id, cover_color) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$teacher_id, $subject_code, $subject_name, $class_id, $cover_color]);
            if ($stmt->execute()) {
                $message = '✅ สร้างห้องเรียนสำเร็จ! ระบบดึงนักเรียนเข้าห้องอัตโนมัติแล้ว';
                $msg_type = 'success';
                systemLog($teacher_id, 'CLASS_CREATE', "Created subject: $subject_name for class_id: $class_id");
            }
            
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
            $stmt = $pdo->prepare("INSERT INTO class_assignments (subject_id, teacher_id, title, description, due_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$subject_id, $teacher_id, $title, $description, $due_date]);
            
            if ($stmt->execute()) {
                // 2. ดึงนักเรียนทั้งหมดในห้องนี้ เพื่อสั่งงานข้ามระบบไปยังสมุดจดงาน (To-Do List)
                $stmt_std = $pdo->prepare("SELECT id FROM users WHERE class_id = ? AND role = 'student' AND is_deleted = 0");
                $stmt_std->execute([$class_id]);
                $stmt_std->execute();
                $res_std = $stmt_std;
                
                // ถ้านักเรียนมีอยู่จริง จะแอบไป Insert งานลงตาราง student_tasks
                if ($res_std->rowCount() > 0) {
                    $insert_task = $pdo->prepare("INSERT INTO student_tasks (student_id, task_text) VALUES (?, ?)");
                    $task_text = "[$subject_name] $title" . ($due_date ? " (กำหนด: ".date('d/m/Y', strtotime($due_date)).")" : "");
                    
                    while ($std = $res_std->fetch()) {
                        $insert_task->execute([$std['id'], $task_text]);
                        $insert_task->execute();
                    }
                    
                }
                

                $message = '✅ มอบหมายงานสำเร็จ! งานถูกส่งไปยังสมุดจดงานของนักเรียนทุกคนแล้ว';
                $msg_type = 'success';
            }
            
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
    $stmt_subj = $pdo->prepare("SELECT ts.*, c.class_name FROM teacher_subjects ts JOIN classes c ON ts.class_id = c.id WHERE ts.id = ? AND ts.teacher_id = ?");
    $stmt_subj->execute([$subject_id, $teacher_id]);
    $stmt_subj->execute();
    $res_subj = $stmt_subj;
    
    if ($res_subj->rowCount() > 0) {
        $current_subject = $res_subj->fetch();
        
        // ดึงรายชื่อนักเรียนที่อยู่ห้องนี้ (Auto-Enrollment)
        $stmt_std = $pdo->prepare("SELECT id, display_name, username FROM users WHERE class_id = ? AND role = 'student' AND is_deleted = 0 ORDER BY username ASC");
        $stmt_std->execute([$current_subject['class_id']]);
        $stmt_std->execute();
        $res_std = $stmt_std;
        while ($row = $res_std->fetch()) { $subject_students[] = $row; }
        

        // ดึงการบ้านของวิชานี้
        $stmt_ass = $pdo->prepare("SELECT * FROM class_assignments WHERE subject_id = ? ORDER BY created_at DESC");
        $stmt_ass->execute([$subject_id]);
        $stmt_ass->execute();
        $res_ass = $stmt_ass;
        while ($row = $res_ass->fetch()) { $subject_assignments[] = $row; }
        

    } else {
        // ถ้าไม่มีสิทธิ์ดู หรือใส่ ID มั่ว ให้เด้งกลับหน้ารวม
        header("Location: teacher_classroom.php");
        exit;
    }
    
}

// ข้อมูลสำหรับหน้ารวม (List Mode)
$my_subjects = [];
$classes = [];
if ($view_mode === 'list') {
    // ดึงวิชาทั้งหมดที่ครูสอน
    $stmt_list = $pdo->prepare("
        SELECT ts.*, c.class_name, 
               (SELECT COUNT(id) FROM users WHERE class_id = ts.class_id AND role='student' AND is_deleted=0) as std_count
        FROM teacher_subjects ts 
        JOIN classes c ON ts.class_id = c.id 
        WHERE ts.teacher_id = ? 
        ORDER BY ts.created_at DESC
    ");
    $stmt_list->execute([$teacher_id]);
    $stmt_list->execute();
    $res_list = $stmt_list;
    while ($row = $res_list->fetch()) { $my_subjects[] = $row; }
    

    // ดึงห้องเรียนไว้ทำ Dropdown ตอนสร้างวิชา
    $res_classes = $pdo->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
    while ($row = $res_classes->fetch()) { $classes[] = $row; }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--tc:<?= h($tenant['primary_color'] ?? '#1e3a8a') ?>;}
*{box-sizing:border-box}
.tcr-wrap{max-width:1100px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}
.tcr-hero{background:var(--tc);border-radius:18px;padding:26px 30px;margin-bottom:22px;color:#fff;
    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
    position:relative;overflow:hidden}
.tcr-hero::after{content:'📚';position:absolute;right:20px;bottom:-8px;font-size:5.5rem;opacity:.1}
.tcr-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:8px}
.tcr-hero h1{font-size:1.5rem;font-weight:700;margin:0 0 3px}
.tcr-hero .sub{font-size:.83rem;opacity:.85;margin:0}
.tcr-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:22px 0 13px;display:flex;align-items:center;gap:8px}
.tcr-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}
.tcr-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 22px;
    margin-bottom:16px;transition:.2s}
.tcr-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.06)}
.tcr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:14px}
.tcr-fg{display:flex;flex-direction:column;gap:4px}
.tcr-fg label{font-size:.76rem;color:#64748b;font-weight:600}
.tcr-fg input,.tcr-fg select,.tcr-fg textarea{
    padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;
    font-family:'Prompt',sans-serif;font-size:.88rem;width:100%;transition:.2s;resize:vertical}
.tcr-fg input:focus,.tcr-fg select:focus,.tcr-fg textarea:focus{outline:none;border-color:var(--tc)}
.tcr-fg.full{grid-column:1/-1}
.tcr-btn{padding:9px 20px;border:none;border-radius:9px;cursor:pointer;
    font-family:'Prompt',sans-serif;font-size:.88rem;font-weight:600;transition:.2s}
.tcr-btn.primary{background:var(--tc);color:#fff}
.tcr-btn.primary:hover{opacity:.85}
.tcr-btn.danger{background:#ef4444;color:#fff}
.tcr-btn.ghost{background:#f8fafc;color:#475569;border:1px solid #e2e8f0}
.tcr-subject-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.tcr-subject{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px;
    position:relative;overflow:hidden;transition:.2s}
.tcr-subject:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.tcr-subject::before{content:'';position:absolute;top:0;left:0;right:0;height:4px}
.tcr-subject-name{font-size:.95rem;font-weight:600;color:#0f172a;margin-bottom:4px}
.tcr-subject-meta{font-size:.75rem;color:#64748b}
.tcr-alert{padding:11px 16px;border-radius:9px;margin-bottom:16px;font-size:.85rem;font-weight:500}
.tcr-alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.tcr-alert.err{background:#fee2e2;color:#b91c1c;border:1px solid #fecaca}
@media(max-width:640px){.tcr-grid{grid-template-columns:1fr}.tcr-subject-grid{grid-template-columns:1fr}
    .tcr-wrap{padding:14px 14px 60px}}
</style>

<div class="class-wrapper">

    <?php if ($message): ?>
        <div class="alert-box alert-<?= $msg_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if ($view_mode === 'list'): ?>
        
        <div class="page-header">
            <div class="tcr-hero">
  <div style="position:relative;z-index:1">
    <div class="tcr-tag">📚 Smart Classroom</div>
    <h1>จัดการชั้นเรียน</h1>
    <p class="sub"><?= h($tenant['school_name'] ?? '') ?> · วิชาเรียน การบ้าน และนักเรียน</p>
  </div>
</div>
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