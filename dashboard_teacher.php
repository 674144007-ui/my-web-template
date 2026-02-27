<?php
// dashboard_teacher.php - ศูนย์บัญชาการครูผู้สอน (Teacher Dashboard - Phase 1: Analytics & UI)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// บังคับให้เฉพาะครูและผู้พัฒนาเท่านั้นที่เข้าหน้านี้ได้
requireRole(['teacher', 'developer']);

$page_title = "แดชบอร์ดครูผู้สอน - โรงเรียนบ้านคาวิทยา";
$teacher_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$display_name = $_SESSION['display_name'] ?? 'คุณครู';

// ---------------------------------------------------------
// 1. ระบบทักทายตามช่วงเวลา (Time-based Greeting)
// ---------------------------------------------------------
date_default_timezone_set('Asia/Bangkok');
$hour = date('H');
$greeting = "สวัสดี"; $greeting_icon = "👋";
if ($hour >= 5 && $hour < 12) { $greeting = "สวัสดีตอนเช้า"; $greeting_icon = "🌅"; } 
elseif ($hour >= 12 && $hour < 17) { $greeting = "สวัสดีตอนบ่าย"; $greeting_icon = "☀️"; } 
elseif ($hour >= 17 && $hour < 21) { $greeting = "สวัสดีตอนเย็น"; $greeting_icon = "🌇"; } 
else { $greeting = "ราตรีสวัสดิ์"; $greeting_icon = "🌙"; }
$avatar_letter = mb_substr($display_name, 0, 1, 'UTF-8');

// ---------------------------------------------------------
// 2. ดึงข้อมูลสถิติภาพรวมของนักเรียน (Analytics)
// ---------------------------------------------------------
$total_students = 0;
$today_reports = 0;
$avg_score_all = 0;
$total_accidents = 0;

// 2.1 จำนวนนักเรียนในระบบทั้งหมด (ที่ใช้งานอยู่)
$stmt_std = $conn->prepare("SELECT COUNT(id) AS total FROM users WHERE role = 'student' AND is_deleted = 0");
$stmt_std->execute();
$res_std = $stmt_std->get_result();
if ($res_std->num_rows > 0) { $total_students = $res_std->fetch_assoc()['total']; }
$stmt_std->close();

// 2.2 จำนวนรายงานการทดลองที่ถูกส่งเข้ามา "วันนี้"
$stmt_today = $conn->prepare("SELECT COUNT(id) AS today_count FROM lab_reports WHERE DATE(created_at) = CURDATE()");
$stmt_today->execute();
$res_today = $stmt_today->get_result();
if ($res_today->num_rows > 0) { $today_reports = $res_today->fetch_assoc()['today_count']; }
$stmt_today->close();

// 2.3 คะแนนเฉลี่ยของการทำแล็บรวมทั้งโรงเรียน
$stmt_avg = $conn->prepare("SELECT AVG(final_score) AS avg_score FROM lab_reports");
$stmt_avg->execute();
$res_avg = $stmt_avg->get_result();
if ($res_avg->num_rows > 0) { $avg_score_all = round(floatval($res_avg->fetch_assoc()['avg_score']), 1); }
$stmt_avg->close();

// 2.4 จำนวนอุบัติเหตุในห้องแล็บ (นับจากรายงานที่ HP ลดลงต่ำกว่า 100%)
$stmt_acc = $conn->prepare("SELECT COUNT(id) AS accident_count FROM lab_reports WHERE hp_remaining < 100");
$stmt_acc->execute();
$res_acc = $stmt_acc->get_result();
if ($res_acc->num_rows > 0) { $total_accidents = $res_acc->fetch_assoc()['accident_count']; }
$stmt_acc->close();

// ---------------------------------------------------------
// 3. ดึงประวัติการส่งรายงานล่าสุด 5 รายการ (พรีวิวสำหรับ Phase 2)
// ---------------------------------------------------------
$recent_submissions = [];
$stmt_recent = $conn->prepare("
    SELECT lr.id, lr.final_score, lr.grade, lr.hp_remaining, lr.created_at, 
           u.display_name, c.class_name 
    FROM lab_reports lr
    JOIN users u ON lr.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    ORDER BY lr.created_at DESC 
    LIMIT 5
");
$stmt_recent->execute();
$res_recent = $stmt_recent->get_result();
while ($row = $res_recent->fetch_assoc()) {
    $recent_submissions[] = $row;
}
$stmt_recent->close();

// โหลด Header หลักของระบบ
require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS สำหรับ Teacher Dashboard (Phase 1)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .dashboard-wrapper { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    /* --- Welcome Banner (สไตล์ครู จะเป็นโทนสี Teal/Emerald ดูเป็นทางการและสบายตา) --- */
    .welcome-banner {
        background: linear-gradient(135deg, #0f766e 0%, #0369a1 100%);
        border-radius: 20px; padding: 40px; color: white; display: flex; align-items: center; gap: 30px;
        box-shadow: 0 10px 30px rgba(15, 118, 110, 0.3); margin-bottom: 30px; position: relative; overflow: hidden;
    }
    .welcome-banner::before { content: ''; position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255, 0.1) 0%, transparent 70%); border-radius: 50%; }
    
    .avatar-wrapper { position: relative; z-index: 2; flex-shrink: 0; }
    .avatar-circle { width: 90px; height: 90px; background: #ffffff; color: #0f766e; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: bold; box-shadow: 0 0 20px rgba(255,255,255, 0.3); border: 4px solid rgba(255,255,255,0.8); }
    .role-badge { position: absolute; bottom: -10px; left: 50%; transform: translateX(-50%); background: #f59e0b; color: white; padding: 4px 15px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.2); white-space: nowrap; }

    .welcome-text { z-index: 2; flex: 1; }
    .welcome-text h1 { margin: 0 0 5px 0; font-size: 2.2rem; font-weight: 700; color: #f8fafc; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
    .welcome-text p { margin: 0; font-size: 1.1rem; color: #ccfbf1; }

    /* --- Stats Grid (สถิติรวม) --- */
    .section-title { font-size: 1.4rem; font-weight: 700; color: #1e293b; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .stat-card { background: white; padding: 25px 20px; border-radius: 16px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; transition: transform 0.3s; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .stat-icon { width: 60px; height: 60px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 2rem; flex-shrink: 0; }
    
    .icon-students { background: #eff6ff; color: #3b82f6; }
    .icon-reports { background: #f0fdf4; color: #10b981; }
    .icon-avg { background: #fef3c7; color: #f59e0b; }
    .icon-accidents { background: #fee2e2; color: #ef4444; }

    .stat-info { display: flex; flex-direction: column; }
    .stat-info h4 { margin: 0; font-size: 0.9rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;}
    .stat-info .value { margin: 5px 0 0 0; font-size: 1.8rem; font-weight: bold; color: #0f172a; }
    .stat-info .sub-text { font-size: 0.8rem; color: #94a3b8; margin-top: 2px; }

    /* --- Quick Access Menu --- */
    .quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 40px; }
    .quick-card { background: white; border-radius: 16px; padding: 25px 20px; text-align: center; text-decoration: none; color: #334155; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; transition: all 0.3s ease; position: relative; overflow: hidden;}
    .quick-card:hover { transform: translateY(-8px); box-shadow: 0 15px 30px rgba(0,0,0,0.08); border-color: #cbd5e1; }
    .card-icon { width: 70px; height: 70px; margin: 0 auto 15px auto; border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; transition: 0.3s; }
    
    .card-review .card-icon { background: rgba(16, 185, 129, 0.1); color: #10b981; } .card-review:hover .card-icon { background: #10b981; color: white; }
    .card-quest .card-icon { background: rgba(245, 158, 11, 0.1); color: #f59e0b; } .card-quest:hover .card-icon { background: #f59e0b; color: white; }
    .card-class .card-icon { background: rgba(59, 130, 246, 0.1); color: #3b82f6; } .card-class:hover .card-icon { background: #3b82f6; color: white; }
    .card-announce .card-icon { background: rgba(139, 92, 246, 0.1); color: #8b5cf6; } .card-announce:hover .card-icon { background: #8b5cf6; color: white; }
    
    .quick-card h3 { margin: 0 0 8px 0; font-size: 1.15rem; color: #0f172a; }
    .quick-card p { margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.5; }

    /* --- Recent Submissions Table --- */
    .panel-card { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 40px;}
    .panel-card h3 { margin: 0 0 20px 0; font-size: 1.2rem; color: #0f172a; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; display: flex; justify-content: space-between; align-items: center;}
    .btn-view-all { font-size: 0.9rem; color: #3b82f6; text-decoration: none; font-weight: 600; padding: 5px 10px; border-radius: 6px; background: #eff6ff; transition: 0.2s;}
    .btn-view-all:hover { background: #dbeafe; color: #1d4ed8; }

    .table-responsive { overflow-x: auto; }
    .styled-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .styled-table th, .styled-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .styled-table th { background-color: #f8fafc; color: #475569; font-weight: 600; white-space: nowrap; }
    .styled-table tbody tr:hover { background-color: #f8fafc; cursor: pointer;}
    .styled-table tbody tr:last-of-type td { border-bottom: none; }
    
    .grade-badge { padding: 5px 12px; border-radius: 20px; font-weight: bold; font-size: 0.85rem; display: inline-block; text-align: center; min-width: 30px; }
    .grade-A { background: #dcfce7; color: #166534; } .grade-B { background: #dbeafe; color: #1e40af; } .grade-C { background: #fef3c7; color: #b45309; } .grade-D { background: #ffedd5; color: #c2410c; } .grade-F { background: #fee2e2; color: #991b1b; }
    
    .hp-text { font-family: monospace; font-weight: bold; }
    .hp-good { color: #10b981; } .hp-warn { color: #f59e0b; } .hp-bad { color: #ef4444; }

    .empty-state { text-align: center; padding: 40px 20px; color: #94a3b8; }
    .empty-state span { font-size: 3rem; display: block; margin-bottom: 10px; }

    @media (max-width: 768px) { 
        .welcome-banner { flex-direction: column; text-align: center; padding: 30px 20px; } 
        .stats-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="dashboard-wrapper">
    
    <div class="welcome-banner">
        <div class="avatar-wrapper">
            <div class="avatar-circle"><?= htmlspecialchars($avatar_letter) ?></div>
            <div class="role-badge">Teacher</div>
        </div>
        <div class="welcome-text">
            <p><?= $greeting_icon ?> <?= $greeting ?>,</p>
            <h1>คุณครู <?= htmlspecialchars($display_name) ?></h1>
            <p>ยินดีต้อนรับสู่ระบบบริหารจัดการการเรียนการสอน (LMS) และ Virtual Lab</p>
        </div>
    </div>

    <div class="section-title">📊 สถิติภาพรวมโรงเรียน (School Analytics)</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon icon-students">👥</div>
            <div class="stat-info">
                <h4>นักเรียนในระบบ</h4>
                <div class="value"><?= number_format($total_students) ?> <span style="font-size:1rem; color:#94a3b8; font-weight:normal;">คน</span></div>
                <div class="sub-text">ผู้ใช้งานที่มีสิทธิ์เข้าทดลอง</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-reports">📥</div>
            <div class="stat-info">
                <h4>ส่งรายงานวันนี้</h4>
                <div class="value"><?= number_format($today_reports) ?> <span style="font-size:1rem; color:#94a3b8; font-weight:normal;">ฉบับ</span></div>
                <div class="sub-text">รายงานการทดลองที่รอตรวจ</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-avg">🎯</div>
            <div class="stat-info">
                <h4>คะแนนเฉลี่ยรวม</h4>
                <div class="value"><?= number_format($avg_score_all, 1) ?> <span style="font-size:1rem; color:#94a3b8; font-weight:normal;">/ 100</span></div>
                <div class="sub-text">ค่าเฉลี่ยจากทุกการทดลอง</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon icon-accidents">⚠️</div>
            <div class="stat-info">
                <h4>อุบัติเหตุในแล็บ</h4>
                <div class="value" style="color:#ef4444;"><?= number_format($total_accidents) ?> <span style="font-size:1rem; color:#94a3b8; font-weight:normal;">ครั้ง</span></div>
                <div class="sub-text">กรณีนักเรียน HP ลด/ระเบิด</div>
            </div>
        </div>
    </div>

    <div class="section-title">🚀 เครื่องมือจัดการ (Command Center)</div>
    <div class="quick-grid">
        <a href="teacher_review_lab.php" class="quick-card card-review">
            <div class="card-icon">📝</div>
            <h3>ตรวจรายงานการทดลอง</h3>
            <p>ตรวจสอบความถูกต้อง ให้คะแนน และคอมเมนต์ผลแล็บของนักเรียน</p>
        </a>
        <a href="teacher_quests.php" class="quick-card card-quest">
            <div class="card-icon">⚔️</div>
            <h3>สร้างภารกิจ (Lab Quests)</h3>
            <p>กำหนดโจทย์การทดลองเฉพาะเจาะจง พร้อมแจกโบนัส XP</p>
        </a>
        <a href="teacher_classroom.php" class="quick-card card-class">
            <div class="card-icon">📚</div>
            <h3>ชั้นเรียนและการบ้าน</h3>
            <p>ระบบ Google Classroom ขนาดย่อม มอบหมายงานตามรายวิชา</p>
        </a>
        <a href="teacher_announcements.php" class="quick-card card-announce">
            <div class="card-icon">📢</div>
            <h3>ประกาศหน้าเสาธง</h3>
            <p>ส่งข้อความแจ้งเตือนสำคัญให้นักเรียนทั้งโรงเรียน หรือรายห้อง</p>
        </a>
        <a href="teacher_student_track.php" class="quick-card" style="border-color: #fca5a5;">
            <div class="card-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">🎯</div>
            <h3>ทำเนียบติดตามผู้เรียน</h3>
            <p>ดูประวัตินักเรียนรายบุคคล กราฟพัฒนาการ และแจ้งเตือนกลุ่มเสี่ยง</p>
        </a>
    </div>

    <div class="panel-card">
        <h3>
            <span>🕒 การทดลองล่าสุดที่นักเรียนส่ง (Recent Submissions)</span>
            <a href="teacher_reports.php" class="btn-view-all">ออกรายงานทั้งหมด &rarr;</a>
        </h3>
        
        <?php if (count($recent_submissions) > 0): ?>
            <div class="table-responsive">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>ชื่อนักเรียน</th>
                            <th>ระดับชั้น</th>
                            <th>วันที่-เวลาส่ง</th>
                            <th style="text-align: center;">คะแนนที่ระบบให้</th>
                            <th style="text-align: center;">เกรด</th>
                            <th style="text-align: center;">ความปลอดภัย</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_submissions as $sub): ?>
                            <?php 
                                $date_format = date('d/m/Y H:i', strtotime($sub['created_at']));
                                $g_class = "grade-F"; if ($sub['grade'] == 'A') $g_class = "grade-A"; elseif ($sub['grade'] == 'B') $g_class = "grade-B"; elseif ($sub['grade'] == 'C') $g_class = "grade-C"; elseif ($sub['grade'] == 'D') $g_class = "grade-D";
                                $hp = intval($sub['hp_remaining']); $hp_class = "hp-good"; if ($hp <= 30) $hp_class = "hp-bad"; elseif ($hp <= 60) $hp_class = "hp-warn";
                                $class_name = $sub['class_name'] ? $sub['class_name'] : '-';
                            ?>
                            <tr onclick="alert('คลิกเพื่อตรวจรายงานของ <?= htmlspecialchars($sub['display_name']) ?> (ระบบจะมาใน Phase 2)')">
                                <td style="font-weight: 600; color: #1e293b;">
                                    👤 <?= htmlspecialchars($sub['display_name']) ?>
                                </td>
                                <td style="color: #64748b;"><?= htmlspecialchars($class_name) ?></td>
                                <td style="color: #64748b; font-size: 0.9rem;"><?= $date_format ?></td>
                                <td style="text-align: center; font-weight: bold; color: #0f172a;"><?= $sub['final_score'] ?></td>
                                <td style="text-align: center;"><span class="grade-badge <?= $g_class ?>"><?= $sub['grade'] ?></span></td>
                                <td style="text-align: center;"><span class="hp-text <?= $hp_class ?>"><?= $hp ?>% HP</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span>📭</span>
                <h4 style="margin: 0; color: #475569;">ยังไม่มีนักเรียนส่งรายงาน</h4>
                <p style="font-size: 0.9rem;">เมื่อนักเรียนทำการทดลองและกดส่งรายงาน ข้อมูลจะมาปรากฏที่นี่</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once 'footer.php'; ?>