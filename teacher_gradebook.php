<?php
/**
 * teacher_gradebook.php — สมุดบันทึกคะแนน (Gradebook ปพ.5)
 * ────────────────────────────────────────────────────────────
 * Features:
 *   - Sticky Header + Sticky First Column (ไม่หลงบรรทัด)
 *   - Auto-save AJAX เมื่อพิมพ์คะแนนเสร็จ
 *   - Validate ไม่เกิน max_score ทั้ง client และ server
 *   - เพิ่มช่องคะแนน manual (สอบ, จิตพิสัย ฯลฯ)
 * ────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';

requireRole(['teacher', 'developer']);

$page_title  = 'สมุดบันทึกคะแนน (Gradebook)';
$teacher_id  = $_SESSION['user_id'];
$is_dev      = ($_SESSION['role'] === 'developer');
$csrf        = generate_csrf_token();

// ── AJAX Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['ajax_action'];

    // ── save_score: บันทึกคะแนน ──────────────────────────────
    if ($action === 'save_score') {
        $column_id  = (int)($_POST['column_id']  ?? 0);
        $student_id = (int)($_POST['student_id'] ?? 0);
        $score_raw  = $_POST['score'] ?? '';

        if (!$column_id || !$student_id) {
            echo json_encode(['ok' => false, 'msg' => 'พารามิเตอร์ไม่ครบ']);
            exit;
        }

        // ตรวจสิทธิ์เจ้าของ column
        $stmt = $pdo->prepare('SELECT max_score, created_by FROM gradebook_columns WHERE id = ?');
        $stmt->execute([$column_id]);
        $col = $stmt->fetch();

        if (!$col) {
            echo json_encode(['ok' => false, 'msg' => 'ไม่พบหัวข้อคะแนน']);
            exit;
        }
        if (!$is_dev && $col['created_by'] != $teacher_id) {
            echo json_encode(['ok' => false, 'msg' => 'ไม่มีสิทธิ์แก้ไข']);
            exit;
        }

        // score เป็น null ได้ (ลบคะแนน)
        $score = ($score_raw === '' || $score_raw === null) ? null : (float)$score_raw;

        if ($score !== null) {
            if ($score < 0 || $score > (float)$col['max_score']) {
                echo json_encode(['ok' => false, 'msg' => "คะแนนต้องอยู่ระหว่าง 0–{$col['max_score']}"]);
                exit;
            }
        }

        try {
            $pdo->prepare('
                INSERT INTO gradebook_scores (column_id, student_id, score)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()
            ')->execute([$column_id, $student_id, $score]);

            echo json_encode(['ok' => true, 'msg' => 'บันทึกแล้ว', 'score' => $score]);
        } catch (PDOException $e) {
            error_log('[gradebook] save_score: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'เกิดข้อผิดพลาดในการบันทึก']);
        }
        exit;
    }

    // ── add_column: เพิ่มช่องคะแนนใหม่ ─────────────────────
    if ($action === 'add_column') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $class_id   = (int)($_POST['class_id']   ?? 0);
        $title      = trim($_POST['title']        ?? '');
        $max_score  = (float)($_POST['max_score'] ?? 100);

        if (!$subject_id || !$class_id || !$title) {
            echo json_encode(['ok' => false, 'msg' => 'กรุณากรอกข้อมูลให้ครบ']);
            exit;
        }
        if ($max_score <= 0 || $max_score > 999) {
            echo json_encode(['ok' => false, 'msg' => 'คะแนนเต็มต้องอยู่ระหว่าง 1–999']);
            exit;
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO gradebook_columns
                    (subject_id, class_id, title, max_score, source, created_by)
                VALUES (?, ?, ?, ?, "manual", ?)
            ');
            $stmt->execute([$subject_id, $class_id, $title, $max_score, $teacher_id]);
            $new_id = $pdo->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $new_id, 'title' => $title, 'max_score' => $max_score]);
        } catch (PDOException $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate') ? 'ชื่อหัวข้อนี้มีอยู่แล้วในวิชา+ห้องนี้' : 'เกิดข้อผิดพลาด';
            echo json_encode(['ok' => false, 'msg' => $msg]);
        }
        exit;
    }

    // ── delete_column: ลบช่องคะแนน ──────────────────────────
    if ($action === 'delete_column') {
        $col_id = (int)($_POST['column_id'] ?? 0);
        $stmt   = $pdo->prepare('SELECT created_by, source FROM gradebook_columns WHERE id = ?');
        $stmt->execute([$col_id]);
        $col = $stmt->fetch();

        if (!$col) { echo json_encode(['ok' => false, 'msg' => 'ไม่พบ']); exit; }
        if (!$is_dev && $col['created_by'] != $teacher_id) {
            echo json_encode(['ok' => false, 'msg' => 'ไม่มีสิทธิ์ลบ']); exit;
        }
        if ($col['source'] === 'assignment') {
            echo json_encode(['ok' => false, 'msg' => 'ลบไม่ได้ — ช่องนี้ผูกกับชิ้นงาน (ลบผ่านหน้าจัดการงาน)']);
            exit;
        }

        $pdo->prepare('DELETE FROM gradebook_columns WHERE id = ?')->execute([$col_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'action ไม่รู้จัก']);
    exit;
}

// ── โหลด Dropdown วิชา + ห้องเรียน ──────────────────────────
$my_subjects = [];
$my_classes  = [];
try {
    if ($is_dev) {
        $my_subjects = $pdo->query('SELECT id, subject_code, subject_name FROM subjects WHERE is_active = 1 ORDER BY subject_name')->fetchAll();
        $my_classes  = $pdo->query('SELECT id, class_name FROM classes WHERE is_active = 1 ORDER BY class_name')->fetchAll();
    } else {
        // ครูเห็นเฉพาะวิชาที่ตัวเองสอน
        $stmt = $pdo->prepare('
            SELECT DISTINCT s.id, s.subject_code, s.subject_name
            FROM class_schedules cs
            JOIN subjects s ON s.id = cs.subject_id
            WHERE cs.teacher_id = ? AND s.is_active = 1
            ORDER BY s.subject_name
        ');
        $stmt->execute([$teacher_id]);
        $my_subjects = $stmt->fetchAll();

        $stmt = $pdo->prepare('
            SELECT DISTINCT c.id, c.class_name
            FROM class_schedules cs
            JOIN classes c ON c.id = cs.class_id
            WHERE cs.teacher_id = ? AND c.is_active = 1
            ORDER BY c.class_name
        ');
        $stmt->execute([$teacher_id]);
        $my_classes = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log('[gradebook] dropdown: ' . $e->getMessage());
}

// ── โหลด Gradebook ถ้าเลือก subject+class แล้ว ───────────────
$sel_subject_id = (int)($_GET['subject_id'] ?? 0);
$sel_class_id   = (int)($_GET['class_id']   ?? 0);
$columns        = [];
$students       = [];
$scores_map     = [];

if ($sel_subject_id && $sel_class_id) {
    try {
        // หัวข้อคะแนน
        $stmt = $pdo->prepare('
            SELECT gc.*, u.display_name AS creator_name
            FROM gradebook_columns gc
            JOIN users u ON u.id = gc.created_by
            WHERE gc.subject_id = ? AND gc.class_id = ?
            ORDER BY gc.col_order ASC, gc.created_at ASC
        ');
        $stmt->execute([$sel_subject_id, $sel_class_id]);
        $columns = $stmt->fetchAll();

        // นักเรียนในห้อง
        $stmt = $pdo->prepare('
            SELECT u.id, u.display_name, u.username
            FROM users u
            JOIN sys_roles r ON r.id = u.role_id
            WHERE u.class_id = ? AND r.role_key = "student" AND u.is_deleted = 0
            ORDER BY u.display_name ASC
        ');
        $stmt->execute([$sel_class_id]);
        $students = $stmt->fetchAll();

        // คะแนนทั้งหมดในห้องนี้ → map[column_id][student_id] = score
        if ($columns && $students) {
            $col_ids = array_column($columns, 'id');
            $stu_ids = array_column($students, 'id');
            $in_cols = implode(',', array_fill(0, count($col_ids), '?'));
            $in_stus = implode(',', array_fill(0, count($stu_ids), '?'));

            $stmt = $pdo->prepare("
                SELECT column_id, student_id, score, note
                FROM gradebook_scores
                WHERE column_id IN ($in_cols) AND student_id IN ($in_stus)
            ");
            $stmt->execute(array_merge($col_ids, $stu_ids));
            foreach ($stmt->fetchAll() as $row) {
                $scores_map[$row['column_id']][$row['student_id']] = $row;
            }
        }

    } catch (PDOException $e) {
        error_log('[gradebook] load: ' . $e->getMessage());
    }
}

require_once 'header.php';
?>

<style>
/* ── Gradebook Layout ──────────────────────────────────────── */
.gb-wrap {
    padding: 20px;
    max-width: 100%;
}
.gb-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
    margin-bottom: 20px;
    background: #fff;
    padding: 16px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.gb-toolbar select, .gb-toolbar button {
    padding: 10px 14px;
    border-radius: 7px;
    border: 1px solid #cbd5e1;
    font-family: 'Prompt', sans-serif;
    font-size: .95rem;
    cursor: pointer;
}
.gb-toolbar button {
    background: #1e3a8a;
    color: #fff;
    border: none;
}
.btn-add-col {
    background: #10b981 !important;
}

/* ── Sticky Table ──────────────────────────────────────────── */
.gb-table-container {
    overflow: auto;
    max-height: 72vh;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgba(0,0,0,.08);
}
.gb-table {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    background: #fff;
}
/* Sticky header row */
.gb-table thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: #1e3a8a;
    color: #fff;
    padding: 10px 14px;
    font-weight: 500;
    font-size: .88rem;
    text-align: center;
    white-space: nowrap;
    border-right: 1px solid rgba(255,255,255,.15);
}
/* Sticky first column (ชื่อนักเรียน) */
.gb-table thead th:first-child,
.gb-table tbody td:first-child {
    position: sticky;
    left: 0;
    z-index: 4;
}
.gb-table thead th:first-child {
    z-index: 5;
    min-width: 160px;
    text-align: left;
}
.gb-table tbody td:first-child {
    background: #f8fafc;
    font-weight: 500;
    color: #1e3a8a;
    padding: 8px 14px;
    border-right: 2px solid #cbd5e1;
    white-space: nowrap;
    z-index: 2;
}
.gb-table tbody tr:nth-child(even) td:first-child {
    background: #f1f5f9;
}

/* Score cell */
.gb-table tbody td {
    padding: 5px 8px;
    text-align: center;
    border-bottom: 1px solid #f1f5f9;
    border-right: 1px solid #f1f5f9;
}
.gb-table tbody tr:nth-child(even) td {
    background: #f8fafc;
}

/* Score input */
.score-input {
    width: 70px;
    padding: 6px !important;
    text-align: center;
    border: 1px solid #cbd5e1;
    border-radius: 5px;
    font-family: 'Prompt', sans-serif;
    font-size: .9rem !important;
    transition: border-color .2s;
}
.score-input:focus {
    outline: none;
    border-color: #1e3a8a;
    box-shadow: 0 0 0 3px rgba(30,58,138,.1);
}
.score-input.saving  { border-color: #f59e0b; background: #fffbeb; }
.score-input.saved   { border-color: #10b981; background: #ecfdf5; }
.score-input.error   { border-color: #ef4444; background: #fef2f2; }
.score-input.over    { border-color: #ef4444; color: #ef4444; }

/* Column header actions */
.col-header { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.col-max    { font-size: .75rem; opacity: .75; }
.col-del    { font-size: .7rem; background: rgba(255,255,255,.2); border: none;
              color: #fff; padding: 1px 6px; border-radius: 4px; cursor: pointer;
              margin-top: 2px; }
.col-del:hover { background: #ef4444; }

/* Total column */
.td-total { font-weight: 600; color: #1e3a8a; background: #eff6ff !important; }

/* Empty state */
.gb-empty {
    text-align: center;
    padding: 60px 20px;
    color: #94a3b8;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}

/* Modal */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 100;
    align-items: center;
    justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 10px 40px rgba(0,0,0,.2);
}
.modal-box h3 { margin: 0 0 18px; color: #1e3a8a; }
.modal-box label { display: block; margin-bottom: 6px; font-weight: 500; font-size: .9rem; }
.modal-box input {
    width: 100%;
    box-sizing: border-box;
    margin-bottom: 14px;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-family: 'Prompt', sans-serif;
    font-size: 1rem;
}
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.modal-actions button {
    padding: 10px 20px;
    border-radius: 7px;
    border: none;
    font-family: 'Prompt', sans-serif;
    cursor: pointer;
    font-size: .95rem;
}
.btn-confirm { background: #10b981; color: #fff; }
.btn-cancel  { background: #f1f5f9; color: #475569; }

/* Toast */
#toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #1e293b;
    color: #fff;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: .9rem;
    opacity: 0;
    transition: opacity .3s;
    z-index: 999;
    pointer-events: none;
}
#toast.show { opacity: 1; }
</style>

<div class="gb-wrap">
    <h2 style="margin:0 0 16px;color:#1e3a8a;">📊 สมุดบันทึกคะแนน (Gradebook)</h2>

    <!-- ── Toolbar ───────────────────────────────────────── -->
    <form method="GET" class="gb-toolbar">
        <div>
            <label style="font-size:.85rem;color:#64748b;display:block;margin-bottom:4px;">วิชา</label>
            <select name="subject_id" onchange="this.form.submit()">
                <option value="">-- เลือกวิชา --</option>
                <?php foreach ($my_subjects as $sub): ?>
                    <option value="<?= $sub['id'] ?>"
                        <?= $sel_subject_id == $sub['id'] ? 'selected' : '' ?>>
                        <?= h($sub['subject_code']) ?> - <?= h($sub['subject_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.85rem;color:#64748b;display:block;margin-bottom:4px;">ห้องเรียน</label>
            <select name="class_id" onchange="this.form.submit()">
                <option value="">-- เลือกห้อง --</option>
                <?php foreach ($my_classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>"
                        <?= $sel_class_id == $cls['id'] ? 'selected' : '' ?>>
                        <?= h($cls['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($sel_subject_id && $sel_class_id): ?>
        <button type="button" class="btn-add-col"
                onclick="openAddColModal()">+ เพิ่มช่องคะแนน</button>
        <?php endif; ?>
    </form>

    <?php if (!$sel_subject_id || !$sel_class_id): ?>
        <div class="gb-empty">
            <div style="font-size:2.5rem;margin-bottom:12px;">📋</div>
            <p>กรุณาเลือก <strong>วิชา</strong> และ <strong>ห้องเรียน</strong> เพื่อแสดงสมุดคะแนน</p>
        </div>

    <?php elseif (empty($students)): ?>
        <div class="gb-empty">
            <div style="font-size:2.5rem;margin-bottom:12px;">👤</div>
            <p>ไม่พบนักเรียนในห้องเรียนนี้</p>
        </div>

    <?php else: ?>
        <!-- ── Gradebook Table ───────────────────────────── -->
        <div class="gb-table-container">
            <table class="gb-table" id="gb-table">
                <thead>
                    <tr>
                        <th>ชื่อ-นามสกุล</th>
                        <?php foreach ($columns as $col): ?>
                        <th>
                            <div class="col-header">
                                <span><?= h($col['title']) ?></span>
                                <span class="col-max">/ <?= $col['max_score'] ?></span>
                                <?php if ($col['source'] === 'manual' && ($is_dev || $col['created_by'] == $teacher_id)): ?>
                                <button class="col-del"
                                        onclick="deleteColumn(<?= $col['id'] ?>, '<?= h(addslashes($col['title'])) ?>')">
                                    ✕ ลบ
                                </button>
                                <?php endif; ?>
                            </div>
                        </th>
                        <?php endforeach; ?>
                        <?php if ($columns): ?>
                        <th>รวม</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $stu):
                        $total_score = 0;
                        $total_max   = 0;
                    ?>
                    <tr>
                        <td><?= h($stu['display_name']) ?></td>
                        <?php foreach ($columns as $col):
                            $entry = $scores_map[$col['id']][$stu['id']] ?? null;
                            $score_val = $entry['score'] ?? '';
                            $total_max += (float)$col['max_score'];
                            if ($score_val !== '') $total_score += (float)$score_val;
                        ?>
                        <td>
                            <input type="number"
                                   class="score-input"
                                   data-column="<?= $col['id'] ?>"
                                   data-student="<?= $stu['id'] ?>"
                                   data-max="<?= $col['max_score'] ?>"
                                   value="<?= $score_val !== '' ? $score_val : '' ?>"
                                   min="0" max="<?= $col['max_score'] ?>"
                                   step="0.5"
                                   placeholder="–">
                        </td>
                        <?php endforeach; ?>
                        <?php if ($columns): ?>
                        <td class="td-total">
                            <?= $total_max > 0
                                ? number_format($total_score, 1) . ' / ' . number_format($total_max, 0)
                                : '–' ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="font-size:.82rem;color:#94a3b8;margin-top:8px;">
            💾 คะแนนบันทึกอัตโนมัติเมื่อพิมพ์เสร็จ
            &nbsp;|&nbsp; 🔒 ช่องที่มาจากชิ้นงานลบได้เฉพาะผ่านหน้าจัดการงาน
        </p>
    <?php endif; ?>
</div>

<!-- ── Modal เพิ่มช่องคะแนน ────────────────────────────────── -->
<div class="modal-overlay" id="add-col-modal">
    <div class="modal-box">
        <h3>➕ เพิ่มช่องคะแนนใหม่</h3>
        <label>ชื่อหัวข้อคะแนน</label>
        <input type="text" id="new-col-title" placeholder="เช่น สอบกลางภาค, จิตพิสัย">
        <label>คะแนนเต็ม</label>
        <input type="number" id="new-col-max" value="100" min="1" max="999">
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeAddColModal()">ยกเลิก</button>
            <button class="btn-confirm" onclick="submitAddCol()">เพิ่ม</button>
        </div>
    </div>
</div>

<!-- ── Toast ─────────────────────────────────────────────────── -->
<div id="toast"></div>

<script>
const CSRF   = <?= json_encode($csrf) ?>;
const SUB_ID = <?= $sel_subject_id ?: 0 ?>;
const CLS_ID = <?= $sel_class_id   ?: 0 ?>;

// ── Auto-save คะแนน ──────────────────────────────────────────
let saveTimer = {};
document.querySelectorAll('.score-input').forEach(inp => {
    inp.addEventListener('input', () => {
        const max = parseFloat(inp.dataset.max);
        const val = parseFloat(inp.value);
        inp.classList.toggle('over', !isNaN(val) && val > max);
    });

    inp.addEventListener('change', () => {
        const col = inp.dataset.column;
        const stu = inp.dataset.student;
        const max = parseFloat(inp.dataset.max);
        const val = inp.value.trim();

        if (val !== '' && (parseFloat(val) < 0 || parseFloat(val) > max)) {
            inp.classList.add('error');
            showToast(`❌ คะแนนต้องอยู่ระหว่าง 0–${max}`, 'error');
            return;
        }

        clearTimeout(saveTimer[`${col}_${stu}`]);
        inp.classList.add('saving');
        inp.classList.remove('saved', 'error', 'over');

        saveTimer[`${col}_${stu}`] = setTimeout(() => saveScore(inp, col, stu, val), 600);
    });
});

async function saveScore(inp, col, stu, val) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_score');
        fd.append('csrf_token',  CSRF);
        fd.append('column_id',   col);
        fd.append('student_id',  stu);
        fd.append('score',       val);

        const res  = await fetch('teacher_gradebook.php', { method: 'POST', body: fd });
        const data = await res.json();

        inp.classList.remove('saving');
        if (data.ok) {
            inp.classList.add('saved');
            setTimeout(() => inp.classList.remove('saved'), 2000);
            showToast('💾 บันทึกแล้ว');
            updateRowTotal(inp);
        } else {
            inp.classList.add('error');
            showToast('❌ ' + data.msg, 'error');
        }
    } catch {
        inp.classList.add('error');
        showToast('❌ ไม่สามารถเชื่อมต่อได้', 'error');
    }
}

// ── คำนวณ Total แบบ real-time ─────────────────────────────────
function updateRowTotal(inp) {
    const row    = inp.closest('tr');
    const inputs = row.querySelectorAll('.score-input');
    const tdTot  = row.querySelector('.td-total');
    if (!tdTot) return;

    let total = 0, maxTotal = 0;
    inputs.forEach(i => {
        maxTotal += parseFloat(i.dataset.max) || 0;
        const v   = parseFloat(i.value);
        if (!isNaN(v)) total += v;
    });
    tdTot.textContent = `${total.toFixed(1)} / ${maxTotal.toFixed(0)}`;
}

// ── Modal เพิ่มช่อง ───────────────────────────────────────────
function openAddColModal()  { document.getElementById('add-col-modal').classList.add('open'); }
function closeAddColModal() { document.getElementById('add-col-modal').classList.remove('open'); }

async function submitAddCol() {
    const title = document.getElementById('new-col-title').value.trim();
    const max   = parseFloat(document.getElementById('new-col-max').value);

    if (!title) { showToast('❌ กรุณากรอกชื่อหัวข้อ', 'error'); return; }
    if (!max || max <= 0) { showToast('❌ คะแนนเต็มต้องมากกว่า 0', 'error'); return; }

    const fd = new FormData();
    fd.append('ajax_action', 'add_column');
    fd.append('csrf_token',  CSRF);
    fd.append('subject_id',  SUB_ID);
    fd.append('class_id',    CLS_ID);
    fd.append('title',       title);
    fd.append('max_score',   max);

    try {
        const res  = await fetch('teacher_gradebook.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast('✅ เพิ่มช่องคะแนนแล้ว — รีเฟรชหน้า');
            closeAddColModal();
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('❌ ' + data.msg, 'error');
        }
    } catch {
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

// ── ลบช่องคะแนน ──────────────────────────────────────────────
async function deleteColumn(colId, title) {
    if (!confirm(`ลบช่อง "${title}" และคะแนนทั้งหมดออก?\n(ไม่สามารถกู้คืนได้)`)) return;

    const fd = new FormData();
    fd.append('ajax_action', 'delete_column');
    fd.append('csrf_token',  CSRF);
    fd.append('column_id',   colId);

    try {
        const res  = await fetch('teacher_gradebook.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            showToast('🗑️ ลบแล้ว');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('❌ ' + data.msg, 'error');
        }
    } catch {
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

// ── Toast ─────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = type === 'error' ? '#ef4444' : '#1e293b';
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
}

// ── ปิด modal เมื่อคลิกนอก ───────────────────────────────────
document.getElementById('add-col-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddColModal();
});
</script>

<?php require_once 'footer.php'; ?>
