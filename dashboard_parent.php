<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
requireRole(['parent','developer']);

$page_title   = 'แดชบอร์ดผู้ปกครอง';
$parent_id    = $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? 'ผู้ปกครอง';
$school_name  = $tenant['school_name'] ?? 'โรงเรียน';
$brand        = $tenant['primary_color'] ?? '#1e3a8a';
$logo_path    = (!empty($tenant['logo_path']) && file_exists(__DIR__.'/'.$tenant['logo_path']))
                ? $tenant['logo_path'] : null;

$children = [];
try {
    $s = $pdo->prepare("SELECT u.id,u.display_name,u.username,c.class_name FROM parent_students ps JOIN users u ON u.id=ps.student_id LEFT JOIN classes c ON c.id=u.class_id WHERE ps.parent_id=? AND u.is_deleted=0");
    $s->execute([$parent_id]); $children = $s->fetchAll();
} catch (PDOException $e) { error_log('[parent] '.$e->getMessage()); }

$child_stats = [];
foreach ($children as $child) {
    $cid = $child['id'];
    try {
        $p = $pdo->prepare("SELECT COUNT(*) FROM student_assignments sa JOIN sys_task_statuses st ON st.id=sa.status_id WHERE sa.student_id=? AND st.status_key='pending'");
        $p->execute([$cid]); $pend = (int)$p->fetchColumn();
        $g = $pdo->prepare("SELECT COUNT(*),COALESCE(AVG(sa.score),0) FROM student_assignments sa JOIN sys_task_statuses st ON st.id=sa.status_id WHERE sa.student_id=? AND st.status_key='graded'");
        $g->execute([$cid]); $gr = $g->fetch(PDO::FETCH_NUM);
        $child_stats[$cid] = ['pending'=>$pend,'graded'=>(int)$gr[0],'avg'=>round((float)$gr[1],1)];
    } catch (PDOException $e) { $child_stats[$cid] = ['pending'=>0,'graded'=>0,'avg'=>0]; }
}

require_once 'header.php';
?>
<style>
.pw{max-width:900px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}

/* Hero */
.p-hero{border-radius:18px;padding:28px 32px;margin-bottom:20px;color:#fff;
    background:<?= h($brand) ?>;display:flex;align-items:center;
    justify-content:space-between;flex-wrap:wrap;gap:14px;position:relative;overflow:hidden}
.p-hero::after{content:'';position:absolute;right:-50px;top:-50px;width:220px;height:220px;
    border-radius:50%;background:rgba(255,255,255,.07);pointer-events:none}
.p-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:10px}
.p-hero h1{font-size:1.55rem;font-weight:700;margin:0 0 4px}
.p-hero .sub{font-size:.83rem;opacity:.85;margin:0}
.p-logo{width:66px;height:66px;border-radius:13px;object-fit:contain;
    background:rgba(255,255,255,.9);padding:5px;box-shadow:0 4px 12px rgba(0,0,0,.2);
    flex-shrink:0;z-index:1}
.p-logo-e{width:66px;height:66px;border-radius:13px;background:rgba(255,255,255,.15);
    display:flex;align-items:center;justify-content:center;font-size:1.8rem;
    border:1px solid rgba(255,255,255,.2);flex-shrink:0;z-index:1}

/* Section */
.p-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:22px 0 13px;display:flex;align-items:center;gap:8px}
.p-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* Child card */
.p-child{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;
    margin-bottom:14px;overflow:hidden;transition:.2s}
.p-child:hover{box-shadow:0 8px 24px rgba(0,0,0,.07)}
.p-child-head{padding:15px 22px;display:flex;align-items:center;gap:14px;border-bottom:1px solid #f1f5f9}
.p-av{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    color:#fff;font-weight:700;font-size:1.1rem;flex-shrink:0;background:<?= h($brand) ?>}
.p-ci h3{font-size:.93rem;font-weight:600;color:#0f172a;margin:0 0 2px}
.p-ci .cls{font-size:.76rem;color:#64748b}
.alert-chip{margin-left:auto;font-size:.7rem;background:#fef2f2;color:#b91c1c;
    border:1px solid #fecaca;padding:3px 10px;border-radius:20px;font-weight:600;white-space:nowrap}
.p-child-stats{display:grid;grid-template-columns:repeat(3,1fr)}
.p-cs{padding:14px 20px;text-align:center;border-right:1px solid #f1f5f9}
.p-cs:last-child{border-right:none}
.p-cs .csl{font-size:.68rem;color:#94a3b8;margin-bottom:4px}
.p-cs .csv{font-size:1.4rem;font-weight:700;color:#0f172a}
.p-cs.al .csv{color:#dc2626}
.p-cs.ok .csv{color:#059669}
.p-cs.br .csv{color:<?= h($brand) ?>}

/* Empty */
.p-empty{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;
    padding:52px 20px;text-align:center;color:#94a3b8}
.p-empty .ei{font-size:3.5rem;margin-bottom:12px}
.p-empty p{font-size:.88rem;line-height:1.6}

/* Note */
.p-note{background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;
    padding:13px 18px;font-size:.8rem;color:#78350f;margin-top:16px;line-height:1.6;
    display:flex;gap:10px;align-items:flex-start}

@media(max-width:640px){.p-child-stats{grid-template-columns:1fr 1fr}
    .pw{padding:16px 16px 60px}.p-hero{padding:20px}.p-hero h1{font-size:1.25rem}}
</style>

<div class="pw">
  <div class="p-hero">
    <div style="position:relative;z-index:1">
      <div class="p-tag">👨‍👩‍👧 Parent Dashboard</div>
      <h1>สวัสดี, <?= h($display_name) ?></h1>
      <p class="sub"><?= h($school_name) ?> · ติดตามผลการเรียนบุตรหลาน</p>
    </div>
    <?php if ($logo_path): ?>
      <img src="<?= h($logo_path) ?>" class="p-logo" alt="โลโก้">
    <?php else: ?>
      <div class="p-logo-e">🏫</div>
    <?php endif; ?>
  </div>

  <div class="p-sec">บุตรหลานในระบบ (<?= count($children) ?> คน)</div>

  <?php if (empty($children)): ?>
  <div class="p-empty">
    <div class="ei">👦</div>
    <p>ยังไม่มีข้อมูลบุตรหลานในระบบ<br>กรุณาติดต่อคุณครูเพื่อเชื่อมบัญชี</p>
  </div>
  <?php else: foreach ($children as $child):
    $cid = $child['id'];
    $st  = $child_stats[$cid] ?? ['pending'=>0,'graded'=>0,'avg'=>0];
    $init = mb_strtoupper(mb_substr($child['display_name'],0,2,'UTF-8')); ?>
  <div class="p-child">
    <div class="p-child-head">
      <div class="p-av"><?= $init ?></div>
      <div class="p-ci">
        <h3><?= h($child['display_name']) ?></h3>
        <div class="cls"><?= $child['class_name'] ? '🚪 '.h($child['class_name']) : '—' ?> · @<?= h($child['username']) ?></div>
      </div>
      <?php if ($st['pending'] > 0): ?>
        <span class="alert-chip">⏰ <?= $st['pending'] ?> งานค้าง</span>
      <?php endif; ?>
    </div>
    <div class="p-child-stats">
      <div class="p-cs <?= $st['pending'] > 0 ? 'al' : '' ?>">
        <div class="csl">งานรอส่ง</div>
        <div class="csv"><?= $st['pending'] ?></div>
      </div>
      <div class="p-cs ok">
        <div class="csl">ส่งแล้ว</div>
        <div class="csv"><?= $st['graded'] ?></div>
      </div>
      <div class="p-cs br">
        <div class="csl">คะแนนเฉลี่ย</div>
        <div class="csv"><?= $st['avg'] > 0 ? $st['avg'] : '—' ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <div class="p-note">
    <span>💡</span>
    <span>หากต้องการทราบรายละเอียดงานแต่ละชิ้น กรุณาติดต่อคุณครูประจำวิชาโดยตรง
    หรือให้บุตรหลานเข้าระบบเพื่อดูรายละเอียดการบ้าน</span>
  </div>
</div>
<?php require_once 'footer.php'; ?>
