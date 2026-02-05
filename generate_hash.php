<?php
$pwds = [
    'Dev@2025!',
    'Tea@2025!',
    'Stu@2025!',
    'Par@2025!'
];

foreach ($pwds as $p) {
    echo $p . " => " . password_hash($p, PASSWORD_BCRYPT) . "<br>";
}
