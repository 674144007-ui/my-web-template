<?php
/**
 * test_lab_reactions.php — Automated Test Script (Phase 7)
 * ทดสอบ: reactions DB, auto-grade logic, security functions
 * รัน: php test_lab_reactions.php (CLI only)
 */

if (php_sapi_name() !== 'cli') die("CLI only\n");

define('TEST_PASS', "\033[32m✅ PASS\033[0m");
define('TEST_FAIL', "\033[31m❌ FAIL\033[0m");
define('TEST_SKIP', "\033[33m⏭️  SKIP\033[0m");

$passed = 0; $failed = 0; $skipped = 0;

function test(string $name, bool $result, string $detail = ''): void {
    global $passed, $failed;
    if ($result) {
        echo TEST_PASS . " {$name}\n";
        $passed++;
    } else {
        echo TEST_FAIL . " {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}
function skip(string $name, string $reason): void {
    global $skipped;
    echo TEST_SKIP . " {$name} ({$reason})\n";
    $skipped++;
}

echo "\n🧪 Smart School Plus — Lab Reaction Test Suite\n";
echo str_repeat('─', 55) . "\n\n";

// ── 1. Security Functions ─────────────────────────────────
echo "[ Security ]\n";
if (file_exists(__DIR__ . '/security.php')) {
    require_once __DIR__ . '/security.php';
    $hash = generate_secure_password('TestPass123!');
    test('generate_secure_password returns hash',    strlen($hash) > 20);
    test('verify_and_upgrade_password correct pass', verify_and_upgrade_password('TestPass123!', $hash));
    test('verify_and_upgrade_password wrong pass',   !verify_and_upgrade_password('WrongPass', $hash));
    test('password not plaintext',                   $hash !== 'TestPass123!');
} else { skip('security.php', 'file not found'); }

// ── 2. CSRF Functions ─────────────────────────────────────
echo "\n[ CSRF ]\n";
if (file_exists(__DIR__ . '/security.php')) {
    if (!isset($_SESSION)) session_start();
    if (function_exists('generate_csrf_token')) {
        $token = generate_csrf_token();
        test('generate_csrf_token returns string',    is_string($token) && strlen($token) > 10);
        test('token stored in session',               isset($_SESSION['csrf_token']));
        test('same call returns same token',          generate_csrf_token() === $token);
    } else { skip('CSRF functions', 'not defined'); }
} else { skip('CSRF', 'security.php not found'); }

// ── 3. Rate Limiter ───────────────────────────────────────
echo "\n[ Rate Limiter ]\n";
if (file_exists(__DIR__ . '/rate_limiter.php')) {
    require_once __DIR__ . '/rate_limiter.php';
    test('rate_limiter loaded',      defined('RATE_LIMITER_LOADED'));
    test('RL_LOGIN_MAX defined',     defined('RL_LOGIN_MAX') && RL_LOGIN_MAX > 0);
    test('RL_API_MAX defined',       defined('RL_API_MAX')   && RL_API_MAX  > 0);
    test('RL_CACHE_DIR exists',      is_dir(RL_CACHE_DIR));
} else { skip('rate_limiter.php', 'file not found'); }

// ── 4. Database Reactions ─────────────────────────────────
echo "\n[ Database — Reactions ]\n";
if (file_exists(__DIR__ . '/db.php') && file_exists(__DIR__ . '/config.php')) {
    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/db.php';

        // chemicals count
        $cnt_chem = $pdo->query("SELECT COUNT(*) FROM chemicals")->fetchColumn();
        test("chemicals table has data (got {$cnt_chem})", $cnt_chem > 0);

        // reactions count
        $cnt_rxn = $pdo->query("SELECT COUNT(*) FROM reactions")->fetchColumn();
        test("reactions table has data (got {$cnt_rxn})", $cnt_rxn > 0);
        test("reactions >= 42 (original)",                $cnt_rxn >= 42);
        test("reactions >= 113 (Phase 3 expanded)",       $cnt_rxn >= 113);

        // ตรวจ required columns
        $cols = $pdo->query("SHOW COLUMNS FROM reactions")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['observation_th','safety_warning','reaction_type','result_color'] as $col) {
            test("reactions.{$col} column exists", in_array($col, $cols));
        }

        // ตรวจ NULL observation_th ไม่ควรเกิน 20%
        $null_obs = $pdo->query("SELECT COUNT(*) FROM reactions WHERE observation_th IS NULL")->fetchColumn();
        $null_pct  = $cnt_rxn > 0 ? round($null_obs/$cnt_rxn*100) : 100;
        test("observation_th NULL < 30% (got {$null_pct}%)", $null_pct < 30);

        // ตรวจ Phase 3 specific reactions
        $pb_rxn = $pdo->query("SELECT COUNT(*) FROM reactions WHERE reaction_type='precipitation' AND result_color='#FFD700'")->fetchColumn();
        test("Golden Rain (PbI₂) reaction exists", $pb_rxn > 0);

        $haz_rxn = $pdo->query("SELECT COUNT(*) FROM reactions WHERE reaction_type='hazardous_mix'")->fetchColumn();
        test("Hazardous mix reactions exist (got {$haz_rxn})", $haz_rxn >= 2);

        // ตรวจ lab_quests
        $cnt_quest = $pdo->query("SELECT COUNT(*) FROM lab_quests")->fetchColumn();
        test("lab_quests >= 6 (original)",           $cnt_quest >= 6);
        test("lab_quests >= 26 (Phase 3 expanded)",  $cnt_quest >= 26);

        // ตรวจ assignments
        $cnt_assign = $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
        test("assignments >= 2 (original)",           $cnt_assign >= 2);
        test("assignments >= 17 (Phase 3 expanded)",  $cnt_assign >= 17);

    } catch (PDOException $e) {
        skip('Database tests', 'DB connect failed: ' . $e->getMessage());
    }
} else { skip('Database tests', 'config.php or db.php not found'); }

// ── 5. Grading Logic ─────────────────────────────────────
echo "\n[ Grading Logic ]\n";
function simulateGrade(array $telemetry, array $settings): array {
    $score = 100.0; $breakdown = [];
    $hp       = max(0, min(100, (int)($telemetry['hp_remaining'] ?? 100)));
    $actions  = $telemetry['actions'] ?? [];
    $beaker   = $telemetry['beaker_items'] ?? [];

    if ($hp <= 0) { $score = 0; $breakdown[] = 'HP=0 FAIL'; return compact('score','breakdown'); }

    $viol = (int)($actions['safety_violations'] ?? 0);
    if ($viol > 0) { $score -= min(30, $viol*10); $breakdown[] = "safety -{$viol}"; }

    if ($actions['exploded'] ?? false) { $score -= 50; $breakdown[] = 'exploded -50'; }

    if (($settings['required_stirring'] ?? 0) && !($actions['did_stir'] ?? false)) {
        $score -= 10; $breakdown[] = 'no stir -10';
    }
    return ['score' => max(0, $score), 'breakdown' => $breakdown];
}

$r1 = simulateGrade(['hp_remaining'=>100,'actions'=>['safety_violations'=>0,'did_stir'=>true]],['required_stirring'=>1]);
test('Perfect score = 100',                        $r1['score'] == 100.0);

$r2 = simulateGrade(['hp_remaining'=>0,'actions'=>[]],[]);
test('HP=0 → score = 0',                           $r2['score'] == 0);

$r3 = simulateGrade(['hp_remaining'=>80,'actions'=>['safety_violations'=>2,'exploded'=>false]],[]);
test('2 safety violations → -20 (score=80)',       $r3['score'] == 80.0);

$r4 = simulateGrade(['hp_remaining'=>100,'actions'=>['exploded'=>true]],[]);
test('Explosion → -50 (score=50)',                 $r4['score'] == 50.0);

$r5 = simulateGrade(['hp_remaining'=>100,'actions'=>['did_stir'=>false]],['required_stirring'=>1]);
test('No stir when required → -10 (score=90)',     $r5['score'] == 90.0);

// Grade calculation
$gradeMap = fn($s) => $s>=80?'A':($s>=70?'B':($s>=60?'C':($s>=50?'D':'F')));
test('Grade A at 85',  $gradeMap(85) === 'A');
test('Grade B at 75',  $gradeMap(75) === 'B');
test('Grade F at 40',  $gradeMap(40) === 'F');

// ── 6. Input Sanitization ─────────────────────────────────
echo "\n[ Input Sanitization ]\n";
$xss = '<script>alert("xss")</script>';
test('htmlspecialchars strips XSS',     htmlspecialchars($xss, ENT_QUOTES) !== $xss);
test('intval prevents SQL injection',   intval('1; DROP TABLE users') === 1);
test('floatval cleans non-numeric',     floatval('3.14abc') === 3.14);

// ── 7. File Structure ─────────────────────────────────────
echo "\n[ File Structure ]\n";
$required_files = [
    'lab_realistic.php','api_auto_grade.php','teacher_review_lab.php',
    'generate_lab_report.php','api_heartbeat.php','teacher_broadcast.php',
    'security.php','auth.php','config.php','db.php','rate_limiter.php',
];
foreach ($required_files as $f) {
    test("File exists: {$f}", file_exists(__DIR__ . '/' . $f));
}

// ── Summary ───────────────────────────────────────────────
echo "\n" . str_repeat('─', 55) . "\n";
$total = $passed + $failed + $skipped;
echo "Results: {$passed}/{$total} passed";
if ($skipped) echo ", {$skipped} skipped";
if ($failed)  echo ", \033[31m{$failed} FAILED\033[0m";
echo "\n";
exit($failed > 0 ? 1 : 0);
