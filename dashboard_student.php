<?php
// dashboard_student.php - ศูนย์กลางการเรียนรู้นักเรียน (Phase 5: Portfolio Export & Dark Mode)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// บังคับให้เฉพาะนักเรียนเท่านั้นที่เข้าหน้านี้ได้
requireRole(['student']);

$page_title = "แดชบอร์ดนักเรียน - โรงเรียนบ้านคาวิทยา";
$student_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$display_name = $_SESSION['display_name'] ?? 'นักเรียน';
$csrf = generate_csrf_token();

// ---------------------------------------------------------
// 1. ดึงข้อมูลส่วนตัวและระดับชั้น
// ---------------------------------------------------------
$class_name = "ยังไม่ระบุชั้นเรียน";
$class_id = 0;
$stmt_user = $conn->prepare("SELECT c.id, c.class_name FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.id = ?");
$stmt_user->bind_param("i", $student_id);
$stmt_user->execute();
$res_user = $stmt_user->get_result();
if ($res_user->num_rows > 0) {
    $row = $res_user->fetch_assoc();
    if (!empty($row['class_name'])) {
        $class_name = $row['class_name'];
        $class_id = $row['id'];
    }
}
$stmt_user->close();

date_default_timezone_set('Asia/Bangkok');
$hour = date('H');
$greeting = "สวัสดี"; $greeting_icon = "👋";
if ($hour >= 5 && $hour < 12) { $greeting = "สวัสดีตอนเช้า"; $greeting_icon = "🌅"; } 
elseif ($hour >= 12 && $hour < 17) { $greeting = "สวัสดีตอนบ่าย"; $greeting_icon = "☀️"; } 
elseif ($hour >= 17 && $hour < 21) { $greeting = "สวัสดีตอนเย็น"; $greeting_icon = "🌇"; } 
else { $greeting = "ราตรีสวัสดิ์"; $greeting_icon = "🌙"; }
$avatar_letter = mb_substr($display_name, 0, 1, 'UTF-8');

// ---------------------------------------------------------
// 2. ดึงสถิติการทำแล็บ และ GAMIFICATION ENGINE
// ---------------------------------------------------------
$total_labs = 0; $avg_score = 0; $max_score = 0; $total_score_sum = 0; $min_hp = 100; $best_grade = "-";
$stmt_stats = $conn->prepare("SELECT COUNT(id) as total_count, AVG(final_score) as average, MAX(final_score) as maximum, SUM(final_score) as score_sum, MIN(hp_remaining) as min_hp FROM lab_reports WHERE student_id = ?");
$stmt_stats->bind_param("i", $student_id); $stmt_stats->execute(); $res_stats = $stmt_stats->get_result();
if ($res_stats->num_rows > 0) {
    $stats = $res_stats->fetch_assoc();
    $total_labs = intval($stats['total_count']); $avg_score = round(floatval($stats['average']), 2);
    $max_score = intval($stats['maximum']); $total_score_sum = intval($stats['score_sum']); $min_hp = intval($stats['min_hp']);
}
$stmt_stats->close();

if ($total_labs > 0) {
    if ($max_score >= 80) $best_grade = "A"; elseif ($max_score >= 70) $best_grade = "B";
    elseif ($max_score >= 60) $best_grade = "C"; elseif ($max_score >= 50) $best_grade = "D"; else $best_grade = "F";
}

$total_xp = $total_score_sum * 15;
$current_level = floor($total_xp / 1000) + 1;
$base_xp_for_level = ($current_level - 1) * 1000;
$target_xp_for_next = $current_level * 1000;
$xp_progress_current = $total_xp - $base_xp_for_level;
$xp_percent = ($xp_progress_current / 1000) * 100;

$all_badges = [
    'first_blood' => ['icon' => '🧪', 'name' => 'นักเคมีฝึกหัด', 'desc' => 'ส่งรายงานการทดลองครั้งแรกสำเร็จ'],
    'perfect_score' => ['icon' => '💯', 'name' => 'ผลงานไร้ที่ติ', 'desc' => 'ทำคะแนนการทดลองได้ 100 คะแนนเต็ม'],
    'safety_master' => ['icon' => '🛡️', 'name' => 'เซฟตี้มาสเตอร์', 'desc' => 'ทำการทดลองโดย HP ไม่ลดเลย (100%)'],
    'mad_scientist' => ['icon' => '💥', 'name' => 'นักวิทย์สติเฟื่อง', 'desc' => 'ทำแล็บระเบิด หรือ HP ต่ำกว่า 30%'],
    'veteran' => ['icon' => '🎖️', 'name' => 'ผู้เชี่ยวชาญ', 'desc' => 'ส่งรายงานการทดลองครบ 10 ครั้งขึ้นไป']
];

$unlocked_keys = [];
$stmt_b = $conn->prepare("SELECT badge_key FROM student_badges WHERE student_id = ?");
$stmt_b->bind_param("i", $student_id); $stmt_b->execute(); $res_b = $stmt_b->get_result();
while ($b = $res_b->fetch_assoc()) { $unlocked_keys[] = $b['badge_key']; }
$stmt_b->close();

$new_badges_unlocked = [];
if ($total_labs >= 1 && !in_array('first_blood', $unlocked_keys)) { $new_badges_unlocked[] = 'first_blood'; }
if ($max_score == 100 && !in_array('perfect_score', $unlocked_keys)) { $new_badges_unlocked[] = 'perfect_score'; }
if ($total_labs >= 10 && !in_array('veteran', $unlocked_keys)) { $new_badges_unlocked[] = 'veteran'; }
if ($total_labs > 0 && $min_hp <= 30 && !in_array('mad_scientist', $unlocked_keys)) { $new_badges_unlocked[] = 'mad_scientist'; }
if ($total_labs > 0 && !in_array('safety_master', $unlocked_keys)) {
    $check_hp = $conn->prepare("SELECT id FROM lab_reports WHERE student_id = ? AND hp_remaining = 100 LIMIT 1");
    $check_hp->bind_param("i", $student_id); $check_hp->execute();
    if ($check_hp->get_result()->num_rows > 0) { $new_badges_unlocked[] = 'safety_master'; }
    $check_hp->close();
}

if (count($new_badges_unlocked) > 0) {
    $insert_b = $conn->prepare("INSERT IGNORE INTO student_badges (student_id, badge_key) VALUES (?, ?)");
    foreach ($new_badges_unlocked as $key) {
        $insert_b->bind_param("is", $student_id, $key);
        $insert_b->execute(); $unlocked_keys[] = $key;
    }
    $insert_b->close();
}

// ---------------------------------------------------------
// 3. ดึงข้อมูลกราฟ และ ประวัติล่าสุด
// ---------------------------------------------------------
$chart_labels = []; $chart_scores = []; $chart_hps = [];
$query_chart = "SELECT final_score, hp_remaining, created_at FROM (SELECT final_score, hp_remaining, created_at FROM lab_reports WHERE student_id = ? ORDER BY created_at DESC LIMIT 10) sub ORDER BY created_at ASC";
$stmt_chart = $conn->prepare($query_chart);
$stmt_chart->bind_param("i", $student_id); $stmt_chart->execute(); $res_chart = $stmt_chart->get_result();
$lab_counter = 1;
while ($row = $res_chart->fetch_assoc()) {
    $chart_labels[] = "ครั้งที่ " . $lab_counter . " (" . date('d/m', strtotime($row['created_at'])) . ")";
    $chart_scores[] = intval($row['final_score']); $chart_hps[] = intval($row['hp_remaining']); $lab_counter++;
}
$stmt_chart->close();

$recent_activities = [];
$stmt_recent = $conn->prepare("SELECT id, final_score, grade, hp_remaining, report_summary, created_at FROM lab_reports WHERE student_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt_recent->bind_param("i", $student_id); $stmt_recent->execute(); $res_recent = $stmt_recent->get_result();
while ($row = $res_recent->fetch_assoc()) { $recent_activities[] = $row; }
$stmt_recent->close();

// ---------------------------------------------------------
// 4. ประกาศจากครู และ สมุดจดงาน (To-Do List)
// ---------------------------------------------------------
$announcements = [];
$stmt_ann = $conn->prepare("SELECT * FROM announcements WHERE target_class_id IS NULL OR target_class_id = ? ORDER BY created_at DESC LIMIT 3");
$stmt_ann->bind_param("i", $class_id); $stmt_ann->execute(); $res_ann = $stmt_ann->get_result();
while ($row = $res_ann->fetch_assoc()) { $announcements[] = $row; }
$stmt_ann->close();

$my_tasks = [];
$stmt_task = $conn->prepare("SELECT * FROM student_tasks WHERE student_id = ? ORDER BY is_completed ASC, created_at DESC");
$stmt_task->bind_param("i", $student_id); $stmt_task->execute(); $res_task = $stmt_task->get_result();
while ($row = $res_task->fetch_assoc()) { $my_tasks[] = $row; }
$stmt_task->close();

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Secular+One&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
    /* =========================================
       CSS ตัวแปร (Variables) สำหรับ Dark/Light Mode
       ========================================= */
    :root {
        --bg-main: #f1f5f9;
        --bg-card: #ffffff;
        --text-main: #0f172a;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --hover-bg: #f8fafc;
        --shadow-color: rgba(0,0,0,0.05);
        --chart-grid: #e2e8f0;
        --input-bg: #ffffff;
    }

    [data-theme="dark"] {
        --bg-main: #0f172a;
        --bg-card: #1e293b;
        --text-main: #f8fafc;
        --text-muted: #94a3b8;
        --border-color: #334155;
        --hover-bg: #0f172a;
        --shadow-color: rgba(0,0,0,0.4);
        --chart-grid: #334155;
        --input-bg: #0f172a;
    }

    body { background-color: var(--bg-main); font-family: 'Prompt', sans-serif; color: var(--text-main); margin: 0; transition: background-color 0.3s ease, color 0.3s ease; }
    .dashboard-wrapper { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    /* Welcome Banner & EXP */
    .welcome-banner { background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%); border-radius: 20px; padding: 40px; color: white; display: flex; align-items: center; gap: 30px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.4); margin-bottom: 30px; position: relative; overflow: hidden; }
    .welcome-banner::before { content: ''; position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(56, 189, 248, 0.2) 0%, transparent 70%); border-radius: 50%; }
    .avatar-wrapper { position: relative; z-index: 2; flex-shrink: 0; }
    .avatar-circle { width: 100px; height: 100px; background: #ffffff; color: #1e3a8a; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: bold; box-shadow: 0 0 20px rgba(56, 189, 248, 0.5); border: 4px solid #38bdf8; }
    .level-badge { position: absolute; bottom: -10px; left: 50%; transform: translateX(-50%); background: #f59e0b; color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold; font-family: 'Secular One', sans-serif; font-size: 1rem; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.3); text-shadow: 1px 1px 2px rgba(0,0,0,0.5); white-space: nowrap; }
    .welcome-text { z-index: 2; flex: 1; }
    .welcome-text h1 { margin: 0 0 5px 0; font-size: 2.2rem; font-weight: 700; color: #f8fafc; text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
    .badge-class { display: inline-block; background: rgba(255,255,255,0.15); padding: 5px 15px; border-radius: 20px; font-weight: bold; margin-top: 5px; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.3); }

    .exp-container { margin-top: 20px; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); }
    .exp-header { display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 8px; color: #94a3b8; font-weight: bold; font-family: 'Secular One', sans-serif; }
    .exp-bar-bg { width: 100%; height: 12px; background: #334155; border-radius: 10px; overflow: hidden; box-shadow: inset 0 2px 5px rgba(0,0,0,0.5); }
    .exp-bar-fill { height: 100%; background: linear-gradient(90deg, #38bdf8, #8b5cf6); border-radius: 10px; transition: width 1s ease-in-out; box-shadow: 0 0 10px rgba(56, 189, 248, 0.8); }

    .section-title { font-size: 1.4rem; font-weight: 700; color: var(--text-main); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; transition: color 0.3s; }

    /* Quick Access */
    .quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .quick-card { background: var(--bg-card); border-radius: 16px; padding: 25px 20px; text-align: center; text-decoration: none; color: var(--text-main); box-shadow: 0 4px 15px var(--shadow-color); border: 1px solid var(--border-color); transition: all 0.3s ease; }
    .quick-card:hover { transform: translateY(-8px); box-shadow: 0 15px 30px var(--shadow-color); border-color: #3b82f6; }
    .card-icon { width: 60px; height: 60px; margin: 0 auto 15px auto; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 2rem; transition: 0.3s; }
    .card-lab .card-icon { background: rgba(59, 130, 246, 0.1); color: #3b82f6; } .card-lab:hover .card-icon { background: #3b82f6; color: white; }
    .card-history .card-icon { background: rgba(124, 58, 237, 0.1); color: #7c3aed; } .card-history:hover .card-icon { background: #7c3aed; color: white; }
    .card-portfolio .card-icon { background: rgba(245, 158, 11, 0.1); color: #f59e0b; } .card-portfolio:hover .card-icon { background: #f59e0b; color: white; }
    .card-profile .card-icon { background: rgba(2, 132, 199, 0.1); color: #0ea5e9; } .card-profile:hover .card-icon { background: #0ea5e9; color: white; }
    .quick-card h3 { margin: 0 0 8px 0; font-size: 1.1rem; color: var(--text-main); }
    .quick-card p { margin: 0; font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; }

    /* Trackers */
    .tracker-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 30px; margin-bottom: 40px; }
    .tracker-panel { background: var(--bg-card); border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px var(--shadow-color); border: 1px solid var(--border-color); display: flex; flex-direction: column; transition: 0.3s; }
    .tracker-panel h3 { margin: 0 0 20px 0; font-size: 1.2rem; color: var(--text-main); border-bottom: 2px solid var(--border-color); padding-bottom: 10px; display: flex; align-items: center; gap: 10px; }
    
    .ann-list { display: flex; flex-direction: column; gap: 15px; overflow-y: auto; max-height: 350px; padding-right: 5px; }
    .ann-card { background: var(--hover-bg); border-left: 4px solid #3b82f6; padding: 15px; border-radius: 0 10px 10px 0; border-top: 1px solid var(--border-color); border-right: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); }
    .ann-title { font-weight: bold; color: var(--text-main); font-size: 1.05rem; margin-bottom: 5px; }
    .ann-msg { color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; margin-bottom: 10px; }
    .ann-meta { display: flex; justify-content: space-between; color: var(--text-muted); font-size: 0.8rem; border-top: 1px dashed var(--border-color); padding-top: 8px; }

    .todo-input-group { display: flex; gap: 10px; margin-bottom: 15px; }
    .todo-input-group input { flex: 1; padding: 12px 15px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); outline: none; font-family: inherit; font-size: 0.95rem; }
    .todo-input-group input:focus { border-color: #8b5cf6; }
    .todo-input-group button { background: #8b5cf6; color: white; border: none; padding: 0 20px; border-radius: 8px; cursor: pointer; font-weight: bold; transition: 0.2s; font-size: 1.2rem; }
    
    .todo-list { list-style: none; padding: 0; margin: 0; overflow-y: auto; max-height: 300px; padding-right: 5px; }
    .todo-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 10px; transition: 0.2s; }
    .todo-item:hover { border-color: #8b5cf6; }
    .todo-item.completed { background: var(--hover-bg); opacity: 0.7; }
    .todo-item.completed .todo-text { text-decoration: line-through; color: var(--text-muted); }
    .todo-left { display: flex; align-items: center; gap: 12px; flex: 1; cursor: pointer; }
    .todo-text { font-size: 0.95rem; color: var(--text-main); user-select: none; }
    .btn-delete-task { background: transparent; border: none; color: #ef4444; font-size: 1.1rem; cursor: pointer; opacity: 0.5; padding: 5px; }
    .todo-item:hover .btn-delete-task { opacity: 1; }

    /* Badges */
    .achievements-container { background: var(--bg-card); border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px var(--shadow-color); border: 1px solid var(--border-color); margin-bottom: 40px; transition: 0.3s; }
    .badges-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    .badge-card { background: var(--hover-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px 15px; text-align: center; transition: 0.3s; position: relative; overflow: hidden; display: flex; flex-direction: column; align-items: center; }
    .badge-card.unlocked { background: linear-gradient(to bottom, var(--bg-card), rgba(56, 189, 248, 0.1)); border-color: #38bdf8; box-shadow: 0 4px 15px rgba(56, 189, 248, 0.15); transform: translateY(-3px); }
    .badge-card.unlocked::before { content: 'ปลดล็อกแล้ว'; position: absolute; top: 10px; right: -25px; background: #10b981; color: white; font-size: 0.7rem; font-weight: bold; padding: 3px 30px; transform: rotate(45deg); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
    .badge-card.locked { filter: grayscale(100%); opacity: 0.4; }
    .badge-icon { font-size: 3.5rem; margin-bottom: 10px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2)); }
    .badge-card.unlocked .badge-icon { filter: drop-shadow(0 0 15px rgba(250, 204, 21, 0.8)); }
    .badge-name { font-weight: 700; color: var(--text-main); margin-bottom: 5px; font-size: 1.05rem; }
    .badge-desc { font-size: 0.85rem; color: var(--text-muted); line-height: 1.4; }

    /* Stats & Charts */
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: var(--bg-card); padding: 20px; border-radius: 16px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px var(--shadow-color); border: 1px solid var(--border-color); transition: 0.3s; }
    .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; }
    .icon-blue { background: rgba(59, 130, 246, 0.1); color: #3b82f6; } .icon-green { background: rgba(16, 185, 129, 0.1); color: #10b981; } .icon-purple { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; }
    .stat-info h4 { margin: 0; font-size: 0.9rem; color: var(--text-muted); font-weight: normal; }
    .stat-info .value { margin: 5px 0 0 0; font-size: 1.8rem; font-weight: bold; color: var(--text-main); }

    .content-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 30px; margin-bottom: 40px; }
    .panel-card { background: var(--bg-card); border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px var(--shadow-color); border: 1px solid var(--border-color); transition: 0.3s; }
    .panel-card h3 { margin: 0 0 20px 0; font-size: 1.2rem; color: var(--text-main); border-bottom: 2px solid var(--border-color); padding-bottom: 10px; }
    .chart-container { position: relative; height: 300px; width: 100%; }

    /* Table */
    .table-responsive { overflow-x: auto; }
    .styled-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; color: var(--text-main); }
    .styled-table th, .styled-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
    .styled-table th { background-color: var(--hover-bg); color: var(--text-muted); font-weight: 600; white-space: nowrap; }
    .styled-table tbody tr:hover { background-color: var(--hover-bg); }
    .styled-table tbody tr:last-of-type td { border-bottom: none; }
    
    .grade-badge { padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; display: inline-block; text-align: center; min-width: 30px; }
    .grade-A { background: rgba(22, 101, 52, 0.2); color: #22c55e; border: 1px solid #22c55e; } 
    .grade-B { background: rgba(30, 64, 175, 0.2); color: #3b82f6; border: 1px solid #3b82f6; } 
    .grade-C { background: rgba(180, 83, 9, 0.2); color: #eab308; border: 1px solid #eab308; } 
    .grade-D { background: rgba(194, 65, 12, 0.2); color: #f97316; border: 1px solid #f97316; } 
    .grade-F { background: rgba(153, 27, 27, 0.2); color: #ef4444; border: 1px solid #ef4444; }

    .hp-text { font-family: monospace; font-weight: bold; }
    .hp-good { color: #10b981; } .hp-warn { color: #f59e0b; } .hp-bad { color: #ef4444; }

    /* --- Theme Toggle & Logout Float Buttons --- */
    .floating-btns { position: fixed; bottom: 30px; right: 30px; display: flex; flex-direction: column; gap: 15px; z-index: 100; }
    .float-btn { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; border: none; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.3); transition: 0.3s; text-decoration: none; }
    .float-btn:hover { transform: scale(1.1); }
    .btn-theme { background: #334155; }
    .btn-logout { background: #ef4444; }

    /* --- PDF Portfolio Template (ซ่อนไว้สำหรับพิมพ์) --- */
    #portfolio-export-area {
        display: none; /* ซ่อนตอนใช้งานปกติ */
        background: white; color: black; padding: 40px; font-family: 'Prompt', sans-serif;
        width: 794px; /* A4 width in pixels approx */
        min-height: 1123px; /* A4 height */
        box-sizing: border-box; margin: 0 auto;
    }
    .pdf-header { text-align: center; border-bottom: 4px solid #1e3a8a; padding-bottom: 20px; margin-bottom: 30px; }
    .pdf-header h1 { color: #1e3a8a; margin: 0 0 10px 0; font-size: 28px; }
    .pdf-header h2 { color: #475569; margin: 0; font-size: 20px; font-weight: normal; }
    .pdf-profile { display: flex; gap: 20px; margin-bottom: 30px; align-items: center; }
    .pdf-info { flex: 1; font-size: 16px; line-height: 1.6; }
    .pdf-box { border: 2px solid #e2e8f0; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
    .pdf-box h3 { color: #1e3a8a; margin-top: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
    .pdf-badges { display: flex; gap: 15px; flex-wrap: wrap; }
    .pdf-badge-item { border: 1px solid #cbd5e1; padding: 10px; border-radius: 8px; text-align: center; width: 120px; }
    
    /* Loading Overlay สำหรับ Export */
    #export-loading { position: fixed; top:0; left:0; width:100vw; height:100vh; background: rgba(0,0,0,0.8); color: white; display: none; align-items: center; justify-content: center; z-index: 9999; flex-direction: column; }
    .spinner { border: 8px solid rgba(255,255,255,0.3); border-top: 8px solid #38bdf8; border-radius: 50%; width: 60px; height: 60px; animation: spin 1s linear infinite; margin-bottom: 20px; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    @media (max-width: 992px) { .content-grid, .tracker-grid { grid-template-columns: 1fr; } }
    @media (max-width: 768px) { .welcome-banner { flex-direction: column; text-align: center; padding: 30px 20px; } .stats-grid { grid-template-columns: 1fr; } }
</style>

<div id="export-loading">
    <div class="spinner"></div>
    <h2>กำลังจัดทำแฟ้มสะสมผลงาน (PDF)...</h2>
    <p>กรุณารอสักครู่ ห้ามปิดหน้าต่างนี้</p>
</div>

<div id="portfolio-export-area">
    <div class="pdf-header">
        <h1>โรงเรียนบ้านคาวิทยา</h1>
        <h2>แฟ้มสะสมผลงาน (Virtual Lab Transcript)</h2>
    </div>
    
    <div class="pdf-profile">
        <div style="width: 100px; height: 100px; background: #f1f5f9; border: 2px solid #cbd5e1; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 40px;">
            <?= h($avatar_letter) ?>
        </div>
        <div class="pdf-info">
            <strong>ชื่อ-นามสกุล:</strong> <?= h($display_name) ?> (<?= h($username) ?>)<br>
            <strong>ระดับชั้น:</strong> <?= h($class_name) ?><br>
            <strong>วันที่ออกเอกสาร:</strong> <?= date('d/m/Y') ?><br>
            <strong>ระดับผู้ใช้งาน (Level):</strong> เลเวล <?= $current_level ?> (<?= number_format($total_xp) ?> EXP)
        </div>
    </div>

    <div class="pdf-box">
        <h3>📊 สรุปผลสัมฤทธิ์ทางการทดลอง (Academic Summary)</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 16px;">
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">จำนวนการทดลองที่สำเร็จ:</td>
                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold; text-align: right;"><?= $total_labs ?> ครั้ง</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;">คะแนนเฉลี่ยสะสม:</td>
                <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold; text-align: right; color: #2563eb;"><?= number_format($avg_score, 1) ?> / 100</td>
            </tr>
            <tr>
                <td style="padding: 10px;">ระดับคุณภาพสูงสุด (Best Grade):</td>
                <td style="padding: 10px; font-weight: bold; text-align: right; font-size: 20px; color: #166534;"><?= $best_grade ?></td>
            </tr>
        </table>
    </div>

    <div class="pdf-box">
        <h3>🏆 เหรียญเกียรติยศ (Achievements Unlocked)</h3>
        <?php if (count($unlocked_keys) > 0): ?>
            <div class="pdf-badges">
                <?php foreach ($all_badges as $key => $b): ?>
                    <?php if (in_array($key, $unlocked_keys)): ?>
                        <div class="pdf-badge-item">
                            <div style="font-size: 30px; margin-bottom: 5px;"><?= $b['icon'] ?></div>
                            <div style="font-weight: bold; font-size: 12px;"><?= $b['name'] ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="color: #64748b;">ยังไม่มีเหรียญรางวัลในขณะนี้</p>
        <?php endif; ?>
    </div>

    <div class="pdf-box" style="border: none; padding: 0;">
        <h3>🕒 ประวัติการทำปฏิบัติการล่าสุด</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; text-align: left;">
            <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                <th style="padding: 10px;">วันที่-เวลา</th>
                <th style="padding: 10px;">คะแนน</th>
                <th style="padding: 10px;">เกรด</th>
                <th style="padding: 10px;">สถานะความปลอดภัย</th>
            </tr>
            <?php foreach ($recent_activities as $act): ?>
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 10px;"><?= date('d/m/Y H:i', strtotime($act['created_at'])) ?></td>
                    <td style="padding: 10px; font-weight: bold;"><?= $act['final_score'] ?></td>
                    <td style="padding: 10px; font-weight: bold;"><?= $act['grade'] ?></td>
                    <td style="padding: 10px;"><?= $act['hp_remaining'] ?>% HP</td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($recent_activities) == 0): ?>
                <tr><td colspan="4" style="text-align: center; padding: 20px;">ไม่มีประวัติการทดลอง</td></tr>
            <?php endif; ?>
        </table>
    </div>

    <div style="text-align: center; margin-top: 50px; color: #94a3b8; font-size: 12px;">
        เอกสารฉบับนี้สร้างโดยระบบ Bankha Virtual Lab (รับรองผลอัตโนมัติโดยระบบ)
    </div>
</div>
<div class="dashboard-wrapper">
    
    <div class="welcome-banner">
        <div class="avatar-wrapper">
            <div class="avatar-circle"><?= h($avatar_letter) ?></div>
            <div class="level-badge">LVL <?= $current_level ?></div>
        </div>
        <div class="welcome-text">
            <p><?= $greeting_icon ?> <?= $greeting ?>,</p>
            <h1><?= h($display_name) ?></h1>
            <div class="badge-class">🏫 ระดับชั้น: <?= h($class_name) ?></div>
            
            <div class="exp-container">
                <div class="exp-header">
                    <span>⚡ EXP Progress</span>
                    <span><?= number_format($total_xp) ?> / <?= number_format($target_xp_for_next) ?> XP</span>
                </div>
                <div class="exp-bar-bg"><div class="exp-bar-fill" style="width: <?= $xp_percent ?>%;"></div></div>
            </div>
        </div>
    </div>

    <div class="section-title">🚀 เมนูด่วน (Quick Access)</div>
    <div class="quick-grid">
        <a href="lab_realistic.php" class="quick-card card-lab">
            <div class="card-icon">🥽</div><h3>เข้าห้องทดลอง (Virtual Lab)</h3><p>เริ่มการทดลองเคมีแบบสมจริง เก็บแต้ม EXP และปลดล็อกเหรียญ</p>
        </a>
        <a href="#recent-acts" class="quick-card card-history">
            <div class="card-icon">📋</div><h3>ประวัติการส่งรายงาน</h3><p>ดูผลคะแนนและข้อเสนอแนะจากการทดลองครั้งที่ผ่านมา</p>
        </a>
        <a href="#" class="quick-card card-portfolio" onclick="exportPortfolioPDF(); return false;">
            <div class="card-icon">🎓</div><h3>แฟ้มสะสมผลงาน (PDF)</h3><p>ส่งออกผลงานเป็น PDF เพื่อนำไปยื่น Portfolio เข้ามหาวิทยาลัย</p>
        </a>
        <a href="#task-tracker" class="quick-card card-profile">
            <div class="card-icon">📅</div><h3>ตารางงานของฉัน</h3><p>ดูประกาศจากครูและจดบันทึกสิ่งที่ต้องทำ (To-Do)</p>
        </a>
    </div>

    <div class="tracker-grid" id="task-tracker">
        <div class="tracker-panel">
            <h3>📢 ประกาศจากครูผู้สอน</h3>
            <div class="ann-list">
                <?php if (count($announcements) > 0): ?>
                    <?php foreach ($announcements as $ann): ?>
                        <div class="ann-card">
                            <div class="ann-title"><?= h($ann['title']) ?></div>
                            <div class="ann-msg"><?= nl2br(h($ann['message'])) ?></div>
                            <div class="ann-meta"><span>✍️ <?= h($ann['author_name']) ?></span><span>🕒 <?= date('d/m/Y H:i', strtotime($ann['created_at'])) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="padding: 20px;"><span style="font-size: 2rem;">📭</span><h4 style="margin:0; color:var(--text-muted);">ไม่มีประกาศใหม่</h4></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="tracker-panel">
            <h3>📝 สมุดจดงาน (To-Do List)</h3>
            <div class="todo-input-group">
                <input type="text" id="newTaskInput" placeholder="พิมพ์งานที่ต้องทำ..." onkeypress="if(event.key === 'Enter') addTask()">
                <button onclick="addTask()">+</button>
            </div>
            <ul class="todo-list" id="todoList">
                <?php if (count($my_tasks) > 0): ?>
                    <?php foreach ($my_tasks as $task): ?>
                        <li class="todo-item <?= $task['is_completed'] ? 'completed' : '' ?>" id="task-<?= $task['id'] ?>">
                            <div class="todo-left" onclick="toggleTask(<?= $task['id'] ?>, <?= $task['is_completed'] ? 0 : 1 ?>)">
                                <input type="checkbox" <?= $task['is_completed'] ? 'checked' : '' ?> onclick="event.stopPropagation(); toggleTask(<?= $task['id'] ?>, <?= $task['is_completed'] ? 0 : 1 ?>)">
                                <span class="todo-text"><?= h($task['task_text']) ?></span>
                            </div>
                            <button class="btn-delete-task" onclick="deleteTask(<?= $task['id'] ?>)" title="ลบงาน">🗑️</button>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" id="emptyTaskState" style="padding: 20px;"><span style="font-size: 2rem;">✨</span><h4 style="margin:0; color:var(--text-muted); font-size:1rem;">เย้! ไม่มีงานค้าง</h4></div>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="section-title">🏆 เหรียญรางวัลแห่งความสำเร็จ</div>
    <div class="achievements-container">
        <div class="badges-grid">
            <?php foreach ($all_badges as $key => $b_data): ?>
                <?php $is_unlocked = in_array($key, $unlocked_keys); $card_class = $is_unlocked ? "unlocked" : "locked"; ?>
                <div class="badge-card <?= $card_class ?>" title="<?= h($b_data['desc']) ?>">
                    <div class="badge-icon"><?= $b_data['icon'] ?></div>
                    <div class="badge-name"><?= $b_data['name'] ?></div>
                    <div class="badge-desc"><?= $b_data['desc'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="section-title">📊 สรุปผลการเรียนรู้</div>
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon icon-blue">🧪</div><div class="stat-info"><h4>จำนวนครั้งที่ทดลอง</h4><div class="value"><?= number_format($total_labs) ?> <span style="font-size:1rem; color:var(--text-muted); font-weight:normal;">ครั้ง</span></div></div></div>
        <div class="stat-card"><div class="stat-icon icon-green">🎯</div><div class="stat-info"><h4>คะแนนเฉลี่ย</h4><div class="value"><?= number_format($avg_score, 1) ?> <span style="font-size:1rem; color:var(--text-muted); font-weight:normal;">/ 100</span></div></div></div>
        <div class="stat-card"><div class="stat-icon icon-purple">👑</div><div class="stat-info"><h4>เกรดสูงสุดที่ทำได้</h4><div class="value" style="color: <?= $best_grade === 'A' ? '#10b981' : ($best_grade === 'F' ? '#ef4444' : '#8b5cf6') ?>;"><?= h($best_grade) ?></div></div></div>
    </div>

    <div class="content-grid" id="recent-acts">
        <div class="panel-card">
            <h3>📈 กราฟพัฒนาการ (Performance)</h3>
            <?php if ($total_labs > 0): ?>
                <div class="chart-container"><canvas id="performanceChart"></canvas></div>
            <?php else: ?>
                <div class="empty-state"><span>📉</span><h4 style="margin: 0; color: var(--text-muted);">ยังไม่มีข้อมูล</h4></div>
            <?php endif; ?>
        </div>

        <div class="panel-card">
            <h3>🕒 ประวัติการทำแล็บล่าสุด</h3>
            <?php if (count($recent_activities) > 0): ?>
                <div class="table-responsive">
                    <table class="styled-table">
                        <thead><tr><th>วันที่</th><th style="text-align: center;">คะแนน</th><th style="text-align: center;">เกรด</th><th style="text-align: center;">HP</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_activities as $act): ?>
                                <?php 
                                    $date_format = date('d/m/Y', strtotime($act['created_at']));
                                    $g_class = "grade-F"; if ($act['grade'] == 'A') $g_class = "grade-A"; elseif ($act['grade'] == 'B') $g_class = "grade-B"; elseif ($act['grade'] == 'C') $g_class = "grade-C"; elseif ($act['grade'] == 'D') $g_class = "grade-D";
                                    $hp = intval($act['hp_remaining']); $hp_class = "hp-good"; if ($hp <= 30) $hp_class = "hp-bad"; elseif ($hp <= 60) $hp_class = "hp-warn";
                                ?>
                                <tr>
                                    <td style="color: var(--text-muted); font-size: 0.85rem;"><?= $date_format ?></td>
                                    <td style="text-align: center; font-weight: bold;"><?= $act['final_score'] ?></td>
                                    <td style="text-align: center;"><span class="grade-badge <?= $g_class ?>"><?= $act['grade'] ?></span></td>
                                    <td style="text-align: center;"><span class="hp-text <?= $hp_class ?>"><?= $hp ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>📭</span><h4 style="margin: 0; color: var(--text-muted);">คุณยังไม่เคยส่งรายงาน</h4></div>
            <?php endif; ?>
        </div>
    </div>

</div>

<div class="floating-btns">
    <button class="float-btn btn-theme" onclick="toggleTheme()" title="สลับโหมดมืด/สว่าง" id="themeIcon">🌙</button>
    <a href="logout.php" class="float-btn btn-logout" title="ออกจากระบบ">🚪</a>
</div>

<input type="hidden" id="csrfToken" value="<?= h($csrf) ?>">

<script>
    // =========================================
    // 🌗 ระบบ Dark/Light Mode Theme Toggle
    // =========================================
    function toggleTheme() {
        const body = document.body;
        const icon = document.getElementById('themeIcon');
        if (body.getAttribute('data-theme') === 'dark') {
            body.removeAttribute('data-theme');
            localStorage.setItem('theme', 'light');
            icon.innerText = '🌙'; // กลับไปเป็นพระจันทร์เพื่อกดเปลี่ยนเป็นกลางคืน
            updateChartTheme(false);
        } else {
            body.setAttribute('data-theme', 'dark');
            localStorage.setItem('theme', 'dark');
            icon.innerText = '☀️'; // เปลี่ยนเป็นพระอาทิตย์เพื่อกดกลับ
            updateChartTheme(true);
        }
    }

    // โหลด Theme เดิมตอนเปิดเว็บ
    if (localStorage.getItem('theme') === 'dark') {
        document.body.setAttribute('data-theme', 'dark');
        document.getElementById('themeIcon').innerText = '☀️';
    }

    // =========================================
    // 📄 ระบบ Export PDF (html2pdf)
    // =========================================
    function exportPortfolioPDF() {
        // แสดง Loading Screen
        document.getElementById('export-loading').style.display = 'flex';
        
        // เอา div แม่แบบ A4 ออกมาให้ html2pdf จับภาพ
        const element = document.getElementById('portfolio-export-area');
        element.style.display = 'block';

        // ตั้งค่าหน้ากระดาษ
        const opt = {
            margin:       0,
            filename:     'Portfolio_<?= h($username) ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        // สั่งสร้าง PDF
        html2pdf().set(opt).from(element).save().then(() => {
            // เมื่อเสร็จแล้ว ปิด Loading และซ่อนเทมเพลตกลับไปเหมือนเดิม
            element.style.display = 'none';
            document.getElementById('export-loading').style.display = 'none';
        });
    }

    // =========================================
    // ระบบ To-Do List (AJAX)
    // =========================================
    const csrfToken = document.getElementById('csrfToken').value;

    function addTask() {
        const input = document.getElementById('newTaskInput'); const text = input.value.trim(); if(!text) return;
        fetch('api_tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'add', task_text: text, csrf_token: csrfToken }) })
        .then(res => res.json()).then(data => {
            if(data.status === 'success') {
                input.value = ''; let emptyState = document.getElementById('emptyTaskState'); if(emptyState) emptyState.remove();
                const ul = document.getElementById('todoList'); const li = document.createElement('li');
                li.className = 'todo-item'; li.id = 'task-' + data.id;
                li.innerHTML = `<div class="todo-left" onclick="toggleTask(${data.id}, 1)"><input type="checkbox" onclick="event.stopPropagation(); toggleTask(${data.id}, 1)"><span class="todo-text">${data.text}</span></div><button class="btn-delete-task" onclick="deleteTask(${data.id})" title="ลบงาน">🗑️</button>`;
                ul.insertBefore(li, ul.firstChild);
            } else alert(data.message);
        });
    }

    function toggleTask(taskId, newStatus) {
        fetch('api_tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'toggle', task_id: taskId, is_completed: newStatus, csrf_token: csrfToken }) })
        .then(res => res.json()).then(data => {
            if(data.status === 'success') {
                const li = document.getElementById('task-' + taskId); const cb = li.querySelector('input[type="checkbox"]'); const tl = li.querySelector('.todo-left');
                if(newStatus === 1) { li.classList.add('completed'); cb.checked = true; tl.setAttribute('onclick', `toggleTask(${taskId}, 0)`); cb.setAttribute('onclick', `event.stopPropagation(); toggleTask(${taskId}, 0)`); } 
                else { li.classList.remove('completed'); cb.checked = false; tl.setAttribute('onclick', `toggleTask(${taskId}, 1)`); cb.setAttribute('onclick', `event.stopPropagation(); toggleTask(${taskId}, 1)`); }
            }
        });
    }

    function deleteTask(taskId) {
        if(!confirm('ลบงานนี้ใช่หรือไม่?')) return;
        fetch('api_tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', task_id: taskId, csrf_token: csrfToken }) })
        .then(res => res.json()).then(data => { if(data.status === 'success') { const li = document.getElementById('task-' + taskId); li.style.transform = "scale(0)"; setTimeout(() => li.remove(), 200); } });
    }
</script>

<?php if ($total_labs > 0): ?>
<script>
    // =========================================
    // กราฟ Chart.js (รองรับ Dark Mode)
    // =========================================
    let performanceChart;
    document.addEventListener("DOMContentLoaded", function() {
        const chartLabels = <?= json_encode($chart_labels) ?>; const chartScores = <?= json_encode($chart_scores) ?>; const chartHps = <?= json_encode($chart_hps) ?>;
        const ctx = document.getElementById('performanceChart').getContext('2d');
        
        let gradientScore = ctx.createLinearGradient(0, 0, 0, 300); gradientScore.addColorStop(0, 'rgba(59, 130, 246, 0.5)'); gradientScore.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
        let gradientHp = ctx.createLinearGradient(0, 0, 0, 300); gradientHp.addColorStop(0, 'rgba(16, 185, 129, 0.5)'); gradientHp.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        // สี Grid เริ่มต้น
        let isDark = document.body.getAttribute('data-theme') === 'dark';
        let gridColor = isDark ? '#334155' : '#e2e8f0';
        let textColor = isDark ? '#94a3b8' : '#64748b';

        performanceChart = new Chart(ctx, { type: 'line', data: { labels: chartLabels, datasets: [ { label: 'คะแนน (Score)', data: chartScores, borderColor: '#3b82f6', backgroundColor: gradientScore, borderWidth: 3, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, fill: true, tension: 0.4 }, { label: 'ความปลอดภัย (HP)', data: chartHps, borderColor: '#10b981', backgroundColor: gradientHp, borderWidth: 3, borderDash: [5, 5], pointBackgroundColor: '#ffffff', pointBorderColor: '#10b981', pointBorderWidth: 2, fill: true, tension: 0.4 } ] }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', labels: { font: { family: "'Prompt', sans-serif" }, color: textColor, usePointStyle: true, boxWidth: 8 } } }, scales: { x: { grid: { display: false }, ticks: { font: { family: "'Prompt', sans-serif", size: 11 }, color: textColor } }, y: { min: 0, max: 100, grid: { color: gridColor, borderDash: [5, 5] }, ticks: { font: { family: "'Prompt', sans-serif", size: 11 }, color: textColor, stepSize: 20 } } } } });
    });

    // อัปเดตสีกราฟเมื่อสลับโหมด
    function updateChartTheme(isDark) {
        if(!performanceChart) return;
        let gridColor = isDark ? '#334155' : '#e2e8f0';
        let textColor = isDark ? '#94a3b8' : '#64748b';
        performanceChart.options.scales.y.grid.color = gridColor;
        performanceChart.options.scales.x.ticks.color = textColor;
        performanceChart.options.scales.y.ticks.color = textColor;
        performanceChart.options.plugins.legend.labels.color = textColor;
        performanceChart.update();
    }
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>