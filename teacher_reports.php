<?php
// teacher_reports.php - แดชบอร์ดตรวจสอบการบ้านและประเมินผล (Phase 3 of Super Teacher Override)
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRole(['teacher', 'developer']);

$page_title = "ตรวจรายงานและประเมินผล";
$teacher_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$is_dev = ($_SESSION['role'] === 'developer');

// 🌟 THE GOD MODE CHECK: ตรวจสอบว่าเป็น tea001 หรือ Developer หรือไม่
$is_super_teacher = $is_dev; // ใช้ role developer แทน hardcode username

$csrf = generate_csrf_token();
$message = '';
$msg_type = '';

// =========================================================
// 1. จัดการ POST Request (ครูต้องการปรับแก้คะแนน Manual Override)
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'override_score') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Validation Failed.");
    }

    $submission_id = intval($_POST['submission_id']);
    $new_score = floatval($_POST['new_score']);

    // 🔒 ตรวจสอบสิทธิ์การแก้คะแนน
    $can_edit = false;
    if ($is_super_teacher) {
        $can_edit = true; // Super Teacher แก้ได้ทุกอย่าง
    } else {
        // ครูปกติ ต้องเช็คก่อนว่าตัวเองเป็นคนสั่งการบ้านชิ้นนี้หรือไม่
        try {
            $check_stmt = $pdo->prepare("
                SELECT a.created_by FROM student_assignments sa
                JOIN assignments a ON sa.assignment_id = a.id
                WHERE sa.id = ?
            ");
            $check_stmt->execute([$submission_id]);
            $owner_id = $check_stmt->fetchColumn();
            if ($owner_id == $teacher_id) {
                $can_edit = true;
            }
        } catch (PDOException $e) {}
    }

    if ($can_edit && $submission_id > 0 && $new_score >= 0 && $new_score <= 100) {
        try {
            // อัปเดตคะแนนที่ครูแก้ไขทับลงไป
            $stmt_upd = $pdo->prepare("UPDATE student_assignments SET score = ?, graded_at = NOW() WHERE id = ?");
            if ($stmt_upd->execute([$new_score, $submission_id])) {
                $message = "✅ ปรับปรุงคะแนนใหม่เป็น {$new_score} สำเร็จ";
                $msg_type = "success";
            }
        } catch (PDOException $e) {
            $message = "❌ เกิดข้อผิดพลาดในการอัปเดตคะแนน: " . $e->getMessage();
            $msg_type = "error";
        }
    } elseif (!$can_edit) {
        $message = "⛔ ปฏิเสธการเข้าถึง: คุณไม่มีสิทธิ์แก้ไขคะแนนของรายวิชาที่คุณไม่ได้เป็นผู้สอน";
        $msg_type = "error";
    } else {
        $message = "⚠️ กรุณาระบุคะแนนระหว่าง 0 - 100";
        $msg_type = "warning";
    }
}

// =========================================================
// 2. ดึงข้อมูลการบ้านมาทำเป็นตัวกรอง (Filter Dropdown)
// =========================================================
$assignments = [];
try {
    if ($is_super_teacher) {
        // 🌟 Super Teacher: ดึงการบ้านทั้งหมด พร้อมแนบชื่อครูผู้สร้างมาด้วย
        $sql_a = "
            SELECT a.id, a.title, a.game_type, u.display_name as creator_name 
            FROM assignments a 
            LEFT JOIN users u ON a.created_by = u.id 
            WHERE a.is_active = 1
            ORDER BY a.created_at DESC
        ";
        $stmt_a = $pdo->query($sql_a);
        $assignments = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // 👨‍🏫 ครูปกติ: ดึงเฉพาะที่ตัวเองสร้าง
        $sql_a = "SELECT id, title, game_type FROM assignments WHERE created_by = ? AND is_active = 1 ORDER BY created_at DESC";
        $stmt_a = $pdo->prepare($sql_a);
        $stmt_a->execute([$teacher_id]);
        $assignments = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Failed to load assignments: " . $e->getMessage());
}

// รับค่าตัวกรอง
$filter_assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

// ถ้าไม่ได้เลือกการบ้าน และมีการบ้านในระบบ ให้เลือกตัวแรกสุดเป็นค่าเริ่มต้น
if ($filter_assignment_id === 0 && count($assignments) > 0) {
    $filter_assignment_id = $assignments[0]['id'];
}

// =========================================================
// 3. ดึงรายชื่อนักเรียนที่ส่งงาน (สำหรับภารกิจที่เลือก)
// =========================================================
$submissions = [];
$current_assignment_title = "กรุณาเลือกการบ้าน";

if ($filter_assignment_id > 0) {
    try {
        // หาชื่อภารกิจ
        $stmt_title = $pdo->prepare("SELECT title FROM assignments WHERE id = ?");
        $stmt_title->execute([$filter_assignment_id]);
        $title_row = $stmt_title->fetch(PDO::FETCH_ASSOC);
        if ($title_row) $current_assignment_title = $title_row['title'];

        // ดึงงานของนักเรียน
        $sql = "
            SELECT sa.id as submission_id, u.username, u.display_name, c.class_name, 
                   COALESCE(st.status_key,'pending') AS status, sa.score, sa.teacher_feedback, sa.submitted_at
            FROM student_assignments sa
            JOIN users u ON sa.student_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN sys_task_statuses st ON st.id = sa.status_id
            WHERE sa.assignment_id = ?
            ORDER BY sa.submitted_at DESC
        ";
        $stmt_sub = $pdo->prepare($sql);
        $stmt_sub->execute([$filter_assignment_id]);
        $submissions = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $message = "❌ เกิดข้อผิดพลาดในการดึงข้อมูลนักเรียน: " . $e->getMessage();
        $msg_type = "error";
    }
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
    body { background-color: #f8fafc; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; -webkit-tap-highlight-color: transparent;}
    .report-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; box-sizing: border-box; }
    
    .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;}
    .page-title { margin: 0; font-size: 1.8rem; font-weight: bold; color: #1e3a8a; display: flex; align-items: center; gap: 10px; }
    
    .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; font-weight: bold; }
    .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert.warning { background: #fefce8; color: #b45309; border: 1px solid #fde047; }

    /* Super Teacher Banner */
    .super-teacher-banner { background: #fefce8; border: 2px dashed #fde047; color: #b45309; padding: 15px 25px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(253, 224, 71, 0.2); }
    .super-teacher-banner .st-icon { font-size: 2rem; animation: pulseIcon 2s infinite; }
    @keyframes pulseIcon { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }

    /* Filter Box */
    .filter-box { background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 30px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;}
    .form-group { flex: 1; min-width: 300px; }
    .form-label { display: block; font-size: 1rem; font-weight: bold; color: #475569; margin-bottom: 8px; }
    .form-select { width: 100%; padding: 14px 15px; border-radius: 10px; border: 1px solid #cbd5e1; background: #f8fafc; font-family: inherit; font-size: 1rem; color: #1e293b; outline:none; transition: 0.3s;}
    .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); background: white;}
    .btn-filter { background: #1e3a8a; color: white; border: none; padding: 14px 30px; border-radius: 10px; cursor: pointer; font-weight: bold; height: 50px; font-size: 1.05rem; transition: 0.3s; display: flex; align-items: center; gap: 8px;}
    .btn-filter:hover { background: #1e40af; transform: translateY(-2px); }

    /* Action Toolbar (Export) */
    .action-toolbar { display: flex; justify-content: flex-end; margin-bottom: 15px; }
    .btn-export { background: #16a34a; color: white; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.3); }
    .btn-export:hover { background: #15803d; transform: translateY(-2px); }

    /* Table Area */
    .table-wrapper { background: white; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; overflow: hidden; }
    .table-header { background: #f1f5f9; padding: 20px; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;}
    .table-header h3 { margin: 0; color: #1e293b; font-size: 1.2rem; }
    .badge-count { background: #3b82f6; color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.9rem; font-weight: bold; }

    .styled-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .styled-table th, .styled-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .styled-table th { color: #64748b; font-weight: bold; background: #f8fafc; }
    .styled-table tbody tr:hover { background: #f8fafc; }
    
    .score-badge { font-family: 'Share Tech Mono', monospace; font-size: 1.3rem; font-weight: bold; padding: 5px 15px; border-radius: 8px; text-align: center; display: inline-block; min-width: 60px; }
    .score-high { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .score-mid { background: #fefce8; color: #b45309; border: 1px solid #fde047; }
    .score-low { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    .btn-inspect { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 8px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-family: inherit; font-size: 0.95rem; }
    .btn-inspect:hover { background: #dbeafe; transform: scale(1.05); }

    /* Modal Inspection (Teacher Review UI) */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(8px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .modal-box { background: white; width: 100%; max-width: 700px; border-radius: 20px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,0.5); transform: translateY(30px); opacity: 0; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); display: flex; flex-direction: column; max-height: 90vh; }
    .modal-box.show { transform: translateY(0); opacity: 1; }
    
    .modal-header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); padding: 25px; color: white; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }
    .btn-close { background: transparent; border: none; color: rgba(255,255,255,0.7); font-size: 1.8rem; cursor: pointer; transition: 0.2s; }
    .btn-close:hover { color: white; transform: rotate(90deg); }

    .modal-body { padding: 30px; overflow-y: auto; flex: 1; background: #f8fafc; }
    
    /* โซนโชว์ AI Breakdown */
    .ai-breakdown-box { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.02); }
    .ai-breakdown-box h3 { margin: 0 0 15px 0; color: #1e293b; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
    .bd-list { list-style: none; padding: 0; margin: 0; }
    .bd-item { padding: 10px 0; border-bottom: 1px dashed #e2e8f0; display: flex; gap: 10px; font-size: 0.95rem; color: #475569; line-height: 1.4; }
    .bd-item:last-child { border-bottom: none; }
    .item-good { color: #166534; background: #f0fdf4; padding: 10px; border-radius: 8px; }
    .item-bad { color: #991b1b; background: #fef2f2; padding: 10px; border-radius: 8px; }
    .item-warn { color: #9a3412; background: #fffbeb; padding: 10px; border-radius: 8px; }

    /* โซนโชว์ Telemetry Data */
    .telemetry-box { background: #0f172a; border-radius: 12px; padding: 20px; color: #cbd5e1; font-family: 'Share Tech Mono', monospace; font-size: 0.95rem; margin-bottom: 20px; box-shadow: inset 0 4px 10px rgba(0,0,0,0.5); }
    .telemetry-box h3 { margin: 0 0 15px 0; color: #38bdf8; border-bottom: 1px dashed #334155; padding-bottom: 10px; font-family: 'Prompt', sans-serif;}

    /* โซนแก้ไขคะแนน */
    .override-box { background: #fffbeb; border: 2px dashed #f59e0b; border-radius: 12px; padding: 20px; text-align: center; }
    .override-box label { font-weight: bold; color: #b45309; display: block; margin-bottom: 10px; font-size: 1.1rem; }
    .score-input { width: 120px; font-family: 'Share Tech Mono'; font-size: 2.5rem; text-align: center; padding: 10px; border-radius: 10px; border: 2px solid #fcd34d; color: #1e293b; background: white; outline: none; }
    .score-input:focus { border-color: #f59e0b; box-shadow: 0 0 15px rgba(245, 158, 11, 0.3); }

    .modal-footer { padding: 20px 30px; background: white; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 15px; }
    .btn-cancel { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s; font-family: inherit; font-size: 1rem; }
    .btn-cancel:hover { background: #e2e8f0; }
    .btn-save { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 12px 35px; border-radius: 8px; font-weight: bold; cursor: pointer; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4); transition: 0.2s; font-family: inherit; font-size: 1.1rem; }
    .btn-save:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5); }

    @media (max-width: 768px) {
        .filter-box { flex-direction: column; align-items: stretch; padding: 20px; }
        .table-wrapper { overflow-x: auto; }
        .modal-box { height: 100vh; max-height: 100vh; border-radius: 0; }
        .modal-footer { flex-direction: column-reverse; }
        .btn-cancel, .btn-save { width: 100%; }
        .action-toolbar { justify-content: stretch; }
        .btn-export { width: 100%; justify-content: center; }
    }
</style>

<div class="report-container">
    <div class="top-bar">
        <h1 class="page-title">📊 แดชบอร์ดตรวจสอบการทดลอง (Review Dashboard)</h1>
        <a href="dashboard_teacher.php" style="color:#64748b; font-weight:bold; text-decoration:none;">⬅ กลับหน้าหลัก</a>
    </div>

    <?php if ($is_super_teacher): ?>
        <div class="super-teacher-banner">
            <div class="st-icon">🌟</div>
            <div>
                <h3 style="margin: 0 0 5px 0;">Super Teacher Mode (God Mode)</h3>
                <p style="margin: 0; font-size: 0.95rem;">คุณสามารถเข้าดูและประเมินผลการบ้านของ <strong>นักเรียนและครูทุกคนในโรงเรียน</strong> ได้จากหน้านี้ทั้งหมด</p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert <?= $msg_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <form method="GET" class="filter-box">
        <div class="form-group">
            <label class="form-label">📌 เลือกภารกิจ / การบ้านที่ต้องการตรวจ</label>
            <select name="assignment_id" class="form-select">
                <?php if(empty($assignments)): ?>
                    <option value="0">-- ยังไม่มีการบ้านในระบบ --</option>
                <?php else: ?>
                    <?php foreach($assignments as $a): ?>
                        <?php 
                            // ถ้าเป็น God Mode ให้โชว์ชื่อครูผู้สั่งการบ้านด้วย
                            $creator_tag = isset($a['creator_name']) ? " (โดย ครู" . h($a['creator_name']) . ")" : "";
                            $game_tag = $a['game_type'] === 'chem_lab' ? ' [🧪 Auto-Grade]' : '';
                        ?>
                        <option value="<?= $a['id'] ?>" <?= ($filter_assignment_id == $a['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['title']) . $game_tag . $creator_tag ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <button type="submit" class="btn-filter">📥 โหลดข้อมูลนักเรียน</button>
    </form>

    <?php if ($filter_assignment_id > 0 && count($submissions) > 0): ?>
    <div class="action-toolbar">
        <a href="export_csv.php?assignment_id=<?= $filter_assignment_id ?>" target="_blank" class="btn-export">
            <span>⬇️</span> ดาวน์โหลดไฟล์คะแนน (Excel CSV)
        </a>
    </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <div class="table-header">
            <h3>📑 ผลงาน: <?= htmlspecialchars($current_assignment_title) ?></h3>
            <div class="badge-count">ส่งแล้ว <?= count($submissions) ?> คน</div>
        </div>

        <div style="overflow-x: auto;">
            <table class="styled-table">
                <thead>
                    <tr>
                        <th width="20%">ชื่อนักเรียน</th>
                        <th width="15%">ห้องเรียน</th>
                        <th width="20%">เวลาที่ส่ง</th>
                        <th width="20%" style="text-align: center;">คะแนน (AI ตรวจ)</th>
                        <th width="25%" style="text-align: center;">ตรวจสอบ / แก้ไข</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($submissions) > 0): ?>
                        <?php foreach ($submissions as $sub): ?>
                            <?php 
                                $score = floatval($sub['score']);
                                $score_class = 'score-low';
                                if ($score >= 80) $score_class = 'score-high';
                                elseif ($score >= 50) $score_class = 'score-mid';
                                
                                // นำ JSON ออกมาซ่อนไว้ใน HTML attribute เพื่อให้ JS ดึงไปแสดงบน Modal ได้
                                $feedback_json = htmlspecialchars($sub['teacher_feedback'] ?? '{}', ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight: bold; color: #1e293b;"><?= htmlspecialchars($sub['display_name']) ?></div>
                                    <div style="font-size: 0.85rem; color: #64748b; font-family: monospace;">@<?= htmlspecialchars($sub['username']) ?></div>
                                </td>
                                <td style="color: #475569;">ม.<?= htmlspecialchars($sub['class_name'] ?? '-') ?></td>
                                <td style="color: #64748b; font-size: 0.9rem;"><?= date('d/m/Y H:i', strtotime($sub['submitted_at'])) ?></td>
                                <td style="text-align: center;">
                                    <span class="score-badge <?= $score_class ?>"><?= $score ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn-inspect" onclick="openInspection(<?= $sub['submission_id'] ?>, '<?= htmlspecialchars($sub['display_name']) ?>', <?= $score ?>, '<?= $feedback_json ?>')">
                                        🔍 ดูผลตรวจ AI
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 60px; color: #94a3b8;">
                                <span style="font-size: 3rem; display:block; margin-bottom: 10px;">📭</span>
                                ยังไม่มีนักเรียนส่งงานในภารกิจนี้
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="inspectModal">
    <div class="modal-box" id="boxInspect">
        <div class="modal-header">
            <h2>🔍 รายงานการวิเคราะห์ผลโดย AI</h2>
            <button type="button" class="btn-close" onclick="closeModal()">✖</button>
        </div>
        
        <div class="modal-body">
            <h3 style="margin: 0 0 20px 0; color: #1e293b; text-align: center;">ผลงานของ: <span id="insStudentName" style="color: #3b82f6;"></span></h3>
            
            <div class="ai-breakdown-box">
                <h3>🤖 เหตุผลการให้คะแนน (AI Grading Breakdown)</h3>
                <ul class="bd-list" id="insBreakdownList">
                    </ul>
            </div>

            <div class="telemetry-box">
                <h3>📡 ข้อมูลพฤติกรรมดิบ (Telemetry Sensors)</h3>
                <div id="insTelemetryData"></div>
            </div>

            <form method="POST" action="teacher_reports.php?assignment_id=<?= $filter_assignment_id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="override_score">
                <input type="hidden" name="submission_id" id="insSubmissionId" value="">
                
                <div class="override-box">
                    <label>✍️ ปรับแก้คะแนน (Manual Override)</label>
                    <input type="number" name="new_score" id="insScoreInput" class="score-input" step="0.01" min="0" max="100" required>
                    <div style="font-size: 0.85rem; color: #94a3b8; margin-top: 10px;">หากเห็นว่า AI ประเมินผิดพลาด ครูสามารถพิมพ์คะแนนใหม่แล้วกดบันทึกได้เลย</div>
                </div>

                <div class="modal-footer" style="margin: 30px -30px -30px -30px;">
                    <button type="button" class="btn-cancel" onclick="closeModal()">ปิดหน้าต่าง</button>
                    <button type="submit" class="btn-save">💾 ยืนยันการปรับคะแนน</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // สคริปต์เปิดหน้าต่างและถอดรหัส JSON เพื่อวาดกราฟิกให้ครูอ่านง่าย
    function openInspection(submissionId, studentName, currentScore, feedbackJsonString) {
        document.getElementById('insSubmissionId').value = submissionId;
        document.getElementById('insStudentName').innerText = studentName;
        document.getElementById('insScoreInput').value = currentScore;

        const bdList = document.getElementById('insBreakdownList');
        const telData = document.getElementById('insTelemetryData');
        bdList.innerHTML = '';
        telData.innerHTML = '';

        try {
            const fb = JSON.parse(feedbackJsonString);
            
            // 1. วาด AI Breakdown
            if (fb && fb.breakdown && fb.breakdown.length > 0) {
                fb.breakdown.forEach(item => {
                    let cssClass = 'item-warn';
                    if (item.includes('✅')) cssClass = 'item-good';
                    if (item.includes('❌')) cssClass = 'item-bad';
                    bdList.innerHTML += `<li class="bd-item ${cssClass}">${item}</li>`;
                });
            } else {
                bdList.innerHTML = '<li class="bd-item">ไม่มีข้อมูลการประเมินอัตโนมัติ (อาจเป็นการส่งงานธรรมดา)</li>';
            }

            // 2. วาด Telemetry Data (ข้อมูลดิบ)
            if (fb && fb.student_telemetry) {
                const t = fb.student_telemetry;
                
                // แกะข้อมูลสารเคมี
                let chemHtml = '';
                if (t.beaker_items && t.beaker_items.length > 0) {
                    chemHtml = t.beaker_items.map(i => `<span style="color:#4ade80;">${i.name} (${i.amount}ml)</span>`).join(', ');
                } else {
                    chemHtml = '<span style="color:#f87171;">บีกเกอร์ว่างเปล่า</span>';
                }

                // แกะข้อมูลอื่นๆ
                const didStir = t.actions && t.actions.did_stir ? '<span style="color:#4ade80;">ใช่</span>' : '<span style="color:#f87171;">ไม่</span>';
                const spillCount = t.actions ? t.actions.spill_count : 0;
                const hp = t.hp_remaining ?? 100;
                const goggles = t.safety && t.safety.goggles ? '<span style="color:#4ade80;">ใส่</span>' : '<span style="color:#f87171;">ไม่ใส่</span>';
                const gloves = t.safety && t.safety.gloves ? '<span style="color:#4ade80;">ใส่</span>' : '<span style="color:#f87171;">ไม่ใส่</span>';

                telData.innerHTML = `
                    <div style="margin-bottom:5px;">> สารเคมีที่ส่งตรวจ: ${chemHtml}</div>
                    <div style="margin-bottom:5px;">> การคนสาร: ${didStir}</div>
                    <div style="margin-bottom:5px;">> การทำหก: ${spillCount > 0 ? '<span style="color:#f87171;">'+spillCount+' ครั้ง</span>' : '<span style="color:#4ade80;">0 ครั้ง</span>'}</div>
                    <div style="margin-bottom:5px;">> อุปกรณ์ความปลอดภัย: แว่น(${goggles}) | ถุงมือ(${gloves})</div>
                    <div style="margin-bottom:5px;">> พลังชีวิต (HP) คงเหลือ: <span style="color:${hp < 50 ? '#f87171' : '#4ade80'};">${hp}%</span></div>
                `;
            } else {
                telData.innerHTML = "<div>ไม่พบข้อมูลเซนเซอร์การทำแล็บ</div>";
            }

        } catch (e) {
            console.error("JSON Parse Error: ", e);
            bdList.innerHTML = '<li class="bd-item item-bad">❌ ไม่สามารถอ่านรูปแบบข้อมูลได้</li>';
        }

        // แสดง Modal
        const m = document.getElementById('inspectModal');
        const b = document.getElementById('boxInspect');
        m.style.display = 'flex';
        setTimeout(() => b.classList.add('show'), 10);
    }

    function closeModal() {
        const m = document.getElementById('inspectModal');
        const b = document.getElementById('boxInspect');
        b.classList.remove('show');
        setTimeout(() => m.style.display = 'none', 400);
    }

    window.onclick = function(event) {
        if (event.target === document.getElementById('inspectModal')) {
            closeModal();
        }
    }
</script>

<?php require_once 'footer.php'; ?>