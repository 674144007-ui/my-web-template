<?php
/**
 * dev_reset_progress.php — ระบบรีเซ็ต XP / คะแนน / เควสนักเรียน
 * เฉพาะ Developer เท่านั้น
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once 'logger.php';

requireRole(['developer']);
$dev_id   = $_SESSION['user_id'];
$dev_name = $_SESSION['display_name'] ?? $_SESSION['username'];
$csrf     = generate_csrf_token();

$result_msg  = '';
$result_type = '';

// ═══════════════════════════════════════════════════════════════
//  POST HANDLER
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF Verification Failed.');
    }

    $action     = $_POST['action']     ?? '';
    $scope      = $_POST['scope']      ?? 'student';   // student | class | all
    $target_id  = intval($_POST['target_id']  ?? 0);
    $class_id   = intval($_POST['class_id']   ?? 0);
    $reset_labs = !empty($_POST['reset_labs']);       // lab_reports + student_assignments
    $reset_xp   = !empty($_POST['reset_xp']);         // results (game XP)
    $reset_badges = !empty($_POST['reset_badges']);   // student_badges

    if ($action === 'reset' && ($reset_labs || $reset_xp || $reset_badges)) {
        try {
            $pdo->beginTransaction();

            // สร้าง IN clause ของ student ids ที่จะรีเซ็ต
            if ($scope === 'student' && $target_id > 0) {
                $ids = [$target_id];
            } elseif ($scope === 'class' && $class_id > 0) {
                $s = $pdo->prepare("SELECT id FROM users WHERE class_id=? AND role='student' AND is_deleted=0");
                $s->execute([$class_id]);
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($scope === 'all') {
                $s = $pdo->query("SELECT id FROM users WHERE role='student' AND is_deleted=0");
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
            } else {
                throw new Exception('ระบุ target ไม่ถูกต้อง');
            }

            if (empty($ids)) throw new Exception('ไม่พบนักเรียนที่ตรงเงื่อนไข');

            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $log = [];

            if ($reset_labs) {
                // ลบ lab_reports
                $pdo->prepare("DELETE FROM lab_reports WHERE student_id IN ($ph)")->execute($ids);
                // รีเซ็ต student_assignments กลับเป็น pending
                $pdo->prepare(
                    "UPDATE student_assignments SET status_id=(SELECT id FROM sys_task_statuses WHERE status_key='pending' LIMIT 1), score=NULL,
                     submitted_at=NULL, graded_at=NULL
                     WHERE student_id IN ($ph)"
                )->execute($ids);
                $log[] = 'Lab Reports & Quest Progress';
            }

            if ($reset_xp) {
                $pdo->prepare("DELETE FROM results WHERE user_id IN ($ph)")->execute($ids);
                $log[] = 'Game XP / Results';
            }

            if ($reset_badges) {
                $pdo->prepare("DELETE FROM student_badges WHERE student_id IN ($ph)")->execute($ids);
                $log[] = 'Badges';
            }

            $pdo->commit();

            $target_label = match($scope) {
                'student' => "นักเรียน ID #{$target_id}",
                'class'   => "ห้องเรียน ID #{$class_id}",
                'all'     => 'นักเรียนทั้งหมด',
            };
            $log_str = implode(', ', $log);
            systemLog($dev_id, 'DEV_RESET_PROGRESS',
                "Reset [{$log_str}] for {$target_label} | {$dev_name} | " . count($ids) . " student(s)");

            $result_msg  = "✅ รีเซ็ตสำเร็จ: [{$log_str}] สำหรับ {$target_label} (" . count($ids) . " คน)";
            $result_type = 'success';

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result_msg  = '❌ เกิดข้อผิดพลาด: ' . h($e->getMessage());
            $result_type = 'error';
        }
    }
}

// ═══════════════════════════════════════════════════════════════
//  DATA FOR UI
// ═══════════════════════════════════════════════════════════════
// นักเรียนทั้งหมด (+ class info)
$students = $pdo->query(
    "SELECT u.id, u.display_name, u.username, u.class_id,
            c.class_name,
            (SELECT COUNT(*) FROM lab_reports lr WHERE lr.student_id = u.id) AS lab_count,
            (SELECT COUNT(*) FROM student_assignments sa WHERE sa.student_id = u.id AND sa.status_id=(SELECT id FROM sys_task_statuses WHERE status_key='graded' LIMIT 1)) AS graded_count,
            (SELECT COUNT(*) FROM student_badges sb WHERE sb.student_id = u.id) AS badge_count
     FROM   users u
     LEFT   JOIN classes c ON c.id = u.class_id
     JOIN sys_roles r ON r.id=u.role_id WHERE r.role_key='student' AND u.is_deleted = 0
     ORDER  BY c.class_name, u.display_name"
)->fetchAll(PDO::FETCH_ASSOC);

// ห้องเรียน
$classes = $pdo->query(
    "SELECT c.id, c.class_name,
            COUNT(u.id) AS student_count
     FROM   classes c
     LEFT JOIN users u ON u.class_id=c.id JOIN sys_roles r ON r.id=u.role_id AND r.role_key='student' AND u.is_deleted=0
     GROUP  BY c.id ORDER BY c.class_name"
)->fetchAll(PDO::FETCH_ASSOC);

require_once 'header.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dev: Reset Progress — Bankha Lab</title>
<style>
:root {
    --bg:       #07090f;
    --surface:  #0d1117;
    --card:     #111827;
    --border:   rgba(255,255,255,0.07);
    --border2:  rgba(255,255,255,0.12);
    --text:     #e2e8f0;
    --muted:    #64748b;
    --accent:   #f59e0b;
    --accent2:  #fbbf24;
    --red:      #ef4444;
    --green:    #10b981;
    --blue:     #38bdf8;
    --purple:   #a78bfa;
    --dev:      #ec4899;
    --radius:   12px;
    --radius-lg:18px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Prompt', 'Segoe UI', sans-serif;
    font-size: 15px;
    line-height: 1.6;
    min-height: 100vh;
}

/* ── Layout ── */
.page-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 32px 20px 80px;
}

/* ── Header banner ── */
.dev-banner {
    display: flex; align-items: center; gap: 16px;
    background: linear-gradient(135deg, rgba(236,72,153,0.12), rgba(168,85,247,0.08));
    border: 1px solid rgba(236,72,153,0.3);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    margin-bottom: 28px;
    position: relative; overflow: hidden;
}
.dev-banner::before {
    content: '';
    position: absolute; inset: 0;
    background: repeating-linear-gradient(
        -55deg,
        transparent, transparent 10px,
        rgba(236,72,153,0.025) 10px, rgba(236,72,153,0.025) 20px
    );
}
.dev-badge {
    background: rgba(236,72,153,0.2);
    border: 1px solid rgba(236,72,153,0.4);
    color: var(--dev);
    border-radius: 8px;
    padding: 6px 14px;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    white-space: nowrap;
}
.banner-text h1 { font-size: 1.3rem; font-weight: 700; color: #f8fafc; }
.banner-text p  { font-size: 0.84rem; color: var(--muted); margin-top: 3px; }

/* ── Alert ── */
.alert {
    border-radius: var(--radius);
    padding: 14px 18px;
    font-size: 0.9rem;
    margin-bottom: 24px;
    border-left: 4px solid;
    animation: slideIn 0.3s ease;
}
.alert.success { background: rgba(16,185,129,0.1); border-color: var(--green); color: #6ee7b7; }
.alert.error   { background: rgba(239,68,68,0.1);  border-color: var(--red);   color: #fca5a5; }
@keyframes slideIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

/* ── Grid ── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }
@media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }

/* ── Card ── */
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
}
.card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,0.02);
}
.card-header h3 { font-size: 0.95rem; font-weight: 600; color: var(--text); }
.card-body { padding: 20px; }

/* ── Form controls ── */
label.field-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 7px;
}
select, input[type="text"] {
    width: 100%;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border2);
    border-radius: 8px;
    color: var(--text);
    padding: 10px 14px;
    font-size: 0.9rem;
    font-family: inherit;
    appearance: none;
    outline: none;
    transition: border-color 0.2s;
}
select:focus, input[type="text"]:focus {
    border-color: var(--accent);
}
select option { background: #1e293b; }
.form-row { margin-bottom: 16px; }

/* ── Scope tabs ── */
.scope-tabs {
    display: flex; gap: 8px; margin-bottom: 20px;
}
.scope-tab {
    flex: 1; padding: 9px; border-radius: 8px;
    border: 1px solid var(--border2);
    background: transparent;
    color: var(--muted);
    font-family: inherit;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}
.scope-tab:hover { border-color: var(--accent); color: var(--accent); }
.scope-tab.active {
    background: rgba(245,158,11,0.15);
    border-color: var(--accent);
    color: var(--accent2);
}

/* ── Checkboxes (reset options) ── */
.check-group { display: flex; flex-direction: column; gap: 10px; }
.check-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
}
.check-item:hover { border-color: var(--border2); background: rgba(255,255,255,0.05); }
.check-item input[type="checkbox"] {
    width: 18px; height: 18px;
    accent-color: var(--accent);
    cursor: pointer; flex-shrink: 0;
}
.check-item-text { flex: 1; }
.check-item-title { font-size: 0.9rem; font-weight: 600; color: var(--text); }
.check-item-desc  { font-size: 0.77rem; color: var(--muted); margin-top: 2px; }
.check-item-tag {
    font-size: 0.68rem; font-weight: 700; padding: 2px 8px;
    border-radius: 20px; white-space: nowrap;
}
.tag-red    { background: rgba(239,68,68,0.15);  color: #f87171;  border: 1px solid rgba(239,68,68,0.3); }
.tag-amber  { background: rgba(245,158,11,0.15); color: #fbbf24;  border: 1px solid rgba(245,158,11,0.3); }
.tag-purple { background: rgba(167,139,250,0.15);color: #c4b5fd;  border: 1px solid rgba(167,139,250,0.3); }

/* ── Confirm section ── */
.confirm-section {
    margin-top: 20px;
    padding: 16px;
    background: rgba(239,68,68,0.06);
    border: 1px solid rgba(239,68,68,0.2);
    border-radius: var(--radius);
}
.confirm-section p { font-size: 0.82rem; color: #fca5a5; margin-bottom: 12px; }
.confirm-row { display: flex; align-items: center; gap: 10px; }
#confirmText {
    flex: 1;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(239,68,68,0.3);
    border-radius: 8px;
    color: var(--text);
    padding: 9px 12px;
    font-family: 'Share Tech Mono', monospace;
    font-size: 0.88rem;
}
#confirmText:focus { outline: none; border-color: var(--red); }

/* ── Buttons ── */
.btn-reset {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: white; border: none;
    border-radius: 10px;
    padding: 11px 24px;
    font-family: inherit; font-size: 0.9rem; font-weight: 700;
    cursor: pointer; white-space: nowrap;
    transition: all 0.2s;
    box-shadow: 0 4px 14px rgba(220,38,38,0.35);
    opacity: 0.4; pointer-events: none;
}
.btn-reset.unlocked {
    opacity: 1; pointer-events: all;
}
.btn-reset.unlocked:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(220,38,38,0.5);
}

/* ── Student table ── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
th {
    text-align: left;
    padding: 10px 14px;
    color: var(--muted);
    font-size: 0.72rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
td {
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    vertical-align: middle;
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(255,255,255,0.02); }
.badge-count { 
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; height: 22px;
    border-radius: 6px; font-size: 0.75rem; font-weight: 700; padding: 0 6px;
}
.count-0   { background: rgba(100,116,139,0.15); color: var(--muted); }
.count-pos { background: rgba(16,185,129,0.15);  color: #34d399; }
.btn-quick-reset {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.25);
    color: #f87171;
    border-radius: 6px; padding: 4px 10px;
    font-size: 0.75rem; font-family: inherit; font-weight: 600;
    cursor: pointer; transition: 0.2s; white-space: nowrap;
}
.btn-quick-reset:hover { background: rgba(239,68,68,0.2); border-color: var(--red); }

/* ── Search ── */
.search-bar {
    position: relative; margin-bottom: 14px;
}
.search-bar input {
    padding-left: 38px;
    background: rgba(255,255,255,0.03);
}
.search-bar::before {
    content: '🔍';
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    font-size: 0.85rem; pointer-events: none;
}

/* ── Hidden panels ── */
.scope-panel { display: none; }
.scope-panel.active { display: block; }

/* ── Danger zone ── */
.danger-zone {
    border: 1px solid rgba(239,68,68,0.3);
    border-radius: var(--radius-lg);
    padding: 20px 24px;
    background: rgba(239,68,68,0.04);
    margin-top: 12px;
}
.danger-label {
    font-size: 0.7rem; font-weight: 800; letter-spacing: 2px;
    text-transform: uppercase; color: var(--red);
    margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}

/* ── Log preview ── */
.log-line {
    font-family: 'Share Tech Mono', monospace;
    font-size: 0.78rem; color: var(--muted);
    padding: 3px 0; border-bottom: 1px solid rgba(255,255,255,0.03);
}
.log-line:last-child { border: none; }
.log-ok   { color: #34d399; }
.log-warn { color: #fbbf24; }
</style>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="page-wrap">

    <!-- Banner -->
    <div class="dev-banner">
        <div class="dev-badge">⚡ DEV ONLY</div>
        <div class="banner-text">
            <h1>🔄 Reset Student Progress</h1>
            <p>รีเซ็ต XP คะแนน และเควสที่ทำแล้ว — ใช้เพื่อทดสอบระบบ / เปิดรอบใหม่</p>
        </div>
    </div>

    <?php if ($result_msg): ?>
    <div class="alert <?= $result_type ?>">
        <?= $result_msg ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="resetForm">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="scope" id="scopeInput" value="student">
        <input type="hidden" name="target_id" id="targetIdInput" value="0">
        <input type="hidden" name="class_id" id="classIdInput" value="0">

        <div class="grid-2">

            <!-- ── LEFT: เลือก Target ── -->
            <div class="card">
                <div class="card-header">
                    <span>🎯</span>
                    <h3>เลือก Target ที่จะรีเซ็ต</h3>
                </div>
                <div class="card-body">

                    <!-- Scope Tabs -->
                    <div class="scope-tabs">
                        <button type="button" class="scope-tab active" data-scope="student" onclick="setScope('student')">👤 รายคน</button>
                        <button type="button" class="scope-tab" data-scope="class" onclick="setScope('class')">🏫 ห้องเรียน</button>
                        <button type="button" class="scope-tab" data-scope="all" onclick="setScope('all')">🌐 ทั้งหมด</button>
                    </div>

                    <!-- Panel: รายคน -->
                    <div class="scope-panel active" id="panel-student">
                        <label class="field-label">เลือกนักเรียน</label>
                        <select id="studentSelect" onchange="document.getElementById('targetIdInput').value=this.value; updateSummary()">
                            <option value="0">— กรุณาเลือกนักเรียน —</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= $s['id'] ?>"
                                data-labs="<?= $s['lab_count'] ?>"
                                data-graded="<?= $s['graded_count'] ?>"
                                data-badges="<?= $s['badge_count'] ?>">
                                <?= h($s['display_name']) ?>
                                (<?= h($s['class_name'] ?? 'ไม่มีห้อง') ?>)
                                — แล็บ <?= $s['lab_count'] ?> รายการ
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Panel: ห้องเรียน -->
                    <div class="scope-panel" id="panel-class">
                        <label class="field-label">เลือกห้องเรียน</label>
                        <select id="classSelect" onchange="document.getElementById('classIdInput').value=this.value; updateSummary()">
                            <option value="0">— กรุณาเลือกห้องเรียน —</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= h($c['class_name']) ?> (<?= $c['student_count'] ?> คน)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Panel: ทั้งหมด -->
                    <div class="scope-panel" id="panel-all">
                        <div style="padding:14px; background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:10px; color:#fca5a5; font-size:0.85rem; text-align:center;">
                            ⚠️ จะรีเซ็ตข้อมูลของนักเรียน <strong><?= count($students) ?> คน</strong> ทั้งหมดในระบบ
                        </div>
                    </div>

                    <!-- Summary box -->
                    <div id="summaryBox" style="margin-top:16px; padding:12px 14px; background:rgba(245,158,11,0.07); border:1px solid rgba(245,158,11,0.2); border-radius:10px; display:none;">
                        <div style="font-size:0.75rem; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:1px; font-weight:600;">ข้อมูลปัจจุบัน</div>
                        <div id="summaryContent" style="font-size:0.85rem; color:var(--accent2); line-height:1.8;"></div>
                    </div>

                </div>
            </div>

            <!-- ── RIGHT: เลือก Reset Options ── -->
            <div class="card">
                <div class="card-header">
                    <span>🗑️</span>
                    <h3>เลือกข้อมูลที่จะรีเซ็ต</h3>
                </div>
                <div class="card-body">

                    <div class="check-group">
                        <label class="check-item" onclick="updateConfirm()">
                            <input type="checkbox" name="reset_labs" id="cbLabs" value="1">
                            <div class="check-item-text">
                                <div class="check-item-title">📋 Lab Reports & เควสที่ทำแล้ว</div>
                                <div class="check-item-desc">ลบ lab_reports + รีเซ็ต student_assignments กลับเป็น pending</div>
                            </div>
                            <span class="check-item-tag tag-red">สำคัญ</span>
                        </label>

                        <label class="check-item" onclick="updateConfirm()">
                            <input type="checkbox" name="reset_xp" id="cbXP" value="1">
                            <div class="check-item-text">
                                <div class="check-item-title">⭐ Game XP / ผลเกม</div>
                                <div class="check-item-desc">ลบข้อมูลในตาราง results (คะแนนเกมและ XP)</div>
                            </div>
                            <span class="check-item-tag tag-amber">XP</span>
                        </label>

                        <label class="check-item" onclick="updateConfirm()">
                            <input type="checkbox" name="reset_badges" id="cbBadges" value="1">
                            <div class="check-item-text">
                                <div class="check-item-title">🏅 Badges / รางวัล</div>
                                <div class="check-item-desc">ลบ student_badges ทั้งหมด</div>
                            </div>
                            <span class="check-item-tag tag-purple">ไม่บังคับ</span>
                        </label>
                    </div>

                    <!-- Confirm -->
                    <div class="confirm-section" style="display:none;" id="confirmSection">
                        <p>⚠️ การรีเซ็ตไม่สามารถยกเลิกได้ พิมพ์ <strong style="color:white;">RESET</strong> เพื่อยืนยัน</p>
                        <div class="confirm-row">
                            <input type="text" id="confirmText" placeholder="พิมพ์ RESET เพื่อยืนยัน" oninput="checkConfirm()" autocomplete="off">
                            <button type="submit" class="btn-reset" id="btnReset">🔄 ดำเนินการรีเซ็ต</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </form>

    <!-- ── ตารางนักเรียน ── -->
    <div class="card">
        <div class="card-header">
            <span>👥</span>
            <h3>รายชื่อนักเรียนและข้อมูลสะสม</h3>
        </div>
        <div class="card-body">
            <div class="search-bar">
                <input type="text" id="searchInput" placeholder="ค้นหาชื่อนักเรียน..." oninput="filterTable()">
            </div>
            <div class="table-wrap">
                <table id="studentTable">
                    <thead>
                        <tr>
                            <th>ชื่อนักเรียน</th>
                            <th>ห้อง</th>
                            <th>Lab Reports</th>
                            <th>เควสผ่าน</th>
                            <th>Badges</th>
                            <th>รีเซ็ตรายคน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600; color:var(--text);"><?= h($s['display_name']) ?></div>
                                <div style="font-size:0.75rem; color:var(--muted);">@<?= h($s['username']) ?></div>
                            </td>
                            <td style="color:var(--muted); font-size:0.83rem;"><?= h($s['class_name'] ?? '—') ?></td>
                            <td>
                                <span class="badge-count <?= $s['lab_count'] > 0 ? 'count-pos' : 'count-0' ?>">
                                    <?= $s['lab_count'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-count <?= $s['graded_count'] > 0 ? 'count-pos' : 'count-0' ?>">
                                    <?= $s['graded_count'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-count <?= $s['badge_count'] > 0 ? 'count-pos' : 'count-0' ?>">
                                    <?= $s['badge_count'] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($s['lab_count'] > 0 || $s['graded_count'] > 0 || $s['badge_count'] > 0): ?>
                                <form method="POST" onsubmit="return confirm('รีเซ็ต <?= h($s['display_name']) ?> ใช่หรือไม่?\n\nจะลบ: Lab Reports + เควส + XP + Badges')">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="reset">
                                    <input type="hidden" name="scope" value="student">
                                    <input type="hidden" name="target_id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="reset_labs" value="1">
                                    <input type="hidden" name="reset_xp" value="1">
                                    <input type="hidden" name="reset_badges" value="1">
                                    <button type="submit" class="btn-quick-reset">🔄 Reset</button>
                                </form>
                                <?php else: ?>
                                <span style="color:var(--muted); font-size:0.78rem;">ไม่มีข้อมูล</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── ลิงก์กลับ ── -->
    <div style="margin-top:24px; text-align:center;">
        <a href="dashboard_dev.php" style="color:var(--muted); font-size:0.85rem; text-decoration:none;">← กลับ Dashboard Dev</a>
        &nbsp;&nbsp;
        <a href="dev_ops_hub.php" style="color:var(--muted); font-size:0.85rem; text-decoration:none;">⚙️ Ops Hub</a>
    </div>

</div><!-- end page-wrap -->

<script>
// ── Scope switch ──────────────────────────────────────────────────────────
function setScope(s) {
    document.getElementById('scopeInput').value = s;
    document.querySelectorAll('.scope-tab').forEach(t => t.classList.toggle('active', t.dataset.scope === s));
    document.querySelectorAll('.scope-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + s).classList.add('active');
    // reset hidden inputs
    document.getElementById('targetIdInput').value = '0';
    document.getElementById('classIdInput').value  = '0';
    const box = document.getElementById('summaryBox');
    box.style.display = 'none';
    if (s === 'all') {
        box.style.display = 'block';
        document.getElementById('summaryContent').innerHTML =
            '👥 นักเรียนทั้งหมด <strong>' + <?= count($students) ?> + '</strong> คน<br>' +
            '⚠️ ข้อมูลทั้งหมดจะถูกรีเซ็ต';
    }
    updateConfirm();
}

// ── Update summary when student selected ────────────────────────────────
function updateSummary() {
    const scope = document.getElementById('scopeInput').value;
    const box   = document.getElementById('summaryBox');
    const cont  = document.getElementById('summaryContent');

    if (scope === 'student') {
        const sel = document.getElementById('studentSelect');
        const opt = sel.options[sel.selectedIndex];
        if (!opt || opt.value === '0') { box.style.display='none'; return; }
        box.style.display = 'block';
        cont.innerHTML =
            '📋 Lab Reports: <strong>' + opt.dataset.labs + '</strong> รายการ<br>' +
            '✅ เควสผ่าน: <strong>' + opt.dataset.graded + '</strong> รายการ<br>' +
            '🏅 Badges: <strong>' + opt.dataset.badges + '</strong> ชิ้น';
    } else if (scope === 'class') {
        const sel = document.getElementById('classSelect');
        if (!sel || sel.value === '0') { box.style.display='none'; return; }
        box.style.display = 'block';
        cont.innerHTML = '🏫 ห้องที่เลือก: <strong>' + sel.options[sel.selectedIndex].text + '</strong>';
    }
    updateConfirm();
}

// ── Show/hide confirm section ────────────────────────────────────────────
function updateConfirm() {
    const anyChecked = document.getElementById('cbLabs').checked ||
                       document.getElementById('cbXP').checked   ||
                       document.getElementById('cbBadges').checked;
    const section = document.getElementById('confirmSection');
    section.style.display = anyChecked ? 'block' : 'none';
    if (!anyChecked) {
        document.getElementById('confirmText').value = '';
        document.getElementById('btnReset').classList.remove('unlocked');
    }
}

// ── Unlock button on correct passphrase ─────────────────────────────────
function checkConfirm() {
    const val = document.getElementById('confirmText').value.trim();
    document.getElementById('btnReset').classList.toggle('unlocked', val === 'RESET');
}

// ── Student table search ──────────────────────────────────────────────────
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#studentTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

// ── Final submit guard ────────────────────────────────────────────────────
document.getElementById('resetForm').addEventListener('submit', function(e) {
    const scope = document.getElementById('scopeInput').value;
    if (scope === 'student' && document.getElementById('targetIdInput').value === '0') {
        e.preventDefault(); alert('กรุณาเลือกนักเรียนก่อน');
    }
    if (scope === 'class' && document.getElementById('classIdInput').value === '0') {
        e.preventDefault(); alert('กรุณาเลือกห้องเรียนก่อน');
    }
});
</script>

<?php require_once 'footer.php'; ?>
</body>
</html>
