#!/usr/bin/env python3
"""
检查前端 HTML 中引用的 DOM ID 是否与 game.js 中的 getElementById 对应
"""
import re

with open('src/index.html', 'r', encoding='utf-8') as f:
    html = f.read()

with open('src/game.js', 'r', encoding='utf-8') as f:
    js = f.read()

# 提取 HTML 中的所有 ID
html_ids = set(re.findall(r'id=["\']([^"\']+)["\']', html))

# 提取 JS innerHTML 中动态注入的 ID
dynamic_ids = set(re.findall(r'id=["\']([^"\']+)["\']', js))
all_defined_ids = html_ids.union(dynamic_ids)

# 提取 game.js 中 getElementById 查询的 ID
js_ids = set(re.findall(r'getElementById\(["\']([^"\']+)["\']\)', js))

missing_ids = []
for jid in js_ids:
    if '${' in jid:
        continue
    if jid not in all_defined_ids:
        missing_ids.append(jid)

if missing_ids:
    print("警告：以下 ID 未在 HTML 或动态代码中找到:", missing_ids)
else:
    print("✓ game.js 中的所有 DOM ID 全部存在！")

assert len(missing_ids) == 0, f"发现缺失的 DOM ID: {missing_ids}"
