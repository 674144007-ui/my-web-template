<?php
require_once "db.php";
$res=$conn->query("SELECT * FROM chemicals");
$out=[];
while($r=$res->fetch_assoc()) $out[]=$r;
echo json_encode($out);
?>
