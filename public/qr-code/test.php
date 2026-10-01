<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('memory_limit', '256M');
header("Content-type:application/json;charset=utf-8");

session_start();

if(!isset($_SESSION['think'])){
    echo json_encode(["code"=>-1,"msg"=>"未登录","data"=>""]);
    exit();
}

// 获取原始图片二进制数据
$blob = null;
if (isset($_POST['base64']) && $_POST['base64'] !== ''){
    $blob = base64_decode($_POST['base64']);
} elseif (isset($_FILES['file']) && $_FILES['file']['tmp_name']){
    $blob = file_get_contents($_FILES['file']['tmp_name']);
}

if (!$blob) {
    echo json_encode(["code"=>-1,"msg"=>"未收到图片数据","data"=>""]);
    exit();
}

// 保存图片用于调试/日志（保留）
$img_path = './image/'.md5($blob).'.jpg';
@file_put_contents($img_path, $blob, LOCK_EX);

// 优先尝试本地识别（不再依赖外网 cli.im，因为内网IP外网不可访问）
$text = '';
$localErr = '';

if (extension_loaded('imagick') || extension_loaded('gd')) {
    try {
        include_once('./lib/QrReader.php');
        // 强制使用GD而非Imagick（Imagick对某些图片格式报Unknown pixel type）
        $qrcode = new QrReader($blob, QrReader::SOURCE_TYPE_BLOB, false);
        $text = $qrcode->text();
    } catch (Exception $e) {
        $localErr = $e->getMessage();
    } catch (Error $e) {
        $localErr = $e->getMessage();
    }
} else {
    $localErr = '服务器未安装 GD 或 Imagick 扩展，无法本地识别二维码';
}

if ($text) {
    @unlink($img_path);
    echo json_encode(["code"=>1,"msg"=>"识别成功","data"=>$text]);
    exit();
}

// 本地识别失败，尝试远程识别（仅当图片URL可被外网访问时有效）
try {
    $url = 'http://'.$_SERVER['SERVER_NAME'].str_replace("/test.php","",$_SERVER["REQUEST_URI"]).str_replace("./","/",$img_path);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://cli.im/apis/up/deqrimg");
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "img=".urlencode($url));
    $res = curl_exec($ch);
    curl_close($ch);
    $obj = json_decode($res);
    if (isset($obj->info->data[0]) && $obj->info->data[0]) {
        $text = $obj->info->data[0];
    }
} catch (Exception $e) {
    // 远程识别失败，忽略
}

@unlink($img_path);

if ($text) {
    echo json_encode(["code"=>1,"msg"=>"识别成功","data"=>$text]);
} else {
    echo json_encode([
        "code"=>-1,
        "msg"=>"二维码识别失败",
        "data"=>"本地识别: " . ($localErr ?: "未能解析出二维码内容") . "。请确保上传的是清晰的收款二维码图片。也可先在 https://cli.im/deqr 识别二维码内容，再手动粘贴。"
    ]);
}

