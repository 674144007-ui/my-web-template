<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireRole(['developer']);

$page_title   = 'Developer Command Center';
$display_name = $_SESSION['display_name'] ?? 'Developer';

$stats = ['students'=>0,'teachers'=>0,'classes'=>0,'subjects'=>0,'assignments'=>0];
try {
    if ($pdo) {
        $stats['students']    = $pdo->query("SELECT COUNT(u.id) FROM users u JOIN sys_roles r ON r.id=u.role_id WHERE r.role_key='student' AND u.is_deleted=0")->fetchColumn();
        $stats['teachers']    = $pdo->query("SELECT COUNT(u.id) FROM users u JOIN sys_roles r ON r.id=u.role_id WHERE r.role_key='teacher' AND u.is_deleted=0")->fetchColumn();
        $stats['classes']     = $pdo->query("SELECT COUNT(id) FROM classes WHERE is_active=1")->fetchColumn();
        $stats['subjects']    = $pdo->query("SELECT COUNT(id) FROM subjects WHERE is_active=1")->fetchColumn();
        $stats['assignments'] = $pdo->query("SELECT COUNT(id) FROM assignments WHERE is_active=1")->fetchColumn();
    }
} catch (PDOException $e) { error_log('[dev] '.$e->getMessage()); }

$tenants = [];
try {
    $tenants = $master_pdo->query("SELECT school_code,school_name,db_name,primary_color,is_active FROM sys_tenants ORDER BY id ASC")->fetchAll();
} catch (Exception $e) {}

$session_min = round((time() - ($_SESSION['login_time'] ?? time())) / 60);

// ดึง online users จาก tenant DB
$online_users = [];
try {
    if ($pdo) {
        $online_users = $pdo->query("
            SELECT us.display_name, us.role_key, us.page_current, us.last_seen_at,
                   TIMESTAMPDIFF(SECOND, us.last_seen_at, NOW()) AS seconds_ago
            FROM user_sessions us
            WHERE us.last_seen_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ORDER BY us.last_seen_at DESC
        ")->fetchAll();
    }
} catch (Exception $e) { /* table ยังไม่มี */ }

require_once 'header.php';
?>
<style>
/* ── Override body สำหรับ dev dark theme ── */
body { background:#0d1117 !important; color:#e6edf3 !important; }
.dc { max-width:1180px; margin:0 auto; padding:24px 20px 80px; font-family:'Prompt',sans-serif; }

/* Hero */
.dc-hero { background:#161b22; border:1px solid #30363d; border-radius:16px;
    padding:28px 32px; margin-bottom:20px; display:flex; justify-content:space-between;
    align-items:center; flex-wrap:wrap; gap:16px; position:relative; overflow:hidden; }
.dc-hero::before { content:''; position:absolute; right:0; top:0; bottom:0; width:300px;
    background:radial-gradient(ellipse at right,rgba(56,189,248,.06) 0%,transparent 70%); pointer-events:none; }
.dc-tag { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700;
    letter-spacing:.1em; text-transform:uppercase; background:rgba(56,189,248,.1);
    color:#38bdf8; border:1px solid rgba(56,189,248,.25); padding:4px 12px;
    border-radius:20px; margin-bottom:10px; }
.dc-hero h1 { font-size:1.7rem; font-weight:700; margin:0 0 5px; color:#fff; }
.dc-hero .sub { font-size:.85rem; color:#8b949e; margin:0; }
.dc-status { display:flex; flex-direction:column; align-items:flex-end; gap:8px; }
.online-pill { display:flex; align-items:center; gap:8px; background:#0d1117;
    border:1px solid #238636; color:#3fb950; font-family:'Share Tech Mono',monospace;
    font-size:.8rem; padding:6px 14px; border-radius:8px; }
.pulse { width:8px; height:8px; border-radius:50%; background:#3fb950;
    box-shadow:0 0 6px #3fb950; animation:pulse 2s infinite; }
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.session-txt { font-family:'Share Tech Mono',monospace; font-size:.72rem; color:#484f58; }

/* Stats */
.dc-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:20px; }
.dc-s { background:#161b22; border:1px solid #30363d; border-radius:12px; padding:16px 18px;
    transition:.2s; cursor:default; }
.dc-s:hover { border-color:#58a6ff; transform:translateY(-2px); }
.dc-s .lbl { font-size:.72rem; color:#8b949e; margin-bottom:8px; display:flex; gap:5px; align-items:center; }
.dc-s .num { font-family:'Share Tech Mono',monospace; font-size:1.9rem; font-weight:700; line-height:1; }
.dc-s.c-blue .num   { color:#58a6ff; }
.dc-s.c-green .num  { color:#3fb950; }
.dc-s.c-purple .num { color:#bc8cff; }
.dc-s.c-amber .num  { color:#d29922; }
.dc-s.c-white .num  { color:#e6edf3; }

/* Section label */
.dc-lbl { font-size:.68rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase;
    color:#484f58; margin:24px 0 12px; display:flex; align-items:center; gap:10px; }
.dc-lbl::after { content:''; flex:1; height:1px; background:#21262d; }

/* Menu grid */
.dc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; margin-bottom:4px; }
.dc-card { background:#161b22; border:1px solid #30363d; border-radius:12px;
    padding:18px 20px; text-decoration:none; color:#e6edf3; display:flex;
    align-items:flex-start; gap:14px; transition:.2s; }
.dc-card:hover { background:#1c2128; border-color:#58a6ff; transform:translateY(-2px); }
.dc-card.g-green:hover { border-color:#3fb950; }
.dc-card.g-purple:hover { border-color:#bc8cff; }
.dc-card.g-amber:hover { border-color:#d29922; }
.dc-icon { width:40px; height:40px; border-radius:9px; flex-shrink:0; font-size:1.2rem;
    display:flex; align-items:center; justify-content:center;
    background:#21262d; border:1px solid #30363d; }
.dc-card h3 { font-size:.88rem; font-weight:600; color:#fff; margin:0 0 4px; }
.dc-card p  { font-size:.74rem; color:#8b949e; margin:0; line-height:1.5; }

/* Tenant table */
.dc-table-wrap { background:#161b22; border:1px solid #30363d; border-radius:12px; overflow:hidden; }
.dc-table-head { padding:13px 20px; border-bottom:1px solid #21262d;
    display:flex; justify-content:space-between; align-items:center; }
.dc-table-head span { font-size:.8rem; color:#58a6ff; font-weight:600; }
.dc-table-head a { font-size:.72rem; color:#58a6ff; text-decoration:none; }
.dc-table-head a:hover { text-decoration:underline; }
.dc-tbl { width:100%; border-collapse:collapse; }
.dc-tbl th { font-size:.65rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
    color:#484f58; padding:8px 20px; text-align:left; border-bottom:1px solid #21262d; }
.dc-tbl td { padding:11px 20px; font-size:.82rem; border-bottom:1px solid rgba(33,38,45,.8);
    color:#c9d1d9; }
.dc-tbl tr:last-child td { border-bottom:none; }
.dc-tbl tr:hover td { background:rgba(88,166,255,.03); }
.t-code { font-family:'Share Tech Mono',monospace; font-size:.75rem;
    background:rgba(56,189,248,.08); color:#58a6ff; padding:2px 8px;
    border-radius:4px; border:1px solid rgba(56,189,248,.15); }
.t-swatch { display:inline-block; width:11px; height:11px; border-radius:2px; vertical-align:middle; margin-right:5px; }
.t-on  { color:#3fb950; } .t-off { color:#484f58; }

@media(max-width:768px){
    .dc-stats{grid-template-columns:repeat(3,1fr)}
    .dc-grid{grid-template-columns:1fr}
    .dc-hero{padding:20px}
    .dc-hero h1{font-size:1.3rem}
}
</style>

<div class="dc">
  <!-- Hero -->
  <div class="dc-hero">
    <div>
      <div class="dc-tag">⚙ Developer · Command Center</div>
      <h1>สวัสดี, <?= h($display_name) ?></h1>
      <p class="sub">Platform Admin · Smart School Plus · ดูแลระบบทั้งหมด <?= count($tenants) ?> โรงเรียน</p>
    </div>
    <div class="dc-status">
      <div class="online-pill"><div class="pulse"></div>SYSTEM ONLINE</div>
      <div class="session-txt">SESSION <?= $session_min ?> MIN · <?= date('H:i') ?></div>
    </div>
  </div>

  <!-- Stats -->
  <div class="dc-stats">
    <div class="dc-s c-amber"><div class="lbl">🏫 โรงเรียน</div><div class="num"><?= count($tenants) ?></div></div>
    <div class="dc-s c-green"><div class="lbl" style="color:#3fb950">● Online ตอนนี้</div><div class="num" style="color:#3fb950"><?= count($online_users) ?></div></div>
    <div class="dc-s c-blue"><div class="lbl">🎓 นักเรียน</div><div class="num"><?= number_format($stats['students']) ?></div></div>
    <div class="dc-s c-green"><div class="lbl">👨‍🏫 ครู</div><div class="num"><?= number_format($stats['teachers']) ?></div></div>
    <div class="dc-s c-purple"><div class="lbl">📚 รายวิชา</div><div class="num"><?= number_format($stats['subjects']) ?></div></div>
    <div class="dc-s c-white"><div class="lbl">🚪 ห้องเรียน</div><div class="num"><?= number_format($stats['classes']) ?></div></div>
  </div>

  <!-- Platform -->
  <div class="dc-lbl">Platform Management</div>
  <div class="dc-grid">
    <a href="tenant_manager.php" class="dc-card g-amber"><div class="dc-icon">🏫</div><div><h3>จัดการโรงเรียน</h3><p>เพิ่ม / แก้ไข / เปิด-ปิดโรงเรียน ตั้งตราและสีธีม</p></div></a>
    <a href="user_manager.php"   class="dc-card"><div class="dc-icon">👥</div><div><h3>จัดการผู้ใช้</h3><p>เพิ่มบัญชีครู/นักเรียน รีเซ็ตรหัสผ่าน นำเข้า Excel</p></div></a>
    <a href="dev_manage_subjects.php" class="dc-card"><div class="dc-icon">📚</div><div><h3>จัดการรายวิชา</h3><p>เพิ่ม แก้ไข ระงับรายวิชา ตั้งรหัสและธีมสี</p></div></a>
    <a href="migrate_lms_phase1.php"  class="dc-card g-amber"><div class="dc-icon">⚙️</div><div><h3>Database Migrations</h3><p>รัน migration scripts อัปเกรดโครงสร้าง DB</p></div></a>
  </div>

  <!-- Timetable -->
  <div class="dc-lbl">Timetable System</div>
  <div class="dc-grid">
    <a href="setup_timetable_db.php" class="dc-card g-green"><div class="dc-icon">🗄️</div><div><h3>1. ตั้งค่า Database</h3><p>สร้าง/อัปเดตโครงสร้างตารางสอน</p></div></a>
    <a href="import_timetable.php"   class="dc-card g-green"><div class="dc-icon">📥</div><div><h3>2. นำเข้า Excel</h3><p>Import ตารางสอนจากไฟล์ Excel</p></div></a>
    <a href="manage_timetable.php"   class="dc-card g-green"><div class="dc-icon">📅</div><div><h3>3. จัดการตาราง</h3><p>แก้ไขตาราง เพิ่มคาบควบ ดูภาพรวม</p></div></a>
  </div>

  <!-- Dev Tools -->
  <div class="dc-lbl">Developer Tools</div>
  <div class="dc-grid">
    <a href="lab_realistic.php"       class="dc-card g-purple"><div class="dc-icon">🧪</div><div><h3>Chemistry Lab (God Mode)</h3><p>ทดสอบปฏิกิริยา สร้าง JSON ตั้งการบ้าน</p></div></a>
    <a href="teacher_assignments.php" class="dc-card g-purple"><div class="dc-icon">📝</div><div><h3>Super Teacher — สั่งงาน</h3><p>สั่งการบ้านทุกวิชาทุกห้องไม่มีข้อจำกัด</p></div></a>
    <a href="teacher_reports.php"     class="dc-card g-purple"><div class="dc-icon">📊</div><div><h3>Super Teacher — รายงาน</h3><p>ตรวจและปรับคะแนนนักเรียนทุกคน</p></div></a>
    <a href="dev_reset_progress.php"  class="dc-card g-amber"><div class="dc-icon">🔄</div><div><h3>Reset Progress</h3><p>รีเซ็ตความคืบหน้านักเรียนเพื่อทดสอบ</p></div></a>
  </div>

  <!-- Online Users -->
  <?php if (!empty($online_users)): ?>
  <div class="dc-lbl">Users Online Now (<?= count($online_users) ?>)</div>
  <div class="dc-table-wrap" style="margin-bottom:12px">
    <div class="dc-table-head">
      <span style="color:#3fb950">● ผู้ใช้ที่ online (active ใน 5 นาที)</span>
    </div>
    <table class="dc-tbl">
      <thead><tr><th>ชื่อ</th><th>บทบาท</th><th>หน้าปัจจุบัน</th><th>Active</th></tr></thead>
      <tbody>
        <?php foreach($online_users as $u):
          $rc = match($u['role_key']??'') {
            'student'=>'#3fb950','teacher'=>'#58a6ff','developer'=>'#bc8cff',default=>'#8b949e'};
          $ago = (int)$u['seconds_ago'];
          $ago_txt = $ago < 60 ? 'เพิ่งใช้งาน' : round($ago/60).' นาทีที่แล้ว';
        ?>
        <tr>
          <td style="font-weight:500;color:#e6edf3"><?= h($u['display_name']) ?></td>
          <td><span style="font-size:.74rem;color:<?= $rc ?>"><?= h($u['role_key']??'-') ?></span></td>
          <td style="font-family:'Share Tech Mono',monospace;font-size:.72rem;color:#484f58"><?= h($u['page_current']??'-') ?></td>
          <td style="font-size:.74rem;color:#3fb950"><?= $ago_txt ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Tenant overview -->
  <?php if ($tenants): ?>
  <div class="dc-lbl">Tenant Overview</div>
  <div class="dc-table-wrap">
    <div class="dc-table-head">
      <span>รายชื่อโรงเรียนทั้งหมด</span>
      <a href="tenant_manager.php">จัดการ →</a>
    </div>
    <table class="dc-tbl">
      <thead><tr><th>Code</th><th>ชื่อโรงเรียน</th><th>Database</th><th>สีธีม</th><th>สถานะ</th></tr></thead>
      <tbody>
      <?php foreach($tenants as $t): ?>
      <tr>
        <td><span class="t-code"><?= h($t['school_code']) ?></span></td>
        <td><?= h($t['school_name']) ?></td>
        <td style="font-family:'Share Tech Mono',monospace;font-size:.73rem;color:#484f58"><?= h($t['db_name']) ?></td>
        <td><span class="t-swatch" style="background:<?= h($t['primary_color']) ?>"></span><span style="font-family:'Share Tech Mono',monospace;font-size:.72rem;color:#484f58"><?= h($t['primary_color']) ?></span></td>
        <td class="<?= $t['is_active'] ? 't-on' : 't-off' ?>"><?= $t['is_active'] ? '● เปิด' : '○ ปิด' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
