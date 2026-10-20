/**
 * Noline 后台脚本：只做两件事。
 *
 * 1) 深色开关。当前值由 header.php 里那段前置脚本同步写在 <html data-nl-theme> 上
 *    （等这里跑完再设就会先白一下），本文件只负责点下去之后翻面 + 记住选择。
 * 2) Cloudreve 图床面板。配置从 window.NL_CR 读，DOM 全在这里建，
 *    服务端不再拼 HTML——面板挂在 admin/header.php:header 之后，
 *    跟核心的 file-upload.php 什么时候渲染、插件的 bottom 钩子有没有生效都脱钩了。
 *
 * 只在 #upload-panel 存在时建面板：写文章、写页面、附件管理三处都有它。
 * 正文插入走 #text 的光标位置；那一个 textarea 在富文本和 Markdown（pagedown）两种编辑器里
 * 都是真正存正文的字段。没有 #text（附件管理页）就只给直链和复制，不假装插进去了。
 * 不用 Typecho.uploadComplete：它只在 Markdown 分支里定义（editor-js.php:212）。
 * 不用 Typecho.insertFileToEditor：Markdown 分支里它是「弹图片对话框、等用户再点一次确定」，
 * 直链都已经在手上了还多一步。
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var STORE = 'noline:admin-theme';

    /* ---------------------------------------------------------------- 深色开关 */

    function bindThemeToggle() {
        var btn = document.getElementById('nl-theme-toggle');
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-nl-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-nl-theme', next);
            btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
            btn.title = btn.getAttribute(next === 'dark' ? 'data-label-light' : 'data-label-dark') || '';

            try {
                localStorage.setItem(STORE, next);
            } catch (e) {
                // 隐私模式下 localStorage 会抛，记不住就记不住，这一页照样是切换了的
            }
        });
    }

    /* ---------------------------------------------------------------- 图床面板 */

    function build(cfg) {
        var panel = document.getElementById('upload-panel');
        var text = document.getElementById('text');

        if (!panel || panel.querySelector('.nl-cr')) {
            return;
        }

        var box = el('div', 'nl-cr' + (cfg.ready ? '' : ' nl-cr-off'));
        var bar = el('div', 'nl-cr-bar');
        var title = el('span', 'nl-cr-title');
        title.textContent = cfg.text.title;

        var hint = el('span', 'nl-cr-hint');
        /* 文案在 PHP 里已经 sprintf 完（含体积上限），这里不再二次填占位符 */
        hint.textContent = cfg.ready ? cfg.text.hint : cfg.text.notready;

        bar.appendChild(title);

        var list = el('ul', 'nl-cr-list');

        if (cfg.ready) {
            var pick = el('label', 'nl-cr-pick');
            pick.textContent = cfg.text.pick;
            var input = document.createElement('input');
            input.type = 'file';
            input.multiple = true;
            input.accept = acceptOf(cfg.allow);
            pick.appendChild(input);
            bar.appendChild(pick);
            input.addEventListener('change', function () {
                files(Array.prototype.slice.call(input.files || []));
                input.value = '';
            });
        }

        bar.appendChild(hint);
        box.appendChild(bar);
        box.appendChild(list);
        panel.insertBefore(box, panel.firstChild);

        if (cfg.ready) {
            box.addEventListener('dragover', function (ev) {
                ev.preventDefault();
            });
            /* 拖拽只认图床框自己：附件区那一块是核心自带上传的落点，不去抢 */
            box.addEventListener('drop', function (ev) {
                ev.preventDefault();
                files(Array.prototype.slice.call((ev.dataTransfer || {}).files || []));
            });

            if (cfg.paste) {
                document.addEventListener('paste', function (ev) {
                    var t = ev.target;
                    if (!t || t.id !== 'text') {
                        return;
                    }
                    /* 只拦图片：粘贴正文文字不该被图床吃掉 */
                    var items = (ev.clipboardData || {}).items || [];
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
                            var f = items[i].getAsFile();
                            if (f) {
                                ev.preventDefault();
                                files([f]);
                            }
                            return;
                        }
                    }
                });
            }
        }

        function el(tag, cls) {
            var node = document.createElement(tag);
            node.className = cls;
            return node;
        }

        function acceptOf(allow) {
            return String(allow || '').split(',').map(function (ext) {
                return '.' + ext.trim();
            }).filter(Boolean).join(',');
        }

        function files(list) {
            list.forEach(send);
        }

        /* 插入语法由服务端算好放进配置：JS 里再拼 HTML 就得跟「引号套引号」较劲，容易漏一个 */
        function snippet(name, url, isImage) {
            return String(isImage ? cfg.tplImg : cfg.tplFile)
                .split('{n}').join(name)
                .split('{u}').join(url);
        }

        function insertIntoText(s) {
            if (!text) {
                return;
            }
            var start = text.selectionStart === undefined ? text.value.length : text.selectionStart;
            var end = text.selectionEnd === undefined ? text.value.length : text.selectionEnd;
            text.value = text.value.slice(0, start) + s + text.value.slice(end);
            text.focus();
            text.setSelectionRange(start + s.length, start + s.length);
            /* input + keyup：Markdown 的实时预览挂在编辑框的输入事件上，只派 input 它不一定跟 */
            ['input', 'keyup'].forEach(function (type) {
                text.dispatchEvent(new Event(type, { bubbles: true }));
            });
        }

        function copy(textContent) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(textContent);
            }
            var ta = document.createElement('textarea');
            ta.value = textContent;
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
            } catch (e) {
                // 老浏览器复制失败只是少一次复制，不影响直链本身
            }
            document.body.removeChild(ta);
            return Promise.resolve();
        }

        function send(file) {
            if (!file) {
                return;
            }

            var li = el('li', 'nl-cr-item');
            var name = el('span', 'nl-cr-name');
            name.textContent = file.name;
            var track = el('span', 'nl-cr-track');
            var fill = el('span', 'nl-cr-fill');
            track.appendChild(fill);
            li.appendChild(name);
            li.appendChild(track);
            list.appendChild(li);

            var data = new FormData();
            data.append('file', file);
            data.append('_', cfg.token);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.endpoint, true);

            xhr.upload.onprogress = function (ev) {
                if (ev.lengthComputable) {
                    fill.style.width = Math.round(ev.loaded * 100 / ev.total) + '%';
                }
            };

            xhr.onload = function () {
                var res = null;
                try {
                    res = JSON.parse(xhr.responseText);
                } catch (e) {
                    // 非 JSON（多半是被某个 WAF 或 PHP 报错页挡了）走下面的 fail
                }

                if (!res || res.code !== 0) {
                    fail((res && res.message) ? res.message : ('HTTP ' + xhr.status));
                    return;
                }

                fill.style.width = '100%';
                li.className = 'nl-cr-item ok';

                var d = res.data;
                insertIntoText(snippet(d.name, d.url, d.isImage));

                var link = el('a', 'nl-cr-link');
                link.href = d.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = cfg.text.link;
                li.appendChild(link);

                var btn = el('a', 'nl-cr-link');
                btn.href = '###';
                btn.textContent = cfg.text.copy;
                btn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    copy(d.url).then(function () {
                        btn.textContent = cfg.text.copied;
                    });
                });
                li.appendChild(btn);
            };

            xhr.onerror = function () {
                fail(cfg.text.network);
            };

            xhr.onloadend = function () {
                if (li.className.indexOf('ok') < 0 && li.className.indexOf('bad') < 0) {
                    fail(cfg.text.interrupted);
                }
            };

            function fail(message) {
                li.className = 'nl-cr-item bad';
                fill.style.width = '100%';
                var msg = el('div', 'nl-cr-msg');
                msg.textContent = message;
                li.appendChild(msg);
            }

            xhr.send(data);
        }
    }

    function start() {
        bindThemeToggle();

        var cfg = window.NL_CR;
        if (cfg) {
            build(cfg);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
