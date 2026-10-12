# NoLine 健康数据接口 · App 对接开发文档

> 面向对象：负责实现移动端 / 桌面端 App 的开发（或 AI 助手）。
> 后端**已经写完并在跑**，你的工作只有客户端。本文档是唯一的接口事实来源；
> 服务器端源码在 `plugins/NolineApi/`（Typecho 插件：`Plugin.php` 路由与端点、`Theme.php` 主题配置项、
> `Admin.php` 后台管理页、`Updater.php` 主题更新），有冲突以源码为准。
> 接口版本：plugin 1.1.0，本文档对应 2026-10-12 的实现。
>
> **1.1.0 的两处变化**（老客户端会踩）：
> 1. `theme.showSidebarCats` 从字符串 `"1"/""` 改成**真正的布尔值** `true/false`。
> 2. 新增 `PUT /api/theme`，App 可以写主题设置（见 §4.3）——这是**高危能力**，务必先读那一节的安全说明。

---

## 1. 这个 App 要做什么

用户在自己的 Typecho 博客上记录体重。博客主题已经有一个网页版「健康记录」页展示数据。
App 是这套数据的**录入口和移动看板**，需要覆盖：

1. 配置：填站点地址 + 密钥，一次配好，能自检。
2. 记录：新增 / 修改 / 删除某一天的体重（可选体脂、备注）。
3. 概览：当前体重、BMI 及分档、目标进度、7/30/90 天涨跌。
4. 趋势：体重折线（可直接用 `/api/health` 返回的 `series` 自己画）。
5. 离线可用：本地暂存，联网后补交（接口按天覆盖，重试天然幂等，见 §7.3）。
6. （可选）跟着博客改门面：读 / 写主题的 14 项配置（头像、强调色、公告等），见 §4.2 与 §4.3。
   **默认可以完全不做**——这块能力风险高（拿到密钥就能改整站），只有用户明确要在 App 里改设置时才做。

**不做**：多用户、账号体系、运动/饮食/睡眠数据。只有体重。

---

## 2. 基本约定

| 项目 | 值 |
|---|---|
| Base URL | 用户站点根地址，如 `https://blog.example.com` |
| 路径前缀 | `/api/` |
| 鉴权 | 请求头 `X-NoLine-Key: <密钥>`（除 `/api/ping` 外全部必需） |
| 请求体 | `application/json`（也接受表单编码，见 §5.5） |
| 响应体 | `application/json; charset=utf-8`，`Cache-Control: no-store` |
| 数字精度 | 体重 / 体脂 / BMI 一律保留 1 位小数 |
| 日期格式 | `YYYY-MM-DD` 字符串，**不带时区** |
| 单位 | 体重 kg、身高 cm、体脂百分比数值（`18.2` 表示 18.2%，不带 `%`） |

### 2.1 三种路径写法（按可用性从高到低依次尝试）

```
https://site/api/health              # 伪静态开启（首选）
https://site/index.php/api/health    # 伪静态关闭
https://site/?noline_api=health      # 服务器抢了 /api，或连 index.php 前缀都不通时的兜底
```

`?noline_api=` 的值就是前缀后面的部分（`ping` / `theme` / `health` / `health/records`），
其它查询参数照常带（`?noline_api=health/records&limit=100`）。

**客户端要求**：配置页做一次连通性探测，三种写法依次试 `GET /api/ping`，记下第一个通的形态，
后续请求都按那个形态拼 URL。不要只硬编码第一种。

### 2.2 CORS

只有网页版 App 需要。原生 App / 脚本不受同源限制。
若站点后台没把 `允许跨域来源` 填成你的 Origin，浏览器里跨域请求会直接被拒——这不是接口 bug，
需要用户在插件设置里填 `https://你的源` 或 `*`。

预检：服务器对已放行的 Origin 回 `Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`
和 `Access-Control-Allow-Headers: X-NoLine-Key, Content-Type`，`OPTIONS` 返回 204。

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
| 400 | 请求体不合法 / 没有一条记录通过校验 | 回显 `data.invalid` 里的日期，让用户改 |
| 401 | 没带密钥 | 引导去 App 设置里填密钥 |
| 403 | 密钥不正确 **或** 写入未开启 | 见 §3.2，两者要区分 |
| 404 | 接口路径不存在（`/api/xxx`）/ DELETE 的日期没有记录 | 路径错 → 试下一种 URL 形态；删除 404 → 提示「今天没有记录」 |
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
网络层失败（超时、连接中断）用指数退避，最多 3 次；因为写接口是「按天覆盖」，重放安全（见 §7.3）。

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

「写入开关」在服务器后台有**两个独立的**：`健康数据写入` 管 POST/DELETE 记录，
`接口写主题设置` 管 `PUT /api/theme`。它们可以一个开一个关，见 §6。

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

## 6. 服务器侧开关对 App 的影响

用户能在博客后台「插件 → NolineApi → 设置」里调这些，App 要能容忍：

| 后台开关 | 关/未填时 App 看到什么 | 应该怎么提示 |
|---|---|---|
| 对外接口 `enabled` | 所有 `/api` 返回 **503 接口未开启** | 「站点后台把接口关了」 |
| 接口密钥 `apiKey` | 后台清空并保存后 → **503 接口密钥未设置**（不是 401） | 同上，让用户重新生成 |
| 密钥填错 | 401（没带）/ 403（带了但不匹配） | 见 §3.2 |
| 健康数据写入 `writeEnabled` | POST / DELETE **403**，GET 正常 | 引导开写入；按 §3.2 区分成因 |
| 接口写主题设置 `themeWrite` | 只有 `PUT /api/theme` **403**，其它端点正常（这项默认是**允许**） | 「后台没开这项，去插件设置里打开」；别自动帮用户猜原因，这条是用户有意关掉的 |
| 身高 `height` | `bmi` / `category` / `profile.ideal` 变 null，记录本身照常返回 | BMI 那一栏显示「先在后台填身高」 |
| 目标体重 `targetWeight` | `goal` 为 null | 隐藏目标进度，或用 `ideal` 区间兜底 |
| 允许跨域来源 `corsOrigin` | 浏览器版请求被同源策略拦 | 让后台填 App 的 Origin |
| 健康页可见性 `healthVisible` | **只影响网页那页，接口不受影响** | 不要在 App 里当错误处理 |

数据存 `usr/uploads/noline_api/store.php`，上限 **3660 条**（约一天一条存 10 年），
超出时**丢最旧的**。所以极老的记录可能凭空消失——App 不要把「最早一条」当不可变边界。

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

### 7.3 离线与写入队列

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

---

## 8. curl 示例

```bash
KEY='你的密钥'
BASE='https://blog.example.com'

# 1 自检（无需密钥）
curl -s "$BASE/api/ping"

# 2 概览
curl -s -H "X-NoLine-Key: ***" "$BASE/api/health"

# 3 明细：最近 90 天区间内的最后 100 条
curl -s -H "X-NoLine-Key: ***" \
     "$BASE/api/health/records?limit=100&from=2026-07-14&to=2026-10-12"

# 4 写一条
curl -s -X POST -H "X-NoLine-Key: ***" -H 'Content-Type: application/json' \
     -d '{"date":"2026-10-12","weight":66.0,"fat":18.4,"note":"晨起空腹"}' \
     "$BASE/api/health/records"

# 5 批量补录
curl -s -X POST -H "X-NoLine-Key: ***" -H 'Content-Type: application/json' \
     -d '{"records":[{"date":"2026-10-01","weight":67.2},{"date":"2026-10-02","weight":67.0}]}' \
     "$BASE/api/health/records"

# 6 删除某天
curl -s -X DELETE -H "X-NoLine-Key: ***" \
     "$BASE/api/health/records?date=2026-10-12"

# 7 兜底 URL 形态（伪静态不通时）
curl -s -H "X-NoLine-Key: ***" "$BASE/index.php?noline_api=health"

# 8 读主题全部配置
curl -s -H "X-NoLine-Key: ***" "$BASE/api/theme"

# 9 写主题配置：只带要改的键，其它保持原值
curl -s -X PUT -H "X-NoLine-Key: ***" -H 'Content-Type: application/json' \
     -d '{"accentColor":"#e0662e","logoName":"QINE","showSidebarCats":true}' \
     "$BASE/api/theme"

# 10 兜底形态下的 PUT（路径名换成 query 参数，方法仍是 PUT）
curl -s -X PUT -H "X-NoLine-Key: ***" -H 'Content-Type: application/json' \
     -d '{"notice":""}' \
     "$BASE/index.php?noline_api=theme"
```

---

## 9. 后端已知限制（不要试图在客户端绕，也别当成 bug 报）

1. **没有分页**：只有 `limit`（尾部 N 条）+ `from/to`。没有 cursor / offset / 排序参数（恒按日期升序）。
2. **没有增量同步**：没有 `updated_since`、没有 ETag、没有记录 ID 和修改时间。
   要「拉最新」只能重新拉 `series`（≤366 条时一次拉全量最省事）。
3. **没有速率限制 / 没有 429**：客户端自己负责退避。
4. **单用户、单密钥**：没有权限分级，密钥泄漏 = 可读写全部健康数据。
   主题写入还多一道独立开关（见下一条），但那只防「手滑」，不防拿到密钥的人。
5. **写入是整体覆盖**，无字段级合并、无软删除、无回收站（见 §4.6 的警告）。
6. **`PUT /api/theme` 是整批原子写，不是逐条写**：任何一个字段校验不过，整批一条都不存，
   返回 400 + `data.errors`（key → 中文原因）。没有「保存了 3 项、第 4 项失败」这种中间态，
   也没有撤销/改回上一版的接口——想要「改回去」只能把原值再 PUT 一次。
   后台开关 `themeWrite` 关着时是 403，跟「密钥不对」是两种提示，别混。
   更强的是 §4.3 里已经写明的：`notice` / `logoFooter` **原样入库、不清洗 HTML**，
   所以这道写权限等于能改整站门面，App 侧不要把它做成随手可点的入口。
7. **无 Webhook / 推送**：数据变了不会通知，客户端自己拉。
8. **记录数超过 3660 会丢最旧的**。
9. `stats` 是全量统计，**不能**按 `from/to` 窗口重算——需要窗口均值请客户端自己算。

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
| 概览 / 明细 / 校验 | 同上 `summary()` `records()` `normalize()` `changes()` `goal()` `profile()` |
| 读写实现 | 同上 `mutate()` `put()` `raw()` |
| 网页版画法（趋势图算法） | `noline/functions.php: noline_health_trend()` `noline_health_axis()` |
| 网页版展示层（可参考交互） | `noline/health.php`、`noline/css/design.css` 的「健康记录页」一节 |
