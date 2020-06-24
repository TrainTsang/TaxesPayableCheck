<?php
date_default_timezone_set("PRC");
//$configEnv = file_get_contents(ROOT_PATH . '/config/env');
//$configEnv = json_decode($configEnv, true);
//switch ($configEnv['mode']) {
//    case 'prod':
//        $configFile = '/config/config.php';
//        break;
//    default:
//        $configFile = '/config/config.sample.php';
//        break;
//}
//require_once ROOT_PATH . $configFile;

spl_autoload_register(function ($class) {
    if (strpos($class, "app\\") === 0) {
        $class = str_replace("app\\", '', $class);
        $class = str_replace("\\", '/', $class);
        include ROOT_PATH . '/app/' . $class . '.class.php';
    } else if (strpos($class, "lib\\") === 0) {
        $class = str_replace("lib\\", '', $class);
        $class = str_replace("\\", '/', $class);
        include ROOT_PATH . '/lib/' . $class . '.class.php';
    }
});
require_once ROOT_PATH . "/vendor/autoload.php";


/**
 * shell输出
 * @param string $txt
 * @param string $color
 * @param string $bg
 * @param bool $needEOL
 */
function func_shell_echo($txt, $color = '', $bg = '', $needEOL = true)
{
    switch ($color) {
        case 'black':
            $color = '30m';
            break;
        case 'red':
            $color = '31m';
            break;
        case 'green':
            $color = '32m';
            break;
        case 'yellow':
            $color = '33m';
            break;
        case 'blue':
            $color = '34m';
            break;
        case 'purple':
            $color = '35m';
            break;
        case 'azure':
            $color = '36m';
            break;
        case 'white':
            $color = '37m';
            break;
        default:
            $color = '';
            break;
    }

    switch ($bg) {
        case 'black':
            $bg = '40;';
            break;
        case 'red':
            $bg = '41;';
            break;
        case 'green':
            $bg = '42;';
            break;
        case 'yellow':
            $bg = '43;';
            break;
        case 'blue':
            $bg = '44;';
            break;
        case 'purple':
            $bg = '45;';
            break;
        case 'azure':
            $bg = '46;';
            break;
        case 'white':
            $bg = '47;';
            break;
        default:
            $bg = '';
            break;
    }
    $t1 = '';
    $t2 = '';
    if (!empty($bg) || !empty($color)) {
        if (!empty($bg)) {
            if (empty($color)) {
                $color = '37m';
            }
        }
        $t1 = "\033[{$bg}{$color}";
        $t2 = "\033[0m";
    }


    echo $t1 . $txt . $t2;
    if ($needEOL) {
        echo PHP_EOL;
    }
    return;
}

/**
 * 标准化内部返回值
 * @param $code
 * @param $msg
 * @param array $data
 * @return array
 */
function func_code_return($code, $msg = '', $data = [])
{
    return ['code' => $code, 'msg' => $msg, 'data' => $data];
}


function func_redis($index = 0, $host = '127.0.0.1', $port = 6379, $password = '', $timeout = 5, $pConnect = false)
{
    $redis = new Redis();
    if ($pConnect) {
        $redis->pconnect($host, $port, $timeout);
    } else {
        $redis->connect($host, $port, $timeout);
    }
    if (!empty($password)) {
        $redis->auth($password);
    }
    $redis->select($index);
    //$redis->setOption($redis::OPT_SERIALIZER, $redis::SERIALIZER_IGBINARY);
    return $redis;
}