# Noline

> 一个克制、无描边的 Typecho 主题，配一个可选的 REST 接口插件。当前版本 **1.20.1**。

Noline 主张「无描边卡片」与「设计令牌驱动」：层次靠留白、圆角和一套统一的颜色令牌划分，
不靠重描边和强阴影。所有颜色集中在 `:root`，浅色深色各一套，强调色在后台填一个十六进制就够，
深色模式会按它自动算出更亮的变体，换肤不用翻文件。

---

## 这个仓库里有什么

| 路径 | 内容 |
|---|---|
| 仓库根目录 | **主题本体**，目录结构就是 Typecho 的主题目录，可以直接当 `usr/themes/noline` 用 |
| `plugins/NolineApi/` | 可选插件：对外接口 + 后台「主题管理」页 + GitHub 一键更新 |
| `APP-API-SPEC.md` | 给 App 开发者的接口规格书（端点、字段、状态码、客户端实现要求） |
| `_dev/` | 静态检查脚本与打包脚本，**不进发布包** |

发布包是 Release 附件 `noline.zip`（由 `_dev/build.py` 生成），不是仓库 ZIP 下载。

## 环境

- Typecho **1.2.x**（写法对着 1.2.1 源码核实过：`index.php:begin` 钩子、`admin/menu.php:navBar`、
  `Widget_Options` 每请求摊平 `theme:*` 配置的行为）
- PHP **7.4+**（主题与插件都没有 PHP 8 专属语法）
- 插件是可选的。不装插件就没有健康记录页的数据、没有接口、没有后台「主题管理」页，
  主题其余部分照常工作。

---

## 特性

### 设计系统
- 单一墨色 + 透明度派生的层级令牌，浅色 103 条 / 深色 66 条，两边色彩令牌一一对齐。
- 根字号旋钮（rem 化）：密度是全局可调的一个值，**移动端不降字号**，只收留白。
- 顶栏 `backdrop-filter` 玻璃化；主题初始化脚本前置，深色模式无闪烁。
- 完整 `aria-*`、`sr-only` 辅助文本，尊重 `prefers-reduced-motion`。

### 首页
- masthead 头图 + 站名 + 座右铭，上面自动压一层渐变罩保证文字读得清。
- 信息流封面卡（缩略图 + 阅读时长 + 最近评论预览）、吸顶分类药丸条，溢出分类收进「更多」面板
  （支持方向键漫游）。
- 命中「动态分类」的文章渲染成微博式动态卡：不显示标题、正文原地铺开、超过 6 行折叠出「全文」。
- 下一页预取：滚动接近底部时提前取好下一页，翻页不留白屏。

### 文章页
- hero 头图 + 正文排版（二级标题下划线、引用块、表格、脚注、图片圆角、嵌套列表）。
- highlight.js 高亮、红绿灯圆点钉住横向滚动、复制按钮不随内容滚、无语言代码块浅色兜底。
- 图片灯箱、回到顶部、相关文章、文末版权卡、上下篇与标签。
- **失效图自动顶替**：图挂了不留浏览器碎图标，换成主题自带的占位图说明「图片资料已失效」。
  圆形头像换中性剪影、顶栏 logo 换回首字母块；没被链接包住的失效图点一下会拿原地址重试。
- 评论区：表单 + 嵌套回复 + 待审状态 + 评论内独立代码块样式。

### 右栏
- 双列 `card-widget` 骨架：月历、那年今日、分类、标签、目录、近期评论、站点信息；
  其中分类卡是可选的（后台开关，默认关——分类已经在信息流顶上那条吸顶药丸条里出现过一次）。
- 尾部 sticky，滚动时跟着走。
- 重查询（分类统计、那年今日等）**按天缓存**到 `usr/uploads/noline_cache`，发新文章会主动失效。

### 页面模板
| 模板 | 用途 |
|---|---|
| `dcument.php` | 归档页，一次列全文章（代价：查询数 O(文章数)，文章上千会慢） |
| `xiaoshi.php` | 单分类页，靠页面的自定义字段 `category` 指定分类缩略名 |
| `links.php` | 友链页，依赖 Links 插件；没装时只显示提示，不硬编码站点列表 |
| `health.php` | 健康记录页，体重/BMI 折线与统计，数据来自 NolineApi 插件 |
| `404.php` | 带空态插画的错误页 |

### 交互与细节
- 站内跳转是「软」的：淡出 + 顶部进度条 + 内容就绪再揭示，不白屏硬切，也没上 PJAX。
- 搜索快捷键 `⌘K` / `Ctrl+K` / `/`，按断点显示不同的角标文案。
- 各处空态有自绘图标 + 文案（首页 / 归档 / 健康页各一枚），不是一行灰字；404 页有插画。

### SEO 与性能
- `canonical` + Open Graph + Twitter card + JSON-LD。
- CSS 走 `.min.css`（压缩后 `design.css` 130 KB → 70 KB），带版本号 `?v=x.y.z`  busting。
- 浏览量去重 + 爬虫 UA 过滤，计数落在 `usr/uploads/noline_views/<cid>.txt`。

---

## 安装

1. 下载 Release 附件 `noline.zip`（或在仓库里执行 `python3 _dev/build.py`），解压到
   `usr/themes/noline`。
2. 后台「控制台 → 主题」启用 Noline，到「设置外观」里填这 14 项：
   站点头像地址、博主名字、座右铭、首页头图、强调色、动态分类、导航图标、首页公告、
   右栏分类卡、GitHub 显示名、GitHub 链接、Bilibili 显示名、Bilibili 链接、站点底部版权。
3. **`views/` 目录别删**：里面是历史浏览量的兜底文件。新计数写在
   `usr/uploads/noline_views/`，找不到就读 `views/`，删了全站计数会从 0 重来。

后台「设置外观」的 14 项就是接口能读写的全部字段，一一对应，没有隐藏项。

## 可选：NolineApi 插件

1. 把 `plugins/NolineApi` 整个目录拷到 `usr/plugins/`。
2. 后台「插件」里启用，填接口密钥（明文存即可，接口靠请求头 `X-Noline-Key` 校验）。
3. **启用后请「停用 → 再启用」一次**：后台顶栏的「主题管理」链接挂在 `admin/menu.php:navBar`
   钩子上，编辑器的图床面板挂在 `admin/write-post.php:bottom`、`write-page.php:bottom` 上，
   老版本激活时注册过的那几条不会因为升级而补上。
4. 后台顶栏点「主题管理」，那一页有：14 项主题设置的可视化编辑、接口状态与自检、
   GitHub 更新与回滚、接入 Cloudreve 的图床上传。
5. **主题自动更新默认开着**：后台路过任意一个页面时查一次（结论缓存 6 小时，两轮之间至少隔
   1 小时），发现 Release 里有新版本就当场下载、验收、把旧主题整份挪进 `usr/uploads/noline_backup`
   再让新版本顶上，不需要点任何东西；只有管理员登录的后台请求会触发。交换那几十微秒里撞上的
   前台请求会吃到一次错误，中途被打断（超时/断电）会自己把备份搬回原位，历史版本全留在备份目录里
   可回滚。不放心就在插件设置里改成「只提示，我自己点」。**它不判断 Release 本身是好是坏**，
   发布前自己验一遍。
6. 图床：在插件设置里填 Cloudreve 地址和 **Refresh Token**（登录 Cloudreve 后在浏览器控制台
   执行 `localStorage.getItem('refresh_token')` 取），启用后写文章/写页面的附件面板顶部会多一个
   「图床」框，选文件、粘贴截图或拖图片进去都能上传，传完自动把直链按当前编辑器形态
   （Markdown 或富文本）插进正文，附带「打开 / 复制」两个按钮。
   Typecho 自带的附件上传一点不动，两者并存。
   Cloudreve v4 没有长期个人 token（access 默认 1 小时过期），插件自己拿 Refresh Token 续 access
   并缓存在 `usr/uploads/noline_api/cloudreve.php`；**令牌只在服务端用，浏览器请求先到插件、
   由插件加上 `Authorization` 再转发给 Cloudreve**，所以这条链路不要求同源以外的任何凭据。

接口端点（细节全部在 `APP-API-SPEC.md`）：

```
GET    /api/ping                  连通性自检，不需要密钥
GET    /api/theme                 站点门面 + 14 项主题配置 + 由强调色推出的配色
PUT    /api/theme                 写主题配置（另一个开关控制，body 只带要改的键）
GET    /api/health                体重概览：最新值 / BMI / 统计 / 趋势 / 目标
GET    /api/health/records        明细，limit ≤ 366
POST   /api/health/records        写入单条或 {"records":[…]} 批量
DELETE /api/health/records?date=  删除某天记录
```

`POST /index.php?noline_api=upload` 是图床上传，走后台登录态 + 编辑器令牌，不吃 `X-Noline-Key`，
只给浏览器用，所以不写进 `APP-API-SPEC.md`。

数据落在 `usr/uploads/noline_api/store.php`，上限 3660 条。

---

## 开发

`_dev/` 里的脚本是这个仓库的门槛，都不依赖 PHP 或 Node 环境：

| 脚本 | 拦的是什么 |
|---|---|
| `phpcheck.py` | 插件五个文件的括号配对、文件头守卫、`self::` 指向的方法是否存在、私有方法漏调用、**跨类读 `private` 成员/常量**（PHP 里是 fatal 不是警告）、**字符串里套同种引号**（引号数量照样是偶数、括号照样配平，只有走词法才看得见）、`_t()` 占位符缺参、**主题字段表与 `functions.php` 的 14 项是否漂移** |
| `structure.py` | 模板 PHP/HTML 平衡、文档外壳、页面骨架、图标引用、死函数、重复声明 |
| `classcheck.py` | 模板在用的 class 有没有样式、样式表里有没有解释不了的死规则（双向） |
| `jscheck.py` | `main.js` 括号平衡、未定义引用、JS 钩子与模板 id/class 一一对应、CSS 花括号 |
| `iconcheck.py` | 图标 path 存在性与 viewBox 越界 |
| `pathcheck.py` | 模板里写死的资源路径是否有效 |
| `tokens.py` / `remify.py` | 令牌是否成对、字号是否 rem 化 |
| `minify.py` | CSS 压缩，并验证只删了注释和空白 |
| `mkpreview.py` | 生成静态预览夹具（`_preview/`，不外发） |
| `publish_release.py` | 把 `dist/noline.zip` 挂成 Release 附件（复用/新建 Release、删同名旧附件、回读校验大小）。**tag 和包内 `NOLINE_VERSION` 不一致直接失败** |
| `build.py` | 打发布包；顶层出现白名单之外的目录**直接构建失败**，`_dev/_preview/_trash/dist/.github` 永远进不去 |

改过 `css/*.css` 记得重跑 `minify.py`，否则 `build.py` 会拒绝发布（它按内容重压一遍源文件，
和仓库里的 `.min.css` 对不上就失败——不看时间戳，`git clone` 出来的仓库时间戳是随机的）。
版本号同时写在 `functions.php` 的 `NOLINE_VERSION`（资源缓存指纹读它）和 `index.php` 头部注释的
`@version`（Typecho 主题列表读它），`build.py` 会核对两边一致，分家就不打包。

### 发布一个新版本

推 tag 就行，`.github/workflows/release.yml` 会在 Actions 里跑 `build.py` + `publish_release.py`，
建好 Release 并挂上 `noline.zip`。不需要本地装 PHP，也不需要把令牌交给任何人。

```bash
python3 _dev/build.py            # 本地先过一遍门槛
git tag -a 1.20.1 -m "1.20.1" && git push origin 1.20.1
```

tag 名用 `1.20.1` 或 `v1.20.1`（工作流只认这两种形状），且必须等于 `NOLINE_VERSION`。
后台「主题管理 → 主题更新」读的是 `releases/latest` 那个附件，所以**没有 `noline.zip` 的 Release
等于没发布**：页面会报「这个 Release 没有 noline.zip 附件」。
也可以不推 tag，直接在 Actions 页手动运行工作流、手填 tag。

## 已知取舍

- `notice`（首页公告）和 `logoFooter`（底部版权）**原样存 HTML，不做标签清洗**，接口写入也一样。
  这是有意的：公告里要能放链接和样式。代价是拿到接口密钥的人能往前台注入任意脚本，
  所以 `PUT /api/theme` 有独立开关，`APP-API-SPEC.md` 里也把它标成高危能力。
- 自动更新只认「Release 里的版本号比本机高」，不判断那个包本身是不是坏的；装上就生效，靠备份+回滚兜底。
- 归档页一次列全部文章，查询数是 O(文章数)。
- 图床只实现「走 Cloudreve 服务端」的上传策略。如果实例的存储策略配成浏览器直传第三方对象存储
  （`file/upload` 会返回非空 `upload_urls`），插件会明确拒绝并提示去后台把该策略改成云端/本地，
  而不是自作主张地去接一套签名协议。
- 不做「首屏关键 CSS 内联 + 其余异步」：异步补 CSS 会重新引入首屏白闪，这一版宁可留全量阻塞 CSS。

---

##### 如果你愿意请我喝一杯可乐，我会感到非常荣幸
![](https://github.com/qine233/NoLineLite/blob/main/pay.png)
