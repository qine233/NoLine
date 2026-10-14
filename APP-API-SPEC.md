# Noline 站点数据接口 · App 对接开发文档

> 面向对象：负责实现移动端 / 桌面端 App 的开发（或 AI 助手）。
> 后端**已经写完并在跑**，你的工作只有客户端。本文档是唯一的接口事实来源；
> 服务器端源码在 `plugins/NolineApi/`（Typecho 插件：`Plugin.php` 路由与端点、`Theme.php` 主题配置项、
> `Bills.php` 买菜记账、`Cloudreve.php` 图床代理、`Admin.php` 后台管理页、`Updater.php` 主题更新），
> 有冲突以源码为准。
> 接口版本：plugin 1.4.0，本文档对应 2026-10-14 的实现。
>
> **本文档现在覆盖两块数据**：
> - **体重**（§4.1–4.7）——一天一条，按天覆盖。
> - **买菜记账**（§4.8–4.12）——一天可以好几条，按 id 改删。两块共用同一套鉴权、包络、URL 形态和开关，
>   只是主键不一样，所以**写入语义完全不同**（记账没有「同日覆盖」这回事，见 §4.10）。
>
> **老客户端要注意的历史版本差异**：
> 1. `1.1.0`：`theme.showSidebarCats` 从字符串 `"1"/""` 改成**真正的布尔值** `true/false`；
>    同时新增 `PUT /api/theme`（§4.3，**高危**，务必先读那一节的安全说明）。
> 2. `1.4.0`：新增记账的 5 个端点（§4.8–4.12）。**对既有端点零影响**——体重、主题、ping 的
>    请求和响应一个字节都没动。看不到 `bills` 路由（回 404）就说明服务器还在 1.4.0 之前。

---

## 1. 这个 App 要做什么

用户在自己的 Typecho 博客上记两件事：**体重**和**买菜花了多少钱**。博客主题已经有对应的网页版
「健康记录」页和「买菜记账」页展示数据。App 是这两套数据的**录入口和移动看板**，需要覆盖：

1. 配置：填站点地址 + 密钥，一次配好，能自检。
2. 记录：新增 / 修改 / 删除某一天的体重（可选体脂、备注）。
3. 概览：当前体重、BMI 及分档、目标进度、7/30/90 天涨跌。
4. 趋势：体重折线（可直接用 `/api/health` 返回的 `series` 自己画）。
5. 离线可用：本地暂存，联网后补交（两边重放安全性不一样，见 §7.3 与 §7.8）。
6. 记账：记一笔（品名 + 金额 + 哪天，可选分类 / 哪儿买的 / 备注），改一笔，删一笔。
7. 记账看板：本周 / 本月 / 累计花了多少、跟上一期比涨跌、近 7 个月柱状、本月钱花在哪类。
8. （可选）跟着博客改门面：读 / 写主题的 14 项配置（头像、强调色、公告等），见 §4.2 与 §4.3。
   **默认可以完全不做**——这块能力风险高（拿到密钥就能改整站），只有用户明确要在 App 里改设置时才做。

**不做**：多用户、账号体系、运动/饮食/睡眠数据、收支预算、多币种。体重只有体重；
记账只有「一笔一笔的买菜开销」，`currency` 恒为 `CNY`，不要拿它做汇率换算。

---

## 2. 基本约定

| 项目 | 值 |
|---|---|
| Base URL | 用户站点根地址，如 `https://blog.example.com` |
| 路径前缀 | `/api/` |
| 鉴权 | 请求头 `X-Noline-Key: <密钥>`（除 `/api/ping` 外全部必需） |
| 请求体 | `application/json`（也接受表单编码，见 §5.5） |
| 响应体 | `application/json; charset=utf-8`，`Cache-Control: no-store` |
| 数字精度 | 体重 / 体脂 / BMI 一律保留 1 位小数；**金额一律 2 位小数**（服务器 `round(x, 2)` 后原样给） |
| 日期格式 | `YYYY-MM-DD` 字符串，**不带时区** |
| 单位 | 体重 kg、身高 cm、体脂百分比数值（`18.2` 表示 18.2%，不带 `%`）、金额**元**（`12.5` 就是 12 元 5 角，`currency` 恒为 `CNY`） |

### 2.1 三种路径写法（按可用性从高到低依次尝试）

```
https://site/api/health              # 伪静态开启（首选）
https://site/index.php/api/health    # 伪静态关闭
https://site/?noline_api=health      # 服务器抢了 /api，或连 index.php 前缀都不通时的兜底
```

`?noline_api=` 的值就是前缀后面的部分（`ping` / `theme` / `health` / `health/records` /
`bills` / `bills/records`），其它查询参数照常带（`?noline_api=health/records&limit=100`、
`?noline_api=bills/records&kind=veg`）。**带斜杠的两段式路径在三种形态下都支持**，
兜底形态里斜杠直接写进 query 值。

**客户端要求**：配置页做一次连通性探测，三种写法依次试 `GET /api/ping`，记下第一个通的形态，
后续请求都按那个形态拼 URL。不要只硬编码第一种。

### 2.2 CORS

只有网页版 App 需要。原生 App / 脚本不受同源限制。
若站点后台没把 `允许跨域来源` 填成你的 Origin，浏览器里跨域请求会直接被拒——这不是接口 bug，
需要用户在插件设置里填 `https://你的源` 或 `*`。

预检：服务器对已放行的 Origin 回 `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`
和 `Access-Control-Allow-Headers: X-Noline-Key, Content-Type`，`OPTIONS` 返回 204。

---

## 3. 统一响应包络

所有端点（含错误）都是同一个形状：

```json
{ "code": 0, "data": { } }
{ "code": 403, "message": "密钥不正确" }
```

- `code` = 0 表示成功，业务数据在 `data`。
- `code` ≠ 0 时没有 `data`（唯一例外：`400` 的写入错误会带 `data.invalid`，见 §5.5）。
- HTTP 状态码与 `code` **数值相同**，可以只看状态码，但 `message` 只有响应体里有。
- `message` 是**中文、面向人**的文案，可能随站点语言环境变化。**客户端不要匹配 `message` 字符串**，
  一律按 `code` / HTTP 状态码分支。

### 3.1 状态码全表

| code | 含义 | 客户端应该怎么处理 |
|---|---|---|
| 0 | 成功 | — |
| 400 | 请求体不合法 / 没有一条记录通过校验 / PUT 没带 `id` | 体重回显 `data.invalid` 里的**日期**，记账回显**品名**（见 §4.10） |
| 401 | 没带密钥 | 引导去 App 设置里填密钥 |
| 403 | 密钥不正确 **或** 写入未开启 | 见 §3.2，两者要区分 |
| 404 | 接口路径不存在（`/api/xxx`）/ 体重 DELETE 的日期没有记录 / 记账 PUT·DELETE 的 `id` 不存在 | 路径错 → 试下一种 URL 形态；删除 404 → 提示「今天没有记录」；记账 404 → 本地那条要清掉或重新拉（服务器已经没有它了） |
| 405 | 方法不对 | 客户端逻辑错，不该重试 |
| 503 | 接口总开关关闭 / 密钥没设置 | 提示「站点那边没开接口」；注意这**不是**用户 App 里配错了 |
| 500 | 服务器数据目录不可写 | 让用户去查 `usr/uploads/noline_api` 权限，重试无意义 |

### 3.2 403 有两种来源

`密钥不正确` 和 `写入未开启（站点是只读模式）` 都是 403，接口没有再细分。
**判别方法**：收到 403 时，客户端补发一次 `GET /api/health`（同一个密钥）：

- GET 也 403 → 密钥错。
- GET 成功、只有 POST/DELETE 403 → 站点是只读模式，提示用户去后台开「允许写入」。

### 3.3 重试策略

服务器**没有速率限制、没有 429、没有幂等键**。401/403/404/405/500 一律不要自动重试。
网络层失败（超时、连接中断）用指数退避，最多 3 次。

> ⚠️ **两块的幂等性不一样，别照抄**：体重的 POST 是「按天整体覆盖」，重放安全；
> **记账的 POST 永远只新增**，同一个 body 发两次就是两笔一样的开销（§4.10）。
> 记账的写入一旦超时，**不要直接重放**，先 `GET /api/bills/records?from=那天&to=那天` 看那笔进没进去
> （判据：品名 + 金额 + 分类都对上），进去了就当成功、把服务器返回的 `id` 认领回本地。

---

## 4. 端点清单

| 方法 | 路径 | 需要密钥 | 需要写入开关 | 作用 |
|---|---|---|---|---|
| GET | `/api/ping` | 否 | 否 | 连通性自检 |
| GET | `/api/theme` | 是 | 否 | 站点门面 + 主题全部配置 + 配色推导 |
| PUT | `/api/theme` | 是 | **是，另一个开关** | 写主题配置（§4.3，高危） |
| GET | `/api/health` | 是 | 否 | 概览（含全量 `series`） |
| GET | `/api/health/records` | 是 | 否 | 明细，可限量、可筛日期区间 |
| POST | `/api/health/records` | 是 | 是 | 写入单条或批量（同日覆盖） |
| DELETE | `/api/health/records?date=` | 是 | 是 | 删除某天 |
| GET | `/api/bills` | 是 | 否 | 记账概览（周 / 月 / 年 / 累计 / 近 7 个月 / 本月分类占比） |
| GET | `/api/bills/records` | 是 | 否 | 流水明细，可限量、可筛日期区间和分类，**顺带给区间合计** |
| POST | `/api/bills/records` | 是 | 是 | 记一笔或批量补记，**永远新增**，返回 `ids[]` |
| PUT | `/api/bills/records` | 是 | 是 | 按 `id` 改一条（body 必须带 `id`） |
| DELETE | `/api/bills/records?id=` | 是 | 是 | 按 `id` 删一条 |

「写入开关」在服务器后台有**两个独立的**：`数据写入（健康 / 记账）` 管两块的所有 POST/PUT/DELETE，
`接口写主题设置` 管 `PUT /api/theme`。它们可以一个开一个关，见 §6。
**健康和记账共用同一个写开关**——开了两边都能写，没有「只准记体重、不准记账」这种中间态。

---

### 4.1 GET /api/ping

不需要密钥。用来区分「路径不通」和「密钥不对」是两件事。

```json
{
  "code": 0,
  "data": {
    "name": "州州的 Blog",
    "url": "https://blog.example.com/",
    "theme": "noline",
    "plugin": "1.1.0",
    "time": "2026-10-12T04:31:07+08:00"
  }
}
```

- `plugin` 是接口版本，客户端可按它做兼容分支。**`1.1.0` 起 `theme.showSidebarCats` 才是布尔值**，
  更早的版本回 `"1"`/`""`；要同时兼容就先 `/api/ping` 一次，或者两种写法都能读。
- `time` 是服务器 ISO8601 时间（带时区偏移），可用来校准「今天」的日期，见 §7.5。

---

### 4.2 GET /api/theme

给 App 做「跟着博客换肤」用。不想要可以直接忽略。

```json
{
  "code": 0,
  "data": {
    "site": {
      "title": "州州的 Blog",
      "description": "记录一些做完的事",
      "url": "https://blog.example.com/",
      "theme": "noline",
      "lang": "zh_CN",
      "timezone": 8
    },
    "theme": {
      "logoCss": "https://blog.example.com/avatar.png",
      "logoName": "QINE",
      "logobg": "",
      "logobgcolor": "",
      "accentColor": "#4482e5",
      "diaryCategory": "日记",
      "navIcons": "",
      "notice": "",
      "showSidebarCats": true,
      "gtihubname": "zhouzhou",
      "gtihubsite": "https://github.com/…",
      "BliBliname": "",
      "BliBlisite": "",
      "logoFooter": ""
    },
    "accent": {
      "hex": "#4482e5",
      "rgb": "68, 130, 229",
      "luminance": 0.2353,
      "on": "#ffffff",
      "dark": "#8bb4f0"
    }
  }
}
```

- `data.theme` 就是主题后台「设置外观」里的**全部 14 项**，一项不落。键名是博客历史遗留的拼写
  （`gtihubname`、`BliBliname` 都是错的，`logoCss` 其实指头像、`logobg` 其实是座右铭、
  `logobgcolor` 其实是首页头图），**不要改名、不要猜语义**，改了就读不回已存的值。
- 除 `showSidebarCats` 外全部是**字符串**，没配的是 `""`（不是 null）。
- `showSidebarCats`（右栏是否出「分类」卡）是**布尔值** `true`/`false`。
  plugin 1.0.0 回的是 `"1"`/`""`，1.1.0 起才是布尔；老客户端如果按字符串读会拿到 `true`。
- 完整字段表（含义 / 类型 / 上限 / 校验）见 §4.3，读写共用那一张表。
- `accent.on`：把 `accent.hex` 当背景时该用的前景色（已按 WCAG 相对亮度算好，阈值 0.45）。
- `accent.dark`：深色模式下建议的提亮版本。
- `accent.hex` 永远合法（解析失败时服务器回落 `#4482e5`），客户端可以直接用。

---

### 4.3 PUT /api/theme —— 写主题设置（**高危，先读安全那一栏**）

把后台「设置外观」里的 14 项全部开放给接口。服务器后台有独立开关
`接口写主题设置`（默认开着）；关掉时本端点回 `403`，`GET /api/theme` 不受影响。

> 🔴 **这条能力的后果要说清楚**：
> - 拿到密钥 = 能改整站门面：头像、名字、强调色、**首页公告**、**页脚备案号**、社交链接。
> - `notice` 和 `logoFooter` 是 **原样入库、不清洗 HTML** 的（用户明确要求的取舍）。
>   主题会把它们直接输出到页面上，所以写进去的 `<script>` 会在**所有访客**的浏览器里执行。
>   这不是漏洞，是设计——**因此客户端必须把「谁能拿到密钥」当成「谁能改网站」来对待**：
>   1. 绝不在 App 里放「一键改公告」这种任何人点了就生效的入口；改动要有明确的二次确认。
>   2. 绝不把密钥写进日志、崩溃上报、ANR trace、剪贴板共享或任何第三方分析。
>   3. 如果 App 提供「模板/预设公告」功能，模板内容由**你自己**保证是可信的，不要拼接用户以外来源的 HTML。
>   4. 站点没上 HTTPS 时不要开放这个功能（密钥是明文头，明文站点等于裸奔）。

**请求**：body 是一个 JSON 对象，**只带要改的键**。

```json
{ "accentColor": "#e0662e", "logoName": "QINE" }
```

- 没带的键保持原值——服务器是「读全量 → 覆盖你给的键 → 整片写回」，少传不会清空。
- 传 `""` 或 `null` = **清空**该项（主题会回落到各自的默认表现，比如没公告就不出公告卡）。
- 传 `{}`（空对象）是合法的**空操作**，返回成功且 `saved` 为空数组。
- 一次可以改任意多项，**但没有事务之外的部分成功**：任何一项校验不过，**一项都不会写入**，
  `data.errors` 里给出每个失败键的原因。改对了再整批重发即可。

**成功响应**（`data` 与 `GET /api/theme` 同形，`theme` 是**刚写进去的新值**，`accent` 按新强调色重算）：

```json
{
  "code": 0,
  "data": {
    "saved": ["accentColor", "logoName"],
    "site": { "…": "同 §4.2" },
    "theme": { "accentColor": "#e0662e", "…": "其余 13 项原样" },
    "accent": { "hex": "#e0662e", "rgb": "224, 102, 46", "luminance": 0.2468, "on": "#ffffff", "dark": "#eb9d78" }
  }
}
```

**失败响应**（HTTP 400，`code` 也是 400）：

```json
{
  "code": 400,
  "message": "一项都没写入。data.errors 的键就是字段名，改对再整批重发。",
  "data": { "errors": { "accentColor": "颜色要写成 #abc 或 #aabbcc" } }
}
```

- 未知键：`errors` 里对应「不是本主题的配置项」。
- 只有 `persist` 失败（服务器当前启用的主题不是 `noline`，或 options 表写不进）时，
  错误键是 `"_"`（下划线，表示「不是某个字段的问题」）。
- 请求体不是 JSON 对象（漏了 `{}`、给成数组、空 body）→ `400 请求体不是合法 JSON 对象`，
  这属于客户端 bug，别重试。

**字段全表**（读写同一套；「校验」列是服务器实际执行的正则/规则，不满足就是 400）：

| 键 | 类型 | 上限 | 后台里的名字 | 作用 |
|---|---|---|---|---|
| `logoCss` | `string`（URL 或 `/` 开头） | 500 字 | 站点头像地址 | 顶栏头像、首页 masthead 头像与站点 favicon 都用它。 |
| `logoName` | `string` | 100 字 | 博主名字 | 显示在顶栏品牌和首页 masthead。 |
| `logobg` | `string` | 200 字 | 座右铭 | 首页 masthead 站名下方那行字，最多两行。 |
| `logobgcolor` | `string`（URL 或 `/` 开头） | 500 字 | 首页头图 | 铺在首页 masthead 当封面，上面自动压一层渐变罩。 |
| `accentColor` | `string`（`#abc`/`#aabbcc`） | 9 | 强调色 | 链接、按钮、hover、标签底色、代码块圆点光环都用它，例如 #4482e5。 |
| `diaryCategory` | `string` | 200 字 | 动态分类 | 命中这个分类的文章在首页渲染成微博式动态卡，不显示标题。 |
| `navIcons` | `string`（`slug:图标` 逗号分隔） | 500 字 | 导航图标 | 格式 slug:图标名，逗号分隔，例如 yanzhi:code,photos:image。 |
| `notice` | `string`（**HTML 原样存，不清洗**） | 4000 字 | 首页公告 | 显示在首页内容区顶部的公告卡，留空则整张卡不出现。 |
| `showSidebarCats` | `true` / `false` | — | 右栏分类卡 | 开启后在右栏显示「分类」卡。 |
| `gtihubname` | `string` | 100 字 | GitHub 显示名 | 写在顶栏 GitHub 按钮上，配了之后按钮会从方块长成药丸。 |
| `gtihubsite` | `string`（URL 或 `/` 开头） | 500 字 | GitHub 链接 | 你的 GitHub 主页。 |
| `BliBliname` | `string` | 100 字 | Bilibili 显示名 | 写在顶栏 Bilibili 按钮上，规则同 GitHub 显示名。 |
| `BliBlisite` | `string`（URL 或 `/` 开头） | 500 字 | Bilibili 链接 | 你的 Bilibili 主页。 |
| `logoFooter` | `string`（**HTML 原样存，不清洗**） | 2000 字 | 站点底部版权 | 显示在右栏「站点信息」卡底部，可填 ICP 备案号。 |

**通用清洗顺序**（所有字段都走这三步，然后才是各自的类型校验）：
1. 去掉控制字符（`\x00-\x08`、`\x0B`、`\x0C`、`\x0E-\x1F`、`\x7F`）；**换行和制表保留**，
   因为 `notice` / `logoFooter` 这类富文本需要多行。
2. 去首尾空白。
3. 按 **UTF-8 字符数**（不是字节）限长，超了报「超过 N 个字符（当前 M 个）」。

**取值写法上的自由度**：
- `hex` 可以省 `#`（`4482e5` 也收），也认 3 位短写；服务器**不会**替你补 `#`，原样存。
  `accent.hex` 那一段会补全，所以读接口返回值更省事。
- `bool` 认 `true`/`false`，也认 `1`/`0`/`"1"`/`"0"`/`"on"`/`""`；其它值报「开关只能是 true 或 false」。
- `url` 只认 `http://`、`https://` 或站内 `/` 开头；含空格、引号、尖括号、反斜杠一律拒。
- `csv` 每一项必须是 `slug:图标名`（两边只允许字母数字 `_` `-`），整串长度受 500 限制。

**生效时机**：写完后前台**下一次请求**就是新值，不用清缓存、不用重新登录。
接口的 `GET /api/theme` 也没有缓存，立刻能读到。

**方法名**：只接受 `PUT`。`POST`/`PATCH` 一律 `405`，消息里会告诉你支持哪些方法。

---

### 4.4 GET /api/health

概览。**首屏只需要这一个请求**就能把数字全画出来。

有数据时：

```json
{
  "code": 0,
  "data": {
    "profile": {
      "height": 175.0,
      "gender": "male",
      "target_weight": 65.0,
      "ideal": { "low": 67.5, "high": 72.5 },
      "bmi_ranges": { "thin": 18.5, "normal": 24.0, "obese": 28.0 }
    },
    "count": 18,
    "first": "2026-05-06",
    "last": "2026-10-12",
    "latest": {
      "date": "2026-10-12",
      "weight": 66.0,
      "delta": -1.0,
      "bmi": 21.5,
      "category": "normal",
      "fat": 18.4,
      "note": "还差 1 公斤"
    },
    "stats": { "min": 66.0, "max": 74.0, "avg": 69.6 },
    "series": [
      { "date": "2026-05-06", "weight": 74.0, "delta": null, "bmi": 24.2, "category": "over", "fat": null, "note": "开始记录" },
      { "date": "2026-05-13", "weight": 73.4, "delta": -0.6, "bmi": 24.0, "category": "normal", "fat": null, "note": "" }
    ],
    "goal": { "target": 65.0, "remaining": 1.0, "done": false },
    "changes": {
      "d7":  { "span": 7,  "from_date": "2026-10-05", "delta": -1.0 },
      "d30": { "span": 30, "from_date": "2026-09-20", "delta": -1.4 },
      "d90": { "span": 90, "from_date": "2026-08-13", "delta": -2.6 }
    }
  }
}
```

一条记录都没有时（**不是错误，是 code 0**）：

```json
{
  "code": 0,
  "data": {
    "profile": { "height": null, "gender": "male", "target_weight": null, "ideal": null, "bmi_ranges": { "thin": 18.5, "normal": 24.0, "obese": 28.0 } },
    "count": 0,
    "first": null,
    "last": null,
    "latest": null,
    "stats": null,
    "series": [],
    "goal": null,
    "changes": {}
  }
}
```

**客户端必须处理**：`latest`、`stats`、`goal`、`profile.height`、`profile.ideal`、
`changes.d7.from_date/delta` 都可能是 `null`；`series` 可能为空数组；`changes` 可能是空对象。
不要假设有数据。

字段详解见 §6。

---

### 4.5 GET /api/health/records

明细。`series`（§4.4）和这里的元素结构完全一样。

| 查询参数 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `limit` | int | 30 | 取**区间内最后** N 条。上限 366，超过自动截到 366，负数按 0 处理（0 = 不限） |
| `from` | `YYYY-MM-DD` | 空 | 起始日期（含）。格式不对**等于没传**，不报错 |
| `to` | `YYYY-MM-DD` | 空 | 结束日期（含）。同上 |

```json
{ "code": 0, "data": { "total": 12, "records": [ … ] } }
```

- 结果**按日期升序**；`total` = 本次返回的条数（已筛选、已限量）。
- ⚠️ `delta` 是相对**全序列里上一条**的差值，不是相对筛选窗口内的上一条。
  也就是说 `?from=2026-09-01` 拿到的第一条，`delta` 可能是 8 月那条与它的差。
  需要「窗口内环比」时客户端自己重算，别改服务器。
- ⚠️ `limit` 是「尾部 N 条」。想要连续历史请按 `from/to` 分段取，接口**没有 cursor/offset 分页**。

---

### 4.6 POST /api/health/records

写入。单条或批量都行。

**单条**：

```json
{ "date": "2026-10-12", "weight": 66.0, "fat": 18.4, "note": "晨起空腹" }
```

**批量**：

```json
{ "records": [
  { "date": "2026-10-10", "weight": 66.8 },
  { "date": "2026-10-11", "weight": 66.4, "fat": 18.6 }
] }
```

成功：

```json
{ "code": 0, "data": { "saved": 2, "total": 18, "invalid": ["2026-02-30"] } }
```

- `saved` = 本次实际写入的条数；`total` = **服务器上现有记录总条数**（不是本次的）。
- `invalid` = 被拒的日期列表。**部分成功也是 200/code 0**：只要有一条通过就保存，其余进 `invalid`。
- 全部被拒时是 `400`，带 `data.invalid`：

```json
{ "code": 400, "message": "没有可用记录", "data": { "invalid": ["2026-02-30", ""] } }
```

- 请求体不是合法 JSON / 为空 → `400 请求体不是合法 JSON`。
- `records` 数组里非对象元素会被**静默跳过**（不进 `invalid`，也不报错），客户端别指望它兜底。

**校验规则（服务器行为，逐条）**：

| 字段 | 必填 | 规则 | 不合法时 |
|---|---|---|---|
| `date` | 是 | `YYYY-MM-DD` 且是**真实存在的日期**（`2026-02-30` 会被拒） | **整条被拒**，进 `invalid` |
| `weight` | 是 | 数值，四舍五入到 1 位，必须 `20 ≤ w ≤ 400` | **整条被拒**，进 `invalid` |
| `fat` | 否 | 数值，1 位小数，`1 ≤ f ≤ 70` | **只丢这个字段**，记录照常保存，不报错 |
| `note` | 否 | 去 HTML 标签、空白折叠成单空格、截到 200 字 | 空字符串会被当成「没有备注」 |

> ⚠️ **最容易踩的一条**：写入是**整天覆盖**（`existing[date] = record`），不是字段合并。
> 只 POST `{date, weight}` 会把那天原有的 `fat` 和 `note` **抹掉**。
> App 里「改今天的体重」必须先读原记录、带上 `fat`/`note` 一起提交，或者在本地维护完整对象再上传。

也接受表单编码（`application/x-www-form-urlencoded` / `multipart`）：
要么把整个 JSON 串放在 `payload` 字段里，要么直接用同名平铺字段（`date=2026-10-12&weight=66.0`）。
原生 App 建议直接用 JSON。

---

### 4.7 DELETE /api/health/records?date=YYYY-MM-DD

```json
{ "code": 0, "data": { "deleted": "2026-10-12", "total": 17 } }
```

- `date` 必填且格式合法，否则 `400 缺少参数 date（YYYY-MM-DD）`。
- 那天没有记录 → `404 该日期没有记录`（幂等性上等于「已删除」，客户端可以吞掉这个 404 当成功）。
- 重复删除同一天第二次就是 404，别当成失败轰炸重试。

---

### 4.8 GET /api/bills

记账概览。**首屏同样一个请求就够**：本周 / 本月 / 累计三个数、跟上期的涨跌、近 7 个月（含本月）的柱子、
本月分类占比、最近 12 天的流水，全在里面。

有数据时（`data` 的键顺序即下面这样，值都保留 2 位小数）：

```json
{
  "code": 0,
  "data": {
    "count": 132,
    "currency": "CNY",
    "first": "2026-05-06",
    "last": "2026-10-14",
    "kinds": [
      { "kind": "veg", "label": "蔬菜" },
      { "kind": "fruit", "label": "水果" },
      { "kind": "meat", "label": "肉禽" },
      { "kind": "fish", "label": "水产" },
      { "kind": "egg", "label": "蛋奶" },
      { "kind": "grain", "label": "米面粮油" },
      { "kind": "seasoning", "label": "调料" },
      { "kind": "other", "label": "其他" }
    ],
    "totals": { "start": "2026-05-06", "end": "2026-10-14", "amount": 1671.5, "count": 132,
                "days": 88, "avg_day": 19.0, "avg_bill": 12.66,
                "max": { "date": "2026-10-07", "title": "食用油", "amount": 65.9 } },
    "week":       { "start": "2026-10-12", "end": "2026-10-18", "amount": 38.3, "count": 3,
                    "days": 2, "avg_day": 19.15, "avg_bill": 12.77,
                    "max": { "date": "2026-10-12", "title": "排骨", "amount": 26.8 } },
    "prev_week":  { "start": "2026-10-05", "end": "2026-10-11", "amount": 94.1, "count": 8, "…": "…" },
    "month":      { "start": "2026-10-01", "end": "2026-10-31", "amount": 193.8, "count": 14, "…": "…" },
    "prev_month": { "start": "2026-09-01", "end": "2026-09-30", "amount": 332.3, "count": 24, "…": "…" },
    "year":       { "start": "2026-01-01", "end": "2026-12-31", "amount": 1671.5, "count": 132, "…": "…" },
    "months": [
      { "month": "2026-04", "amount": 0, "count": 0 },
      { "month": "2026-05", "amount": 336.2, "count": 22 },
      { "month": "2026-06", "amount": 0, "count": 0 },
      { "month": "2026-07", "amount": 355.7, "count": 25 },
      { "month": "2026-08", "amount": 453.5, "count": 27 },
      { "month": "2026-09", "amount": 332.3, "count": 24 },
      { "month": "2026-10", "amount": 193.8, "count": 14 }
    ],
    "kinds_month": [
      { "kind": "meat",  "amount": 76.2, "count": 3, "share": 0.393 },
      { "kind": "grain", "amount": 65.9, "count": 1, "share": 0.34 }
    ],
    "by_date": [
      { "date": "2026-10-14", "amount": 11.5, "count": 2,
        "items": [ { "id": "20261014-002", "date": "2026-10-14", "title": "花蛤", "amount": 8.8,
                     "kind": "fish", "shop": "东门菜场", "note": "",
                     "time": 1760428800, "updated": 0 }, "…" ] },
      "…"
    ],
    "recent": [ { "id": "20261014-002", "…": "…" }, "…" ],
    "span": {
      "today": "2026-10-14", "weekday": 3,
      "week_start": "2026-10-12", "week_end": "2026-10-18",
      "prev_week_start": "2026-10-05", "prev_week_end": "2026-10-11",
      "month_start": "2026-10-01", "month_end": "2026-10-31",
      "prev_month_start": "2026-09-01", "prev_month_end": "2026-09-30",
      "year_start": "2026-01-01", "year_end": "2026-12-31",
      "month_days": 31, "month_day_of": 14
    }
  }
}
```

一条记录都没有时（**不是错误，是 code 0**）：

```json
{
  "code": 0,
  "data": {
    "count": 0, "currency": "CNY", "first": null, "last": null,
    "kinds": [ { "kind": "veg", "label": "蔬菜" }, "… 8 项全在 …" ],
    "kinds_month": [], "totals": null,
    "week": null, "prev_week": null, "month": null, "prev_month": null, "year": null,
    "months": [], "by_date": [], "recent": [],
    "span": { "…": "时间区间照样给全，客户端可以据此画一个空框架" }
  }
}
```

**客户端必须处理**：`totals` / `week` / `prev_week` / `month` / `prev_month` / `year`
**整个对象可能是 `null`**（一条记录都没有时），不是「对象里的金额是 0」；
`months` / `by_date` / `recent` / `kinds_month` 可能是空数组；每个聚合对象里的 `max` 也可能是 `null`。
`kinds`（分类字典）**永远是全的 8 项**，包括一条都没用过的分类——画分类选择器用它，别用 `kinds_month`。

涨跌怎么算：**服务器不做差**，`week` 和 `prev_week` 是两个独立区间，
`本期 - 上期` 由客户端自己减（网页版就是 `noline_bills_delta()` 干的）。
上期是 `null` 或 `count == 0` 时显示 `—`，**不要显示成「省了本期全部」**。

---

### 4.9 GET /api/bills/records

明细。数组元素和 §4.8 里 `by_date[].items[]` / `recent[]` 完全同形（就是流水对象，见 §5.6）。

| 查询参数 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `limit` | int | 50 | 取**区间内最后** N 条。上限 1000，超过自动截到 1000；`0` = 不限（全给） |
| `from` | `YYYY-MM-DD` | 空 | 起始日期（含）。格式不对**等于没传**，不报错 |
| `to` | `YYYY-MM-DD` | 空 | 结束日期（含）。同上 |
| `kind` | string | 空 | 只要某个分类（`veg`/`fruit`/…，见 §5.7）。**不认识的代码等于没传**，不报错 |

```json
{ "code": 0, "data": { "total": 12, "amount": 168.4, "records": [ … ] } }
```

- 结果**按日期升序**（同日按当天录入顺序）；`total` = 本次返回的条数（已筛选、已限量）。
- `amount` = **本次返回这 N 条的合计**。这是记账端点和健康端点最大的区别：
  App 要做「这个月花了多少」「这周肉禽花了多少」，直接 `?from=&to=&kind=` 拉一次看 `amount` 就行，
  **不用把明细拉完自己加**。`limit` 截断时 `amount` 也跟着只算截断后的部分——
  要整月合计就别让 `limit` 生效（默认 50 可能不够，显式传 `limit=0` 或足够大的值）。
- ⚠️ `limit` 是「尾部 N 条」，接口**没有 cursor/offset 分页**。翻旧账按 `from/to` 分段取。

---

### 4.10 POST /api/bills/records

**记一笔。永远新增，不覆盖。**

**单条**（`date` 可以省，省了服务器按「服务器视角的今天」填）：

```json
{ "date": "2026-10-14", "title": "番茄", "amount": 12.5, "kind": "veg", "shop": "东门菜场", "note": "两个" }
```

**批量补记**：

```json
{ "records": [
  { "date": "2026-10-12", "title": "排骨", "amount": 26.8, "kind": "meat" },
  { "date": "2026-10-13", "title": "牛奶", "amount": 11.8, "kind": "egg" }
] }
```

成功：

```json
{ "code": 0,
  "data": { "saved": 2, "total": 134, "ids": ["20261012-001", "20261013-001"], "invalid": [] } }
```

- `saved` = 本次实际写入条数；`total` = **服务器上现有流水总条数**（不是本次的）。
- **`ids` 是这次新分配的 id，顺序和通过校验的记录一一对应**。客户端必须把它写回本地那条记录——
  没有 id 就永远改不了、删不了它（§4.11、§4.12）。
- `invalid` = **被拒条目的品名**（截到 20 字），没有品名的填 `(无品名)`。
  ⚠️ 和体重的 `invalid`（那里是日期）**不是同一个东西**，别复用同一个 UI 文案。
- 部分成功仍是 `200`/`code 0`：只要有一条通过就保存，其余进 `invalid`。全部被拒才是 `400`：

```json
{ "code": 400, "message": "没有可用记录：品名和金额（0.01–100000 元）都得有，日期得是真实的 YYYY-MM-DD",
  "data": { "invalid": ["番茄", "(无品名)"] } }
```

- 请求体不是合法 JSON / 为空 → `400 请求体不是合法 JSON`。
- `records` 数组里的非对象元素会被**静默跳过**（不进 `invalid`，也不报错）。

**校验规则（服务器行为，逐条）**：

| 字段 | 必填 | 规则 | 不合法时 |
|---|---|---|---|
| `date` | 否 | 省了 = 服务器今天；给了就必须是 `YYYY-MM-DD` 且**真实存在**（`2026-02-30` 被拒） | **整条被拒**，进 `invalid` |
| `title` | **是** | 去 HTML 标签、空白折叠、截到 **60 字**；空字符串等于没填 | **整条被拒**，进 `invalid` |
| `amount` | **是** | 数值，四舍五入 2 位，必须 `0.01 ≤ a ≤ 100000` | **整条被拒**，进 `invalid` |
| `kind` | 否 | 必须是 §5.7 里的代码（大小写不敏感，服务器 `strtolower`） | **不报错，静默归到 `other`** |
| `shop` | 否 | 同 `title` 的清洗，截到 **40 字** | 超长被截，不报错 |
| `note` | 否 | 同 `title` 的清洗，截到 **200 字** | 超长被截，不报错 |

> ⚠️ **和体重正相反的一条**：记账的 POST **没有**「同日覆盖」，也没有 upsert。
> 同一分钟连按两次「+12.5 番茄」就是两笔 12.5。App 侧自己防重复提交（提交中禁用按钮），
> 别指望服务器兜底。改内容走 PUT。
>
> ⚠️ `kind` 写错代码**不报错**，会变成「其他」。App 的分类选择器只放 §5.7 那 8 个代码，
> 不要让用户手输分类。

也接受表单编码：把整个 JSON 串放在 `payload` 字段，或用同名平铺字段
（`date=2026-10-14&title=番茄&amount=12.5`）。原生 App 建议直接用 JSON。

---

### 4.11 PUT /api/bills/records

按 `id` 改一条。

```json
{ "id": "20261014-001", "amount": 9.9, "note": "打折" }
```

- **`id` 必填**，没带 → `400 缺少 id（POST 返回体里的 ids[]）`；`id` 不存在 → `404 没有这条记录：20261014-001`。
- 其余键**只改传了的**，没带的沿用原值（服务器读原记录 → 覆盖你给的键 → 整条重校验）。
  可改的键：`date`、`title`、`amount`、`kind`、`shop`、`note`。传 `""` 是**清空**该字段
  （`title`/`amount` 清空等于不合法，会被拒）。
- `date` 改了会**重新排进新的一天**（id 不变，还是原来那个 `20261014-001`，即使已经改到 10 月 20 号）。
  **id 只是主键，不要拿它当日期解析**——要日期就读 `date` 字段。
- 整条重校验：改后的内容任何一项不合法 → `400 改后的内容不合法`，**原记录一个字都不动**。

成功（`record` 是**改完之后的整条**，直接拿来覆盖本地）：

```json
{ "code": 0, "data": { "record": { "id": "20261014-001", "date": "2026-10-14", "title": "番茄",
                                   "amount": 9.9, "kind": "veg", "shop": "东门菜场", "note": "打折",
                                   "time": 1760412000, "updated": 1760429100 },
                       "total": 132 } }
```

- `time` 是**录入时间**，改不动；`updated` 是这次修改的 Unix 秒（新增时是 `0`）。

---

### 4.12 DELETE /api/bills/records?id=YYYYMMDD-NNN

```json
{ "code": 0, "data": { "deleted": "20261014-001", "total": 131 } }
```

- `id` 走 **query 参数**（和体重的 `?date=` 一样，body 不参与）。没带 → `400 缺少参数 id`。
- id 不存在 → `404 没有这条记录`。第二次删同一条就是 404，客户端可以吞掉当成功（幂等意义上）。

---


## 5. 数据字典

### 5.1 记录对象（`series[]` / `records[]` / `latest`）

| 字段 | 类型 | 可为 null | 说明 |
|---|---|---|---|
| `date` | string | 否 | `YYYY-MM-DD` |
| `weight` | number | 否 | kg，1 位小数 |
| `delta` | number\|null | 是 | 相对上一条的变化。第一条恒为 `null`。负数 = 减重 |
| `bmi` | number\|null | 是 | **后台没填身高时是 `null`** |
| `category` | string | — | `thin`/`normal`/`over`/`obese`；`bmi` 为 null 时是 **空字符串 `""`**（不是 null） |
| `fat` | number\|null | 是 | 体脂百分比，没填是 `null` |
| `note` | string | 否 | 没填是 `""` |

### 5.2 BMI 分档（中国成人标准，不是 WHO 25/30）

| `category` | 区间 | 阈值来自 |
|---|---|---|
| `thin` | `bmi < 18.5` | `profile.bmi_ranges.thin` |
| `normal` | `18.5 ≤ bmi < 24.0` | `.normal` |
| `over` | `24.0 ≤ bmi < 28.0` | `.obese` 前的边界（`bmi_ranges.over = 28.0`） |
| `obese` | `bmi ≥ 28.0` | — |

> 注意 `bmi_ranges` 的对象语义是**上界**：`{"thin":18.5,"normal":24.0,"obese":28.0}`，
> 键名与阈值不完全对齐（`28.0` 挂在 `obese` 名下，实际是 `over` 的上界）。
> App 直接用 `category` 字段，别拿 `bmi_ranges` 自己算分档。

`category` 是**稳定的英文代码**，服务器保证不本地化。**文案由 App 自己翻译**
（建议：偏瘦 / 正常 / 超重 / 肥胖）。

### 5.3 `profile`

| 字段 | 类型 | 说明 |
|---|---|---|
| `height` | number\|null | cm。后台填的非数字、或 <80 / >250 都会变成 `null` → 连带 `bmi`/`category` 失效 |
| `gender` | string | `male`/`female`，只影响理想区间 |
| `target_weight` | number\|null | 后台没填是 `null` → `goal` 也是 `null` |
| `ideal` | object\|null | `{low, high}`，公式 `身高-105 ±2.5`，`height` 为 null 时是 null |
| `bmi_ranges` | object | 见 §5.2 |

### 5.4 `stats` / `goal` / `changes`

- `stats`：全量记录的 `{min, max, avg}`，1 位小数。**是全部历史的统计，不是时间窗内的**。
- `goal`：`{target, remaining, done}`。`remaining = 当前体重 - 目标体重`，
  **正数 = 还要减，负数 = 还要增**，展示时取 `abs(remaining)` 再配文案。
  `done` 在 `|remaining| < 0.05` 时为 true。没设目标是 `null`。
- `changes.d7 / d30 / d90`：`{span, from_date, delta}`。
  锚点是「最新记录日期 - N 天」，取**该日期或之前**的最后一条作为基线，
  所以 `from_date` 通常不等于「N 天前那天」——**UI 上要把 `from_date` 一起显示出来**，别让用户以为精确。
  数据不满 N 天时 `from_date` 和 `delta` 都是 `null`（键仍然存在）。

### 5.5 趋势图（客户端自己画）

`/api/health` 的 `series` 就是画图数据，全量返回（记录多时响应会大，需要瘦身时用
`/api/health/records?limit=90`）。

网页端画法（`noline/functions.php: noline_health_trend()`）可以直接照抄，行为一致：

- x 轴按**真实日期**线性分布（有断档时点会拉开，不伪装成等距）。
- y 轴范围 = 数据 `min`/`max`；**当 `max - min < 2` 时按中值 ±1 撑开**，否则一条水平线看着像坏了。
- 上下界正好映射到画布上下边缘（网页端留 10/170 的内边距）。
- 点数 >180 时按序号等距抽样，**但一定保留最后一点**（那才是「现在」）。
- **只有 1 条记录时画不出折线**（需要 ≥2 点），UI 走单点/空态。

---

### 5.6 流水对象（`records[]` / `recent[]` / `by_date[].items[]` / PUT 的 `record`）

| 字段 | 类型 | 可为 null | 说明 |
|---|---|---|---|
| `id` | string | 否 | 主键，形如 `20261014-003`。**服务器分配，客户端只读**。PUT/DELETE 全靠它 |
| `date` | string | 否 | `YYYY-MM-DD`，这一天里的第几条由 `id` 的后缀决定，**别拿 id 当日期用**（改过日期的记录 id 不变） |
| `title` | string | 否 | 品名，≤60 字，已去 HTML、空白折叠成单空格 |
| `amount` | number | 否 | 元，2 位小数，`0.01–100000` |
| `kind` | string | 否 | 分类代码，见 §5.7。**永远合法**（不认识的一律归 `other`） |
| `shop` | string | 否 | 在哪儿买的，≤40 字，没填是 `""` |
| `note` | string | 否 | 备注，≤200 字，没填是 `""` |
| `time` | int | 否 | **录入**时刻的 Unix 秒（服务器时钟）。PUT 改不动它 |
| `updated` | int | 否 | 最后修改时刻的 Unix 秒，**`0` = 从没改过**（不是「没这个字段」） |

- `id` 的字符串排序就是时间顺序，但**不要依赖这个**——要顺序就读接口返回的数组顺序（升序）。
- `time`/`updated` 是 Unix 秒（不是毫秒），时区按服务器；只用来做「今天记的」这类判断，
  别拿它反推 `date`（`date` 才是用户认定的那一天）。

### 5.7 分类代码（`kind`）

**稳定的英文代码**，服务器保证不本地化；中文名在 `data.kinds` 里一起给（那是**站点语言环境下**的
文案，App 要做自己的 i18n，别直接显示它）。

| `kind` | 后台中文 | 装什么 |
|---|---|---|
| `veg` | 蔬菜 | 叶菜、根茎、菌菇 |
| `fruit` | 水果 | 含干果以外的水果 |
| `meat` | 肉禽 | 猪牛羊鸡鸭、排骨、香肠 |
| `fish` | 水产 | 鱼虾蟹贝 |
| `egg` | 蛋奶 | 鸡蛋、牛奶、豆腐（豆制品算在这一类） |
| `grain` | 米面粮油 | 米、面、油、挂面 |
| `seasoning` | 调料 | 盐糖酱醋、葱姜蒜 |
| `other` | 其他 | 兜底。不确定就填这个 |

- 边界由**用户自己**决定，服务器不做任何推断（不会因为「排骨」里有「肉」就改分类）。
- 写错代码不报错、静默变 `other`（§4.10），所以 App 的分类选择器**只能从这 8 个里选**。
- 想加分类要改服务器（`Bills.php: KINDS`），客户端单方面加的代码会被当成 `other` 存进去。

### 5.8 聚合对象（`totals` / `week` / `prev_week` / `month` / `prev_month` / `year`）

同一个形状，只是区间不同：

| 字段 | 类型 | 说明 |
|---|---|---|
| `start` / `end` | string | 区间两端，含。`week` 的 `end` 可能是**未来**的日期（本周还没过完） |
| `amount` | number | 区间内合计，2 位小数 |
| `count` | int | 区间内笔数 |
| `days` | int | **有开销的天数**，不是区间天数 |
| `avg_day` | number | `amount / days`，即**「平均每个开了火的多少」**。`days == 0` 时是 `0`。⚠️ 它**不是**「除以日历天数的日均」，UI 上要写清「N 天有开销」，别说「本月日均花费」而不加解释 |
| `avg_bill` | number | `amount / count`，单笔均值。`count == 0` 时是 `0` |
| `max` | object\|null | 区间内金额最大的一笔 `{date, title, amount}`；区间空时是 `null` |

其它派生数组：

- `months[]`：**固定 7 个元素**（当月 + 往前 6 个月），`{month:"YYYY-MM", amount, count}`，
  没记录的月份**补零出现**，不会缺项。升序。所以「这个月一条没记」在图上是一根 0 高的柱子，
  而不是少一根——App 画柱状图时**别自己按有数据的月份生成横轴**。
- `kinds_month[]`：**只算本月**，`{kind, amount, count, share}`，按 `amount` 降序。
  `share` 是 **0–1 的小数**（`0.393` = 39.3%），保留 3 位；百分号前端拼。
  本月没记录时是空数组。想看别的月份的分类，用 `?from=&to=` + §4.9 自己聚合。
- `by_date[]`：**最近 12 天**（按有记录的日子数，不是自然 12 天），新的在前，
  `{date, amount, count, items[]}`，`items` 组内按录入时间倒序（最后记的那条在最上面）。
  来源是**最后 120 条**流水，更早的不在这里。
- `recent[]`：最后 30 条流水，新的在前，扁平（不分组的 `by_date`）。

### 5.9 `span`（时间口径，全部由服务器算好）

| 字段 | 说明 |
|---|---|
| `today` | 服务器视角的今天 |
| `weekday` | 今天是周几，**ISO：周一=1 … 周日=7** |
| `week_start` / `week_end` | 本周区间。**一周从周一算起**（不是周日，也不是站点 locale 的习惯） |
| `prev_week_start` / `prev_week_end` | 上一周 |
| `month_start` / `month_end` | 本自然月（`month_end` 是这个月最后一天） |
| `prev_month_start` / `prev_month_end` | 上一自然月 |
| `year_start` / `year_end` | 当年 1/1 – 12/31 |
| `month_days` | 本月总天数（28–31） |
| `month_day_of` | 今天是本月第几天 |

> ⚠️ **本周 / 本月都是「过了一半」的区间**。周一到周三就报「本周 38.3 元、较上周 −55.8」，
> 用户会以为这周省了钱。网页版的四宫格在涨跌后面挂了 `3/7 天`、`14/31 天`
> （算法：周用 `weekday`，月用 `month_day_of` / `month_days`）。**App 请照做**，
> 或者用「日均」这类能横向比的标准去展示，别裸报部分区间对完整区间的差。
>
> 所有区间都是**服务器时区**下的日历区间，跟设备时区无关。跨时区出差时以服务器为准
> （`/api/ping` 的 `time` 可用来校准）。

---

## 6. 服务器侧开关对 App 的影响

用户能在博客后台「插件 → NolineApi → 设置」里调这些，App 要能容忍：

| 后台开关 | 关/未填时 App 看到什么 | 应该怎么提示 |
|---|---|---|
| 对外接口 `enabled` | 所有 `/api` 返回 **503 接口未开启** | 「站点后台把接口关了」 |
| 接口密钥 `apiKey` | 后台清空并保存后 → **503 接口密钥未设置**（不是 401） | 同上，让用户重新生成 |
| 密钥填错 | 401（没带）/ 403（带了但不匹配） | 见 §3.2 |
| 健康数据写入 `writeEnabled` | 两块（健康 + 记账）的 POST/PUT/DELETE **403**，GET 正常 | 引导开写入；按 §3.2 区分成因。后台里这一项叫「数据写入（健康 / 记账）」，**一个开关管两边** |
| 接口写主题设置 `themeWrite` | 只有 `PUT /api/theme` **403**，其它端点正常（这项默认是**允许**） | 「后台没开这项，去插件设置里打开」；别自动帮用户猜原因，这条是用户有意关掉的 |
| 身高 `height` | `bmi` / `category` / `profile.ideal` 变 null，记录本身照常返回 | BMI 那一栏显示「先在后台填身高」 |
| 目标体重 `targetWeight` | `goal` 为 null | 隐藏目标进度，或用 `ideal` 区间兜底 |
| 允许跨域来源 `corsOrigin` | 浏览器版请求被同源策略拦 | 让后台填 App 的 Origin |
| 健康页可见性 `healthVisible` | **只影响网页那页，接口不受影响** | 不要在 App 里当错误处理 |
| 记账页可见性 `billsVisible` | 同上，**只影响网页「买菜记账」页**，`/api/bills*` 不受它管 | 同上。设成「仅自己可见」时网页只给登录用户看，App 拿密钥照样能读 |

数据存 `usr/uploads/noline_api/store.php`（体重）和 `usr/uploads/noline_api/bills.php`（记账），
**两个文件、两把锁**，互不影响。体重上限 **3660 条**（约一天一条存 10 年），记账上限
**5000 条**（一天三四条能用四五年），超出时都**丢最旧的**。所以极老的记录可能凭空消失——
App 不要把「最早一条」当不可变边界，也不要把本地缓存当成准。

后台还有一个「主题管理」页（顶栏右上角的链接 → `?noline_api=settings`）：14 项主题设置、
接口状态、GitHub 一键更新都在那儿。**它走管理员登录态 + 会话令牌，和接口密钥是两套鉴权**——
App 拿不到也不需要拿那个页面。

---

## 7. 客户端实现要求

### 7.1 配置与安全

- 站点地址、密钥两项由用户填。密钥**存系统级安全存储**（iOS Keychain / Android Keystore 或 EncryptedSharedPreferences），
  不要放明文 SharedPreferences、SQLite 或日志。
- 请求一律走 **header** 传密钥。`?key=` 虽然支持，但会进服务器访问日志、代理日志和浏览器历史，
  App 里**不要用**（除非用户手动勾选「用兜底查询串」）。
- 日志、崩溃上报、错误提示里对密钥脱敏（只留前 4 位 + 长度）。
- 站点地址强制或强烈建议 **https**；用 http 时提示「写入模式不安全」。
- 如果实现了 `PUT /api/theme`（§4.3）：**默认不暴露给普通入口**，放进「开发者/高级」折叠区，
  每次提交都要有逐项 diff 预览 + 二次确认（改的是全站可见内容，不是本地设置）。
  `notice` / `logoFooter` 含 HTML 时，预览要用**纯文本**渲染给用户看，别在他的 App 里 `innerHTML` 一遍。

### 7.2 首次配置自检（建议顺序）

1. `GET /api/ping`（不带密钥）三种 URL 形态依次试 → 确定路径形态，确认接口存在。
2. 带密钥 `GET /api/health` → 确认密钥正确，同时拿到全部首屏数据。
3. 若 1 通 2 挂 → 是鉴权问题；若 1 就挂 → 是 URL / 网络 / 接口关闭问题。
   **把这个区分做实**，用户最常见的困惑就在这。

### 7.3 离线与写入队列（体重）

- 本地先落库，再入队上传。
- **同一天的记录以「最后一次提交」为准**（服务器按天整体覆盖），所以队列重放、乱序、重复提交都是安全的。
- 队列里同一天多条待提交记录，**合并成一条**再发（取本地时间戳最新的那条完整对象）。
- 批量补录用 `{"records":[…]}` 一次请求，不要逐条发。
- 提交成功后用服务器返回的整条记录覆盖本地（`bmi`/`delta`/`category` 都是服务器算的，
  别在客户端自己算一遍——身高可能在后台被改过）。

### 7.4 空态与边界（**必须做**，别只跑 happy path）

| 情况 | 判据 | UI |
|---|---|---|
| 完全没有记录 | `count == 0` | 引导「记第一条」，趋势图整块隐藏 |
| 只有 1 条 | `count == 1` | 折线画不出来；涨跌芯片全是 `—`；`stats` 三项相等 |
| 没设身高 | `profile.height == null` | BMI 显示 `—`，提示去后台填 |
| 没设目标 | `goal == null` | 用 `ideal` 区间或不显示目标进度 |
| 跨度内无基线 | `changes.d30.delta == null` | 显示 `—` 而不是 0 |
| 首次/无上一条 | `delta == null` | 显示「第一条记录」 |
| 持平 | `|delta| < 0.05` | 显示「持平」，不要用 `+0.0kg` |
| 记账一条没有 | `/api/bills` 的 `count == 0`（`totals`/`week`/`month` 全是 `null`） | 引导「记第一笔」，柱状图和分类条整块隐藏（`months` 是空数组，不是七根 0） |
| 记账本周没买 | `week.count == 0` 但 `count > 0` | 金额显示 `0`，**不是** `—`（这是真的没花，不是没数据）；涨跌按 §5.9 处理 |
| 上期是空的 | `prev_week`/`prev_month` 的 `count == 0` | 涨跌显示 `—`，**别显示「省了本期全部」** |
| 某月整月空档 | `months[]` 里 `amount == 0` 的那格 | 横轴照常出现（服务器已补零），画一根贴地的矮柱，别删格子 |
| 分类一条没用过 | `kinds_month` 里没有那个 `kind` | 分类占比不显示它；分类**选择器**仍要显示全 8 项（读 `kinds`） |

### 7.5 日期与「今天」

- `date` 是不带时区的裸日期，服务器不猜时区，直接按字符串存取。
- **建议**：录入时用**设备本地日期**作为 `date`，并允许用户手改
  （跨时区出差、或者凌晨称重时，用户自己更清楚该算哪天）。
- 需要「服务器视角的今天」时用 `/api/ping` 的 `time` 做偏移校准（服务器 `timezone` 见 `/api/theme`）。
- 未来的日期接口**不拒绝**（没有校验「不能填明天」），客户端自己要拦。

### 7.6 数值输入

- 体重步进 0.1 kg，输入后立即按 `20–400` 校验（越界服务器整条拒）。
- 体脂可选，`1–70`。**越界时服务器只是丢掉这个字段，不报错**——客户端必须自己校验并明确告知，
  否则用户会以为存进去了。
- 备注可选，客户端预检 200 字（超了会被截断，不报错）。

### 7.7 本地化

- 服务器返回的 `category`、`gender` 是代码，`message` 是中文人读文案。
- App 的文案走自己的 i18n，**不要显示服务器 `message` 原文**（除非作为「服务器提示」的次要信息）。
- 记账的 `kind` 同样是代码；`data.kinds` 里的 `label` 是**站点语言**下的中文，
  当占位文案用可以，做正式 UI 文案请 App 自己翻译那 8 个词。

### 7.8 记账：录入、id 与离线队列

**录入界面**（用户原话：「记录我平时买菜的开销，买的什么菜这种」，所以品名是主角）：

- 三个必填级别的字段：`title`（品名）、`amount`（金额）、`date`（哪天，默认今天、允许手改）。
  分类、哪儿买的、备注都是可选。
- **金额输入**：允许 2 位小数，输入后立即按 `0.01–100000` 校验。越界或非数字服务器会**整条拒掉**
  （不是丢字段），所以预检要拦住并告诉用户是哪一条。
- **品名给历史建议**：买菜翻来覆去就那些东西。把本地见过的 `title` 做成快捷候选，
  选中时顺带填上上次用的 `kind` 和 `shop`——这是这套数据能提供的最省事的交互，
  接口本身没有「常用商品」端点，靠 App 本地缓存。
- **一次买多样**：一次提交里放多条（`{"records":[…]}`），别为了「三样菜」发三个请求。
  网页版就是按天分组显示同一天多笔的。
- 提交按钮**必须防连点**：POST 永远新增，双击就是两笔（§4.10）。

**id 是本地库的一等公民**：

- 新增成功后，把响应 `data.ids[]` 按顺序认领回本地那几条（`ids` 与通过校验的记录同序）。
  **没拿到 id 的本地记录改不了也删不了**——它只能被重复新增。
- 队列里一条待提交的记账记录如果还没有 id，它只能是「未上传的新增」；
  有 id 的才允许排 PUT / DELETE。
- 启动时（或下拉刷新时）拉一次 `/api/bills/records?limit=0`（≤5000 条时一次拉全量最省事），
  用服务器结果**覆盖本地已知的条目**，本地只保留服务器没有的未提交项。

**离线与重放**（和体重不一样，看仔细）：

- 新增：离线攒着，联网后**合并成一个批量 POST**。重放不安全（会多记），
  所以只在拿到 `ids` 之后才把本地那条标成已同步；超时/断网时按 §3.3 的办法先查后补。
- 修改：`PUT` 带 id，**整体幂等**（同一个 body 重放两次结果一样），随便重试。
- 删除：`DELETE` 带 id，第二次是 404，**当成功处理**。
- 队列里同一条 id 的多条待提交（改了又删），按顺序发即可；只有「删了又改」这种本地不该出现的
  情况需要客户端自己合并。
- 记账**没有字段级合并**，PUT 是「给哪些键改哪些键」，没带的键保持服务器原值
  （这点比体重的整体覆盖友好，别自己先 GET 再整条 PUT 回去——那会引入不必要的读写竞争）。

---

## 8. curl 示例

```bash
KEY='你的密钥'
BASE='https://blog.example.com'

# 1 自检（无需密钥）
curl -s "$BASE/api/ping"

# 2 概览
curl -s -H "X-Noline-Key: ***" "$BASE/api/health"

# 3 明细：最近 90 天区间内的最后 100 条
curl -s -H "X-Noline-Key: ***" \
     "$BASE/api/health/records?limit=100&from=2026-07-14&to=2026-10-12"

# 4 写一条
curl -s -X POST -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"date":"2026-10-12","weight":66.0,"fat":18.4,"note":"晨起空腹"}' \
     "$BASE/api/health/records"

# 5 批量补录
curl -s -X POST -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"records":[{"date":"2026-10-01","weight":67.2},{"date":"2026-10-02","weight":67.0}]}' \
     "$BASE/api/health/records"

# 6 删除某天
curl -s -X DELETE -H "X-Noline-Key: ***" \
     "$BASE/api/health/records?date=2026-10-12"

# 7 兜底 URL 形态（伪静态不通时）
curl -s -H "X-Noline-Key: ***" "$BASE/index.php?noline_api=health"

# 8 读主题全部配置
curl -s -H "X-Noline-Key: ***" "$BASE/api/theme"

# 9 写主题配置：只带要改的键，其它保持原值
curl -s -X PUT -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"accentColor":"#e0662e","logoName":"QINE","showSidebarCats":true}' \
     "$BASE/api/theme"

# 10 兜底形态下的 PUT（路径名换成 query 参数，方法仍是 PUT）
curl -s -X PUT -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"notice":""}' \
     "$BASE/index.php?noline_api=theme"

# 11 记账概览（首屏一个请求）
curl -s -H "X-Noline-Key: ***" "$BASE/api/bills"

# 12 记账明细：默认最后 50 条
curl -s -H "X-Noline-Key: ***" "$BASE/api/bills/records"

# 13 本月肉禽合计（看 data.amount，别自己加）
curl -s -H "X-Noline-Key: ***" \
     "$BASE/api/bills/records?limit=0&kind=meat&from=2026-10-01&to=2026-10-31"

# 14 记一笔
curl -s -X POST -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"date":"2026-10-14","title":"番茄","amount":12.5,"kind":"veg","shop":"东门菜场"}' \
     "$BASE/api/bills/records"

# 15 一次买多样：批量补记，响应里的 ids[] 按顺序认领回本地
curl -s -X POST -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"records":[{"date":"2026-10-14","title":"排骨","amount":26.8,"kind":"meat"},{"date":"2026-10-14","title":"牛奶","amount":11.8,"kind":"egg"}]}' \
     "$BASE/api/bills/records"

# 16 改一笔（body 必须带 id，只给要改的键）
curl -s -X PUT -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"id":"20261014-001","amount":9.9,"note":"打折"}' \
     "$BASE/api/bills/records"

# 17 删一笔（id 在 query 上）
curl -s -X DELETE -H "X-Noline-Key: ***" "$BASE/api/bills/records?id=20261014-001"

# 18 兜底形态下的记账写入（路径名换成 query 参数，方法仍是 POST）
curl -s -X POST -H "X-Noline-Key: ***" -H 'Content-Type: application/json' \
     -d '{"title":"土豆","amount":3.2,"kind":"veg"}' \
     "$BASE/index.php?noline_api=bills/records"
```

---

## 9. 后端已知限制（不要试图在客户端绕，也别当成 bug 报）

1. **没有分页**：两块都只有 `limit`（尾部 N 条）+ `from/to`（记账再多一个 `kind`）。
   没有 cursor / offset / 排序参数（恒按日期升序）。
2. **没有增量同步**：没有 `updated_since`、没有 ETag。体重的记录连 id 都没有（按天定位），
   记账有 `id` 和 `updated`，但接口**不提供**「只要 updated > X 的那些」——
   要「拉最新」只能重新拉全量（记账 `?limit=0`，体重 `series`）。
3. **没有速率限制 / 没有 429**：客户端自己负责退避。
4. **单用户、单密钥**：没有权限分级，密钥泄漏 = 可读写全部健康数据和全部流水（含改、删）。
   主题写入还多一道独立开关（见下一条），但那只防「手滑」，不防拿到密钥的人。
5. **写入语义两块相反**：体重 POST 是**整天整体覆盖**（无字段合并、无软删除、无回收站，见 §4.6 的警告）；
   记账 POST **永远新增**、无 upsert，改删只能按 id（见 §4.10）。谁也别照抄谁。
6. **`PUT /api/theme` 是整批原子写，不是逐条写**：任何一个字段校验不过，整批一条都不存，
   返回 400 + `data.errors`（key → 中文原因）。没有「保存了 3 项、第 4 项失败」这种中间态，
   也没有撤销/改回上一版的接口——想要「改回去」只能把原值再 PUT 一次。
   后台开关 `themeWrite` 关着时是 403，跟「密钥不对」是两种提示，别混。
   更强的是 §4.3 里已经写明的：`notice` / `logoFooter` **原样入库、不清洗 HTML**，
   所以这道写权限等于能改整站门面，App 侧不要把它做成随手可点的入口。
7. **无 Webhook / 推送**：数据变了不会通知，客户端自己拉。
8. **条数上限**：体重 3660、记账 5000，超了**丢最旧的**，没有提醒、没有回收站。
9. `stats`（体重）是全量统计，**不能**按 `from/to` 窗口重算——需要窗口均值请客户端自己算。
10. **记账的统计口径是写死的**：`months` 固定近 7 个月、`by_date` 固定最近 12 天（来源只有最后 120 条）、
    `recent` 固定 30 条、`kinds_month` 只算本月。想要别的窗口就用 §4.9 自己拉自己聚合，
    接口不会加参数。
11. **金额没有多币种、没有预算、没有退款/负数**：`amount` 必须 ≥ 0.01，退货只能把那条删掉或改小，
    不存在「记一笔 −12.5」。`currency` 恒为 `CNY`。
12. **分类是固定 8 项**（§5.7），接口不接受自定义分类；`title` 里没有语义解析，
    服务器不会替你猜分类。
13. **id 不可变、也不回收**：改了 `date` 的流水 id 还是老的那个（`20261014-003` 可能已经指到 10-20）。
    删掉的 id 不会重新发给新记录。客户端可以把 id 当不透明主键存着，**不要解析它的日期**。

---

## 10. 交付验收清单

**连通与鉴权**
- [ ] 三种 URL 形态自动探测，配置页显示最终采用的形态
- [ ] 未配密钥 / 密钥错 / 站点关接口 / 只读模式 / 写主题未开启，五种情形给出五种不同且准确的提示
- [ ] 401/403/404/405/500 不自动重试；网络失败退避 ≤3 次
- [ ] 密钥存安全存储，任何日志/错误文案里已脱敏

**读**
- [ ] 首屏一次 `/api/health` 渲染：当前体重、BMI+分档、目标进度、7/30/90 天涨跌
- [ ] 涨跌芯片把 `from_date` 一起显示出来
- [ ] `remaining` 正负分别渲染成「还要减 / 还要增」，`done` 渲染成「已达目标」
- [ ] §7.4 七种空态/边界全部走过一遍
- [ ] 趋势图 x 轴按真实日期分布，1 条记录时不崩、不画空线
- [ ] `category` 用 App 自己的语言显示，不显示原始代码也不显示服务器中文串以外的硬编码

**写**
- [ ] 新增 / 修改 / 删除当天记录，成功后用服务器返回覆盖本地
- [ ] 修改体重时**保留**原有 `fat` / `note`（整体覆盖陷阱）
- [ ] 批量提交用 `{"records":[…]}`，并正确处理 `data.invalid`（回显被拒日期）
- [ ] `fat` 越界、`note` 超长、`date` 非法，客户端预检并给明确反馈
- [ ] 离线记录 → 恢复网络后自动补交，重复提交不产生重复数据
- [ ] DELETE 的 404 不被当失败

**记账 · 读**
- [ ] 首屏一次 `/api/bills` 渲染：本周 / 本月 / 累计三个数 + 近 7 个月柱 + 本月分类占比 + 最近流水
- [ ] 涨跌由客户端用 `week` 与 `prev_week`（或 `month`/`prev_month`）自己减，上期空时显示 `—`
- [ ] 本周 / 本月的卡片上带「过了几天」的口径提示（`weekday`、`month_day_of`/`month_days`），不裸报部分区间差
- [ ] 柱状图横轴用服务器给的 7 个 `months`，**含金额为 0 的那几个月**，不按有数据的月份自己生成
- [ ] `avg_day` 的文案写清是「有开销的那几天平均」，不是除以日历天数
- [ ] §7.4 里记账那 5 行空态全部走过（一条没有 / 本周没买 / 上期空 / 整月空档 / 分类没用过）
- [ ] 区间合计读 `data.amount`，没有把明细拉完自己加

**记账 · 写**
- [ ] 记一笔的表单：品名 + 金额必填、日期默认今天可改、分类从固定 8 项里选（不手输）
- [ ] 提交按钮防连点；批量用 `{"records":[…]}` 一次发完
- [ ] POST 成功后把 `data.ids[]` 按顺序写回本地记录，之后才允许改/删
- [ ] `data.invalid` 回显的是**品名**，文案按记账写（不是「日期无效」）
- [ ] 金额越界 / 非数字 / 品名空，客户端预检并指明是哪一条（服务器整条拒，不丢字段）
- [ ] 改一笔走 PUT（带 id、只传改动键）；删一笔走 DELETE（id 在 query）
- [ ] PUT/DELETE 的 404 不当失败轰炸：本地那条要么已删，要么从没上传成功过
- [ ] 离线新增重连后补交，**不会因为重放产生重复开销**（超时先按日期查一次再决定补不补）
- [ ] `kind` 用 App 自己的语言显示，不显示原始代码，也不直接抄 `kinds[].label`

**主题设置（做 §4.3 才需要，只读展示则跳过本组）**
- [ ] 编辑器里每个字段的类型对齐 §4.3 的字段表：3 个开关用 switch、2 个富文本用多行、
      `accentColor` 用取色器 + 十六进制输入双入口、`navIcons` 给「逗号分隔的 slug:图标」提示
- [ ] 只把改动过的字段放进 PUT body，不要整包 14 项原样回传
- [ ] `data.saved` 成功后用响应体里的新值刷新本地，不重新 GET
- [ ] 400 时把 `data.errors` 逐字段回显到对应输入框下面，其余字段保留用户已输入的内容
- [ ] `notice` / `logoFooter` 的输入框明示「这里可以写 HTML」，且**不要**在预览里执行它
- [ ] 没有任何一键改公告 / 改头图的快捷入口（§9 第 6 条）
- [ ] 403（写主题未开启）文案引导用户去后台插件设置，而不是重试

**质量**
- [ ] 有针对 API 层的单元测试（mock 全部 §3.1 状态码）
- [ ] 有一份「配置 + 首次自检」的用户说明，包含「后台要开写入开关」和「改主题还要再开写主题开关」这两步

---

## 附：源码位置（需要确认细节时看这里）

| 内容 | 位置 |
|---|---|
| 路由 / 分发 | `plugins/NolineApi/Plugin.php: onRequest()` `route()` |
| 鉴权 | 同上 `requireKey()` `requireWrite()` `requireThemeWrite()` |
| CORS | 同上 `cors()` |
| 主题设置端点 | 同上 `themeSettings()` `themePatch()` `accent()` |
| 主题字段白名单 / 校验 / 清洗 | `plugins/NolineApi/Theme.php: FIELDS` `check()` `read()` `write()` `shape()` |
| 后台「主题管理」页 | `plugins/NolineApi/Admin.php`（与接口共用上面那张字段表） |
| 体重：概览 / 明细 / 校验 | `plugins/NolineApi/Plugin.php: summary()` `records()` `normalize()` `changes()` `goal()` `profile()` |
| 体重：读写实现 | 同上 `mutate()` `put()` `raw()` |
| 记账：路由 / 五个端点 | `plugins/NolineApi/Bills.php: handle()` `save()` `replace()` `remove()` |
| 记账：概览聚合 | 同上 `summary()` `span()` `aggregate()` `months()` `byKind()` `byDate()` `records()` |
| 记账：校验与 id 分配 | 同上 `normalize()` `text()` `append()` |
| 记账：分类表（8 项，接口的 `kinds` 就是它） | 同上 `KINDS` `kindDict()` |
| 记账：读写实现与文件上限 | 同上 `mutate()` `put()` `read()` `readIndexed()` `dataDir()`（`FILE` `KEEP` `MAX_LIMIT`） |
| 记账：插件设置页里的可见性 / 接口一览 | `plugins/NolineApi/Plugin.php`（`billsVisible` 字段）+ `Bills.php: docRows()` |
| 网页版画法（趋势图算法） | `noline/functions.php: noline_health_trend()` `noline_health_axis()` |
| 网页版展示层（可参考交互） | `noline/health.php`、`noline/css/design.css` 的「健康记录页」一节 |
| 记账网页版（数字口径可参考） | `noline/bills.php`、`noline/functions.php: noline_bills_*()`（`money` `delta` `bars` `kinds` `kind_row`） |
