<?php

namespace TypechoPlugin\NolineApi;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 买菜记账。
 *
 * 一条流水 = 哪天、买了什么、多少钱。分类是稳定的英文代码，文案交给主题和 App 自己翻，
 * 别让接口的返回跟着站点的语言环境变（和健康的 BMI 分档同一个规矩）。
 *
 * 数据单独存一个文件（usr/uploads/noline_api/bills.php），不和体重的 store.php 混：
 * 两边主键不一样——体重一天一条、记账一天好几条，混在一个文件里就得在同一份 JSON 里
 * 塞两种形状，读的人每次都要先判断这条是什么。
 *
 * 这个类自给自足（自己的文件、自己的锁、自己的 JSON 出口），只向外借 Plugin::setting()，
 * 和 Cloudreve.php 一个路子。
 */
class Bills
{
    /** 记录文件同样用 .php 后缀 + 头部守卫：直接访问只会拿到空白页 */
    private const FILE = '/usr/uploads/noline_api/bills.php';
    private const GUARD = "<?php if (!defined('__TYPECHO_ROOT_DIR__')) { exit; } ?>\n";

    /** 接口一次最多吐多少条。买菜一天三四条，1000 条够翻两三年 */
    private const MAX_LIMIT = 1000;

    /** 文件里最多留多少条。记账是钱的历史，宁多别少；5000 条约 700KB */
    private const KEEP = 5000;

    /** 单笔金额上限（元）。真买过这个数的东西不该记在这一页上 */
    private const MAX_AMOUNT = 100000.0;

    /** 分类代码 → 后台和文档里出现的中文名。键是接口值，值是给人看的 */
    private const KINDS = array(
        'veg' => '蔬菜',
        'fruit' => '水果',
        'meat' => '肉禽',
        'fish' => '水产',
        'egg' => '蛋奶',
        'grain' => '米面粮油',
        'seasoning' => '调料',
        'other' => '其他',
    );

    /* ======================================================================
       入口
       ====================================================================== */

    /**
     * 处理 bills / bills/records 两个路由。鉴权、CORS 都已经在 Plugin::onRequest 里做完了。
     */
    public static function handle(string $route, string $method)
    {
        if ($route === 'bills') {
            if ($method !== 'GET') {
                self::json(array('code' => 405, 'message' => _t('概览只支持 GET')), 405);
            }
            self::json(array('code' => 0, 'data' => self::summary()));
        }

        if ($route === 'bills/records') {
            if ($method === 'POST') {
                self::save();
            }
            if ($method === 'PUT') {
                self::replace();
            }
            if ($method === 'DELETE') {
                self::remove();
            }
            if ($method !== 'GET') {
                self::json(array('code' => 405, 'message' => _t('该端点支持 GET / POST / PUT / DELETE')), 405);
            }

            list($limit, $from, $to, $kind) = self::filters();
            $rows = self::records($limit, $from, $to, $kind);
            $amount = 0.0;
            foreach ($rows as $row) {
                $amount += (float)$row['amount'];
            }

            self::json(array('code' => 0, 'data' => array(
                'total' => count($rows),
                // 一次区间查询最想要的就是这个数：App 不用把明细拉完自己加
                'amount' => round($amount, 2),
                'records' => $rows,
            )));
        }

        self::json(array('code' => 404, 'message' => _t('接口不存在')), 404);
    }

    /**
     * 主题模板直接调这个，不用绕一圈走接口。
     */
    public static function summary(): array
    {
        $all = self::rows();
        $span = self::span();

        if ($all === array()) {
            return array(
                'count' => 0,
                'currency' => 'CNY',
                'first' => null,
                'last' => null,
                'kinds' => self::kindDict(),
                'kinds_month' => array(),
                'totals' => null,
                'week' => null, 'prev_week' => null,
                'month' => null, 'prev_month' => null,
                'year' => null,
                'months' => array(),
                'by_date' => array(),
                'recent' => array(),
                'span' => $span,
            );
        }

        $monthRows = self::slice($all, $span['month_start'], $span['month_end'], '');

        return array(
            'count' => count($all),
            'currency' => 'CNY',
            'first' => $all[0]['date'],
            'last' => $all[count($all) - 1]['date'],
            'kinds' => self::kindDict(),
            'totals' => self::aggregate($all, $all[0]['date'], $all[count($all) - 1]['date']),
            'week' => self::aggregate($all, $span['week_start'], $span['week_end']),
            'prev_week' => self::aggregate($all, $span['prev_week_start'], $span['prev_week_end']),
            'month' => self::aggregate($all, $span['month_start'], $span['month_end']),
            'prev_month' => self::aggregate($all, $span['prev_month_start'], $span['prev_month_end']),
            'year' => self::aggregate($all, $span['year_start'], $span['year_end']),
            'months' => self::months($all),
            'kinds_month' => self::byKind($monthRows),
            'by_date' => self::byDate(array_slice($all, -120), 12),
            'recent' => array_slice(array_reverse($all), 0, 30),
            'span' => $span,
        );
    }

    /**
     * 明细（按日期升序，同日按录入顺序）。limit>0 时取最近的 limit 条。
     */
    public static function records(int $limit = 0, string $from = '', string $to = '', string $kind = ''): array
    {
        $all = self::rows();
        $rows = array();

        foreach ($all as $row) {
            if ($from !== '' && $row['date'] < $from) {
                continue;
            }
            if ($to !== '' && $row['date'] > $to) {
                continue;
            }
            if ($kind !== '' && $row['kind'] !== $kind) {
                continue;
            }
            $rows[] = $row;
        }

        return $limit > 0 ? array_slice($rows, -$limit) : $rows;
    }

    /* ======================================================================
       写
       ====================================================================== */

    /**
     * 新增。body 可以是单条对象，也可以是 {"records":[…]} 批量。
     * 记账没有「同一天覆盖同一条」这种自然主键，所以永远只新增，改走 PUT。
     */
    private static function save()
    {
        $body = self::body();

        if ($body === null) {
            self::json(array('code' => 400, 'message' => _t('请求体不是合法 JSON')), 400);
        }

        $input = isset($body['records']) && is_array($body['records']) ? $body['records'] : array($body);
        $errors = array();
        $valid = array();

        foreach ($input as $item) {
            if (!is_array($item)) {
                continue;
            }

            $record = self::normalize($item);

            if ($record === null) {
                // 不过的那条把品名回给用户，批量补录时才知道是哪一行错了
                $errors[] = isset($item['title']) ? mb_substr((string)$item['title'], 0, 20) : _t('(无品名)');
                continue;
            }

            $valid[] = $record;
        }

        if ($valid === array()) {
            self::json(array(
                'code' => 400,
                'message' => _t('没有可用记录：品名和金额（0.01–100000 元）都得有，日期得是真实的 YYYY-MM-DD'),
                'data' => array('invalid' => $errors),
            ), 400);
        }

        $written = null;

        $total = self::mutate(function (array $existing) use ($valid, &$written) {
            $written = self::append($existing, $valid);
            return $existing;
        });

        if ($total === null) {
            self::json(array('code' => 500, 'message' => _t('数据目录不可写，检查 usr/uploads/noline_api')), 500);
        }

        self::json(array('code' => 0, 'data' => array(
            'saved' => count($written),
            'total' => $total,
            'ids' => $written,
            'invalid' => $errors,
        )));
    }

    /**
     * 按 id 改一条。body 里可以只带要改的键；date 改了会重新排进新的一天。
     */
    private static function replace()
    {
        $body = self::body();

        if ($body === null) {
            self::json(array('code' => 400, 'message' => _t('请求体不是合法 JSON')), 400);
        }

        $id = isset($body['id']) ? trim((string)$body['id']) : '';

        if ($id === '') {
            self::json(array('code' => 400, 'message' => _t('缺少 id（POST 返回体里的 ids[]）')), 400);
        }

        $current = null;
        $raw = self::read();

        foreach ($raw as $record) {
            if ($record['id'] === $id) {
                $current = $record;
            }
        }

        if ($current === null) {
            self::json(array('code' => 404, 'message' => _t('没有这条记录') . '：' . $id), 404);
        }

        // 没带的键沿用原值，再整体过一遍校验——半条脏记录比拒绝写入难查得多
        $merged = array_merge($current, array_intersect_key($body, array(
            'date' => 1, 'title' => 1, 'amount' => 1, 'kind' => 1, 'shop' => 1, 'note' => 1,
        )));
        $next = self::normalize($merged);

        if ($next === null) {
            self::json(array('code' => 400, 'message' => _t('改后的内容不合法')), 400);
        }

        $next['id'] = $id;
        $next['time'] = $current['time'];
        $next['updated'] = time();

        $total = self::mutate(function (array $existing) use ($id, $next) {
            $existing[$id] = $next;
            return $existing;
        });

        if ($total === null) {
            self::json(array('code' => 500, 'message' => _t('数据目录不可写')), 500);
        }

        self::json(array('code' => 0, 'data' => array('record' => $next, 'total' => $total)));
    }

    /**
     * 按 id 删一条。?id=20261014-001
     */
    private static function remove()
    {
        $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

        if ($id === '') {
            self::json(array('code' => 400, 'message' => _t('缺少参数 id')), 400);
        }

        $removed = false;
        $total = self::mutate(function (array $existing) use ($id, &$removed) {
            if (isset($existing[$id])) {
                unset($existing[$id]);
                $removed = true;
            }
            return $existing;
        });

        if ($total === null) {
            self::json(array('code' => 500, 'message' => _t('数据目录不可写')), 500);
        }
        if (!$removed) {
            self::json(array('code' => 404, 'message' => _t('没有这条记录')), 404);
        }

        self::json(array('code' => 0, 'data' => array('deleted' => $id, 'total' => $total)));
    }

    /**
     * 校验并整理一条。不合法返回 null。
     */
    private static function normalize(array $item)
    {
        $date = isset($item['date']) ? trim((string)$item['date']) : date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $parts = explode('-', $date);
        if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
            return null;
        }

        if (!isset($item['title'])) {
            return null;
        }
        $title = self::text((string)$item['title'], 60);
        if ($title === '') {
            return null;
        }

        if (!isset($item['amount']) || !is_numeric($item['amount'])) {
            return null;
        }
        $amount = round((float)$item['amount'], 2);
        if ($amount < 0.01 || $amount > self::MAX_AMOUNT) {
            return null;
        }

        $kind = isset($item['kind']) ? strtolower(trim((string)$item['kind'])) : 'other';
        if (!isset(self::KINDS[$kind])) {
            $kind = 'other';
        }

        $record = array(
            'date' => $date,
            'title' => $title,
            'amount' => $amount,
            'kind' => $kind,
            'shop' => isset($item['shop']) ? self::text((string)$item['shop'], 40) : '',
            'note' => isset($item['note']) ? self::text((string)$item['note'], 200) : '',
        );

        return $record;
    }

    /**
     * 去掉标签、把连续空白压成一个空格、截断。记账字段全是给人读的一行字，不留 HTML。
     */
    private static function text(string $value, int $limit): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($value)));

        return $clean === '' ? '' : mb_substr($clean, 0, $limit);
    }

    /**
     * 给新记录发 id 并塞进 $existing（引用传进去，mutate 的回调直接改它）。
     * id 形如 20261014-003：日期打头，字符串排序就是时间顺序，日志里也一眼看得懂。
     */
    private static function append(array &$existing, array $records): array
    {
        $written = array();

        foreach ($records as $record) {
            $prefix = str_replace('-', '', $record['date']);
            $seq = 1;

            foreach ($existing as $id => $row) {
                if (strpos((string)$id, $prefix . '-') === 0) {
                    $seq = max($seq, (int)substr((string)$id, strlen($prefix) + 1) + 1);
                }
            }

            // 同一天里并发补录时 003 可能已被占（比如另一条同秒写入），撞了就往后挪
            while (isset($existing[$prefix . '-' . sprintf('%03d', $seq)])) {
                $seq++;
            }

            $id = $prefix . '-' . sprintf('%03d', $seq);
            $record['id'] = $id;
            $record['time'] = time();
            $existing[$id] = $record;
            $written[] = $id;
        }

        return $written;
    }

    /* ======================================================================
       统计口径
       ====================================================================== */

    /**
     * 一周从周一算起，一个月按自然月。全部用 mktime 的中午做锚点：
     * 站点时区要是设成了有夏令时的地方，减 86400 秒会跳错一天，中午怎么挪都还在同一天。
     */
    private static function span(): array
    {
        $y = (int)date('Y');
        $m = (int)date('n');
        $d = (int)date('j');
        $n = (int)date('N');

        $today = mktime(12, 0, 0, $m, $d, $y);
        $weekStart = $today - ($n - 1) * 86400;
        $monthStart = mktime(12, 0, 0, $m, 1, $y);
        $pm = $m - 1;
        $py = $y;
        if ($pm < 1) {
            $pm = 12;
            $py--;
        }

        return array(
            'today' => date('Y-m-d', $today),
            'weekday' => $n,
            'week_start' => date('Y-m-d', $weekStart),
            'week_end' => date('Y-m-d', $weekStart + 6 * 86400),
            'prev_week_start' => date('Y-m-d', $weekStart - 7 * 86400),
            'prev_week_end' => date('Y-m-d', $weekStart - 86400),
            'month_start' => date('Y-m-d', $monthStart),
            'month_end' => date('Y-m-t', $monthStart),
            'prev_month_start' => date('Y-m-01', mktime(12, 0, 0, $pm, 1, $py)),
            'prev_month_end' => date('Y-m-t', mktime(12, 0, 0, $pm, 1, $py)),
            'year_start' => sprintf('%04d-01-01', $y),
            'year_end' => sprintf('%04d-12-31', $y),
            'month_days' => (int)date('t', $monthStart),
            'month_day_of' => $d,
        );
    }

    /**
     * 区间内求和。区间一天都没有时也照样返回全零——App 要区分「这个月没花钱」和
     * 「接口没数据」，靠的是 count 而不是字段缺不缺。
     */
    private static function aggregate(array $rows, string $from, string $to): array
    {
        $picked = self::slice($rows, $from, $to, '');
        $amount = 0.0;
        $days = array();
        $biggest = null;

        foreach ($picked as $row) {
            $amount += (float)$row['amount'];
            $days[$row['date']] = true;

            if ($biggest === null || (float)$row['amount'] > (float)$biggest['amount']) {
                $biggest = $row;
            }
        }

        $count = count($picked);
        $dayCount = count($days);

        return array(
            'start' => $from,
            'end' => $to,
            'amount' => round($amount, 2),
            'count' => $count,
            'days' => $dayCount,
            'avg_day' => $dayCount === 0 ? 0.0 : round($amount / $dayCount, 2),
            'avg_bill' => $count === 0 ? 0.0 : round($amount / $count, 2),
            'max' => $biggest === null ? null : array(
                'date' => $biggest['date'],
                'title' => $biggest['title'],
                'amount' => (float)$biggest['amount'],
            ),
        );
    }

    private static function slice(array $rows, string $from, string $to, string $kind): array
    {
        $out = array();

        foreach ($rows as $row) {
            if ($from !== '' && $row['date'] < $from) {
                continue;
            }
            if ($to !== '' && $row['date'] > $to) {
                continue;
            }
            if ($kind !== '' && $row['kind'] !== $kind) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * 最近 6 个自然月（含本月）的月度合计，从旧到新。
     * 月份是自己数出来的，不是从记录里 group 出来的——中间那个月一条没记也得出现，
     * 否则柱状图上「没消费」和「没记录」长得一样。
     */
    private static function months(array $rows): array
    {
        $buckets = array();
        $y = (int)date('Y');
        $m = (int)date('n');

        for ($i = 0; $i < 6; $i++) {
            $m--;
            if ($m < 1) {
                $m = 12;
                $y--;
            }
            $buckets[sprintf('%04d-%02d', $y, $m)] = 0.0;
        }
        $buckets[sprintf('%04d-%02d', (int)date('Y'), (int)date('n'))] = 0.0;
        ksort($buckets);

        $counted = array();
        foreach ($rows as $row) {
            $key = substr((string)$row['date'], 0, 7);
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key] += (float)$row['amount'];
            $counted[$key] = isset($counted[$key]) ? $counted[$key] + 1 : 1;
        }

        $out = array();
        foreach ($buckets as $month => $amount) {
            $out[] = array(
                'month' => $month,
                'amount' => round($amount, 2),
                'count' => isset($counted[$month]) ? $counted[$month] : 0,
            );
        }

        return $out;
    }

    /**
     * 分类占比（降序）。share 是 0–1 的小数，百分号由前端拼。
     */
    private static function byKind(array $rows): array
    {
        $sum = 0.0;
        $buckets = array();

        foreach ($rows as $row) {
            $kind = (string)$row['kind'];
            if (!isset($buckets[$kind])) {
                $buckets[$kind] = array('amount' => 0.0, 'count' => 0);
            }
            $buckets[$kind]['amount'] += (float)$row['amount'];
            $buckets[$kind]['count']++;
            $sum += (float)$row['amount'];
        }

        $out = array();
        foreach ($buckets as $kind => $bucket) {
            $out[] = array(
                'kind' => $kind,
                'amount' => round($bucket['amount'], 2),
                'count' => $bucket['count'],
                'share' => $sum <= 0 ? 0.0 : round($bucket['amount'] / $sum, 3),
            );
        }

        usort($out, function (array $a, array $b) {
            return $b['amount'] <=> $a['amount'];
        });

        return $out;
    }

    /**
     * 按天分组的流水（新的在前），一天一组，组里按录入顺序倒序。
     * 模板要的就是这个形状，省得在 PHP 模板里再套一层分组循环。
     */
    private static function byDate(array $rows, int $dayLimit): array
    {
        $groups = array();

        foreach (array_reverse($rows) as $row) {
            $date = (string)$row['date'];
            if (!isset($groups[$date])) {
                $groups[$date] = array('date' => $date, 'amount' => 0.0, 'items' => array());
            }
            $groups[$date]['amount'] += (float)$row['amount'];
            $groups[$date]['items'][] = $row;
        }

        foreach ($groups as $date => $group) {
            $groups[$date]['amount'] = round($group['amount'], 2);
            $groups[$date]['count'] = count($group['items']);
        }

        return array_slice(array_values($groups), 0, max(1, $dayLimit));
    }

    /**
     * 分类字典。接口把代码和中文名一起给出去，App 至少有个能看的东西；
     * 要做本地化就自己按 kind 映射，别猜。
     */
    public static function kindDict(): array
    {
        $out = array();

        foreach (self::KINDS as $kind => $label) {
            $out[] = array('kind' => $kind, 'label' => $label);
        }

        return $out;
    }

    /* ======================================================================
       存取
       ====================================================================== */

    /**
     * 读—改—写全程独占锁。返回改后的总条数，写不进去返回 null。
     * 锁文件和健康分开：两边写的是不同文件，抢同一把锁只会让 App 的批量补录排队。
     */
    private static function mutate(callable $callback)
    {
        $dir = self::dataDir();

        if ($dir === null) {
            return null;
        }

        $lock = @fopen($dir . '/bills.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return null;
        }

        $existing = self::readIndexed();
        $existing = $callback($existing);
        ksort($existing);
        $existing = array_slice($existing, -self::KEEP);
        $ok = self::put($existing);

        flock($lock, LOCK_UN);
        fclose($lock);

        return $ok ? count($existing) : null;
    }

    private static function put(array $records): bool
    {
        $dir = self::dataDir();

        if ($dir === null) {
            return false;
        }

        $payload = self::GUARD . json_encode(
            array('records' => array_values($records)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $file = $dir . '/bills.php';
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload) === false) {
            return false;
        }

        @chmod($tmp, 0644);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * 全部记录，按 id 升序（id 以日期打头，所以也就是时间顺序）。
     * 每次请求读一遍文件，不做进程内缓存——概览和明细在同一次请求里都会被模板调到。
     */
    private static function rows(): array
    {
        return array_values(self::readIndexed());
    }

    private static function readIndexed(): array
    {
        $out = array();

        foreach (self::read() as $index => $record) {
            // 老文件里万一有 id 丢了的手工记录，补一个稳定位置键，别让整份数据读不出来
            $id = isset($record['id']) && $record['id'] !== ''
                ? (string)$record['id']
                : sprintf('legacy-%06d', $index);
            $out[$id] = $record;
        }

        ksort($out);

        return $out;
    }

    /**
     * 文件里的原始记录数组（已按字段补齐默认值）。
     */
    private static function read(): array
    {
        $file = __TYPECHO_ROOT_DIR__ . self::FILE;

        if (!is_file($file)) {
            return array();
        }

        $raw = @file_get_contents($file, false, null, strlen(self::GUARD));
        $data = json_decode((string)$raw, true);

        if (!is_array($data) || !isset($data['records']) || !is_array($data['records'])) {
            return array();
        }

        $out = array();

        foreach ($data['records'] as $index => $record) {
            if (!is_array($record) || !isset($record['date'], $record['title'], $record['amount'])) {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$record['date'])) {
                continue;
            }

            $kind = isset($record['kind']) ? (string)$record['kind'] : 'other';

            $out[] = array(
                'id' => isset($record['id']) ? (string)$record['id'] : '',
                'date' => (string)$record['date'],
                'title' => (string)$record['title'],
                'amount' => round((float)$record['amount'], 2),
                'kind' => isset(self::KINDS[$kind]) ? $kind : 'other',
                'shop' => isset($record['shop']) ? (string)$record['shop'] : '',
                'note' => isset($record['note']) ? (string)$record['note'] : '',
                'time' => isset($record['time']) ? (int)$record['time'] : 0,
                'updated' => isset($record['updated']) ? (int)$record['updated'] : 0,
            );
        }

        return $out;
    }

    /**
     * 数据目录。不可创建或不可写时返回 null：读为空、写报错，不把警告打在页面上。
     */
    private static function dataDir()
    {
        static $dir = false;

        if ($dir !== false) {
            return $dir;
        }

        $path = dirname(__TYPECHO_ROOT_DIR__ . self::FILE);

        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            $dir = null;
            return $dir;
        }

        // 兜底封锁目录；nginx 那边靠 bills.php 的 PHP 守卫
        if (!is_file($path . '/.htaccess')) {
            @file_put_contents($path . '/.htaccess', "Require all denied\n");
        }

        $dir = $path;
        return $dir;
    }

    /* ======================================================================
       请求工具
       ====================================================================== */

    private static function filters(): array
    {
        $limit = isset($_GET['limit']) ? max(0, min((int)$_GET['limit'], self::MAX_LIMIT)) : 50;
        $kind = isset($_GET['kind']) ? strtolower(trim((string)$_GET['kind'])) : '';

        return array(
            $limit,
            self::dateParam('from'),
            self::dateParam('to'),
            isset(self::KINDS[$kind]) ? $kind : '',
        );
    }

    /**
     * 只认 YYYY-MM-DD。日期串直接参与文件里的比较，脏值一律当没传。
     */
    private static function dateParam(string $name): string
    {
        $value = isset($_GET[$name]) ? trim((string)$_GET[$name]) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function body()
    {
        $raw = file_get_contents('php://input');

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (strpos($raw, '{') !== 0) {
            parse_str($raw, $parsed);
            $raw = isset($parsed['payload']) ? (string)$parsed['payload'] : json_encode($parsed);
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private static function json(array $payload, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * 设置页那张接口一览要列记账的端点，路径在这儿给出去，不在 Plugin 里另抄一份。
     */
    public static function docRows(): array
    {
        return array(
            array('GET', '/api/bills', _t('记账概览：本周 / 本月 / 上月 / 累计 / 近 7 个月（含本月）/ 本月分类占比')),
            array('GET', '/api/bills/records?limit=50&from=&to=&kind=', _t('流水明细，limit 最大 1000，kind 见返回里的 kinds')),
            array('POST', '/api/bills/records', _t('记一笔，body 为 {"date":"2026-10-14","title":"番茄","amount":12.5,"kind":"veg","shop":"东门菜场"}，也收 {"records":[…]} 批量；返回 ids[]')),
            array('PUT', '/api/bills/records', _t('按 id 改一条，body 必须带 id，其余键只改传了的')),
            array('DELETE', '/api/bills/records?id=20261014-001', _t('按 id 删一条')),
        );
    }
}
