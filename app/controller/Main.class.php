<?php

namespace app\controller;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;

set_time_limit(0);
ini_set("memory_limit", "6000M");
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);


class Main
{
    const FILE_MODE_FINISHED = 1;
    const FILE_MODE_TOTAL = 2;

    private $finishedUsersGreen = [];
    private $finishedUsersYellow = [];
    private $finishedUsersBlue = [];
    private $matchCount = [];
    //匹配程度说明
    //绿色：姓名、纳税人识别号、单位纳税识别号、电话
    //黄色：姓名、纳税人识别号、单位纳税识别号；姓名、纳税人识别号、电话
    //蓝色：姓名+纳税人识别号，且没有单位、没有电话


    public function index()
    {
        $start = microtime(true);


        //从已完成的纳税人名单文件存放的目录，读取数据
        $pathFinished = "./public/finished/";
        $finishedFileList = self::scanPath($pathFinished);
        foreach ($finishedFileList as $filename) {
            func_shell_echo("当前文件：$filename", 'green');
            $this->getUserList($filename, self::FILE_MODE_FINISHED);
        }

        func_shell_echo(count($this->finishedUsersGreen), 'green');
        func_shell_echo(count($this->finishedUsersYellow), 'yellow');
        func_shell_echo(count($this->finishedUsersBlue), 'blue');

        $pathTotal = "./public/total/";
        $totalFileList = self::scanPath($pathTotal);

        foreach ($totalFileList as $filename) {
            func_shell_echo("当前文件：$filename", 'green');
            $this->getUserList($filename, self::FILE_MODE_TOTAL);
        }
        var_dump($this->matchCount);
        echo (microtime(true) - $start) . 's';
    }

    /**
     * 递归目录，寻找文件
     * @param $path
     * @return array
     */
    private static function scanPath($path)
    {
        if (substr($path, -1, 1) !== DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }
        $fileList = [];
        $dir = scandir($path);
        foreach ($dir as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            } else if (is_file($path . $item)) {
                $fileList[] = $path . $item;
            } else if (is_dir($path . $item)) {
                $list = self::scanPath($path . $item);
                $fileList = array_merge($fileList, $list);
            }
        }
        return $fileList;
    }

    /**
     * 获取表内的数据
     * @param $fileName
     * @param $fileMode
     * @param string $fileType
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    private function getUserList($fileName, $fileMode, $fileType = 'Xlsx')
    {
        $reader = IOFactory::createReader($fileType);
        $spreadsheet = $reader->load($fileName);

        //如果是读取已经汇算清缴的文件，只读
        //如果是读取区县需要匹配的文件，创建writer
        if ($fileMode == self::FILE_MODE_FINISHED) {
            $reader->setReadDataOnly(TRUE);
        } else if ($fileMode == self::FILE_MODE_TOTAL) {
            $writer = IOFactory::createWriter($spreadsheet, $fileType);
            $writerChange = false;
        }
        //循环sheet
        for ($sheetIndex = 0; $sheetIndex < $spreadsheet->getSheetCount(); $sheetIndex++) {
            $spreadsheet->setActiveSheetIndex($sheetIndex);
            $worksheet = $spreadsheet->getActiveSheet();
            func_shell_echo("当前sheet：" . $spreadsheet->getSheetNames()[$sheetIndex], 'green');

            // Get the highest row number and column letter referenced in the worksheet
            $highestRow = $worksheet->getHighestRow(); // e.g. 10
            $highestColumn = $worksheet->getHighestColumn(); // e.g 'F'
            if ($highestRow == 1 && $highestColumn == 'A') {
                func_shell_echo('空sheet，跳过', 'yellow');
                continue;
            }
            // Increment the highest column letter
            $highestColumn++;
            //初始化空表头信息
            $schema = [];
            //最大的列ID，如果循环行时超过了这个值，可以直接跳到下一行
            $maxSchema = null;
            for ($row = 1; $row <= $highestRow; ++$row) {
                //初始化当前行用户信息
                $thisUser = [];
                for ($col = 'A'; $col != $highestColumn; ++$col) {
                    $value = $worksheet->getCell($col . $row)
                        ->getValue();
//                    func_shell_echo($col . $row);
                    //一般读取完前两行，就能读到表头，如果未读到，就要中断程序，人工检查原因
                    if (count($schema) !== 4) {
                        if ($row <= 2) {
                            self::autoSchema($schema, $col, $value);
                            continue;
                        } else {
                            func_shell_echo("未能正确识别表头信息", 'red');
                            print_r($schema);
                            die();
                        }
                    }
                    //表头已经读取完成，开始筛选数据
                    //判断最大有效的列是多少，这样可以跳过后面的列加快速度
                    if (!is_null($maxSchema) && $col > $maxSchema) {
                        continue;
                    } else if (is_null($maxSchema)) {
                        arsort($schema);
                        foreach ($schema as $schemaItem) {
                            $maxSchema = $schemaItem;
                            break;
                        }
                    }
                    //通过读取到的表头信息，去匹配本行对应的数据
                    self::matchUserData($schema, $col, $value, $thisUser);
                }

                if (empty($thisUser)) {
                    //无效数据
                    func_shell_echo('该行无用户数据，跳过', 'yellow');
                    continue;
                }
//                echo implode("|", $thisUser) . PHP_EOL;
                if (
                    empty($thisUser['user_id'])
                    && empty($thisUser['user_name'])
                    && empty($thisUser['user_tel'])
                    && empty($thisUser['company_id'])
                ) {
                    func_shell_echo('该行用户字段全为empty，跳过', 'yellow');
                    continue;
                }
                //数据有效
                //当读取的是已完成清缴的用户时，写入不同级别的用户列表
                //当读取的是待检查的数据是，直接判断属于哪个类别的用户
                if ($fileMode == self::FILE_MODE_FINISHED) {
                    if (//数据齐全
                        !empty($thisUser['user_id'])
                        && !empty($thisUser['user_name'])
                        && !empty($thisUser['user_tel'])
                        && !empty($thisUser['company_id'])
                    ) {
                        $this->setGreenArr(md5($thisUser['user_id'] . $thisUser['user_name'] . $thisUser['user_tel'] . $thisUser['company_id']));
                        $this->setYellowArr(md5($thisUser['user_id'] . $thisUser['user_name'] . $thisUser['company_id']));
                        $this->setYellowArr(md5($thisUser['user_id'] . $thisUser['user_name'] . $thisUser['user_tel']));
                        $this->setBlueArr(md5($thisUser['user_id'] . $thisUser['user_name']));
                    } else if (//缺失电话或者缺失单位
                        (!empty($thisUser['user_id'])
                            && !empty($thisUser['user_name'])
                            && !empty($thisUser['company_id'])
                            && empty($thisUser['user_tel'])
                        )
                        || (!empty($thisUser['user_id'])
                            && !empty($thisUser['user_name'])
                            && empty($thisUser['company_id'])
                            && !empty($thisUser['user_tel'])
                        )
                    ) {
                        $this->setYellowArr(md5($thisUser['user_id'] . $thisUser['user_name'] . $thisUser['company_id']));
                        $this->setYellowArr(md5($thisUser['user_id'] . $thisUser['user_name'] . $thisUser['user_tel']));
                        $this->setBlueArr(md5($thisUser['user_id'] . $thisUser['user_name']));
                    } else if ( //没有单位且没有电话
                        !empty($thisUser['user_id'])
                        && !empty($thisUser['user_name'])
                        && empty($thisUser['user_tel'])
                        && empty($thisUser['company_id'])
                    ) {
                        $this->setBlueArr(md5($thisUser['user_id'] . $thisUser['user_name']));
                    }
                } elseif ($fileMode == self::FILE_MODE_TOTAL) {
                    if (//数据齐全，检查是否能全匹配，不行的话降级匹配
                        !empty($thisUser['user_id'])
                        && !empty($thisUser['user_name'])
                        && !empty($thisUser['user_tel'])
                        && !empty($thisUser['company_id'])
                    ) {
                        if ($this->matchCheck(
                            'g',
                            $thisUser['user_id'],
                            $thisUser['user_name'],
                            $thisUser['user_tel'],
                            $thisUser['company_id'])
                        ) {
                            echo func_shell_echo(implode("|", $thisUser), 'green');

                            $spreadsheet->getActiveSheet()
                                ->getStyle($schema['user_name'] . $row)
                                ->getFont()
                                ->getColor()
                                ->setARGB(Color::COLOR_DARKGREEN);
                            $writerChange = true;
                            continue;
                        }

                        if ($this->matchCheck(
                            'y',
                            $thisUser['user_id'],
                            $thisUser['user_name'],
                            $thisUser['user_tel'],
                            null)
                        ) {
                            echo func_shell_echo(implode("|", $thisUser), 'yellow');

                            $spreadsheet->getActiveSheet()
                                ->getStyle($schema['user_name'] . $row)
                                ->getFont()
                                ->getColor()
                                ->setARGB(Color::COLOR_DARKYELLOW);
                            $writerChange = true;
                            continue;
                        }

                        if ($this->matchCheck(
                            'y',
                            $thisUser['user_id'],
                            $thisUser['user_name'],
                            null,
                            $thisUser['company_id'])
                        ) {
                            echo func_shell_echo(implode("|", $thisUser), 'blue');

                            $spreadsheet->getActiveSheet()
                                ->getStyle($schema['user_name'] . $row)
                                ->getFont()
                                ->getColor()
                                ->setARGB(Color::COLOR_DARKYELLOW);
                            $writerChange = true;
                            continue;
                        }
                    }

                    if (//缺失电话
                        !empty($thisUser['user_id'])
                        && !empty($thisUser['user_name'])
                        && !empty($thisUser['company_id'])
                        && empty($thisUser['user_tel'])
                    ) {
                        echo func_shell_echo(implode("|", $thisUser), 'blue');

                        if ($this->matchCheck(
                            'y',
                            $thisUser['user_id'],
                            $thisUser['user_name'],
                            null,
                            $thisUser['company_id'])
                        ) {
                            $spreadsheet->getActiveSheet()
                                ->getStyle($schema['user_name'] . $row)
                                ->getFont()
                                ->getColor()
                                ->setARGB(Color::COLOR_DARKYELLOW);
                            $writerChange = true;
                            continue;
                        }
                    }

                    if (//缺失单位
                        !empty($thisUser['user_id'])
                        && !empty($thisUser['user_name'])
                        && empty($thisUser['company_id'])
                        && !empty($thisUser['user_tel'])
                    ) {
                        echo func_shell_echo(implode("|", $thisUser), 'yellow');

                        if ($this->matchCheck(
                            'y',
                            $thisUser['user_id'],
                            $thisUser['user_name'],
                            $thisUser['user_tel'],
                            null)
                        ) {
                            $spreadsheet->getActiveSheet()
                                ->getStyle($schema['user_name'] . $row)
                                ->getFont()
                                ->getColor()
                                ->setARGB(Color::COLOR_DARKYELLOW);
                            $writerChange = true;
                            continue;
                        }
                    }

                    if ( //没有单位且没有电话
                        !empty($thisUser['user_id'])
                        && !empty($thisUser['user_name'])
                        && empty($thisUser['user_tel'])
                        && empty($thisUser['company_id'])
                    ) {
                        if ($this->matchCheck(
                            'b',
                            $thisUser['user_id'],
                            $thisUser['user_name'])
                        ) {
                            $spreadsheet->getActiveSheet()
                                ->getStyle($schema['user_name'] . $row)
                                ->getFont()
                                ->getColor()
                                ->setARGB(Color::COLOR_DARKBLUE);
                            $writerChange = true;
                            continue;
                        }
                    }
                    $this->matchCount['n']++;
                }
            }
        }
        if ($fileMode == self::FILE_MODE_TOTAL && $writerChange) {
            $writer->save($fileName);
        }
    }

    /**
     * 检查是否匹配
     */
    private function matchCheck($level, $userId, $userName, $userTel = null, $companyId = null)
    {
        switch ($level) {
            case 'g':
                $hashId = md5($userId . $userName . $userTel . $companyId);
                if ($this->isGreenArr($hashId)) {
                    $this->matchCount['g']++;
                    return true;
                } else {
                    return false;
                }
                break;
            case 'y':
                if (!empty($userTel)) {
                    $hashId = md5($userId . $userName . $userTel);
                    if ($this->isYellowArr($hashId)) {
                        $this->matchCount['y']++;
                        return true;
                    } else {
                        return false;
                    }
                }
                if (!empty($companyId)) {
                    $hashId = md5($userId . $userName . $companyId);
                    if ($this->isYellowArr($hashId)) {
                        $this->matchCount['y']++;
                        return true;
                    } else {
                        return false;
                    }
                }
                die('y级校验必须有tel或者companyId');
                break;
            case 'b':
                $hashId = md5($userId . $userName);
                if ($this->isBlueArr($hashId)) {
                    $this->matchCount['b']++;
                    return true;
                } else {
                    return false;
                }
                break;
            default:
                die('错误的检验模式');
                break;
        }
    }

    /**
     * 自动寻找相关信息所在列
     */
    private static function autoSchema(&$schema, $col, $value)
    {
        //自动找出姓名所在的列
        if (
            strpos($value, '姓名') !== false
            && !isset($schema['user_name'])
        ) {
            $schema['user_name'] = $col;
        }
        //自动找出纳税人识别号所在的列
        if (
            strpos($value, '纳税人识别号') !== false
            && !isset($schema['user_id'])
        ) {
            $schema['user_id'] = $col;
        }
        //自动找出联系方式所在的列
        if (
            (
                strpos($value, '联系电话') !== false
                || strpos($value, '联系方式') !== false
            )
            && !isset($schema['user_tel'])) {
            $schema['user_tel'] = $col;
        }
        //自动找出扣缴义务人纳税识别号所在的列
        if (
            (
                strpos($value, '扣缴义务人') !== false
                && strpos($value, '识别号') !== false
            )
            && !isset($schema['company_id'])) {
            $schema['company_id'] = $col;
        }
    }

    /**
     * 根据schema匹配用户数据
     * @param $schema
     * @param $col
     * @param $value
     * @param $thisUser
     * @return mixed
     */
    private static function matchUserData($schema, $col, $value, &$thisUser)
    {
        $value = trim($value);
        switch ($col) {
            case $schema['user_id']:
                //身份证全为星号，视为没有身份证
                if (empty($value) || trim($value, '*') == '') {
                    $thisUser['user_id'] = "";
                } else if (strlen($value) == 18) {
                    $thisUser['user_id'] = substr_replace($value, '**********', 4, 10);
                } else {
                    $thisUser['user_id'] = $value;
                    func_shell_echo("身份证号码非18位:" . $value, 'yellow');
                }
                break;
            case $schema['user_name']:
                if (empty($value)) {
                    $thisUser['user_name'] = "";
                } else {
                    $thisUser['user_name'] = mb_substr($value, 0, 1, "UTF-8") . mb_strlen($value, "UTF-8");
                }
                break;
            case $schema['user_tel']:
                if (empty($value) || strlen($value) < 7) {
                    $thisUser['user_tel'] = "";
                } else if (strlen($value) == 11) {
                    $thisUser['user_tel'] = substr_replace($value, '****', 3, 4);
                } else {
                    $thisUser['user_tel'] = $value;
                    func_shell_echo("用户电话位数非11位:" . $value, 'yellow');
                }
                break;
            case $schema['company_id']:
                if (empty($value)) {
                    $thisUser['company_id'] = "";
                } else {
                    $thisUser['company_id'] = $value;
                }
                break;
        }
        return $thisUser;
    }


    private function setGreenArr($val)
    {
//        $this->finishedUsersGreen[] = $val;
        $this->finishedUsersGreen[$val]='';
    }
    private function setYellowArr($val)
    {
//        $this->finishedUsersYellow[] = $val;
        $this->finishedUsersYellow[$val]='';
    }

    private function setBlueArr($val)
    {
//        $this->finishedUsersBlue[] = $val;
        $this->finishedUsersBlue[$val]='';
    }

    private function isGreenArr($val)
    {
//        return in_array($val, $this->finishedUsersGreen);
        return isset($this->finishedUsersGreen[$val]);
//        return array_key_exists($val,$this->finishedUsersGreen);
    }

    private function isYellowArr($val)
    {
//        return in_array($val, $this->finishedUsersYellow);
        return isset($this->finishedUsersYellow[$val]);
//        return array_key_exists($val,$this->finishedUsersYellow);
    }


    private function isBlueArr($val)
    {
//        return in_array($val, $this->finishedUsersBlue);
        return isset($this->finishedUsersBlue[$val]);
//        return array_key_exists($val,$this->finishedUsersBlue);
    }


}