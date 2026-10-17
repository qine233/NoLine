<?php

namespace TypechoPlugin\NolineApi;

use Typecho\Http\Client;
use Widget\Options;
use Widget\User;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 从 GitHub Release 更新主题：检测 → 下载 noline.zip → 解压验收 → 旧主题和旧插件各整份挪走当备份 → 新版本顶上。
 *
 * 包里有两个根：noline/ 落到 usr/themes/noline，plugins/NolineApi/ 落到 usr/plugins/NolineApi。
 * 两个目录是一对，一次交换要么都成要么都退回去；换完插件不改数据库里的钩子登记，
 * 那部分由 Plugin::resyncHooks() 在下一次路过入口钩子时自己补齐。
 *
 * 三个硬门槛，缺任何一个都只给手动说明，不做半吊子的自动升级：
 *   连不上 GitHub（没 curl 又没 allow_url_fopen）
 *   解不开包（没 ZipArchive）
 *   换不了目录（usr/themes 与 usr/uploads 不在同一设备，rename 会 EXDEV 失败）
 *
 * 「拉不到」和「没有新版本」必须分开说：一次网络抖动被报成「已是最新」，人会照着做错误决定。
 * 所以成功过的检测结果落盘缓存，重新拉取失败时把上一次的成功结果摊出来、并标清它是旧的。
 *
 * 交换期间 usr/themes/noline 有几十微秒不存在，并发请求可能撞上一次 500；
 * 这是选定的代价，换来的是不用 FTP。中途被 PHP 超时或断电打断由 state.json 里的 pending 标记兜：
 * autoTick() 和进「主题管理」页都会先 repair()，发现主题目录不见了就自动把备份搬回去。
 *
 * 自动升级默认开着：后台每个页面都会过一遍 autoTick()，检测到新版本就自己下载覆盖完，不等人点按钮。
 * 它只认管理员的登录态、每 AUTO_WAIT 秒最多跑一轮（失败也一样，免得每个页面都重试打爆 GitHub 限额），
 * 结论落进 state.json，人下次进这一页看到的是「已经升完了」而不是一个待办的按钮。
 *
 * 镜像是第二通道，不是替代：GitHub 直连先走，连不上 / 超时 / 被限流 / 5xx 才按设置里的顺序退到镜像，
 * 一条镜像试不通就冷却 MIRROR_WAIT 秒。「检测能不能成」和「包能不能装」在这儿是两件事——
 * 摘要只认 api.github.com 直连给的那份 sha256：镜像给什么字节我们信什么字节的话，它连摘要一起给就永远自洽，
 * 所以只从镜像拿到结果时自动覆盖会停住，等人进这一页自己确认过再点。
 */
class Updater
{
    /** Release 附件必须叫这个名字，build.py 也是这么产出的 */
    private const ASSET = 'noline.zip';

    private const WORK = '/usr/uploads/noline_update';
    private const BACKUP = '/usr/uploads/noline_backup';

    /** 包里的第二个根：build.py 把插件按这个路径打进来，交换时和主题一起换 */
    private const PACKAGE_PLUGIN = 'plugins/NolineApi';

    /** 本机插件目录名，对着 usr/plugins/ */
    private const LIVE_PLUGIN = 'NolineApi';

    /** 检测结果的缓存时长。GitHub 匿名限流是每 IP 每小时 60 次，别拿刷新去撞它 */
    private const TTL = 21600;

    private const MAX_ZIP = 20971520;           // 20MB，主题包实际只有几百 KB
    private const MAX_UNCOMPRESSED = 62914560;  // 解压后 60MB 封顶，挡压缩炸弹
    private const MAX_ENTRIES = 4000;

    /** 自动模式两轮之间的最短间隔。检测本身有 TTL 缓存，这个闸是给「失败也先歇一小时」用的 */
    private const AUTO_WAIT = 3600;

    private const DEFAULT_REPO = 'qine233/noline';

    /**
     * 预置的公共镜像，一行一条，用户在后台可以随便改。
     *
     * 2026-10-15 实测：三个都能把 Release 附件原样代理出来（sha256 与 GitHub 给的一致）；
     * 只有 gh-proxy.com 连 api.github.com 一起代理，另两个对 API 回 403——
     * 所以它们只会参与下载那一步，检测还是得直连或者另找镜像。
     */
    public const DEFAULT_MIRRORS = "https://gh-proxy.com/\nhttps://ghfast.top/\nhttps://ghproxy.net/";

    /** 试不通的镜像冷却这么久：一次更新要撞三条死镜像，比直连超时还慢，不能每个后台页面都来一遍 */
    private const MIRROR_WAIT = 1800;

    /** 设置里最多认这么多条，多出来的直接忽略 */
    private const MAX_MIRRORS = 8;

    /** 检测用的 GitHub 地址；镜像的前缀式形态就是把它或下载直链拼在后面 */
    private const API_URL = 'https://api.github.com/repos/%s/releases/latest';

    /** 附件直链一律自己按 repo + tag 拼，不拿镜像回的 JSON 里的 URL 去下载 */
    private const DOWNLOAD_URL = 'https://github.com/%s/releases/download/%s/' . self::ASSET;

    /**
     * 只有直连说出口才算定论、不再换下一条的状态码。
     * 404 是 GitHub 亲口说「这个仓库没有已发布的 Release」，换出口问不出别的答案；
     * 镜像回 404 通常只是它没代理 API。
     */
    private const DIRECT_FINAL = array(404);

    /** 这几个域名填进镜像设置里没有意义：直连本来就走它们，去重时直接丢掉 */
    private const DIRECT_HOSTS = array(
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'raw.githubusercontent.com',
    );

    /* ======================================================================
       页面区块与动作
       ====================================================================== */

    /**
     * 管理页里的「主题与插件更新」这一段。一次更新同时换 usr/themes/noline 和 usr/plugins/NolineApi。
     */
    public static function section(string $token, string $url): string
    {
        $check = self::check(false);
        $env = self::env();
        $block = self::blocking();
        $version = self::installedVersion();
        $canUpgrade = $check['ok'] && $check['available'] && $block === '';

        $html = '<h3 class="nl-h3">' . Admin::esc(_t('主题与插件更新')) . '</h3>';

        $auto = Plugin::setting('autoUpgrade', '1') === '1';
        $state = self::readState();
        $last = isset($state['auto']) && is_array($state['auto']) ? $state['auto'] : array();

        $html .= '<div class="nl-chips">';
        foreach ($env as $item) {
            $html .= '<span class="nl-chip nl-chip-' . $item[0] . '">' . Admin::esc($item[1]) . '</span>';
        }
        $html .= '<span class="nl-chip nl-chip-' . ($auto ? 'ok' : 'muted') . '">'
            . Admin::esc($auto ? _t('自动升级开着') : _t('自动升级已关')) . '</span>';

        $html .= '<span class="nl-chip nl-chip-' . self::viaLevel($check) . '">'
            . Admin::esc(self::viaText($check)) . '</span>';

        $html .= '<span class="nl-chip nl-chip-' . ($check['sha_trusted'] ? 'ok' : 'muted') . '">'
            . Admin::esc($check['sha_trusted']
                ? _t('摘要可信（GitHub 直连给的）')
                : ($check['sha'] === '' ? _t('GitHub 没给摘要') : _t('摘要只来自镜像'))) . '</span>';

        $html .= '</div>';

        $html .= self::mirrorsHtml($check);

        $stateText = !$check['ok']
            ? _t('没拿到')
            : ($check['available'] ? _t('有新版本') : _t('已经是最新'));

        $html .= '<div class="nl-ver">'
            . '<div class="nl-ver-now"><b>' . Admin::esc($version === '' ? _t('未知') : $version) . '</b>'
            . '<em>' . Admin::esc(_t('本机在跑')) . '</em></div>'
            . '<div class="nl-ver-arrow">→</div>'
            . '<div class="nl-ver-latest"><b>' . Admin::esc($check['ok'] ? $check['tag'] : _t('未知')) . '</b>'
            . '<em>' . Admin::esc($stateText) . '</em></div></div>';

        $pluginNow = self::installedPluginVersion();

        $html .= '<p class="nl-note">' . Admin::esc($pluginNow === ''
            ? _t('本机没装 NolineApi 插件，这一轮只换主题。')
            : _t('插件 %s 跟着一起换：包里带着 plugins/NolineApi/，'
                . '新插件的钩子登记会在下一个页面自己对齐，不用去插件页停用再启用。', $pluginNow))
            . '</p>';

        if (isset($last['at']) && !empty($last['html'])) {
            $level = in_array(isset($last['level']) ? $last['level'] : '', array('success', 'error', 'warn'), true)
                ? $last['level'] : 'warn';
            $html .= '<div class="nl-msg nl-msg-' . $level . '">'
                . Admin::esc(_t('%s 自动跑过一轮：', date('m-d H:i', (int)$last['at']))) . $last['html']
                . '</div>';
        }

        if ($check['cached']) {
            $html .= '<p class="nl-note">' . Admin::esc(_t(
                '上面是 %s 的检测结果，缓存 %d 小时。点「重新检测」才会真的再访问一次 GitHub。',
                date('m-d H:i', (int)$check['checked']),
                (int)round(self::TTL / 3600)
            )) . '</p>';
        }

        if (!$check['ok']) {
            $html .= '<div class="nl-msg nl-msg-error">' . Admin::esc($check['error']) . '</div>';
        }

        if ($check['failed'] !== '') {
            $html .= '<div class="nl-msg nl-msg-warn">'
                . Admin::esc(_t('刚才重新拉取失败了：%s。下面显示的仍是上次成功的结果。', $check['failed']))
                . '</div>';
        }

        if ($check['ok'] && !$check['comparable']) {
            $html .= '<div class="nl-msg nl-msg-warn">'
                . Admin::esc(_t('版本号写法对不上（本机 %s / Release %s），只能按「不相等就当有更新」处理，'
                    . '升级前自己确认一下哪个更新。', $version, $check['tag']))
                . '</div>';
        }

        /* 自动那一轮会被这个条件挡下来，手动按钮不会：得让人知道为什么要自己点一下 */
        if ($check['ok'] && $check['available'] && !$check['sha_trusted']) {
            $html .= '<div class="nl-msg nl-msg-warn">'
                . Admin::esc(self::trustReason($check))
                . '</div>';
        }

        if ($block !== '') {
            $html .= '<div class="nl-msg nl-msg-warn">' . Admin::esc($block) . '</div>';
        }

        if ($check['ok'] && $check['body'] !== '') {
            $html .= '<details class="nl-log" open><summary>'
                . Admin::esc(_t('这个版本改了什么（Release 正文原文，Markdown）'))
                . '</summary><pre>' . Admin::esc(self::trimBody($check['body'])) . '</pre></details>';
        }

        $ask = $canUpgrade
            ? _t('确定升级吗？旧主题和旧插件会各自整份备份，站点会有极短的重启窗口。')
            : _t('重新访问 GitHub 检测一次？');

        $html .= '<form class="nl-inline" method="post" action="' . Admin::esc($url) . '"'
            . ' data-ask="' . Admin::esc($ask) . '">'
            . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">';

        if ($canUpgrade) {
            $html .= '<button type="submit" class="btn btn-l primary" name="action" value="upgrade">'
                . Admin::esc(_t('备份并升级到 %s', $check['tag'])) . '</button> ';
        }

        $html .= '<button type="submit" class="btn" name="action" value="refresh">'
            . Admin::esc(_t('重新检测')) . '</button> ';

        if ($check['page'] !== '') {
            $html .= '<a class="btn btn-s" href="' . Admin::esc($check['page']) . '" target="_blank" rel="noopener">'
                . Admin::esc(_t('Release 页')) . '</a>';
        }

        if ($check['asset'] !== '') {
            $html .= ' <a class="btn btn-s" href="' . Admin::esc($check['asset']) . '" rel="noopener">'
                . Admin::esc(_t('只下 zip（GitHub 直链）')) . '</a>'
                . '<span class="nl-note">' . Admin::esc(_t(
                    '手动更新：解压后把 noline/ 覆盖到 usr/themes/noline，'
                    . '包里的 plugins/NolineApi/ 覆盖到 usr/plugins/NolineApi，'
                    . '记得留 views/ 里本机独有的文件。'))
                . '</span>';
        }

        $html .= '</form>';

        $html .= self::backupsHtml($token, $url);
        $html .= self::leftoversHtml($token, $url);

        return $html;
    }

    /**
     * 更新相关的动作。文案一律已经 HTML 转义，页面直接输出。
     *
     * @return array [级别, HTML 文案]
     */
    public static function act(string $action): array
    {
        switch ($action) {
            case 'refresh':
                $check = self::check(true);

                /* 强制拉取失败时 check() 会退回上一次的成功结果，那里的 ok 仍是 true。
                   不先岔开这一条，就会拿旧版本号报一句「检测成功」，看着像按钮压根没生效 */
                if ($check['failed'] !== '') {
                    return array('error', Admin::esc(_t(
                        '这一轮没拉到新的：%s。下面显示的仍是 %s 那次成功检测的结果。',
                        $check['failed'],
                        date('m-d H:i', (int)$check['checked'])
                    )));
                }

                if (!$check['ok']) {
                    return array('error', Admin::esc(_t('检测失败：') . $check['error']));
                }

                return array('success', Admin::esc($check['available']
                    ? _t('发现新版本 %s（本机 %s）。', $check['tag'], self::installedVersion())
                    : _t('已经是最新版本 %s。', $check['tag'])));

            case 'upgrade':
                return self::upgrade();

            case 'rollback':
                return self::rollback(isset($_POST['to']) ? (string)$_POST['to'] : '');

            case 'drop_backup':
                return self::dropBackup(isset($_POST['to']) ? (string)$_POST['to'] : '');

            case 'clean_work':
                return self::cleanWork();

            default:
                return array('error', Admin::esc(_t('不认识的更新操作。')));
        }
    }

    /* ======================================================================
       自动升级
       ====================================================================== */

    /**
     * 后台每一个页面都会过一下（Plugin::onAdminMenu → admin/menu.php:navBar）。
     * 发现新版本就当场升完，不再攒着等人点按钮。
     */
    public static function autoTick()
    {
        if (Plugin::setting('autoUpgrade', '1') !== '1') {
            return;
        }

        /* 顶栏对所有能进后台的人都渲染，编辑和 subscriber 也算；
           搬主题目录这种事只跟着站长走 */
        if (!User::alloc()->pass('administrator', true)) {
            return;
        }

        $state = self::readState();
        $last = isset($state['auto']) && is_array($state['auto']) ? $state['auto'] : array();

        if (isset($last['at']) && (int)$last['at'] + self::AUTO_WAIT > time()) {
            return;
        }

        /* 先把上次没走完的交换圆回来。主题目录已经不在原地时还去下载新包，
           只会把「前台坏了」变成「前台坏了而且没人知道」 */
        $fixed = self::repair();

        if ($fixed !== '') {
            self::note('warn', Admin::esc($fixed));

            return;
        }

        $check = self::check(false);

        if (empty($check['ok'])) {
            /* 一次都没成功过、这回又连不上：打时间戳，别拿每个后台页面去撞限流 */
            self::note('warn', Admin::esc($check['error']));

            return;
        }

        if (empty($check['available'])) {
            /* 已经是最新。结论在 TTL 缓存里，缓存到期前不会再碰网络，不用额外打标记 */
            return;
        }

        /* 刚被人手动回滚掉的正是这一版，别当场又顶上去。等下一个比它新的 tag */
        $fresh = self::readState();
        $floor = self::normalize(isset($fresh['auto_floor']) ? (string)$fresh['auto_floor'] : '');

        if ($floor !== '' && self::normalize($check['tag']) === $floor) {
            return;
        }

        $block = self::blocking();

        if ($block !== '') {
            self::note('warn', Admin::esc($block));

            return;
        }

        /* 摘要来路不可信就不自动搬主题目录：静默换上一份「和镜像给的摘要一致」的包，
           等于把站点交给那个镜像。人自己进这一页点确认是另一回事，autoTick 不代办。 */
        if (empty($check['sha_trusted'])) {
            self::note('warn', Admin::esc(self::trustReason($check)));

            return;
        }

        $result = self::upgrade(true);
        self::note($result[0], $result[1]);
    }

    /**
     * 自动这一轮的结论落盘，好让人下次进页面看见的是「已经升完了」而非一排待办按钮。
     * 存进去的 HTML 已经转义过，页面上直接输出、不再二次转义。
     */
    private static function note(string $level, string $html)
    {
        $state = self::readState();
        $state['auto'] = array('at' => time(), 'level' => $level, 'html' => $html);
        self::saveState($state);
    }

    /* ======================================================================
       检测
       ====================================================================== */

    /**
     * 远端最新情况。默认优先吃缓存。
     */
    private static function check(bool $force = false): array
    {
        $state = self::readState();
        $repo = self::repo();
        $cached = isset($state['check']) && is_array($state['check']) ? $state['check'] : null;

        /* 换了仓库就是换了上游，旧结果一点参考价值都没有 */
        if ($cached !== null && (isset($cached['repo']) ? (string)$cached['repo'] : '') !== $repo) {
            $cached = null;
            $force = true;
        }

        if (!$force && $cached !== null
            && (isset($cached['checked']) ? (int)$cached['checked'] : 0) + self::TTL > time()) {
            $cached['cached'] = true;

            return self::withRelation($cached);
        }

        $fresh = self::fetch($repo);
        $fresh['checked'] = time();
        $fresh['repo'] = $repo;
        $fresh['cached'] = false;
        $fresh['failed'] = '';

        if ($fresh['ok']) {
            /* 重读一遍再写：这一轮 fetch() 可能刚把某条镜像的冷却记进去，
               拿开头的旧快照覆盖会把那条记录抹掉 */
            $state = self::readState();
            $state['check'] = $fresh;
            unset($state['last_error']);
            self::saveState($state);

            return self::withRelation($fresh);
        }

        if ($cached !== null && !empty($cached['ok'])) {
            $cached['cached'] = true;
            $cached['failed'] = $fresh['error'];

            return self::withRelation($cached);
        }

        $state = self::readState();
        $state['last_error'] = $fresh['error'];
        self::saveState($state);

        return self::withRelation($fresh);
    }

    /**
     * 远端事实可以缓存，本机版本是当下的——回滚或手动换过主题之后，
     * 「有没有新版本」必须重算，不能沿用缓存里那个结论。
     */
    private static function withRelation(array $check): array
    {
        $check = array_merge(array(
            'ok' => false, 'error' => '', 'tag' => '', 'body' => '', 'page' => '',
            'asset' => '', 'size' => 0, 'sha' => '', 'checked' => 0, 'cached' => false,
            'failed' => '', 'repo' => '', 'api_via' => '', 'api_hops' => array(),
        ), $check);

        /* 摘要只有在 GitHub 亲口给的时候才算可信：镜像要改包，连摘要也能一起编。
           老缓存里没有 api_via 字段，一律按不可信处理——TTL 到期自然会被新结果顶掉。 */
        $check['sha_trusted'] = $check['api_via'] === 'github' && $check['sha'] !== '';

        $mine = self::normalize(self::installedVersion());
        $theirs = self::normalize($check['tag']);

        $check['available'] = false;
        $check['comparable'] = true;

        if ($check['ok'] && $theirs !== '') {
            if (self::looksNumeric($mine) && self::looksNumeric($theirs)) {
                $check['available'] = version_compare($theirs, $mine) > 0;
            } else {
                $check['comparable'] = false;
                $check['available'] = $theirs !== $mine;
            }
        }

        return $check;
    }

    /**
     * 走一遍检测的候选链：GitHub 直连打头，后面接设置里没在冷却的镜像。
     *
     * 「试了一条不通就换下一条」和「上游压根没有 Release」必须分开：404 只有直连说的算数，
     * 镜像回 404 更像是它没代理 GitHub 的 API 路径，接着往下试就是了。
     * 反过来，token 出问题（401）时镜像反倒可能成功——token 压根不会发给镜像。
     */
    private static function fetch(string $repo): array
    {
        $out = array(
            'ok' => false, 'error' => '', 'tag' => '', 'body' => '', 'page' => '',
            'asset' => '', 'size' => 0, 'sha' => '', 'api_via' => '', 'api_hops' => array(),
        );

        if (!preg_match('#^[A-Za-z0-9._-]{1,60}/[A-Za-z0-9._-]{1,100}$#', $repo)) {
            $out['error'] = _t('仓库要写成 owner/repo，现在是「%s」。', $repo);

            return $out;
        }

        $candidates = array(array('github', sprintf(self::API_URL, $repo), false));

        foreach (self::mirrors() as $mirror) {
            if ($mirror['cooling'] > 0) {
                continue;
            }

            $url = self::mirrorPlan($mirror['line'], $repo, '', 'check');

            if ($url === '') {
                continue;
            }

            $candidates[] = array($mirror['host'], $url, true);
        }

        $failures = array();

        foreach ($candidates as $candidate) {
            $parsed = self::parseRelease(
                self::http($candidate[1], 15, true),
                $candidate[0],
                $repo,
                $candidate[2]
            );

            if ($parsed['final']) {
                $out['error'] = $parsed['why'];

                return $out;
            }

            if ($parsed['ok']) {
                return array_merge($out, $parsed['data'], array(
                    'ok' => true,
                    'api_via' => $candidate[0],
                    'api_hops' => $failures,
                ));
            }

            self::hopFail($failures, $candidate[0], $parsed['why'], $candidate[2]);
        }

        $out['api_hops'] = $failures;
        $out['error'] = self::hopError($failures);

        return $out;
    }

    /**
     * 一次检测应答的三种归类：可以用、换下一条、这条答案就是最终答案。
     *
     * 结构性的坏消息（Release 里压根没有 noline.zip、附件大得不像主题包）只有 GitHub
     * 直连说的才当定论；镜像回的 JSON 随时可能是被改写或截断过的，换一条出口再问一次。
     *
     * @return array ['ok' => bool, 'final' => bool, 'why' => string, 'data' => array]
     */
    private static function parseRelease(array $http, string $label, string $repo, bool $isMirror): array
    {
        if ($http['error'] !== '') {
            return self::miss($http['error'], false);
        }

        $direct = !$isMirror;

        if (in_array($http['status'], self::DIRECT_FINAL, true)) {
            return self::miss($direct
                ? _t('%s 没有可用的 Release。latest 只认「已发布、非草稿」里最新的那一个，'
                    . 'draft 状态的 release 看不见。', $repo)
                : _t('HTTP 404，这条镜像多半没代理 GitHub 的 API。'), $direct);
        }

        if ($http['status'] === 401) {
            return self::miss(_t('GitHub 不认设置里那个 token（HTTP 401），它多半已被撤销或过期。'
                . '把 token 清空保存一次就退回匿名访问。'), false);
        }

        if ($http['status'] === 403 || $http['status'] === 429) {
            return self::miss(trim(Plugin::setting('ghToken', '')) === ''
                ? _t('被 GitHub 限流了（匿名访问每个 IP 每小时 60 次），或者这条镜像本身回 %d。'
                    . '在插件设置里填一个只读 token，或者一小时后再来。', $http['status'])
                : _t('GitHub 用 HTTP %d 挡了这个 token：权限不够、已被撤销，或者额度用完了。'
                    . '先把 token 清空退回匿名访问试一次。', $http['status']), false);
        }

        if ($http['status'] !== 200) {
            return self::miss($isMirror
                ? _t('镜像 %1$s 返回 HTTP %2$d（%3$s 没把它代理出来）。', $label, $http['status'], $repo)
                : _t('GitHub 返回了 HTTP %d。', $http['status']), false);
        }

        $data = json_decode($http['body'], true);

        if (!is_array($data) || empty($data['tag_name'])) {
            return self::miss($isMirror
                ? _t('镜像 %s 回的内容不是预期的 release JSON，多半只代理了附件、没代理 API。', $label)
                : _t('返回的内容不像预期的 release JSON，可能被代理或 CDN 改写了。'), false);
        }

        $tag = trim((string)$data['tag_name']);
        $payload = array(
            'tag' => $tag,
            'body' => isset($data['body']) && is_string($data['body']) ? $data['body'] : '',
            'page' => isset($data['html_url']) ? (string)$data['html_url'] : '',
            'asset' => '',
            'size' => 0,
            'sha' => '',
        );

        $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();

        foreach ($assets as $asset) {
            if (!is_array($asset) || !isset($asset['name'])) {
                continue;
            }

            if (strtolower(trim((string)$asset['name'])) !== self::ASSET) {
                continue;
            }

            $payload['size'] = isset($asset['size']) ? (int)$asset['size'] : 0;
            /* GitHub 给了摘要就校验，没给就跳过——不能因为它没提供 digest 就拒绝升级 */
            $payload['sha'] = isset($asset['digest']) && is_string($asset['digest'])
                ? strtolower(trim($asset['digest'])) : '';
            /* 直链一律自己按 repo + tag 拼，不用 JSON 里的 browser_download_url */
            $payload['asset'] = self::assetUrl($repo, $tag);

            break;
        }

        if ($payload['asset'] === '') {
            return self::miss(_t('这个 Release 里没有叫 %s 的附件。发布时把 build.py 产出的 dist/noline.zip '
                . '按原名上传成附件即可。', self::ASSET), $direct);
        }

        if ($payload['size'] > self::MAX_ZIP) {
            return self::miss(_t('附件超过 %d MB，不像一个主题包，拒绝下载。',
                (int)round(self::MAX_ZIP / 1048576)), $direct);
        }

        return array('ok' => true, 'final' => false, 'why' => '', 'data' => $payload);
    }

    private static function miss(string $why, bool $final): array
    {
        return array('ok' => false, 'final' => $final, 'why' => $why, 'data' => array());
    }

    /**
     * 一次 GET。curl 优先，没 curl 退到 allow_url_fopen，两条都不通才算连不上。
     *
     * @return array ['status' => int, 'body' => string, 'error' => string]
     */
    private static function http(string $url, int $timeout, bool $json): array
    {
        if (extension_loaded('curl')) {
            $client = Client::get();

            if ($client === null) {
                return array('status' => 0, 'body' => '', 'error' => _t('curl 扩展状态异常。'));
            }

            $client->setTimeout($timeout);
            $client->setAgent('NolineApi/updater');
            $client->setHeader('Accept', $json ? 'application/vnd.github+json' : 'application/zip');

            $token = self::tokenFor($url);

            if ($token !== '') {
                $client->setHeader('Authorization', 'Bearer ' . $token);
            }

            /* 核心的客户端不带 FOLLOWLOCATION，而 Release 附件直链会 302 到
               objects.githubusercontent.com：少这一条，下载永远只拿到一个空 body */
            $client->setOption(CURLOPT_FOLLOWLOCATION, true);
            $client->setOption(CURLOPT_MAXREDIRS, 5);
            $client->setOption(CURLOPT_CONNECTTIMEOUT, min(10, $timeout));

            try {
                $client->send($url);

                return array(
                    'status' => $client->getResponseStatus(),
                    'body' => $client->getResponseBody(),
                    'error' => '',
                );
            } catch (\Throwable $e) {
                /* 证书、DNS、TLS 版本都从这里冒出来，原文递给用户比自己猜有用 */
                return array('status' => 0, 'body' => '', 'error' => _t('请求 %s 失败：%s', $url, $e->getMessage()));
            }
        }

        if (!ini_get('allow_url_fopen')) {
            return array('status' => 0, 'body' => '', 'error' => _t(
                'PHP 既没有 curl 扩展，也没开 allow_url_fopen，这一页连不上 GitHub。'
                . '请手动下载 zip 覆盖到 usr/themes/noline。'));
        }

        $headers = array(
            'Accept: ' . ($json ? 'application/vnd.github+json' : 'application/zip'),
            'User-Agent: NolineApi/updater',
        );
        $token = self::tokenFor($url);

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
        )));

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return array('status' => 0, 'body' => '', 'error' => _t('allow_url_fopen 也连不上 %s，检查出站网络和证书。', $url));
        }

        $status = 0;

        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return array('status' => $status, 'body' => $body, 'error' => '');
    }

    /**
     * token 只发给 api.github.com。
     *
     * 发给镜像等于把凭据递给第三方，而它本来也不认这个 token；镜像那几条请求全部按匿名处理。
     */
    private static function tokenFor(string $url): string
    {
        if (strtolower((string)parse_url($url, PHP_URL_HOST)) !== 'api.github.com') {
            return '';
        }

        return trim(Plugin::setting('ghToken', ''));
    }

    /* ======================================================================
       升级
       ====================================================================== */

    /**
     * 下载、验收、把旧主题和旧插件各整份挪去备份，再让新版本顶上。
     *
     * @param bool $auto 只影响历史里那一条怎么标，动作本身两种触发完全一样
     */
    private static function upgrade(bool $auto = false): array
    {
        $state = self::readState();

        if (!isset($state['check']) || !is_array($state['check'])) {
            return self::fail(_t('还没有一次成功的检测，先点「重新检测」。'));
        }

        $check = self::withRelation($state['check']);

        if (empty($check['ok'])) {
            return self::fail(_t('缓存里的检测结果不完整，重新检测一次。'));
        }

        if (empty($check['available'])) {
            return self::fail(_t('本机版本不低于 Release 里的 %s，不需要升级。', $check['tag']));
        }

        $block = self::blocking();

        if ($block !== '') {
            return self::fail($block);
        }

        $work = self::workDir();
        $backupRoot = self::backupDir();

        if ($work === null || $backupRoot === null) {
            return self::fail(_t('临时目录或备份目录建不出来，检查 usr/uploads 是否可写。'));
        }

        /* 附件地址按 repo + tag 现拼，所以这里要的仓库是检测当时那一份；
           换过仓库的话缓存早就被 check() 作废了，不会走到这儿 */
        $repo = $check['repo'] === '' ? self::repo() : $check['repo'];

        $stamp = date('Ymd-His');
        $from = self::installedVersion();
        $backup = $backupRoot . '/' . self::safe($from === '' ? 'unknown' : $from) . '-' . $stamp;
        $staging = $work . '/package-' . $stamp;
        $zip = $work . '/' . self::safe($check['tag']) . '-' . $stamp . '.zip';

        set_time_limit(0);
        ignore_user_abort(true);

        $fetched = self::download($repo, $check['tag'], $zip, (int)$check['size'], (string)$check['sha']);

        if (empty($fetched['ok'])) {
            return self::fail($fetched['error']);
        }

        $via = $fetched['via'] === 'github' ? _t('GitHub 直连') : $fetched['via'];

        $root = '';
        $packagePlugin = '';
        $error = self::extract($zip, $staging, $root, $packagePlugin);

        if ($error !== '') {
            self::rrmdir($staging);
            @unlink($zip);

            return self::fail($error);
        }

        $newVersion = self::versionFromIndex($root . '/index.php');

        if ($newVersion === '') {
            self::rrmdir($staging);
            @unlink($zip);

            return self::fail(_t('包里的 index.php 读不到 @version，这个包不能用来升级，已放弃。'));
        }

        if (self::normalize($newVersion) === self::normalize($from)) {
            self::rrmdir($staging);
            @unlink($zip);

            return self::fail(_t('包里的版本（%s）和本机一模一样。是 Release 重发过没换 tag 吗？已放弃。', $newVersion));
        }

        /* 包里带了插件才考虑换插件；本机没装、或者 usr/plugins 不让写，就只换主题，
           不去替人决定「要不要突然多出一个插件目录」，也别把能成的主题升级一起拖死 */
        $skipped = '';

        if ($packagePlugin !== '') {
            $livePlugin = self::pluginDir();

            if (!is_dir($livePlugin) || is_link($livePlugin)) {
                $skipped = _t('包里有插件，但本机没有可换的 NolineApi 目录，这一轮只换了主题。');
                $packagePlugin = '';
            } elseif (!is_writable(dirname($livePlugin))) {
                $skipped = _t('包里有插件，但 usr/plugins 不可写，插件换不动，这一轮只换了主题。'
                    . '插件还停在 %s，和主题的版本可能不匹配，去把目录权限修好再升级。',
                    self::installedPluginVersion() === '' ? _t('未知版本') : self::installedPluginVersion());
                $packagePlugin = '';
            }
        }

        $pluginFrom = $packagePlugin === '' ? '' : self::installedPluginVersion();
        $pluginTo = $packagePlugin === '' ? '' : self::versionFromPlugin($packagePlugin . '/Plugin.php');
        $swapped = self::swapAll(self::swapItems($root, $packagePlugin, $backup), array(
            'backup'      => $backup,
            'from'        => $from,
            'to'          => $newVersion,
            'plugin_from' => $pluginFrom,
            'plugin_to'   => $pluginTo,
        ));

        if (empty($swapped['ok'])) {
            /* 现场一点没动：挪走的、没挪走的、下载下来的都原样留着等下一次 repair() 收尾，
               页面里的「清掉升级残留」是给人在确认过之后再按的 */
            return self::fail($swapped['error']);
        }

        $plan = $swapped['plan'];

        /* 权威计数在 usr/uploads/noline_views/ 里，交换碰不到；
           这一步只补主题目录 views/ 下「本机有、新版本没有」的旧版兜底文件。 */
        $kept = self::mergeViews($backup . '/theme/views', $plan[0]['live'] . '/views');

        self::clearPending();
        self::rrmdir($staging);
        @unlink($zip);

        $state = self::readState();
        $history = isset($state['upgrades']) && is_array($state['upgrades']) ? $state['upgrades'] : array();
        $history[] = array(
            'from' => $from, 'to' => $newVersion, 'at' => date('c'),
            'backup' => $backup, 'kept_views' => $kept, 'via' => $fetched['via'],
            'plugin_from' => $pluginFrom, 'plugin_to' => $pluginTo,
        );
        $state['upgrades'] = array_slice($history, -20);
        self::saveState($state);

        $tagVersion = self::normalize($check['tag']);
        $mismatch = $tagVersion !== '' && self::normalize($newVersion) !== $tagVersion;

        return array('success', Admin::esc(_t(
            '已升级到 %1$s（原 %2$s），包从 %3$s 下的。旧主题和旧插件各留了一份整副本在 %4$s，随时能回滚。',
            $newVersion, $from === '' ? _t('未知') : $from, $via, $backup
        ))
            . ($pluginTo !== '' ? '<br>' . Admin::esc(_t(
                '插件也一起换了：%s → %s。它登记的钩子会在下一个页面（前台后台都算）自己补齐，'
                . '不用去插件页停用再启用。',
                $pluginFrom === '' ? _t('未知版本') : $pluginFrom, $pluginTo)) : '')
            . ($skipped !== '' ? '<br>' . Admin::esc($skipped) : '')
            . ($kept > 0 ? '<br>' . Admin::esc(_t('另外把本机独有的 %d 个 views/ 文件补进了新版本。', $kept)) : '')
            . ($mismatch ? '<br>' . Admin::esc(_t(
                '提醒：Release 的 tag 是 %s，包里的 @version 是 %s，两者对不上，回头确认一下发布。',
                $check['tag'], $newVersion)) : '')
            . self::trustNote($check));
    }

    /**
     * 一次交换涉及的两个地方：主题，以及（包里带了插件时）插件。
     * 备份目录里固定是 theme/ 和 plugin/ 两个子目录，repair 和回滚都按这个形状走。
     *
     * @return array 每项 ['label' => 文案, 'live' => 现网目录, 'backup' => 备份落点, 'staging' => 包里的新份]
     */
    private static function swapItems(string $root, string $pluginRoot, string $backup): array
    {
        $items = array(array(
            'label' => _t('主题'),
            'live' => self::themeDir(),
            'backup' => $backup . '/theme',
            'staging' => $root,
        ));

        if ($pluginRoot !== '') {
            $items[] = array(
                'label' => _t('插件'),
                'live' => self::pluginDir(),
                'backup' => $backup . '/plugin',
                'staging' => $pluginRoot,
            );
        }

        return $items;
    }

    /**
     * 成对交换：每一项先把 live 整份挪到 backup 位置，再把各自的 staging 顶回 live。
     *
     * 升级和回滚只是这两组路径从哪儿来——顺序、pending 记录、失败回退全共用一套。
     * 主题永远排第一，插件排第二，中途被打断时 repair() 也按这个顺序退回旧版本。
     *
     * @param array $plan 每项 ['label','live','backup','staging']
     * @param array $base 先写进 pending 的说明性字段（备份目录、版本号之类）
     * @return array ['ok' => bool, 'error' => string, 'plan' => array]
     */
    private static function swapAll(array $plan, array $base): array
    {
        $targets = array();
        $absent = array();

        foreach ($plan as $i => $item) {
            if (!self::isSwappable($item) || is_link($item['live'])) {
                continue;
            }

            $targets[] = $i;

            /* 现网目录本来就不在（比如上一次交换没走完就被打断）：没东西要挪走，
               但备份或解压出来的那一份照样能顶上去 */
            if (!is_dir($item['live'])) {
                $absent[] = $i;
            }
        }

        if ($targets === array()) {
            return array('ok' => false, 'plan' => $plan, 'error' => _t(
                '要交换的目录一个都不在站点自己的目录下（主题或插件目录被移走或改成了软链接），没有动手。'));
        }

        $pending = $base + array(
            'items'   => $plan,
            'targets' => $targets,
            'moved'   => array(),
            'placed'  => array(),
            'stage'   => 'prepare',
            'at'      => date('c'),
        );

        self::mark($pending);

        /* 每一项的落点必须还不存在：撞上上一次留下的同名目录，宁可不动也不要把两份副本混在一起 */
        foreach ($targets as $i) {
            $into = $plan[$i]['backup'];

            if (is_dir($into) || (!is_dir(dirname($into)) && !@mkdir(dirname($into), 0755, true))) {
                self::clearPending();

                return array('ok' => false, 'plan' => $plan, 'error' => _t(
                    '备份的落点 %s 用不了（已经存在，或者建不出来：%s），没有动手。', $into, self::phpError()));
            }
        }

        /* 整份挪走：一项一次 rename，不复制不删除，所以 views/ 下那些还没迁进
           usr/uploads 的历史计数文件一定还在原地，回退时也完整。 */
        foreach ($targets as $i) {
            if (in_array($i, $absent, true)) {
                continue;
            }

            if (@rename($plan[$i]['live'], $plan[$i]['backup'])) {
                $pending['moved'][] = $i;
                self::mark($pending);

                continue;
            }

            return self::swapFailed($pending, $i, _t('旧的%s挪不动', $plan[$i]['label']));
        }

        $pending['stage'] = 'moved';
        self::mark($pending);

        foreach ($targets as $i) {
            if (@rename($plan[$i]['staging'], $plan[$i]['live'])) {
                $pending['placed'][] = $i;
                self::mark($pending);

                continue;
            }

            return self::swapFailed($pending, $i, _t('新%s没能落到原位', $plan[$i]['label']));
        }

        $pending['stage'] = 'placed';
        self::mark($pending);

        return array('ok' => true, 'plan' => $plan, 'error' => '');
    }

    /**
     * 交换某一步失败：能全自动退回就退回并清标记，退不干净就把 pending 留着——
     * 留着才有下一次 repair() 重试的机会，清了就等于把半截现场丢给人自己收拾。
     */
    private static function swapFailed(array $pending, int $failedOn, string $what): array
    {
        $why = self::phpError();
        $aside = '';
        $undone = self::restorePending($pending, $aside);

        if ($undone) {
            self::clearPending();

            return array('ok' => false, 'plan' => $pending['items'], 'error' => _t(
                '%1$s：%2$s。前面已经搬过的都原样放回了，站点还是原来的样子。', $what, $why));
        }

        return array('ok' => false, 'plan' => $pending['items'], 'error' => _t(
            '%1$s：%2$s。剩下的没能自动放回原位，现场整个留着了：一份在 %3$s，'
            . '已经顶上原位的另一份暂存在 %4$s。再进一次这一页会自动重试，'
            . '重试还不行就手动把它们各自摆回 usr/themes 与 usr/plugins 下。',
            $what, $why, (string)$pending['backup'],
            $aside === '' ? self::rootDir() . self::WORK : $aside));
    }

    /** 把 pending 落到 state.json，每次调用前都重读一遍，免得盖掉镜像冷却之类的别的记录 */
    private static function mark(array $pending)
    {
        $state = self::readState();
        $state['pending'] = $pending;
        self::saveState($state);
    }

    private static function clearPending()
    {
        $state = self::readState();
        unset($state['pending']);
        self::saveState($state);
    }

    /**
     * pending 里的一条记录能不能动手：三个路径必须都还在站点自己名下的目录里。
     * state.json 被人手改过、或者目录搬过家，到这里就被挡下来。
     */
    private static function isSwappable(array $item): bool
    {
        if (!isset($item['live'], $item['backup'], $item['staging'])) {
            return false;
        }

        foreach (array('live', 'backup', 'staging') as $key) {
            if (!is_string($item[$key]) || $item[$key] === '') {
                return false;
            }
        }

        $root = self::rootDir();

        /* 新版本的来源有两个：升级时是解压出来的临时目录，回滚时是备份目录里那份旧副本 */
        return self::under($item['live'], array($root . '/usr/themes/', $root . __TYPECHO_PLUGIN_DIR__ . '/'))
            && self::under($item['backup'], array($root . self::BACKUP . '/'))
            && self::under($item['staging'], array($root . self::WORK . '/', $root . self::BACKUP . '/'));
    }

    /**
     * 路径是不是严格落在某个前缀目录里。前缀都以 / 结尾，所以「正好等于前缀」不算。
     */
    private static function under(string $path, array $prefixes): bool
    {
        if (strpos($path, '..') !== false) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if (strpos($path, $prefix) === 0 && strlen($path) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把 pending 里已经挪走的目录放回现网。中途被打断、以及某一步失败时都靠它收场。
     *
     * 已经落进去的新版不能就地盖掉——先把它挪去工作目录暂存，旧版才有位置回来。
     *
     * @param string|null $asideOut 新版暂存到了哪儿，供失败文案指给人看
     * @return bool 是否一个都不缺（true 表示站点回到了交换前的样子）
     */
    private static function restorePending(array $pending, &$asideOut = null): bool
    {
        $items = isset($pending['items']) && is_array($pending['items']) ? $pending['items'] : array();
        $moved = isset($pending['moved']) && is_array($pending['moved']) ? $pending['moved'] : array();
        $placed = isset($pending['placed']) && is_array($pending['placed']) ? $pending['placed'] : array();
        $clean = true;
        $asideOut = '';
        $aside = '';

        if (!empty($placed)) {
            $dir = self::workDir();

            if ($dir !== null) {
                $candidate = $dir . '/replaced-' . date('Ymd-His');

                if (@mkdir($candidate, 0755, true) || is_dir($candidate)) {
                    $aside = $candidate;
                    $asideOut = $candidate;
                }
            }
        }

        foreach (array_reverse($moved) as $i) {
            if (!isset($items[$i]) || !self::isSwappable($items[$i])) {
                $clean = false;

                continue;
            }

            /* 这一项已经换成新版了：新版先让位 */
            if (in_array($i, $placed, true) && is_dir($items[$i]['live'])) {
                $held = $aside === '' ? '' : $aside . '/' . basename($items[$i]['live']);

                if ($held === '' || !@rename($items[$i]['live'], $held)) {
                    $clean = false;

                    continue;
                }
            }

            if (!is_dir($items[$i]['live']) && !@rename($items[$i]['backup'], $items[$i]['live'])) {
                $clean = false;
            }
        }

        return $clean;
    }

    /**
     * 摘要不是 GitHub 直连给的时候，升级完了要补一句「这回没验到 GitHub 那份摘要」。
     * 手动升级允许在这种状态下发生（人自己看过 Release），但不能让它悄悄发生。
     *
     * @return string 已转义的 HTML 片段
     */
    private static function trustNote(array $check): string
    {
        if (!empty($check['sha_trusted'])) {
            return '';
        }

        return '<br>' . Admin::esc($check['sha'] === ''
            ? _t('提醒：这一轮没有可信的摘要可验，包只按字节数收了货，装完自己看一眼前台是否正常。')
            : _t('提醒：这次的摘要来自 %s，不是 api.github.com 直接给的。'
                . '校验只证明「字节和摘要一致」，而摘要和包是同一个出口一起回的。',
                $check['api_via'] === '' ? _t('未知出口') : $check['api_via']));
    }

    /**
     * 回滚：把备份里的主题（和插件）搬回原位，被顶掉的现行版本顺势存成一份新备份。
     *
     * 走的正是升级那套 swapAll：pending 记录、失败回退、中途打断后由 repair() 收尾都共用，
     * 只是每一项的来处换成了备份目录。
     */
    private static function rollback(string $name): array
    {
        $src = self::backupPath($name);

        if ($src === '') {
            return self::fail(_t('找不到这个备份，或者名字不合法。'));
        }

        $block = self::blocking();

        if ($block !== '') {
            return self::fail($block);
        }

        $backupRoot = self::backupDir();

        if ($backupRoot === null) {
            return self::fail(_t('备份目录不可写。'));
        }

        /* 新备份是 theme/ + plugin/ 两个子目录；1.22 之前的老备份是平铺的整份主题 */
        $themeSrc = '';

        foreach (array($src . '/theme', $src) as $candidate) {
            if (is_file($candidate . '/index.php')) {
                $themeSrc = $candidate;

                break;
            }
        }

        if ($themeSrc === '') {
            return self::fail(_t('这份备份里读不到主题的 index.php，不知道往哪儿放，回不去。'));
        }

        set_time_limit(0);
        ignore_user_abort(true);

        $current = self::installedVersion();
        $aside = $backupRoot . '/' . self::safe($current === '' ? 'unknown' : $current) . '-' . date('Ymd-His');

        if (!is_dir($aside) && !@mkdir($aside, 0755, true)) {
            return self::fail(_t('给现在的版本腾备份的位子建不出来：%s', self::phpError()));
        }

        $pairs = self::rollbackPairs($src, $themeSrc, $aside);
        $swapped = self::swapAll($pairs, array('backup' => $src, 'rollback' => $name));

        if (empty($swapped['ok'])) {
            /* 现场留着：挪出去的部分还在 aside 里，没挪的都在原位，pending 也已按策略决定去留 */
            return self::fail($swapped['error']);
        }

        self::clearPending();

        /* 现行版本一个目录都没挪出来（比如主题本来就不在），别留一份空备份在列表里碍事 */
        $stashed = self::treeSize($aside);
        @rmdir($aside);

        /* 手动回滚掉的那一版不能被自动更新当场顶回去：记下这个 tag，以后只有比它新的才自动上 */
        $state = self::readState();
        $seen = isset($state['check']) && is_array($state['check']) ? $state['check'] : array();
        $state['auto_floor'] = self::normalize(isset($seen['tag']) ? (string)$seen['tag'] : '');
        self::saveState($state);

        $back = self::installedVersion();
        /* 只有这份备份里确实带了插件副本，才说得上「插件也回去了」 */
        $pluginBack = self::rollbackHasPlugin($pairs) ? self::installedPluginVersion() : '';

        return array('success', Admin::esc(_t(
            '已回到 %1$s（用的是备份 %2$s）。%3$s',
            $back === '' ? $name : $back, $name,
            $stashed[0] > 0
                ? _t('被顶掉的那一版整份存成了新备份 %s。', basename($aside))
                : _t('刚才现网并没有可挪走的目录，所以这次没留下新备份。')
        ))
            . ($pluginBack !== '' ? '<br>' . Admin::esc(_t(
                '插件也跟着回到了 %s，它登记的钩子会在下一个页面自己补齐。', $pluginBack)) : '')
            . '<br>' . Admin::esc(_t('自动升级不会再把它顶上来了，除非 Release 里出了更新的版本。')));
    }

    /**
     * 一份备份能往回放到哪些地方：theme/ 一定有，plugin/ 有就一起放回去。
     *
     * @return array 每项 ['label','slot','live','backup','staging']
     */
    private static function rollbackPairs(string $src, string $themeSrc, string $aside): array
    {
        $pairs = array(array(
            'label'   => _t('主题'),
            'slot'    => 'theme',
            'live'    => self::themeDir(),
            'backup'  => $aside . '/theme',
            'staging' => $themeSrc,
        ));

        if (is_file($src . '/plugin/Plugin.php')) {
            $pairs[] = array(
                'label'   => _t('插件'),
                'slot'    => 'plugin',
                'live'    => self::pluginDir(),
                'backup'  => $aside . '/plugin',
                'staging' => $src . '/plugin',
            );
        }

        return $pairs;
    }

    /** 这一份备份里带没带插件副本：没带的话不能说「插件也回去了」 */
    private static function rollbackHasPlugin(array $pairs): bool
    {
        foreach ($pairs as $pair) {
            if (isset($pair['slot']) && $pair['slot'] === 'plugin') {
                return true;
            }
        }

        return false;
    }

    /* ======================================================================
       下载 / 解压 / 校验
       ====================================================================== */

    /**
     * 下载附件：GitHub 直链先试，不通再按设置里的顺序退镜像。
     *
     * 每一条候选都重新验字节数和摘要，验不过就删掉文件、把这条出口冷却起来再换下一条——
     * 镜像回一半 HTML 错误页、回个截断的包，在这儿都会被挡下来。
     * 地址一律自己按 repo + tag 拼，不用谁给的直链。
     *
     * @return array ['ok' => bool, 'via' => string, 'error' => string]
     */
    private static function download(string $repo, string $tag, string $dest, int $expectedSize, string $expectedSha): array
    {
        $candidates = array(array('github', self::assetUrl($repo, $tag), false));

        foreach (self::mirrors() as $mirror) {
            if ($mirror['cooling'] > 0) {
                continue;
            }

            $url = self::mirrorPlan($mirror['line'], $repo, $tag, 'download');

            if ($url === '') {
                continue;
            }

            $candidates[] = array($mirror['host'], $url, true);
        }

        $failures = array();

        foreach ($candidates as $candidate) {
            $http = self::http($candidate[1], 120, false);

            if ($http['error'] !== '') {
                self::hopFail($failures, $candidate[0], $http['error'], $candidate[2]);

                continue;
            }

            if ($http['status'] !== 200) {
                self::hopFail($failures, $candidate[0], _t('下载附件返回 HTTP %d。', $http['status']), $candidate[2]);

                continue;
            }

            if (@file_put_contents($dest, $http['body']) === false) {
                return array('ok' => false, 'via' => $candidate[0],
                    'error' => _t('写不进 %s。', $dest));
            }

            $error = self::verifyPackage($dest, $expectedSize, $expectedSha);

            if ($error !== '') {
                self::hopFail($failures, $candidate[0], $error, $candidate[2]);

                continue;
            }

            return array('ok' => true, 'via' => $candidate[0], 'error' => '');
        }

        return array('ok' => false, 'via' => '', 'error' => self::hopError($failures));
    }

    /**
     * 字节数和摘要两道验收。失败时文件已经被删掉，调用方只管换下一条出口。
     *
     * @return string 空串代表通过
     */
    private static function verifyPackage(string $dest, int $expectedSize, string $expectedSha): string
    {
        $actual = (int)@filesize($dest);

        if ($expectedSize > 0 && $actual !== $expectedSize) {
            @unlink($dest);

            return _t('下载不完整：期望 %1$d 字节，只拿到 %2$d 字节。', $expectedSize, $actual);
        }

        if ($expectedSha === '') {
            return '';
        }

        $position = strpos($expectedSha, ':');
        $algorithm = $position === false ? '' : substr($expectedSha, 0, $position);
        $wanted = $position === false ? $expectedSha : substr($expectedSha, $position + 1);

        if (!in_array($algorithm, array('sha256', 'sha1', 'md5'), true)) {
            @unlink($dest);

            return _t('摘要格式不认识（%s），为稳妥起见没有继续。', $expectedSha);
        }

        $got = @hash_file($algorithm, $dest);

        if ($got === false || !hash_equals(strtolower($wanted), $got)) {
            @unlink($dest);

            return _t('%s 摘要对不上：期望 %s，实际 %s。包在路上被改过，已删除。', $algorithm, $wanted, (string)$got);
        }

        return '';
    }

    /**
     * 解压并验收。包里有两层根，$root / $pluginRoot 分别带出主题和插件那一份：
     * build.py 打的是 noline/… + plugins/NolineApi/…，只有一层 noline/ 的老包也认
     * （那时 $pluginRoot 是空串，这一轮只换主题）。
     *
     * @return string 空串代表通过
     */
    private static function extract(string $zip, string $into, &$root, &$pluginRoot): string
    {
        if (!class_exists('ZipArchive')) {
            return _t('PHP 没有 zip 扩展，解不开包。');
        }

        $archive = new \ZipArchive();

        if ($archive->open($zip) !== true) {
            return _t('压缩包打不开，可能不是 zip 或者下载已经损坏。');
        }

        if ($archive->numFiles > self::MAX_ENTRIES) {
            $archive->close();

            return _t('包里条目超过 %d 个，不像一个主题包。', self::MAX_ENTRIES);
        }

        $uncompressed = 0;

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $entry = $archive->statIndex($i);

            if (!is_array($entry) || !isset($entry['name'])) {
                continue;
            }

            $name = (string)$entry['name'];

            /* 绝对路径、反斜杠、任何一层的 .. 都不许有：解压目标必须完全在临时目录里 */
            if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false
                || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                $archive->close();

                return _t('包里有非法路径「%s」，拒绝解压。', $name);
            }

            if (substr($name, -1) !== '/') {
                $uncompressed += isset($entry['size']) ? (int)$entry['size'] : 0;
            }
        }

        if ($uncompressed > self::MAX_UNCOMPRESSED) {
            $archive->close();

            return _t('包解压后要占 %d MB 以上，像压缩炸弹，拒绝解压。',
                (int)round(self::MAX_UNCOMPRESSED / 1048576));
        }

        if (!@mkdir($into, 0755, true) && !is_dir($into)) {
            $archive->close();

            return _t('临时目录建不出来：%s', $into);
        }

        $done = $archive->extractTo($into);
        $archive->close();

        if (!$done) {
            return _t('解压过程出错，磁盘可能满了。');
        }

        /* 解出来的东西里有软链就放弃：软链能把文件写到主题或插件目录外面去。
           整个解压目录都扫，不只看主题那一半——包里现在还有 plugins/ */
        foreach (self::walk($into) as $path) {
            if (is_link($path)) {
                return _t('包里有软链接（%s），不做交换。', $path);
            }
        }

        $root = '';

        foreach (array($into . '/' . Theme::DIR, $into) as $candidate) {
            if (self::looksLikeTheme($candidate)) {
                $root = $candidate;

                break;
            }
        }

        if ($root === '') {
            return _t('包里找不到主题的 index.php / functions.php / public/header.php。'
                . '顶层应该是一层 noline/ 目录，重新跑一次 _dev/build.py 再发布。');
        }

        /* 插件是包的第二个根。老包只有主题那一层，这种情况就只换主题 */
        $plugin = $into . '/' . self::PACKAGE_PLUGIN;

        if (is_dir($plugin) && !is_file($plugin . '/Plugin.php')) {
            return _t('包里的 %s 没有 Plugin.php，这个包不完整，已放弃。', self::PACKAGE_PLUGIN);
        }

        if ($root === $into && is_dir($plugin)) {
            return _t('这个包是平铺着打的（没有 noline/ 那一层），插件目录会和主题混在同一个根里，'
                . '没法分开交换。重新跑一次 _dev/build.py。');
        }

        $pluginRoot = is_dir($plugin) ? $plugin : '';

        return '';
    }

    private static function looksLikeTheme(string $dir): bool
    {
        return is_dir($dir) && is_file($dir . '/index.php')
            && is_file($dir . '/functions.php') && is_file($dir . '/public/header.php');
    }

    /**
     * 只补「备份里有、新版本没有」的 views 文件。同名文件以新版本为准。
     */
    private static function mergeViews(string $from, string $to): int
    {
        if (!is_dir($from) || !is_dir($to)) {
            return 0;
        }

        $kept = 0;

        foreach (self::walk($from) as $path) {
            if (is_link($path)) {
                continue;
            }

            $relative = substr($path, strlen($from) + 1);

            if ($relative === '' || file_exists($to . '/' . $relative)) {
                continue;
            }

            @mkdir($to . '/' . dirname($relative), 0755, true);

            if (@copy($path, $to . '/' . $relative)) {
                $kept++;
            }
        }

        return $kept;
    }

    /* ======================================================================
       中途被打断的收尾
       ====================================================================== */

    /**
     * 上次交换停在半路就按 pending 把现场收干净。进这一页第一件事就是它。
     *
     * 判断只看目录在不在，不猜进行到了第几步：要交换的每一项都换到位才算成，
     * 差一个就整套退回旧版本——一半新一半旧最容易在运行时炸。
     */
    public static function repair(): string
    {
        $state = self::readState();

        if (empty($state['pending']) || !is_array($state['pending'])) {
            return '';
        }

        $pending = $state['pending'];

        if (!isset($pending['items']) || !is_array($pending['items'])) {
            return self::repairLegacy($pending);
        }

        $items = $pending['items'];
        $targets = self::intList(isset($pending['targets']) ? $pending['targets'] : array());
        $placed = self::intList(isset($pending['placed']) ? $pending['placed'] : array());
        $moved = self::intList(isset($pending['moved']) ? $pending['moved'] : array());
        $backup = isset($pending['backup']) ? (string)$pending['backup'] : '';

        if ($moved === array()) {
            /* 一个目录都还没挪过，站点就是原来的样子 */
            self::clearPending();

            return '';
        }

        $done = $targets !== array();

        foreach ($targets as $i) {
            if (!in_array($i, $placed, true) || !isset($items[$i]) || !is_dir($items[$i]['live'])) {
                $done = false;

                break;
            }
        }

        if ($done) {
            self::clearPending();
            $state = self::readState();
            $state['repairs'][] = array('at' => date('c'), 'completed' => $backup);
            self::saveState($state);

            return _t('上次升级只差最后一条记录没写下，主题和插件其实都已经换到位了。标记清掉了，'
                . '旧版本整份留在 %s。', $backup);
        }

        $aside = '';
        $undone = self::restorePending($pending, $aside);
        $missing = array();

        foreach ($moved as $i) {
            if (isset($items[$i]) && self::isSwappable($items[$i]) && !is_dir($items[$i]['live'])) {
                $missing[] = $items[$i]['label'];
            }
        }

        if ($undone) {
            self::clearPending();
        }

        $state = self::readState();
        $state['repairs'][] = array(
            'at' => date('c'), 'restored' => $backup, 'clean' => $undone, 'missing' => $missing,
        );
        $state['repairs'] = array_slice($state['repairs'], -20);
        self::saveState($state);

        if ($undone) {
            return _t('检测到上次交换只完成了一部分，已全部退回旧版本（旧副本来自 %s），站点照旧。', $backup);
        }

        /* 退不干净就留着 pending：下次进这一页还会再试一遍，话也跟着一直挂在这儿 */
        return _t('检测到上次交换只完成了一部分，%s 到现在还没回到原位，标记留着下次再试。'
            . '待放的在 %s，已经顶上原位的新版本暂存在 %s，'
            . '自动重试还不行就手动把它们摆回 usr/themes 与 usr/plugins 下。',
            implode('、', $missing) === '' ? _t('有目录') : implode('、', $missing), $backup,
            $aside === '' ? self::rootDir() . self::WORK : $aside);
    }

    /**
     * 只带 theme 一项的老 pending（1.22 之前那套形状）单独收一次场。
     */
    private static function repairLegacy(array $pending): string
    {
        $theme = isset($pending['theme']) ? (string)$pending['theme'] : '';
        $backup = isset($pending['backup']) ? (string)$pending['backup'] : '';
        $root = self::rootDir();

        /* 只认我们自己名下的路径，state.json 被手动改过也动不到别处 */
        if ($theme === '' || strpos($theme, $root . '/usr/themes/') !== 0) {
            self::clearPending();

            return '';
        }

        if (is_dir($theme)) {
            self::clearPending();

            return _t('上次升级在收尾阶段被打断，主题目录已经是新版本上的了，标记清掉了。'
                . '再确认一次前台是否正常。');
        }

        if ($backup !== '' && is_dir($backup) && @rename($backup, $theme)) {
            self::clearPending();
            $state = self::readState();
            $state['repairs'][] = array('at' => date('c'), 'restored' => $backup);
            self::saveState($state);

            return _t('检测到上次升级中途停下、主题目录不见了。已把 %s 搬回原位，站点恢复。', $backup);
        }

        return _t('主题目录不见了，备份也找不回来（%s）。只能手动重新上传主题。', $backup);
    }

    /**
     * pending 里的下标列表都是 json 解码出来的，只收真正的整数下标。
     */
    private static function intList($values): array
    {
        $out = array();

        foreach ((array)$values as $value) {
            if (is_int($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * repair() 的文案要上页面，单独包一层转义。
     */
    public static function repairMessage(): string
    {
        $message = self::repair();

        return $message === '' ? '' : Admin::esc($message);
    }

    private static function cleanWork(): array
    {
        $dir = self::workDir();

        if ($dir === null) {
            return self::fail(_t('临时目录不可用。'));
        }

        $removed = 0;
        $held = 0;

        /* 半截交换还挂着 pending 的时候，暂存的那份新版本可能还要用来放回去，先别碰 */
        $state = self::readState();
        $paused = !empty($state['pending']);

        foreach (array_merge(
            (array)glob($dir . '/package-*'),
            (array)glob($dir . '/replaced-*')
        ) as $path) {
            if (!is_dir($path) || is_link($path)) {
                continue;
            }

            if ($paused && strpos(basename($path), 'replaced-') === 0) {
                $held++;

                continue;
            }

            $removed += self::rrmdir($path) ? 1 : 0;
        }

        foreach ((array)glob($dir . '/*.zip') as $path) {
            if (is_file($path) && !is_link($path) && @unlink($path)) {
                $removed++;
            }
        }

        return array('success', Admin::esc(_t('清掉了 %d 项残留。', $removed))
            . ($held > 0 ? '<br>' . Admin::esc(_t(
                '另有 %d 份暂存的新版本没动：上一次交换还没收尾，它可能还要用来放回去。', $held)) : ''));
    }

    /* ======================================================================
       环境闸门
       ====================================================================== */

    /**
     * @return array 每项 [级别 ok|warn|bad|muted, 文案]
     */
    private static function env(): array
    {
        $chips = array();
        $version = self::installedVersion();
        $active = trim((string)Options::alloc()->theme);
        $themeDir = self::themeDir();

        $chips[] = array('muted', _t('本机 %s', $version === '' ? _t('版本未知') : $version));
        $chips[] = array('muted', self::pluginPresent()
            ? _t('插件 %s', self::installedPluginVersion() === '' ? _t('版本未知') : self::installedPluginVersion())
            : _t('本机没装 NolineApi 插件'));
        $chips[] = array('muted', _t('仓库 %s', self::repo()));

        $net = extension_loaded('curl') ? 'curl' : (ini_get('allow_url_fopen') ? 'allow_url_fopen' : '');
        $chips[] = array($net === '' ? 'bad' : 'ok', $net === ''
            ? _t('没有 curl 也没开 allow_url_fopen')
            : _t('联网检测：%s', $net));

        $chips[] = array(class_exists('ZipArchive') ? 'ok' : 'bad',
            class_exists('ZipArchive') ? _t('zip 扩展可用') : _t('缺 zip 扩展，装不了'));

        $chips[] = array($active === Theme::DIR ? 'ok' : 'warn',
            $active === Theme::DIR ? _t('主题 noline 正在启用') : _t('当前启用的主题是 %s', $active));

        $chips[] = array(is_dir($themeDir) && !is_link($themeDir) ? 'ok' : 'bad',
            is_dir($themeDir)
                ? (is_link($themeDir) ? _t('主题目录是软链接，不敢动') : _t('主题目录正常'))
                : _t('主题目录不存在'));

        $parent = dirname($themeDir);
        $chips[] = array(is_dir($parent) && is_writable($parent) ? 'ok' : 'bad',
            is_dir($parent) && is_writable($parent) ? _t('usr/themes 可写') : _t('usr/themes 不可写，换不了目录'));

        $pluginParent = dirname(self::pluginDir());
        $writable = is_dir($pluginParent) && is_writable($pluginParent);
        $chips[] = array(!self::pluginPresent() ? 'muted' : ($writable ? 'ok' : 'warn'),
            !self::pluginPresent()
                ? _t('本机没有插件目录，升级只换主题')
                : ($writable ? _t('usr/plugins 可写，插件能一起换')
                    : _t('usr/plugins 不可写，这一轮只换主题，插件会留在原地')));

        $work = self::workDir();
        $chips[] = array($work === null ? 'bad' : 'ok',
            $work === null ? _t('usr/uploads 建不出临时目录') : _t('usr/uploads 可写'));

        $chips[] = array(self::sameDevice() ? 'ok' : 'bad', self::sameDevice()
            ? _t('themes 与 uploads 在同一设备，rename 可用')
            : _t('themes 与 uploads 不在同一设备，rename 会失败，只能手动更新'));

        $doc = self::versionFromIndex($themeDir . '/index.php');
        $code = self::versionFromFunctions($themeDir . '/functions.php');

        if ($doc !== '' && $code !== '' && $doc !== $code) {
            $chips[] = array('warn', _t('index.php 写 %s，functions.php 写 %s，两个版本号对不上', $doc, $code));
        }

        $mirrors = self::mirrors();
        $cooling = 0;

        foreach ($mirrors as $mirror) {
            if ($mirror['cooling'] > 0) {
                $cooling++;
            }
        }

        $chips[] = array($mirrors === array() ? 'muted' : ($cooling > 0 ? 'warn' : 'ok'),
            $mirrors === array()
                ? _t('没配镜像，只走 GitHub 直连')
                : _t('镜像 %d 条%s', count($mirrors),
                    $cooling > 0 ? _t('（%d 条在冷却）', $cooling) : ''));

        return $chips;
    }

    /**
     * 有 bad 就不许一键升级，并把第一条原因端出去。
     */
    private static function blocking(): string
    {
        foreach (self::env() as $item) {
            if ($item[0] === 'bad') {
                return _t('一键升级做不成：%s', $item[1]);
            }
        }

        return '';
    }

    private static function sameDevice(): bool
    {
        $theme = @stat(dirname(self::themeDir()));
        $uploads = @stat(self::baseUploads());

        return is_array($theme) && is_array($uploads) && $theme['dev'] === $uploads['dev'];
    }

    private static function repo(): string
    {
        $repo = trim(Plugin::setting('nolineRepo', self::DEFAULT_REPO));

        return $repo === '' ? self::DEFAULT_REPO : $repo;
    }

    /* ======================================================================
       镜像
       ====================================================================== */

    /**
     * 设置里的镜像，外加各自的冷却状态。检测、下载、页面三处共用同一份解析。
     *
     * 直连永远排在候选链最前面，所以把 GitHub 自己的域名填进镜像里没有任何意义，
     * 只会被当成重复项丢掉。
     *
     * @return array 每项 ['line' => 规整过的写法, 'host' => 域名, 'cooling' => 还要等多少秒]
     */
    private static function mirrors(): array
    {
        $raw = (string)Plugin::setting('updateMirrors', self::DEFAULT_MIRRORS);
        $cooldown = self::cooldowns();
        $out = array();
        $seen = array();

        foreach (preg_split('#[\r\n]+#', $raw) as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            /* 一行一条；中间还有空白，多半是把说明文字和地址粘在了一起，
               这种猜不出用户到底想试哪个域名，直接丢掉比拼出一个怪地址好 */
            if (preg_match('#\s#', $line)) {
                continue;
            }

            /* 只写域名的补上 https://，省得「gh-proxy.com」这种写法静默失效 */
            if (!preg_match('#^[a-zA-Z][a-zA-Z0-9.+-]*://#', $line)) {
                $line = 'https://' . ltrim($line, '/');
            }

            $host = strtolower((string)parse_url($line, PHP_URL_HOST));

            if ($host === '' || in_array($host, self::DIRECT_HOSTS, true)) {
                continue;
            }

            /* 前缀式写法末尾的斜杠统一去掉，拼地址时由 mirrorPlan() 补 */
            if (strpos($line, '{') === false) {
                $line = rtrim($line, '/');
            }

            if (isset($seen[$host])) {
                continue;
            }

            $seen[$host] = true;
            $out[] = array(
                'line' => $line,
                'host' => $host,
                'cooling' => isset($cooldown[$host]) ? max(0, $cooldown[$host] - time()) : 0,
            );

            if (count($out) >= self::MAX_MIRRORS) {
                break;
            }
        }

        return $out;
    }

    /**
     * 把一条镜像写法展开成某个用途的地址。
     *
     * 三种写法：含 `{api}` 的是检测模板；含 `{tag}` 或 `{asset}` 的是下载地址模板
     * （只镜像了包、没镜像 API 的自有 CDN 用这种）；都不含的当纯前缀，
     * 把整条 GitHub 地址拼在后面，检测和下载都能走。
     *
     * @param string $purpose check | download
     *
     * @return string 空串表示这条镜像干不了这活
     */
    private static function mirrorPlan(string $line, string $repo, string $tag, string $purpose): string
    {
        $isCheck = $purpose === 'check';

        if (strpos($line, '{api}') !== false) {
            return $isCheck ? str_replace('{api}', sprintf(self::API_URL, $repo), $line) : '';
        }

        if (strpos($line, '{tag}') !== false || strpos($line, '{asset}') !== false) {
            if ($isCheck || $tag === '') {
                return '';
            }

            return str_replace(
                array('{tag}', '{asset}'),
                array(rawurlencode($tag), self::ASSET),
                $line
            );
        }

        if ($isCheck) {
            return $line . '/' . sprintf(self::API_URL, $repo);
        }

        return $tag === '' ? '' : $line . '/' . self::assetUrl($repo, $tag);
    }

    /**
     * 附件直链一律自己按 repo + tag 拼。
     *
     * 镜像回的 JSON 里那个 browser_download_url 想指哪儿就指哪儿，
     * 拿它去下载等于让镜像决定我们装的是谁的字节。
     */
    private static function assetUrl(string $repo, string $tag): string
    {
        return sprintf(self::DOWNLOAD_URL, $repo, rawurlencode($tag));
    }

    /**
     * @return array 域名 => 冷却到什么时候（只回没过期的）
     */
    private static function cooldowns(): array
    {
        $state = self::readState();
        $raw = isset($state['mirror_cooldown']) && is_array($state['mirror_cooldown'])
            ? $state['mirror_cooldown'] : array();
        $now = time();
        $out = array();

        foreach ($raw as $host => $until) {
            $host = strtolower((string)$host);

            if ($host !== '' && (int)$until > $now) {
                $out[$host] = (int)$until;
            }
        }

        return $out;
    }

    /**
     * 把一条镜像关进冷却。每次动手前重读一遍 state.json 并顺手清掉过期的条目：
     * 这一轮里 check() 和 upgrade() 也要各自落结论，谁也不该把谁的记录冲掉。
     */
    private static function mirrorCool(string $host)
    {
        $host = strtolower($host);

        if ($host === '') {
            return;
        }

        $state = self::readState();
        $raw = isset($state['mirror_cooldown']) && is_array($state['mirror_cooldown'])
            ? $state['mirror_cooldown'] : array();
        $now = time();

        foreach ($raw as $key => $until) {
            if ((int)$until <= $now) {
                unset($raw[$key]);
            }
        }

        $raw[$host] = $now + self::MIRROR_WAIT;
        $state['mirror_cooldown'] = $raw;
        self::saveState($state);
    }

    /**
     * 试过却没试通的镜像逐条记名，好把「直连挂了」和「三条镜像全挂了」分开说。
     */
    private static function hopFail(array &$failures, string $label, string $why, bool $isMirror)
    {
        $failures[$label] = $why;

        if ($isMirror) {
            self::mirrorCool($label);
        }
    }

    /**
     * 候选链全空时的文案。只试过直连就原话直说，试过镜像才把每条的失败原因摊开。
     */
    private static function hopError(array $failures): string
    {
        if (count($failures) === 1) {
            return (string)reset($failures);
        }

        $listed = array();

        foreach ($failures as $label => $why) {
            $listed[] = $label . '：' . $why;
        }

        return _t('%d 条出口全都没走通（%s）。', count($listed), implode(' / ', $listed));
    }

    /**
     * 「检测走的是哪条出口」在页面上怎么说。缓存里的老结果没有这个字段，说「未知」而不是猜。
     */
    private static function viaText(array $check): string
    {
        if (!$check['ok'] || $check['api_via'] === '') {
            return _t('检测出口未知');
        }

        return $check['api_via'] === 'github'
            ? _t('检测出口：GitHub 直连')
            : _t('检测出口：镜像 %s', $check['api_via']);
    }

    private static function viaLevel(array $check): string
    {
        if (!$check['ok'] || $check['api_via'] === '') {
            return 'muted';
        }

        return $check['api_via'] === 'github' ? 'ok' : 'warn';
    }

    /**
     * 自动覆盖为什么这一轮没跑。摘要来路不对和压根没有摘要是两件事，分开说才有下一步可做。
     */
    private static function trustReason(array $check): string
    {
        $via = $check['api_via'] === '' ? _t('未知出口') : $check['api_via'];

        return $check['sha'] === ''
            ? _t('这次没拿到 GitHub 给的附件摘要（走的 %s），没有可信的验收依据，自动覆盖已跳过。'
                . '看过 Release 说明、确认要升的话，点下面的按钮即可。', $via)
            : _t('这次的摘要来自镜像 %s，不是 api.github.com 直接给的，自动覆盖已跳过。'
                . '看过 Release 说明、确认要升的话，点下面的按钮即可。', $via);
    }

    /**
     * 镜像这一段：每条能干什么、在不在冷却、上次为什么没通，顺手给出可直接点的下载入口。
     *
     * @return string 已转义的 HTML
     */
    private static function mirrorsHtml(array $check): string
    {
        $mirrors = self::mirrors();
        $repo = $check['repo'] === '' ? self::repo() : $check['repo'];
        $tag = $check['tag'];

        if (empty($mirrors)) {
            return '<p class="nl-note">' . Admin::esc(_t(
                '没有可用的镜像，只用 GitHub 直连。连不上 GitHub 的站点在插件设置「主题更新镜像」里填几条，'
                . '一行一条，按顺序退。')) . '</p>';
        }

        $rows = array();

        foreach ($mirrors as $mirror) {
            $canCheck = self::mirrorPlan($mirror['line'], $repo, '', 'check') !== '';
            $link = self::mirrorPlan($mirror['line'], $repo, $tag, 'download');
            $skills = array();

            if ($canCheck) {
                $skills[] = _t('检测');
            }

            if ($link !== '') {
                $skills[] = _t('下载');
            }

            $rows[] = '<tr><td><b>' . Admin::esc($mirror['host']) . '</b>'
                . '<em>' . Admin::esc($mirror['line']) . '</em></td>'
                . '<td>' . Admin::esc($skills === array()
                    ? _t('写法不认识，这一条等于没填')
                    : _t('可用：%s', implode('、', $skills))) . '</td>'
                . '<td>' . ($mirror['cooling'] > 0
                    ? Admin::esc(_t('冷却 %d 分钟', (int)ceil($mirror['cooling'] / 60)))
                    : Admin::esc(_t('可试'))) . '</td>'
                . '<td class="nl-ops">' . ($link !== ''
                    ? '<a class="btn btn-s" href="' . Admin::esc($link) . '" rel="noopener">'
                        . Admin::esc(_t('从这里下')) . '</a>'
                    : '') . '</td></tr>';
        }

        $html = '<details class="nl-log"><summary>'
            . Admin::esc(_t('镜像（%d 条，GitHub 直连优先，不通才退到这里）', count($mirrors)))
            . '</summary><table class="nl-list"><tbody>' . implode('', $rows) . '</tbody></table>';

        if ($check['ok'] && !empty($check['api_hops'])) {
            $listed = array();

            foreach ($check['api_hops'] as $label => $why) {
                $listed[] = $label . '：' . $why;
            }

            $html .= '<p class="nl-note">' . Admin::esc(_t(
                '直连和这些镜像都没通才走了成功的出口：%s。', implode(' / ', $listed))) . '</p>';
        }

        return $html . '</details>';
    }

    /* ======================================================================
       路径与状态
       ====================================================================== */

    private static function rootDir(): string
    {
        return rtrim((string)__TYPECHO_ROOT_DIR__, '/');
    }

    private static function themeDir(): string
    {
        return rtrim(Options::alloc()->themeFile(Theme::DIR), '/');
    }

    /**
     * 本机插件目录。核心也是这么拼的（Common.php 里 __TYPECHO_ROOT_DIR__ . __TYPECHO_PLUGIN_DIR__），
     * 跟着它走，别自己写死 /usr/plugins。
     */
    private static function pluginDir(): string
    {
        return rtrim(self::rootDir() . __TYPECHO_PLUGIN_DIR__, '/');
    }

    private static function baseUploads(): string
    {
        return self::rootDir() . '/usr/uploads';
    }

    /**
     * 工作目录。建不出来或不可写返回 null——此时只读，不下载也不交换。
     */
    private static function workDir()
    {
        return self::ensureDir(self::rootDir() . self::WORK);
    }

    /**
     * 备份根目录。同上，拿不到就是 null。
     */
    private static function backupDir()
    {
        return self::ensureDir(self::rootDir() . self::BACKUP);
    }

    private static function ensureDir(string $path)
    {
        static $done = array();

        if (array_key_exists($path, $done)) {
            return $done[$path];
        }

        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            $done[$path] = null;

            return null;
        }

        /* 里面会有整份主题副本和下载下来的 zip，能少一个入口就少一个 */
        if (!is_file($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', "Require all denied\n");
        }

        $done[$path] = $path;

        return $path;
    }

    private static function stateFile(): string
    {
        $dir = self::workDir();

        return $dir === null ? '' : $dir . '/state.json';
    }

    private static function readState(): array
    {
        $file = self::stateFile();

        if ($file === '' || !is_file($file)) {
            return array();
        }

        $data = json_decode((string)@file_get_contents($file), true);

        return is_array($data) ? $data : array();
    }

    private static function saveState(array $state): bool
    {
        $file = self::stateFile();

        if ($file === '') {
            return false;
        }

        return @file_put_contents(
            $file,
            json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        ) !== false;
    }

    /* ======================================================================
       版本
       ====================================================================== */

    /**
     * 本机主题版本。读 index.php 头部注释里的 @version——Typecho 的主题列表读的就是它，
     * 所以这才是「站点当前跑的版本」。
     */
    private static function installedVersion(): string
    {
        return self::versionFromIndex(self::themeDir() . '/index.php');
    }

    /**
     * 本机插件版本。后台插件列表读的是 Plugin.php 头部注释，和主题一个口径。
     */
    private static function installedPluginVersion(): string
    {
        return self::versionFromPlugin(self::pluginDir() . '/Plugin.php');
    }

    /** 插件目录在不在、是不是个普通目录（软链接不敢动） */
    private static function pluginPresent(): bool
    {
        return is_dir(self::pluginDir()) && !is_link(self::pluginDir());
    }

    private static function versionFromPlugin(string $file): string
    {
        $doc = self::versionFromIndex($file);

        if ($doc !== '') {
            return $doc;
        }

        /* 头注释漏了 @version 也别报「未知」：const VERSION 是同一个版本号的另一份抄本 */
        $head = (string)@file_get_contents($file);

        return preg_match("/const VERSION = '([^']+)'/", $head, $m) ? trim($m[1]) : '';
    }

    private static function versionFromIndex(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        try {
            /* 核心的解析器就是主题列表用的那一个，口径保持一致。
               这里必须写全 \\Typecho\\Plugin：本命名空间下也有一个同名的 Plugin 类。 */
            $info = \Typecho\Plugin::parseInfo($file);

            if (isset($info['version']) && trim((string)$info['version']) !== '') {
                return trim((string)$info['version']);
            }
        } catch (\Throwable $e) {
            // 下面退回正则
        }

        /* 4000 字节而不是整个文件：插件的头注释有几百个汉字，窗口太窄会读不到 @version，
           而这两个文件都是几 KB 量级，多读一点也不怕。 */
        $head = (string)@file_get_contents($file, false, null, 0, 4000);

        return preg_match('/@version\s+([^\s*]+)/', $head, $m) ? trim($m[1]) : '';
    }

    private static function versionFromFunctions(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }

        $head = (string)@file_get_contents($file, false, null, 0, 800);

        return preg_match("/NOLINE_VERSION',\s*'([^']+)'/", $head, $m) ? trim($m[1]) : '';
    }

    private static function normalize(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    private static function looksNumeric(string $version): bool
    {
        return (bool)preg_match('/^\d+(\.\d+)*([-._+][0-9A-Za-z.-]+)?$/', $version);
    }

    /* ======================================================================
       备份列表
       ====================================================================== */

    /**
     * @return array 备份目录名，新的在前
     */
    private static function backupNames(): array
    {
        $root = self::backupDir();

        if ($root === null) {
            return array();
        }

        $names = array();

        foreach ((array)@scandir($root, SCANDIR_SORT_DESCENDING) as $name) {
            if (!is_string($name) || $name[0] === '.') {
                continue;
            }

            if (is_dir($root . '/' . $name) && !is_link($root . '/' . $name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * 把用户传来的名字钉死在备份目录里面。空串表示这个名字不合法。
     */
    private static function backupPath(string $name): string
    {
        $name = trim($name);
        $root = self::backupDir();

        if ($root === null || $name === '' || $name !== basename($name)) {
            return '';
        }

        if (!in_array($name, self::backupNames(), true)) {
            return '';
        }

        return $root . '/' . $name;
    }

    private static function backupsHtml(string $token, string $url): string
    {
        $names = self::backupNames();
        $root = self::backupDir();

        if ($root === null) {
            return '';
        }

        if (empty($names)) {
            return '<p class="nl-note">' . Admin::esc(_t(
                '还没有备份。第一次升级之后，旧主题和旧插件会各留一份整副本在 %s 下，随时回滚。', self::BACKUP)) . '</p>';
        }

        $html = '<h4 class="nl-h4">' . Admin::esc(_t('备份（%d 份）', count($names))) . '</h4>'
            . '<table class="nl-list"><tbody>';

        foreach ($names as $name) {
            $stat = self::treeSize($root . '/' . $name);
            $withPlugin = is_file($root . '/' . $name . '/plugin/Plugin.php');

            $html .= '<tr><td><b>' . Admin::esc($name) . '</b>'
                . '<em>' . Admin::esc(_t('%1$d 个文件 · %2$s · %3$s', $stat[0], self::humanSize($stat[1]),
                    $withPlugin ? _t('含插件副本') : _t('只有主题'))) . '</em></td>'
                . '<td class="nl-ops">';

            foreach (array(
                'rollback' => _t('回滚到这一版'),
                'drop_backup' => _t('删除这份备份'),
            ) as $action => $label) {
                $ask = $action === 'drop_backup'
                    ? _t('删掉这份备份就没了，views/ 里的旧计数也一起没了。确定？')
                    : _t('把这一版搬回原位？现在的版本会被存成一份新备份。')
                        . ($withPlugin ? _t('这一份里有插件副本，插件会一起回去。') : '');

                $html .= '<form method="post" action="' . Admin::esc($url) . '"'
                    . ' data-ask="' . Admin::esc($ask) . '">'
                    . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">'
                    . '<input type="hidden" name="to" value="' . Admin::esc($name) . '">'
                    . '<button type="submit" class="btn btn-s' . ($action === 'drop_backup' ? ' btn-warn' : '')
                    . '" name="action" value="' . $action . '">' . Admin::esc($label) . '</button></form> ';
            }

            $html .= '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private static function leftoversHtml(string $token, string $url): string
    {
        $dir = self::workDir();

        if ($dir === null) {
            return '';
        }

        $leftovers = array_merge(
            (array)glob($dir . '/package-*'),
            (array)glob($dir . '/replaced-*'),
            (array)glob($dir . '/*.zip')
        );

        if (empty($leftovers)) {
            return '';
        }

        return '<form class="nl-inline" method="post" action="' . Admin::esc($url) . '"'
            . ' data-ask="' . Admin::esc(_t('删掉这些升级残留？不影响站点和备份。')) . '">'
            . '<input type="hidden" name="_" value="' . Admin::esc($token) . '">'
            . '<button type="submit" class="btn btn-s" name="action" value="clean_work">'
            . Admin::esc(_t('清掉 %d 项升级残留', count($leftovers))) . '</button> '
            . '<span class="nl-note">' . Admin::esc(_t('上次下载或解压没走完留下的东西。')) . '</span></form>';
    }

    private static function dropBackup(string $name): array
    {
        $path = self::backupPath($name);

        if ($path === '') {
            return self::fail(_t('找不到这个备份，或者名字不合法。'));
        }

        if (!self::rrmdir($path)) {
            return self::fail(_t('备份删不干净：%s', self::phpError()));
        }

        return array('success', Admin::esc(_t('已删除备份 %s。', $name)));
    }

    /* ======================================================================
       杂项
       ====================================================================== */

    private static function fail(string $message): array
    {
        return array('error', Admin::esc($message));
    }

    private static function phpError(): string
    {
        $last = error_get_last();

        return is_array($last) && isset($last['message']) ? (string)$last['message'] : _t('没有更多细节');
    }

    private static function safe(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        if ($clean === '' || $clean === '.' || $clean === '..') {
            return 'x';
        }

        return substr($clean, 0, 80);
    }

    private static function trimBody(string $body): string
    {
        $body = trim($body);

        return mb_strlen($body, 'UTF-8') > 4000 ? mb_substr($body, 0, 4000, 'UTF-8') . '…' : $body;
    }

    /**
     * 目录下的所有文件（含软链本身），返回绝对路径。目录自己不入表，也不跟软链往下走。
     *
     * @return array
     */
    private static function walk(string $dir): array
    {
        $out = array();
        $stack = array($dir);

        while (!empty($stack)) {
            $current = array_pop($stack);
            $handle = @opendir($current);

            if ($handle === false) {
                continue;
            }

            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $current . '/' . $entry;

                /* 软链只记录、不进入：遍历不能顺着它爬到目录外面去 */
                if (is_link($path)) {
                    $out[] = $path;
                    continue;
                }

                if (is_dir($path)) {
                    $stack[] = $path;
                    continue;
                }

                $out[] = $path;
            }

            closedir($handle);
        }

        return $out;
    }

    /**
     * @return array [文件数, 字节数]
     */
    private static function treeSize(string $dir): array
    {
        $count = 0;
        $bytes = 0;

        foreach (self::walk($dir) as $path) {
            if (is_link($path)) {
                continue;
            }

            $count++;
            $bytes += (int)@filesize($path);
        }

        return array($count, $bytes);
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return (int)max(1, round($bytes / 1024)) . ' KB';
    }

    /**
     * 递归删目录。只用在自己造的临时目录和备份上，入口前都已经验过名字合法性。
     */
    private static function rrmdir(string $dir): bool
    {
        $root = self::rootDir();

        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return false;
        }

        /* 只允许删 uploads 下我们自己的两个目录 */
        if (strpos($dir, $root . '/usr/uploads/noline_update') !== 0
            && strpos($dir, $root . '/usr/uploads/noline_backup') !== 0) {
            return false;
        }

        $stack = array($dir);

        while (!empty($stack)) {
            $current = array_pop($stack);
            $handle = @opendir($current);

            if ($handle === false) {
                continue;
            }

            $children = array();

            while (($entry = readdir($handle)) !== false) {
                if ($entry !== '.' && $entry !== '..') {
                    $children[] = $current . '/' . $entry;
                }
            }

            closedir($handle);

            foreach ($children as $child) {
                if (is_dir($child) && !is_link($child)) {
                    $stack[] = $child;
                    continue;
                }

                @unlink($child);
            }

            /* 这一层空了才删得掉；上面的 unlink 失败时这里会留痕 */
            @rmdir($current);
        }

        return !is_dir($dir);
    }
}
