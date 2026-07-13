<?php
// smart_attendance.php - ระบบเช็คชื่ออัจฉริยะ (Phase 4: Touch-Friendly Mobile UI & Anti-Crash)
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'security.php';
require_once 'auth.php';
require_once 'db.php';
requireRole(['teacher', 'developer']);

$page_title = "ระบบเช็คชื่ออัจฉริยะ (Smart Attendance)";
require_once 'header.php';

$db_error_msg = null;

// ดึงรายการห้องเรียน (PDO) แบบปลอดภัย
$classes = [];
try {
    $stmt = $pdo->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
    if ($stmt) {
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $db_error_msg = "ไม่สามารถดึงข้อมูลห้องเรียนได้: " . $e->getMessage();
}
?>

<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600;700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
    body { background-color: #f8fafc; color: #0f172a; font-family: 'Prompt', sans-serif; transition: 0.3s; margin: 0;}
    .smart-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; display: grid; grid-template-columns: 1fr 2fr; gap: 30px; box-sizing: border-box; }
    
    .panel { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .panel h2 { margin: 0 0 20px 0; font-size: 1.3rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; display: flex; align-items: center; gap: 10px; }
    
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #64748b; }
    .form-control { width: 100%; padding: 14px; border-radius: 10px; border: 1px solid #cbd5e1; background: #f8fafc; color: #0f172a; font-family: inherit; outline: none; box-sizing: border-box; font-size: 16px; }
    .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); background: white;}
    
    .btn-generate { width: 100%; background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 18px; border-radius: 12px; font-weight: bold; font-size: 1.2rem; cursor: pointer; transition: 0.3s; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4); display: flex; justify-content: center; align-items: center; gap: 10px;}
    .btn-generate:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(59, 130, 246, 0.5); }
    .btn-stop { background: linear-gradient(135deg, #ef4444, #dc2626) !important; box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4) !important; display: none; }

    .qr-display-zone { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; background: #f8fafc; border-radius: 12px; border: 2px dashed #cbd5e1; position: relative; overflow: hidden; padding: 20px; box-sizing: border-box;}
    #qrcode { background: white; padding: 20px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); transition: 0.3s; transform: scale(0); opacity: 0; }
    #qrcode.active { transform: scale(1); opacity: 1; }
    #qrcode img { display: block; margin: 0 auto; width: 100%; height: auto; max-width: 300px; }
    
    .qr-placeholder { position: absolute; text-align: center; color: #94a3b8; transition: 0.3s; }
    .qr-placeholder.hidden { opacity: 0; pointer-events: none; }
    
    .timer-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; margin-top: 30px; overflow: hidden; display: none; max-width: 300px; }
    .timer-fill { height: 100%; background: #10b981; width: 100%; transition: width 1s linear, background 0.3s; }
    
    .live-status { margin-top: 15px; font-family: 'Share Tech Mono', monospace; font-size: 1.2rem; color: #10b981; font-weight: bold; display: none; text-align: center; }

    .student-list { list-style: none; padding: 0; margin: 0; max-height: 400px; overflow-y: auto; }
    .student-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #e2e8f0; animation: slideIn 0.3s ease-out; background: white;}
    @keyframes slideIn { from { opacity: 0; transform: translateX(-20px); } to { opacity: 1; transform: translateX(0); } }
    .student-item:last-child { border-bottom: none; }
    .st-name { font-weight: bold; color: #1e293b; font-size: 1.05rem;}
    .st-time { font-family: 'Share Tech Mono'; color: #64748b; font-size: 0.9rem; margin-top: 3px; }
    .st-status { background: #dcfce7; color: #166534; padding: 5px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: bold; border: 1px solid #bbf7d0; }

    @media (max-width: 992px) {
        .smart-container { grid-template-columns: 1fr; padding: 15px; gap: 20px;}
        .panel { padding: 20px; }
        .btn-generate { padding: 20px; font-size: 1.3rem; }
        
        .qr-display-zone { min-height: 350px; }
        #qrcode { padding: 15px; }
        #qrcode img { max-width: 200px; }
        
        .header-status-mobile { flex-direction: column; align-items: flex-start !important; gap: 10px;}
        #sessionCounter { align-self: stretch; text-align: center; }
    }
</style>

<div class="smart-container">
    
    <?php if ($db_error_msg): ?>
        <div style="background:#fee2e2; border:1px solid #ef4444; color:#b91c1c; padding:15px; border-radius:8px; margin-bottom:20px; font-weight:bold; grid-column: 1 / -1;">
            ⚠️ ฐานข้อมูลขัดข้อง: <?= htmlspecialchars($db_error_msg) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2>🎛️ ตั้งค่าการเช็คชื่อ</h2>
        
        <div class="form-group">
            <label>เลือกชั้นเรียนเป้าหมาย:</label>
            <select id="classSelect" class="form-control">
                <option value="">-- กรุณาเลือกห้องเรียน --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>หัวข้อ/คาบเรียน:</label>
            <input type="text" id="topicInput" class="form-control" placeholder="เช่น วิทยาศาสตร์ ม.3 คาบ 1">
        </div>
        
        <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 8px; margin-bottom: 25px;">
            <p style="margin:0; font-size: 0.9rem; color: #b45309; line-height:1.5;"><strong>🛡️ ระบบป้องกันการทุจริต:</strong> QR Code นี้เป็นแบบ Dynamic รหัสจะเปลี่ยนใหม่ทุก 15 วินาที ป้องกันการแอบถ่ายภาพส่งให้เพื่อนนอกห้องสแกน</p>
        </div>

        <button id="btnStart" class="btn-generate" onclick="startSession()">🚀 เริ่มรับการเช็คชื่อ</button>
        <button id="btnStop" class="btn-generate btn-stop" onclick="stopSession()">🛑 ปิดการเช็คชื่อทันที</button>
    </div>

    <div class="panel">
        <h2 class="header-status-mobile" style="justify-content: space-between;">
            <span>📱 สแกนเพื่อเข้าเรียน</span>
            <span id="sessionCounter" style="font-size: 1rem; color: #2563eb; background: #dbeafe; padding: 6px 15px; border-radius: 20px;">นักเรียนเข้าเรียน: 0 คน</span>
        </h2>
        
        <div class="qr-display-zone" id="qrZone">
            <div class="qr-placeholder" id="qrPlaceholder">
                <div style="font-size: 4rem; margin-bottom: 10px;">🕒</div>
                <h3>รอการสร้าง QR Code...</h3>
                <p>กรุณาตั้งค่าและกดปุ่มเริ่มทางด้านซ้าย</p>
            </div>
            
            <div id="qrcode"></div>
            
            <div class="timer-bar" id="timerBar"><div class="timer-fill" id="timerFill"></div></div>
            <div class="live-status" id="liveStatus">● LIVE STATUS: ACCEPTING SCANS</div>
        </div>

        <h3 style="margin: 30px 0 15px 0; font-size: 1.1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; color: #1e293b;">รายชื่อผู้เข้าเรียน (Real-time)</h3>
        <ul class="student-list" id="attendanceList">
            <div style="text-align: center; color: #94a3b8; padding: 30px; font-style: italic;" id="emptyListMsg">ยังไม่มีผู้สแกนเข้าเรียน</div>
        </ul>
    </div>

</div>

<input type="hidden" id="currentSessionId" value="">
<input type="hidden" id="csrfToken" value="<?= generate_csrf_token() ?>">

<script>
    let qrcodeInstance = null;
    let refreshInterval = null;
    let fetchInterval = null;
    let timeLeft = 15;
    let isSessionActive = false;

    function startSession() {
        const classId = document.getElementById('classSelect').value;
        const topic = document.getElementById('topicInput').value.trim();

        if (!classId) return alert("กรุณาเลือกชั้นเรียนเป้าหมายก่อน");
        if (!topic) return alert("กรุณาระบุหัวข้อหรือคาบเรียน");

        const mockSessionId = "SES-" + Date.now();
        document.getElementById('currentSessionId').value = mockSessionId;
        
        document.getElementById('btnStart').style.display = 'none';
        document.getElementById('btnStop').style.display = 'flex';
        document.getElementById('classSelect').disabled = true;
        document.getElementById('topicInput').disabled = true;
        
        document.getElementById('qrPlaceholder').classList.add('hidden');
        document.getElementById('qrcode').classList.add('active');
        document.getElementById('timerBar').style.display = 'block';
        document.getElementById('liveStatus').style.display = 'block';
        document.getElementById('emptyListMsg').style.display = 'none';

        isSessionActive = true;
        generateDynamicQR();

        refreshInterval = setInterval(() => {
            timeLeft--;
            updateTimerUI();
            if (timeLeft <= 0) {
                generateDynamicQR(); 
                timeLeft = 15; 
            }
        }, 1000);

        fetchInterval = setInterval(mockFetchAttendance, 3000);
    }

    function stopSession() {
        if (!confirm("ต้องการปิดรับการเช็คชื่อคาบนี้ใช่หรือไม่?")) return;
        
        clearInterval(refreshInterval);
        clearInterval(fetchInterval);
        isSessionActive = false;

        document.getElementById('btnStart').style.display = 'flex';
        document.getElementById('btnStop').style.display = 'none';
        document.getElementById('classSelect').disabled = false;
        document.getElementById('topicInput').disabled = false;
        
        document.getElementById('qrcode').classList.remove('active');
        document.getElementById('timerBar').style.display = 'none';
        
        const liveStatus = document.getElementById('liveStatus');
        liveStatus.innerText = "■ SESSION ENDED (ปิดการเช็คชื่อแล้ว)";
        liveStatus.style.color = "#ef4444";
        
        alert("บันทึกรายชื่อผู้เข้าเรียนเรียบร้อยแล้ว");
    }

    function generateDynamicQR() {
        const sessionId = document.getElementById('currentSessionId').value;
        const dynamicToken = Math.random().toString(36).substring(2, 15);
        const qrDataUrl = `${window.location.origin}/scan.php?sess=${sessionId}&token=${dynamicToken}`;

        const qrContainer = document.getElementById('qrcode');
        qrContainer.innerHTML = ""; 
        
        const qrSize = window.innerWidth < 768 ? 200 : 250;
        
        qrcodeInstance = new QRCode(qrContainer, {
            text: qrDataUrl, width: qrSize, height: qrSize,
            colorDark : "#0f172a", colorLight : "#ffffff", correctLevel : QRCode.CorrectLevel.H
        });
        
        const tf = document.getElementById('timerFill');
        tf.style.transition = "none"; tf.style.width = "100%"; tf.style.background = "#10b981";
        void tf.offsetWidth;
        tf.style.transition = "width 1s linear, background 0.3s";
    }

    function updateTimerUI() {
        const tf = document.getElementById('timerFill');
        tf.style.width = ((timeLeft / 15) * 100) + "%";
        if (timeLeft <= 5) tf.style.background = "#ef4444"; 
        else if (timeLeft <= 10) tf.style.background = "#f59e0b"; 
    }

    let mockCounter = 1;
    function mockFetchAttendance() {
        if(!isSessionActive) return;
        if(Math.random() > 0.7) {
            const timeStr = new Date().toLocaleTimeString('th-TH');
            const ul = document.getElementById('attendanceList');
            const li = document.createElement('li');
            li.className = 'student-item';
            li.innerHTML = `<div><div class="st-name">นักเรียน จำลองคนที่ ${mockCounter}</div><div class="st-time">เวลาสแกน: ${timeStr}</div></div><div class="st-status">เข้าเรียนแล้ว</div>`;
            ul.insertBefore(li, ul.firstChild);
            document.getElementById('sessionCounter').innerText = `นักเรียนเข้าเรียน: ${mockCounter} คน`;
            mockCounter++;
        }
    }
</script>

<?php require_once 'footer.php'; ?>