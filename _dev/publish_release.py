#!/usr/bin/env python3
"""把 dist/noline.zip 发成一个 GitHub Release，并把附件挂上去。

用法：
    GH_TOKEN=<token> python3 _dev/publish_release.py [tag]
    python3 _dev/publish_release.py 1.18.0 --dry-run      # 只校验，不碰网络写

tag 缺省取 functions.php 里的 NOLINE_VERSION。CI 里由 workflow 传 GITHUB_REF_NAME。

插件的一键升级只认 Release 附件，而且名字必须是 noline.zip（Updater::ASSET）。
光推 tag 不建 Release，后台那一页永远显示「这个 Release 里没有叫 noline.zip 的附件」，
所以发版动作要一步到位：建 release + 传附件 + 回读确认。
"""
import json
import os
import re
import sys
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

HERE = Path(__file__).resolve().parent          # _dev/
THEME = HERE.parent                               # 主题目录（仓库布局下就是仓库根）
ASSET = 'noline.zip'                              # 必须和 Updater::ASSET 一致
VERSION_RE = re.compile(r"define\('NOLINE_VERSION',\s*'([^']+)'\)")
DEFAULT_REPO = 'qine233/noline'


def fail(message):
    raise SystemExit('发布中止：' + message)


def find_zip():
    candidates = [THEME / 'dist' / ASSET, THEME.parent / 'dist' / ASSET]

    for path in candidates:
        if path.is_file():
            return path

    fail('找不到 %s。先跑 python3 _dev/build.py。试过：%s'
         % (ASSET, '、'.join(str(p) for p in candidates)))


def zip_version(path):
    """读包里的版本号，用来确认「发出去的包」和「tag」说的是同一件事。"""
    with zipfile.ZipFile(str(path)) as archive:
        for name in archive.namelist():
            if name.endswith('functions.php') and name.count('/') <= 1:
                head = archive.read(name).decode('utf-8', 'replace')
                match = VERSION_RE.search(head)

                if match:
                    return match.group(1)

    return ''


def api(method, url, token, body=None, raw=None, content_type=None):
    data = body if raw is None else raw
    payload = json.dumps(body).encode('utf-8') if body is not None else data

    request = urllib.request.Request(url, data=payload, method=method)
    request.add_header('Authorization', 'Bearer ' + token)
    request.add_header('Accept', 'application/vnd.github+json')
    request.add_header('User-Agent', 'noline-publish')
    request.add_header('X-GitHub-Api-Version', '2022-11-28')

    if content_type:
        request.add_header('Content-Type', content_type)

    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            text = response.read().decode('utf-8', 'replace')

            return response.status, json.loads(text) if text else {}
    except urllib.error.HTTPError as error:
        text = error.read().decode('utf-8', 'replace')

        return error.code, {'raw': text[:600]}
    except OSError as error:
        fail('%s %s 请求失败：%s' % (method, url, error))


def release_body(api_base, repo, tag, token):
    """Release 正文优先用 tag 自带的说明，轻量 tag 就退回那条 commit 的信息。"""
    status, ref = api('GET', '%s/repos/%s/git/refs/tags/%s' % (api_base, repo, tag), token)

    if status != 200:
        return ''

    sha = ref.get('object', {}).get('sha', '')

    if not sha:
        return ''

    status, annotated = api('GET', '%s/repos/%s/git/tags/%s' % (api_base, repo, sha), token)

    if status == 200 and annotated.get('message'):
        return annotated['message'].strip()

    status, commit = api('GET', '%s/repos/%s/commits/%s' % (api_base, repo, sha), token)

    if status == 200:
        return commit.get('commit', {}).get('message', '').strip()

    return ''


def main():
    arguments = [a for a in sys.argv[1:] if a != '--dry-run']
    dry_run = '--dry-run' in sys.argv[1:]
    repo = os.environ.get('GITHUB_REPOSITORY') or DEFAULT_REPO
    tag = (arguments[0] if arguments else '').strip() or zip_version(find_zip())

    path = find_zip()
    inside = zip_version(path)

    if not inside:
        fail('%s 里找不到 noline/functions.php 的 NOLINE_VERSION，这个包不像 build.py 出来的' % path)

    # tag 允许写 v1.18.0，包里的版本是 1.18.0，比之前都剥掉前导 v
    if tag.lstrip('vV') != inside:
        fail('tag 是「%s」但包里的版本是「%s」。改完版本号要重跑 build.py，'
             '否则发出去的包和标题说的不是同一件事。' % (tag, inside))

    size = path.stat().st_size

    if dry_run:
        print('dry-run，不联网写。计划：')
        print('  仓库   : %s' % repo)
        print('  tag    : %s（包内版本 %s，一致）' % (tag, inside))
        print('  附件   : %s（%d 字节）' % (ASSET, size))
        print('  动作   : 建/复用 Release %s，挂 %s，回读确认大小' % (tag, ASSET))

        return

    token = os.environ.get('GH_TOKEN') or os.environ.get('GITHUB_TOKEN')

    if not token:
        fail('没有 GH_TOKEN（或 GITHUB_TOKEN）。CI 里用仓库自带的 secrets.GITHUB_TOKEN 就行。')

    api_base = 'https://api.github.com'
    status, release = api('GET', '%s/repos/%s/releases/tags/%s' % (api_base, repo, tag), token)

    if status == 404:
        print('Release %s 不存在，创建。' % tag)
        status, release = api('POST', '%s/repos/%s/releases' % (api_base, repo), token, {
            'tag_name': tag,
            'name': 'NoLine %s' % tag,
            'body': release_body(api_base, repo, tag, token),
            'draft': False,
            'prerelease': False,
        })

    if status not in (200, 201):
        fail('拿 Release 失败（HTTP %d）：%s' % (status, release.get('raw', release)))

    release_id = release.get('id')

    if not release_id:
        fail('返回里没有 release id：%s' % release)

    # 重发同一个 tag 时旧附件还在，同名上传会得到 422，先删掉自己那个
    for asset in release.get('assets', []):
        if asset.get('name', '').lower() == ASSET:
            print('先删掉旧附件 %s（%d 字节）。' % (asset['name'], asset.get('size', 0)))
            status, gone = api('DELETE', '%s/repos/%s/releases/assets/%s'
                               % (api_base, repo, asset['id']), token)

            if status not in (204, 200):
                fail('删旧附件失败（HTTP %d）：%s' % (status, gone.get('raw', gone)))

    upload = 'https://uploads.github.com/repos/%s/releases/%s/assets?name=%s' % (repo, release_id, ASSET)
    status, asset = api('POST', upload, token,
                        raw=path.read_bytes(), content_type='application/zip')

    if status not in (200, 201):
        fail('上传附件失败（HTTP %d）：%s' % (status, asset.get('raw', asset)))

    status, final = api('GET', '%s/repos/%s/releases/tags/%s' % (api_base, repo, tag), token)
    found = [a for a in final.get('assets', []) if a.get('name', '').lower() == ASSET]

    if not found:
        fail('上传后回读却看不到 %s，接口返回：%s' % (ASSET, final))

    if found[0].get('size') != size:
        fail('回读到的附件大小是 %s，本地是 %d，对不上。' % (found[0].get('size'), size))

    print('已发布 %s：%s' % (tag, final.get('html_url', '')))
    print('附件下载地址：%s（%d 字节）' % (found[0].get('browser_download_url'), found[0]['size']))
    print('插件那边：后台「主题管理 → 主题更新」点重新检测，应显示「已经是最新 %s」。' % tag)


if __name__ == '__main__':
    main()
