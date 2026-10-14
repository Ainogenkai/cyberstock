# 1Panel 部署与 OpenResty 反向代理实战指南

本指南专为使用 **1Panel 服务器运维管理面板** 的用户设计，无需复杂命令行操作，通过图形化界面即可快速完成「赛博多人股市」的部署、HTTPS 域名绑定、数据持久化与定时备份。

---

## 一、在 1Panel 中部署 Docker Compose 项目

### 第一步：上传项目代码
1. 登录 1Panel 管理面板。
2. 进入 **【主机】->【文件】**。
3. 进入你常用的应用目录（推荐 `/opt/1panel/apps/cyberstock`，若不存在可新建文件夹）。
4. 将本项目的所有文件完整上传到该目录下，目录结构应如下：
   ```text
   /opt/1panel/apps/cyberstock/
   ├── docker-compose.yml
   ├── Dockerfile
   ├── .env.example
   ├── mcp_kurumi.py          # 必须上传！MCP 云端服务脚本 (compose 会挂载进容器)
   ├── database/
   │   └── init.sql
   ├── docker/
   │   ├── php.ini
   │   └── entrypoint.sh
   └── src/
       ├── index.html
       ├── styles.css
       ├── game.js
       └── api/ ...
   ```

### 第二步：配置环境变量 `.env`
1. 在 1Panel 文件管理中，复制 `.env.example` 为 `.env`。
2. 打开 `.env`，修改敏感密码配置：
   ```ini
   # 宿主机映射端口 (OpenResty 反代的目标端口)
   WEB_PORT=8080

   # 数据库配置 (务必修改复杂密码)
   DB_NAME=cyberstock
   DB_USER=cyberstock_user
   DB_PASSWORD=YourStrongDatabasePassword2026!

   # 系统安全密钥 (32位以上随机字符)
   APP_SECRET=CyberStockSecretKeyChangeMe_2026_Secure!

   # 默认群友注册邀请码
   DEFAULT_INVITE_CODE=CYBER666
   ```
3. 保存并关闭文件。

### 第三步：创建 Compose 编排项目
1. 在 1Panel 左侧菜单中，点击 **【容器】->【编排】**。
2. 点击右上角的 **【创建编排】**：
   - **名称：** `cyberstock`
   - **路径：** 选择刚才上传的目录 `/opt/1panel/apps/cyberstock`
   - **操作：** 点击 **【确认】** 并启动。
3. 1Panel 会自动拉取 `postgres:16-alpine` 镜像并基于 `Dockerfile` 自动构建 PHP 应用镜像，随后自动执行 `database/init.sql` 初始化数据表。
4. 在容器列表中可以看到两个运行正常的容器：
   - `cyberstock-postgres` (状态：Healthy)
   - `cyberstock-app` (端口：`0.0.0.0:8080->80/tcp`)

---

## 二、配置 OpenResty 反向代理与 HTTPS

### 2.1 关键设置：客户端真实 IP 配置 (解决 Cloudflare CDN 穿透)
在 1Panel 网站设置的 **【客户端真实 IP】** 配置弹窗中（针对接入了 Cloudflare CDN 的站点）：
- ❌ **错误配置：** 如果在「IP 来源地址」中填了 `127.0.0.1`，OpenResty 只有在收到本机发出的请求时才会读取 `CF-Connecting-IP`。由于 Cloudflare 的回源请求来自 Cloudflare 的海外边缘节点公网 IP，该配置会导致 OpenResty 无法识别真实 IP，所有群友在后台和日志里都会显示为 Cloudflare 节点 IP。
- 💡 **强烈推荐配置 (一键生效，100% 成功)：**
  - **IP 来源地址：** 填写 `0.0.0.0/0`（若开启了 IPv6，下一行填 `::/0`）
  - **IP Header：** 保持 `CF-Connecting-IP`
  - **原理：** 告知 OpenResty 无论请求从哪个边缘节点回源，均强制提取 Cloudflare 注入的 `CF-Connecting-IP` 作为玩家真实公网 IP。
- 🛡️ **严谨方案 (仅信任 Cloudflare 官方 IP 段)：**
  如果你希望严格仅信任 Cloudflare 官方节点，可将「IP 来源地址」填写为 Cloudflare 官方 CIDR 段（在 1Panel 中换行录入）：
  ```text
  173.245.48.0/20
  103.21.244.0/22
  103.22.200.0/22
  103.31.4.0/22
  141.101.64.0/18
  108.162.192.0/18
  190.93.240.0/20
  188.114.96.0/20
  197.234.240.0/22
  198.41.128.0/17
  162.158.0.0/15
  104.16.0.0/13
  104.24.0.0/14
  172.64.0.0/13
  131.0.72.0/22
  ```

### 2.2 基础反代配置

### 第一步：创建网站
1. 在 1Panel 菜单中点击 **【网站】->【网站】**。
2. 点击 **【创建网站】**，类型选择 **【反向代理】**：
   - **主域名：** 填写你的域名（例如 `stock.yourdomain.com`）。
   - **代理地址：** 填写 `http://127.0.0.1:8080`（即在 `.env` 中指定的 `WEB_PORT`）。
   - 点击 **【确认】**。

### 第二步：配置 SSL 证书 (HTTPS)
1. 在该网站的卡片中点击 **【配置】->【HTTPS】**。
2. 启用 HTTPS：
   - 可以使用 1Panel 内置的 **【Let's Encrypt / ACME】** 申请免费证书，或者直接上传你已有的 SSL 证书。
   - 勾选 **【HTTP 强转 HTTPS】**。
3. 点击 **【保存】**。

### 第三步：优化 OpenResty 反代配置 (高并发与长连接)
进入该网站的 **【配置文件】**，在对应的 `location /` 段落中确保反向代理头部设置齐全：
```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # 保持长连接与轮询优化
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_connect_timeout 60s;
    proxy_send_timeout 60s;
    proxy_read_timeout 60s;

    # 禁用缓存以保证实时行情准确性
    proxy_buffering off;
    proxy_cache_bypass $http_upgrade;
}
```
> 说明：MCP 的 Streamable HTTP 端点 `/mcp` 已由上面的 `location /` 一并反代到 8080，无需单独添加 location。请务必保留 `proxy_buffering off;`，否则流式响应可能被缓冲拖慢。
保存配置后，OpenResty 会自动重载生效。

---

## 三、数据库持久化、备份与数据恢复

### 3.1 数据持久化机制
在 `docker-compose.yml` 中，PostgreSQL 的数据目录映射到 Docker 命名卷：
```yaml
volumes:
  postgres_data:
    name: cyberstock_pgdata
```
**安全说明：** 只要不主动执行 `docker volume rm cyberstock_pgdata`，无论容器停止、重启、升级构建还是更新镜像，所有的玩家账号、虚拟资金、持仓、成交订单与历史行情数据都绝对不会丢失。

### 3.2 备份数据库
#### 方式 A：1Panel 界面备份 (推荐)
1. 进入 1Panel **【计划任务】**。
2. 添加计划任务：
   - **类型：** 执行脚本（或容器备份）。
   - **执行周期：** 每天凌晨 3:00。
   - **脚本内容：**
     ```bash
     BACKUP_DIR="/opt/1panel/backup/cyberstock"
     mkdir -p $BACKUP_DIR
     docker exec -t cyberstock-postgres pg_dump -U cyberstock_user cyberstock | gzip > "$BACKUP_DIR/backup_$(date +%Y%m%d_%H%M%S).sql.gz"
     # 保留最近 14 天备份
     find $BACKUP_DIR -name "*.sql.gz" -mtime +14 -delete
     ```
3. 点击保存即可享受全自动定时备份。

#### 方式 B：手动快速备份 (命令行)
```bash
docker exec -t cyberstock-postgres pg_dump -U cyberstock_user cyberstock > backup_manual.sql
```

### 3.3 数据恢复
如需从历史备份恢复数据：
```bash
# 将备份文件导入正在运行的 postgres 容器中
cat backup_manual.sql | docker exec -i cyberstock-postgres psql -U cyberstock_user -d cyberstock
```

---

## 四、项目平滑更新说明 (Zero Downtime / Safe Update)

当项目代码有更新时：
1. 在 1Panel 文件管理中更新对应的前端或 PHP 文件（由于 `src/` 通过 volume 挂载，前端修改立即生效，无需重启）。
2. 若涉及 `Dockerfile` 依赖变动：
   - 进入 **【容器】->【编排】-> `cyberstock`**。
   - 点击 **【重新构建并启动】**。
   - 数据库卷挂载保持不变，账号与持仓不受任何影响。

---

## 五、常见问题排查 (FAQ)

### 5.1 旧版本部署升级后 admin 无法登录 (重要)
早期版本 init.sql 中的 admin 密码哈希与文档密码 `CyberStock@2026` 不匹配。若你的数据库是在旧版本上初始化的（`ON CONFLICT DO NOTHING` 不会覆盖已有账号），请在宿主机执行以下命令重置 admin 密码：
```bash
docker exec -t cyberstock-postgres psql -U cyberstock_user -d cyberstock -c \
  "UPDATE users SET password_hash = '\$2y\$10\$iR/6MHC4p.XSGLGYL4YGfuYQlJBTcyM1KC7.ZPmPCyEuKajT8DACe' WHERE username = 'admin';"
```
执行后即可用 `admin / CyberStock@2026` 登录管理后台（建议登录后立即在界面修改密码）。全新部署不受影响。

### 5.2 久留美 MCP 服务 (/mcp) 无法连接
1. 检查 `/opt/1panel/apps/cyberstock/mcp_kurumi.py` 是否已上传（compose 将其只读挂载进容器，缺失则 MCP 服务不会启动）。
2. 进入容器查看日志：`docker exec cyberstock-app cat /tmp/mcp.log`。
3. 容器内 MCP 访问 Apache 使用 `CYBERSTOCK_URL=http://127.0.0.1:80`（已在 docker-compose.yml 中配置，请勿改为 8080——那是宿主机映射端口）。
4. 传输协议为 **Streamable HTTP 单端点 `/mcp`**（旧版的 `/sse` 与 `/messages` 已废弃）。AstrBot 端务必按 `ASTRBOT_KURUMI_SETUP.md` 1.2 节配置 `transport: streamable_http` + `url: https://域名/mcp`。
5. 验证端点是否已通：浏览器访问 `http://服务器IP:8080/mcp` 应返回 **405 Method Not Allowed**（说明反代与内部服务正常）；若 404 说明反代未指向 `/mcp`，502 说明容器内 MCP 进程未启动。

#### 5.2.1 访问 /mcp 返回 421 Misdirected Request (重要)
**成因**：mcp SDK 自带的 DNS-rebinding 防护会校验请求的 `Host` 头，白名单默认仅 `127.0.0.1:*` / `localhost:*` / `[::1]:*`。若 Apache 用 `ProxyPreserveHost On` 把外部真实 Host（域名或 `IP:8080`）透传给内部 MCP 服务，SDK 判为非法 Host，直接返回 `421 Invalid Host header`。
**本版已修复**：Dockerfile 中反代已改为 `ProxyPreserveHost Off`——Apache 转发时会把 Host 归一为后端地址 `127.0.0.1:8000`，SDK 始终收到合法 Host，因此域名、IP、端口、甚至日后更换域名都不会再触发 421，同时 SDK 防护保持开启（安全不降级）。
**注意**：此项修改在 Dockerfile 内，**必须重建容器**（用全量包，非日常更新包）才会生效。若重建后仍是 421，检查容器内实际配置是否为 `ProxyPreserveHost Off`：
```bash
docker exec cyberstock-app grep -i proxy /etc/apache2/sites-available/000-default.conf
```

### 5.3 访问全站 500 且 Apache 日志报 "AllowOverride not allowed here"
历史版本的 `src/.htaccess` 中误写了 `AllowOverride All`。该指令**只允许出现在服务器主配置**（VirtualHost / Directory）中，写进 .htaccess 会让 Apache 拒绝读取，导致全站 500——这就是早期部署需要手动删除 .htaccess 的原因。当前版本已修正，`src/.htaccess` 可安全保留，**不要再删除它**（其中的敏感文件禁下载规则需要它生效；Docker 镜像内已内置等效规则作双保险）。

### 5.4 1Panel 上传压缩包解压覆盖时提示权限不足
压缩包会携带文件权限位。旧版本仓库文件权限为 775（组可写、其他只读），在服务器上解压后若属主与当前操作用户不一致，后续覆盖就会报权限不足，形成"每次覆盖都要手动调权限"的循环。解决方法见第六章「标准更新流程」：本地用 `dev/pack.sh` 打包（自动输出标准 644/755 权限），服务器首次部署或出现权限问题时执行一次 `dev/fix-server-perms.sh` 即可永久解决。

---

## 六、标准更新流程 (告别手动调权限)

### 6.1 日常更新 (改前端/接口代码, 推荐 95% 的场景)
`src/` 目录是 volume 挂载，覆盖即生效，**无需重启容器、无需重新构建**：
1. 本地打包日常更新包（只含 `src/`，标准权限）：
   ```bash
   bash dev/pack.sh --src-only
   # 生成 dist/cyberstock-src-YYYYMMDD-HHMMSS.zip
   ```
2. 在 1Panel 文件管理中把 zip 上传到 `/opt/1panel/apps/cyberstock/`。
3. 面板中右键 zip → 解压到当前目录（会直接覆盖 `src/`）→ 完成，浏览器刷新即生效。

### 6.2 全量更新 (Dockerfile / docker-compose / 依赖变动时才需要)
1. 本地打包全量包：
   ```bash
   bash dev/pack.sh
   # 生成 dist/cyberstock-full-YYYYMMDD-HHMMSS.zip
   ```
2. 1Panel 上传到 `/opt/1panel/`（项目目录的上一级），解压覆盖 `cyberstock/` 目录。
   - 若面板解压报权限不足：先在 1Panel 终端执行一次 `bash /opt/1panel/cyberstock/dev/fix-server-perms.sh` 再解压。
3. 进入 【容器】->【编排】-> `cyberstock` → 【重新构建并启动】。

### 6.3 服务器权限一键修复 (首次部署 / 权限混乱时执行一次)
在 1Panel 终端（或 SSH）中执行：
```bash
bash /opt/1panel/apps/cyberstock/dev/fix-server-perms.sh          # 属主 root:root (面板解压默认以 root 运行, 永远可覆盖)
# 若你的 1Panel 文件操作不是 root 用户, 则指定属主:
# bash /opt/1panel/apps/cyberstock/dev/fix-server-perms.sh 1panel
```
脚本会递归将项目文件标准化为 644、目录 755、可执行脚本 755。执行一次后，此后面板解压覆盖不会再报权限错误。
> 兜底：即使忘记执行，应用容器每次启动也会自动补齐 webroot 读取权限（entrypoint 内置自愈），不会影响玩家访问。

### 5.5 修改久留美操盘手账号密码
旧版本久留美密码哈希写死在源码中 (公开风险)。v1.0.0 起种子密码由 `.env` 的 `KURUMI_PASSWORD` 运行时派生——**新建数据库**自动使用你设置的密码。已有数据库需执行一次性重置：
```bash
# 1. 生成新哈希 (把 新密码 换掉)
docker exec cyberstock-app php -r "echo password_hash('新密码', PASSWORD_BCRYPT), PHP_EOL;"
# 2. 写入数据库
docker exec cyberstock-postgres psql -U cyberstock_user -d cyberstock -c \
  "UPDATE users SET password_hash='上一步输出的哈希' WHERE username = '福贺久留美';"
# 3. 同步修改 .env 的 KURUMI_PASSWORD 并 docker compose up -d, 让 MCP 登录与新密码一致
```

### 5.6 MCP_API_KEY 相关
- 未设置 `MCP_API_KEY` 时容器日志会打印无鉴权警告 (MCP 仍可运行, 仅限内网调试)。
- 设置后, 所有 `/mcp` 请求必须携带 `Authorization: Bearer <MCP_API_KEY>`, 否则 401。
- AstrBot 连接后如果一直重试 401, 检查 `.env` 值与 AstrBot headers 中 `Bearer ` 后的值是否完全一致 (无多余空格)。
