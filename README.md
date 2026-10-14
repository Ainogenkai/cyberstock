# 赛博多人股市 (Cyber Stock Simulator)

![Version](https://img.shields.io/badge/version-v1.0.0-blue) ![License](https://img.shields.io/badge/license-MIT-green)

基于开源项目 [Stock-simulator](https://github.com/ljy969/Stock-simulator)（MIT License, Copyright (c) 2026 MOX）改造的**简体中文优先、支持多人同时在线交易的虚拟股票市场**。专供私人社群、群友娱乐与策略对抗。

本项目为上述开源项目的衍生改造作品，遵循 MIT 许可证发布，版权与许可全文见根目录 [LICENSE](LICENSE)。主要改造包括：服务端权威撮合、多人实时交易、融券做空与强平、自动交易策略引擎、成就与自选股系统、AstrBot MCP 操盘手接入等。

> **v1.0.0 正式版** — 首个全功能可用版本。当前版本号见仓库根目录 `VERSION` 文件；容器启动时也会打印版本横幅。

---

## 一、改造背景与项目定位

原版 Stock-simulator 是一个纯前端运行的本地模拟炒股游戏，所有资金、行情和交易数据均保存在玩家浏览器的 LocalStorage 中，每位玩家看到的股票价格是由前端各自随机生成的，无法进行真正的联机互动。

本项目在**最大限度保留原项目经典的界面风格、交互逻辑、Canvas K线图表与全量简体中文文案**的基础上，进行了彻底的架构重构：
1. **服务端权威（Server Authority）：** 所有股票报价、订单撮合、成交记录、K线、持仓和资金均由服务端（PHP + PostgreSQL）统一计算与落库，绝不信任客户端上报的任何金额或持仓。
2. **万人同屏同市：** 玩家 A 买入或做空，玩家 B、C 看到的股票价格、盘口深度与成交明细同步按确定性规则演变。
3. **融券做空机制：** 新增完整的做空交易闭环，支持开空、平空、保证金锁定、维持担保比例监测与连环强制平仓。
4. **社群行为驱动事件：** 真实玩家集中扫货、集体做空或极端暴跌时，系统实时触发全服广播事件并对行情施加影响；同时支持现实经济与科技新闻映射。
5. **群友邀请码与权限隔离：** 采用邀请码防刷注册，配备管理员控制台、操作审计日志与资金账目双向核对。

---

## 二、技术选型与目录结构

- **前端：** 原生 HTML5 + CSS3 + JavaScript (ES6+)，Canvas 2D 自绘 K 线图与成交量柱状图，五档深度盘口。
- **后端：** PHP 8.2（轻量、低开销、高并发事务处理、PDO-pgSQL）。
- **数据库：** PostgreSQL 16（ACID 强事务、行级锁 `FOR UPDATE`、高可靠持久化卷）。
- **部署方式：** Docker Compose，深度兼容 1Panel 图形化面板一键管理。
- **反向代理：** OpenResty / Nginx 统一提供 HTTPS 访问与证书管理。

```text
├── docker-compose.yml       # 1Panel 兼容的 Docker Compose 编排文件
├── Dockerfile               # PHP 8.2 + Apache + pgsql 运行环境镜像构建
├── .env.example             # 环境变量配置模板
├── database/
│   └── init.sql             # PostgreSQL 12 张表结构初始化及种子数据
├── docker/
│   └── php.ini              # PHP 性能调优与安全配置
├── src/
│   ├── index.html           # 主页面 (保留原版 UI 布局与多人交互组件)
│   ├── styles.css           # 样式表 (深色/浅色主题、A股红涨绿跌配色)
│   ├── game.js              # 前端核心驱动 (行情轮询、Canvas图表、交易表单)
│   ├── stockData.js         # 股票池元数据定义
│   ├── i18n.js              # 国际化管理器
│   ├── locales/
│   │   └── zh-CN.js         # 简体中文词典 (100% 覆盖)
│   └── api/
│       ├── config.php       # 全局参数、常量、响应助手
│       ├── db.php           # PDO 单例与事务管理
│       ├── auth.php         # 注册、登录、邀请码校验、Session 会话
│       ├── market.php       # 统一行情报价、K线生成、五档盘口、Tick引擎
│       ├── trade.php        # 现货买入、卖出、并发行级锁、价格冲击模型
│       ├── short.php        # 做空开仓、平仓、维持保证金计算与强制平仓
│       ├── events.php       # 玩家异动事件触发器与现实新闻映射
│       ├── portfolio.php    # 个人资产概览、多空持仓、流水与成交明细
│       └── admin.php        # 邀请码生成、用户资产调控、市场暂停审计
├── tests/
│   ├── test_simulation.py  # 核心数学模型、做空强平与账目一致性验证
│   ├── test_sql.py         # 数据库脚本表结构完整性验证
│   ├── test_php_syntax.py  # 后端接口语法结构测试
│   └── test_frontend.py    # 前端 DOM 与脚本绑定验证
└── DEPLOY_1PANEL.md         # 1Panel 部署与 OpenResty 反代实战指南
```

---

## 三、核心交易与金融模型

### 3.1 订单对价格的冲击模型 (Market Impact)
为了使群友交易对股价产生可预测、可复现的影响，同时避免单笔订单恶意操纵股价，系统采用基于市场流动性深度的价格冲击模型：

$$\Delta P = P_t \times \min\left(0.09, \frac{Q}{D_i} \times \lambda\right)$$

- $P_t$：股票当前价格。
- $Q$：交易股数。
- $D_i$：标的基准流动性深度（如 50,000 股）。
- $\lambda$：市场冲击敏感度系数（默认 0.05）。
- **买入与平空：** 施加正向价格推升压力（$+\Delta P$）。
- **卖出与开空：** 施加负向打压价格压力（$-\Delta P$）。
- **边界约束：** 任何成交后的新价格严守涨跌停限制 $[P_{prev} \times 0.90, P_{prev} \times 1.10]$。单 Tick 异常波动超过 5% 时触发临时熔断冷静期。

### 3.2 融券做空与强制平仓规则 (Short Selling & Liquidation)
1. **开仓做空：**
   - 初始保证金比例：$100\%$。即按现价做空 1000 股单价 50 元的股票，需锁定可用资金 $50,000$ 元作为保证金。
   - 用户可用资金减少 $50,000$ 元，`locked_margin` 增加 $50,000$ 元。
2. **盈亏计算：**
   - 浮动盈亏：$(开仓均价 - 当前现价) \times 做空股数$。股价下跌盈利，上涨亏损。
   - 持仓净值（Equity）：$锁定保证金 + 浮动盈亏$。
3. **维持担保比例与强制平仓：**
   - 维持担保比例：$\frac{持仓净值}{当前现价 \times 做空股数}$。
   - 当担保比例低于 **30%** 时，系统判定该仓位出现穿仓风险，自动执行**强制平仓（Liquidation）**：
     - 以市价全额买券平仓，扣除 0.5% 违约惩罚金；
     - 归还剩余保证金至用户可用资金；
     - 平仓产生的买压进一步推高股价，并自动向全服广播**【逼空强平异动】**事件。

### 3.3 玩家行为驱动的事件系统
- **集中扫货（MARKET_SURGE）：** 当 2 分钟内某标的玩家多头买入量突破阈值（>2000股）且显著高于卖单时自动触发。
- **空头围猎（SHORT_SQUEEZE）：** 当大量群友集中开空某标的突破阈值时自动触发，盘面面临空头重压。
- **现实与行业资讯映射（REAL_MAPPING）：** 内置宏观、半导体芯片、新能源电池、白酒消费、创新生物医药等现实产业政策事件池，定期按权重释放，产生产业级漂移因子。

---

## 四、安全与并发设计

1. **防超卖与防负余额（悲观锁）：**
   - 交易时通过 PostgreSQL `SELECT balance FROM users WHERE id = :id FOR UPDATE` 锁定资金；
   - 针对持仓使用 `SELECT ... FROM positions ... FOR UPDATE` 锁定股份，彻底杜绝并发超卖、余额透支或双花问题。
2. **幂等性保障：**
   - 客户端下单自动附带 `client_req_id`，数据库基于用户与请求 ID 唯一性排重，防止弱网环境重复点击扣款。
3. **资金账目流水可追溯：**
   - 任何资金变动均双向记录至 `account_ledger` 表，支持审计对账：期初资金 + 累计流水 = 期末可用资金。
4. **数据库与网络隔离：**
   - PostgreSQL 容器不对公网暴露 5432 端口，仅通过内部 Docker Bridge 网络与 PHP 容器通信。

---

## 五、快速启动 (本地 / 服务器)

```bash
# 1. 复制配置文件
cp .env.example .env

# 2. 启动服务 (后台运行)
docker compose up -d --build

# 3. 检查服务健康状态
docker compose ps
```

启动完成后，通过浏览器访问 `http://服务器IP:8080` 即可畅玩！
默认管理员账号：`admin`，默认密码：`CyberStock@2026`（建议首次登录后修改）。
默认群友注册邀请码：`CYBER666`。
详细 1Panel 部署与 OpenResty 域名 HTTPS 反向代理步骤，请参阅 [DEPLOY_1PANEL.md](./DEPLOY_1PANEL.md)。

### 5.1 本地免 Docker 部署快速预览 (开发调试)

不想构建 Docker 应用镜像、只想本地看看实际效果时，可用一键预览脚本（仅需本机有 docker 与 postgres:15-alpine 镜像，PHP 运行时会自动准备）：

```bash
bash dev/preview.sh start   # 启动预览: http://127.0.0.1:8099
bash dev/preview.sh stop    # 停止预览 (数据库数据保留)
bash dev/preview.sh down    # 停止并清空预览数据
```

- 预览使用 PHP 内置服务器 + 独立临时 PostgreSQL（端口 15432），与生产 Docker Compose 完全隔离。
- 预览数据持久化在命名卷 `cyberstock_preview_pgdata` 中，重启电脑后 `start` 即可恢复。
- 自定义端口：`PREVIEW_PORT=9000 bash dev/preview.sh start`。
