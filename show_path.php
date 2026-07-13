<?php
echo "<pre style='font-size:16px;padding:20px;background:#000;color:#0f0'>";
echo "=== MAMP PATH FINDER ===\n\n";
echo "This FILE path: " . __FILE__ . "\n";
echo "This DIR path:  " . __DIR__ . "\n\n";
echo "DOCUMENT_ROOT:  " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n\n";
$lab = __DIR__ . '/lab_realistic.php';
if(file_exists($lab)){
    $lines = count(file($lab));
    echo "lab_realistic.php lines: " . $lines . "\n";
    echo "lab_realistic.php MD5:   " . md5_file($lab) . "\n";
    echo "lab_realistic.php size:  " . filesize($lab) . " bytes\n";
    if($lines == 5672){
        echo "\n✅ FILE IS CORRECT!\n";
    } else {
        echo "\n❌ WRONG FILE! Expected 5672 lines, got " . $lines . "\n";
        echo "   Copy the new lab_realistic.php to: " . __DIR__ . "\n";
    }
} else {
    echo "❌ lab_realistic.php NOT FOUND in " . __DIR__ . "\n";
}
echo "</pre>";
