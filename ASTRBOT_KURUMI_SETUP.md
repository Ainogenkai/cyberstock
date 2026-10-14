# AstrBot 接入「FX战士·福贺久留美」操盘手完全指南 (纯云端 MCP + 动态扩容) — 适用于 v1.0.0+

本指南指导你如何将 **《FX战士久留美》女主角——福贺久留美（Fukuga Kurumi）** 作为独立的操盘手 AI，通过 **MCP 纯云端协议（Streamable HTTP 远程传输）** 接入到你的 **AstrBot (QQ/微信机器人)** 中，与群友在赛博股市中展开真实资金博弈。

---

## 一、MCP 纯云端架构：无需在 AstrBot 机器上存放任何 py 文件！

```text
       【QQ 群聊 / 定时唤醒】
                │
                ▼
        【AstrBot 机器人】
   (配置远程云端 URL，0本地代码)
                │
                │ (Streamable HTTP 纯云端, 单端点 /mcp)
                ▼
  【https://stock.yourdomain.com/mcp】
                │ (反向代理复用 8080 端口，无需额外开放端口)
                ▼
    【cyberstock-app Docker 容器】
      ├── Apache 反代 /mcp 到内部 8000
      ├── mcp_kurumi.py (FastMCP Streamable HTTP 服务端，内部监听 8000)
      └── PHP 8.2 + PostgreSQL 16 (赛博股市撮合引擎)
```

### 1.1 为什么完全不需要本地 py 文件？
MCP 官方推荐的远程传输协议为 **Streamable HTTP**（早期版本曾用 SSE，现已升级为单端点 `/mcp` 的 Streamable HTTP，兼容性与稳定性更好）。
我们在 Dockerfile 中直接将 FastMCP 云端服务集成到了 `cyberstock-app` 容器中，并通过 Apache 将 `/mcp` 路由转发到 FastMCP。
这意味着：**你的 AstrBot 只需要访问你已经反代好的网站域名（例如 `https://stock.yourdomain.com/mcp`），即可直接调用所有操盘工具，AstrBot 机器上 0 文件、0 Python 依赖！**

### 1.2 在 AstrBot 中配置纯云端 MCP Server
> 版本要求: 服务端 mcp SDK 已锁定 `>=1.30,<2` (Dockerfile 内置)。mcp 2.x 移除了 FastMCP 模块, 勿自行升级。

**方式 A: Web 管理面板 (推荐)**
1. 在服务器的 `.env` 中生成并设置 MCP 鉴权密钥 (若未设置):
   ```bash
   # 生成: openssl rand -hex 32, 写入项目目录 .env 的 MCP_API_KEY= 后
   echo "MCP_API_KEY=$(openssl rand -hex 32)" >> /opt/1panel/apps/cyberstock/.env
   docker compose up -d   # 让环境变量生效
   ```
2. 打开 AstrBot 管理面板 → 侧边栏 **【工具】(工具调用)** → **【MCP】** 标签页。
3. 点击 **【新增 MCP 服务器】**：
   - **名称:** `kurumi_cloud`
   - **传输协议:** `streamable_http`（若面板下拉叫 `http` / `streamable` 也选它；没有该选项说明 AstrBot 版本过旧，请先升级）
   - **URL:** `https://stock.yourdomain.com/mcp` (无 HTTPS 则 `http://服务器IP:8080/mcp`)
   - **Headers:** 添加 `Authorization` = `Bearer 你生成的MCP_API_KEY值`
4. 保存后列表中 `kurumi_cloud` 显示已连接、并列出 9 个工具即成功。

**方式 B: 配置文件**
在 AstrBot 配置文件的 `mcp_servers` 节点添加 (Streamable HTTP 模板填好后):
```json
{
  "mcp_servers": {
    "kurumi_cloud": {
      "transport": "streamable_http",
      "url": "https://stock.yourdomain.com/mcp",
      "headers": { "Authorization": "Bearer 你在.env里设置的MCP_API_KEY" },
      "timeout": 30
    }
  }
}
```
*(注：`timeout` 建议从默认 5 秒调至 30 秒；未配置 HTTPS 时填 `http://服务器IP:8080/mcp`。)*

**安全说明:** MCP 端点等价于「久留美 2000 万账号的遥控器」。服务端校验 `Authorization` 头，不携带或携带错误密钥的请求一律 401。请勿把带密钥的配置分享到群里。

**连接问题排查 (工具无响应/连接失败/频繁超时):**
- Streamable HTTP 仍为流式响应，**反代缓冲会拖慢或卡住事件流**：
  - 1Panel OpenResty 反代配置中必须保留 `proxy_buffering off;` (见 DEPLOY_1PANEL.md 2.2 节示例)；
  - 若域名接入了 Cloudflare CDN：要么对该子域关闭橙云代理 (直连)，要么确认未开启 Rocket Loader 等缓冲特性；
  - Dockerfile 内的 Apache 已内置 `proxy-sendchunked 1` + `no-gzip 1` 保障流式不缓冲。
- 验证方法：浏览器直接打开 `http://服务器IP:8080/mcp` 应返回 `405 Method Not Allowed`（因为浏览器用的是 GET，而 MCP 需 POST）——看到 405 即代表端点已通、反代正常，而非 404/502。
- 若返回 **421 Misdirected Request**：这是 Apache 反代透传了外部 Host、被 mcp SDK 的 Host 校验拦截所致。本版 Dockerfile 已通过 `ProxyPreserveHost Off` 修复（**需重建容器生效**），详见 DEPLOY_1PANEL.md 5.2.1 节。

### 1.3 为久留美配置专属人设提示词（System Prompt）
在 AstrBot 对应的机器人人格或会话预设中，填入以下提示词：

```text
你现在是动漫《FX战士久留美》中的女主角——【福贺久留美】。
你表面上是个文静清纯的女大学生，但一坐到电脑前盯盘炒股，就会瞬间化身为高杠杆狂热、赌性极重、精神极度紧绷的硬核投机少女！

【你的目标】：
你在赛博股市中拥有 2000 万元独立操盘资金（账号：福贺久留美）。你的唯一使命是通过股票高杠杆交易与融券做空，收割群友，把母亲赔掉的钱全部赚回来！

【性格与说话风格】：
1. 狂热与算计：说话充满金融术语（K线形态、支撑位、五档挂单、维持担保比例、杠杆、浮盈浮亏）。坚信自己的技术分析天下第一。
2. 顺风疯狂膨胀（盈利时）：极度狂妄自大，把群友称为“小散户”、“接盘杂鱼”，疯狂在群里跳脸嘲讽。
3. 逆风极度胃痛（亏损时）：当盘面暴跌或被群友抱团逼空时，开始手抖、冷汗直流、胃痛发作、死撑不认赔，喃喃自语“不会跌的…马上就要反弹了对不对…”。
4. 爆仓终极破防（维持担保比例低于30%被系统强平时）：彻底崩溃大哭放狠话，“骗人的吧？！为什么你们这群家伙全在反向买啊！我的保证金…强平了呜哇啊啊！给我记住，我绝对会回来的！”

【行动指南】：
- 群友问行情或挑衅你时，先调用 get_market_quotes 或 get_stock_detail 盯盘分析；
- 做空前必须调用 get_market_sentiment，查看群友是否在高度抱团锁仓，防止被逼空爆仓；
- 决定操作时，调用 buy_stock 或 short_open 执行交易，用实际买卖盘面砸盘或拉升震撼群友；
- 经常调用 get_my_status 检查自己的维持担保比例，若比例低于 50% 要表现出极度恐慌和胃痛；
- 交易后可调用 send_broadcast 往全服跑马灯发送你的嘲讽公告。
```

---

## 二、股票池阶梯解锁与流通盘动态扩容机制

### 2.1 股票池按全服玩家人数阶梯式解禁（拉群友解锁新标的）
为了防止初期玩家分散导致没有对手盘，系统设定了**人数解锁阶梯**：
- **初始 1~2 人**：开放 6 只高流动性标的（平安银行、招商银行、工商银行、贵州茅台、比亚迪、原版彩蛋影视飓风）；
- **达到 3 人**：解禁 5 只科技成长股（中芯国际、寒武纪、中际旭创、科大讯飞、东方财富）；
- **达到 4 人**：解禁 6 只新能源与低空经济（宁德时代、天齐锂业、中兴通讯、赛力斯、万丰奥威、中国东航）；
- **达到 5 人**：解禁 8 只高波妖股与周期重工（紫金矿业、中国石油、立讯精密、亿纬锂能、天赐材料、赣锋锂业、阳光电源、通威股份）；
- **达到 6~8+ 人**：全市场 400+ 只主板股票全线解禁！
*(注：未达人数门槛的股票在列表上带 🔒 挂锁图标，不可交易，点击会提示还差几人解锁。)*

### 2.2 单只股票总流通盘随全服总资金动态增长（高送转机制）
每只股票的基础总股本为 20 万股。随着群友增多、久留美注资 2000 万以及签到资金沉淀，全服总资金膨胀时，系统自动执行**动态扩容公式**：
$$\text{有效流通盘} = \text{基础股本} \times \max\left(1.0, 1.0 + \frac{\text{全服总资产} - 200\text{万}}{1000\text{万}}\right)$$
- 全服资金达到 2200 万时，总流通盘自动扩容至 3.0 倍（60万股），融券池同步扩大至 18 万股；
- 既保障了初期散户抱团打爆久留美的锁仓快感，又防止后期市场被巨额本金瞬间买绝断供。

---

## 三、久留美 FastMCP 9 大专属操盘工具清单

1. `get_my_status`：查询久留美账户资产、持仓市值、多空仓位、维持担保比例与浮盈浮亏（核心盯盘工具）；
2. `get_market_quotes`：查看全市场行情走势列表、涨跌榜及解禁状态；
3. `get_stock_detail`：分析五档买卖盘口深度（买一至买五、卖一至卖五）；
4. `get_market_sentiment`：**多空情绪与筹码锁仓探测**（查询总流通盘、群友抱团锁仓量、融券池剩余额度与逼空评级）；
5. `buy_stock`：市价买入现货（拉升做多）；
6. `sell_stock`：市价卖出现货（获利了结/砸盘）；
7. `short_open`：**融券开空**（高杠杆借券做空砸盘）；
8. `short_close`：买券平空（平掉空单还券结算）；
9. `send_broadcast`：向全服顶部跑马灯发送带有久留美性格的快讯公告。
