<?php
namespace app\admin\controller;

use think\App;
use think\Db;
use think\facade\Session;
use app\service\QrcodeServer;
use Zxing\QrReader;

class Index
{
    public function index()
    {
        return 'by:vone';
    }

    public function getReturn($code = 1,$msg = "成功",$data = null){
        return array("code"=>$code,"msg"=>$msg,"data"=>$data);
    }

    /**
     * 幂等迁移：pay_qrcode_pool 多码池表 + pay_order.qrcode_id/qrcode_code + setting.switchThreshold
     */
    protected function ensurePoolSchema()
    {
        $migrated = Db::name("setting")->where("vkey", "pool_migrated")->find();
        if ($migrated && $migrated['vvalue'] == '3') return;

        Db::execute("CREATE TABLE IF NOT EXISTS `pay_qrcode_pool` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `code` varchar(32) NOT NULL DEFAULT '' COMMENT '码编码 WX001/ZFB001',
            `pay_url` varchar(255) DEFAULT NULL COMMENT '二维码图片地址',
            `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
            `is_fallback` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否兜底码 1是 0否',
            `type` int(11) NOT NULL DEFAULT 1 COMMENT '1微信 2支付宝',
            `healthy` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1健康 0不健康',
            `switch_count` int(11) NOT NULL DEFAULT 0 COMMENT '被换次数',
            `last_used_at` bigint(20) NOT NULL DEFAULT 0 COMMENT '最后使用时间',
            `add_time` bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8");

        // 老表补字段
        $poolCols = Db::query("SHOW COLUMNS FROM `pay_qrcode_pool`");
        $poolColNames = array_column($poolCols, 'Field');
        if (!in_array('remark', $poolColNames)) {
            Db::execute("ALTER TABLE `pay_qrcode_pool` ADD COLUMN `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注' AFTER `pay_url`");
        }
        if (!in_array('is_fallback', $poolColNames)) {
            Db::execute("ALTER TABLE `pay_qrcode_pool` ADD COLUMN `is_fallback` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否兜底码 1是 0否' AFTER `remark`");
        }

        // 迁移旧 setting 码入池（作为兜底码）
        foreach (array(array("wxpay",1), array("zfbpay",2)) as $pair) {
            $row = Db::name("setting")->where("vkey", $pair[0])->find();
            if ($row && $row['vvalue']) {
                $exists = Db::name("pay_qrcode_pool")->where("pay_url", $row['vvalue'])->where("type", $pair[1])->find();
                if (!$exists) {
                    $prefix = $pair[1] == 1 ? "WX" : "ZFB";
                    $maxId = (int)Db::name("pay_qrcode_pool")->where("type", $pair[1])->max("id");
                    $code = $prefix . str_pad((string)($maxId + 1), 3, "0", STR_PAD_LEFT);
                    while (Db::name("pay_qrcode_pool")->where("code", $code)->find()) {
                        $maxId++;
                        $code = $prefix . str_pad((string)($maxId + 1), 3, "0", STR_PAD_LEFT);
                    }
                    Db::name("pay_qrcode_pool")->insert(array(
                        "code" => $code,
                        "pay_url" => $row['vvalue'],
                        "remark" => "原系统设置兜底码",
                        "is_fallback" => 1,
                        "type" => $pair[1],
                        "healthy" => 1,
                        "switch_count" => 0,
                        "last_used_at" => 0,
                        "add_time" => time()
                    ));
                }
            }
        }

        $cols = Db::query("SHOW COLUMNS FROM `pay_order`");
        $colNames = array_column($cols, 'Field');
        if (!in_array('qrcode_id', $colNames)) {
            Db::execute("ALTER TABLE `pay_order` ADD COLUMN `qrcode_id` bigint(20) NOT NULL DEFAULT 0 COMMENT '支付码池ID'");
        }
        if (!in_array('qrcode_code', $colNames)) {
            Db::execute("ALTER TABLE `pay_order` ADD COLUMN `qrcode_code` varchar(32) NOT NULL DEFAULT '' COMMENT '支付码编码'");
        }

        $threshold = Db::name("setting")->where("vkey", "switchThreshold")->find();
        if (!$threshold) {
            Db::name("setting")->insert(array("vkey" => "switchThreshold", "vvalue" => "10"));
        }

        if ($migrated) {
            Db::name("setting")->where("vkey", "pool_migrated")->update(array("vvalue" => "3"));
        } else {
            Db::name("setting")->insert(array("vkey" => "pool_migrated", "vvalue" => "3"));
        }
    }



    public function getMain(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $today = strtotime(date("Y-m-d"),time());

        $todayOrder = Db::name("pay_order")
            ->where("create_date >=".$today)
            ->where("create_date <=".($today+86400))
            ->count();


        $todaySuccessOrder = Db::name("pay_order")
            ->where("state >=1")
            ->where("create_date >=".$today)
            ->where("create_date <=".($today+86400))
            ->count();



        $todayCloseOrder = Db::name("pay_order")
            ->where("state",-1)
            ->where("create_date >=".$today)
            ->where("create_date <=".($today+86400))
            ->count();

        $todayMoney = Db::name("pay_order")
            ->where("state >=1")
            ->where("create_date >=".$today)
            ->where("create_date <=".($today+86400))
            ->sum("price");


        $countOrder = Db::name("pay_order")
            ->count();
        $countMoney = Db::name("pay_order")
            ->where("state >=1")
            ->sum("price");

        $v = Db::query("SELECT VERSION();");
        $v=$v[0]['VERSION()'];

        if(function_exists("gd_info")) {
            $gd_info = @gd_info();
            $gd = $gd_info["GD Version"];
        }else{
            $gd = '<font color="red">GD库未开启！</font>';
        }

        return json($this->getReturn(1,"成功",array(
            "todayOrder"=>$todayOrder,
            "todaySuccessOrder"=>$todaySuccessOrder,
            "todayCloseOrder"=>$todayCloseOrder,
            "todayMoney"=>round($todayMoney,2),
            "countOrder"=>$countOrder,
            "countMoney"=>round($countMoney),

            "PHP_VERSION"=>PHP_VERSION,
            "PHP_OS"=>PHP_OS,
            "SERVER"=>$_SERVER ['SERVER_SOFTWARE'],
            "MySql"=>$v,
            "Thinkphp"=>"v".App::VERSION,
            "RunTime"=>$this->sys_uptime(),
            "ver"=>"v".config("ver"),
            "gd"=>$gd,
        )));

    }
    private function sys_uptime() {
        $output='';
        if (false === ($str = @file("/proc/uptime"))) return false;
        $str = explode(" ", implode("", $str));
        $str = trim($str[0]);
        $min = $str / 60;
        $hours = $min / 60;
        $days = floor($hours / 24);
        $hours = floor($hours - ($days * 24));
        $min = floor($min - ($days * 60 * 24) - ($hours * 60));
        if ($days !== 0) $output .= $days."天";
        if ($hours !== 0) $output .= $hours."小时";
        if ($min !== 0) $output .= $min."分钟";
        return $output;
    }
    public function checkUpdate(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $ver = $this->getCurl("https://raw.githubusercontent.com/szvone/vmqphp/master/ver");
        $ver = explode("|",$ver);

        if (sizeof($ver)==2 && $ver[0]!=config("ver")){
            return json($this->getReturn(1,"[v".$ver[0]."已于".$ver[1]."发布]","https://github.com/szvone/vmqphp"));
        }else{
            return json($this->getReturn(0,"程序是最新版"));
        }
    }

    public function getSettings(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $user = Db::name("setting")->where("vkey","user")->find();
        $pass = Db::name("setting")->where("vkey","pass")->find();
        $notifyUrl = Db::name("setting")->where("vkey","notifyUrl")->find();
        $returnUrl = Db::name("setting")->where("vkey","returnUrl")->find();
        $key = Db::name("setting")->where("vkey","key")->find();
        $lastheart = Db::name("setting")->where("vkey","lastheart")->find();
        $lastpay = Db::name("setting")->where("vkey","lastpay")->find();
        $jkstate = Db::name("setting")->where("vkey","jkstate")->find();
        $close = Db::name("setting")->where("vkey","close")->find();
        $payQf = Db::name("setting")->where("vkey","payQf")->find();
        $wxpay = Db::name("setting")->where("vkey","wxpay")->find();
        $zfbpay = Db::name("setting")->where("vkey","zfbpay")->find();
        if ($key['vvalue']==""){
            $key['vvalue'] = md5(time());
            Db::name("setting")->where("vkey","key")->update(array(
                "vvalue"=>$key['vvalue']
            ));
        }

        return json($this->getReturn(1,"成功",array(
            "user"=>$user['vvalue'],
            "pass"=>$pass['vvalue'],
            "notifyUrl"=>$notifyUrl['vvalue'],
            "returnUrl"=>$returnUrl['vvalue'],
            "key"=>$key['vvalue'],
            "lastheart"=>$lastheart['vvalue'],
            "lastpay"=>$lastpay['vvalue'],
            "jkstate"=>$jkstate['vvalue'],
            "close"=>$close['vvalue'],
            "payQf"=>$payQf['vvalue'],
            "wxpay"=>$wxpay['vvalue'],
            "zfbpay"=>$zfbpay['vvalue'],

        )));


    }
    public function saveSetting(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        Db::name("setting")->where("vkey","user")->update(array("vvalue"=>input("user")));
        Db::name("setting")->where("vkey","pass")->update(array("vvalue"=>input("pass")));
        Db::name("setting")->where("vkey","notifyUrl")->update(array("vvalue"=>input("notifyUrl")));
        Db::name("setting")->where("vkey","returnUrl")->update(array("vvalue"=>input("returnUrl")));
        Db::name("setting")->where("vkey","key")->update(array("vvalue"=>input("key")));
        Db::name("setting")->where("vkey","close")->update(array("vvalue"=>input("close")));
        Db::name("setting")->where("vkey","payQf")->update(array("vvalue"=>input("payQf")));
        $wxpay = input("post.wxpay", null);
        if ($wxpay !== null) {
            Db::name("setting")->where("vkey","wxpay")->update(array("vvalue"=>$wxpay));
        }
        $zfbpay = input("post.zfbpay", null);
        if ($zfbpay !== null) {
            Db::name("setting")->where("vkey","zfbpay")->update(array("vvalue"=>$zfbpay));
        }


        return json($this->getReturn());


    }


    public function addPayQrcode(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $db = Db::name("pay_qrcode")->insert(array(
            "type"=>input("type"),
            "pay_url"=>input("pay_url"),
            "price"=>input("price"),
        ));
        return json($this->getReturn());

    }

    /* ================= 无金额多码池管理 ================= */

    /**
     * 无金额码池列表（type=1微信 2支付宝）
     */
    public function getPoolQrcodes(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $this->ensurePoolSchema();
        $type = (int)input("type", 1);
        $list = Db::name("pay_qrcode_pool")
            ->where("type", $type)
            ->order("id", "asc")
            ->select();
        return json($this->getReturn(1, "成功", $list ? $list : array()));
    }

    /**
     * 编辑码池（二维码内容 + 备注 + 是否兜底）
     */
    public function editPoolQrcode(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $this->ensurePoolSchema();
        $id = (int)input("id", 0);
        $type = (int)input("type", 1);
        $payUrl = input("pay_url", "");
        $remark = input("remark", "");
        $isFallback = (int)input("is_fallback", 0);
        if (!$id) return json($this->getReturn(-1, "缺少ID"));

        // 查重：同类型下 pay_url 不能重复（排除自己）
        if ($payUrl) {
            $dup = Db::name("pay_qrcode_pool")
                ->where("type", $type)
                ->where("pay_url", $payUrl)
                ->where("id", "<>", $id)
                ->find();
            if ($dup) return json($this->getReturn(-1, "该二维码已存在（编码：" . $dup['code'] . "），不能重复添加"));
        }

        // 设为兜底码时，同类型其他码取消兜底
        if ($isFallback) {
            Db::name("pay_qrcode_pool")->where("type", $type)->where("id", "<>", $id)->update(array("is_fallback" => 0));
        }

        $update = array("remark" => $remark, "is_fallback" => $isFallback);
        if ($payUrl) $update['pay_url'] = $payUrl;
        Db::name("pay_qrcode_pool")->where("id", $id)->update($update);
        return json($this->getReturn(1, "已更新"));
    }

    /**
     * 添加无金额码（多张循环调用），编码自动生成 WX001/ZFB001...
     */
    public function addPoolQrcode(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $this->ensurePoolSchema();
        $type = (int)input("type", 1);
        $payUrl = input("pay_url");
        $remark = input("remark", "");
        $isFallback = (int)input("is_fallback", 0);
        if (!$payUrl) return json($this->getReturn(-1, "缺少二维码地址"));

        // 查重：同类型下 pay_url 不能重复
        $dup = Db::name("pay_qrcode_pool")
            ->where("type", $type)
            ->where("pay_url", $payUrl)
            ->find();
        if ($dup) return json($this->getReturn(-1, "该二维码已存在（编码：" . $dup['code'] . "），不能重复添加"));

        // 设为兜底码时，同类型其他码取消兜底
        if ($isFallback) {
            Db::name("pay_qrcode_pool")->where("type", $type)->update(array("is_fallback" => 0));
        }

        $prefix = $type == 1 ? "WX" : "ZFB";
        $max = Db::name("pay_qrcode_pool")->where("type", $type)->max("id");
        $code = $prefix . str_pad((string)(((int)$max) + 1), 3, "0", STR_PAD_LEFT);
        while (Db::name("pay_qrcode_pool")->where("code", $code)->find()) {
            $max++;
            $code = $prefix . str_pad((string)(((int)$max) + 1), 3, "0", STR_PAD_LEFT);
        }

        Db::name("pay_qrcode_pool")->insert(array(
            "code" => $code,
            "pay_url" => $payUrl,
            "remark" => $remark,
            "is_fallback" => $isFallback,
            "type" => $type,
            "healthy" => 1,
            "switch_count" => 0,
            "last_used_at" => 0,
            "add_time" => time()
        ));
        return json($this->getReturn(1, "成功", array("code" => $code)));
    }

    /**
     * 删除无金额码
     */
    public function delPoolQrcode(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        Db::name("pay_qrcode_pool")->where("id", (int)input("id"))->delete();
        return json($this->getReturn());
    }

    /**
     * 手动设置健康状态（运营恢复/下线某张码）
     */
    public function setPoolHealthy(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $id = (int)input("id");
        $healthy = (int)input("healthy", 1) ? 1 : 0;
        $update = array("healthy" => $healthy);
        if ($healthy) $update['switch_count'] = 0; // 手动恢复健康时清零计数
        Db::name("pay_qrcode_pool")->where("id", $id)->update($update);
        return json($this->getReturn());
    }

    /**
     * 保存不健康阈值（换了几次判不健康）
     */
    public function saveSwitchThreshold(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $this->ensurePoolSchema();
        $v = max(1, (int)input("value", 10));
        Db::name("setting")->where("vkey", "switchThreshold")->update(array("vvalue" => (string)$v));
        return json($this->getReturn());
    }

    /**
     * 获取不健康阈值
     */
    public function getSwitchThreshold(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $this->ensurePoolSchema();
        $row = Db::name("setting")->where("vkey", "switchThreshold")->find();
        return json($this->getReturn(1, "成功", array("value" => (int)$row['vvalue'])));
    }

    public function getPayQrcodes(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $page = input("page");
        $size = input("limit");

        $obj = Db::table('pay_qrcode')->page($page,$size);

        $obj = $obj->where("type",input("type"));

        $array = $obj->order("id","desc")->select();

        //echo $obj->getLastSql();
        return json(array(
            "code"=>0,
            "msg"=>"获取成功",
            "data"=>$array,
            "count"=> $obj->count()
        ));
    }
    public function delPayQrcode(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        Db::name("pay_qrcode")->where("id",input("id"))->delete();
        return json($this->getReturn());

    }

    public function getOrders(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $page = input("page");
        $size = input("limit");

        $obj = Db::table('pay_order')->page($page,$size);
        if (input("type")){
            $obj = $obj->where("type",input("type"));
        }
        if (input("state")){
            $obj = $obj->where("state",input("state"));
        }


        $array = $obj->order("id","desc")->select();

        //echo $obj->getLastSql();
        return json(array(
            "code"=>0,
            "msg"=>"获取成功",
            "data"=>$array,
            "count"=> $obj->count()
        ));
    }
    public function delOrder(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        $res = Db::name("pay_order")->where("id",input("id"))->find();

        Db::name("pay_order")->where("id",input("id"))->delete();
        if ($res['state']==0){
            Db::name("tmp_price")
                ->where("oid",$res['order_id'])
                ->delete();
        }

        return json($this->getReturn());

    }

    public function setBd(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }

        $res = Db::name("pay_order")->where("id",input("id"))->find();

        if ($res){

            $url = $res['notify_url'];

            $res2 = Db::name("setting")->where("vkey","key")->find();
            $key = $res2['vvalue'];

            $p = "payId=".$res['pay_id']."&param=".$res['param']."&type=".$res['type']."&price=".$res['price']."&reallyPrice=".$res['really_price'];

            $sign = $res['pay_id'].$res['param'].$res['type'].$res['price'].$res['really_price'].$key;
            $p = $p . "&sign=".md5($sign);
            if (strpos($url,"?")===false){
                $url = $url."?".$p;
            }else{
                $url = $url."&".$p;
            }

            $re = $this->getCurl($url);

            if ($re=="success"){
                if ($res['state']==0){
                    Db::name("tmp_price")
                        ->where("oid",$res['order_id'])
                        ->delete();
                }

                Db::name("pay_order")->where("id",$res['id'])->update(array("state"=>1));

                return json($this->getReturn());
            }else{
                return json($this->getReturn(-2,"补单失败",$re));
            }
        }else{
            return json($this->getReturn(-1,"订单不存在"));

        }


    }

    public function delGqOrder(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }
        Db::name("pay_order")->where("state","-1")->delete();
        return json($this->getReturn());
    }
    public function delLastOrder(){
        if (!Session::has("admin")){
            return json($this->getReturn(-1,"没有登录"));
        }

        Db::name("pay_order")->where("create_date <".(time()-604800))->delete();
        return json($this->getReturn());
    }




    public function enQrcode($url){

        $qr_code = new QrcodeServer(['generate'=>"display","size",200]);
        $content = $qr_code->createServer($url);

        return response($content,200,['Content-Length'=>strlen($content)])->contentType('image/png');

    }


























    //获取客户IP
    public function ip() {

        return $_SERVER['REMOTE_ADDR'];
    }
    //发送Http请求
    function getCurl($url, $post = 0, $cookie = 0, $header = 0, $nobaody = 0)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $klsf[] = 'Accept:*/*';
        $klsf[] = 'Accept-Language:zh-cn';
        //$klsf[] = 'Content-Type:application/json';
        $klsf[] = 'User-Agent:Mozilla/5.0 (iPhone; CPU iPhone OS 11_2_1 like Mac OS X) AppleWebKit/604.4.7 (KHTML, like Gecko) Mobile/15C153 MicroMessenger/6.6.1 NetType/WIFI Language/zh_CN';
        $klsf[] = 'Referer:'.$url;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $klsf);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        if ($header) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }
        if ($cookie) {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }
        if ($nobaody) {
            curl_setopt($ch, CURLOPT_NOBODY, 1);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT,60);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $ret = curl_exec($ch);
        curl_close($ch);
        return $ret;
    }
}
