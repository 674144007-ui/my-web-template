<?php
/**
 * student_timetable.php — ตารางเรียนส่วนตัวนักเรียน
 * ออกแบบใหม่: Soft Modern, real-time period highlight
 */
require_once 'config.php';
require_once 'db.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'logger.php';

requireRole(['student', 'developer', 'admin']);

$student_id  = $_SESSION['user_id'];
$class_id    = $_SESSION['class_id'] ?? 0;
$class_level = $_SESSION['class_level'] ?? '';
$display_name = $_SESSION['display_name'] ?? 'นักเรียน';

// ── ดึงข้อมูลห้องเรียน ────────────────────────────────────────────────────
$class_info = null;
try {
    $s = $pdo->prepare("SELECT c.*, u.display_name AS advisor_name FROM classes c LEFT JOIN users u ON c.teacher_id=u.id WHERE c.id=?");
    $s->execute([$class_id]);
    $class_info = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── ดึง time_slots ─────────────────────────────────────────────────────────
$time_slots = [];
try {
    $time_slots = $pdo->query("SELECT * FROM time_slots ORDER BY period_number ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $time_slots = [
        ['period_number'=>1,'start_time'=>'08.30','end_time'=>'09.20','is_break'=>0,'label'=>null],
        ['period_number'=>2,'start_time'=>'09.20','end_time'=>'10.10','is_break'=>0,'label'=>null],
        ['period_number'=>3,'start_time'=>'10.20','end_time'=>'11.10','is_break'=>0,'label'=>null],
        ['period_number'=>4,'start_time'=>'11.10','end_time'=>'12.00','is_break'=>0,'label'=>null],
        ['period_number'=>5,'start_time'=>'12.00','end_time'=>'12.50','is_break'=>1,'label'=>'พักกลางวัน'],
        ['period_number'=>6,'start_time'=>'12.50','end_time'=>'13.40','is_break'=>0,'label'=>null],
        ['period_number'=>7,'start_time'=>'13.40','end_time'=>'14.30','is_break'=>0,'label'=>null],
        ['period_number'=>8,'start_time'=>'14.40','end_time'=>'15.30','is_break'=>0,'label'=>null],
        ['period_number'=>9,'start_time'=>'15.30','end_time'=>'16.20','is_break'=>0,'label'=>null],
        ['period_number'=>10,'start_time'=>'16.20','end_time'=>'17.10','is_break'=>0,'label'=>null],
    ];
}

// ── ดึงตารางเรียนจาก teacher_schedules ────────────────────────────────────
$schedule_map = []; // [day][period] = row
$subjects_info = []; // subject_code → subject+teacher info
try {
    // ดึงจาก teacher_schedules โดย match class_room กับ class_name
    $cn = $class_info['class_name'] ?? '';
    // รูปแบบ class_room ใน teacher_schedules อาจเป็น "ม.5/1" หรือ "5/1"
    $cn_short = preg_replace('/^ม\./', '', $cn); // "5/1"

    $stmt = $pdo->prepare("
        SELECT ts.*,
               COALESCE(s.subject_name, ts.subject_name, ts.subject_code) AS display_name,
               COALESCE(s.color_theme, '#3b82f6') AS color,
               COALESCE(s.icon, '📚') AS icon,
               u.display_name AS teacher_display,
               t.full_name AS teacher_full
        FROM teacher_schedules ts
        LEFT JOIN subjects s ON s.subject_code = ts.subject_code AND s.is_active = 1
        LEFT JOIN teachers t ON t.id = ts.teacher_id
        LEFT JOIN users u ON u.username = t.teacher_code AND r.role_key='teacher'
        WHERE ts.is_activity = 0
          AND (ts.class_room = ? OR ts.class_room = ? OR ts.class_room = ?)
        ORDER BY ts.day_of_week ASC, ts.start_period ASC
    ");
    $stmt->execute([$cn, $cn_short, 'ม.'.$cn_short]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $d = $r['day_of_week'];
        for ($p = $r['start_period']; $p <= $r['end_period']; $p++) {
            $schedule_map[$d][$p] = $r;
        }
        $subjects_info[$r['subject_code']] = $r;
    }
} catch (Exception $e) { error_log($e->getMessage()); }

// ── วันนี้คือวันอะไร + คาบปัจจุบัน ─────────────────────────────────────────
date_default_timezone_set('Asia/Bangkok');
$today_num  = (int)date('N'); // 1=จ 5=ศ
$now_time   = date('H.i');
$now_float  = (float)str_replace('.', '.', $now_time);
$now_period = 0;
foreach ($time_slots as $ts) {
    if (!$ts['is_break']) {
        $s = (float)$ts['start_time'];
        $e = (float)$ts['end_time'];
        if ($now_float >= $s && $now_float < $e) {
            $now_period = $ts['period_number'];
            break;
        }
    }
}

// next period
$next_period = 0;
$next_start  = '';
foreach ($time_slots as $ts) {
    if (!$ts['is_break'] && $ts['period_number'] > $now_period) {
        $s = (float)$ts['start_time'];
        if ($s > $now_float) { $next_period = $ts['period_number']; $next_start = $ts['start_time']; break; }
    }
}

// คาบปัจจุบันมีวิชาอะไร
$current_subject = $schedule_map[$today_num][$now_period] ?? null;
$next_subject    = $schedule_map[$today_num][$next_period] ?? null;

// ── จำนวนวิชาไม่ซ้ำ ────────────────────────────────────────────────────────
$unique_subjects = count($subjects_info);
$total_periods   = 0;
foreach ($schedule_map as $d => $periods) {
    foreach ($periods as $p => $r) {
        if ($p == $r['start_period']) $total_periods++;
    }
}

$day_th   = ['', 'วันจันทร์', 'วันอังคาร', 'วันพุธ', 'วันพฤหัสบดี', 'วันศุกร์'];
$day_abbr = ['', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.'];

require_once 'header.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ตารางเรียน — <?= h($display_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════
   DESIGN TOKENS — Warm Soft Pastel
═══════════════════════════════════════════ */
:root {
    --bg:       #f5f3ef;
    --bg2:      #edeae4;
    --card:     #ffffff;
    --navy:     #1a1f36;
    --text:     #2d3142;
    --muted:    #7c7f93;
    --border:   rgba(0,0,0,.07);
    --shadow:   0 2px 16px rgba(0,0,0,.07);
    --shadow-lg:0 8px 32px rgba(0,0,0,.11);
    --radius:   16px;
    --radius-sm:10px;

    /* วัน */
    --mon: #6366f1; --mon-l: #eef2ff;
    --tue: #ec4899; --tue-l: #fdf2f8;
    --wed: #10b981; --wed-l: #ecfdf5;
    --thu: #f59e0b; --thu-l: #fffbeb;
    --fri: #ef4444; --fri-l: #fef2f2;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    background: var(--bg);
    font-family: 'Noto Sans Thai', sans-serif;
    color: var(--text);
    min-height: 100vh;
}

/* ── Page wrapper ── */
.page {
    max-width: 1160px;
    margin: 0 auto;
    padding: 28px 20px 80px;
}

/* ── Hero strip ── */
.hero {
    background: var(--navy);
    border-radius: 22px;
    padding: 28px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}
.hero::before {
    content: '';
    position: absolute;
    right: -60px; top: -60px;
    width: 260px; height: 260px;
    background: radial-gradient(circle, rgba(99,102,241,.25) 0%, transparent 70%);
    pointer-events: none;
}
.hero::after {
    content: '';
    position: absolute;
    left: 30%; bottom: -40px;
    width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(236,72,153,.15) 0%, transparent 70%);
    pointer-events: none;
}
.hero-avatar {
    width: 56px; height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #ec4899);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; font-weight: 800; color: white;
    flex-shrink: 0; position: relative; z-index: 2;
    box-shadow: 0 4px 16px rgba(99,102,241,.4);
}
.hero-info { flex: 1; position: relative; z-index: 2; }
.hero-name { font-size: 1.25rem; font-weight: 700; color: #fff; margin-bottom: 4px; }
.hero-class { font-size: .85rem; color: rgba(255,255,255,.6); }
.hero-stats { display: flex; gap: 20px; position: relative; z-index: 2; }
.hstat { text-align: center; }
.hstat-num { font-size: 1.6rem; font-weight: 800; color: #fff; font-family: 'IBM Plex Mono', monospace; line-height: 1; }
.hstat-lbl { font-size: .72rem; color: rgba(255,255,255,.5); margin-top: 4px; }

/* ── Now bar ── */
.now-bar {
    border-radius: var(--radius);
    padding: 16px 22px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    animation: slideDown .4s ease;
}
.now-bar.has-class {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    box-shadow: 0 6px 24px rgba(99,102,241,.3);
}
.now-bar.break-time { background: linear-gradient(135deg, #f59e0b, #f97316); box-shadow: 0 6px 24px rgba(245,158,11,.3); }
.now-bar.free-time  { background: var(--bg2); border: 1px solid var(--border); }
.now-bar.holiday    { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 6px 24px rgba(16,185,129,.3); }
.now-pulse {
    width: 10px; height: 10px; border-radius: 50%; background: #fff; flex-shrink: 0;
    box-shadow: 0 0 0 0 rgba(255,255,255,.5);
    animation: pulse 2s infinite;
}
.now-bar.free-time .now-pulse { background: var(--muted); }
@keyframes pulse {
    0%   { box-shadow: 0 0 0 0 rgba(255,255,255,.6); }
    70%  { box-shadow: 0 0 0 8px rgba(255,255,255,0); }
    100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); }
}
.now-text { flex: 1; }
.now-title { font-size: 1rem; font-weight: 700; color: #fff; }
.now-sub   { font-size: .8rem; color: rgba(255,255,255,.75); margin-top: 2px; }
.now-bar.free-time .now-title,
.now-bar.free-time .now-sub { color: var(--muted); }
.now-time-chip {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .82rem; font-weight: 600;
    background: rgba(255,255,255,.2);
    color: #fff;
    padding: 5px 12px;
    border-radius: 20px;
    white-space: nowrap;
}
.now-bar.free-time .now-time-chip { background: var(--border); color: var(--muted); }

/* ── Day tabs ── */
.day-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: none;
}
.day-tabs::-webkit-scrollbar { display: none; }
.day-tab {
    padding: 9px 18px;
    border-radius: 30px;
    border: 1.5px solid var(--border);
    background: var(--card);
    font-family: inherit;
    font-size: .85rem;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
    transition: .2s;
    white-space: nowrap;
    flex-shrink: 0;
    position: relative;
}
.day-tab .dot {
    display: inline-block;
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--muted);
    margin-right: 5px;
    vertical-align: middle;
}
.day-tab.today { border-color: var(--mon); }
.day-tab.active { color: #fff; border-color: transparent; }
.day-tab.d1.active { background: var(--mon); }
.day-tab.d2.active { background: var(--tue); }
.day-tab.d3.active { background: var(--wed); }
.day-tab.d4.active { background: var(--thu); }
.day-tab.d5.active { background: var(--fri); }
.day-tab.active .dot { background: rgba(255,255,255,.6); }
.day-tab:not(.active):hover { background: var(--bg2); }

/* ── Timetable grid ── */
.tt-card {
    background: var(--card);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    animation: fadeUp .35s ease;
}
@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

.tt-header {
    padding: 18px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
}
.tt-day-label { font-size: 1.1rem; font-weight: 700; }
.tt-count { font-size: .8rem; color: var(--muted); background: var(--bg2); padding: 3px 10px; border-radius: 20px; }

/* Period rows */
.period-list { padding: 12px; display: flex; flex-direction: column; gap: 8px; }

.period-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    border-radius: var(--radius-sm);
    transition: .2s;
    position: relative;
}
.period-item.has-subject { background: var(--bg); }
.period-item.is-break {
    background: #fffbeb;
    border: 1px dashed #fde68a;
    justify-content: center;
    padding: 8px 16px;
}
.period-item.is-now {
    background: linear-gradient(135deg, #eef2ff, #fdf4ff) !important;
    border: 2px solid #818cf8;
    transform: scale(1.01);
}
.period-item.is-done { opacity: .5; }

/* Period number badge */
.period-num {
    width: 38px; height: 38px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800;
    font-family: 'IBM Plex Mono', monospace;
    font-size: .85rem;
    flex-shrink: 0;
    background: var(--bg2);
    color: var(--muted);
}
.period-item.is-now .period-num { background: #6366f1; color: #fff; }

/* Subject info */
.period-info { flex: 1; min-width: 0; }
.period-subject-name {
    font-size: .95rem;
    font-weight: 700;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.period-code {
    font-size: .72rem;
    color: var(--muted);
    font-family: 'IBM Plex Mono', monospace;
    margin-top: 2px;
}
.period-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 5px;
}
.period-teacher { font-size: .78rem; color: var(--muted); display: flex; align-items: center; gap: 4px; }
.period-room    { font-size: .75rem; background: var(--bg2); color: var(--muted); padding: 2px 8px; border-radius: 6px; font-family: 'IBM Plex Mono', monospace; }

/* Time badge */
.period-time {
    font-family: 'IBM Plex Mono', monospace;
    font-size: .72rem;
    color: var(--muted);
    text-align: right;
    flex-shrink: 0;
    line-height: 1.5;
}
.period-time .end { opacity: .6; }

/* Color dot per subject */
.subject-color-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

/* Now indicator */
.now-indicator {
    position: absolute;
    left: 0; top: 50%;
    transform: translateY(-50%);
    width: 4px; height: 70%;
    background: #6366f1;
    border-radius: 0 4px 4px 0;
}

/* Empty slot */
.period-empty {
    font-size: .82rem;
    color: rgba(0,0,0,.2);
    font-style: italic;
}

/* Break row */
.break-emoji { font-size: 1.1rem; margin-right: 6px; }
.break-label { font-size: .82rem; font-weight: 600; color: #92400e; }
.break-time  { font-size: .75rem; color: #b45309; font-family: 'IBM Plex Mono', monospace; }

/* ── Weekly mini overview ── */
.weekly-strip {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
    margin-top: 20px;
}
.wday-card {
    background: var(--card);
    border-radius: var(--radius-sm);
    padding: 12px 10px;
    border: 1px solid var(--border);
    cursor: pointer;
    transition: .2s;
}
.wday-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
.wday-card.today { border-color: #818cf8; box-shadow: 0 4px 16px rgba(99,102,241,.15); }
.wday-name { font-size: .75rem; font-weight: 700; color: var(--muted); text-align: center; margin-bottom: 8px; }
.wday-card.today .wday-name { color: #6366f1; }
.wday-periods { display: flex; flex-direction: column; gap: 4px; }
.wday-chip {
    border-radius: 6px;
    padding: 4px 6px;
    font-size: .68rem;
    font-weight: 600;
    color: #fff;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.wday-empty {
    height: 24px;
    background: var(--bg2);
    border-radius: 6px;
}

/* ── Responsive ── */
@media (max-width: 640px) {
    .hero { padding: 20px; gap: 16px; }
    .hero-stats { gap: 14px; }
    .hstat-num { font-size: 1.3rem; }
    .weekly-strip { grid-template-columns: repeat(5, 1fr); gap: 6px; }
    .wday-chip { font-size: .62rem; }
}

@keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:none; } }
</style>
</head>
<body>
<div class="page">

    <!-- ── HERO ── -->
    <div class="hero">
        <div class="hero-avatar"><?= mb_substr($display_name, 0, 1, 'UTF-8') ?></div>
        <div class="hero-info">
            <div class="hero-name"><?= h($display_name) ?></div>
            <div class="hero-class">
                🏫 <?= h($class_info['class_name'] ?? $class_level ?: 'ยังไม่ระบุห้องเรียน') ?>
                <?php if ($class_info['advisor_name']): ?>
                &nbsp;·&nbsp; 👨‍🏫 ครูที่ปรึกษา: <?= h($class_info['advisor_name']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-stats">
            <div class="hstat">
                <div class="hstat-num"><?= $unique_subjects ?></div>
                <div class="hstat-lbl">วิชาเรียน</div>
            </div>
            <div class="hstat">
                <div class="hstat-num"><?= $total_periods ?></div>
                <div class="hstat-lbl">คาบ/สัปดาห์</div>
            </div>
            <div class="hstat">
                <div class="hstat-num"><?= count(array_filter($time_slots, fn($t) => !$t['is_break'])) ?></div>
                <div class="hstat-lbl">คาบ/วัน</div>
            </div>
        </div>
    </div>

    <!-- ── NOW BAR ── -->
    <?php
    $is_school_day = ($today_num >= 1 && $today_num <= 5);
    if (!$is_school_day):
    ?>
    <div class="now-bar holiday">
        <div class="now-pulse"></div>
        <div class="now-text">
            <div class="now-title">🎉 วันหยุด — พักผ่อนให้เต็มที่นะ</div>
            <div class="now-sub">วันจันทร์ถึงศุกร์เท่านั้นที่มีตาราง</div>
        </div>
        <div class="now-time-chip" id="liveClock"></div>
    </div>
    <?php elseif ($current_subject): ?>
    <div class="now-bar has-class">
        <div class="now-pulse"></div>
        <div class="now-text">
            <div class="now-title">📖 กำลังเรียน: <?= h($current_subject['display_name']) ?></div>
            <div class="now-sub">
                คาบ <?= $now_period ?> · ห้อง <?= h($current_subject['room_number'] ?: $current_subject['class_room']) ?>
                <?php $tname = $current_subject['teacher_display'] ?: $current_subject['teacher_full'] ?: $current_subject['teacher_name']; ?>
                <?php if ($tname): ?> · 👨‍🏫 <?= h($tname) ?><?php endif; ?>
            </div>
        </div>
        <div class="now-time-chip" id="liveClock"></div>
    </div>
    <?php else: ?>
    <div class="now-bar free-time">
        <div class="now-pulse" style="background:var(--muted);animation:none;"></div>
        <div class="now-text">
            <div class="now-title" style="color:var(--text);">
                <?= $now_period == 0 ? '⏰ ยังไม่ถึงเวลาเรียน' : '☕ ว่างอยู่ในขณะนี้' ?>
            </div>
            <div class="now-sub">
                <?php if ($next_period && $next_subject): ?>
                    ถัดไป คาบ <?= $next_period ?> (<?= $next_start ?>) — <?= h($next_subject['display_name']) ?>
                <?php elseif ($next_period): ?>
                    ถัดไป คาบ <?= $next_period ?> เวลา <?= $next_start ?>
                <?php else: ?>
                    เรียนเสร็จสำหรับวันนี้แล้ว 🎒
                <?php endif; ?>
            </div>
        </div>
        <div class="now-time-chip" id="liveClock"></div>
    </div>
    <?php endif; ?>

    <!-- ── DAY TABS ── -->
    <div class="day-tabs" id="dayTabs">
        <?php for ($d = 1; $d <= 5; $d++):
            $has = !empty($schedule_map[$d]);
            $colors = [1=>'#6366f1',2=>'#ec4899',3=>'#10b981',4=>'#f59e0b',5=>'#ef4444'];
        ?>
        <button class="day-tab d<?= $d ?> <?= $today_num==$d?'today':'' ?>"
                data-day="<?= $d ?>"
                onclick="showDay(<?= $d ?>)">
            <span class="dot" style="<?= $has ? 'background:'.$colors[$d] : '' ?>"></span>
            <?= $day_abbr[$d] ?> <?= $day_th[$d] ?>
        </button>
        <?php endfor; ?>
    </div>

    <!-- ── TIMETABLE CARD ── -->
    <?php for ($d = 1; $d <= 5; $d++):
        $day_colors = [1=>'#6366f1',2=>'#ec4899',3=>'#10b981',4=>'#f59e0b',5=>'#ef4444'];
        $day_lights = [1=>'#eef2ff',2=>'#fdf2f8',3=>'#ecfdf5',4=>'#fffbeb',5=>'#fef2f2'];
        $cnt = 0;
        foreach ($time_slots as $ts) {
            if (!$ts['is_break'] && isset($schedule_map[$d][$ts['period_number']])) $cnt++;
        }
    ?>
    <div class="tt-card day-panel" id="panel-<?= $d ?>" style="display:none;">
        <div class="tt-header">
            <div class="tt-day-label" style="color:<?= $day_colors[$d] ?>">
                <?= $day_th[$d] ?>
            </div>
            <span class="tt-count"><?= $cnt ?> วิชา</span>
        </div>
        <div class="period-list">
            <?php foreach ($time_slots as $ts):
                $p = $ts['period_number'];
                $is_break = $ts['is_break'];

                if ($is_break):
            ?>
            <div class="period-item is-break">
                <span class="break-emoji">🍱</span>
                <span class="break-label"><?= $ts['label'] ?? 'พักกลางวัน' ?></span>
                <span class="break-time" style="margin-left:8px;"><?= $ts['start_time'] ?> - <?= $ts['end_time'] ?></span>
            </div>
            <?php else:
                $subj = $schedule_map[$d][$p] ?? null;
                // ข้ามถ้า span (ไม่ใช่ start)
                if ($subj && $p > $subj['start_period']) continue;
                $is_now = ($d === $today_num && $p === $now_period);
                $is_done = ($d === $today_num && $p < $now_period);
                $span = $subj ? ($subj['end_period'] - $subj['start_period'] + 1) : 1;
                $tname = $subj ? ($subj['teacher_display'] ?: $subj['teacher_full'] ?: $subj['teacher_name'] ?: '') : '';
                $color = $subj['color'] ?? '#94a3b8';
            ?>
            <div class="period-item <?= $subj?'has-subject':'' ?> <?= $is_now?'is-now':'' ?> <?= $is_done?'is-done':'' ?>">
                <?php if ($is_now): ?><div class="now-indicator"></div><?php endif; ?>
                <div class="period-num"><?= $p ?><?= $span > 1 ? '-'.($p+$span-1) : '' ?></div>
                <div class="period-info">
                    <?php if ($subj): ?>
                    <div class="period-subject-name"><?= h($subj['display_name']) ?></div>
                    <div class="period-code"><?= h($subj['subject_code']) ?></div>
                    <div class="period-meta">
                        <?php if ($tname): ?>
                        <span class="period-teacher">👨‍🏫 <?= h($tname) ?></span>
                        <?php endif; ?>
                        <?php if ($subj['room_number']): ?>
                        <span class="period-room">ห้อง <?= h($subj['room_number']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="period-empty">ว่าง</div>
                    <?php endif; ?>
                </div>
                <div class="period-time">
                    <?php
                    $slot_s = null; $slot_e = null;
                    foreach ($time_slots as $tsx) if ($tsx['period_number'] == $p) { $slot_s = $tsx['start_time']; }
                    foreach ($time_slots as $tsx) if ($tsx['period_number'] == ($p+$span-1)) { $slot_e = $tsx['end_time']; }
                    ?>
                    <?= $slot_s ?><br><span class="end"><?= $slot_e ?></span>
                </div>
                <?php if ($subj): ?>
                <div class="subject-color-dot" style="background:<?= h($color) ?>; opacity:.7;"></div>
                <?php endif; ?>
            </div>
            <?php endif; endforeach; ?>
        </div>
    </div>
    <?php endfor; ?>

    <!-- ── WEEKLY OVERVIEW STRIP ── -->
    <div style="margin-top:24px;">
        <div style="font-size:.8rem; font-weight:700; color:var(--muted); margin-bottom:10px; letter-spacing:.5px; text-transform:uppercase;">ภาพรวมสัปดาห์</div>
        <div class="weekly-strip">
            <?php for ($d = 1; $d <= 5; $d++):
                $colors = [1=>'#6366f1',2=>'#ec4899',3=>'#10b981',4=>'#f59e0b',5=>'#ef4444'];
                $c = $colors[$d];
            ?>
            <div class="wday-card <?= $d==$today_num?'today':'' ?>" onclick="showDay(<?= $d ?>)">
                <div class="wday-name"><?= $day_abbr[$d] ?></div>
                <div class="wday-periods">
                    <?php foreach ($time_slots as $ts):
                        if ($ts['is_break']) continue;
                        $p = $ts['period_number'];
                        $subj = $schedule_map[$d][$p] ?? null;
                        if ($subj && $p > $subj['start_period']) continue;
                    ?>
                    <?php if ($subj): ?>
                    <div class="wday-chip" style="background:<?= $c ?>;" title="<?= h($subj['display_name']) ?>">
                        <?= h(mb_substr($subj['display_name'], 0, 6, 'UTF-8')) ?>
                    </div>
                    <?php else: ?>
                    <div class="wday-empty"></div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>

</div><!-- end .page -->

<script>
// ── Live clock ──────────────────────────────────────────────────────────
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    const el = document.getElementById('liveClock');
    if (el) el.textContent = `${h}:${m}:${s}`;
}
setInterval(updateClock, 1000);
updateClock();

// ── Day switcher ──────────────────────────────────────────────────────
const today = <?= $today_num ?>;

function showDay(d) {
    document.querySelectorAll('.day-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.day-tab').forEach(t => t.classList.remove('active'));
    const panel = document.getElementById('panel-' + d);
    if (panel) { panel.style.display = ''; panel.style.animation = 'fadeUp .3s ease'; }
    const tab = document.querySelector(`.day-tab[data-day="${d}"]`);
    if (tab) tab.classList.add('active');
}

// เปิดวันนี้ก่อน ถ้าวันหยุดเปิดจันทร์
const startDay = (today >= 1 && today <= 5) ? today : 1;
showDay(startDay);
</script>

<?php require_once 'footer.php'; ?>
</body>
</html>
