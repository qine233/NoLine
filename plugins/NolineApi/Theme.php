<?php

namespace TypechoPlugin\NolineApi;

use Typecho\Db;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 主题配置项的统一读写入口。
 *
 * 数据在库里的形状由 Typecho 决定：table.options 里一行 name='theme:<主题名>'、user=0、
 * value=serialize(数组)，Widget\Options::execute() 每次请求把它摊平覆盖到基础 options 上
 * （见 var/Widget/Options.php 的「支持皮肤变量重载」）。所以这里必须整片 merge 后写回，
 * 只写单个键会把主题其它设置一起抹掉。
 *
 * 键名沿用 functions.php 里 themeConfig() 的拼写——gtihubname、BliBliname 是错的，
 * logobg 其实是座右铭、logobgcolor 其实是头图——但它们是已存数据的键，改了就读不回来了。
 *
 * FIELDS 是唯一一份真相：后台表单、接口白名单、写库前的校验都从它出发。
 */
class Theme
{
    /** 主题目录名，同时也是 options 里的行名后缀 */
    public const DIR = 'noline';

    /**
     * 全部可写配置项。
     *
     * kind 决定校验：
     *   plain  普通文本，去首尾空白，禁控制字符
     *   url    给 src/href 用，只认 http(s):// 和站内相对路径，禁空白引号尖括号括号
     *   hex    颜色，#abc 或 #aabbcc
     *   csv    逗号分隔的 slug:icon 列表
     *   html   原样存的富文本（公告、备案号），只限长度不做清洗——见下面 write() 的说明
     *   bool   开关，存 '1' 或不存在
     */
    private const FIELDS = [
        'logoCss' => [
            'label' => '站点头像地址',
            'kind'  => 'url',
            'max'   => 500,
            'hint'  => '顶栏头像、首页 masthead 头像与站点 favicon 都用它。留空时文章卡片头像回退到作者 Gravatar。',
        ],
        'logoName' => [
            'label' => '博主名字',
            'kind'  => 'plain',
            'max'   => 100,
            'hint'  => '显示在顶栏品牌和首页 masthead。留空则回退到站点标题。',
        ],
        'logobg' => [
            'label' => '座右铭',
            'kind'  => 'plain',
            'max'   => 200,
            'hint'  => '首页 masthead 站名下方那行字，最多两行。留空则回退到站点描述。',
        ],
        'logobgcolor' => [
            'label' => '首页头图',
            'kind'  => 'url',
            'max'   => 500,
            'hint'  => '铺在首页 masthead 当封面，上面自动压一层渐变罩。留空则不出封面。',
        ],
        'accentColor' => [
            'label' => '强调色',
            'kind'  => 'hex',
            'max'   => 9,
            'hint'  => '链接、按钮、hover、标签底色、代码块圆点光环都用它，例如 #4482e5。深色模式会自动算一个更亮的变体。留空或格式不对则用默认靛蓝。',
        ],
        'diaryCategory' => [
            'label' => '动态分类',
            'kind'  => 'plain',
            'max'   => 200,
            'hint'  => '命中这个分类的文章在首页渲染成微博式动态卡，不显示标题。分类名和缩略名都认，多个用逗号分隔。留空则关闭。',
        ],
        'navIcons' => [
            'label' => '导航图标',
            'kind'  => 'csv',
            'max'   => 500,
            'hint'  => '格式 slug:图标名，逗号分隔，例如 yanzhi:code,photos:image。留空则按内置规则猜。写错名字的那一条会被跳过。',
        ],
        'notice' => [
            'label' => '首页公告',
            'kind'  => 'html',
            'max'   => 4000,
            'hint'  => '显示在首页内容区顶部的公告卡，留空则整张卡不出现。支持 HTML，原样入库不做清洗。',
        ],
        'showSidebarCats' => [
            'label' => '右栏分类卡',
            'kind'  => 'bool',
            'max'   => 0,
            'hint'  => '开启后在右栏显示「分类」卡。默认关闭——分类已经在信息流顶上那条吸顶药丸条里出现过一次。',
        ],
        'gtihubname' => [
            'label' => 'GitHub 显示名',
            'kind'  => 'plain',
            'max'   => 100,
            'hint'  => '写在顶栏 GitHub 按钮上，配了之后按钮会从方块长成药丸。留空则只显示图标。',
        ],
        'gtihubsite' => [
            'label' => 'GitHub 链接',
            'kind'  => 'url',
            'max'   => 500,
            'hint'  => '你的 GitHub 主页。留空则不显示该图标。',
        ],
        'BliBliname' => [
            'label' => 'Bilibili 显示名',
            'kind'  => 'plain',
            'max'   => 100,
            'hint'  => '写在顶栏 Bilibili 按钮上，规则同 GitHub 显示名。',
        ],
        'BliBlisite' => [
            'label' => 'Bilibili 链接',
            'kind'  => 'url',
            'max'   => 500,
            'hint'  => '你的 Bilibili 主页。留空则不显示该图标。',
        ],
        'logoFooter' => [
            'label' => '站点底部版权',
            'kind'  => 'html',
            'max'   => 2000,
            'hint'  => '显示在右栏「站点信息」卡底部，可填 ICP 备案号。支持 HTML，原样入库不做清洗。',
        ],
    ];

    /**
     * 字段定义表，给接口文档和后台表单共用。
     */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /* ======================================================================
       读
       ====================================================================== */

    /**
     * 当前全部主题配置。返回的值和库里存的一致：开关是 '1' 或 ''，其余是字符串。
     */
    public static function read(): array
    {
        $options = Options::alloc();
        $out = array();

        foreach (self::FIELDS as $key => $spec) {
            if ($spec['kind'] === 'bool') {
                /* 没勾选时这个键压根不在提交里，库里可能是 null，也可能是 ['1'] */
                $value = $options->$key;
                $out[$key] = empty($value) ? '' : '1';
                continue;
            }

            $value = $options->$key;
            $out[$key] = ($value === null || $value === false || is_array($value))
                ? ''
                : trim((string)$value);
        }

        return $out;
    }

    /**
     * 把「库里的形状」翻译成「接口的形状」。
     *
     * 写成功后必须用它而不是 read()：Options 是请求开始时从库里摊平的，本次请求内再读
     * 也还是旧值，只有 write() 返回的那份才是刚写进去的。
     */
    public static function shape(array $raw): array
    {
        $out = array();

        foreach ($raw as $key => $value) {
            $out[$key] = isset(self::FIELDS[$key]) && self::FIELDS[$key]['kind'] === 'bool'
                ? $value === '1'
                : (string)$value;
        }

        return $out;
    }

    /* ======================================================================
       写
       ====================================================================== */

    /**
     * 校验并落库。
     *
     * $patch 里只应该出现想改的键，其余保持原样——整片 merge 之后写回，
     * 少传一个键不会把它清空，传了 null 或空串才是清空。
     *
     * 返回 ['ok' => bool, 'errors' => [键 => 原因], 'values' => 改后的全量值]。
     * errors 非空时整次不写：宁可让 App 一次改完再来，也不要改一半。
     */
    public static function write(array $patch): array
    {
        $current = self::read();
        $errors = array();
        $next = $current;

        foreach ($patch as $key => $value) {
            if (!isset(self::FIELDS[$key])) {
                $errors[$key] = _t('不是本主题的配置项');
                continue;
            }

            $checked = self::check(self::FIELDS[$key], $value);

            if (is_string($checked)) {
                $errors[$key] = $checked;
                continue;
            }

            $next[$key] = $checked;
        }

        if (!empty($errors)) {
            return array('ok' => false, 'errors' => $errors, 'values' => $current);
        }

        if (self::persist($next) === false) {
            return array(
                'ok'     => false,
                'errors' => array('_' => _t('配置写不进数据库（当前启用的主题不是 noline，或者 options 表不可写）')),
                'values' => $current,
            );
        }

        return array('ok' => true, 'errors' => array(), 'values' => $next);
    }

    /**
     * 单项校验。返回清洗后的值，或者一条错误说明（字符串）。
     */
    private static function check(array $spec, $value)
    {
        if ($value === null) {
            $value = '';
        }

        if (is_array($value)) {
            return _t('这一项要传字符串');
        }

        if ($spec['kind'] === 'bool') {
            /* 接口传 true/false/'1'/''，后台传复选框的有无，统一成 '1' 或 '' */
            if ($value === true || $value === 1 || $value === '1' || $value === 'on') {
                return '1';
            }
            if ($value === false || $value === 0 || $value === '' || $value === '0') {
                return '';
            }

            return _t('开关只能是 true 或 false');
        }

        $value = (string)$value;

        // 换行/制表是富文本和长文本的正常内容，其它控制字符一律去掉：
        // 它们进不了 HTML 属性，也进不了 URL，留着只会让库里出现看不见的脏字节。
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim($value);

        if (mb_strlen($value, 'UTF-8') > $spec['max']) {
            return _t('超过 %d 个字符（当前 %d 个）', $spec['max'], mb_strlen($value, 'UTF-8'));
        }

        switch ($spec['kind']) {
            case 'url':
                if ($value === '' || preg_match('#^(https?://|/)[^\s"\'<>\\\\]*$#i', $value)) {
                    return $value;
                }

                return _t('要么留空，要么是 http(s):// 开头的完整地址、或以 / 开头的站内路径');

            case 'hex':
                if ($value === '' || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
                    return $value;
                }

                return _t('颜色要写成 #abc 或 #aabbcc');

            case 'csv':
                if ($value === '') {
                    return '';
                }

                foreach (preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) as $item) {
                    if (!preg_match('/^[A-Za-z0-9_-]+:[A-Za-z0-9_-]+$/u', $item)) {
                        return _t('「%s」不是 slug:图标名 的写法，整条请按 yanzhi:code,photos:image 的格式来', $item);
                    }
                }

                return $value;

            default:
                // plain 和 html 到这里已经去过控制字符、限过长度，内容原样保留
                return $value;
        }
    }

    /**
     * 整片写回 theme:<当前主题>。
     *
     * 只认正在启用的那套主题：改 theme:noline 而站点跑的是别的主题，用户点了保存却什么都没变，
     * 不如当场报错。查询形状照抄 Widget\Options::updateRoutingTable()。
     */
    private static function persist(array $values)
    {
        $active = trim((string)Options::alloc()->theme);

        if ($active !== self::DIR) {
            return false;
        }

        $stored = array();
        $name = 'theme:' . self::DIR;
        $db = Db::get();

        $row = $db->fetchRow($db->select('value')->from('table.options')
            ->where('name = ?', $name)->where('user = ?', 0));

        if (is_array($row) && isset($row['value'])) {
            $decoded = @unserialize((string)$row['value']);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        /**
         * 库里可能有主题不认识的键（老版本留下的），原样带着——
         * 主题读的是摊平后的 options，多一个键不会坏事，少一个键会。
         */
        $merged = array_merge($stored, $values);

        // 值是字符串或空串，序列化后体积小、unserialize 也不会碰到别的类型
        $payload = serialize($merged);

        try {
            if (is_array($row)) {
                $db->query($db->update('table.options')->rows(array('value' => $payload))
                    ->where('name = ?', $name));

                return true;
            }

            $db->query($db->insert('table.options')
                ->rows(array('name' => $name, 'value' => $payload, 'user' => 0)));

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ======================================================================
       后台表单
       ====================================================================== */

    /**
     * 渲染一张能直接提交的字段表格。不调 Typecho 的 Form 组件——
     * 插件设置页那张表单存的是 plugin:NolineApi，主题配置必须落到 theme:noline，
     * 两者不能共用一套 input，所以这里发的是普通控件，由管理页自己接收。
     */
    public static function formHtml(array $values): string
    {
        $html = '';

        foreach (self::FIELDS as $key => $spec) {
            $value = isset($values[$key]) ? $values[$key] : '';
            $id = 'nl-' . $key;
            $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

            $html .= '<tr><td class="nl-key"><label for="' . $id . '">'
                . htmlspecialchars($spec['label'], ENT_QUOTES, 'UTF-8')
                . '<code>' . $key . '</code></label></td><td class="nl-val">';

            if ($spec['kind'] === 'bool') {
                $html .= '<label class="nl-switch"><input type="checkbox" id="' . $id . '" name="t['
                    . $key . ']" value="1"' . ($value === '1' ? ' checked' : '') . '> '
                    . _t('开启') . '</label>';
            } elseif ($spec['kind'] === 'html') {
                $html .= '<textarea id="' . $id . '" name="t[' . $key . ']" rows="4" maxlength="'
                    . $spec['max'] . '" spellcheck="false">'
                    . htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8') . '</textarea>';
            } else {
                $html .= '<input type="text" id="' . $id . '" name="t[' . $key . ']" value="' . $escaped
                    . '" maxlength="' . $spec['max'] . '"'
                    . ($spec['kind'] === 'hex' ? ' class="nl-hex"' : '') . '>';
            }

            $html .= '<p class="nl-hint">' . htmlspecialchars($spec['hint'], ENT_QUOTES, 'UTF-8') . '</p>';

            if ($spec['kind'] === 'hex') {
                $html .= '<p class="nl-hint"><span class="nl-swatch" data-for="' . $id . '"></span></p>';
            }

            $html .= '</td></tr>';
        }

        return $html;
    }

    /**
     * 后台提交进来的原始数组，收成干净的 patch（未知键丢掉，控件缺失当成没传）。
     *
     * 复选框是唯一需要特判的：不勾选时它压根不在 $_POST 里，而表单提交的是「这一屏的全部字段」，
     * 所以要把缺席当成关掉，否则永远关不掉。
     */
    public static function patchFromPost(): array
    {
        $raw = isset($_POST['t']) && is_array($_POST['t']) ? $_POST['t'] : array();
        $patch = array();

        foreach (self::FIELDS as $key => $spec) {
            if ($spec['kind'] === 'bool') {
                $patch[$key] = isset($raw[$key]) && $raw[$key] === '1' ? '1' : '';
                continue;
            }

            if (array_key_exists($key, $raw)) {
                $patch[$key] = $raw[$key];
            }
        }

        return $patch;
    }
}
