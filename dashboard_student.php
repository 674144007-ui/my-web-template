<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
requireRole(['student','developer']);

$page_title   = 'แดชบอร์ดนักเรียน';
$student_id   = $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? 'นักเรียน';
$school_name  = $tenant['school_name'] ?? 'โรงเรียน';
$brand        = $tenant['primary_color'] ?? '#0f766e';
$logo_path    = (!empty($tenant['logo_path']) && file_exists(__DIR__.'/'.$tenant['logo_path']))
                ? $tenant['logo_path'] : null;

$pending = 0; $done = 0; $avg = 0; $recent = [];
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM student_assignments sa JOIN sys_task_statuses st ON st.id=sa.status_id WHERE sa.student_id=? AND st.status_key='pending'");
    $s->execute([$student_id]); $pending = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*), COALESCE(AVG(sa.score),0) FROM student_assignments sa JOIN sys_task_statuses st ON st.id=sa.status_id WHERE sa.student_id=? AND st.status_key='graded'");
    $s->execute([$student_id]); $r = $s->fetch(PDO::FETCH_NUM);
    $done = (int)$r[0]; $avg = round((float)$r[1],1);
    $total = $pending + $done;
    $pct   = $total > 0 ? round($done/$total*100) : 0;
    $s = $pdo->prepare("SELECT a.title, sa.score, sa.submitted_at, COALESCE(st.status_key,'pending') AS status FROM student_assignments sa JOIN assignments a ON a.id=sa.assignment_id JOIN sys_task_statuses st ON st.id=sa.status_id WHERE sa.student_id=? ORDER BY sa.submitted_at DESC LIMIT 5");
    $s->execute([$student_id]); $recent = $s->fetchAll();
} catch (PDOException $e) { error_log('[student] '.$e->getMessage()); }

require_once 'header.php';
?>
<style>
.sw{max-width:960px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}

/* Hero */
.s-hero{border-radius:18px;padding:28px 32px;margin-bottom:20px;color:#fff;
    background:<?= h($brand) ?>;display:flex;align-items:center;
    justify-content:space-between;flex-wrap:wrap;gap:14px;position:relative;overflow:hidden}
.s-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;
    border-radius:50%;background:rgba(255,255,255,.08);pointer-events:none}
.s-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:10px}
.s-hero h1{font-size:1.55rem;font-weight:700;margin:0 0 4px}
.s-hero .sub{font-size:.83rem;opacity:.85;margin:0}
.s-logo{width:66px;height:66px;border-radius:13px;object-fit:contain;
    background:rgba(255,255,255,.9);padding:5px;box-shadow:0 4px 12px rgba(0,0,0,.2);
    flex-shrink:0;z-index:1}
.s-logo-e{width:66px;height:66px;border-radius:13px;background:rgba(255,255,255,.15);
    display:flex;align-items:center;justify-content:center;font-size:1.8rem;
    border:1px solid rgba(255,255,255,.2);flex-shrink:0;z-index:1}

/* Stats row */
.s-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;margin-bottom:20px}
.s-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:18px 20px;transition:.2s}
.s-stat:hover{box-shadow:0 6px 20px rgba(0,0,0,.06);transform:translateY(-2px)}
.s-stat .sl{font-size:.72rem;color:#64748b;margin-bottom:7px;display:flex;gap:5px;align-items:center}
.s-stat .sv{font-size:1.85rem;font-weight:700;color:#0f172a;line-height:1}
.s-stat.warn .sv{color:#dc2626}
.s-stat .sd{font-size:.7rem;color:#94a3b8;margin-top:3px}

/* Progress */
.s-prog{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
    padding:18px 22px;margin-bottom:20px}
.s-prog-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.s-prog-head span:first-child{font-size:.88rem;font-weight:600;color:#0f172a}
.s-prog-head span:last-child{font-size:1.1rem;font-weight:700;color:<?= h($brand) ?>}
.s-bar{height:10px;background:#f1f5f9;border-radius:10px;overflow:hidden}
.s-bar-f{height:100%;border-radius:10px;background:<?= h($brand) ?>;transition:width .6s ease}
.s-bar-info{display:flex;justify-content:space-between;margin-top:6px;font-size:.7rem;color:#94a3b8}

/* Section */
.s-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:22px 0 13px;display:flex;align-items:center;gap:8px}
.s-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* Actions */
.s-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.s-act{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:20px 16px;
    text-decoration:none;color:inherit;display:block;text-align:center;transition:.2s}
.s-act:hover{border-color:<?= h($brand) ?>;box-shadow:0 8px 20px rgba(0,0,0,.07);transform:translateY(-2px)}
.s-act .ai{font-size:2rem;margin-bottom:8px}
.s-act h3{font-size:.85rem;font-weight:600;color:#0f172a;margin:0 0 3px}
.s-act p{font-size:.72rem;color:#64748b;margin:0;line-height:1.4}

/* Recent */
.s-recent{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden}
.s-recent-h{padding:13px 20px;border-bottom:1px solid #f1f5f9;font-size:.82rem;font-weight:600;color:#0f172a}
.s-ri{display:flex;align-items:center;gap:12px;padding:11px 20px;border-bottom:1px solid #f9fafb;transition:.12s}
.s-ri:last-child{border-bottom:none}
.s-ri:hover{background:#fafafa}
.s-ri-i{width:34px;height:34px;border-radius:9px;background:#f1f5f9;
    display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.s-ri-t{flex:1;font-size:.83rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.s-ri-s{font-size:.8rem;font-weight:600;color:<?= h($brand) ?>}
.s-ri-p{font-size:.7rem;background:#fef9c3;color:#854d0e;padding:2px 8px;
    border-radius:10px;border:1px solid #fde68a}
.s-ri-d{font-size:.68rem;color:#94a3b8;flex-shrink:0}

@media(max-width:640px){.s-stats{grid-template-columns:1fr 1fr}.s-grid{grid-template-columns:1fr 1fr}
    .sw{padding:16px 16px 60px}.s-hero{padding:20px}.s-hero h1{font-size:1.25rem}}
</style>

<div class="sw">
  <div class="s-hero">
    <div style="position:relative;z-index:1">
      <div class="s-tag">🎓 Student Dashboard</div>
      <h1>สวัสดี, <?= h($display_name) ?></h1>
      <p class="sub"><?= h($school_name) ?> · <?= date('j F Y') ?></p>
    </div>
    <?php if ($logo_path): ?>
      <img src="<?= h($logo_path) ?>" class="s-logo" alt="โลโก้">
    <?php else: ?>
      <div class="s-logo-e">🏫</div>
    <?php endif; ?>
  </div>

  <div class="s-stats">
    <div class="s-stat <?= $pending > 0 ? 'warn' : '' ?>">
      <div class="sl"><?= $pending > 0 ? '⏰' : '✅' ?> งานรอส่ง</div>
      <div class="sv"><?= $pending ?></div>
      <div class="sd"><?= $pending > 0 ? 'รายการที่ยังไม่ส่ง' : 'ส่งครบทุกชิ้นแล้ว' ?></div>
    </div>
    <div class="s-stat">
      <div class="sl">📬 ส่งแล้ว</div>
      <div class="sv"><?= $done ?></div>
      <div class="sd">รายการ</div>
    </div>
    <div class="s-stat">
      <div class="sl">⭐ คะแนนเฉลี่ย</div>
      <div class="sv" style="color:<?= h($brand) ?>"><?= $avg > 0 ? $avg : '—' ?></div>
      <div class="sd">คะแนน</div>
    </div>
  </div>

  <?php if (($pending + $done) > 0):
    $total = $pending + $done; $pct2 = round($done/$total*100); ?>
  <div class="s-prog">
    <div class="s-prog-head">
      <span>ความคืบหน้าการส่งงาน</span>
      <span><?= $pct2 ?>%</span>
    </div>
    <div class="s-bar"><div class="s-bar-f" style="width:<?= $pct2 ?>%"></div></div>
    <div class="s-bar-info">
      <span>ส่งแล้ว <?= $done ?>/<?= $total ?> รายการ</span>
      <span>ค้าง <?= $pending ?> รายการ</span>
    </div>
  </div>
  <?php endif; ?>

  <div class="s-sec">เมนูหลัก</div>
  <div class="s-grid">
    <a href="student_classroom.php" class="s-act"><div class="ai">📚</div><h3>ห้องเรียนของฉัน</h3><p>ดูการบ้านและภารกิจ</p></a>
    <a href="lab_realistic.php"     class="s-act"><div class="ai">🧪</div><h3>ห้องแล็บเคมี</h3><p>ทดลองเคมีเสมือนจริง</p></a>
    <a href="leaderboard.php"       class="s-act"><div class="ai">🏆</div><h3>อันดับ & คะแนน</h3><p>Leaderboard ห้องเรียน</p></a>
    <a href="student_reports.php"   class="s-act"><div class="ai">📈</div><h3>ผลการเรียน</h3><p>ดูคะแนนทั้งหมด</p></a>
  </div>

  <?php if ($recent): ?>
  <div class="s-sec">งานล่าสุด</div>
  <div class="s-recent">
    <div class="s-recent-h">📋 5 รายการล่าสุด</div>
    <?php foreach ($recent as $r): ?>
    <div class="s-ri">
      <div class="s-ri-i">📝</div>
      <div class="s-ri-t"><?= h($r['title']) ?></div>
      <?php if ($r['status'] === 'graded'): ?>
        <div class="s-ri-s"><?= $r['score'] !== null ? $r['score'].' คะแนน' : '—' ?></div>
      <?php else: ?>
        <span class="s-ri-p">รอตรวจ</span>
      <?php endif; ?>
      <div class="s-ri-d"><?= $r['submitted_at'] ? date('d/m/y', strtotime($r['submitted_at'])) : '' ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once 'footer.php'; ?>
