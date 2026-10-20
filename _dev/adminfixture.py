#!/usr/bin/env python3
"""一次性夹具：把 Typecho 后台的骨架 + 我们的皮肤拼成静态页，给 headless Chrome 拍。

不发版的临时东西，写在 /tmp 下。核心样式表用 _typecho-ref/ 里那三份原版，
这样拍出来的图能同时验证「皮肤压得住核心」和「压不住的地方在哪」。
"""
import json
import sys
from pathlib import Path

ROOT = Path('/Users/zhouzhou/Documents/Qoder/2026-10-06/f69e2d5a')
REF = ROOT / '_typecho-ref'
ASSETS = ROOT / 'plugins/NolineApi/assets'
OUT = Path('/tmp/nl-admin')

CSS = ['admin-normalize.css', 'admin-grid.css', 'admin-style.css']

CR = {
    'ready': True,
    'endpoint': 'about:blank',
    'token': 'fixture-token',
    'allow': 'png,jpg,jpeg,gif,webp,mp4,pdf,zip',
    'paste': True,
    'tplImg': '<img src="{u}" alt="{n}">',
    'tplFile': '<a href="{u}">{n}</a>',
    'text': {
        'title': '图床 · Cloudreve',
        'pick': '选择文件',
        'hint': '拖进来也可以；单个不超过 20 MB，传完自动把直链插到光标处。',
        'notready': '图床没开启，或者实例地址 / 邮箱 / 密码还没填全。补齐后刷新这一页就能传。',
        'link': '直链',
        'copy': '复制',
        'copied': '已复制',
        'network': '传不出去：浏览器连不上站点。',
        'interrupted': '这次上传没走完。',
    },
}

NAV = '''
<div class="typecho-head-nav clearfix" role="navigation">
    <button class="menu-bar">菜单</button>
    <nav id="typecho-nav-list">
        <ul class="root">
            <li class="parent"><a href="###">首页</a></li>
        </ul>
        <ul class="root">
            <li class="parent"><a href="###">文件</a>
                <ul class="child">
                    <li class="return"><a href="###">文件</a></li>
                    <li><a href="###">文章</a></li>
                    <li class="focus"><a href="###">页面</a></li>
                    <li><a href="###">评论</a></li>
                </ul>
            </li>
        </ul>
        <ul class="root">
            <li class="parent"><a href="###">设置</a></li>
        </ul>
        <ul class="root">
            <li class="parent"><a href="###">外观</a></li>
        </ul>
        <ul class="root">
            <li class="parent"><a href="###">插件</a></li>
        </ul>
    </nav>
    <div class="operate">
        <a href="###">主题管理</a>
        __TOGGLE__
        <a href="###" class="author">州州</a><a class="exit" href="###">登出</a><a href="###">网站</a>
    </div>
</div>
'''

TOGGLE = ('<button type="button" id="nl-theme-toggle" class="nl-theme-toggle" aria-pressed="false"'
          ' title="切换到深色" data-label-dark="切换到深色" data-label-light="切换到浅色">'
          '<svg class="nl-ico-light" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"'
          ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">'
          '<circle cx="12" cy="12" r="4.2" fill="currentColor" stroke="none"/>'
          '<path d="M12 2.6v2.4M12 19v2.4M2.6 12h2.4M19 12h2.4M5.3 5.3l1.7 1.7M17 17l1.7 1.7'
          'M18.7 5.3L17 7M7 17l-1.7 1.7"/></svg>'
          '<svg class="nl-ico-dark" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"'
          ' fill="currentColor"><path d="M20.5 14.6A8.6 8.6 0 0 1 9.4 3.5a8.7 8.7 0 1 0 11.1 11.1z"/>'
          '</svg></button>')


def shell(title, body, dark=False, accent=True):
    links = ''.join('<link rel="stylesheet" href="%s">' % f for f in CSS)
    style = '<link rel="stylesheet" href="admin.css">'
    acc = ('<style id="nl-admin-accent">:root{--nl-accent-user:#425aef;--nl-accent-user-rgb:66, 90, 239;'
           '--nl-accent-user-hover:#3549c9;--nl-accent-user-dark:#8a9bff;--nl-accent-user-dark-rgb:138, 155, 255;'
           '--nl-accent-user-dark-hover:#a3b2ff}</style>') if accent else ''
    boot = ('<script>document.documentElement.setAttribute("data-nl-theme","%s");</script>'
            % ('dark' if dark else 'light'))
    return ('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width, initial-scale=1">'
            '<title>%s</title>%s%s%s%s'
            '<script>window.NL_CR=%s;</script>'
            '<script src="admin.js" defer></script>'
            '</head><body>%s</body></html>'
            % (title, boot, links, acc, style, json.dumps(CR, ensure_ascii=False), body))


# 编辑器一页拍不下两种 tab 状态（核心用 display:none 切），所以同一个骨架出两页：
# write.html 看「选项」，writefiles.html 看「附件 + 图床面板」。
WRITE_HTML = NAV.replace('__TOGGLE__', TOGGLE) + '''
<div class="main">
  <div class="body container">
    <div class="typecho-page-title"><h2>发布文章</h2></div>
    <div class="row typecho-page-main typecho-post-area" role="form">
      <form method="post" name="write_post">
        <div class="col-mb-12 col-tb-9" role="main">
          <p class="title">
            <input type="text" id="title" name="title" class="w-100 text title" placeholder="在此填写文章标题">
          </p>
          <p class="mono url-slug">
            <label for="slug" class="sr-only">网址缩略名</label>
            https://ui.zhouzhou.work/<input type="text" id="slug" name="slug" class="mono" value="admin-skin">
          </p>
          <div class="editor wmd-button-row" id="wmd-button-bar">
            <div class="wmd-edittab"><a href="###" class="active">撰写</a><a href="###">预览</a></div>
            <ul class="wmd-row">
              <li class="wmd-button" id="wmd-bold-button"><span class="editor-editor-bold"></span></li>
              <li class="wmd-button" id="wmd-italic-button"><span class="editor-editor-italic"></span></li>
              <li class="wmd-button" id="wmd-link-button"><span class="editor-editor-link"></span></li>
              <li class="wmd-button" id="wmd-quote-button"><span class="editor-editor-quote"></span></li>
              <li class="wmd-button" id="wmd-code-button"><span class="editor-editor-code"></span></li>
              <li class="wmd-spacer"><span></span></li>
              <li class="wmd-button" id="wmd-fullscreen-button"><span class="editor-editor-fullscreen"></span></li>
            </ul>
          </div>
          <div id="wmd-preview" class="wmd-preview">
            <h2>Markdown 实时预览</h2>
            <p>正文里的 <code>inline code</code> 和一段代码：</p>
            <pre><code>function noline_mix($rgb, $target, $ratio) {
    return $out;
}</code></pre>
            <blockquote>引用一行：深色下要还能读出层次。</blockquote>
            <table><thead><tr><th>键</th><th>值</th></tr></thead>
            <tbody><tr><td>accentColor</td><td>#425aef</td></tr></tbody></table>
            <hr>
            <p class="summary">摘要之后的内容</p>
          </div>
          <p>
            <label for="text" class="sr-only">文章内容</label>
            <textarea id="text" name="text" class="w-100 mono" style="height:320px">正文从这里开始。

粘贴一张截图（Ctrl+V）就会走图床，传完把直链插回光标处。</textarea>
          </p>
          <section id="custom-field" class="typecho-post-option">
            <label id="custom-field-expand" class="typecho-label"><a href="##"><i class="i-caret-right"></i> 自定义字段</a></label>
            <table class="typecho-list-table mono">
              <colgroup><col width="20%"><col width="15%"><col width="55%"><col width="10%"></colgroup>
              <tbody>
                <tr><td>封面</td><td>文本</td><td><input type="text" value="https://cdn/1.jpg" class="w-100"></td><td><a href="###" class="btn btn-mini">删除</a></td></tr>
                <tr><td><input type="text" value="cover"></td><td><select><option>文本</option></select></td><td><input type="text" class="w-100"></td><td><a href="###" class="btn btn-mini">删除</a></td></tr>
              </tbody>
            </table>
            <p><a href="###" class="btn btn-s">增加自定义字段</a></p>
          </section>
          <p class="submit clearfix">
            <span class="left"><button type="button" id="btn-cancel-preview" class="btn"><i class="i-caret-left"></i> 取消预览</button></span>
            <span class="right">
              <button type="button" id="btn-preview" class="btn"><i class="i-exlink"></i> 预览文章</button>
              <button type="submit" id="btn-save" class="btn">保存草稿</button>
              <button type="submit" id="btn-submit" class="btn primary">发布文章</button>
            </span>
          </p>
        </div>

        <div id="edit-secondary" class="col-mb-12 col-tb-3" role="complementary">
          <ul class="typecho-option-tabs clearfix">
            <li class="__ADV_ON__ w-50"><a href="#tab-advance">选项</a></li>
            <li class="__FIL_ON__ w-50"><a href="#tab-files" id="tab-files-btn">附件</a></li>
          </ul>

          <div id="tab-advance" class="tab-content" __ADV_HIDE__>
            <section class="typecho-post-option">
              <label for="date" class="typecho-label">发布日期</label>
              <p><input class="typecho-date w-100" type="text" id="date" value="2026-10-20 23:59"></p>
            </section>
            <section class="typecho-post-option category-option">
              <label class="typecho-label">分类</label>
              <ul>
                <li><input type="checkbox" id="category-1" checked><label for="category-1">日记</label></li>
                <li><input type="checkbox" id="category-2"><label for="category-2">技术</label></li>
              </ul>
            </section>
            <section class="typecho-post-option">
              <label for="tags" class="typecho-label">标签</label>
              <p><input id="tags" name="tags" type="text" value="typecho, 主题" class="w-100 text"></p>
            </section>
            <button type="button" id="advance-panel-btn" class="btn btn-xs">高级选项 <i class="i-caret-down"></i></button>
            <div id="advance-panel">
              <section class="typecho-post-option visibility-option">
                <label for="visibility" class="typecho-label">公开度</label>
                <p><select id="visibility" name="visibility"><option value="public">公开</option><option value="private">私密</option><option value="hidden">隐藏</option></select></p>
              </section>
              <section class="typecho-post-option">
                <label for="password" class="typecho-label">密码保护</label>
                <p><input id="password" name="password" type="text" class="w-100 text"></p>
              </section>
              <section class="typecho-post-option">
                <label for="description" class="typecho-label">摘要</label>
                <p><textarea id="description" class="w-100" rows="3"></textarea></p>
              </section>
            </div>
          </div>

          <div id="tab-files" class="tab-content" __FIL_HIDE__>
            <div id="upload-panel" class="p">
              <div class="upload-area" draggable="true">拖放文件到这里<br>或者 <a href="###" class="upload-file">选择文件上传</a></div>
              <ul id="file-list">
                <li>
                  <a class="insert" href="###">cover-2x.jpg</a>
                  <div class="info">248 Kb <a class="file" href="###"><i class="i-edit"></i></a> <a class="delete" href="###"><i class="i-delete"></i></a></div>
                </li>
                <li>
                  <a class="insert" href="###">walk.mp4</a>
                  <div class="info">1024 Kb <a class="file" href="###"><i class="i-edit"></i></a> <a class="delete" href="###"><i class="i-delete"></i></a></div>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
'''

def write_page(advance):
    return (WRITE_HTML
            .replace('__ADV_ON__', 'active' if advance else '')
            .replace('__FIL_ON__', '' if advance else 'active')
            .replace('__ADV_HIDE__', '' if advance else 'style="display:none"')
            .replace('__FIL_HIDE__', 'style="display:none"' if advance else ''))

WRITE = write_page(True)
WRITE_FILES = write_page(False)

# 主题管理页：整页都是 .nl-* 自己定义的，皮肤关掉时它也还得能读
SETTINGS = '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>主题管理</h2></div>
  <div class="row typecho-page-main" role="main"><div class="col-mb-12">
    <div class="nl-bar">
      <a class="btn btn-s" href="###">返回后台</a>
      <a class="btn btn-s" href="###">看前台</a>
      <span class="nl-site">州州的小站</span>
    </div>
    <div class="nl-msg nl-msg-success">主题设置已保存，前台刷新即生效。</div>
    <h3 class="nl-h3">主题设置</h3>
    <p class="nl-lead">和「控制台 → 设置外观 → 设置外观」是同一份数据，改哪边都一样。</p>
    <form class="nl-form" method="post">
      <table class="nl-table"><tbody>
        <tr>
          <td class="nl-key"><label for="accentColor">强调色</label><code>accentColor</code></td>
          <td class="nl-val"><input type="text" id="accentColor" value="#425aef">
            <span class="nl-swatch" data-for="accentColor"></span>
            <p class="nl-hint">十六进制，带不带 # 都行。留空用默认靛蓝。</p></td>
        </tr>
        <tr>
          <td class="nl-key"><label for="diaryCategory">动态分类</label><code>diaryCategory</code></td>
          <td class="nl-val"><input type="text" id="diaryCategory" value="diary">
            <p class="nl-hint">命中这个分类的文章当动态渲染。</p></td>
        </tr>
        <tr>
          <td class="nl-key">公告</td>
          <td class="nl-val"><textarea rows="3">&lt;p&gt;晚上好&lt;/p&gt;</textarea></td>
        </tr>
      </tbody></table>
      <p class="nl-actions"><button type="submit" class="btn btn-l primary">保存主题设置</button>
        <span class="nl-note">含 HTML 的两项按原样保存。</span></p>
    </form>

    <h3 class="nl-h3">接口</h3>
    <div class="nl-chips">
      <span class="nl-chip nl-chip-ok">接口密钥已设置</span>
      <span class="nl-chip nl-chip-warn">接口写主题设置已禁止</span>
    </div>
    <p class="nl-lead"><code>GET /api/theme</code> 读全部配置。</p>

    <h3 class="nl-h3">后台挂钩自检</h3>
    <div class="nl-chips">
      <span class="nl-chip nl-chip-ok">index.php:begin 已登记</span>
      <span class="nl-chip nl-chip-ok">admin/menu.php:navBar 已登记</span>
      <span class="nl-chip nl-chip-ok">admin/header.php:header 已登记</span>
      <span class="nl-chip nl-chip-muted">后台皮肤开</span>
      <span class="nl-chip nl-chip-ok">图床设置已填齐</span>
      <span class="nl-chip nl-chip-bad">钩子没登记时是这一枚</span>
    </div>
    <p class="nl-lead">都走 <code>admin/header.php:header</code> 这一条钩子。</p>
    <p class="nl-note">在线更新换的是文件，换不掉库里那份登记。</p>

    <h3 class="nl-h3">在线更新</h3>
    <div class="nl-ver">
      <span><b>1.29.4</b><em>主题</em></span>
      <span class="nl-ver-arrow">→</span>
      <span><b>1.29.6</b><em>Release · 2 小时前</em></span>
      <span class="nl-inline"><button class="btn btn-s primary" type="button">立即更新</button></span>
    </div>
    <details class="nl-log"><summary>上一次换装的记录</summary><pre>备份 usr/themes/noline → …
新目录顶上 → 完成</pre></details>

    <h3 class="nl-h3">Cloudreve 图床</h3>
    <table class="nl-list"><tbody>
      <tr><td><b>已连接</b><em>会话有效，剩余 41 分钟</em></td><td class="nl-ops"><form><button class="btn btn-s" type="submit">测试连接</button></form></td></tr>
      <tr><td><b>存放目录</b><em>noline</em></td><td class="nl-ops"><form><button class="btn btn-s btn-warn" type="submit">退出登录</button></form></td></tr>
    </tbody></table>
  </div></div></div></div>
'''

LIST = '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>文章管理 <a class="btn btn-s" href="###">写文章</a></h2></div>
  <div class="row typecho-page-main" role="main"><div class="col-mb-12">
    <form method="post">
      <div class="typecho-table-wrap">
        <table class="typecho-list-table">
          <colgroup><col width="20"><col width="45%"><col width="25%"><col width="10%"><col width="*"></colgroup>
          <thead><tr><th>&nbsp;</th><th>标题</th><th>分类</th><th>评论</th><th>日期</th></tr></thead>
          <tbody>
            <tr class="checked">
              <td><input type="checkbox" checked></td>
              <td><a href="###">后台皮肤这一版做了什么</a> <a class="operate-action" href="###">编辑</a></td>
              <td>技术</td><td>12</td><td>2026-10-20</td>
            </tr>
            <tr>
              <td><input type="checkbox"></td>
              <td><a href="###">日记为什么不是文章</a> <a class="operate-action" href="###">编辑</a></td>
              <td>日记</td><td>3</td><td>2026-10-18</td>
            </tr>
            <tr class="deactivate">
              <td><input type="checkbox"></td>
              <td><a href="###">Cloudreve 直链的取法</a> <span class="tip">隐藏</span></td>
              <td>技术</td><td>0</td><td>2026-10-12</td>
            </tr>
            <tr><td colspan="5">没有更多文章了</td></tr>
          </tbody>
        </table>
      </div>
      <p class="table-actions">
        <label>全选 <input type="checkbox"></label>
        <select><option>移动到</option><option>删除</option></select>
        <button class="btn btn-s" type="submit">执行</button>
      </p>
      <ul class="typecho-pager"><li><a class="prev" href="###">上一页</a></li><li><a class="current" href="###">1</a></li><li><a href="###">2</a></li><li><a class="next" href="###">下一页</a></li></ul>
    </form>
  </div></div></div></div>
'''

PLUGINS = '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>插件管理</h2></div>
  <div class="row typecho-page-main" role="main"><div class="col-mb-12 typecho-list">
    <h4 class="typecho-list-table-title">启用的插件</h4>
    <div class="typecho-table-wrap">
      <table class="typecho-list-table">
        <colgroup><col width="25%"><col width="45%"><col width="8%"><col width="10%"><col width=""></colgroup>
        <thead><tr><th>名称</th><th>描述</th><th>版本</th><th>作者</th><th>操作</th></tr></thead>
        <tbody>
          <tr id="plugin-NolineApi">
            <td>Noline 对外接口</td>
            <td>把主题设置、健康数据和买菜流水以 JSON 接口暴露出去。</td>
            <td>1.7.13</td>
            <td><a href="###">qine233</a></td>
            <td><a href="###">设置</a> &bull; <a href="###">禁用</a></td>
          </tr>
        </tbody>
      </table>
    </div>
    <h4 class="typecho-list-table-title">禁用的插件</h4>
    <div class="typecho-table-wrap">
      <table class="typecho-list-table deactivate">
        <colgroup><col width="25%"><col width="45%"><col width="8%"><col width="10%"><col width=""></colgroup>
        <thead><tr><th>名称</th><th>描述</th><th>版本</th><th>作者</th><th>操作</th></tr></thead>
        <tbody>
          <tr id="plugin-HelloWorld">
            <td>Hello Word</td>
            <td>一个示例插件。</td>
            <td>1.0.0</td>
            <td><a href="###">Typecho</a></td>
            <td><a href="###">启用</a> &bull; <a href="###">删除</a></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div></div></div></div>
'''

MESSAGES = '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>评论管理</h2></div>
  <div class="row typecho-page-main" role="main"><div class="col-mb-12">
    <div class="message popup error" style="position:static;display:block"><p>安全令牌对不上。多半是页面开着太久或者换了登录状态。</p></div>
    <div class="message popup success" style="position:static;display:block"><p>评论已通过。</p></div>
    <div class="message popup notice" style="position:static;display:block"><p>你的 Typecho 有新版本可以更新。</p></div>
    <div class="typecho-table-wrap">
      <table class="typecho-list-table">
        <thead><tr><th>&nbsp;</th><th>作者</th><th>评论内容</th><th>文件</th><th>日期</th><th>&nbsp;</th></tr></thead>
        <tbody>
          <tr>
            <td><input type="checkbox"></td>
            <td>访客 <span class="tip">visitor@example.com</span></td>
            <td class="title">这个后台看着舒服多了，卡片化的列表尤其好用。</td>
            <td><a href="###">后台皮肤这一版做了什么</a></td>
            <td>2026-10-20</td>
            <td><a class="hidden-by-mouse" href="###">通过</a></td>
          </tr>
          <tr class="checked">
            <td><input type="checkbox" checked></td>
            <td>广告 <span class="tip">spam@example.com</span></td>
            <td class="title">买我东西，链接在正文里。</td>
            <td><a href="###">日记为什么不是文章</a></td>
            <td>2026-10-19</td>
            <td><a class="hidden-by-mouse" href="###">回复</a></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div></div></div></div>
'''

DASHBOARD = NAV.replace('__TOGGLE__', TOGGLE) + '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>首页</h2></div>
  <div class="row typecho-page-main" role="main">
    <div class="col-mb-12 col-tb-8">
      <div id="typecho-welcome">
        <p class="welcome-board"><em>欢迎回来。</em>这是你第 128 次登录。</p>
        <ul id="start-link">
          <li><a class="i-edit" href="###">写文章</a></li>
          <li><a class="i-exlink" href="###">查看网站</a></li>
          <li><a class="i-upload" href="###">上传附件</a></li>
        </ul>
        <p>最近 7 天有 3 条评论在等你处理。</p>
      </div>
      <div class="typecho-list-table">
        <table class="typecho-list-table"><thead><tr><th>标题</th><th>时间</th></tr></thead>
        <tbody><tr><td><a href="###">今天的风向</a></td><td>10-20</td></tr>
        <tr><td><a href="###">备份与回滚</a></td><td>10-19</td></tr></tbody></table>
      </div>
    </div>
    <div class="col-mb-12 col-tb-4" role="sidebar">
      <section class="container">
        <h3>快捷操作</h3>
        <ul class="post-list">
          <li><a href="###">写文章</a></li>
          <li><a href="###">管理分类</a></li>
          <li><a href="###">附件管理</a></li>
        </ul>
      </section>
      <section class="container empty">
        <h3>来自第三方</h3>
        <p>没有内容。</p>
      </section>
    </div>
  </div>
</div></div>
'''

LOGIN = '''
<div class="typecho-login-wrap">
  <div class="typecho-login">
    <h1><a href="###" class="i-logo">Typecho</a></h1>
    <form method="post" name="login" role="form">
      <p><input type="text" id="name" name="name" class="text-l w-100" placeholder="用户名" autofocus></p>
      <p><input type="password" id="password" name="password" class="text-l w-100" placeholder="密码"></p>
      <p class="submit"><button class="btn btn-l w-100 primary" type="submit">登录</button></p>
      <p><label><input type="checkbox" class="checkbox" value="1"> 下次自动登录</label></p>
    </form>
    <p class="more-link"><a href="###">返回首页</a> &bull; <a href="###">用户注册</a></p>
  </div>
</div>
'''

DIALOG = NAV.replace('__TOGGLE__', TOGGLE) + '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>写文章 · 插入链接</h2></div>
  <p>下面这块是编辑器弹出的输入框（核心写死 #F6F6F3 底），深色下它得跟着卡片走。</p>
  <div class="wmd-prompt-dialog" style="position:static;margin:0">
    <p>请输入链接地址</p>
    <form method="post">
      <input type="text" value="https://ui.zhouzhou.work/">
      <button class="btn primary" type="button">确定</button>
      <button class="btn" type="button">取消</button>
    </form>
  </div>
  <div class="resize"><i></i><i></i><i></i></div>
</div></div>
'''

# 发布时间那块是 jQuery UI 弹出来的浮层，核心整块写死白底；摊平成一页好拍
DATE = NAV.replace('__TOGGLE__', TOGGLE) + '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>发布时间选择器</h2></div>
  <div class="row typecho-page-main" role="form"><div class="col-mb-12 col-tb-3">
    <div id="ui-datepicker-div" style="display:block">
      <div class="ui-datepicker-header">
        <a class="ui-datepicker-prev" href="###">‹</a>
        <a class="ui-datepicker-next" href="###">›</a>
        <div class="ui-datepicker-title"><span>10 月</span> 2026</div>
      </div>
      <table class="ui-datepicker-calendar">
        <thead><tr><th><span>日</span></th><th><span>一</span></th><th><span>二</span></th><th><span>三</span></th></tr></thead>
        <tbody><tr>
          <td class="ui-datepicker-week-end"><a href="###">18</a></td>
          <td><a href="###">19</a></td>
          <td class="ui-datepicker-today"><a href="###">20</a></td>
          <td class="ui-datepicker-current-day"><a href="###">21</a></td>
        </tr></tbody>
      </table>
      <div class="ui-timepicker-div"><dl>
        <dt>时间</dt><dd>23:59</dd>
        <dt>时</dt><dd><div class="ui-slider ui-slider-horizontal"><div class="ui-slider-handle"></div></div></dd>
      </dl></div>
      <div class="ui-datepicker-buttonpane">
        <button type="button" class="ui-datepicker-current">现在</button>
        <button type="button" class="ui-datepicker-close">关闭</button>
      </div>
    </div>
  </div></div></div></div>
'''

TAGS = NAV.replace('__TOGGLE__', TOGGLE) + '''
<div class="main"><div class="body container">
  <div class="typecho-page-title"><h2>标签</h2></div>
  <div class="row typecho-page-main" role="main"><div class="col-mb-12">
    <form method="post">
      <ul class="tag-list">
        <li class="size-5 checked"><input type="checkbox" checked><label>typecho</label>
          <span class="description">12 篇文章</span> <a class="tag-edit-link" href="###">编辑</a></li>
        <li class="size-10"><input type="checkbox"><label>php</label>
          <span class="description">8 篇文章</span></li>
        <li class="size-20"><input type="checkbox"><label>css</label>
          <span class="description">5 篇文章</span></li>
        <li class="size-30"><input type="checkbox"><label>设计</label>
          <span class="description">3 篇文章</span></li>
        <li class="size-0"><input type="checkbox"><label>随笔</label>
          <span class="description">1 篇文章</span></li>
      </ul>
      <p class="typecho-list-operate">
        <label>全选 <input type="checkbox"></label>
        <button class="btn btn-s" type="submit">删除选中</button>
      </p>
    </form>
    <h3 class="typecho-list-table-title">分类</h3>
    <div class="category-option">
      <ul><li><input type="checkbox"><label>日记</label></li>
          <li><input type="checkbox"><label>技术</label></li></ul>
    </div>
  </div></div></div></div>
'''

PAGES = {
    'write.html': shell('发布文章', WRITE),
    'write-dark.html': shell('发布文章', WRITE, dark=True),
    'writefiles.html': shell('发布文章', WRITE_FILES),
    'writefiles-dark.html': shell('发布文章', WRITE_FILES, dark=True),
    'settings.html': shell('主题管理', SETTINGS),
    'settings-dark.html': shell('主题管理', SETTINGS, dark=True),
    'list.html': shell('文章管理', LIST),
    'list-dark.html': shell('文章管理', LIST, dark=True),
    'plugins.html': shell('插件管理', PLUGINS),
    'comments.html': shell('评论管理', MESSAGES),
    'comments-dark.html': shell('评论管理', MESSAGES, dark=True),
    'dashboard.html': shell('首页', DASHBOARD),
    'dashboard-dark.html': shell('首页', DASHBOARD, dark=True),
    'login.html': shell('登录', LOGIN),
    'login-dark.html': shell('登录', LOGIN, dark=True),
    'dialog.html': shell('插入链接的对话框', DIALOG),
    'dialog-dark.html': shell('插入链接的对话框', DIALOG, dark=True),
    'tags.html': shell('标签', TAGS),
    'tags-dark.html': shell('标签', TAGS, dark=True),
    'date.html': shell('发布时间', DATE),
    'date-dark.html': shell('发布时间', DATE, dark=True),
}


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for name, html in PAGES.items():
        (OUT / name).write_text(html, encoding='utf-8')
    (OUT / 'admin.css').write_text((ASSETS / 'admin.css').read_text(encoding='utf-8'), encoding='utf-8')
    (OUT / 'admin.js').write_text((ASSETS / 'admin.js').read_text(encoding='utf-8'), encoding='utf-8')
    for name in CSS:
        (OUT / name).write_text((REF / name).read_text(encoding='utf-8'), encoding='utf-8')
    print('夹具：%s（%d 页）' % (OUT, len(PAGES)))


if __name__ == '__main__':
    sys.exit(main())
