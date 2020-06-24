<?php
define('ROOT_PATH', dirname(__FILE__));
require_once ROOT_PATH . "/lib/function.php";

$class = new \app\controller\Main();
$class->index();