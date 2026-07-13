<?php
/**
 * verify_copy.php — ตรวจสอบว่าไฟล์ถูก copy ถูกต้องหรือไม่
 * วางไฟล์นี้ใน my-web-template-main/ แล้วเปิด browser
 */
$files = [
    'lab_realistic.php'       => ['min_lines' => 5600, 'max_lines' => 5800],
    'api_auto_grade.php'      => ['min_lines' => 300,  'max_lines' => 400],
    'api_heartbeat.php'       => ['min_lines' => 60,   'max_lines' => 120],
    'teacher_review_lab.php'  => ['min_lines' => 350,  'max_lines' => 450],
    'generate_lab_report.php' => ['min_lines' => 250,  'max_lines' => 350],
    'teacher_broadcast.php'   => ['min_lines' => 200,  'max_lines' => 310],
    'lab_analytics.php'       => ['min_lines' => 320,  'max_lines' => 420],
    'sw.js'                   => ['min_lines' => 30,   'max_lines' => 80],
];

echo '<style>
body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:30px}
h2{color:#38bdf8}
.ok{color:#4ade80} .err{color:#f87171} .warn{color:#fbbf24}
table{border-collapse:collapse;width:100%}
td,th{padding:8px 14px;border:1px solid #334155;text-align:left}
th{background:#1e293b;color:#94a3b8}
</style>';
echo '<h2>🔍 ตรวจสอบไฟล์ที่ copy</h2><table>';
echo '<tr><th>ไฟล์</th><th>บรรทัด</th><th>สถานะ</th><th>หมายเหตุ</th></tr>';

$all_ok = true;
foreach ($files as $file => $range) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "<tr><td>{$file}</td><td>-</td><td class='err'>❌ ไม่มีไฟล์</td><td>ต้อง copy ไฟล์นี้เข้ามา</td></tr>";
        $all_ok = false;
        continue;
    }
    $lines = count(file($path));
    $ok = $lines >= $range['min_lines'] && $lines <= $range['max_lines'];
    if (!$ok) $all_ok = false;
    $cls = $ok ? 'ok' : 'err';
    $note = $ok ? 'ถูกต้อง' : "ต้องการ {$range['min_lines']}-{$range['max_lines']} บรรทัด (ได้ {$lines}) → copy ไฟล์ใหม่";
    echo "<tr><td>{$file}</td><td>{$lines}</td><td class='{$cls}'>" . ($ok?'✅':'❌') . "</td><td>{$note}</td></tr>";
}

echo '</table><br>';
if ($all_ok) {
    echo '<p class="ok" style="font-size:1.3rem">✅ ทุกไฟล์ถูกต้อง! ลบไฟล์ verify_copy.php นี้ทิ้งได้เลย</p>';
} else {
    echo '<p class="err" style="font-size:1.2rem">❌ มีไฟล์ที่ยังไม่ถูก copy — ดูรายการสีแดงด้านบน</p>';
    echo '<p class="warn">📌 วิธี copy: เปิดโฟลเดอร์ที่ดาวน์โหลดจาก Claude → ลาก/วางไฟล์สีแดงไปทับในโฟลเดอร์ my-web-template-main/</p>';
}
