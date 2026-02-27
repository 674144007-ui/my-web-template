<?php
// teacher_student_track.php - ระบบติดตามผู้เรียนรายบุคคล (Phase 6)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// บังคับสิทธิ์ครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$page_title = "ติดตามพัฒนาการผู้เรียน";
$teacher_id = $_SESSION['user_id'];
$csrf = generate_csrf_token();

// ---------------------------------------------------------
// 1. ดึงรายชื่อห้องเรียนสำหรับ Dropdown กรองข้อมูล
// ---------------------------------------------------------
$classes = [];
$res_classes = $conn->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// ---------------------------------------------------------
// 2. จัดการโหมดแสดงผล (List: ดูทุกคน | Detail: เจาะจงเด็ก 1 คน)
// ---------------------------------------------------------
$view_mode = 'list';
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$all_badges = [
    'first_blood' => ['icon' => '🧪', 'name' => 'นักเคมีฝึกหัด', 'desc' => 'ส่งรายงานการทดลองครั้งแรกสำเร็จ'],
    'perfect_score' => ['icon' => '💯', 'name' => 'ผลงานไร้ที่ติ', 'desc' => 'ทำคะแนนการทดลองได้ 100 คะแนนเต็ม'],
    'safety_master' => ['icon' => '🛡️', 'name' => 'เซฟตี้มาสเตอร์', 'desc' => 'ทำการทดลองโดย HP ไม่ลดเลย'],
    'mad_scientist' => ['icon' => '💥', 'name' => 'นักวิทย์สติเฟื่อง', 'desc' => 'ทำแล็บระเบิด หรือ HP ต่ำกว่า 30%'],
    'veteran' => ['icon' => '🎖️', 'name' => 'ผู้เชี่ยวชาญ', 'desc' => 'ส่งรายงานการทดลองครบ 10 ครั้ง']
];

if ($student_id > 0) {
    // ===================================================
    // โหมดเจาะลึก (DETAIL MODE) - ดูข้อมูลเด็กรายบุคคล
    // ===================================================
    $view_mode = 'detail';
    
    // ดึงข้อมูลส่วนตัวเด็ก
    $stmt_std = $conn->prepare("SELECT u.id, u.username, u.display_name, c.class_name FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.id = ? AND u.role = 'student'");
    $stmt_std->bind_param("i", $student_id);
    $stmt_std->execute();
    $res_std = $stmt_std->get_result();
    
    if ($res_std->num_rows === 0) {
        header("Location: teacher_student_track.php"); // ถ้าไม่เจอเด็ก ให้กลับหน้ารวม
        exit;
    }
    $student_info = $res_std->fetch_assoc();
    $stmt_std->close();

    // ดึงสถิติรวมของเด็กคนนี้
    $stats = ['total_labs' => 0, 'avg_score' => 0, 'accidents' => 0, 'best_grade' => '-'];
    $stmt_stats = $conn->prepare("
        SELECT COUNT(id) as total_labs, AVG(final_score) as avg_score, MAX(final_score) as max_score, 
               SUM(CASE WHEN hp_remaining < 50 THEN 1 ELSE 0 END) as accidents 
        FROM lab_reports WHERE student_id = ?
    ");
    $stmt_stats->bind_param("i", $student_id);
    $stmt_stats->execute();
    $res_stats = $stmt_stats->get_result();
    if ($row = $res_stats->fetch_assoc()) {
        $stats['total_labs'] = intval($row['total_labs']);
        $stats['avg_score'] = round(floatval($row['avg_score']), 1);
        $stats['accidents'] = intval($row['accidents']);
        
        $max_score = intval($row['max_score']);
        if ($stats['total_labs'] > 0) {
            if ($max_score >= 80) $stats['best_grade'] = 'A';
            elseif ($max_score >= 70) $stats['best_grade'] = 'B';
            elseif ($max_score >= 60) $stats['best_grade'] = 'C';
            elseif ($max_score >= 50) $stats['best_grade'] = 'D';
            else $stats['best_grade'] = 'F';
        }
    }
    $stmt_stats->close();

    // ประเมินความเสี่ยง (At-Risk Status)
    $is_at_risk = false;
    $risk_reason = "";
    if ($stats['total_labs'] > 0) {
        if ($stats['avg_score'] < 50) { $is_at_risk = true; $risk_reason = "คะแนนเฉลี่ยต่ำกว่า 50 (ไม่ผ่านเกณฑ์)"; }
        elseif ($stats['accidents'] >= 2) { $is_at_risk = true; $risk_reason = "เกิดอุบัติเหตุร้ายแรงในห้องแล็บบ่อยครั้ง"; }
    }

    // ดึงเหรียญรางวัล (Badges) ที่ปลดล็อกแล้ว
    $unlocked_badges = [];
    $stmt_b = $conn->prepare("SELECT badge_key FROM student_badges WHERE student_id = ?");
    $stmt_b->bind_param("i", $student_id);
    $stmt_b->execute();
    $res_b = $stmt_b->get_result();
    while ($b = $res_b->fetch_assoc()) { $unlocked_badges[] = $b['badge_key']; }
    $stmt_b->close();

    // ดึงข้อมูลกราฟ (10 ครั้งล่าสุด)
    $chart_labels = []; $chart_scores = []; $chart_hps = [];
    $stmt_chart = $conn->prepare("SELECT final_score, hp_remaining, created_at FROM (SELECT final_score, hp_remaining, created_at FROM lab_reports WHERE student_id = ? ORDER BY created_at DESC LIMIT 10) sub ORDER BY created_at ASC");
    $stmt_chart->bind_param("i", $student_id);
    $stmt_chart->execute();
    $res_chart = $stmt_chart->get_result();
    $lab_counter = 1;
    while ($row = $res_chart->fetch_assoc()) {
        $chart_labels[] = "ครั้งที่ " . $lab_counter . " (" . date('d/m', strtotime($row['created_at'])) . ")";
        $chart_scores[] = intval($row['final_score']);
        $chart_hps[] = intval($row['hp_remaining']);
        $lab_counter++;
    }
    $stmt_chart->close();

    // ดึงประวัติการส่งงานทั้งหมดของเด็กคนนี้ (ลงตาราง)
    $student_reports = [];
    $stmt_rep = $conn->prepare("SELECT id, final_score, grade, hp_remaining, created_at, teacher_comment FROM lab_reports WHERE student_id = ? ORDER BY created_at DESC");
    $stmt_rep->bind_param("i", $student_id);
    $stmt_rep->execute();
    $res_rep = $stmt_rep->get_result();
    while ($row = $res_rep->fetch_assoc()) { $student_reports[] = $row; }
    $stmt_rep->close();

} else {
    // ===================================================
    // โหมดหน้ารวม (LIST MODE) - ค้นหาและดูภาพรวมทุกคน
    // ===================================================
    $search_text = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
    $filter_risk = isset($_GET['risk']) ? intval($_GET['risk']) : 0; // 1 = ดูเฉพาะเด็กเสี่ยง

    // สร้างคำสั่ง SQL แบบ Dynamic
    $sql = "
        SELECT u.id, u.username, u.display_name, c.class_name,
               COUNT(lr.id) as total_labs,
               AVG(lr.final_score) as avg_score,
               SUM(CASE WHEN lr.hp_remaining < 50 THEN 1 ELSE 0 END) as accidents
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN lab_reports lr ON u.id = lr.student_id
        WHERE u.role = 'student' AND u.is_deleted = 0
    ";
    
    $params = []; $types = "";

    if ($search_text !== '') {
        $sql .= " AND (u.display_name LIKE ? OR u.username LIKE ?)";
        $like_search = "%{$search_text}%";
        $params[] = $like_search; $params[] = $like_search; $types .= "ss";
    }
    if ($filter_class > 0) {
        $sql .= " AND u.class_id = ?";
        $params[] = $filter_class; $types .= "i";
    }

    $sql .= " GROUP BY u.id ORDER BY u.display_name ASC";

    $stmt = $conn->prepare($sql);
    if ($types !== "") { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result_students = $stmt->get_result();
    $all_students = [];
    
    while ($row = $result_students->fetch_assoc()) {
        // ประเมิน At-Risk สดๆ
        $is_risk = false;
        if ($row['total_labs'] > 0 && ($row['avg_score'] < 50 || $row['accidents'] >= 2)) {
            $is_risk = true;
        }

        // ถ้าเลือก filter เฉพาะเด็กเสี่ยง แล้วคนนี้ไม่เสี่ยง ให้ข้ามไป
        if ($filter_risk == 1 && !$is_risk) continue;
        
        $row['is_risk'] = $is_risk;
        $all_students[] = $row;
    }
    $stmt->close();
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* =========================================
       CSS สำหรับ Student Tracking (Phase 6)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .track-wrapper { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #0f766e; display: flex; align-items: center; gap: 10px; }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* =========================================
       สไตล์สำหรับหน้ารายชื่อ (List Mode)
       ========================================= */
    .toolbar-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 30px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; }
    .search-input { flex: 2; min-width: 200px; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 0.95rem; }
    .search-input:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    .filter-select { flex: 1; min-width: 150px; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; background: white; }
    
    .chk-risk-wrapper { display: flex; align-items: center; gap: 8px; font-weight: bold; color: #ef4444; background: #fee2e2; padding: 10px 15px; border-radius: 8px; border: 1px solid #fecaca; cursor: pointer; user-select: none;}
    .chk-risk-wrapper input { width: 18px; height: 18px; accent-color: #ef4444; cursor: pointer; }

    .btn-filter { background: #0f766e; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: inherit; font-size: 0.95rem; }
    .btn-filter:hover { background: #0d9488; }
    .btn-clear { background: transparent; color: #64748b; border: 1px solid #cbd5e1; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; text-decoration: none; transition: 0.3s; }
    .btn-clear:hover { background: #f1f5f9; color: #0f172a;}

    .student-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
    .student-card { background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); transition: 0.3s; position: relative; overflow: hidden; display: flex; flex-direction: column; text-decoration: none; color: inherit;}
    .student-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); border-color: #cbd5e1; }
    
    .std-header { display: flex; gap: 15px; align-items: center; margin-bottom: 15px; }
    .std-avatar { width: 50px; height: 50px; background: #e0f2fe; color: #0284c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.5rem; flex-shrink: 0;}
    .std-info h3 { margin: 0 0 5px 0; font-size: 1.15rem; color: #1e293b; }
    .std-info p { margin: 0; color: #64748b; font-size: 0.85rem; }

    .std-stats { display: flex; justify-content: space-between; border-top: 1px dashed #e2e8f0; padding-top: 15px; margin-bottom: 10px;}
    .s-box { text-align: center; }
    .s-box span { display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 3px;}
    .s-box strong { font-size: 1.2rem; color: #0f172a; }

    .risk-badge { position: absolute; top: 15px; right: -30px; background: #ef4444; color: white; font-size: 0.75rem; font-weight: bold; padding: 5px 30px; transform: rotate(45deg); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
    
    /* =========================================
       สไตล์สำหรับหน้าโปรไฟล์เด็ก (Detail Mode)
       ========================================= */
    .profile-grid { display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start; }
    
    /* แผงข้อมูลซ้าย */
    .profile-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden; position: sticky; top: 30px;}
    .profile-banner { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 40px 20px 20px 20px; text-align: center; color: white; position: relative;}
    .profile-banner .std-avatar { width: 90px; height: 90px; font-size: 3rem; margin: 0 auto 15px auto; border: 4px solid white; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
    .profile-banner h2 { margin: 0 0 5px 0; font-size: 1.4rem; }
    .profile-banner p { margin: 0; color: #cbd5e1; }
    
    .risk-alert-banner { background: #fee2e2; color: #991b1b; padding: 15px; text-align: center; border-bottom: 1px solid #fecaca; }
    .risk-alert-banner h4 { margin: 0 0 5px 0; display: flex; align-items: center; justify-content: center; gap: 5px; }

    .profile-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: #e2e8f0; }
    .p-stat-box { background: white; padding: 20px; text-align: center; }
    .p-stat-box span { display: block; color: #64748b; font-size: 0.85rem; margin-bottom: 5px; }
    .p-stat-box strong { font-size: 1.8rem; color: #0f172a; }

    /* ตู้โชว์เหรียญ */
    .badges-section { padding: 20px; }
    .badges-section h3 { margin: 0 0 15px 0; font-size: 1.1rem; color: #0f766e; border-bottom: 2px solid #ccfbf1; padding-bottom: 5px; }
    .badges-mini-grid { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
    .badge-icon-box { width: 50px; height: 50px; border-radius: 12px; border: 1px solid #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; background: #f8fafc; position: relative; cursor: help; transition: 0.2s;}
    .badge-icon-box.locked { filter: grayscale(100%); opacity: 0.3; }
    .badge-icon-box:hover:not(.locked) { transform: translateY(-3px); box-shadow: 0 5px 15px rgba(250, 204, 21, 0.4); border-color: #fcd34d; background: #fffbeb;}
    
    /* แผงกราฟและประวัติขวา */
    .content-panel { display: flex; flex-direction: column; gap: 30px; }
    .panel-card { background: white; border-radius: 16px; border: 1px solid #e2e8f0; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .panel-card h3 { margin: 0 0 20px 0; font-size: 1.2rem; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
    .chart-container { position: relative; height: 320px; width: 100%; }

    /* Table */
    .table-responsive { overflow-x: auto; }
    .styled-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .styled-table th, .styled-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .styled-table th { background-color: #f8fafc; color: #475569; font-weight: 600; white-space: nowrap; }
    .styled-table tbody tr:hover { background-color: #f0fdfa; }
    
    .grade-badge { padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; display: inline-block; text-align: center; min-width: 30px; }
    .grade-A { background: #dcfce7; color: #166534; } .grade-B { background: #dbeafe; color: #1e40af; } .grade-C { background: #fef3c7; color: #b45309; } .grade-D { background: #ffedd5; color: #c2410c; } .grade-F { background: #fee2e2; color: #991b1b; }
    
    .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
    .empty-state span { font-size: 4rem; display: block; margin-bottom: 15px; }

    @media (max-width: 992px) { .profile-grid { grid-template-columns: 1fr; } .profile-card { position: relative; top: 0; } }
</style>

<div class="track-wrapper">

    <?php if ($view_mode === 'list'): ?>
        
        <div class="page-header">
            <h1 class="page-title">🎯 ทำเนียบติดตามผู้เรียน (Student Roster)</h1>
            <a href="dashboard_teacher.php" class="btn-back">⬅ กลับหน้าหลัก</a>
        </div>

        <form method="GET" action="teacher_student_track.php" class="toolbar-card">
            <input type="text" name="search" class="search-input" placeholder="🔍 ค้นหาชื่อ หรือ รหัสนักเรียน..." value="<?= htmlspecialchars($search_text) ?>">
            
            <select name="class_id" class="filter-select">
                <option value="0">-- ทุกชั้นเรียน --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($filter_class == $c['id']) ? 'selected' : '' ?>>
                        ห้อง <?= htmlspecialchars($c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label class="chk-risk-wrapper" title="แสดงเฉพาะเด็กที่มีคะแนนต่ำหรือเกิดอุบัติเหตุบ่อย">
                <input type="checkbox" name="risk" value="1" <?= ($filter_risk == 1) ? 'checked' : '' ?>>
                ⚠️ คัดกรองกลุ่มเสี่ยง (At-Risk)
            </label>

            <button type="submit" class="btn-filter">ค้นหา</button>
            <a href="teacher_student_track.php" class="btn-clear">ล้างค่า</a>
        </form>

        <div class="student-grid">
            <?php if (count($all_students) > 0): ?>
                <?php foreach ($all_students as $std): ?>
                    <a href="teacher_student_track.php?id=<?= $std['id'] ?>" class="student-card">
                        <?php if ($std['is_risk']): ?>
                            <div class="risk-badge">⚠️ เสี่ยง</div>
                        <?php endif; ?>
                        
                        <div class="std-header">
                            <div class="std-avatar"><?= mb_substr($std['display_name'], 0, 1, 'UTF-8') ?></div>
                            <div class="std-info">
                                <h3><?= htmlspecialchars($std['display_name']) ?></h3>
                                <p>🏫 ห้อง <?= $std['class_name'] ? htmlspecialchars($std['class_name']) : '-' ?> | ID: <?= htmlspecialchars($std['username']) ?></p>
                            </div>
                        </div>

                        <div class="std-stats">
                            <div class="s-box">
                                <span>ทำแล็บไปแล้ว</span>
                                <strong><?= $std['total_labs'] ?></strong>
                            </div>
                            <div class="s-box">
                                <span>คะแนนเฉลี่ย</span>
                                <strong style="color: <?= ($std['avg_score'] < 50 && $std['total_labs'] > 0) ? '#ef4444' : '#0ea5e9' ?>;">
                                    <?= $std['total_labs'] > 0 ? round($std['avg_score'], 1) : '-' ?>
                                </strong>
                            </div>
                            <div class="s-box">
                                <span>อุบัติเหตุ 💥</span>
                                <strong style="color: <?= ($std['accidents'] > 0) ? '#ef4444' : '#10b981' ?>;">
                                    <?= $std['accidents'] ?>
                                </strong>
                            </div>
                        </div>
                        
                        <div style="text-align: center; color: #3b82f6; font-size: 0.85rem; font-weight: bold; margin-top: 5px;">
                            ดูกราฟวิเคราะห์พฤติกรรม &rarr;
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="grid-column: 1 / -1;">
                    <div class="empty-state">
                        <span>📭</span>
                        <h2 style="margin: 0; color: #1e293b;">ไม่พบข้อมูลนักเรียน</h2>
                        <p>ไม่มีนักเรียนที่ตรงกับเงื่อนไขการค้นหาของคุณ</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($view_mode === 'detail'): ?>

        <div class="page-header" style="margin-bottom: 20px;">
            <h1 class="page-title">📄 ข้อมูลวิเคราะห์ผู้เรียน (Student Profile)</h1>
            <a href="teacher_student_track.php" class="btn-back">⬅ กลับทำเนียบนักเรียน</a>
        </div>

        <div class="profile-grid">
            
            <div class="profile-card">
                <div class="profile-banner">
                    <div class="std-avatar"><?= mb_substr($student_info['display_name'], 0, 1, 'UTF-8') ?></div>
                    <h2><?= htmlspecialchars($student_info['display_name']) ?></h2>
                    <p>ID: <?= htmlspecialchars($student_info['username']) ?> | 🏫 ห้อง <?= htmlspecialchars($student_info['class_name'] ?? '-') ?></p>
                </div>

                <?php if ($is_at_risk): ?>
                    <div class="risk-alert-banner">
                        <h4>⚠️ ตรวจพบความเสี่ยง (At-Risk)</h4>
                        <span style="font-size: 0.85rem;"><?= $risk_reason ?></span>
                    </div>
                <?php endif; ?>

                <div class="profile-stats">
                    <div class="p-stat-box">
                        <span>ส่งรายงานแล้ว</span>
                        <strong><?= $stats['total_labs'] ?></strong>
                    </div>
                    <div class="p-stat-box">
                        <span>คะแนนเฉลี่ย</span>
                        <strong style="color: <?= $stats['avg_score'] < 50 ? '#ef4444' : '#0ea5e9' ?>;">
                            <?= $stats['total_labs'] > 0 ? $stats['avg_score'] : '-' ?>
                        </strong>
                    </div>
                    <div class="p-stat-box">
                        <span>เกรดสูงสุด</span>
                        <strong style="color: #10b981;"><?= $stats['best_grade'] ?></strong>
                    </div>
                    <div class="p-stat-box">
                        <span>ทำแล็บระเบิด</span>
                        <strong style="color: <?= $stats['accidents'] > 0 ? '#ef4444' : '#0f172a' ?>;"><?= $stats['accidents'] ?></strong>
                    </div>
                </div>

                <div class="badges-section">
                    <h3>🏆 ตู้โชว์เหรียญรางวัล (Badges)</h3>
                    <div class="badges-mini-grid">
                        <?php foreach ($all_badges as $key => $b): ?>
                            <?php 
                                $is_unlocked = in_array($key, $unlocked_badges);
                                $box_class = $is_unlocked ? "" : "locked";
                                $tooltip = $b['name'] . " : " . $b['desc'];
                            ?>
                            <div class="badge-icon-box <?= $box_class ?>" title="<?= htmlspecialchars($tooltip) ?>">
                                <?= $b['icon'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="text-align: center; color: #94a3b8; font-size: 0.8rem; margin-top: 15px;">
                        เอาเมาส์ชี้ที่เหรียญเพื่อดูรายละเอียด
                    </p>
                </div>
            </div>

            <div class="content-panel">
                
                <div class="panel-card">
                    <h3>📈 กราฟพัฒนาการเชิงลึก (Performance Radar)</h3>
                    <?php if ($stats['total_labs'] > 0): ?>
                        <div class="chart-container">
                            <canvas id="studentPerformanceChart"></canvas>
                        </div>
                        <p style="text-align: center; color: #64748b; font-size: 0.85rem; margin-top: 15px;">
                            * ข้อมูลจากผลการทดลอง 10 ครั้งล่าสุด (เส้นทึบ: คะแนน | เส้นประ: ความปลอดภัย HP)
                        </p>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 30px;">
                            <span style="font-size: 3rem;">📉</span>
                            <h4 style="margin: 0; color: #475569;">ไม่มีข้อมูลให้วิเคราะห์</h4>
                            <p style="font-size: 0.9rem;">นักเรียนคนนี้ยังไม่เคยส่งรายงานการทดลอง</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel-card">
                    <h3>🕒 ประวัติการส่งรายงานทั้งหมด</h3>
                    <?php if (count($student_reports) > 0): ?>
                        <div class="table-responsive">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>วันที่ส่งรายงาน</th>
                                        <th style="text-align: center;">คะแนน</th>
                                        <th style="text-align: center;">เกรด</th>
                                        <th style="text-align: center;">ความปลอดภัย</th>
                                        <th style="text-align: center;">สถานะตรวจ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($student_reports as $rep): ?>
                                        <?php 
                                            $date_format = date('d/m/Y H:i', strtotime($rep['created_at']));
                                            $g_class = "grade-F"; if ($rep['grade'] == 'A') $g_class = "grade-A"; elseif ($rep['grade'] == 'B') $g_class = "grade-B"; elseif ($rep['grade'] == 'C') $g_class = "grade-C"; elseif ($rep['grade'] == 'D') $g_class = "grade-D";
                                            $hp = intval($rep['hp_remaining']); $hp_class = "hp-good"; if ($hp <= 30) $hp_class = "hp-bad"; elseif ($hp <= 60) $hp_class = "hp-warn";
                                            $has_comment = !empty($rep['teacher_comment']);
                                        ?>
                                        <tr>
                                            <td style="color: #475569; font-size: 0.9rem;"><?= $date_format ?></td>
                                            <td style="text-align: center; font-weight: bold; color: #0f172a;"><?= $rep['final_score'] ?></td>
                                            <td style="text-align: center;"><span class="grade-badge <?= $g_class ?>"><?= $rep['grade'] ?></span></td>
                                            <td style="text-align: center;"><span style="font-weight:bold;" class="<?= $hp_class ?>"><?= $hp ?>%</span></td>
                                            <td style="text-align: center; font-size: 0.85rem;">
                                                <?= $has_comment ? '<span style="color:#10b981;">💬 มีคอมเมนต์</span>' : '<span style="color:#94a3b8;">-</span>' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 20px;">
                            <span style="font-size: 2.5rem;">📭</span>
                            <h4 style="margin: 0; color: #475569;">ไม่มีประวัติ</h4>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    <?php endif; ?>

</div>

<?php if ($view_mode === 'detail' && $stats['total_labs'] > 0): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const chartLabels = <?= json_encode($chart_labels) ?>;
        const chartScores = <?= json_encode($chart_scores) ?>;
        const chartHps = <?= json_encode($chart_hps) ?>;

        const ctx = document.getElementById('studentPerformanceChart').getContext('2d');
        
        let gradientScore = ctx.createLinearGradient(0, 0, 0, 300);
        gradientScore.addColorStop(0, 'rgba(14, 165, 233, 0.5)'); // Light Blue
        gradientScore.addColorStop(1, 'rgba(14, 165, 233, 0.0)');

        let gradientHp = ctx.createLinearGradient(0, 0, 0, 300);
        gradientHp.addColorStop(0, 'rgba(16, 185, 129, 0.3)'); // Emerald
        gradientHp.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'คะแนนที่ได้ (Score)', data: chartScores,
                        borderColor: '#0ea5e9', backgroundColor: gradientScore, borderWidth: 3,
                        pointBackgroundColor: '#ffffff', pointBorderColor: '#0ea5e9', pointBorderWidth: 2,
                        pointRadius: 5, fill: true, tension: 0.4
                    },
                    {
                        label: 'ความปลอดภัย (HP)', data: chartHps,
                        borderColor: '#10b981', backgroundColor: gradientHp, borderWidth: 3, borderDash: [5, 5],
                        pointBackgroundColor: '#ffffff', pointBorderColor: '#10b981', pointBorderWidth: 2,
                        pointRadius: 5, fill: true, tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { family: "'Prompt', sans-serif" }, usePointStyle: true, boxWidth: 8 } },
                    tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.9)', titleFont: { family: "'Prompt', sans-serif", size: 13 }, bodyFont: { family: "'Prompt', sans-serif", size: 14 }, padding: 12, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { family: "'Prompt', sans-serif", size: 11 }, color: '#64748b' } },
                    y: { min: 0, max: 100, grid: { color: '#e2e8f0', borderDash: [5, 5] }, ticks: { font: { family: "'Prompt', sans-serif", size: 11 }, color: '#64748b', stepSize: 20 } }
                }
            }
        });
    });
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>