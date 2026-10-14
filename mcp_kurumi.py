#!/usr/bin/env python3
"""
赛博多人股市 - AstrBot 专属 MCP Server (FX战士·福贺久留美操盘接口)
支持 FastMCP 协议，可直接挂载到 AstrBot 扩展中。

传输协议:
- stdio      本地挂载 (AstrBot 与脚本同机, 直接 spawn 进程)
- Streamable HTTP  纯云端远程 (默认, 单端点 /mcp, 由 Apache 反代到容器内 8000)

功能:
1. 自动登录久留美独立账号，维持 Session Token
2. 暴露 MCP Tools:
   - get_my_status: 查久留美自己的资金、多空持仓、盈亏与维持担保比例 (盯盘)
   - get_market_quotes: 查看全市场股票行情列表与涨跌榜
   - get_stock_detail: 查看某只股票的五档买卖盘口与详细指标
   - get_market_sentiment: 查询全市场或指定股票的筹码锁仓率、融券池剩余额度与逼空评级 (防爆仓/抓战机)
   - buy_stock: 现货买入 (做多推升价格)
   - sell_stock: 现货卖出 (获利了结/多头抛售)
   - short_open: 融券开空 (高杠杆做空/打压价格)
   - short_close: 买券平空 (空头平仓/止盈止损)
   - send_broadcast: 向全服顶部跑马灯发送久留美的嘲讽或胃痛公告
"""

import os
import sys
import json
import uuid
import hmac
import urllib.request
import urllib.error

# 配置参数 (支持环境变量注入)
# 注意: 本脚本运行在 cyberstock-app 容器内部, Apache 监听 80 端口 (8080 仅是宿主机映射端口)
API_BASE = os.getenv("CYBERSTOCK_URL", "http://127.0.0.1:80").rstrip("/")
USERNAME = os.getenv("KURUMI_USERNAME", "福贺久留美")
PASSWORD = os.getenv("KURUMI_PASSWORD", "KurumiFX2026!")
INVITE_CODE = os.getenv("DEFAULT_INVITE_CODE", "CYBER666")
# MCP 端点鉴权密钥: 与 AstrBot headers 中的 "Authorization: Bearer <key>" 比对 (生成: openssl rand -hex 32)
API_KEY = os.getenv("MCP_API_KEY", "")

SESSION_TOKEN = None


def new_req_id() -> str:
    """每次交易工具调用生成唯一幂等号, 防止网络/LLM 重试导致重复下单"""
    return f"mcp-{uuid.uuid4().hex}"

def http_req(path: str, data: dict = None, token: str = None) -> dict:
    url = f"{API_BASE}{path}"
    headers = {"Content-Type": "application/json"}
    if token or SESSION_TOKEN:
        headers["Authorization"] = f"Bearer {token or SESSION_TOKEN}"
    
    body = json.dumps(data).encode("utf-8") if data is not None else None
    req = urllib.request.Request(url, data=body, headers=headers)
    
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            res_json = json.loads(resp.read().decode("utf-8"))
            if res_json.get("code") != 200:
                raise Exception(res_json.get("message", "API 响应异常"))
            return res_json.get("data")
    except urllib.error.HTTPError as e:
        err_msg = e.read().decode("utf-8")
        try:
            err_json = json.loads(err_msg)
            raise Exception(err_json.get("message", f"HTTP {e.code}"))
        except Exception:
            raise Exception(f"HTTP {e.code}: {err_msg}")

def ensure_login():
    """保证久留美账号已登录并持有有效 Token，未注册则自动注册"""
    global SESSION_TOKEN
    if SESSION_TOKEN:
        try:
            http_req("/api/auth.php?action=me", token=SESSION_TOKEN)
            return SESSION_TOKEN
        except Exception:
            SESSION_TOKEN = None

    # 尝试登录
    try:
        res = http_req("/api/auth.php?action=login", {"username": USERNAME, "password": PASSWORD})
        SESSION_TOKEN = res["token"]
        return SESSION_TOKEN
    except Exception as e:
        # 若用户不存在，尝试自动注册
        try:
            res = http_req("/api/auth.php?action=register", {
                "username": USERNAME,
                "password": PASSWORD,
                "invite_code": INVITE_CODE
            })
            SESSION_TOKEN = res["token"]
            return SESSION_TOKEN
        except Exception as reg_err:
            raise Exception(f"久留美账号登录与注册均失败: 登录异常({e}) / 注册异常({reg_err})")

# ==============================================================
# 使用 FastMCP 暴露 Tools (AstrBot 官方原生标准)
# ==============================================================
try:
    from mcp.server.fastmcp import FastMCP

    # 兼容性说明 (mcp SDK 1.x):
    #  - host/port 是 FastMCP 构造参数 (settings), run_*_async 不接受这些参数
    #  - 1.30 起 FastMCP.run() 快捷方法已移除, 需通过 uvicorn/anyio 调用对应 ASGI/async 方法
    #  - 纯云端传输使用官方 Streamable HTTP 单端点 (/mcp), 取代旧的 SSE 双端点 (/sse + /messages)
    #  - Dockerfile 已锁定 mcp>=1.30,<2 (2.x 移除了本模块, 导入即崩)
    # 监听地址: 默认仅 127.0.0.1 (AstrBot 经容器内 Apache 反代访问, 无需对外暴露 8000 端口)
    _HOST = os.getenv("MCP_HOST", "127.0.0.1")
    _PORT = int(os.getenv("MCP_PORT", "8000"))
    mcp = FastMCP("KurumiTrader", host=_HOST, port=_PORT)
    # Streamable HTTP 单端点路径 (mcp 1.x 默认 /mcp), 用于反代与鉴权对齐
    HTTP_PATH = mcp.settings.streamable_http_path or "/mcp"
    HTTP_SUBPATHS = {HTTP_PATH, HTTP_PATH.rstrip("/") + "/"}

    @mcp.tool()
    def get_my_status() -> str:
        """【久留美盯盘】查询久留美当前的可用资金、持仓市值、多头持仓、空头持仓、维持担保比例与浮动盈亏状态。"""
        ensure_login()
        overview = http_req("/api/portfolio.php?action=overview")
        sum_data = overview.get("summary", {})
        longs = overview.get("long_positions", [])
        shorts = overview.get("short_positions", [])
        
        report = []
        report.append("【福贺久留美 当前账户状态】")
        report.append(f"总资产: ¥{sum_data.get('total_assets', 0):,.2f}")
        report.append(f"可用资金: ¥{sum_data.get('available_cash', 0):,.2f}")
        report.append(f"做空锁定保证金: ¥{sum_data.get('locked_margin', 0):,.2f}")
        report.append(f"多头股票市值: ¥{sum_data.get('long_market_value', 0):,.2f}")
        report.append(f"全仓浮动盈亏: ¥{sum_data.get('total_floating_pnl', 0):,.2f} ({sum_data.get('total_return_percent', 0)}%)")
        
        if longs:
            report.append("\n[多头持仓]:")
            for p in longs:
                report.append(f"- {p['stock_name']}({p['stock_code']}): {p['quantity']}股, 成本¥{p['avg_cost']}, 现价¥{p['current_price']}, 浮盈¥{p['floating_pnl']}({p['floating_pnl_percent']}%)")
        else:
            report.append("\n[多头持仓]: 空仓")

        if shorts:
            report.append("\n[融券做空持仓 (注意维持担保比例，低于30%会被强平爆仓!)]:")
            for p in shorts:
                danger = "⚠️濒临强平!" if p.get('margin_ratio_percent', 100) < 50 else "正常"
                report.append(f"- {p['stock_name']}({p['stock_code']}): 空单{p['quantity']}股, 开空价¥{p['avg_cost']}, 现价¥{p['current_price']}, 浮盈¥{p['floating_pnl']}, 维持比例{p['margin_ratio_percent']}% (强平线¥{p['est_liquidation_price']}) [{danger}]")
        else:
            report.append("\n[做空持仓]: 无空单")

        return "\n".join(report)

    @mcp.tool()
    def get_market_quotes() -> str:
        """【查看大盘】获取全市场所有股票的实时价格、今日涨跌幅与成交量列表。"""
        ensure_login()
        res = http_req("/api/market.php?action=quotes")
        quotes = res.get("quotes", [])
        lines = ["【赛博股市 实时大盘走势】"]
        for q in quotes[:25]:
            sign = "+" if q['change'] > 0 else ""
            lines.append(f"{q['name']}({q['code']}) | 现价: ¥{q['current_price']:.2f} | 涨跌: {sign}{q['change_percent']:.2f}% | 行业: {q['industry']}")
        return "\n".join(lines)

    @mcp.tool()
    def get_stock_detail(stock_code: str) -> str:
        """【盘口分析】查询指定股票的五档买卖盘口（买一到买五、卖一到卖五）与详细指标。"""
        ensure_login()
        res = http_req(f"/api/market.php?action=detail&code={stock_code}")
        s = res.get("stock", {})
        ob = s.get("order_book", {})
        lines = [f"【{s.get('name')}({stock_code}) 盘口深度】"]
        lines.append(f"现价: ¥{s.get('current_price')} | 涨跌幅: {s.get('change_percent')}% | 涨停价: ¥{s.get('limit_up')} | 跌停价: ¥{s.get('limit_down')}")
        lines.append("\n卖盘:")
        for a in ob.get("asks", []):
            lines.append(f"  {a['level']}: ¥{a['price']:.2f} ({a['volume']}股)")
        lines.append("买盘:")
        for b in ob.get("bids", []):
            lines.append(f"  {b['level']}: ¥{b['price']:.2f} ({b['volume']}股)")
        return "\n".join(lines)

    @mcp.tool()
    def get_market_sentiment(stock_code: str = "") -> str:
        """【多空情绪与筹码锁仓探测】查询指定股票或全市场的筹码锁仓率、融券池剩余额度与逼空风险评级。久留美做空前必看，防止被群友抱团锁仓打爆！"""
        ensure_login()
        param = f"&code={stock_code}" if stock_code else ""
        res = http_req(f"/api/market.php?action=sentiment{param}")
        s_data = res.get("sentiment")
        
        if isinstance(s_data, list):
            lines = ["【赛博股市 全服多空筹码风向与逼空监测】"]
            for item in s_data[:15]:
                sqz_tip = " [🚨极高逼空风险!]" if item.get('squeeze_risk') == 'EXTREME_SQUEEZE' else (" [⚠️逼空警戒]" if item.get('squeeze_risk') == 'HIGH_SQUEEZE' else "")
                lines.append(f"{item['name']}({item['code']}): 现价¥{item['current_price']:.2f} | 锁仓率{item['lockup_ratio']}% (余{item['available_shares']}股) | 融券占用{item['short_utilization']}% (余{item['available_short_shares']}股){sqz_tip}")
            return "\n".join(lines)
        elif isinstance(s_data, dict):
            sqz_tip = "【🚨严重警告：群友高度抱团锁仓，空头极度危险！】" if s_data.get('squeeze_risk') == 'EXTREME_SQUEEZE' else ("【⚠️提示：融券池紧张或锁仓率较高】" if s_data.get('squeeze_risk') == 'HIGH_SQUEEZE' else "【市场流动性正常】")
            return (
                f"【{s_data.get('name')}({s_data.get('code')}) 筹码与融券深度】\n"
                f"现价: ¥{s_data.get('current_price'):.2f}\n"
                f"总流通盘: {s_data.get('total_shares'):,} 股 | 群友锁仓: {s_data.get('held_shares'):,} 股 ({s_data.get('lockup_ratio')}%) | 剩余流通: {s_data.get('available_shares'):,} 股\n"
                f"融券池上限: {s_data.get('max_short_shares'):,} 股 | 已借出做空: {s_data.get('borrowed_short_shares'):,} 股 ({s_data.get('short_utilization')}%) | 剩余可借: {s_data.get('available_short_shares'):,} 股\n"
                f"风控评级: {sqz_tip}"
            )
        return "未获取到多空筹码数据"

    @mcp.tool()
    def buy_stock(stock_code: str, quantity: int) -> str:
        """【执行买入】以市价买入指定股票。会消耗可用资金，并对股价产生向上的拉升买压。"""
        ensure_login()
        res = http_req("/api/trade.php?action=buy", {"stock_code": stock_code, "quantity": quantity, "client_req_id": new_req_id()})
        return f"买入执行成功！以现价 ¥{res['executed_price']} 成交 {quantity} 股 {res['stock_name']}，推动股价至 ¥{res['new_market_price']}，当前剩余可用资金: ¥{res['balance']:,.2f}"

    @mcp.tool()
    def sell_stock(stock_code: str, quantity: int) -> str:
        """【执行卖出】以市价卖出已持有的多头股票。到账资金并对股价产生砸盘压力。"""
        ensure_login()
        res = http_req("/api/trade.php?action=sell", {"stock_code": stock_code, "quantity": quantity, "client_req_id": new_req_id()})
        return f"卖出执行成功！以现价 ¥{res['executed_price']} 成交 {quantity} 股，实现盈亏 ¥{res['realized_pnl']}，打压股价至 ¥{res['new_market_price']}，当前剩余持仓: {res['remaining_quantity']} 股"

    @mcp.tool()
    def short_open(stock_code: str, quantity: int) -> str:
        """【融券做空/开空】以当前市价开空某股票。锁定 100% 保证金，对股票产生强大砸盘打压卖压！股价下跌时盈利，上涨时亏损。"""
        ensure_login()
        res = http_req("/api/short.php?action=open", {"stock_code": stock_code, "quantity": quantity, "client_req_id": new_req_id()})
        return f"做空开仓成功！以现价 ¥{res['price']} 借入开空 {quantity} 股 {res['stock_name']}，锁定保证金 ¥{res['locked_margin']:,.2f}，打压股价至最新价。当前空单均价 ¥{res['avg_cost']}"

    @mcp.tool()
    def short_close(stock_code: str, quantity: int) -> str:
        """【买券平空/平仓】买回股票平掉空头仓位。释放保证金并结算最终盈亏。买还操作会对股价产生推升买压。"""
        ensure_login()
        res = http_req("/api/short.php?action=close", {"stock_code": stock_code, "quantity": quantity, "client_req_id": new_req_id()})
        return f"平仓买还成功！以 ¥{res['price']} 购回 {quantity} 股，实现已实现盈亏: ¥{res['realized_pnl']}，返还可用资金: ¥{res['returned_cash']:,.2f}，剩余空单: {res['remaining_short_quantity']} 股"

    @mcp.tool()
    def send_broadcast(title: str, content: str) -> str:
        """【全服公告】向股市顶部的跑马灯横幅发布带有久留美性格的快讯公告，全服玩家均可见。(限频：每分钟 1 条，标题≤60字、正文≤200字)"""
        ensure_login()
        if len(title) > 60 or len(content) > 200:
            return f"发送失败：标题需≤60字、正文需≤200字 (当前标题{len(title)}字、正文{len(content)}字)"
        res = http_req("/api/events.php?action=broadcast", {
            "title": title,
            "content": content,
            "duration_sec": 180
        })
        return f"已向全服跑马灯发布公告: 【{res['title']}】{content}"

    if __name__ == "__main__":
        import anyio

        # 传输选择: --http / --streamable-http (纯云端, 单端点 /mcp) 或 stdio (本地挂载, 默认)
        # 兼容: 旧的 --sse / MCP_TRANSPORT=sse 归一到 http, 并打印迁移提示 (方便旧部署平滑过渡)
        argv = set(sys.argv[1:])
        env_transport = (os.getenv("MCP_TRANSPORT") or "").strip().lower()
        want_http = bool({"--http", "--streamable-http", "--streamable_http"} & argv) or env_transport in ("http", "streamable-http", "streamable_http")
        legacy_sse = ("--sse" in argv) or env_transport == "sse"
        transport = "http" if (want_http or legacy_sse) else "stdio"

        if transport == "http":
            if legacy_sse and not want_http:
                print("[Kurumi MCP] 提示: --sse / MCP_TRANSPORT=sse 已废弃, 已自动切换为 Streamable HTTP (/mcp)")
            if API_KEY:
                # 带鉴权: 对 Streamable HTTP 端点 (/mcp) 校验 "Authorization: Bearer <MCP_API_KEY>"
                from starlette.responses import JSONResponse

                class BearerAuthMiddleware:
                    """纯 ASGI 中间件 (零额外依赖): 密钥不匹配一律 401"""

                    def __init__(self, app):
                        self.app = app

                    async def __call__(self, scope, receive, send):
                        if scope["type"] == "http" and scope["path"].rstrip("/") in {p.rstrip("/") for p in HTTP_SUBPATHS}:
                            req_headers = {
                                k.decode("latin-1").lower(): v.decode("latin-1")
                                for k, v in scope.get("headers", [])
                            }
                            provided = req_headers.get("authorization", "")
                            if not hmac.compare_digest(provided, f"Bearer {API_KEY}"):
                                resp = JSONResponse(
                                    {"error": "unauthorized: invalid or missing MCP API key"},
                                    status_code=401,
                                )
                                await resp(scope, receive, send)
                                return
                        await self.app(scope, receive, send)

                import uvicorn
                print(f"[Kurumi MCP] Streamable HTTP 模式 (API Key 鉴权已启用): {_HOST}:{_PORT}{HTTP_PATH}")
                uvicorn.run(BearerAuthMiddleware(mcp.streamable_http_app()), host=_HOST, port=_PORT, log_level="info")
            else:
                print(f"[Kurumi MCP] ★★★ 警告: 未设置 MCP_API_KEY, {HTTP_PATH} 端点无鉴权运行! ★★★")
                print("[Kurumi MCP] 任何人拿到 URL 都能用久留美的账号交易。请在 .env 设置 MCP_API_KEY")
                print("[Kurumi MCP] (生成: openssl rand -hex 32) 并在 AstrBot 的 headers 中携带。")
                import uvicorn
                uvicorn.run(mcp.streamable_http_app(), host=_HOST, port=_PORT, log_level="info")
        else:
            anyio.run(mcp.run_stdio_async)

except ImportError:
    print("提示: 运行此脚本需要安装官方 MCP SDK: pip install mcp", file=sys.stderr)
    if __name__ == "__main__":
        print(f"正在测试连接股市服务: {API_BASE}...")
        try:
            token = ensure_login()
            print(f"久留美账号自动就绪！Token: {token[:12]}...")
            overview = http_req("/api/portfolio.php?action=overview")
            print("久留美资产概况:", overview.get("summary"))
            print("连接成功！请在 AstrBot 环境中通过 fastmcp 挂载此脚本。")
        except Exception as e:
            print(f"连接失败: {e}", file=sys.stderr)
