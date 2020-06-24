<?php

namespace lib;

/**
 * 日志
 * Class LogHelper
 * @package lib
 */
class LogHelper
{
    /**
     * 临时写的一个快速存储文件日志的方法
     * @param string $class
     * @param string $func
     * @param string $logContent
     * @param string $error
     * @param string|array $data
     * @param string $lastSql
     * @param int|null $time
     * @param string $path
     * @param string $filename
     * @param bool $isAppend
     * @return false|int
     */
    public static function fastFileLog($class, $func, $logContent, $error = '', $data = '', $lastSql = '', $time = null, $path = '/data/log/error/', $filename = '', $isAppend = true)
    {
        if (empty($time)) {
            $time = time();
        }

        $path = ltrim($path, ROOT_PATH);
        if (mb_substr($path, 0, 1, "UTF8") !== '/') {
            $path = ROOT_PATH . '/' . $path;
        } else {
            $path = ROOT_PATH . $path;
        }
        $text = "";

        if ($error != "") {
            $text .= "【错误原因】" . $error . "\r\n";
        }
        if ($data != "") {
            if (is_array($data)) {
                $text .= "【相关数据】" . json_encode($data, JSON_UNESCAPED_UNICODE) . "\r\n";
            } else {
                $text .= "【相关数据】" . $data . "\r\n";
            }
        }
        if ($lastSql != "") {
            $text .= "【最后SQL】" . $lastSql . "\r\n";
        }

        if ($text != "") {
            $text = "\r\n" . $text;
        }

        //FIXME 暂时没有处理创建失败的情况
        self::createDir($path);

        if (empty($filename)) {
            $filename = date("Y-m-d", $time) . '.txt';
        }

        $log = date("Y-m-d H:i:s", $time) . "【class】" . $class . "【func】" . $func . "\r\n【日志内容】" . $logContent . $text . "\r\n\r\n";

        if ($isAppend) {
            return @file_put_contents($path . $filename, $log, FILE_APPEND);
        } else {
            return @file_put_contents($path . $filename, $log);
        }
    }

    /**
     * 创建目录
     * @param string $path
     * @param int $permission
     * @return bool
     */
    public static function createDir($path, $permission = 0766)
    {
        //判断路径是不是/结尾
        if (mb_substr($path, -1, 1, "UTF8") !== "/") {
            $path = $path . "/";
        }

        if (!is_dir($path)) {
            $res = mkdir($path, $permission, true);
        } else {
            $res = true;
        }
        return $res;
    }

}