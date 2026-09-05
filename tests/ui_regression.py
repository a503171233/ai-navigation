#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全站 UI 改造后逐页回归：后台所有页面渲染 + 商城所有页面渲染"""
import urllib.request, urllib.parse, http.cookiejar, re, sys

BASE = 'http://127.0.0.1:8080'
cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

def get(url):
    try:
        r = opener.open(url, timeout=15)
        return r.status, r.read().decode('utf-8', 'ignore')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'ignore')
    except Exception as e:
        return -1, str(e)

results = []

# ---------- 1. 后台登录 ----------
html = get(BASE + '/admin/login.php')[1]
m = re.search(r'name="csrf_token" value="([^"]+)"', html)
csrf = m.group(1) if m else ''
data = urllib.parse.urlencode({'csrf_token': csrf, 'username': 'admin', 'password': 'admin123'}).encode()
req = urllib.request.Request(BASE + '/admin/login.php', data=data)
try:
    opener.open(req)
    logged = True
except Exception as e:
    logged = False
results.append(('后台登录', 'PASS' if logged else 'FAIL', ''))

# ---------- 2. 后台页面 ----------
admin_pages = {
    'dashboard.php': '仪表盘',
    'products.php': '产品管理',
    'packages.php': '安装包',
    'licenses.php': '授权码',
    'machines.php': '机器绑定',
    'offline_activate.php': '离线激活',
    'instances.php': '实例管理',
    'commands.php': '命令中心',
    'popups.php': '弹窗管理',
    'updates.php': '更新管理',
    'system_update.php': '系统升级',
    'modules.php': '远程模块',
    'skills.php': '技能管理',
    'orders.php': '订单管理',
    'logs.php': '日志管理',
    'settings.php': '系统设置',
    'admins.php': '管理员',
    '2fa.php': '两步验证',
}
for page, title in admin_pages.items():
    code, html = get(BASE + '/admin/' + page)
    ok = code == 200 and 'nav-group' in html
    results.append((f'后台页面 {page}', 'PASS' if ok else 'FAIL', f'HTTP {code}' if code != 200 else ''))

# ---------- 3. 商城页面 ----------
shop_pages = {
    'home': '产品列表',
    'login': '买家登录',
    'register': '买家注册',
    'licenses': '我的授权',
    'orders': '我的订单',
    'trial': '试用',
}
for page, title in shop_pages.items():
    code, html = get(BASE + '/shop/' + page)
    ok = code == 200 and 'data-nav-toggle' in html
    results.append((f'商城页面 {page}', 'PASS' if ok else 'FAIL', f'HTTP {code}' if code != 200 else ''))

# ---------- 4. 核心交互 ----------
# 买家注册
import time
email = 'ui_reg_' + str(int(time.time())) + '@test.com'
data = urllib.parse.urlencode({'email': email, 'nickname': 'UI回归', 'password': 'test123456', 'password2': 'test123456'}).encode()
req = urllib.request.Request(BASE + '/shop/register', data=data)
code = -1
try:
    r = opener.open(req); code = r.status
except urllib.error.HTTPError as e:
    code = e.code
results.append(('商城注册', 'PASS' if code == 200 else 'FAIL', f'HTTP {code}'))

# 搜索（licenses 搜索）
code, html = get(BASE + '/admin/licenses.php?kw=test&product_id=0&status=')
results.append(('后台授权码搜索', 'PASS' if code == 200 else 'FAIL', f'HTTP {code}'))

# 导出 CSV
code, _ = get(BASE + '/admin/licenses.php?export=csv')
results.append(('授权码导出CSV', 'PASS' if code == 200 else 'FAIL', f'HTTP {code}'))

# API 健康检查
code, html = get(BASE + '/api/healthz')
results.append(('API健康检查', 'PASS' if code == 200 else 'FAIL', f'HTTP {code}'))

# ---------- 输出 ----------
print('\n================ 全站回归结果 ================')
pass_cnt = fail_cnt = 0
for name, status, detail in results:
    mark = '✓' if status == 'PASS' else '✗'
    print(f'{mark} {name}: {status}' + (f' ({detail})' if detail else ''))
    if status == 'PASS': pass_cnt += 1
    else: fail_cnt += 1
print(f'\n总计: {pass_cnt} 通过 / {fail_cnt} 失败')
sys.exit(1 if fail_cnt else 0)
