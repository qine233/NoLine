<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/* 获取模板版本号 */
function NolineVersion()
{
    return "LITE v1.0";//后续完善版本号管理方法
}
function themeConfig($logo)
{


    $logoCss = new Typecho_Widget_Helper_Form_Element_Text('logoCss', NULL, NULL, _t('站点头像地址'), _t('在这里填入一个图片 URL 地址, 以修改头像'));
    $logo->addInput($logoCss);


    $logoName = new Typecho_Widget_Helper_Form_Element_Text('logoName', NULL, NULL, _t('博主名字'), _t('在这里填入你的博客名'));
    $logo->addInput($logoName);

    $logobg = new Typecho_Widget_Helper_Form_Element_Text('logobg', NULL, NULL, _t('座右铭'), _t('在这里填入你的座右铭，建议简短，10字内'));
    $logo->addInput($logobg);

    $logobgcolor = new Typecho_Widget_Helper_Form_Element_Text('logobgcolor', NULL, NULL, _t('资料卡头图'), _t('在这里填入你的头图链接，建议使用外链'));
    $logo->addInput($logobgcolor);

    // $logocontacta = new Typecho_Widget_Helper_Form_Element_Text('logocontacta', NULL, NULL, _t('关注按钮对应链接'), _t('在这里填入你的github链接或其他社交平台链接'));
    // $logo->addInput($logocontacta);

    // $logocontactb = new Typecho_Widget_Helper_Form_Element_Text('logocontactb', NULL, _t('https://cdn.jsdelivr.net/gh/qine233/jsdever-ty/bg.jpg'), _t('主页半屏背景图'), _t('在这里填入你的背景图链接，建议引用外部图床节省网站所在服务器带宽'));
    // $logo->addInput($logocontactb);

    $gtihubname = new Typecho_Widget_Helper_Form_Element_Text('gtihubname', NULL, _t('qine233'), _t('GitHubID名'), _t('在这里填入你的ID'));
    $logo->addInput($gtihubname);
    $gtihubsite = new Typecho_Widget_Helper_Form_Element_Text('gtihubsite', NULL, _t('https://github.com/qine233/NoLineLite'), _t('GitHub链接'), _t('在这里填入你的GitHub链接'));
    $logo->addInput($gtihubsite);

    $BliBliname = new Typecho_Widget_Helper_Form_Element_Text('BliBliname', NULL, _t('BliBli'), _t('Blibli名'), _t('Blibli名'));
    $logo->addInput($BliBliname);

    $BliBlisite = new Typecho_Widget_Helper_Form_Element_Text('BliBlisite', NULL, NULL, _t('BliBli链接'), _t('在这里填入你的BILIBILI链接等'));
    $logo->addInput($BliBlisite);

    $logoFooter = new Typecho_Widget_Helper_Form_Element_Textarea('logoFooter', NULL, NULL, _t('站点底部版权填写区域，后续会考虑将footer区域拉高，增加更多可自定义内容'), _t('在这里填入你的站点底部代码，例如备案链接等'));
    $logo->addInput($logoFooter);
  $exsite = new Typecho_Widget_Helper_Form_Element_Text('exsite', NULL, NULL, _t('临时屏蔽恶意IP'), _t('在这里填入一个IP，强制其跳转baidu.com,'));
    $logo->addInput($exsite);

}

//统计多少天内发布的文章数量
function getNumPosts($days)
{
    $db = Typecho_Db::get();
    $st_days = time() - $days * 24 * 60 * 60;
    $result = $db->fetchAll($db->select()->from('table.contents')
        ->where('status = ?', 'publish')
        ->where('type = ?', 'post')
        ->where('modified >= ?', $st_days)
    //统计时间
    );
    $total_posts = count($result);
    return $total_posts;
}

//获取Gravatar头像 QQ邮箱取用qq头像
function getGravatar($email, $s = 96, $d = 'mp', $r = 'g', $img = false, $atts = array())
{
    preg_match_all('/((\d)*)@qq.com/', $email, $vai);
    if (empty($vai['1']['0'])) {
        $url = 'https://cdn.sep.cc/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
    } else {
        $url = 'https://q2.qlogo.cn/headimg_dl?dst_uin=' . $vai['1']['0'] . '&spec=100';
    }
    return $url;
}

function time_ago_in_words($timestamp) {
    $time_difference = time() - $timestamp;
    $seconds = $time_difference;
    
    $minutes      = round($seconds / 60);           // value 60 is seconds
    $hours        = round($seconds / 3600);         // value 3600 is 60 minutes * 60 sec
    $days         = round($seconds / 86400);        // value 86400 is 24 hours * 60 minutes * 60 sec
    $weeks        = round($seconds / 604800);       // value 604800 is 7 days * 24 hours * 60 minutes * 60 sec
    $months       = round($seconds / 2629440);      // value 2629440 is 30 days * 24 hours * 60 minutes * 60 sec
    $years        = round($seconds / 31553280);     // value 31553280 is 365 days * 24 hours * 60 minutes * 60 sec

    if ($seconds <= 60) {
        return '刚刚';
    } else if ($minutes <= 60) {
        if ($minutes == 1) {
            return '1分前';
        } else {
            return $minutes.'分前';
        }
    } else if ($hours <= 24) {
        if ($hours == 1) {
            return '1小时前';
        } else {
            return $hours.'小时前';
        }
    } else if ($days <= 7) {
        if ($days == 1) {
            return '昨天';
        } else {
            return $days.'天前';
        }
    } else if ($weeks <= 4.3) { // 4.3 == 30/7
        if ($weeks == 1) {
            return '1周前';
        } else {
            return $weeks.'周前';
        }
    } else if ($months <= 12) {
        if ($months == 1) {
            return '1月前';
        } else {
            return $months.'月前';
        }
    } else {
        if ($years == 1) {
            return '1年前';
        } else {
            return $years.'年前';
        }
    }
}
function get_post_view($archive) {
    $cid = $archive->cid;
    $path = __DIR__ . '/views/' . $cid . '.txt'; // 计数文件存放目录（确保可写）

    if (!file_exists($path)) {
        file_put_contents($path, '0');
    }

    $views = (int)file_get_contents($path);

    $cookieName = 'viewed_' . $cid;

    // 如果没有记录过，就增加计数
    if (!isset($_COOKIE[$cookieName])) {
        $views++;
        file_put_contents($path, $views);

        // 设置cookie，有效期1天，可以自己改
        setcookie($cookieName, '1', time() + 86400, '/');
    }

    echo $views;
}
Typecho_Plugin::factory('Widget_Feedback')->comment = function($comment, $post) {
    file_put_contents('/tmp/comment_debug.log', 
        date('Y-m-d H:i:s') . "\n" . 
        print_r($comment, true) . "\n\n", 
        FILE_APPEND
    );
};