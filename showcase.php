<?php
/**
 * 项目展示
 *
 * 把这套代码自己摊开给人看：主题画了什么、插件管了什么、接口有哪些、怎么装进去。
 * 页面不写死任何规格——版本、强调色、站内数据全是现取的，所以它永远说的是「本机正在跑的
 * 这一套」，而不是发布那天抄下来的说明书。
 *
 * 后台新建页面时模板选「项目展示」，slug 建议用 showcase。
 *
 * @package custom
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/* helper 名字带 health，但它问的就是「NolineApi 在不在」——主题里认插件只有这一条路 */
$plugin = noline_health_plugin();
$stats = noline_site_stats();
$version = NOLINE_VERSION;

$repo = 'https://github.com/qine233/NoLine';
/* 发布规则要求 tag 名等于包里的版本号（_dev/publish_release.py 会当场核对），
   所以这条直链可以按版本拼；万一某个 Release 只建了没挂附件，就退回列表页。 */
$zip = $repo . '/releases/download/' . $version . '/noline.zip';

/** 端点清单和插件里 onRequest() 的 switch 一一对应。这一页只是给访客看的说明书。 */
$endpoints = array(
    array('get', '/api/ping', _t('连通性自检，不要密钥')),
    array('get', '/api/theme', _t('站点门面 + 14 项主题配置 + 由强调色推出的配色')),
    array('put', '/api/theme', _t('写主题配置，另一个开关单独控制，body 只带要改的键')),
    array('get', '/api/health', _t('体重概览：最新值 / BMI / 统计 / 趋势 / 目标')),
    array('get', '/api/health/records', _t('体重明细，limit 到 366')),
    array('post', '/api/health/records', _t('写一条或批量写，同一天覆盖')),
    array('delete', '/api/health/records', _t('按 date 删掉那天的记录')),
    array('get', '/api/bills', _t('记账概览：周 / 月 / 累计 / 近 7 个月 / 本月分类占比')),
    array('get', '/api/bills/records', _t('流水明细，limit 到 1000，可带 from / to / kind')),
    array('post', '/api/bills/records', _t('记一笔或批量记，返回 ids[]，永远新增')),
    array('put', '/api/bills/records', _t('按 id 改一条，只改传了的那些键')),
    array('delete', '/api/bills/records', _t('按 id 删一条')),
);

/** 主题侧的能力。图标名必须存在于 public/icons.php，写错了 noline_icon() 只是什么都不输出。 */
$features = array(
    array('sun', _t('令牌驱动配色'),
        _t('颜色全部集中在两个 :root 块：一支墨色按浓度派生出文字四档、表面三档、描边三档，'
            . '浅色 103 条 / 深色 66 条一一对齐。强调色在后台填一个十六进制就够。')),
    array('moon', _t('深色模式无闪烁'),
        _t('主题初始化脚本前置在 head 顶部，data-theme 先落地再画第一帧；'
            . '两档之间切换时卡片、图表、代码块、玻璃浮层一起翻，不用逐个抄色值。')),
    array('house', _t('首页信息流'),
        _t('masthead 头图 + 站名 + 座右铭，上面自动压一层渐变罩保证文字读得清。'
            . '封面卡带缩略图、阅读时长和最近评论预览；分类收成一条吸顶药丸带，溢出的收进「更多」面板，支持方向键漫游。')),
    array('book-open', _t('动态卡'),
        _t('命中「日记」分类的文章不当文章处理：不显示标题、正文原地铺开、超过六行折叠出「全文」。'
            . '首页和分类页同一套 partial。')),
    array('file-lines', _t('文章页'),
        _t('hero 头图 + 正文排版（二级标题下划线、引用块、表格、脚注、嵌套列表）+ 代码块红绿灯与复制按钮'
            . '+ 图片灯箱 + 目录 + 上下篇 + 相关文章 + 文末版权卡。')),
    array('bars', _t('右栏组件'),
        _t('双列 card-widget：月历、那年今日、分类、标签、目录、近期评论、站点信息，尾部 sticky 跟着滚动走。'
            . '分类卡是可选的，默认关——分类已经在信息流那条药丸带里出现过一次。')),
    array('arrow-up', _t('软跳转'),
        _t('站内跳转主路径是浏览器原生的跨文档视图过渡：新页没就绪之前旧页原样挂着，顶栏整块跨页连续。'
            . '浏览器不认就自动退回淡出 + 顶部进度条，两边都不白屏硬切。')),
    array('image', _t('失效图自愈'),
        _t('图挂了不留浏览器碎图标：换成主题自带的占位图说明「图片资料已失效」，圆形头像换中性剪影，'
            . '顶栏 logo 换回首字母块；没被链接包住的失效图点一下会拿原地址重试。')),
    array('heart', _t('密度与空态'),
        _t('根字号是一个全局旋钮（rem 化），移动端不降字号、只收留白。'
            . '各处空态有自绘图标和文案而不是一行灰字，按钮和药丸都有「按下去瘪一点」的反馈。')),
    array('magnifying-glass', _t('SEO 与性能'),
        _t('canonical + Open Graph + Twitter card + JSON-LD；CSS 走压缩版并按版本号 busting，'
            . '重查询按天缓存、发新文章主动失效；滚动接近底部时提前取好下一页；站点头像转存成站内文件。')),
);

/** 插件侧的三块能力。装不装都不影响主题其余部分。 */
$pillars = array(
    array('code', _t('对外接口'),
        _t('一把 X-Noline-Key 管住除 ping 以外的全部端点；写入和「写主题配置」各有一道独立开关，'
            . '默认只读。体重和记账的记录文件在 usr/uploads/noline_api/，各自一把锁，互不阻塞。'
            . '页面只画不能写，写进去只有接口一条路。')),
    array('image', _t('Cloudreve 图床'),
        _t('在插件设置里填网盘地址和那个账号的邮箱、密码，写文章和写页面的附件面板顶部就多一块图床区：'
            . '选文件、粘贴截图、拖图片进去都能传，传完按当前编辑器形态（Markdown 或富文本）把永久直链插进正文。'
            . 'Typecho 自带的附件上传一点不动，两者并存；邮箱、密码和令牌都只待在服务端。')),
    array('history', _t('自动覆盖更新'),
        _t('后台路过任意一个页面时查一次 Release（结论缓存 6 小时），发现新版本就当场下载、验收，'
            . '把旧主题和旧插件各整份挪进 usr/uploads/noline_backup/，再让新版本顶上，不需要点任何东西。'
            . '成对交换要么两边都成、要么两边都退回旧版本；历史版本全留在备份目录里，回滚同样成对。'
            . '连不上 GitHub 的站点可以配镜像，token 只发给 api.github.com，绝不发给镜像。')),
);

/** 自定义页面模板。选模板是在后台「新建页面」里，不是在文件里。 */
$templates = array(
    array('showcase.php', _t('就是这一页。不依赖插件，纯静态说明')),
    array('health.php', _t('健康记录页，体重/BMI 折线与统计；数据来自插件')),
    array('bills.php', _t('买菜记账页，周/月/累计开销、月度柱、分类占比与流水；数据来自插件')),
    array('dcument.php', _t('归档页，一次列全文章（代价：查询数是 O(文章数)，文章上千会慢）')),
    array('xiaoshi.php', _t('单分类页，靠页面的自定义字段 category 指定分类缩略名')),
    array('links.php', _t('友链页，依赖 Links 插件；没装时只显示提示，不硬编码站点列表')),
    array('404.php', _t('带空态插画的错误页')),
);

$steps = array(
    array(_t('下载发布包'),
        _t('拿 Release 附件 noline.zip（或在仓库里跑 python3 _dev/build.py）。包里有两棵根：'
            . 'noline/ 落到 usr/themes/noline，plugins/NolineApi/ 落到 usr/plugins/NolineApi，'
            . '在线更新一次把主题和插件一起换掉，不留版本错配的那一半。')),
    array(_t('启用主题'),
        _t('后台「控制台 → 主题」启用 Noline，到「设置外观」填这 14 项：站点头像地址、博主名字、座右铭、'
            . '首页头图、强调色、动态分类、导航图标、首页公告、右栏分类卡、GitHub 显示名与链接、'
            . 'Bilibili 显示名与链接、站点底部版权。这 14 项就是接口能读写的全部字段，一一对应，没有隐藏项。')),
    array(_t('要接口再装插件'),
        _t('把 plugins/NolineApi 整个目录拷到 usr/plugins/，后台「插件」里启用并填密钥。'
            . '启用后顶栏多一个「主题管理」：14 项设置的可视化编辑、接口自检、更新与回滚、图床接入都在那一页。'
            . '不需要「停用 → 再启用」——插件每次路过前台入口和后台菜单都会拿数据库里的登记和当前代码比一遍，'
            . '对不上就当场按核心语义重登记。')),
    array(_t('建个人页'),
        _t('想要那两个数据页就在「新建页面」里选模板：健康记录（slug 建议 health）、买菜记账（slug 建议 bills）；'
            . '这一页选「项目展示」（slug 建议 showcase）。三张页面都不存数据，删掉只是没了入口，'
            . '记录还在插件的文件里。')),
    array(_t('一句提醒'),
        _t('usr/themes/noline/views/ 目录别删：里面是历史浏览量的兜底文件。'
            . '新计数写在 usr/uploads/noline_views/，找不到才回读 views/，删了全站计数会从 0 重来。')),
);

$trades = array(
    _t('首页公告和底部版权原样存 HTML、不做标签清洗，接口写入也一样。这是有意的：公告里要能放链接和样式。'
        . '代价是拿到接口密钥的人能往前台注入脚本，所以 PUT /api/theme 有独立开关。'),
    _t('自动更新只认「Release 里的版本号比本机高」，不判断那个包本身是不是坏的。'
        . '装上就生效，靠成对备份 + 成对回滚兜底，发布前自己验一遍。'),
    _t('图床只实现「走 Cloudreve 服务端」的上传策略。实例把存储策略配成浏览器直传第三方对象存储时'
        . '插件会明确拒绝并提示去后台改成云端/本地，而不是自作主张去接一套签名协议。'
        . '实例开了登录验证码、或账号开了两步验证，插件绕不过去，只会在设置页把话说清楚。'),
    _t('不做「首屏关键 CSS 内联 + 其余异步」：异步补 CSS 会重新引入首屏白闪，这一版宁可留全量阻塞 CSS。'),
);

/* 强调色现取。这里只报「后台填的那一支」，不重算深色变体——派生发生在 header.php，
   两处各算一份迟早会分家，所以这页只说现值，变体交给下面的实时色块去演示。 */
$accent = noline_parse_hex(isset($this->options->accentColor) ? $this->options->accentColor : null);
$accentHex = $accent === null ? '' : vsprintf('#%02x%02x%02x', $accent);

/* 五格色块：背景一律挂 CSS 变量，所以深色模式一开，这一排自己跟着翻。 */
$swatches = array(
    array('--accent', _t('主色：链接、圆点、当前项')),
    array('--accent-hover', _t('它的 hover')),
    array('--accent-bg', _t('掺一成透明度：胶囊底、选中底')),
    array('--accent-container', _t('掺一成四：需要一块淡底的地方')),
    array('--heading-marker', _t('唯一不参与派生的：正文标题那颗暖金标记')),
);

$this->need('public/header.php');
?>

<div class="container">
    <div class="grid-layout">

        <div class="main-content">
            <article class="card article-card">
                <h1 class="post-title page-title"><?php $this->title(); ?></h1>
                <p class="page-subtitle"><?php noline_icon('github'); ?> <?php _e('一个 Typecho 主题和一个可选的 REST 接口插件；这一页由它自己画'); ?></p>

                <?php if (trim((string)$this->text) !== ''): ?>
                <div class="post-content"><?php $this->content(); ?></div>
                <?php endif; ?>

                <div class="show-facts">

                    <div class="show-tile">
                        <span class="show-tile-label"><?php noline_icon('tag'); ?> <?php _e('主题版本'); ?></span>
                        <span class="show-tile-value"><?php echo htmlspecialchars($version); ?></span>
                        <span class="show-tile-note"><?php _e('就是正在渲染这一页的那份'); ?></span>
                    </div>

                    <div class="show-tile">
                        <span class="show-tile-label"><?php noline_icon('box-archive'); ?> <?php _e('接口插件'); ?></span>
                        <?php if ($plugin === null): ?>
                        <span class="show-tile-value is-off"><?php _e('没装'); ?></span>
                        <span class="show-tile-note"><?php _e('主题照常跑，只少接口和那一页后台'); ?></span>
                        <?php else: ?>
                        <span class="show-tile-value"><?php _e('已启用'); ?></span>
                        <span class="show-tile-note"><?php _e('和主题同一个包、同一次交换'); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="show-tile">
                        <span class="show-tile-label"><?php noline_icon('code'); ?> <?php _e('接口端点'); ?></span>
                        <span class="show-tile-value"><?php echo count($endpoints); ?><i><?php _e('条'); ?></i></span>
                        <span class="show-tile-note"><?php _e('鉴权走请求头 X-Noline-Key'); ?></span>
                    </div>

                    <div class="show-tile">
                        <span class="show-tile-label"><?php noline_icon('calendar'); ?> <?php _e('本站在跑'); ?></span>
                        <span class="show-tile-value"><?php echo (int)$stats['posts']; ?><i><?php _e('篇'); ?></i></span>
                        <span class="show-tile-note">
                            <?php
                            echo htmlspecialchars(sprintf(
                                _t('%1$d 条评论 · 开站第 %2$d 天'),
                                (int)$stats['comments'],
                                (int)$stats['days']
                            ));
                            ?>
                        </span>
                    </div>

                </div>

                <div class="show-actions">
                    <a class="btn-primary" href="<?php echo htmlspecialchars($zip, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php noline_icon('box-archive'); ?> <?php _e('下载 noline.zip'); ?>
                    </a>
                    <a class="show-action" href="<?php echo htmlspecialchars($repo, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php noline_icon('github'); ?> <?php _e('仓库'); ?>
                    </a>
                    <a class="show-action" href="<?php echo htmlspecialchars($repo . '/releases', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php noline_icon('history'); ?> <?php _e('更新记录'); ?>
                    </a>
                    <a class="show-action" href="<?php echo htmlspecialchars($repo . '/blob/ai-1.0/APP-API-SPEC.md', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php noline_icon('file-lines'); ?> <?php _e('接口文档'); ?>
                    </a>
                    <?php if ($plugin !== null): ?>
                    <a class="show-action" href="<?php echo htmlspecialchars((string)$this->options->siteUrl . '?noline_api=ping', ENT_QUOTES, 'UTF-8'); ?>">
                        <?php noline_icon('paper-plane'); ?> <?php _e('试一下自检'); ?>
                    </a>
                    <?php endif; ?>
                </div>

                <p class="show-req">
                    <?php
                    echo htmlspecialchars(sprintf(
                        _t('环境：Typecho 1.2.x · PHP 7.4 及以上 · 主题当前版本 %s。'),
                        $version
                    ));
                    ?>
                    <?php _e('前端没有 CDN：图标是内联 SVG，highlight.js 走本地文件且只在有代码块的那几页加载。'); ?>
                </p>

                <h2 class="show-heading"><?php _e('主题画了什么'); ?></h2>

                <div class="show-grid">
                    <?php foreach ($features as $feature): ?>
                    <div class="show-card">
                        <span class="show-card-title"><?php noline_icon($feature[0]); ?> <?php echo $feature[1]; ?></span>
                        <p class="show-card-text"><?php echo $feature[2]; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h2 class="show-heading"><?php _e('颜色只有两支'); ?></h2>

                <div class="show-block">
                    <div class="show-block-head">
                        <span class="card-header"><?php noline_icon('compass'); ?><?php _e('这一排是实时取的'); ?></span>
                        <span class="show-block-note">
                            <?php
                            echo $accentHex === ''
                                ? _t('后台还没填强调色')
                                : htmlspecialchars($accentHex);
                            ?>
                        </span>
                    </div>

                    <div class="show-palette">
                        <?php foreach ($swatches as $swatch): ?>
                        <span class="show-swatch">
                            <i class="show-swatch-fill" style="background-color: var(<?php echo $swatch[0]; ?>)"></i>
                            <code class="show-swatch-name"><?php echo $swatch[0]; ?></code>
                            <span class="show-swatch-role"><?php echo $swatch[1]; ?></span>
                        </span>
                        <?php endforeach; ?>
                    </div>

                    <p class="show-palette-note">
                        <?php
                        echo $accentHex === ''
                            ? _t('后台「设置外观 → 强调色」填一支十六进制，整站连同上面这五格立刻跟着换；深色模式按亮度自动推亮一档，剩下的层次全是这两支掺透明度。')
                            : sprintf(_t('后台只填了 %s 这一支；深色模式按亮度自动推亮，剩下的层次全是这两支掺透明度——上面这五格现在显示的就是这一档。'), $accentHex);
                        ?>
                        <?php _e('切到深色模式再看一次，这五格会跟着翻，因为每一格挂的都是变量而不是色值。'); ?>
                    </p>
                </div>

                <h2 class="show-heading"><?php _e('插件管了什么'); ?></h2>

                <div class="show-pillars">
                    <?php foreach ($pillars as $pillar): ?>
                    <div class="show-pillar">
                        <span class="show-card-title"><?php noline_icon($pillar[0]); ?> <?php echo $pillar[1]; ?></span>
                        <p class="show-pillar-text"><?php echo $pillar[2]; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="show-block">
                    <div class="show-block-head">
                        <span class="card-header"><?php noline_icon('link'); ?><?php _e('接口清单'); ?></span>
                        <span class="show-block-note">
                            <?php
                            echo $plugin === null
                                ? _t('本机还没装插件，下面这张表是装好之后就有的')
                                : _t('本机正在提供这些端点');
                            ?>
                        </span>
                    </div>

                    <?php if ($plugin === null): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?php noline_icon('circle-info'); ?></div>
                        <p class="empty-state-text"><?php _e('把插件目录放进 usr/plugins/NolineApi/，在「控制台 → 插件」里启用，接口就在了。'); ?></p>
                        <p class="empty-state-text"><?php _e('主题不依赖插件：没装也照样是一整套能用的博客皮肤。'); ?></p>
                    </div>
                    <?php endif; ?>

                    <div class="show-api">
                        <?php foreach ($endpoints as $endpoint): ?>
                        <div class="show-api-row">
                            <span class="show-api-method" data-m="<?php echo $endpoint[0]; ?>"><?php echo strtoupper($endpoint[0]); ?></span>
                            <code class="show-api-path"><?php echo $endpoint[1]; ?></code>
                            <span class="show-api-desc"><?php echo $endpoint[2]; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="show-palette-note">
                        <?php _e('三种写法都认：/api/health（伪静态开着）、/index.php/api/health（伪静态关了）、?noline_api=health（服务器把 /api 抢走了时的兜底）。'); ?>
                        <?php _e('后台顶栏的「主题管理」页是另一个入口，它看的是登录态，不吃接口密钥。'); ?>
                    </p>
                </div>

                <h2 class="show-heading"><?php _e('页面模板'); ?></h2>

                <div class="show-tpl">
                    <?php foreach ($templates as $template): ?>
                    <div class="show-tpl-row">
                        <code class="show-tpl-file"><?php echo $template[0]; ?></code>
                        <span class="show-tpl-desc"><?php echo $template[1]; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <h2 class="show-heading"><?php _e('怎么装进去'); ?></h2>

                <ol class="show-steps">
                    <?php foreach ($steps as $no => $step): ?>
                    <li class="show-step">
                        <span class="show-step-no"><?php echo (int)$no + 1; ?></span>
                        <div class="show-step-body">
                            <span class="show-step-title"><?php echo $step[0]; ?></span>
                            <p class="show-step-text"><?php echo $step[1]; ?></p>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>

                <h2 class="show-heading"><?php _e('已知取舍'); ?></h2>

                <ul class="show-notes">
                    <?php foreach ($trades as $trade): ?>
                    <li class="show-note"><?php echo $trade; ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($this->user->hasLogin()): ?>
                <p class="show-foot">
                    <?php _e('给你自己看的：版本号一共四处——主题的 NOLINE_VERSION 与 index.php 头部注释的 @version，插件的 const VERSION 与文件头 @version。前两处必须相等、后两处必须相等，_dev/build.py 会核对；推一个等于版本号的 tag，Actions 就会打出 noline.zip 并挂到 Release 上。'); ?>
                </p>
                <?php endif; ?>
            </article>
        </div>

        <?php $this->need('public/sidebar.php'); ?>

    </div>
</div>

<?php $this->need('public/footer.php'); ?>
