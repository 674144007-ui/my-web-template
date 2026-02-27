<?php
// teacher_reports.php - ระบบออกรายงานและส่งออกข้อมูล (Export & Official Reporting - Phase 7)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// บังคับสิทธิ์ครูและผู้พัฒนา
requireRole(['teacher', 'developer']);

$page_title = "ออกรายงานและส่งออกข้อมูล";
$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['display_name'] ?? 'คุณครู';

// ---------------------------------------------------------
// 1. ดึงรายชื่อห้องเรียนสำหรับ Dropdown
// ---------------------------------------------------------
$classes = [];
$res_classes = $conn->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// ---------------------------------------------------------
// 2. รับค่าตัวกรองห้องเรียน
// ---------------------------------------------------------
$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$selected_class_name = "นักเรียนทั้งหมดในระบบ";

if ($selected_class_id > 0) {
    $stmt_c = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $stmt_c->bind_param("i", $selected_class_id);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    if ($res_c->num_rows > 0) {
        $selected_class_name = "ห้อง " . $res_c->fetch_assoc()['class_name'];
    }
    $stmt_c->close();
}

// ---------------------------------------------------------
// 3. ดึงข้อมูลนักเรียนและสถิติเพื่อพรีวิว
// ---------------------------------------------------------
$report_data = [];
$sql = "
    SELECT 
        u.id as student_id, u.username, u.display_name, c.class_name,
        COUNT(lr.id) as total_labs,
        AVG(lr.final_score) as avg_score,
        MAX(lr.final_score) as max_score,
        SUM(CASE WHEN lr.hp_remaining < 50 THEN 1 ELSE 0 END) as accidents
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN lab_reports lr ON u.id = lr.student_id
    WHERE u.role = 'student' AND u.is_deleted = 0
";

if ($selected_class_id > 0) {
    $sql .= " AND u.class_id = $selected_class_id";
}

$sql .= " GROUP BY u.id ORDER BY u.username ASC";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    // จัดการข้อมูลตัวเลขให้สวยงาม
    $row['avg_score'] = $row['total_labs'] > 0 ? round(floatval($row['avg_score']), 1) : 0;
    
    // คำนวณเกรดคร่าวๆ จากคะแนนเฉลี่ย
    $row['grade'] = "-";
    if ($row['total_labs'] > 0) {
        if ($row['avg_score'] >= 80) $row['grade'] = "A";
        elseif ($row['avg_score'] >= 70) $row['grade'] = "B";
        elseif ($row['avg_score'] >= 60) $row['grade'] = "C";
        elseif ($row['avg_score'] >= 50) $row['grade'] = "D";
        else $row['grade'] = "F";
    }
    
    $report_data[] = $row;
}

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Sarabun:wght@400;700&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS สำหรับหน้า Export & Reporting (Phase 7)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    
    /* โซนที่ไม่พิมพ์ (ซ่อนตอนกด Print) */
    @media print {
        .no-print { display: none !important; }
        body { background-color: white; }
        .print-only { display: block !important; }
    }
    
    .print-only { display: none; } /* ซ่อนโซนพิมพ์ตอนดูบนเว็บ */

    .report-wrapper { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

    /* Page Header */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #0f766e; display: flex; align-items: center; gap: 10px; }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* Toolbar & Actions */
    .toolbar-card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
    
    .filter-group { display: flex; align-items: center; gap: 15px; }
    .filter-select { padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 1rem; background: #f8fafc; min-width: 250px; }
    .btn-filter { background: #0f766e; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; font-size: 1rem; }
    .btn-filter:hover { background: #0d9488; }

    .action-group { display: flex; gap: 15px; }
    .btn-export-excel { background: #16a34a; color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.3);}
    .btn-export-excel:hover { background: #15803d; transform: translateY(-2px); }
    
    .btn-export-pdf { background: #ea580c; color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 0 4px 10px rgba(234, 88, 12, 0.3); font-family: inherit; font-size: 1rem;}
    .btn-export-pdf:hover { background: #c2410c; transform: translateY(-2px); }

    /* Info Banner */
    .info-banner { background: #e0f2fe; border-left: 5px solid #0284c7; padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; color: #0369a1; font-size: 0.95rem; }

    /* Table Preview */
    .table-card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; overflow: hidden; }
    .table-header { padding: 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
    .table-header h3 { margin: 0; color: #1e293b; font-size: 1.2rem; }
    .table-count { background: #0f766e; color: white; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; }

    .table-responsive { overflow-x: auto; max-height: 600px; }
    .styled-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .styled-table th, .styled-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
    .styled-table th { background-color: #f1f5f9; color: #475569; font-weight: 600; white-space: nowrap; position: sticky; top: 0; z-index: 10; border-bottom: 2px solid #cbd5e1; }
    .styled-table tbody tr:hover { background-color: #f8fafc; }
    
    .grade-badge { padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; display: inline-block; text-align: center; min-width: 30px; }
    .grade-A { background: #dcfce7; color: #166534; } .grade-B { background: #dbeafe; color: #1e40af; } .grade-C { background: #fef3c7; color: #b45309; } .grade-D { background: #ffedd5; color: #c2410c; } .grade-F { background: #fee2e2; color: #991b1b; } .grade-none { background: #f1f5f9; color: #94a3b8; }

    .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
    .empty-state span { font-size: 4rem; display: block; margin-bottom: 15px; }

    /* =========================================
       📄 CSS สำหรับกระดาษพิมพ์ A4 (ปพ. สไตล์ทางการ)
       ========================================= */
    .a4-report {
        width: 210mm; /* ขนาด A4 แนวตั้ง */
        min-height: 297mm;
        margin: 0 auto;
        background: white;
        color: black;
        font-family: 'Sarabun', sans-serif; /* ฟอนต์ทางการของไทย */
        padding: 20mm;
        box-sizing: border-box;
    }
    
    .print-header { text-align: center; margin-bottom: 20px; }
    .print-header h1 { font-size: 24px; margin: 0 0 5px 0; font-weight: bold; }
    .print-header h2 { font-size: 18px; margin: 0 0 10px 0; font-weight: normal; }
    
    .print-meta { display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 20px; border-bottom: 2px solid black; padding-bottom: 10px; }
    
    .print-table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 30px; }
    .print-table th, .print-table td { border: 1px solid black; padding: 8px 10px; text-align: center; }
    .print-table th { background-color: #f2f2f2; font-weight: bold; }
    .print-table td.text-left { text-align: left; }
    
    .print-signature { margin-top: 50px; display: flex; justify-content: flex-end; }
    .sig-box { text-align: center; width: 250px; }
    .sig-line { border-bottom: 1px dotted black; height: 30px; margin-bottom: 10px; }
    .sig-name { font-size: 14px; }

</style>

<div class="report-wrapper no-print">
    
    <div class="page-header">
        <h1 class="page-title">🖨️ ออกรายงานและส่งออกข้อมูล (Export Reports)</h1>
        <a href="dashboard_teacher.php" class="btn-back">⬅ กลับหน้าหลัก</a>
    </div>

    <div class="toolbar-card">
        <form method="GET" action="teacher_reports.php" class="filter-group">
            <select name="class_id" class="filter-select">
                <option value="0">🌍 นักเรียนทั้งหมดในระบบ</option>
                <optgroup label="เลือกตามห้องเรียน">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($selected_class_id == $c['id']) ? 'selected' : '' ?>>
                            ห้อง <?= htmlspecialchars($c['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <button type="submit" class="btn-filter">แสดงข้อมูล</button>
        </form>

        <div class="action-group">
            <a href="export_csv.php?class_id=<?= $selected_class_id ?>" class="btn-export-excel" target="_blank" title="นำไปเปิดใน Microsoft Excel เพื่อคำนวณเกรดต่อ">
                <span>📊</span> ดาวน์โหลดไฟล์ Excel (CSV)
            </a>
            
            <button class="btn-export-pdf" onclick="window.print()" title="สั่งพิมพ์หน้ากระดาษ หรือ Save As PDF">
                <span>📄</span> พิมพ์เอกสารสรุปผล (PDF)
            </button>
        </div>
    </div>

    <div class="info-banner">
        💡 <b>คำแนะนำ:</b> คุณสามารถกดปุ่ม "ดาวน์โหลดไฟล์ Excel" เพื่อนำคะแนนดิบไปกรอกลงในระบบ ปพ.5 ของโรงเรียนได้ทันที หรือกดปุ่ม "พิมพ์เอกสารสรุปผล" เพื่อสั่งพิมพ์รายงานทางการผ่านเครื่องปริ้นเตอร์
    </div>

    <div class="table-card">
        <div class="table-header">
            <h3>พรีวิวข้อมูล: <?= htmlspecialchars($selected_class_name) ?></h3>
            <div class="table-count">จำนวนนักเรียน <?= count($report_data) ?> คน</div>
        </div>
        
        <div class="table-responsive">
            <table class="styled-table">
                <thead>
                    <tr>
                        <th style="width: 50px; text-align: center;">ลำดับ</th>
                        <th style="width: 120px;">รหัสนักเรียน</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>ระดับชั้น</th>
                        <th style="text-align: center;">ส่งงาน (ครั้ง)</th>
                        <th style="text-align: center;">คะแนนเฉลี่ย</th>
                        <th style="text-align: center;">เกรด</th>
                        <th style="text-align: center;">อุบัติเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($report_data) > 0): ?>
                        <?php $i = 1; foreach ($report_data as $row): ?>
                            <?php 
                                $g_class = "grade-none";
                                if ($row['grade'] == 'A') $g_class = "grade-A"; elseif ($row['grade'] == 'B') $g_class = "grade-B"; elseif ($row['grade'] == 'C') $g_class = "grade-C"; elseif ($row['grade'] == 'D') $g_class = "grade-D"; elseif ($row['grade'] == 'F') $g_class = "grade-F";
                            ?>
                            <tr>
                                <td style="text-align: center; color: #94a3b8;"><?= $i++ ?></td>
                                <td style="font-family: monospace; color: #64748b;"><?= htmlspecialchars($row['username']) ?></td>
                                <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($row['display_name']) ?></td>
                                <td style="color: #475569;"><?= $row['class_name'] ? htmlspecialchars($row['class_name']) : '-' ?></td>
                                <td style="text-align: center; font-weight: bold;"><?= $row['total_labs'] ?></td>
                                <td style="text-align: center; font-weight: bold; color: #0ea5e9;"><?= $row['avg_score'] ?></td>
                                <td style="text-align: center;"><span class="grade-badge <?= $g_class ?>"><?= $row['grade'] ?></span></td>
                                <td style="text-align: center; font-weight: bold; color: <?= $row['accidents'] > 0 ? '#ef4444' : '#10b981' ?>;"><?= $row['accidents'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <span>📭</span>
                                    <h3 style="margin: 0; color: #475569;">ไม่พบข้อมูลนักเรียน</h3>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div class="print-only">
    <div class="a4-report">
        
        <div class="print-header">
            <h1>เอกสารสรุปผลสัมฤทธิ์การปฏิบัติการเคมี (Virtual Lab)</h1>
            <h2>โรงเรียนบ้านคาวิทยา สำนักงานเขตพื้นที่การศึกษามัธยมศึกษาราชบุรี</h2>
        </div>

        <div class="print-meta">
            <div>
                <strong>รายงานผลของ:</strong> <?= htmlspecialchars($selected_class_name) ?><br>
                <strong>ครูผู้สอน/ผู้คุมสอบ:</strong> <?= htmlspecialchars($teacher_name) ?>
            </div>
            <div style="text-align: right;">
                <strong>วันที่ออกเอกสาร:</strong> <?= date('d / m / Y') ?><br>
                <strong>จำนวนนักเรียน:</strong> <?= count($report_data) ?> คน
            </div>
        </div>

        <table class="print-table">
            <thead>
                <tr>
                    <th style="width: 5%;">ที่</th>
                    <th style="width: 15%;">รหัสประจำตัว</th>
                    <th style="width: 30%;">ชื่อ - นามสกุล</th>
                    <th style="width: 15%;">ปฏิบัติงาน (ครั้ง)</th>
                    <th style="width: 15%;">คะแนนเฉลี่ย</th>
                    <th style="width: 10%;">ระดับผลการเรียน</th>
                    <th style="width: 10%;">พฤติกรรมเสี่ยง</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($report_data) > 0): ?>
                    <?php $i = 1; foreach ($report_data as $row): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($row['username']) ?></td>
                            <td class="text-left"><?= htmlspecialchars($row['display_name']) ?></td>
                            <td><?= $row['total_labs'] ?></td>
                            <td><?= $row['total_labs'] > 0 ? number_format($row['avg_score'], 2) : '-' ?></td>
                            <td><strong><?= $row['grade'] ?></strong></td>
                            <td><?= $row['accidents'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">ไม่มีข้อมูลนักเรียน</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="print-signature">
            <div class="sig-box">
                <div class="sig-line"></div>
                <div class="sig-name">( <?= htmlspecialchars($teacher_name) ?> )</div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">ครูประจำวิชา / ผู้ประเมิน</div>
            </div>
        </div>

        <div style="text-align: center; font-size: 10px; color: #999; margin-top: 50px;">
            เอกสารฉบับนี้ประมวลผลอัตโนมัติจากระบบ Bankha Virtual Lab System
        </div>
        
    </div>
</div>

<?php require_once 'footer.php'; ?>