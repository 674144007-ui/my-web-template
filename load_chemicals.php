<?php
require_once "db.php";
$res=$pdo->query("SELECT * FROM chemicals");
$out=[];
while($r=$res->fetch()) $out[]=$r;
echo json_encode($out);
?>
