<?php
require 'vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$s = require 'config/settings.php';
$pdo = (new App\Database($s['db']))->bikershop();
$rows = $pdo->query("SELECT p.id_product,p.reference,p.supplier_reference,p.active,p.price,p.id_category_default,p.id_manufacturer,pl.name,pl.link_rewrite,m.name man
 FROM ps_product p JOIN ps_product_lang pl ON pl.id_product=p.id_product AND pl.id_lang=1 AND pl.id_shop=1
 LEFT JOIN ps_manufacturer m ON m.id_manufacturer=p.id_manufacturer
 WHERE pl.name LIKE 'CFMOTO %' AND (pl.name REGEXP '20[0-9]{2}$' OR p.reference LIKE 'cfmoto-%') ORDER BY pl.name LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) echo implode(' | ',$r),"\n";
echo count($rows),"\n";
if($rows){ $id=$rows[0]['id_product'];
 foreach($pdo->query("SELECT * FROM ps_product_supplier WHERE id_product=$id",PDO::FETCH_ASSOC) as $r) echo json_encode($r),"\n";
 foreach($pdo->query("SELECT pa.id_product_attribute,pa.reference,pa.supplier_reference,pa.price FROM ps_product_attribute pa WHERE id_product=$id",PDO::FETCH_ASSOC) as $r) echo json_encode($r),"\n";}
