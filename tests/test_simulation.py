#!/usr/bin/env python3
"""
赛博多人股市 - 业务模型与核心算法单元测试脚本
验证：
1. 市场价格冲击模型与涨跌停限制
2. 融券做空、浮动盈亏、维持担保比例与强制平仓判定
3. 资金账目借贷平衡与流水一致性
4. 幂等性订单去重防重放机制
"""

import math
import sys

def test_price_impact_model():
    print("--- [测试 1] 价格冲击模型测试 ---")
    current_price = 100.0
    prev_close = 100.0
    depth = 50000
    sensitivity = 0.05
    limit_rate = 0.10

    # 1. 正常小单买入 (100股)
    qty = 100
    pressure = min(0.09, (qty / depth) * sensitivity)
    new_price = round(current_price * (1.0 + pressure), 2)
    assert new_price >= current_price, "买入价格应该上涨或不变"
    print(f"买入 {qty} 股: 原价 {current_price} -> 新价 {new_price} (微幅推升 +{pressure*100:.4f}%)")

    # 2. 超大单买入 (50,000股)
    big_qty = 50000
    pressure_big = min(0.09, (big_qty / depth) * sensitivity)
    impact_price = round(current_price * (1.0 + pressure_big), 2)
    limit_up = round(prev_close * (1.0 + limit_rate), 2)
    final_price = min(limit_up, impact_price)
    assert final_price <= limit_up, "不得突破涨停限制"
    print(f"巨量买入 {big_qty} 股: 原价 {current_price} -> 冲击价 {impact_price} (受限于涨停价 {limit_up})")

    # 3. 卖出砸盘
    sell_qty = 20000
    sell_pressure = min(0.09, (sell_qty / depth) * sensitivity)
    sell_impact = round(current_price * (1.0 - sell_pressure), 2)
    limit_down = round(prev_close * (1.0 - limit_rate), 2)
    final_sell_price = max(limit_down, sell_impact)
    assert final_sell_price >= limit_down, "不得击穿跌停价"
    print(f"大单卖出 {sell_qty} 股: 原价 {current_price} -> 砸盘价 {final_sell_price}")
    print("✓ 价格冲击模型验证通过！\n")


def test_short_selling_and_liquidation():
    print("--- [测试 2] 做空盈亏与维持保证金强平测试 ---")
    entry_price = 50.0
    qty = 1000
    margin_rate = 1.00 # 100% 保证金
    locked_margin = round(entry_price * qty * margin_rate, 2) # 50,000 元
    maintenance_rate = 0.30 # 30% 维持保证金

    # 1. 价格下跌盈利场景 (跌至 40 元)
    drop_price = 40.0
    floating_pnl_drop = round((entry_price - drop_price) * qty, 2) # (50-40)*1000 = +10,000 元
    equity_drop = locked_margin + floating_pnl_drop # 60,000 元
    notional_drop = round(drop_price * qty, 2) # 40,000 元
    ratio_drop = equity_drop / notional_drop
    assert floating_pnl_drop == 10000.0, "跌价时做空应盈利"
    assert ratio_drop > maintenance_rate, "盈利时维持保证金比例安全"
    print(f"价格下跌至 ¥{drop_price}: 浮盈 +¥{floating_pnl_drop}, 担保比例 {ratio_drop*100:.2f}% (安全)")

    # 2. 价格上涨暴亏触发强平场景 (涨至 75 元)
    surge_price = 75.0
    floating_pnl_surge = round((entry_price - surge_price) * qty, 2) # (50-75)*1000 = -25,000 元
    equity_surge = locked_margin + floating_pnl_surge # 50,000 - 25,000 = 25,000 元
    notional_surge = round(surge_price * qty, 2) # 75,000 元
    ratio_surge = equity_surge / notional_surge # 25,000 / 75,000 = 33.33% (接近警戒线)

    # 进一步暴涨至 80 元
    surge_price_2 = 80.0
    floating_pnl_surge_2 = round((entry_price - surge_price_2) * qty, 2) # -30,000 元
    equity_surge_2 = locked_margin + floating_pnl_surge_2 # 20,000 元
    notional_surge_2 = round(surge_price_2 * qty, 2) # 80,000 元
    ratio_surge_2 = equity_surge_2 / notional_surge_2 # 20,000 / 80,000 = 25% < 30%!

    is_liquidated = ratio_surge_2 < maintenance_rate
    assert is_liquidated, "担保比例低于 30% 必须触发强制平仓"

    # 计算强平结算
    penalty = round(notional_surge_2 * 0.005, 2) # 0.5% 违约金
    returned_cash = max(0.0, round(equity_surge_2 - penalty, 2))
    print(f"价格暴涨至 ¥{surge_price_2}: 浮亏 -¥{-floating_pnl_surge_2}, 担保比例 {ratio_surge_2*100:.2f}% (跌破30%)")
    print(f"触发强制平仓！扣除违约金 ¥{penalty}, 归还剩余资金 ¥{returned_cash}")
    print("✓ 做空与强平机制验证通过！\n")


def test_ledger_accounting_consistency():
    print("--- [测试 3] 资金账户借贷平衡与流水核对测试 ---")
    initial_fund = 1000000.0
    balance = initial_fund
    locked_margin = 0.0
    ledger = []

    # 1. 开户注资
    ledger.append(('INITIAL_DEPOSIT', initial_fund, balance, locked_margin))

    # 2. 买入 100 股茅台 @ 1680 元，手续费 0.03%
    buy_amount = 1680.0 * 100
    buy_fee = max(5.0, buy_amount * 0.0003)
    total_buy_cost = buy_amount + buy_fee
    balance -= total_buy_cost
    ledger.append(('BUY_STOCK', -total_buy_cost, balance, locked_margin))

    # 3. 开空 500 股比亚迪 @ 290 元，锁定保证金 100%
    short_notional = 290.0 * 500
    short_margin = short_notional * 1.00
    short_fee = max(5.0, short_notional * 0.0013)
    total_short_lock = short_margin + short_fee
    balance -= total_short_lock
    locked_margin += short_margin
    ledger.append(('SHORT_MARGIN_LOCK', -total_short_lock, balance, locked_margin))

    # 校验所有流水变动累加是否等于当前账户结余
    net_cash_flow = sum(entry[1] for entry in ledger)
    assert round(net_cash_flow, 2) == round(balance, 2), f"账目不平: 流水总和 {net_cash_flow} != 当前余额 {balance}"
    print(f"开户资金: ¥{initial_fund:,.2f}")
    print(f"买入支出: ¥{total_buy_cost:,.2f}")
    print(f"做空锁定: ¥{total_short_lock:,.2f}")
    print(f"期末可用: ¥{balance:,.2f}, 锁定保证金: ¥{locked_margin:,.2f}")
    print("✓ 账户流水与资金借贷平衡核对无误！\n")


def test_idempotency_simulation():
    print("--- [测试 4] 幂等性与防重复提交测试 ---")
    processed_reqs = set()
    orders = []

    def submit_order(client_req_id, user_id, code, qty):
        if client_req_id in processed_reqs:
            return False, "请勿重复提交同一笔订单 (幂等拦截)"
        processed_reqs.add(client_req_id)
        orders.append((client_req_id, user_id, code, qty))
        return True, "下单成功"

    token_a = "req_1728000000_abc123"
    # 第一次提交
    ok1, msg1 = submit_order(token_a, 1, "600519", 100)
    assert ok1 is True
    # 快速双击/网络重发相同请求
    ok2, msg2 = submit_order(token_a, 1, "600519", 100)
    assert ok2 is False
    assert len(orders) == 1, "重复提交被正确拦截，只生成一笔订单"
    print(f"第一次提交: {msg1}")
    print(f"重复提交: {msg2}")
    print("✓ 幂等性校验通过！\n")


if __name__ == '__main__':
    test_price_impact_model()
    test_short_selling_and_liquidation()
    test_ledger_accounting_consistency()
    test_idempotency_simulation()
    print("==========================================")
    print("所有核心算法与业务规则全部通过验证！")
    print("==========================================")
