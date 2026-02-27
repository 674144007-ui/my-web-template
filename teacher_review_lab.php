<?php
// teacher_review_lab.php - ระบบตรวจรายงานการทดลอง (Teacher Dashboard Phase 2)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

requireRole(['teacher', 'developer']);

$page_title = "ตรวจรายงาน Virtual Lab";
$csrf = generate_csrf_token();

// ---------------------------------------------------------
// 1. ดึงข้อมูลชั้นเรียนทั้งหมดเพื่อใช้ใน Dropdown กรองข้อมูล
// ---------------------------------------------------------
$classes = [];
$res_classes = $conn->query("SELECT id, class_name FROM classes ORDER BY level ASC, room ASC");
if ($res_classes) {
    while ($row = $res_classes->fetch_assoc()) {
        $classes[] = $row;
    }
}

// ---------------------------------------------------------
// 2. รับค่าตัวกรอง (Filters)
// ---------------------------------------------------------
$search_text = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, reviewed, pending

// ---------------------------------------------------------
// 3. สร้าง Query ดึงข้อมูลรายงาน
// ---------------------------------------------------------
$sql = "
    SELECT lr.id as report_id, lr.final_score, lr.grade, lr.hp_remaining, lr.report_summary, lr.teacher_comment, lr.created_at,
           u.id as student_id, u.display_name, u.username,
           c.class_name
    FROM lab_reports lr
    JOIN users u ON lr.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.is_deleted = 0
";
$params = [];
$types = "";

if ($search_text !== '') {
    $sql .= " AND (u.display_name LIKE ? OR u.username LIKE ?)";
    $like_search = "%{$search_text}%";
    $params[] = $like_search;
    $params[] = $like_search;
    $types .= "ss";
}

if ($filter_class > 0) {
    $sql .= " AND u.class_id = ?";
    $params[] = $filter_class;
    $types .= "i";
}

if ($filter_status === 'reviewed') {
    $sql .= " AND lr.teacher_comment IS NOT NULL AND lr.teacher_comment != ''";
} elseif ($filter_status === 'pending') {
    $sql .= " AND (lr.teacher_comment IS NULL OR lr.teacher_comment = '')";
}

$sql .= " ORDER BY lr.created_at DESC"; // เรียงจากใหม่ไปเก่า

// ประมวลผล Query
$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result_reports = $stmt->get_result();

require_once 'header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    /* =========================================
       CSS สำหรับหน้าระบบตรวจรายงาน (Phase 2)
       ========================================= */
    body { background-color: #f1f5f9; font-family: 'Prompt', sans-serif; color: #0f172a; margin: 0; }
    .review-wrapper { max-width: 1300px; margin: 30px auto; padding: 0 20px; }

    /* --- Page Header --- */
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .page-title { margin: 0; font-size: 1.8rem; font-weight: 700; color: #0f766e; display: flex; align-items: center; gap: 10px; }
    .btn-back { background: white; color: #64748b; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .btn-back:hover { background: #f8fafc; color: #0f172a; border-color: #94a3b8; }

    /* --- Toolbar (Filters) --- */
    .toolbar-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 30px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; }
    .search-input { flex: 2; min-width: 250px; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 0.95rem; }
    .search-input:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.1); }
    
    .filter-select { flex: 1; min-width: 150px; padding: 12px 15px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-family: inherit; font-size: 0.95rem; background: white; cursor: pointer; }
    .btn-filter { background: #0f766e; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.3s; font-family: inherit; font-size: 0.95rem; }
    .btn-filter:hover { background: #0d9488; }
    .btn-clear { background: transparent; color: #ef4444; border: 1px solid #ef4444; padding: 12px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; transition: 0.3s; }
    .btn-clear:hover { background: #fee2e2; }

    /* --- Data Table --- */
    .table-card { background: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; overflow: hidden; }
    .table-responsive { overflow-x: auto; }
    .styled-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .styled-table th, .styled-table td { padding: 15px 20px; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .styled-table th { background-color: #f8fafc; color: #475569; font-weight: 600; white-space: nowrap; border-bottom: 2px solid #e2e8f0; }
    .styled-table tbody tr { transition: 0.2s; }
    .styled-table tbody tr:hover { background-color: #f0fdfa; }
    
    .student-info { display: flex; align-items: center; gap: 15px; }
    .student-avatar { width: 40px; height: 40px; background: #e0f2fe; color: #0369a1; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.2rem; }
    .student-name { font-weight: 700; color: #1e293b; margin-bottom: 2px; }
    .student-class { font-size: 0.8rem; color: #64748b; }

    .grade-badge { padding: 6px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9rem; display: inline-block; text-align: center; min-width: 35px; border: 1px solid transparent;}
    .grade-A { background: #dcfce7; color: #166534; border-color: #bbf7d0;} 
    .grade-B { background: #dbeafe; color: #1e40af; border-color: #bfdbfe;} 
    .grade-C { background: #fef3c7; color: #b45309; border-color: #fde68a;} 
    .grade-D { background: #ffedd5; color: #c2410c; border-color: #fed7aa;} 
    .grade-F { background: #fee2e2; color: #991b1b; border-color: #fecaca;}

    .hp-text { font-family: 'Share Tech Mono', monospace; font-weight: bold; font-size: 1.1rem;}
    .hp-good { color: #10b981; } .hp-warn { color: #f59e0b; } .hp-bad { color: #ef4444; }

    .status-badge { padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
    .status-pending { background: #fffbeb; color: #d97706; border: 1px dashed #fcd34d; }
    .status-reviewed { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

    .btn-review { background: #0ea5e9; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 5px; font-family: inherit; font-size: 0.9rem;}
    .btn-review:hover { background: #0284c7; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(14, 165, 233, 0.3); }

    /* --- Modal ตรวจงาน (Review Modal) --- */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(5px); z-index: 9999; display: none; align-items: center; justify-content: center; }
    .review-modal { background: white; width: 100%; max-width: 600px; border-radius: 16px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.5); transform: translateY(20px); opacity: 0; transition: 0.3s; display: flex; flex-direction: column;}
    .review-modal.show { transform: translateY(0); opacity: 1; }
    
    .modal-header { background: #0f766e; color: white; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center; }
    .modal-header h2 { margin: 0; font-size: 1.4rem; display: flex; align-items: center; gap: 10px; }
    .btn-close { background: transparent; color: white; border: none; font-size: 1.5rem; cursor: pointer; opacity: 0.7; transition: 0.2s; }
    .btn-close:hover { opacity: 1; transform: scale(1.1); }

    .modal-body { padding: 25px; max-height: 70vh; overflow-y: auto; }
    
    /* สรุปคะแนนใน Modal */
    .score-banner { display: flex; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 20px; }
    .score-box { flex: 1; text-align: center; border-right: 1px solid #e2e8f0; }
    .score-box:last-child { border-right: none; }
    .score-box .label { font-size: 0.85rem; color: #64748b; margin-bottom: 5px; text-transform: uppercase; }
    .score-box .val { font-size: 1.8rem; font-weight: bold; color: #0f172a; }

    /* รายละเอียด Log */
    .detail-section { margin-bottom: 20px; }
    .detail-section h4 { margin: 0 0 10px 0; color: #0f766e; font-size: 1.1rem; border-bottom: 2px solid #ccfbf1; padding-bottom: 5px; }
    .log-box { background: #0f172a; color: #e2e8f0; font-family: 'Share Tech Mono', monospace; padding: 15px; border-radius: 8px; font-size: 0.95rem; line-height: 1.6; }
    .log-item { margin-bottom: 5px; }
    .log-item span.lbl { color: #38bdf8; display: inline-block; width: 140px; }

    /* ฟอร์มคอมเมนต์ */
    .comment-area { display: flex; flex-direction: column; gap: 10px; }
    .comment-area label { font-weight: bold; color: #334155; }
    .comment-area textarea { width: 100%; height: 120px; padding: 15px; border-radius: 8px; border: 2px solid #cbd5e1; outline: none; font-family: inherit; font-size: 1rem; resize: vertical; box-sizing: border-box; transition: 0.3s; }
    .comment-area textarea:focus { border-color: #0f766e; }
    
    .modal-footer { padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 15px; }
    .btn-cancel { background: white; color: #475569; border: 1px solid #cbd5e1; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: inherit; }
    .btn-cancel:hover { background: #f1f5f9; }
    .btn-save { background: #10b981; color: white; border: none; padding: 10px 25px; border-radius: 8px; font-weight: bold; cursor: pointer; font-family: inherit; font-size: 1.05rem; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); transition: 0.2s; }
    .btn-save:hover { background: #059669; transform: translateY(-2px); }

</style>

<div class="review-wrapper">
    
    <div class="page-header">
        <h1 class="page-title">📝 ตรวจรายงานการทดลอง (Lab Reviews)</h1>
        <a href="dashboard_teacher.php" class="btn-back">⬅ กลับหน้าหลัก</a>
    </div>

    <form method="GET" action="teacher_review_lab.php" class="toolbar-card">
        <input type="text" name="search" class="search-input" placeholder="🔍 ค้นหาชื่อ หรือ รหัสนักเรียน..." value="<?= htmlspecialchars($search_text) ?>">
        
        <select name="class_id" class="filter-select">
            <option value="0">-- ทุกชั้นเรียน --</option>
            <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($filter_class == $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['class_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="filter-select">
            <option value="all" <?= ($filter_status === 'all') ? 'selected' : '' ?>>-- สถานะทั้งหมด --</option>
            <option value="pending" <?= ($filter_status === 'pending') ? 'selected' : '' ?>>⏳ รอการตรวจ</option>
            <option value="reviewed" <?= ($filter_status === 'reviewed') ? 'selected' : '' ?>>✅ ตรวจแล้ว</option>
        </select>

        <button type="submit" class="btn-filter">ค้นหาข้อมูล</button>
        <a href="teacher_review_lab.php" class="btn-clear">ล้างค่า</a>
    </form>

    <div class="table-card">
        <div class="table-responsive">
            <table class="styled-table">
                <thead>
                    <tr>
                        <th>นักเรียน (Student)</th>
                        <th>วันที่ส่งรายงาน</th>
                        <th style="text-align: center;">คะแนนรวม</th>
                        <th style="text-align: center;">เกรด</th>
                        <th style="text-align: center;">ความปลอดภัย (HP)</th>
                        <th style="text-align: center;">สถานะ</th>
                        <th style="text-align: center;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result_reports->num_rows > 0): ?>
                        <?php while($row = $result_reports->fetch_assoc()): ?>
                            <?php 
                                // จัดการค่าต่างๆ ให้พร้อมแสดงผล
                                $date_format = date('d/m/Y H:i', strtotime($row['created_at']));
                                $avatar = mb_substr($row['display_name'], 0, 1, 'UTF-8');
                                $c_name = $row['class_name'] ? $row['class_name'] : 'ไม่ระบุชั้น';
                                
                                $g_class = "grade-F"; if ($row['grade'] == 'A') $g_class = "grade-A"; elseif ($row['grade'] == 'B') $g_class = "grade-B"; elseif ($row['grade'] == 'C') $g_class = "grade-C"; elseif ($row['grade'] == 'D') $g_class = "grade-D";
                                $hp = intval($row['hp_remaining']); $hp_class = "hp-good"; if ($hp <= 30) $hp_class = "hp-bad"; elseif ($hp <= 60) $hp_class = "hp-warn";
                                
                                $is_reviewed = !empty($row['teacher_comment']);
                                
                                // เตรียม Data Attributes ไว้สำหรับส่งให้ JS สร้าง Modal ทันทีโดยไม่ต้องดึง DB ซ้ำ
                                $js_data = htmlspecialchars(json_encode([
                                    'id' => $row['report_id'],
                                    'name' => $row['display_name'],
                                    'class' => $c_name,
                                    'date' => $date_format,
                                    'score' => $row['final_score'],
                                    'grade' => $row['grade'],
                                    'hp' => $hp,
                                    'summary' => $row['report_summary'],
                                    'comment' => $row['teacher_comment'] ?? ''
                                ]), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar"><?= $avatar ?></div>
                                        <div>
                                            <div class="student-name"><?= htmlspecialchars($row['display_name']) ?></div>
                                            <div class="student-class">🏫 <?= htmlspecialchars($c_name) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: #64748b; font-size: 0.9rem;"><?= $date_format ?></td>
                                <td style="text-align: center; font-weight: bold; font-size: 1.1rem; color: #0f172a;"><?= $row['final_score'] ?></td>
                                <td style="text-align: center;"><span class="grade-badge <?= $g_class ?>"><?= $row['grade'] ?></span></td>
                                <td style="text-align: center;"><span class="hp-text <?= $hp_class ?>"><?= $hp ?>%</span></td>
                                <td style="text-align: center;">
                                    <?php if($is_reviewed): ?>
                                        <span class="status-badge status-reviewed">✅ ตรวจแล้ว</span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">⏳ รอตรวจ</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn-review" onclick="openReviewModal(this)" data-info='<?= $js_data ?>'>
                                        <?= $is_reviewed ? '👁️ ดู/แก้ไข' : '📝 ตรวจงาน' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <span>📭</span>
                                    <h3 style="margin: 0; color: #475569;">ไม่พบข้อมูลรายงาน</h3>
                                    <p style="font-size: 0.95rem;">ลองเปลี่ยนเงื่อนไขการค้นหา หรือยังไม่มีนักเรียนส่งรายงานเข้ามา</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div class="modal-overlay" id="reviewModalOverlay">
    <div class="review-modal" id="reviewModalBox">
        <div class="modal-header">
            <h2><span id="mdName">ชื่อนักเรียน</span></h2>
            <button class="btn-close" onclick="closeReviewModal()">✖</button>
        </div>
        
        <div class="modal-body">
            <div style="color:#64748b; font-size:0.9rem; margin-bottom: 15px;">
                🏫 ชั้นเรียน: <strong id="mdClass" style="color:#0f172a;">-</strong> | 
                🕒 ส่งเมื่อ: <strong id="mdDate" style="color:#0f172a;">-</strong>
            </div>

            <div class="score-banner">
                <div class="score-box">
                    <div class="label">คะแนนรวม</div>
                    <div class="val" id="mdScore" style="color:#0ea5e9;">0</div>
                </div>
                <div class="score-box">
                    <div class="label">เกรดที่ได้</div>
                    <div class="val" id="mdGrade">F</div>
                </div>
                <div class="score-box">
                    <div class="label">ความปลอดภัย (HP)</div>
                    <div class="val" id="mdHp" style="font-family:'Share Tech Mono', monospace;">100%</div>
                </div>
            </div>

            <div class="detail-section">
                <h4>บันทึกจากระบบ (System Log Summary)</h4>
                <div class="log-box" id="mdSummaryLog">
                    </div>
            </div>

            <div class="detail-section">
                <div class="comment-area">
                    <label for="teacherCommentInput">💬 ข้อเสนอแนะจากครูผู้สอน (ส่งกลับให้นักเรียนเห็น):</label>
                    <textarea id="teacherCommentInput" placeholder="พิมพ์คำแนะนำ ติชม หรือให้กำลังใจนักเรียนที่นี่..."></textarea>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <input type="hidden" id="mdReportId" value="0">
            <button class="btn-cancel" onclick="closeReviewModal()">ยกเลิก</button>
            <button class="btn-save" id="btnSaveComment" onclick="saveTeacherComment()">💾 บันทึกผลการตรวจ</button>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?= h($csrf) ?>">

<script>
    // =========================================
    // JavaScript สำหรับจัดการ Modal และ AJAX
    // =========================================

    const overlay = document.getElementById('reviewModalOverlay');
    const modalBox = document.getElementById('reviewModalBox');

    function openReviewModal(btnElement) {
        // 1. ดึงข้อมูล JSON ที่ฝังไว้ที่ปุ่ม
        const rawData = btnElement.getAttribute('data-info');
        const data = JSON.parse(rawData);

        // 2. ยัดข้อมูลลง DOM ของ Modal
        document.getElementById('mdReportId').value = data.id;
        document.getElementById('mdName').innerText = "👤 " + data.name;
        document.getElementById('mdClass').innerText = data.class;
        document.getElementById('mdDate').innerText = data.date;
        document.getElementById('mdScore').innerText = data.score;
        document.getElementById('mdGrade').innerText = data.grade;
        
        // เซ็ตสี HP
        let hpVal = document.getElementById('mdHp');
        hpVal.innerText = data.hp + "%";
        if(data.hp > 60) hpVal.style.color = "#10b981";
        else if(data.hp > 30) hpVal.style.color = "#f59e0b";
        else hpVal.style.color = "#ef4444";

        // เซ็ตสีเกรด
        let gradeVal = document.getElementById('mdGrade');
        if(data.grade === 'A') gradeVal.style.color = "#166534";
        else if(data.grade === 'F') gradeVal.style.color = "#991b1b";
        else gradeVal.style.color = "#1e40af";

        // แยกบรรทัด System Log (ข้อมูลเดิมคั่นด้วย | )
        let summaryRaw = data.summary || "ไม่มีบันทึกระบบ";
        let summaryParts = summaryRaw.split(" | ");
        let logHtml = "";
        summaryParts.forEach(part => {
            let detail = part.split(": ");
            if(detail.length === 2) {
                logHtml += `<div class="log-item"><span class="lbl">[${detail[0]}]</span> => ${detail[1]}</div>`;
            } else {
                logHtml += `<div class="log-item">${part}</div>`;
            }
        });
        document.getElementById('mdSummaryLog').innerHTML = logHtml;

        // นำคอมเมนต์เดิมมาใส่ Textarea (ถ้าเคยตรวจแล้ว)
        document.getElementById('teacherCommentInput').value = data.comment;

        // 3. แสดง Modal พร้อมอนิเมชัน
        overlay.style.display = 'flex';
        setTimeout(() => { modalBox.classList.add('show'); }, 10);
    }

    function closeReviewModal() {
        modalBox.classList.remove('show');
        setTimeout(() => { overlay.style.display = 'none'; }, 300);
    }

    // ฟังก์ชันเซฟคอมเมนต์ผ่าน AJAX
    function saveTeacherComment() {
        const reportId = document.getElementById('mdReportId').value;
        const comment = document.getElementById('teacherCommentInput').value;
        const csrf = document.getElementById('csrfToken').value;
        const btnSave = document.getElementById('btnSaveComment');

        // เปลี่ยนสถานะปุ่มกันกดซ้ำ
        btnSave.disabled = true;
        btnSave.innerText = "⏳ กำลังบันทึก...";

        const payload = {
            action: 'save_comment',
            report_id: reportId,
            comment: comment,
            csrf_token: csrf
        };

        fetch('api_teacher_lab.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                // เซฟผ่าน โชว์ Alert แล้วรีเฟรชหน้าเพื่ออัปเดตสถานะในตาราง
                alert("✅ " + data.message);
                location.reload();
            } else {
                alert("❌ เกิดข้อผิดพลาด: " + data.message);
                btnSave.disabled = false;
                btnSave.innerText = "💾 บันทึกผลการตรวจ";
            }
        })
        .catch(err => {
            console.error(err);
            alert("❌ ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้");
            btnSave.disabled = false;
            btnSave.innerText = "💾 บันทึกผลการตรวจ";
        });
    }

</script>

<?php 
$stmt->close();
require_once 'footer.php'; 
?>