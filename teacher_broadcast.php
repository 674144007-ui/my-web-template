<?php
/**
 * teacher_broadcast.php — ส่งคำแนะนำถึงนักเรียนระหว่างทดลอง (Phase 6)
 * รองรับ: In-Lab live hint + LINE Notify (ถ้ามี token)
 */
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

if (file_exists(__DIR__ . '/line_notify.php')) require_once 'line_notify.php';

requireRole(['teacher', 'developer']);

$page_title = 'ประกาศ & ส่งคำแนะนำ';
$csrf       = generate_csrf_token();
$teacher_id = (int)$_SESSION['user_id'];
$school_code = $_SESSION['school_code'] ?? 'BKW';
$msg = ''; $msg_type = '';

// ── POST: ส่ง in-lab hint ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'send_hint') {
        $target_uid = (int)($_POST['target_uid'] ?? 0);
        $hint_text  = trim($_POST['hint_text'] ?? '');
        if (!$hint_text || !$target_uid) {
            $msg = '❌ กรุณาระบุนักเรียนเป้าหมายและข้อความ';
            $msg_type = 'error';
        } else {
            // บันทึก hint ลงใน teacher_hints table (ถ้ามี) หรือ user_sessions.teacher_hint
            try {
                $pdo->prepare("
                    UPDATE user_sessions
                    SET teacher_hint = ?, teacher_hint_at = NOW()
                    WHERE user_id = ? AND school_code = ?
                ")->execute([htmlspecialchars($hint_text, ENT_QUOTES), $target_uid, $school_code]);
                $msg = '✅ ส่งคำแนะนำไปแล้ว นักเรียนจะเห็นใน 60 วิ';
                $msg_type = 'success';
            } catch (PDOException $e) {
                // column ยังไม่มี — fallback
                $msg = '⚠️ ส่งไม่สำเร็จ: กรุณา run migration เพิ่ม column teacher_hint ก่อน';
                $msg_type = 'warning';
            }
        }
    }

    elseif ($action === 'broadcast_line') {
        $message_text  = trim($_POST['message_text'] ?? '');
        $target_group  = trim($_POST['target_group'] ?? 'all');
        $line_token    = trim($_POST['line_token'] ?? '');

        if (!$message_text) {
            $msg = '❌ กรุณาพิมพ์ข้อความ'; $msg_type = 'error';
        } else {
            $final = "\n📢 ประกาศจากครูผู้สอน:\n{$message_text}\n\nส่งโดย: {$_SESSION['display_name']}";
            if ($line_token && function_exists('sendLineNotify')) {
                $ok = sendLineNotify($final, $line_token);
                $msg = $ok ? '✅ ส่ง LINE Notify สำเร็จ' : '❌ ส่ง LINE ไม่สำเร็จ ตรวจสอบ Token';
                $msg_type = $ok ? 'success' : 'error';
            } else {
                $msg = '⚠️ ไม่มี LINE Token หรือ line_notify.php ยังไม่ได้ตั้งค่า';
                $msg_type = 'warning';
            }
        }
    }
}

// ── ดึงนักเรียน Online ในห้อง lab ─────────────────────────────
$online_in_lab = [];
try {
    $stmt = $pdo->prepare("
        SELECT us.user_id, us.display_name, us.page_current,
               us.last_seen_at, c.class_name,
               us.teacher_hint, us.teacher_hint_at
        FROM user_sessions us
        JOIN users u        ON u.id = us.user_id
        LEFT JOIN classes c ON c.id = u.class_id
        WHERE us.school_code = ?
          AND us.role_key = 'student'
          AND us.last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ORDER BY c.class_name, us.display_name
    ");
    $stmt->execute([$school_code]);
    $online_in_lab = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[broadcast] ' . $e->getMessage());
}

$in_lab   = array_filter($online_in_lab, fn($s) => strpos($s['page_current'] ?? '', 'lab_realistic') !== false);
$not_lab  = array_filter($online_in_lab, fn($s) => strpos($s['page_current'] ?? '', 'lab_realistic') === false);

require_once 'header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root { --blue:#3b82f6; --green:#10b981; --amber:#f59e0b; --red:#ef4444; }
body { background:#f8fafc; font-family:'Sarabun',sans-serif; }
.wrap { max-width:1100px; margin:28px auto; padding:0 20px; }
.page-header { background:linear-gradient(135deg,var(--blue),#1d4ed8); color:white; padding:24px 28px; border-radius:14px; margin-bottom:24px; }
.page-header h1 { margin:0; font-size:1.7rem; }

.alert { padding:12px 18px; border-radius:10px; margin-bottom:18px; font-weight:600; }
.alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.alert-warning { background:#fef9c3; color:#854d0e; border:1px solid #fde047; }

.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }

.card { background:white; border-radius:12px; border:1px solid #e2e8f0; padding:22px; box-shadow:0 2px 6px rgba(0,0,0,0.04); }
.card h3 { margin:0 0 16px; font-size:1.1rem; color:#1e293b; display:flex; align-items:center; gap:8px; }

/* Online list */
.student-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid #f1f5f9; }
.student-row:last-child { border:none; }
.online-dot { width:9px; height:9px; border-radius:50%; background:var(--green); flex-shrink:0; animation:pulse 2s infinite; }
.in-lab-badge { background:#dcfce7; color:#166534; padding:2px 8px; border-radius:6px; font-size:0.78rem; font-weight:600; }
.s-name { font-weight:600; flex:1; }
.s-class { font-size:0.85rem; color:#64748b; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

/* Hint form */
.hint-form { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
.hint-form input, .hint-form select { padding:7px 12px; border:1px solid #cbd5e1; border-radius:8px; font-family:'Sarabun',sans-serif; font-size:0.9rem; }
.hint-form input { flex:1; min-width:200px; }
.btn { padding:8px 18px; border:none; border-radius:8px; cursor:pointer; font-family:'Sarabun',sans-serif; font-weight:600; font-size:0.9rem; transition:0.2s; }
.btn-blue  { background:var(--blue); color:white; }
.btn-green { background:var(--green); color:white; }
.btn-amber { background:var(--amber); color:white; }

/* LINE form */
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-weight:600; margin-bottom:6px; color:#475569; }
.form-group textarea, .form-group input, .form-group select { width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:10px; font-family:'Sarabun',sans-serif; font-size:0.95rem; box-sizing:border-box; }
.form-group textarea { resize:vertical; min-height:90px; }
.hint-box { background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; font-size:0.85rem; color:#78350f; margin-top:12px; }
</style>

<div class="wrap">
    <div class="page-header">
        <h1>📢 ประกาศ & ส่งคำแนะนำ</h1>
        <p style="margin:6px 0 0; opacity:0.85;">ส่งคำแนะนำถึงนักเรียนระหว่างทดลอง หรือประกาศผ่าน LINE</p>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?>"><?= $msg ?></div>
    <?php endif; ?>

    <div class="grid-2">

        <!-- Left: In-Lab Live Hint -->
        <div class="card">
            <h3>🔬 นักเรียนกำลังทดลองอยู่ (<?= count($in_lab) ?> คน)</h3>

            <?php if (empty($in_lab)): ?>
                <p style="color:#94a3b8;">ไม่มีนักเรียนอยู่ในห้อง Lab ขณะนี้</p>
            <?php else: ?>
                <?php foreach ($in_lab as $s): ?>
                <div class="student-row">
                    <div class="online-dot"></div>
                    <div style="flex:1;">
                        <div class="s-name">
                            <?= htmlspecialchars($s['display_name']) ?>
                            <span class="in-lab-badge">🔬 Lab</span>
                        </div>
                        <div class="s-class"><?= htmlspecialchars($s['class_name'] ?? 'ไม่ระบุ') ?>
                            · Last seen: <?= date('H:i', strtotime($s['last_seen_at'])) ?>
                        </div>
                        <?php if ($s['teacher_hint'] ?? ''): ?>
                            <div style="font-size:0.8rem;color:var(--amber);margin-top:2px;">
                                💬 ส่งแล้ว: <?= htmlspecialchars($s['teacher_hint']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Quick hint form -->
                    <form method="POST" style="display:flex; gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="send_hint">
                        <input type="hidden" name="target_uid" value="<?= (int)$s['user_id'] ?>">
                        <input type="text" name="hint_text" placeholder="พิมพ์คำแนะนำ..." style="width:180px;padding:5px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
                        <button type="submit" class="btn btn-blue" style="padding:5px 12px;font-size:0.85rem;">ส่ง</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($not_lab)): ?>
                <div style="margin-top:16px; padding-top:12px; border-top:1px solid #f1f5f9;">
                    <div style="font-size:0.85rem;color:#64748b;margin-bottom:8px;">📄 Online แต่ไม่ได้อยู่ใน Lab (<?= count($not_lab) ?> คน)</div>
                    <?php foreach ($not_lab as $s): ?>
                    <div style="font-size:0.85rem;padding:4px 0;color:#94a3b8;">
                        · <?= htmlspecialchars($s['display_name']) ?> — <?= htmlspecialchars(basename($s['page_current'] ?? '')) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top:16px;">
                <a href="teacher_review_lab.php" style="color:var(--blue);font-size:0.9rem;font-weight:600;">→ ดูรายงานทั้งหมด</a>
            </div>
        </div>

        <!-- Right: LINE Notify Broadcast -->
        <div>
            <div class="card" style="margin-bottom:20px;">
                <h3>📱 ส่งคำแนะนำทั่วไป (ในระบบ)</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="send_hint">
                    <div class="form-group">
                        <label>เลือกนักเรียน</label>
                        <select name="target_uid">
                            <option value="0">-- ส่งให้นักเรียนที่เลือก --</option>
                            <?php foreach ($online_in_lab as $s): ?>
                                <option value="<?= (int)$s['user_id'] ?>">
                                    <?= htmlspecialchars($s['display_name']) ?>
                                    (<?= htmlspecialchars($s['class_name'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>คำแนะนำ</label>
                        <textarea name="hint_text" placeholder="เช่น: ลองคนสารดูก่อนนะ หรือตรวจสอบปริมาณสาร..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-green">💬 ส่งคำแนะนำ</button>
                </form>
            </div>

            <div class="card">
                <h3>📲 LINE Notify Broadcast</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="broadcast_line">
                    <div class="form-group">
                        <label>LINE Token (จาก notify-bot.line.me)</label>
                        <input type="text" name="line_token" placeholder="ใส่ LINE Notify Token ที่นี่...">
                    </div>
                    <div class="form-group">
                        <label>ข้อความประกาศ</label>
                        <textarea name="message_text" placeholder="เช่น: กรุณาส่งงานภายใน 15 นาที..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-amber">📲 ส่ง LINE</button>
                </form>
                <div class="hint-box">
                    💡 ขอ LINE Notify Token ได้ที่ <a href="https://notify-bot.line.me/" target="_blank" style="color:var(--blue);">notify-bot.line.me</a> → My page → Generate token
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once 'footer.php'; ?>
