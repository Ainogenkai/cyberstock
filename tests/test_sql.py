#!/usr/bin/env python3
"""
检查 database/init.sql 语法与表结构完整性
"""
import re

with open('database/init.sql', 'r', encoding='utf-8') as f:
    sql = f.read()

# 检查建表语句
tables = re.findall(r'CREATE TABLE IF NOT EXISTS (\w+)', sql)
print(f"检测到创建的数据表 ({len(tables)} 张):", tables)

expected_tables = [
    'users', 'invite_codes', 'user_sessions', 'stocks', 'market_state',
    'orders', 'trades', 'positions', 'account_ledger', 'candles',
    'market_events', 'admin_logs'
]

for t in expected_tables:
    assert t in tables, f"缺少预期的数据表: {t}"

print("✓ 预期 12 张数据表全部定义完整！")

# 检查是否有单引号或双引号未闭合
quotes = len(re.findall(r"'", sql))
assert quotes % 2 == 0, f"单引号数量不成对: {quotes}"
print("✓ SQL 字符串引号成对校验通过！")
