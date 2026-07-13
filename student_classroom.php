<?php
// student_classroom.php - หน้ากระดานรายวิชาและการบ้านของนักเรียน (Phase 5: Modular Ready)
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'db.php';

requireRole(['student']);

$student_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$class_id = $_SESSION['class_id'] ?? 0;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if ($subject_id <= 0) {
    header("Location: dashboard_student.php");
    exit;
}

// ---------------------------------------------------------
// 1. ตรวจสอบสิทธิ์การเข้าถึงวิชา (Access Control)
// ---------------------------------------------------------
$has_access = false;
$subject_info = null;

try {
    $stmt_sub = $pdo->prepare("SELECT * FROM subjects WHERE id = ? AND is_active = 1");
    $stmt_sub->execute([$subject_id]);
    $subject_info = $stmt_sub->fetch(PDO::FETCH_ASSOC);

    if (!$subject_info) {
        header("Location: dashboard_student.php?error=not_found");
        exit;
    }

    if (false) { // removed hardcode stu001 check
        $has_access = true; // God Mode
    } else {
        $stmt_check = $pdo->prepare("SELECT id FROM class_schedules WHERE class_id = ? AND subject_id = ?");
        $stmt_check->execute([$class_id, $subject_id]);
        if ($stmt_check->fetch()) {
            $has_access = true;
        }
    }
} catch (PDOException $e) {
    error_log("Classroom Access Error: " . $e->getMessage());
}

if (!$has_access) {
    header("Location: dashboard_student.php?error=access_denied");
    exit;
}

$page_title = $subject_info['subject_name'] . " - Classroom";

// ---------------------------------------------------------
// 2. ดึงรายการการบ้านในวิชานี้ (Assignments)
// ---------------------------------------------------------
$assignments = [];
try {
    $sql = "
        SELECT a.*, 
               COALESCE(st.status_key, 'pending') AS status,
               sa.score, sa.submitted_at 
        FROM assignments a
        LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = ?
        LEFT JOIN sys_task_statuses st ON st.id = sa.status_id
        WHERE a.subject_id = ? 
        AND (a.target_class_id IS NULL OR a.target_class_id = ?) 
        AND a.is_active = 1
        ORDER BY a.created_at DESC
    ";
    $stmt_assign = $pdo->prepare($sql);
    $stmt_assign->execute([$student_id, $subject_id, $class_id]);
    $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Load Assignments Error: " . $e->getMessage());
}

require_once 'header.php';
?>
<style>
:root { --sc: <?= h($subject_info['color_theme'] ?? '#1e3a8a') ?>; }
*{box-sizing:border-box}
.sc-wrap{max-width:900px;margin:0 auto;padding:20px 20px 80px;font-family:'Prompt',sans-serif}
.btn-back{display:inline-flex;align-items:center;gap:8px;color:#64748b;text-decoration:none;
    font-weight:600;margin-bottom:18px;font-size:.88rem;transition:.2s}
.btn-back:hover{color:#0f172a;transform:translateX(-4px)}
/* Hero */
.sc-hero{border-radius:18px;padding:30px 32px;margin-bottom:24px;color:#fff;
    background:linear-gradient(135deg,var(--sc),color-mix(in srgb,var(--sc) 60%,#000));
    position:relative;overflow:hidden}
.sc-hero::after{content:attr(data-icon);position:absolute;right:16px;bottom:-10px;
    font-size:6rem;opacity:.15;transform:rotate(-10deg)}
.sc-hero-tag{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:10px}
.sc-hero h1{font-size:1.6rem;font-weight:700;margin:0 0 5px}
.sc-hero .sub{font-size:.88rem;opacity:.85;margin:0}
/* Stats */
.sc-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px}
.sc-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;text-align:center}
.sc-stat .sv{font-size:1.6rem;font-weight:700;color:var(--sc);line-height:1}
.sc-stat .sl{font-size:.72rem;color:#94a3b8;margin-top:3px}
/* Section */
.sc-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:20px 0 12px;display:flex;align-items:center;gap:8px}
.sc-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}
/* Assignment cards */
.sc-list{display:flex;flex-direction:column;gap:12px}
.sc-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 22px;
    transition:.2s;position:relative;overflow:hidden}
.sc-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--sc)}
.sc-card:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.sc-card.done::before{background:#10b981}
.sc-card.done{background:#f0fdf4}
.sc-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:8px}
.sc-card h3{font-size:.95rem;font-weight:600;color:#0f172a;margin:0 0 4px}
.sc-card .desc{font-size:.8rem;color:#64748b;line-height:1.5;margin:0}
.sc-badge{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:600;
    padding:3px 10px;border-radius:20px;white-space:nowrap;flex-shrink:0}
.sc-badge.pending{background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0}
.sc-badge.done{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.sc-badge.graded{background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd}
.sc-card-bot{display:flex;justify-content:space-between;align-items:center;
    border-top:1px solid #f1f5f9;padding-top:12px;margin-top:12px;gap:10px;flex-wrap:wrap}
.sc-meta{display:flex;gap:14px;font-size:.75rem;color:#94a3b8;flex-wrap:wrap}
.sc-play{display:inline-flex;align-items:center;gap:6px;background:var(--sc);
    color:#fff;text-decoration:none;padding:8px 20px;border-radius:9px;
    font-size:.85rem;font-weight:600;transition:.2s}
.sc-play:hover{opacity:.85;transform:translateY(-1px)}
.sc-play.done-btn{background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0}
.sc-play.done-btn:hover{background:#e2e8f0}
.sc-score{font-size:1rem;font-weight:700;color:var(--sc)}
/* Empty */
.sc-empty{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
    padding:52px 20px;text-align:center;color:#94a3b8}
.sc-empty .ei{font-size:3rem;margin-bottom:12px}
@media(max-width:600px){.sc-stats{grid-template-columns:1fr 1fr}
    .sc-wrap{padding:14px 14px 60px}}
</style>

<div class="sc-wrap">
  <a href="dashboard_student.php" class="btn-back">← กลับแดชบอร์ด</a>

  <!-- Hero -->
  <div class="sc-hero" data-icon="<?= h($subject_info['subject_icon'] ?? '📚') ?>">
    <div class="sc-hero-tag">📚 ห้องเรียนวิชา</div>
    <h1><?= h($subject_info['subject_name']) ?></h1>
    <p class="sub"><?= h($subject_info['subject_code'] ?? '') ?>
      <?php if (!empty($subject_info['description'])): ?>
      · <?= h($subject_info['description']) ?>
      <?php endif; ?></p>
  </div>

  <?php
    $total   = count($assignments);
    $done    = array_reduce($assignments, fn($c,$a) => $c + ($a['status']==='graded'?1:0), 0);
    $pending = $total - $done;
    $avg     = $done > 0 ? round(array_sum(array_column(array_filter($assignments, fn($a)=>$a['score']!==null),'score'))/$done,1) : 0;
  ?>
  <div class="sc-stats">
    <div class="sc-stat"><div class="sv"><?= $total ?></div><div class="sl">งานทั้งหมด</div></div>
    <div class="sc-stat"><div class="sv" style="color:<?= $pending>0?'#dc2626':'#10b981' ?>"><?= $pending ?></div><div class="sl">ยังไม่ส่ง</div></div>
    <div class="sc-stat"><div class="sv"><?= $avg>0?$avg:'—' ?></div><div class="sl">คะแนนเฉลี่ย</div></div>
  </div>

  <div class="sc-sec">รายการการบ้านและภารกิจ (<?= $total ?>)</div>

  <?php if (empty($assignments)): ?>
  <div class="sc-empty">
    <div class="ei">📭</div>
    <p>ยังไม่มีการบ้านในวิชานี้<br>คอยติดตามจากคุณครูนะครับ</p>
  </div>
  <?php else: ?>
  <div class="sc-list">
    <?php foreach ($assignments as $a):
      $is_done = $a['status'] === 'graded';
      $is_submitted = !empty($a['submitted_at']);
      $type_icon = match($a['assignment_type'] ?? 'lab') {
          'quiz'  => '❓',
          'game'  => '🎮',
          'lab'   => '🧪',
          default => '📝'
      };
      // หา link ที่ถูกต้อง
      $play_url = '#';
      if (!empty($a['assignment_type'])) {
          $play_url = match($a['assignment_type']) {
              'lab'  => 'lab_realistic.php?assignment_id=' . $a['id'],
              'game' => 'assign_game.php?assignment_id=' . $a['id'],
              'quiz' => 'assign_from_library.php?assignment_id=' . $a['id'],
              default => 'assign_from_library.php?assignment_id=' . $a['id'],
          };
      }
    ?>
    <div class="sc-card <?= $is_done ? 'done' : '' ?>">
      <div class="sc-card-top">
        <div>
          <h3><?= $type_icon ?> <?= h($a['title']) ?></h3>
          <?php if (!empty($a['description'])): ?>
          <p class="desc"><?= h(mb_substr($a['description'],0,100)) ?><?= mb_strlen($a['description'])>100?'...':'' ?></p>
          <?php endif; ?>
        </div>
        <?php if ($is_done): ?>
          <span class="sc-badge graded">✅ ตรวจแล้ว</span>
        <?php elseif ($is_submitted): ?>
          <span class="sc-badge pending">⏳ รอตรวจ</span>
        <?php else: ?>
          <span class="sc-badge pending">📥 รอส่ง</span>
        <?php endif; ?>
      </div>
      <div class="sc-card-bot">
        <div class="sc-meta">
          <?php if (!empty($a['max_score'])): ?>
          <span>🏆 เต็ม <?= $a['max_score'] ?> คะแนน</span>
          <?php endif; ?>
          <?php if ($a['submitted_at']): ?>
          <span>📬 ส่งเมื่อ <?= date('d/m/y H:i', strtotime($a['submitted_at'])) ?></span>
          <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <?php if ($is_done && $a['score'] !== null): ?>
          <span class="sc-score"><?= $a['score'] ?> คะแนน</span>
          <?php endif; ?>
          <a href="<?= h($play_url) ?>" class="sc-play <?= $is_done?'done-btn':'' ?>">
            <?= $is_done ? '👁 ดูผล' : ($is_submitted ? '👁 ดูงาน' : '▶ เริ่มทำ') ?>
          </a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
