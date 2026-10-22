#!/usr/bin/env python3
"""一次性夹具：把 Typecho 后台的骨架 + 我们的皮肤拼成静态页，给 headless Chrome 拍。

不发版的临时东西，写在 /tmp 下。核心样式表用 _typecho-ref/ 里那几份原版，
这样拍出来的图能同时验证「皮肤压得住核心」和「压不住的地方在哪」。

核心在 1.3.0 把后台导航整个换了结构（<div>→<header>、ul→menu、.child→嵌套 menu、
<div.operate>→<li.operate>、button.menu-bar→details.menu-bar），
所以同一页要出两种代际：后缀 -13 的那批照 v1.3.0 原版 markup + 原版 style.css 拍。
本地能对着哪个版本复现，皮肤就只对那个版本有效——线上炸过一次就不许再靠猜。
"""
import json
import re
import sys
from pathlib import Path

ROOT = Path('/Users/zhouzhou/Documents/Qoder/2026-10-06/f69e2d5a')
REF = ROOT / '_typecho-ref'
ASSETS = ROOT / 'plugins/NolineApi/assets'
OUT = Path('/tmp/nl-admin')

CSS = {
    '12': ['admin-normalize.css', 'admin-grid.css', 'admin-style.css'],
    '13': ['admin-normalize-13.css', 'admin-grid-13.css', 'admin-style-13.css'],
}

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

NAV12 = '''
<div class="typecho-head-nav clearfix" role="navigation">
    <button class="menu-bar">菜单</button>
    <nav id="typecho-nav-list">
__MENU__
    </nav>
    <div class="operate">
        <a href="###">主题管理</a>
        __TOGGLE__
        <a href="###" class="author">州州</a><a class="exit" href="###">登出</a><a href="###">网站</a>
    </div>
</div>
'''

# 逐字照抄 admin/menu.php（v1.3.0）：汉堡是 <details>，项是 <li>，
# 动作簇从 <div class="operate"> 变成了顶层 <menu> 里的最后一个 <li class="operate">。
NAV13 = '''
<header class="typecho-head-nav" role="navigation">
    <nav>
        <details class="menu-bar">
            <summary>菜单</summary>
        </details>
        <menu>
__MENU__
            <li class="operate">
                <a href="###">主题管理</a>
                __TOGGLE__
                <a href="###" class="author">州州</a><a class="exit" href="###">登出</a><a href="###">网站</a>
            </li>
        </menu>
    </nav>
</header>
'''

MENU_ITEMS = [
    ('控制台', [('首页', True), ('个人设置', False), ('插件', False),
                ('外观', False), ('设置外观', False)], True),
    ('撰写', [('撰写文章', False), ('创建页面', False)], False),
    ('管理', [('文章', False), ('独立页面', False), ('评论', False),
              ('分类', False), ('标签', False), ('文件', False), ('用户', False)], False),
    ('设置', [('基本', False), ('评论', False), ('阅读', False), ('永久链接', False)], False),
]


def _parent(title, children, current):
    """逐字照抄 Widget_Menu::output()（v1.2.1）吐的那一沓。

    两个地方和「看起来应该长这样」不一样，而且都关键：
    focus 挂在 ul.root 上而不是 li.parent 上；ul.child 是 li.parent 的**兄弟**，
    不是它的孩子（<ul> 直接套在 <ul> 里）。夹具当初按语义顺手写成了嵌套，
    于是所有「.child 撑满整条胶囊」的问题在本地一个都复现不出来。
    """
    last = max(k for k, (label, focused) in enumerate(children))
    items = ''.join(
        '<li%s><a href="###">%s</a></li>' % (
            ' class="%s"' % ' '.join(
                c for c in ('focus' if focused else '', 'last' if i == last else '') if c
            ) if (focused or i == last) else '',
            label,
        )
        for i, (label, focused) in enumerate(children)
    )
    return ('<ul class="root%s"><li class="parent"><a href="###">%s</a></li>'
            '<ul class="child">%s</ul></ul>'
            % (' focus' if current else '', title, items))


def _parent13(title, children, current):
    """照抄 Widget_Menu::output()（v1.3.0）：<menu> 嵌在 <li> 里面，
    focus 直接挂在根 <li> 上（1.2 那是挂在 <ul class="root"> 上），
    子项没有 .last、也没有返回项，空的 <menu> 也照样吐出来。"""
    items = ''.join(
        '<li%s><a href="###">%s</a></li>' % (' class="focus"' if focused else '', label)
        for label, focused in children
    )
    return ('<li%s><a href="###">%s</a><menu>%s</menu></li>'
            % (' class="focus"' if current else '', title, items))


def _nav(shape, gen):
    menu = ''.join(_parent13(*row) for row in MENU_ITEMS) if gen == '13' \
        else ''.join(_parent(*row) for row in MENU_ITEMS)
    return shape.replace('__MENU__', menu)


NAV_OF = {'12': _nav(NAV12, '12'), '13': _nav(NAV13, '13')}

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


def shell(title, body, dark=False, accent=True, gen='12'):
    links = ''.join('<link rel="stylesheet" href="%s">' % f for f in CSS[gen])
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
WRITE_BODY = '''
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
          <div class="editor" id="wmd-button-bar">
            <div class="wmd-edittab"><a href="#wmd-editarea" class="active">撰写</a><a href="#wmd-preview">预览</a></div>
            <ul class="wmd-button-row" id="wmd-button-row1">
              <li class="wmd-button" id="wmd-bold-button" style="left:0px"><span class="editor-editor-bold"></span></li>
              <li class="wmd-button" id="wmd-italic-button" style="left:25px"><span class="editor-editor-italic"></span></li>
              <li class="wmd-button" id="wmd-link-button" style="left:50px"><span class="editor-editor-link"></span></li>
              <li class="wmd-button" id="wmd-quote-button" style="left:75px"><span class="editor-editor-quote"></span></li>
              <li class="wmd-button" id="wmd-code-button" style="left:100px"><span class="editor-editor-code"></span></li>
              <li class="wmd-spacer wmd-spacer1" id="wmd-spacer1"><span></span></li>
              <li class="wmd-button" id="wmd-fullscreen-button" style="left:150px"><span class="editor-editor-fullscreen"></span></li>
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
    return (WRITE_BODY
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
      <span><b>1.32.0</b><em>主题</em></span>
      <span class="nl-ver-arrow">→</span>
      <span><b>1.33.0</b><em>Release · 2 小时前</em></span>
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

# ==========================================================================
# 列表页家族：文章 / 评论 / 插件 / 标签
# 骨架逐字照 _typecho-ref/ 里的两代模板抄（manage-posts、manage-comments、
# manage-tags、plugins 各有一份 1.2.1 和一份 1.3.0）。1.2 → 1.3 在每张列表页上
# 都动了四刀，皮肤每一刀都可能接不住，所以两代各出一套正文，不做事后字符串替换：
#   ① .typecho-table-wrap 整层删掉——核心改成给表格自己刷白
#      （.typecho-list-table{background:#FFF;border:30px solid #FFF}）
#   ② 操作条从「div.typecho-list-operate clearfix 套一个 form」换成
#      「form.typecho-list-operate 自己就是操作条」
#   ③ 页签那一排：<div class="clearfix"> 里「所有/我的」挂在
#      <ul class="typecho-option-tabs right"> 上、排在状态页签前面；
#      1.3 变成一条 .typecho-list-operate 里摆两个 .typecho-option-tabs，
#      顺序反过来，右边那一排靠核心的 >*:nth-child(2){justify-content:flex-end} 顶过去
#   ④ 空态从 <td colspan><h6 class="typecho-list-table-title"> 换成 <td class="none">
#   另有两处不在这四刀里：page-title 的「新增」链接从 <h2> 里挪到了外面、
#   评论页有两列的 kit-hidden-mb 改名叫 kit-hidden。
# ==========================================================================


def bar(gen, inner):
    """列表页上下的操作条（第 ② 刀）。"""
    if gen == '13':
        return '<form method="get" class="typecho-list-operate">\n' + inner + '\n</form>'
    return ('<div class="typecho-list-operate clearfix">\n<form method="get">\n'
            + inner + '\n</form>\n</div>')


def tabrow(gen, status, mine):
    """状态页签 + 「所有/我的」（第 ③ 刀）。"""
    if gen == '13':
        right = ('\n<ul class="typecho-option-tabs">' + mine + '</ul>') if mine.strip() else ''
        return ('<div class="typecho-list-operate">\n'
                '<ul class="typecho-option-tabs">' + status + '</ul>' + right + '\n</div>')
    right = ('<ul class="typecho-option-tabs right">' + mine + '</ul>\n') if mine.strip() else ''
    return ('<div class="clearfix">\n' + right
            + '<ul class="typecho-option-tabs">' + status + '</ul>\n</div>')


def card(gen, table):
    """表格的卡片壳（第 ① 刀）。"""
    return table if gen == '13' else '<div class="typecho-table-wrap">\n' + table + '\n</div>'


def empty_cell(gen, colspan, text):
    """空列表那一格（第 ④ 刀）。"""
    if gen == '13':
        return '<td colspan="%s" class="none">%s</td>' % (colspan, text)
    return '<td colspan="%s"><h6 class="typecho-list-table-title">%s</h6></td>' % (colspan, text)


def page_shell(gen, title, inner):
    """列表页公共外壳。<div class="main"> 由 to13() 换成 <main class="main">，
    1.3 的「新增」链接也在那一步从 <h2> 里挪出去。"""
    return ('<div class="main"><div class="body container">\n'
            '<div class="typecho-page-title"><h2>' + title + '</h2></div>\n'
            '<div class="row typecho-page-main" role="main">\n'
            '<div class="col-mb-12 typecho-list">\n' + inner + '</div>\n'
            '</div></div></div>\n')


POST_TABS = '''
  <li class="current"><a href="###">所有</a></li>
  <li><a href="###">已发布</a></li>
  <li><a href="###">草稿</a></li>
  <li><a href="###">待审核</a></li>
  <li><a href="###">隐藏</a></li>
  <li><a href="###">私密</a></li>
  <li><a href="###">密码保护</a></li>
'''
MINE_TABS = '''
  <li class="current"><a href="###">所有</a></li>
  <li><a href="###">我的</a></li>
'''

POST_OPERATE = '''<div class="operate">
  <label><i class="sr-only">全选</i><input type="checkbox" class="typecho-table-select-all"/></label>
  <div class="btn-group btn-drop">
    <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only">操作</i>选中项 <i class="i-caret-down"></i></button>
    <ul class="dropdown-menu">
      <li><a lang="你确认要删除这些文章吗?">删除</a></li>
      <li><a href="###">标记为<strong>公开</strong></a></li>
      <li><a href="###">标记为<strong>待审核</strong></a></li>
      <li><a href="###">标记为<strong>隐藏</strong></a></li>
      <li><a href="###">标记为<strong>私密</strong></a></li>
    </ul>
  </div>
</div>'''

POST_SEARCH = '''<div class="search" role="search">
  <input type="text" class="text-s" placeholder="请输入关键字" name="keywords"/>
  <select name="category"><option value="">所有分类</option><option value="2">技术</option><option value="3">日记</option></select>
  <button type="submit" class="btn btn-s">筛选</button>
</div>'''

POST_PAGER = '''<ul class="typecho-pager">
  <li><a class="prev" href="###">上一页</a></li><li class="current"><a href="###">1</a></li><li><a href="###">2</a></li><li><a class="next" href="###">下一页</a></li>
</ul>'''

POST_ROWS = '''
<tr id="content-1" class="checked">
  <td class="kit-hidden-mb"><input type="checkbox" value="1" name="cid[]" checked/></td>
  <td class="kit-hidden-mb"><a class="balloon-button size-1" href="###" title="12 评论">12</a></td>
  <td><a href="###">后台皮肤这一版做了什么</a> <em class="status">草稿</em>
      <a href="###" title="编辑"><i class="i-edit"></i></a></td>
  <td class="kit-hidden-mb"><a href="###">qine233</a></td>
  <td class="kit-hidden-mb"><a href="###">技术</a></td>
  <td><span class="description">保存于 2 小时前</span></td>
</tr>
<tr id="content-2">
  <td class="kit-hidden-mb"><input type="checkbox" value="2" name="cid[]"/></td>
  <td class="kit-hidden-mb"><a class="balloon-button size-1" href="###" title="3 评论">3</a></td>
  <td><a href="###">日记为什么不是文章</a>
      <a href="###" title="编辑"><i class="i-edit"></i></a> <a href="###" title="浏览"><i class="i-exlink"></i></a></td>
  <td class="kit-hidden-mb"><a href="###">qine233</a></td>
  <td class="kit-hidden-mb"><a href="###">日记</a></td>
  <td>2026-10-18</td>
</tr>
<tr id="content-3" class="deactivate">
  <td class="kit-hidden-mb"><input type="checkbox" value="3" name="cid[]"/></td>
  <td class="kit-hidden-mb"><a class="balloon-button size-0" href="###" title="0 评论">0</a></td>
  <td><a href="###">Cloudreve 直链的取法</a> <em class="status">隐藏</em></td>
  <td class="kit-hidden-mb"><a href="###">qine233</a></td>
  <td class="kit-hidden-mb"><a href="###">技术</a>, <a href="###">随笔</a></td>
  <td>2026-10-12</td>
</tr>
'''


def post_table(gen, rows):
    """文章表。1.3 只把第一列的固定宽换成了百分比（manage-posts.php:56）。"""
    first = ('<col width="3%" class="kit-hidden-mb"/>' if gen == '13'
             else '<col width="20" class="kit-hidden-mb"/>')
    return ('<table class="typecho-list-table">\n<colgroup>' + first
            + '<col width="6%" class="kit-hidden-mb"/><col width="45%"/>'
            '<col width="" class="kit-hidden-mb"/><col width="18%" class="kit-hidden-mb"/>'
            '<col width="16%"/></colgroup>\n'
            '<thead><tr><th class="kit-hidden-mb"> </th><th class="kit-hidden-mb"> </th>'
            '<th>标题</th><th class="kit-hidden-mb">作者</th><th class="kit-hidden-mb">分类</th>'
            '<th>日期</th></tr></thead>\n<tbody>' + rows + '</tbody>\n</table>')


def list_page(gen):
    rows = POST_ROWS if gen == '12' else POST_ROWS   # 行内结构两代一致，只有 colgroup 变了
    inner = (tabrow(gen, POST_TABS, MINE_TABS) + '\n'
             + bar(gen, POST_OPERATE + '\n' + POST_SEARCH) + '\n'
             + '<form method="post" name="manage_posts" class="operate-form">\n'
             + card(gen, post_table(gen, rows)) + '\n</form>\n'
             + bar(gen, POST_OPERATE + '\n' + POST_PAGER) + '\n')
    return page_shell(gen, '文章管理 <a class="btn btn-s" href="###">写文章</a>', inner)


def list_empty(gen):
    """空列表那一版。第 ④ 刀只在空态上看得出来，不单独拍一张就没处验。"""
    inner = (tabrow(gen, POST_TABS, MINE_TABS) + '\n'
             + bar(gen, POST_OPERATE + '\n' + POST_SEARCH) + '\n'
             + '<form method="post" name="manage_posts" class="operate-form">\n'
             + card(gen, post_table(gen, '<tr>\n' + empty_cell(gen, '6', '没有任何文章') + '\n</tr>'))
             + '\n</form>\n')
    return page_shell(gen, '文章管理 <a class="btn btn-s" href="###">写文章</a>', inner)


PLUGIN_COLS = ('<colgroup><col width="25%"/><col width="45%"/>'
               '<col width="8%" class="kit-hidden-mb"/><col width="10%" class="kit-hidden-mb"/>'
               '<col width="17%"/></colgroup>')
PLUGIN_HEAD = ('<thead><tr><th>名称</th><th>描述</th>'
               '<th class="kit-hidden-mb">版本</th><th class="kit-hidden-mb">作者</th>'
               '<th>操作</th></tr></thead>')


def plugin_table(cls, rows):
    return ('<table class="typecho-list-table ' + cls + '">\n' + PLUGIN_COLS + PLUGIN_HEAD
            + '\n<tbody>\n' + rows + '\n</tbody>\n</table>')


def plugins_page(gen):
    on = ('<tr id="plugin-NolineApi">\n'
          '<td>Noline 对外接口</td>\n'
          '<td>把主题设置、健康数据和买菜流水以 JSON 接口暴露出去。</td>\n'
          '<td class="kit-hidden-mb">1.9.2</td>\n'
          '<td class="kit-hidden-mb"><a href="###">qine233</a></td>\n'
          '<td><a href="###">设置</a> &bull; <a href="###">禁用</a></td>\n</tr>\n')
    off = ('<tr id="plugin-HelloWorld">\n<td>Hello Word</td>\n<td>一个示例插件。</td>\n'
           '<td class="kit-hidden-mb">1.0.0</td>\n'
           '<td class="kit-hidden-mb"><a href="###">Typecho</a></td>\n'
           '<td><a href="###">启用</a> &bull; <a href="###">删除</a></td>\n</tr>\n')
    inner = ('<h4 class="typecho-list-table-title">启用的插件</h4>\n'
             + card(gen, plugin_table('', on)) + '\n'
             '<h4 class="typecho-list-table-title">禁用的插件</h4>\n'
             + card(gen, plugin_table('deactivate', off)) + '\n')
    return page_shell(gen, '插件管理', inner)


COMMENT_COLS12 = ('<colgroup><col width="20" class="kit-hidden-mb"/>'
                  '<col width="6%" class="kit-hidden-mb"/><col width="6%"/>'
                  '<col width="17%"/><col width="45%"/><col width="*"/></colgroup>')
# 1.3 把两列的 kit-hidden-mb 改成了 kit-hidden（manage-comments.php:60）——
# grid.css 里前者 ≤575px 才藏，后者 ≤991px 就藏，中间那一段两代的列数不一样。
COMMENT_COLS13 = ('<colgroup><col width="3%" class="kit-hidden-mb"/>'
                  '<col width="3%" class="kit-hidden-mb"/><col width="6%" class="kit-hidden"/>'
                  '<col width="6%" class="kit-hidden"/><col width="20%"/><col width="71%"/></colgroup>')

COMMENT_OPERATE = '''<div class="operate">
  <label><i class="sr-only">全选</i><input type="checkbox" class="typecho-table-select-all"/></label>
  <div class="btn-group btn-drop">
    <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only">操作</i>选中项 <i class="i-caret-down"></i></button>
    <ul class="dropdown-menu">
      <li><a href="###">通过</a></li><li><a href="###">待审核</a></li>
      <li><a href="###">标记垃圾</a></li><li><a lang="你确认要删除这些评论吗?">删除</a></li>
      <li class="multiline"><button class="btn btn-s btn-warn btn-operate">删除所有垃圾评论</button></li>
    </ul>
  </div>
</div>'''

COMMENT_SEARCH = '''<div class="search" role="search">
  <input type="text" class="text-s" placeholder="请输入关键字" name="keywords"/>
  <button type="submit" class="btn btn-s">筛选</button>
</div>'''

COMMENT_TABS = '''
  <li class="current"><a href="###">已通过</a></li>
  <li><a href="###">待审核</a></li>
  <li><a href="###">垃圾评论</a></li>
'''


def comments_page(gen):
    hide = 'kit-hidden-mb' if gen == '12' else 'kit-hidden'
    rows = ('<tr id="comment-91">\n'
            '<td class="kit-hidden-mb"><input type="checkbox" value="91" name="cid[]" checked/></td>\n'
            '<td valign="top" class="' + hide + '"><a href="###" title="浏览"><i class="i-exlink"></i></a></td>\n'
            '<td valign="top" class="' + hide + '"><input type="checkbox" value="91"/> </td>\n'
            '<td valign="top">qine233</td>\n'
            '<td valign="top">这个后台看着舒服多了，卡片化的列表尤其好用。</td>\n'
            '<td valign="top"><a href="###">后台皮肤这一版做了什么</a><br/>2026-10-20</td>\n</tr>\n'
            '<tr id="comment-92">\n'
            '<td class="kit-hidden-mb"><input type="checkbox" value="92" name="cid[]"/></td>\n'
            '<td valign="top" class="' + hide + '"><a href="###" title="浏览"><i class="i-exlink"></i></a></td>\n'
            '<td valign="top" class="' + hide + '"><input type="checkbox" value="92"/> </td>\n'
            '<td valign="top">访客</td>\n'
            '<td valign="top"><a href="###">回复</a> <a href="###">编辑</a></td>\n'
            '<td valign="top"><a href="###">日记为什么不是文章</a><br/>2026-10-19</td>\n</tr>\n')
    cols = COMMENT_COLS12 if gen == '12' else COMMENT_COLS13
    table = ('<table class="typecho-list-table">\n' + cols
             + '\n<thead><tr><th class="kit-hidden-mb"> </th>'
               '<th class="kit-hidden-mb"> </th><th>作者</th><th class="kit-hidden-mb"> </th>'
               '<th>内容</th><th>文章 / 日期</th></tr></thead>\n<tbody>' + rows + '</tbody>\n</table>')
    inner = ('<div class="message popup error" style="position:static;display:block">'
             '<p>安全令牌对不上。多半是页面开着太久或者换了登录状态。</p></div>\n'
             '<div class="message popup success" style="position:static;display:block">'
             '<p>评论已通过。</p></div>\n'
             '<div class="message popup notice" style="position:static;display:block">'
             '<p>你的 Typecho 有新版本可以更新。</p></div>\n'
             + tabrow(gen, COMMENT_TABS, MINE_TABS) + '\n'
             + bar(gen, COMMENT_OPERATE + '\n' + COMMENT_SEARCH) + '\n'
             + '<form method="post" name="manage_comments" class="operate-form">\n'
             + card(gen, table) + '\n</form>\n'
             + bar(gen, COMMENT_OPERATE + '\n' + POST_PAGER) + '\n')
    return page_shell(gen, '评论管理', inner)

def dashboard_page(gen):
    """控制台首页 = 核心 admin/index.php。两代只差 #start-link 上那个 clearfix。
    注意它的外壳是 .container.typecho-dashboard，不是别的页面那层 .body.container；
    欢迎卡是 .welcome-board 那一整条，右侧三栏是 section.latest-link。
    底下那条 .update-check 是页面脚本 insertAfter('.typecho-page-title') 塞进来的。"""
    clearfix = ' class="clearfix"' if gen == '12' else ''
    return '''
<div class="main">
  <div class="container typecho-dashboard">
    <div class="typecho-page-title"><h2>首页</h2></div>
    <div class="update-check message error"><p>您当前使用的版本是 1.3.0<br><strong><a href="###">官方最新版本是 1.3.1</a></strong></p></div>
    <div class="row typecho-page-main">
      <div class="col-mb-12 welcome-board" role="main">
        <p>目前有 <em>12</em> 篇文章, 并有 <em>34</em> 条关于你的评论在 <em>5</em> 个分类中.<br>点击下面的链接快速开始:</p>
        <ul id="start-link"''' + clearfix + '''>
          <li><a href="###">撰写新文章</a></li>
          <li><a href="###">待审核的评论</a> <span class="balloon">3</span></li>
          <li><a href="###">垃圾评论</a> <span class="balloon">12</span></li>
          <li><a href="###">更换外观</a></li>
          <li><a href="###">插件管理</a></li>
          <li><a href="###">系统设置</a></li>
        </ul>
      </div>
      <div class="col-mb-12 col-tb-4" role="complementary">
        <section class="latest-link">
          <h3>最近发布的文章</h3>
          <ul>
            <li><span>10.20</span> <a href="###" class="title">后台皮肤这一版做了什么</a></li>
            <li><span>10.18</span> <a href="###" class="title">Cloudreve 直链的取法</a></li>
          </ul>
        </section>
      </div>
      <div class="col-mb-12 col-tb-4" role="complementary">
        <section class="latest-link">
          <h3>最近得到的回复</h3>
          <ul>
            <li><span>10.20</span> <a href="###" class="title">qine233</a>: 这个后台看着舒服多了...</li>
            <li><span>10.19</span> <a href="###" class="title">访客</a>: 图床的直链能久一点吗...</li>
          </ul>
        </section>
      </div>
      <div class="col-mb-12 col-tb-4" role="complementary">
        <section class="latest-link">
          <h3>官方最新日志</h3>
          <div id="typecho-message">
            <ul>
              <li><span>10.15</span> <a href="###" target="_blank">Typecho 1.3.0 发布</a></li>
              <li><span>09.02</span> <a href="###" target="_blank">插件开发者公告</a></li>
            </ul>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
'''

def login_page(gen):
    """登录页 = 核心 admin/login.php。两代结构一模一样，只有那句占位文案
    从「用户名」变成了「用户名或邮箱」，外加密码框多了 required。
    每个输入前面都有一枚 .sr-only 的 label（核心 style.css 里是 1px 裁掉），别漏。"""
    name = '用户名或邮箱' if gen == '13' else '用户名'
    return '''
<div class="typecho-login-wrap">
  <div class="typecho-login">
    <h1><a href="###" class="i-logo">Typecho</a></h1>
    <form method="post" name="login" role="form">
      <p>
        <label for="name" class="sr-only">''' + name + '''</label>
        <input type="text" id="name" name="name" class="text-l w-100" placeholder="''' + name + '''" autofocus>
      </p>
      <p>
        <label for="password" class="sr-only">密码</label>
        <input type="password" id="password" name="password" class="text-l w-100" placeholder="密码">
      </p>
      <p class="submit"><button type="submit" class="btn btn-l w-100 primary">登录</button></p>
      <p>
        <label for="remember"><input type="checkbox" name="remember" class="checkbox" value="1" id="remember"> 下次自动登录</label>
      </p>
    </form>
    <p class="more-link"><a href="###">返回首页</a> &bull; <a href="###">用户注册</a></p>
  </div>
</div>
'''

DIALOG_BODY = '''
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
DATE_BODY = '''
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

# 标签页（manage-tags.php）。1.3 的三处差别全在这一页上：
#   ① <ul> 上的 clearfix 没了，.tag-list 自己改成 display:flex;flex-wrap:wrap
#   ② 勾选高亮从「tableSelectable 给 li 打 .checked」换成核心直接
#      li:has(input[type=checkbox]:checked)，写死 #FFFBCC
#   ③ 空态从 ul 里面的 <h6 class="typecho-list-table-title"> 换成
#      另一个 <ul class="tag-list"><li class="none">
TAGS_OPERATE = '''<div class="operate">
  <label><i class="sr-only">全选</i><input type="checkbox" class="typecho-table-select-all"/></label>
  <div class="btn-group btn-drop">
    <button class="btn dropdown-toggle btn-s" type="button"><i class="sr-only">操作</i>选中项 <i class="i-caret-down"></i></button>
    <ul class="dropdown-menu">
      <li><a lang="你确认要删除这些标签吗?">删除</a></li>
      <li><a lang="刷新标签可能需要等待较长时间, 你确认要刷新这些标签吗?">刷新</a></li>
      <li class="multiline"><button type="button" class="btn btn-s merge">合并到</button>
          <input type="text" name="merge" class="text-s"/></li>
    </ul>
  </div>
</div>'''

TAG_ITEMS = [
    ('5', 'typecho', 1), ('10', 'php', 0), ('20', 'css', 0),
    ('30', '设计', 0), ('0', '随笔', 0),
]


def tags_page(gen):
    lis = ''.join(
        '\n<li class="size-%s"%s id="mid-%d"><input type="checkbox" value="%d" name="mid[]"%s/>'
        '<span rel="###">%s</span><a class="tag-edit-link" href="###"><i class="i-edit"></i></a></li>'
        % (size, ' checked' if checked and gen == '12' else '', mid, mid,
           ' checked' if checked else '', name)
        for mid, (size, name, checked) in enumerate(TAG_ITEMS, start=1))
    if gen == '13':
        ul = '<ul class="typecho-list-notable tag-list">' + lis + '\n</ul>'
    else:
        ul = '<ul class="typecho-list-notable tag-list clearfix">' + lis + '\n</ul>'

    bar_tag = ('<div class="typecho-list-operate">\n' + TAGS_OPERATE + '\n</div>' if gen == '13'
               else '<div class="typecho-list-operate clearfix">\n' + TAGS_OPERATE + '\n</div>')

    form = '''<form method="post" action="###" class="typecho-post-area">
  <ul class="typecho-option" id="typecho-option-item-name-0-1">
    <li><label class="typecho-label required" for="name-0-1">标签名称 *</label>
        <input id="name-0-1" name="name" type="text" class="text"/>
        <p class="description">这是标签在站点中显示的名称.可以使用中文,如 "地球".</p></li>
  </ul>
  <ul class="typecho-option" id="typecho-option-item-slug-1-2">
    <li><label class="typecho-label" for="slug-1-2">标签缩略名</label>
        <input id="slug-1-2" name="slug" type="text" class="text"/>
        <p class="description">标签缩略名用于创建友好的链接形式, 如果留空则默认使用标签名称.</p></li>
  </ul>
  <ul class="typecho-option typecho-option-submit" id="typecho-option-item-2-3">
    <li><button type="submit" class="btn primary">提交</button></li>
  </ul>
</form>'''

    return ('<div class="main"><div class="body container">\n'
            '<div class="typecho-page-title"><h2>标签</h2></div>\n'
            '<div class="row typecho-page-main manage-metas">\n'
            '<div class="col-mb-12 col-tb-8" role="main">\n'
            '<form method="post" name="manage_tags" class="operate-form">\n'
            + bar_tag + '\n' + ul + '\n<input type="hidden" name="do" value="delete"/>\n'
            '</form>\n</div>\n'
            '<div class="col-mb-12 col-tb-4" role="form">\n' + form + '\n</div>\n'
            '</div></div></div>\n')

# 核心在 1.3 还改了正文骨架，两处都会骗过皮肤：
#   admin/write-post.php:11 —— <form> 自己就是那一行 .row，两列直接挂在 form 下
#   每一页 —— <div class="main"> 换成了 <main class="main">
ROW12 = '<div class="row typecho-page-main typecho-post-area" role="form">\n      <form method="post" name="write_post">'
ROW13 = '<form class="row typecho-page-main typecho-post-area" method="post" name="write_post">'
ROW12_CLOSE = '      </form>\n    </div>\n'

# 高级选项那一块 1.3 整个换了实现（admin/write-post.php:121）：
# 1.2 是 #advance-panel-btn 按钮 + 一个被 style.css:429 藏起来的 div；
# 1.3 是原生 <details id="advance-panel">，开关就是 <summary class="btn btn-xs">，
# 里面还多了 .allow-option 权限控制、引用通告，密码框改成 #post-password.hidden。
ADV12 = '''            <button type="button" id="advance-panel-btn" class="btn btn-xs">高级选项 <i class="i-caret-down"></i></button>
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
            </div>'''

ADV13 = '''            <details id="advance-panel">
              <summary class="btn btn-xs">高级选项 <i class="i-caret-down"></i></summary>
              <section class="typecho-post-option visibility-option">
                <label for="visibility" class="typecho-label">公开度</label>
                <p><select id="visibility" name="visibility"><option value="publish">公开</option><option value="hidden">隐藏</option><option value="password">密码保护</option><option value="private">私密</option><option value="waiting">待审核</option></select></p>
                <p id="post-password" class="hidden"><label for="protect-pwd" class="sr-only">内容密码</label>
                  <input type="text" name="password" id="protect-pwd" class="text-s" size="16" placeholder="内容密码"></p>
              </section>
              <section class="typecho-post-option allow-option">
                <label class="typecho-label">权限控制</label>
                <ul>
                  <li><input id="allowComment" name="allowComment" type="checkbox" value="1" checked><label for="allowComment">允许评论</label></li>
                  <li><input id="allowPing" name="allowPing" type="checkbox" value="1"><label for="allowPing">允许被引用</label></li>
                  <li><input id="allowFeed" name="allowFeed" type="checkbox" value="1"><label for="allowFeed">允许在聚合中出现</label></li>
                </ul>
              </section>
              <section class="typecho-post-option">
                <label for="trackback" class="typecho-label">引用通告</label>
                <p><textarea id="trackback" class="w-100 mono" name="trackback" rows="2"></textarea></p>
                <p class="description">每一行一个引用地址, 用回车隔开</p>
              </section>
            </details>'''

# 自定义字段同样换了骨架（admin/custom-fields.php）：1.2 那张 .typecho-list-table
# 在 1.3 变成 <details><summary> + <ul class="fields">，控件叫 .field-name/.field-value。
CF12 = '''          <section id="custom-field" class="typecho-post-option">
            <label id="custom-field-expand" class="typecho-label"><a href="##"><i class="i-caret-right"></i> 自定义字段</a></label>
            <table class="typecho-list-table mono">
              <colgroup><col width="20%"><col width="15%"><col width="55%"><col width="10%"></colgroup>
              <tbody>
                <tr><td>封面</td><td>文本</td><td><input type="text" value="https://cdn/1.jpg" class="w-100"></td><td><a href="###" class="btn btn-mini">删除</a></td></tr>
                <tr><td><input type="text" value="cover"></td><td><select><option>文本</option></select></td><td><input type="text" class="w-100"></td><td><a href="###" class="btn btn-mini">删除</a></td></tr>
              </tbody>
            </table>
            <p><a href="###" class="btn btn-s">增加自定义字段</a></p>
          </section>'''

CF13 = '''          <details id="custom-field" class="typecho-post-option" open>
            <summary>自定义字段</summary>
            <ul class="fields mono">
              <li class="field">
                <div class="field-name"><label for="fn1" class="sr-only">字段名称</label><input type="text" name="fieldNames[]" value="cover" id="fn1" class="text-s w-100"><label for="ft1" class="sr-only">字段类型</label><select name="fieldTypes[]" id="ft1"><option value="str" selected>字符</option><option value="int">整数</option><option value="float">小数</option><option value="json">JSON 结构</option></select></div>
                <div class="field-value"><label for="fv1" class="sr-only">字段值</label><textarea name="fieldValues[]" id="fv1" class="text-s w-100" rows="2">https://cdn/1.jpg</textarea><button type="button" class="btn btn-xs">删除</button></div>
              </li>
              <li class="field">
                <div class="field-name"><label for="fn2" class="sr-only">字段名称</label><input type="text" name="fieldNames[]" placeholder="字段名称" id="fn2" class="text-s w-100"><label for="ft2" class="sr-only">字段类型</label><select name="fieldTypes[]" id="ft2"><option value="str">字符</option><option value="int">整数</option><option value="float">小数</option><option value="json">JSON 结构</option></select></div>
                <div class="field-value"><label for="fv2" class="sr-only">字段值</label><textarea name="fieldValues[]" placeholder="字段值" id="fv2" class="text-s w-100" rows="2"></textarea><button type="button" class="btn btn-xs">删除</button></div>
              </li>
            </ul>
            <div class="add"><button type="button" class="btn btn-xs operate-add">+添加字段</button>
              <div class="description">自定义字段可以扩展你的模板功能, 使用方法参见 <a href="###">帮助文档</a></div>
            </div>
          </details>'''


ADD_LINK = re.compile(r'(<h2>)([^<]*?)(<a\b[^>]*>[^<]*</a>)</h2></div>')


def to13(body):
    """按 v1.3.0 原版模板（_typecho-ref/admin-write-post-13.php、admin-category-13.php）
    把 1.2 形状的正文改写成 1.3 形状。只认结构、对不上就抛，
    好让夹具先于皮肤失效——静默失真的夹具比没有夹具更坏。"""
    if '<div class="main">' not in body:          # 登录页核心不包 .main
        return body

    assert body.count('<div class="main">') == 1, '正文里 .main 不止一层，改写规则得跟着调'
    out = body.replace('<div class="main">', '<main class="main">')

    head, sep, tail = out.rpartition('</div>')
    assert sep and tail.strip() == '', '.main 不是在正文末尾收口的'
    out = head + '</main>' + tail

    # page-title.php 在 1.3 把「新增」那一枚链接从 <h2> 里挪到了外面，
    # 皮肤里 .typecho-page-title h2 a 那条链子当场落空，核心自己的
    # .typecho-page-title a 会把它拍成灰底小方片。
    out, hit = ADD_LINK.subn(r'\1\2</h2>\3</div>', out)
    if ADD_LINK.search(body):
        assert hit == 1, '页头的「新增」链接不止一枚，改写规则得跟着调'

    # 1.3 的 tabs 上没有 clearfix，隐藏那块走 .hidden 类（grid.css 里有定义）
    out = out.replace('<ul class="typecho-option-tabs clearfix">', '<ul class="typecho-option-tabs">')
    for tab in ('tab-advance', 'tab-files'):
        out = out.replace('<div id="%s" class="tab-content" style="display:none">' % tab,
                          '<div id="%s" class="tab-content hidden">' % tab)

    if ROW12 in out:
        assert out.count(ROW12_CLOSE) == 1, 'write 页 .row 的收口形状变了'
        out = out.replace(ROW12, ROW13).replace(ROW12_CLOSE, '    </form>\n')
        assert out.count(ADV12) == 1, '高级选项那块对不上 1.2 的形状'
        out = out.replace(ADV12, ADV13)
        assert out.count(CF12) == 1, '自定义字段那块对不上 1.2 的形状'
        out = out.replace(CF12, CF13)
        out = out.replace('<p class="submit clearfix">', '<p class="submit">')
    return out


# (文件名前缀, 标题, 正文, 要不要顶栏)——登录页核心本来就不渲染导航。
# 正文给函数的是「两代各长一个样」的列表页家族，直接写字符串的是只在
# write / 高级选项那几处需要 to13() 改写的老骨架。
PAGE_SPECS = [
    ('write', '发布文章', WRITE, True),
    ('writefiles', '发布文章', WRITE_FILES, True),
    ('settings', '主题管理', SETTINGS, True),
    ('list', '文章管理', list_page, True),
    ('listempty', '文章管理 · 空', list_empty, True),
    ('plugins', '插件管理', plugins_page, True),
    ('comments', '评论管理', comments_page, True),
    ('dashboard', '首页', dashboard_page, True),
    ('login', '登录', login_page, False),
    ('dialog', '插入链接的对话框', DIALOG_BODY, True),
    ('tags', '标签', tags_page, True),
    ('date', '发布时间', DATE_BODY, True),
]


def build_pages(gen):
    """两代各出一套：1.2 那套用老名字（截图脚本认这些），1.3 那套加 -13 后缀。"""
    nav = NAV_OF[gen].replace('__TOGGLE__', TOGGLE)
    suffix = '' if gen == '12' else '-13'
    pages = {}
    for name, title, body, has_nav in PAGE_SPECS:
        if callable(body):
            body = body(gen)
        if gen == '13':
            body = to13(body)
        src = nav + body if has_nav else body
        pages['%s%s.html' % (name, suffix)] = shell(title, src, gen=gen)
        pages['%s%s-dark.html' % (name, suffix)] = shell(title, src, dark=True, gen=gen)
    return pages


PAGES = {}
PAGES.update(build_pages('12'))
PAGES.update(build_pages('13'))


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for name, html in PAGES.items():
        (OUT / name).write_text(html, encoding='utf-8')
    (OUT / 'admin.css').write_text((ASSETS / 'admin.css').read_text(encoding='utf-8'), encoding='utf-8')
    (OUT / 'admin.js').write_text((ASSETS / 'admin.js').read_text(encoding='utf-8'), encoding='utf-8')
    for name in sorted({f for files in CSS.values() for f in files}):
        (OUT / name).write_text((REF / name).read_text(encoding='utf-8'), encoding='utf-8')
    # 核心样式表里的图标是 url(../img/editor.png)，相对 /tmp/nl-admin 就是 /tmp/img。
    # 缺了它编辑器工具条会拍成一片空白，别误判成皮肤把按钮弄没了。
    if not (OUT.parent / 'img' / 'editor.png').is_file():
        print('提醒：/tmp/img/ 里没有 editor.png / icons.png，工具条图标会拍成空白')
    print('夹具：%s（%d 页）' % (OUT, len(PAGES)))


if __name__ == '__main__':
    sys.exit(main())
