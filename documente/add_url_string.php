<?php 
include_once("../__connect.php");

$select_products_query = "SELECT `products`.`id_product`,`products`.`nume`,`products`.`an`,`categories`.`category_name`,`categories`.`category_parent` FROM `products` LEFT JOIN `categories` ON `products`.`categorie` =`categories`.`id_categories`";
$select_products = mysqli_query($link, $select_products_query);

while($product = mysqli_fetch_assoc($select_products)) {
	
$select_main=mysqli_query($link,"SELECT `category_name` FROM `categories` WHERE `id_categories`=".$product['category_parent']);    
$main=mysqli_fetch_assoc($select_main);

$nume=str_replace("/","",$product['nume']);

    $url_string_full = str_replace(" ", "-", strtolower( $main['category_name'] ."-yamaha/" . $product['category_name'] . "/" . $nume . "-" . $product['an'] . ".html"));
    $url_string_full = str_replace("---","-",$url_string_full);
    $url_string_full = str_replace("+","-plus",$url_string_full);
    $url_string_full = str_replace("-//-","-",$url_string_full);
    $url_string_full = str_replace("®","",$url_string_full);
    $url_string_full = str_replace("™","",$url_string_full);
    $url_string_full = str_replace("²","",$url_string_full);
    $url_string_full = str_replace("--","-",$url_string_full);
    $url_string_full = str_replace("-2024-2024","-2024",$url_string_full);
    $url_string_full = str_replace("-2025-2025","-2025",$url_string_full);
	
	//echo $url_string_full;
    //echo "<br />";
    
    $url_string_short = str_replace(" ", "-", strtolower($nume . "-" . $product['an']));
    $url_string_short = str_replace("---","-",$url_string_short);
    $url_string_short = str_replace("---","-",$url_string_short);
    $url_string_short = str_replace("+","-plus",$url_string_short);
    $url_string_short = str_replace("-//-","-",$url_string_short);
    $url_string_short = str_replace("®","-",$url_string_short);
    $url_string_short = str_replace("™","-",$url_string_short);
    $url_string_short = str_replace("²","-",$url_string_short);
    $url_string_short = str_replace("--","-",$url_string_short);
    $url_string_short = str_replace("-2024-2024","-2024",$url_string_short);
    $url_string_short = str_replace("-2025-2025","-2025",$url_string_short);
	
    //echo $url_string_short;
	//echo "<hr />";
    
    $update_url_string_query = "UPDATE `products` SET `url_string_full` = '$url_string_full', `url_string_short` = '$url_string_short' WHERE `id_product` = " . $product['id_product'];
    
    mysqli_query($link, $update_url_string_query);   
}