<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
requireRole(['teacher','developer']);

$page_title   = 'แดชบอร์ดครู';
$teacher_id   = $_SESSION['user_id'];
$display_name = $_SESSION['display_name'] ?? 'คุณครู';
$is_dev       = ($_SESSION['role'] === 'developer');
$school_name  = $tenant['school_name'] ?? 'โรงเรียน';
$brand        = $tenant['primary_color'] ?? '#1e3a8a';
$logo_path    = (!empty($tenant['logo_path']) && file_exists(__DIR__.'/'.$tenant['logo_path']))
                ? $tenant['logo_path'] : null;

$pending = 0; $total_students = 0; $total_assign = 0;
try {
    if ($is_dev) {
        $pending        = $pdo->query("SELECT COUNT(*) FROM student_assignments sa JOIN sys_task_statuses st ON st.id=sa.status_id WHERE st.status_key='pending'")->fetchColumn();
        $total_students = $pdo->query("SELECT COUNT(u.id) FROM users u JOIN sys_roles r ON r.id=u.role_id WHERE r.role_key='student' AND u.is_deleted=0")->fetchColumn();
        $total_assign   = $pdo->query("SELECT COUNT(id) FROM assignments WHERE is_active=1")->fetchColumn();
    } else {
        $s = $pdo->prepare("SELECT COUNT(*) FROM student_assignments sa JOIN assignments a ON a.id=sa.assignment_id JOIN sys_task_statuses st ON st.id=sa.status_id WHERE a.created_by=? AND st.status_key='pending'");
        $s->execute([$teacher_id]); $pending = $s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM class_schedules cs JOIN users u ON cs.class_id=u.class_id JOIN sys_roles r ON r.id=u.role_id WHERE cs.teacher_id=? AND r.role_key='student' AND u.is_deleted=0");
        $s->execute([$teacher_id]); $total_students = $s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(id) FROM assignments WHERE created_by=? AND is_active=1");
        $s->execute([$teacher_id]); $total_assign = $s->fetchColumn();
    }
} catch (PDOException $e) { error_log('[teacher] '.$e->getMessage()); }

require_once 'header.php';
?>
<style>
.tw{max-width:1100px;margin:0 auto;padding:24px 20px 80px;font-family:'Prompt',sans-serif}

/* Hero */
.t-hero{border-radius:18px;padding:30px 34px;margin-bottom:22px;position:relative;
    overflow:hidden;display:flex;align-items:center;justify-content:space-between;
    flex-wrap:wrap;gap:16px;
    background:<?= h($brand) ?>;color:#fff}
.t-hero::after{content:'';position:absolute;width:280px;height:280px;border-radius:50%;
    right:-60px;top:-60px;background:rgba(255,255,255,.07);pointer-events:none}
.t-hero::before{content:'';position:absolute;width:160px;height:160px;border-radius:50%;
    right:80px;bottom:-70px;background:rgba(255,255,255,.05);pointer-events:none}
.t-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
    letter-spacing:.08em;text-transform:uppercase;background:rgba(255,255,255,.15);
    border:1px solid rgba(255,255,255,.25);padding:3px 11px;border-radius:20px;margin-bottom:10px}
.t-hero h1{font-size:1.65rem;font-weight:700;margin:0 0 4px;line-height:1.2}
.t-hero .sub{font-size:.85rem;opacity:.85;margin:0}
.t-logo{width:70px;height:70px;border-radius:14px;object-fit:contain;
    background:rgba(255,255,255,.9);padding:6px;box-shadow:0 4px 14px rgba(0,0,0,.2);
    flex-shrink:0;z-index:1}
.t-logo-emoji{width:70px;height:70px;border-radius:14px;background:rgba(255,255,255,.15);
    display:flex;align-items:center;justify-content:center;font-size:2rem;
    border:1px solid rgba(255,255,255,.2);flex-shrink:0;z-index:1}

/* Stats */
.t-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}
.t-stat{background:#fff;border-radius:14px;padding:20px 22px;
    border:1.5px solid #e2e8f0;transition:.2s;position:relative;overflow:hidden}
.t-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:<?= h($brand) ?>}
.t-stat.alert-s::before{background:#ef4444}
.t-stat:hover{box-shadow:0 8px 24px rgba(0,0,0,.07);transform:translateY(-2px)}
.t-stat .sl{font-size:.74rem;color:#64748b;margin-bottom:8px;display:flex;align-items:center;gap:5px}
.t-stat .sv{font-size:2rem;font-weight:700;color:#0f172a;line-height:1}
.t-stat.alert-s .sv{color:#dc2626}
.t-stat .sd{font-size:.72rem;color:#94a3b8;margin-top:3px}

/* Section */
.t-sec{font-size:.7rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase;
    margin:26px 0 14px;display:flex;align-items:center;gap:10px}
.t-sec::after{content:'';flex:1;height:1px;background:#f1f5f9}

/* Action grid */
.t-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.t-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:22px;
    text-decoration:none;color:inherit;display:block;transition:.2s;position:relative}
.t-card:hover{border-color:<?= h($brand) ?>;box-shadow:0 10px 28px rgba(0,0,0,.07);transform:translateY(-3px)}
.t-card.priority{border-color:<?= h($brand) ?>;background:linear-gradient(135deg,rgba(30,58,138,.02),#fff)}
.t-card .ci{font-size:2rem;margin-bottom:10px}
.t-card h3{font-size:.92rem;font-weight:600;color:#0f172a;margin:0 0 5px}
.t-card p{font-size:.75rem;color:#64748b;margin:0;line-height:1.5}
.t-card .arr{position:absolute;right:18px;top:50%;transform:translateY(-50%);
    font-size:1rem;color:#cbd5e1;transition:.2s}
.t-card:hover .arr{color:<?= h($brand) ?>;transform:translateY(-50%) translateX(3px)}
.t-badge{display:inline-block;font-size:.7rem;background:#fef2f2;color:#b91c1c;
    border:1px solid #fecaca;padding:2px 8px;border-radius:20px;margin-top:6px;font-weight:600}

@media(max-width:640px){.t-stats{grid-template-columns:1fr 1fr}.t-grid{grid-template-columns:1fr}
    .tw{padding:16px 16px 60px}.t-hero{padding:20px 22px}.t-hero h1{font-size:1.3rem}}
</style>

<div class="tw">
  <div class="t-hero">
    <div style="position:relative;z-index:1">
      <div class="t-tag">👨‍🏫 <?= $is_dev ? 'DEVELOPER · SUPER TEACHER' : 'TEACHER DASHBOARD' ?></div>
      <h1>สวัสดี, <?= h($display_name) ?></h1>
      <p class="sub"><?= h($school_name) ?> · <?= date('j F Y') ?></p>
    </div>
    <?php if ($logo_path): ?>
      <img src="<?= h($logo_path) ?>" class="t-logo" alt="โลโก้">
    <?php else: ?>
      <div class="t-logo-emoji">🏫</div>
    <?php endif; ?>
  </div>

  <div class="t-stats">
    <div class="t-stat <?= $pending > 0 ? 'alert-s' : '' ?>">
      <div class="sl"><?= $pending > 0 ? '🔴' : '✅' ?> งานรอตรวจ</div>
      <div class="sv"><?= number_format($pending) ?></div>
      <div class="sd"><?= $pending > 0 ? 'รายการที่ยังค้างอยู่' : 'ตรวจครบทุกรายการ' ?></div>
    </div>
    <div class="t-stat">
      <div class="sl">👥 นักเรียน<?= $is_dev ? 'ทั้งหมด' : 'ในดูแล' ?></div>
      <div class="sv"><?= number_format($total_students) ?></div>
      <div class="sd">คน</div>
    </div>
    <div class="t-stat">
      <div class="sl">📝 การบ้าน<?= $is_dev ? 'ทั้งหมด' : 'ที่สั่ง' ?></div>
      <div class="sv"><?= number_format($total_assign) ?></div>
      <div class="sd">รายการ</div>
    </div>
  </div>

  <div class="t-sec">Quick Actions</div>
  <div class="t-grid">
    <a href="teacher_reports.php" class="t-card priority">
      <div class="ci">📊</div>
      <h3>ตรวจรายงาน & ออกเกรด</h3>
      <p>ตรวจงานนักเรียน ให้คะแนน ส่งออก Excel</p>
      <?php if ($pending > 0): ?><span class="t-badge"><?= $pending ?> รายการรอตรวจ</span><?php endif; ?>
      <span class="arr">→</span>
    </a>
    <a href="teacher_assignments.php" class="t-card">
      <div class="ci">📝</div><h3>สั่งการบ้าน / ภารกิจ</h3>
      <p>สร้างการบ้านใหม่ เลือกวิชาและห้องเรียน</p>
      <span class="arr">→</span>
    </a>
    <a href="manage_timetable.php" class="t-card">
      <div class="ci">📅</div><h3>ตารางสอนของฉัน</h3>
      <p>ดูตารางสอนรายสัปดาห์ คาบว่างและคาบควบ</p>
      <span class="arr">→</span>
    </a>
    <a href="lab_realistic.php" class="t-card">
      <div class="ci">🧪</div><h3>ห้องแล็บเคมีเสมือน</h3>
      <p>ทดสอบปฏิกิริยา สร้างโจทย์การบ้านแล็บ</p>
      <span class="arr">→</span>
    </a>
    <a href="smart_attendance.php" class="t-card">
      <div class="ci">📱</div><h3>เช็คชื่อ QR Code</h3>
      <p>สร้าง QR Code ให้นักเรียนสแกนเข้าเรียน</p>
      <span class="arr">→</span>
    </a>
  </div>
</div>
<?php require_once 'footer.php'; ?>
