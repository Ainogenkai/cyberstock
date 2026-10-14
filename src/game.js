/**
 * 赛博多人股市 (Cyber Stock Simulator) - 生产环境核心控制器
 * 严格复用原版 Stock-simulator 界面结构、Canvas K线绘制与视觉交互
 * 全量数据与交易实时由服务端 (PHP 8.2 + PostgreSQL) 统一计算与结算
 */

class CyberStockApp {
    constructor() {
        this.currentUser = null;
        this.stocks = [];
        this.selectedStock = null;
        this.candles = [];
        this.pollingTimer = null;
        this.activeEvents = [];
        this.currentSort = { field: 'code', order: 'asc' };
        this.searchKeyword = '';
        this.longPositions = [];
        this.shortPositions = [];
        this.watchlist = new Set(JSON.parse(localStorage.getItem('cyberstock_watchlist') || '[]'));
        this.showOnlyWatchlist = false;
        this.autoTradeActiveTab = 'condition';
        this.autoStrategies = [];
        this.autoRiskConfig = null;
        this.portfolioData = null;
        this.achievementsExpanded = false;

        this.init();
    }

    async init() {
        if (window.I18n) {
            window.I18n.init ? window.I18n.init() : window.I18n.applyToDOM();
        }
        this.bindEvents();
        await this.checkAuth();
        this.startMarketPolling();
    }

    // ==============================================================
    // 1. 网络请求包装与鉴权
    // ==============================================================
    async api(url, options = {}) {
        options.headers = options.headers || {};
        options.headers['Content-Type'] = 'application/json';
        const token = localStorage.getItem('cyberstock_token');
        if (token) {
            options.headers['Authorization'] = `Bearer ${token}`;
        }

        try {
            const res = await fetch(url, options);
            const json = await res.json();
            if (!res.ok || json.code !== 200) {
                const errMsg = json.message || `请求失败 (HTTP ${res.status})`;
                const err = new Error(errMsg);
                err.code = json.code || res.status;
                throw err;
            }
            return json.data;
        } catch (err) {
            console.error('API Error:', err);
            throw err;
        }
    }

    async checkAuth() {
        try {
            const data = await this.api('/api/auth.php?action=me');
            this.currentUser = data.user;
            this.showScreen('main-screen');
            this.updateHeaderUser();
        } catch (e) {
            // 未登录状态，展示登录注册大厅
            this.currentUser = null;
            this.showScreen('auth-screen');
        }
    }

    showScreen(screenId) {
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
        const target = document.getElementById(screenId);
        if (target) target.classList.add('active');
    }

    showPage(pageId) {
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));

        const targetPage = document.getElementById(`${pageId}-page`);
        if (targetPage) targetPage.classList.add('active');

        const targetBtn = document.querySelector(`.nav-btn[data-page="${pageId}"]`);
        if (targetBtn) targetBtn.classList.add('active');

        if (pageId === 'portfolio') this.fetchPortfolio();
        if (pageId === 'events') this.fetchEventsFeed();
        if (pageId === 'admin') this.loadAdminDashboard();
        if (pageId === 'auto-trade') this.loadAutoTradePage();
        if (pageId === 'profile') this.loadProfilePage();
        if (pageId === 'trade' && this.selectedStock) {
            this.syncTradeStock(this.selectedStock.code);
        }
    }

    updateHeaderUser() {
        const adminBtn = document.getElementById('nav-admin-btn');
        if (adminBtn) {
            adminBtn.style.display = (this.currentUser && this.currentUser.role === 'admin') ? 'inline-block' : 'none';
        }
    }

    async logout() {
        try {
            await this.api('/api/auth.php?action=logout');
        } catch (e) {}
        localStorage.removeItem('cyberstock_token');
        this.currentUser = null;
        this.showToast('已安全退出登录', 'info');
        this.showScreen('auth-screen');
    }

    // ==============================================================
    // 2. 服务端实时行情轮询驱动
    // ==============================================================
    startMarketPolling() {
        this.fetchMarketQuotes();
        this.fetchEventsBanner();

        if (this.pollingTimer) clearInterval(this.pollingTimer);
        this.pollingTimer = setInterval(() => {
            this.fetchMarketQuotes(true);
            this.fetchEventsBanner();
            if (this.selectedStock) {
                this.fetchStockDetail(this.selectedStock.code, true);
                this.fetchCandles(this.selectedStock.code);
            }
            const activePage = document.querySelector('.page.active');
            if (activePage && activePage.id === 'portfolio-page') {
                this.fetchPortfolio(true);
            }
            if (activePage && activePage.id === 'auto-trade-page') {
                this.fetchAutoTradeStrategies(true);
            }
        }, 3000);
    }

    async fetchMarketQuotes(silent = false) {
        try {
            const data = await this.api('/api/market.php?action=quotes');
            this.stocks = data.quotes || [];
            this.playerCount = data.player_count || 1;
            this.capitalMultiplier = data.capital_multiplier || 1.0;
            if (!this.selectedStock && this.stocks.length > 0) {
                await this.selectStock(this.stocks[0].code);
            } else {
                this.renderStockList();
            }
        } catch (err) {
            if (!silent) this.showToast('同步行情失败: ' + err.message, 'error');
        }
    }

    async fetchStockDetail(code, silent = false) {
        try {
            const data = await this.api(`/api/market.php?action=detail&code=${encodeURIComponent(code)}`);
            this.selectedStock = data.stock;
            this.renderStockDetail();
        } catch (err) {
            if (!silent) this.showToast('获取股票详情失败: ' + err.message, 'error');
        }
    }

    async fetchCandles(code) {
        try {
            const data = await this.api(`/api/market.php?action=candles&code=${encodeURIComponent(code)}&limit=60`);
            this.candles = data.candles || [];
            this.drawKLine();
        } catch (err) {
            console.error('KLine Error:', err);
        }
    }

    async fetchEventsBanner() {
        try {
            const data = await this.api('/api/events.php?action=list&limit=5');
            this.activeEvents = data.events || [];
            const tickerEl = document.getElementById('event-ticker-text');
            if (!tickerEl) return;
            const active = this.activeEvents.filter(e => e.is_active);
            if (active.length === 0) {
                tickerEl.textContent = '当前市场运行平稳，全服暂无极端行情异动。';
            } else {
                tickerEl.textContent = active.map(e => `【${e.title}】${e.content}`).join('   ★   ');
            }
        } catch (e) {}
    }

    // ==============================================================
    // 3. 原版 DOM 元素渲染 (股票列表、五档盘口、标的详情)
    // ==============================================================
    renderStockList() {
        const listContainer = document.getElementById('stock-list');
        if (!listContainer) return;

        if (this.stocks.length === 0) {
            listContainer.innerHTML = '<div style="padding:30px; text-align:center; color:var(--text-secondary); font-size:13px;">正在加载大盘股票列表...</div>';
            return;
        }

        let filtered = this.stocks.filter(s => {
            const kw = this.searchKeyword.toLowerCase();
            return s.code.toLowerCase().includes(kw) || s.name.toLowerCase().includes(kw) || s.industry.toLowerCase().includes(kw);
        });

        if (this.showOnlyWatchlist) {
            filtered = filtered.filter(s => this.watchlist.has(s.code));
            if (filtered.length === 0) {
                listContainer.innerHTML = '<div style="padding:30px; text-align:center; color:var(--text-secondary); font-size:13px;">暂无自选股，点击股票名称旁的 ☆ 即可添加</div>';
                return;
            }
        }

        const { field, order } = this.currentSort;
        filtered.sort((a, b) => {
            let va = a[field], vb = b[field];
            if (field === 'price') { va = a.current_price; vb = b.current_price; }
            if (field === 'change') { va = a.change_percent; vb = b.change_percent; }
            if (va < vb) return order === 'asc' ? -1 : 1;
            if (va > vb) return order === 'asc' ? 1 : -1;
            return 0;
        });

        listContainer.innerHTML = filtered.map(s => {
            const isSelected = this.selectedStock && this.selectedStock.code === s.code;
            const diffClass = s.change > 0 ? 'up' : (s.change < 0 ? 'down' : '');
            const sign = s.change > 0 ? '+' : '';
            const isLocked = (s.is_unlocked === false);
            const lockBadge = isLocked ? ('<span style="color:#faad14; font-size:11px; margin-left:4px;">🔒满' + s.unlock_required_users + '人</span>') : '';
            const isStarred = this.watchlist.has(s.code);
            return `
                <div class="stock-item ${isSelected ? 'active' : ''} ${isLocked ? 'locked-stock' : ''}" onclick="window.game.selectStock('${s.code}')">
                    <div class="stock-item-info">
                        <div class="name">
                            <span class="star-icon ${isStarred ? 'starred' : ''}" style="cursor:pointer; margin-right:4px; font-size:13px; color:${isStarred ? '#ffd700' : 'var(--text-muted)'};" onclick="event.stopPropagation(); window.game.toggleWatchlist('${s.code}')" title="${isStarred ? '取消自选' : '添加自选'}">${isStarred ? '⭐' : '☆'}</span>
                            ${this.escape(s.name)}${lockBadge}
                        </div>
                        <div class="code">${s.code}</div>
                    </div>
                    <div class="stock-item-price ${diffClass}">¥${s.current_price.toFixed(2)}</div>
                    <div class="stock-item-change ${diffClass}">${sign}${s.change_percent.toFixed(2)}%</div>
                </div>
            `;
        }).join('');
    }

    toggleWatchlist(code) {
        if (this.watchlist.has(code)) {
            this.watchlist.delete(code);
            this.showToast('已从自选股移除: ' + code, 'info');
        } else {
            this.watchlist.add(code);
            this.showToast('已添加到自选股: ' + code, 'success');
        }
        localStorage.setItem('cyberstock_watchlist', JSON.stringify([...this.watchlist]));
        this.renderStockList();
    }

    async selectStock(code) {
        await Promise.all([
            this.fetchStockDetail(code),
            this.fetchCandles(code)
        ]);
        this.renderStockList();
    }

    renderStockDetail() {
        if (!this.selectedStock) return;
        const s = this.selectedStock;

        const nameEl = document.getElementById('detail-name');
        const codeEl = document.getElementById('detail-code');
        const priceEl = document.getElementById('detail-price');
        const changeEl = document.getElementById('detail-change');

        if (nameEl) nameEl.textContent = s.name;
        if (codeEl) codeEl.textContent = s.code;

        const diffClass = s.change > 0 ? 'up' : (s.change < 0 ? 'down' : '');
        const sign = s.change > 0 ? '+' : '';

        if (priceEl) {
            priceEl.textContent = '¥' + s.current_price.toFixed(2);
            priceEl.className = 'price ' + diffClass;
        }
        if (changeEl) {
            changeEl.textContent = `${sign}${s.change.toFixed(2)} (${sign}${s.change_percent.toFixed(2)}%)`;
            changeEl.className = 'change ' + diffClass;
        }

        const lUp = document.getElementById('limit-up-price');
        const lDown = document.getElementById('limit-down-price');
        if (lUp) lUp.textContent = '¥' + s.limit_up.toFixed(2);
        if (lDown) lDown.textContent = '¥' + s.limit_down.toFixed(2);

        // 渲染流通盘锁仓与融券借券池数据
        const totEl = document.getElementById('detail-total-shares');
        const heldEl = document.getElementById('detail-held-shares');
        const lockRatioEl = document.getElementById('detail-lockup-ratio');
        const availEl = document.getElementById('detail-avail-shares');
        const availShortEl = document.getElementById('detail-avail-short');
        const maxShortEl = document.getElementById('detail-max-short');
        const squeezeBadge = document.getElementById('detail-squeeze-badge');

        const tot = s.total_shares || 200000;
        const held = s.held_shares || 0;
        const avail = Math.max(0, tot - held);
        const maxShort = s.max_short_shares || 60000;
        const borrowed = s.borrowed_short_shares || 0;
        const availShort = Math.max(0, maxShort - borrowed);
        const lockRatio = ((held / Math.max(1, tot)) * 100).toFixed(1);

        if (totEl) totEl.textContent = tot.toLocaleString('zh-CN');
        if (heldEl) heldEl.textContent = held.toLocaleString('zh-CN');
        if (lockRatioEl) lockRatioEl.textContent = lockRatio + "%";
        if (availEl) availEl.textContent = avail.toLocaleString('zh-CN');
        if (availShortEl) availShortEl.textContent = availShort.toLocaleString('zh-CN');
        if (maxShortEl) maxShortEl.textContent = maxShort.toLocaleString('zh-CN');

        if (squeezeBadge) {
            if (borrowed > maxShort * 0.7 && held > tot * 0.6) {
                squeezeBadge.style.display = 'inline-block';
                squeezeBadge.textContent = '🚨 极高逼空爆发风险';
            } else if (borrowed > maxShort * 0.4 || held > tot * 0.4) {
                squeezeBadge.style.display = 'inline-block';
                squeezeBadge.textContent = '⚠️ 逼空警戒';
            } else {
                squeezeBadge.style.display = 'none';
            }
        }

        // 渲染五档买卖盘口
        if (s.order_book) {
            s.order_book.asks.forEach((ask, idx) => {
                const lvl = idx + 1;
                const pEl = document.getElementById(`ask${lvl}-price`);
                const vEl = document.getElementById(`ask${lvl}-vol`);
                if (pEl) {
                    pEl.textContent = '¥' + ask.price.toFixed(2);
                    pEl.className = 'price down';
                }
                if (vEl) vEl.textContent = Math.round(ask.volume / 100);
            });

            s.order_book.bids.forEach((bid, idx) => {
                const lvl = idx + 1;
                const pEl = document.getElementById(`bid${lvl}-price`);
                const vEl = document.getElementById(`bid${lvl}-vol`);
                if (pEl) {
                    pEl.textContent = '¥' + bid.price.toFixed(2);
                    pEl.className = 'price up';
                }
                if (vEl) vEl.textContent = Math.round(bid.volume / 100);
            });
        }
    }

    // ==============================================================
    // 4. 原版自绘制 Canvas K 线图与成交量柱
    // ==============================================================
    drawKLine() {
        const kCanvas = document.getElementById('kline-canvas');
        const vCanvas = document.getElementById('volume-canvas');
        if (!kCanvas || !vCanvas || this.candles.length === 0) return;

        const kCtx = kCanvas.getContext('2d');
        const vCtx = vCanvas.getContext('2d');

        const dpr = window.devicePixelRatio || 1;
        const kWidth = kCanvas.clientWidth || 600;
        const kHeight = kCanvas.clientHeight || 260;
        const vWidth = vCanvas.clientWidth || 600;
        const vHeight = vCanvas.clientHeight || 80;

        kCanvas.width = kWidth * dpr;
        kCanvas.height = kHeight * dpr;
        kCtx.scale(dpr, dpr);

        vCanvas.width = vWidth * dpr;
        vCanvas.height = vHeight * dpr;
        vCtx.scale(dpr, dpr);

        kCtx.clearRect(0, 0, kWidth, kHeight);
        vCtx.clearRect(0, 0, vWidth, vHeight);

        let minPrice = Infinity, maxPrice = -Infinity, maxVol = 0;
        this.candles.forEach(c => {
            if (c.low < minPrice) minPrice = c.low;
            if (c.high > maxPrice) maxPrice = c.high;
            if (c.volume > maxVol) maxVol = c.volume;
        });

        const pad = (maxPrice - minPrice) * 0.05 || 0.5;
        minPrice -= pad;
        maxPrice += pad;
        maxVol = maxVol * 1.1 || 1000;

        const n = this.candles.length;
        const candleWidth = Math.max(3, (kWidth - 40) / n);
        const barWidth = candleWidth * 0.75;

        kCtx.strokeStyle = 'rgba(255, 255, 255, 0.06)';
        kCtx.lineWidth = 1;
        for (let i = 1; i <= 4; i++) {
            const y = (kHeight / 5) * i;
            kCtx.beginPath();
            kCtx.moveTo(0, y);
            kCtx.lineTo(kWidth - 45, y);
            kCtx.stroke();

            const p = maxPrice - ((maxPrice - minPrice) / 5) * i;
            kCtx.fillStyle = '#8b949e';
            kCtx.font = '10px sans-serif';
            kCtx.fillText(p.toFixed(2), kWidth - 40, y + 3);
        }

        const upColor = '#ff4d4f';
        const downColor = '#52c41a';

        this.candles.forEach((c, idx) => {
            const x = idx * candleWidth + 6;
            const isUp = c.close >= c.open;
            const color = isUp ? upColor : downColor;

            const yOpen = kHeight - ((c.open - minPrice) / (maxPrice - minPrice)) * kHeight;
            const yClose = kHeight - ((c.close - minPrice) / (maxPrice - minPrice)) * kHeight;
            const yHigh = kHeight - ((c.high - minPrice) / (maxPrice - minPrice)) * kHeight;
            const yLow = kHeight - ((c.low - minPrice) / (maxPrice - minPrice)) * kHeight;

            kCtx.strokeStyle = color;
            kCtx.lineWidth = 1.2;
            kCtx.beginPath();
            kCtx.moveTo(x + barWidth / 2, yHigh);
            kCtx.lineTo(x + barWidth / 2, yLow);
            kCtx.stroke();

            const rectY = Math.min(yOpen, yClose);
            const rectH = Math.max(2, Math.abs(yClose - yOpen));
            kCtx.fillStyle = color;
            kCtx.fillRect(x, rectY, barWidth, rectH);

            const vH = Math.max(1, (c.volume / maxVol) * vHeight);
            vCtx.fillStyle = color;
            vCtx.fillRect(x, vHeight - vH, barWidth, vH);
        });
    }

    // ==============================================================
    // 5. 交易下单逻辑 (买入、卖出、融券做空、买券平仓)
    // ==============================================================
    syncTradeStock(code) {
        const stock = this.stocks.find(s => s.code === code) || this.selectedStock;
        if (!stock) return;

        const buyCode = document.getElementById('buy-code');
        const buyPrice = document.getElementById('buy-price');
        const buyName = document.getElementById('buy-name');
        if (buyCode) buyCode.value = stock.code;
        if (buyPrice) buyPrice.value = stock.current_price.toFixed(2);
        if (buyName) buyName.textContent = stock.name;

        const sellCode = document.getElementById('sell-code');
        const sellPrice = document.getElementById('sell-price');
        const sellName = document.getElementById('sell-name');
        if (sellCode) sellCode.value = stock.code;
        if (sellPrice) sellPrice.value = stock.current_price.toFixed(2);
        if (sellName) sellName.textContent = stock.name;

        const shortOpenCode = document.getElementById('short-open-code');
        const shortOpenPrice = document.getElementById('short-open-price');
        const shortOpenName = document.getElementById('short-open-name');
        if (shortOpenCode) shortOpenCode.value = stock.code;
        if (shortOpenPrice) shortOpenPrice.value = stock.current_price.toFixed(2);
        if (shortOpenName) shortOpenName.textContent = stock.name;

        const shortCloseCode = document.getElementById('short-close-code');
        const shortClosePrice = document.getElementById('short-close-price');
        const shortCloseName = document.getElementById('short-close-name');
        if (shortCloseCode) shortCloseCode.value = stock.code;
        if (shortClosePrice) shortClosePrice.value = stock.current_price.toFixed(2);
        if (shortCloseName) shortCloseName.textContent = stock.name;

        this.updateTradeEstimates();
    }

    onTradeCodeInput(rawCode, type) {
        const code = String(rawCode || '').trim();
        if (code.length < 6) {
            const nameEl = document.getElementById(type + '-name');
            if (nameEl) nameEl.textContent = '';
            if (type === 'sell') {
                const availEl = document.getElementById('sell-available');
                if (availEl) availEl.textContent = '--';
            }
            return;
        }

        // 精确匹配股票
        let stock = this.stocks.find(s => s.code === code);
        if (stock) {
            const nameEl = document.getElementById(type + '-name');
            const priceEl = document.getElementById(type + '-price');
            if (nameEl) nameEl.textContent = stock.name;
            if (priceEl) priceEl.value = stock.current_price.toFixed(2);
            this.updateTradeEstimates();
        } else {
            // 从后端拉取详情补充
            this.api('/api/market.php?action=detail&code=' + encodeURIComponent(code)).then(data => {
                if (data && data.stock) {
                    const s = data.stock;
                    const nameEl = document.getElementById(type + '-name');
                    const priceEl = document.getElementById(type + '-price');
                    if (nameEl) nameEl.textContent = s.name;
                    if (priceEl) priceEl.value = s.current_price.toFixed(2);
                    this.updateTradeEstimates();
                }
            }).catch(() => {});
        }
    }

    updateTradeEstimates() {
        const availCash = this.currentUser ? this.currentUser.balance : 0;

        // 1. 买入面板联动
        const buyCode = document.getElementById('buy-code')?.value.trim();
        const buyPrice = parseFloat(document.getElementById('buy-price')?.value) || 0;
        const buyQty = parseInt(document.getElementById('buy-quantity')?.value) || 0;
        const buyAvail = document.getElementById('buy-available');
        const buyEst = document.getElementById('buy-estimate');

        if (buyAvail) buyAvail.textContent = '¥' + availCash.toLocaleString('zh-CN', {minimumFractionDigits:2});
        if (buyEst) buyEst.textContent = (buyQty > 0 && buyPrice > 0) ? ('¥' + (buyQty * buyPrice).toFixed(2)) : '--';

        // 2. 卖出面板联动 (匹配个人实际持仓)
        const sellCode = document.getElementById('sell-code')?.value.trim();
        const sellPrice = parseFloat(document.getElementById('sell-price')?.value) || 0;
        const sellQty = parseInt(document.getElementById('sell-quantity')?.value) || 0;
        const sellAvail = document.getElementById('sell-available');
        const sellEst = document.getElementById('sell-estimate');

        if (sellAvail) {
            if (!sellCode) {
                sellAvail.textContent = '--';
            } else {
                const holding = (this.longPositions || []).find(p => p.stock_code === sellCode);
                if (holding && holding.available_quantity > 0) {
                    sellAvail.innerHTML = '<span style="color:#28a745; font-weight:bold;">' + holding.available_quantity.toLocaleString('zh-CN') + ' 股</span>';
                } else {
                    sellAvail.innerHTML = '<span style="color:#dc3545;">0 股 (暂未持有)</span>';
                }
            }
        }
        if (sellEst) sellEst.textContent = (sellQty > 0 && sellPrice > 0) ? ('¥' + (sellQty * sellPrice).toFixed(2)) : '--';

        // 3. 融券做空面板联动
        const shortPrice = parseFloat(document.getElementById('short-open-price')?.value) || 0;
        const shortQty = parseInt(document.getElementById('short-open-quantity')?.value) || 0;
        const shortAvail = document.getElementById('short-open-available');
        const shortEst = document.getElementById('short-open-estimate');
        if (shortAvail) shortAvail.textContent = '¥' + availCash.toLocaleString('zh-CN', {minimumFractionDigits:2});
        if (shortEst) shortEst.textContent = (shortQty > 0 && shortPrice > 0) ? ('¥' + (shortQty * shortPrice).toFixed(2)) : '--';

        // 4. 买券平空面板联动
        const closeCode = document.getElementById('short-close-code')?.value.trim();
        const closePrice = parseFloat(document.getElementById('short-close-price')?.value) || 0;
        const closeQty = parseInt(document.getElementById('short-close-quantity')?.value) || 0;
        const closeAvail = document.getElementById('short-close-available');
        const closeEst = document.getElementById('short-close-estimate');
        if (closeAvail) {
            if (!closeCode) {
                closeAvail.textContent = '--';
            } else {
                const sp = (this.shortPositions || []).find(p => p.stock_code === closeCode);
                closeAvail.innerHTML = sp ? '<span style="color:#a371f7; font-weight:bold;">' + sp.quantity.toLocaleString('zh-CN') + ' 股</span>' : '<span style="color:#888;">0 股</span>';
            }
        }
        if (closeEst) closeEst.textContent = (closeQty > 0 && closePrice > 0) ? ('¥' + (closeQty * closePrice).toFixed(2)) : '--';
    }

    setTradeQuantity(type, ratio) {
        if (type === 'buy') {
            const price = parseFloat(document.getElementById('buy-price')?.value) || 0;
            const availCash = this.currentUser ? this.currentUser.balance : 0;
            if (price <= 0 || availCash <= 0) return;
            const maxShares = Math.floor(availCash / price);
            const qty = Math.floor(maxShares * ratio);
            const buyQtyEl = document.getElementById('buy-quantity');
            if (buyQtyEl) buyQtyEl.value = qty > 0 ? qty : '';
            this.updateTradeEstimates();
        } else if (type === 'sell') {
            const code = document.getElementById('sell-code')?.value.trim();
            const holding = (this.longPositions || []).find(p => p.stock_code === code);
            const maxShares = holding ? holding.available_quantity : 0;
            if (maxShares <= 0) {
                this.showToast('您当前未持有该股票，可用持仓为 0 股', 'error');
                return;
            }
            const qty = Math.floor(maxShares * ratio);
            const sellQtyEl = document.getElementById('sell-quantity');
            if (sellQtyEl) sellQtyEl.value = qty > 0 ? qty : '';
            this.updateTradeEstimates();
        } else if (type === 'short-open') {
            const price = parseFloat(document.getElementById('short-open-price')?.value) || 0;
            const availCash = this.currentUser ? this.currentUser.balance : 0;
            if (price <= 0 || availCash <= 0) return;
            const maxShares = Math.floor(availCash / price);
            const qty = Math.floor(maxShares * ratio);
            const shortQtyEl = document.getElementById('short-open-quantity');
            if (shortQtyEl) shortQtyEl.value = qty > 0 ? qty : '';
            this.updateTradeEstimates();
        }
    }

    selectStockForTrade(code, tradeType = 'sell') {
        this.showPage('trade');
        const tabBtn = document.querySelector('.trade-tab[data-trade="' + tradeType + '"]');
        if (tabBtn) tabBtn.click();
        this.syncTradeStock(code);

        if (tradeType === 'sell') {
            const holding = (this.longPositions || []).find(p => p.stock_code === code);
            const sellQtyEl = document.getElementById('sell-quantity');
            if (sellQtyEl && holding) {
                sellQtyEl.value = holding.available_quantity;
            }
            this.updateTradeEstimates();
        }
    }

    async submitBuy() {
        const code = document.getElementById('buy-code')?.value.trim();
        const qty = parseInt(document.getElementById('buy-quantity')?.value) || 0;
        if (!code || qty <= 0) return this.showToast('请输入股票代码和买入数量', 'error');

        const reqId = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        try {
            const res = await this.api('/api/trade.php?action=buy', {
                method: 'POST',
                body: JSON.stringify({ stock_code: code, quantity: qty, client_req_id: reqId })
            });
            this.showToast('买入成功！' + (res.stock_name || code) + ' ' + qty + ' 股', 'success');
            await this.checkAuth();
            this.fetchStockDetail(code);
            this.fetchMarketQuotes();
        } catch (err) {
            this.showToast(err.message, 'error');
        }
    }

    async submitSell() {
        const code = document.getElementById('sell-code')?.value.trim();
        const qty = parseInt(document.getElementById('sell-quantity')?.value) || 0;
        if (!code) return this.showToast('请选择或输入要卖出的股票代码', 'error');
        if (qty <= 0) return this.showToast('请输入有效的卖出数量 (必须大于 0)', 'error');

        // 前端即时校验持仓
        const holding = (this.longPositions || []).find(p => p.stock_code === code);
        const holdQty = holding ? holding.available_quantity : 0;
        if (holdQty < qty) {
            return this.showToast(`持仓不足！您当前持有该股票 ${holdQty} 股，无法卖出 ${qty} 股`, 'error');
        }

        const reqId = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        try {
            const res = await this.api('/api/trade.php?action=sell', {
                method: 'POST',
                body: JSON.stringify({ stock_code: code, quantity: qty, client_req_id: reqId })
            });
            this.showToast(`🎉 卖出成功！${res.stock_name || code} ${qty} 股已成交，回款 ¥${(res.trade_amount || 0).toLocaleString('zh-CN', {minimumFractionDigits:2})}`, 'success');
            // 清空卖出数量，避免重复点击
            const sellQtyInput = document.getElementById('sell-quantity');
            if (sellQtyInput) sellQtyInput.value = '';
            await this.checkAuth();
            await this.fetchPortfolio(true);
            this.syncTradeStock(code);
            this.fetchMarketQuotes();
        } catch (err) {
            this.showToast(err.message, 'error');
        }
    }

    async submitShortOpen() {
        const code = document.getElementById('short-open-code')?.value.trim();
        const qty = parseInt(document.getElementById('short-open-quantity')?.value) || 0;
        if (!code || qty <= 0) return this.showToast('请输入股票代码和做空数量', 'error');

        const reqId = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        try {
            const res = await this.api('/api/short.php?action=open', {
                method: 'POST',
                body: JSON.stringify({ stock_code: code, quantity: qty, client_req_id: reqId })
            });
            this.showToast('融券开空成功！锁定保证金 ¥' + (res.locked_margin || 0), 'success');
            await this.checkAuth();
            this.fetchStockDetail(code);
            this.fetchMarketQuotes();
        } catch (err) {
            this.showToast(err.message, 'error');
        }
    }

    async submitShortClose() {
        const code = document.getElementById('short-close-code')?.value.trim();
        const qty = parseInt(document.getElementById('short-close-quantity')?.value) || 0;
        if (!code || qty <= 0) return this.showToast('请输入股票代码和平仓数量', 'error');

        const reqId = 'req_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        try {
            const res = await this.api('/api/short.php?action=close', {
                method: 'POST',
                body: JSON.stringify({ stock_code: code, quantity: qty, client_req_id: reqId })
            });
            this.showToast('平仓成功！退回资金 ¥' + (res.returned_cash || 0), 'success');
            await this.checkAuth();
            this.fetchStockDetail(code);
            this.fetchMarketQuotes();
        } catch (err) {
            this.showToast(err.message, 'error');
        }
    }

    // ==============================================================
    // 6. 个人资产、持仓与成交流水
    // ==============================================================
    async fetchPortfolio(silent = false) {
        try {
            const data = await this.api('/api/portfolio.php?action=overview');
            this.portfolioData = data;
            const sum = data.summary;

            const tAssets = document.getElementById('total-assets');
            const sVal = document.getElementById('stock-value');
            const aFund = document.getElementById('available-fund');
            const fPnl = document.getElementById('floating-pnl');
            const tRet = document.getElementById('total-return');

            if (tAssets) tAssets.textContent = '¥' + sum.total_assets.toLocaleString('zh-CN', {minimumFractionDigits:2});
            if (sVal) sVal.textContent = '¥' + sum.long_market_value.toLocaleString('zh-CN', {minimumFractionDigits:2});
            if (aFund) aFund.textContent = '¥' + sum.available_cash.toLocaleString('zh-CN', {minimumFractionDigits:2});

            if (fPnl) {
                const sign = sum.total_floating_pnl > 0 ? '+' : '';
                fPnl.textContent = `${sign}¥${sum.total_floating_pnl.toFixed(2)}`;
                fPnl.className = 'value ' + (sum.total_floating_pnl > 0 ? 'up' : (sum.total_floating_pnl < 0 ? 'down' : ''));
            }
            if (tRet) {
                const sign = sum.total_return_percent > 0 ? '+' : '';
                tRet.textContent = `${sign}${sum.total_return_percent.toFixed(2)}%`;
                tRet.className = 'value ' + (sum.total_return_percent > 0 ? 'up' : (sum.total_return_percent < 0 ? 'down' : ''));
            }

            // 签到与破产低保状态联动
            const checkinBtn = document.getElementById('daily-checkin-btn');
            const bailoutBtn = document.getElementById('bankruptcy-bailout-btn');

            if (checkinBtn) {
                if (sum.has_checked_in_today) {
                    checkinBtn.disabled = true;
                    checkinBtn.style.opacity = '0.5';
                    checkinBtn.style.cursor = 'not-allowed';
                    checkinBtn.textContent = '✅ 今日已签到';
                } else {
                    checkinBtn.disabled = false;
                    checkinBtn.style.opacity = '1';
                    checkinBtn.style.cursor = 'pointer';
                    checkinBtn.textContent = '🎁 每日签到 (+¥50,000)';
                }
            }

            if (bailoutBtn) {
                if (sum.can_claim_bailout) {
                    bailoutBtn.disabled = false;
                    bailoutBtn.style.opacity = '1';
                    bailoutBtn.style.cursor = 'pointer';
                    bailoutBtn.title = '总资产低于 1 万元，符合破产重组低保条件！';
                } else {
                    bailoutBtn.disabled = true;
                    bailoutBtn.style.opacity = '0.5';
                    bailoutBtn.style.cursor = 'not-allowed';
                    bailoutBtn.title = '总资产充足 (需低于 1 万元方可申请)';
                }
            }

            this.longPositions = data.long_positions || [];
            this.shortPositions = data.short_positions || [];

            const longTbody = document.getElementById('portfolio-holdings-tbody');
            if (longTbody) {
                if (this.longPositions.length === 0) {
                    longTbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:var(--text-muted);">暂无多头持仓</td></tr>';
                } else {
                    longTbody.innerHTML = this.longPositions.map(p => {
                        const pClass = p.floating_pnl > 0 ? 'up' : (p.floating_pnl < 0 ? 'down' : '');
                        return `
                            <tr>
                                <td><strong>${this.escape(p.stock_name)}</strong> (${p.stock_code})</td>
                                <td>${p.quantity}</td>
                                <td>¥${p.avg_cost.toFixed(2)}</td>
                                <td>¥${p.current_price.toFixed(2)}</td>
                                <td>¥${p.market_value.toLocaleString('zh-CN', {minimumFractionDigits:2})}</td>
                                <td class="price ${pClass}">¥${p.floating_pnl.toFixed(2)}</td>
                                <td class="change ${pClass}">${p.floating_pnl_percent.toFixed(2)}%</td>
                                <td>
                                    <button class="btn-small btn-sell" style="padding:4px 10px; font-size:12px;" onclick="window.game.selectStockForTrade('${p.stock_code}', 'sell')">一键卖出</button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }
            }

            // 同步渲染交易页右侧的当前持仓列表
            const tradeHoldingsEl = document.getElementById('trade-holdings-list');
            if (tradeHoldingsEl) {
                if (this.longPositions.length === 0) {
                    tradeHoldingsEl.innerHTML = '<div style="text-align:center; padding:30px 10px; color:var(--text-muted);">暂无持仓股票<br><small style="font-size:12px;">买入股票后可在此点击一键卖出</small></div>';
                } else {
                    tradeHoldingsEl.innerHTML = this.longPositions.map(p => {
                        const pnlClass = p.floating_pnl > 0 ? 'up' : (p.floating_pnl < 0 ? 'down' : '');
                        return `
                            <div class="holding-item" onclick="window.game.selectStockForTrade('${p.stock_code}', 'sell')" title="点击立即挂出卖出单">
                                <div class="holding-info">
                                    <div class="name">${this.escape(p.stock_name)}</div>
                                    <div class="code">${p.stock_code}</div>
                                </div>
                                <div class="holding-qty">
                                    <div class="qty" style="font-weight:bold;">${p.quantity} 股</div>
                                    <div class="pnl ${pnlClass}">¥${p.floating_pnl.toFixed(2)}</div>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            }
            this.updateTradeEstimates();

            const shortTbody = document.getElementById('portfolio-short-tbody');
            if (shortTbody) {
                if (data.short_positions.length === 0) {
                    shortTbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:var(--text-muted);">暂无空头持仓</td></tr>';
                } else {
                    shortTbody.innerHTML = data.short_positions.map(p => {
                        const pClass = p.floating_pnl > 0 ? 'up' : (p.floating_pnl < 0 ? 'down' : '');
                        const isDanger = p.margin_ratio_percent <= 50.0;
                        return `
                            <tr>
                                <td><strong>${this.escape(p.stock_name)}</strong> (${p.stock_code})</td>
                                <td>${p.quantity}</td>
                                <td>¥${p.avg_cost.toFixed(2)}</td>
                                <td>¥${p.current_price.toFixed(2)}</td>
                                <td>¥${p.locked_margin.toFixed(2)}</td>
                                <td class="price ${pClass}">¥${p.floating_pnl.toFixed(2)}</td>
                                <td style="${isDanger ? 'color:var(--up-color); font-weight:700;' : ''}">${p.margin_ratio_percent.toFixed(1)}% (强平线: ¥${p.est_liquidation_price.toFixed(2)})</td>
                                <td>
                                    <button class="btn-small btn-secondary" onclick="window.game.quickCloseShort('${p.stock_code}', ${p.quantity})">平仓</button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }
            }

            const trData = await this.api('/api/portfolio.php?action=trades&limit=30');
            const recTbody = document.querySelector('.records-table tbody');
            if (recTbody) {
                recTbody.innerHTML = trData.trades.map(t => `
                    <tr>
                        <td>${t.time}</td>
                        <td>${this.escape(t.stock_name)} (${t.stock_code})</td>
                        <td>${t.direction}</td>
                        <td>¥${t.price.toFixed(2)}</td>
                        <td>${t.quantity}</td>
                        <td>¥${t.amount.toLocaleString('zh-CN', {minimumFractionDigits:2})}</td>
                    </tr>
                `).join('');
            }

        } catch (e) {
            if (!silent) console.error('Portfolio Error:', e);
        }
    }

    quickCloseShort(code, qty) {
        this.showPage('trade');
        const tabBtn = document.querySelector('.trade-tab[data-trade="short-close"]');
        if (tabBtn) tabBtn.click();
        const codeInput = document.getElementById('short-close-code');
        const qtyInput = document.getElementById('short-close-quantity');
        if (codeInput) codeInput.value = code;
        if (qtyInput) qtyInput.value = qty;
        this.syncTradeStock(code);
    }

    // ==============================================================
    // 7. 市场异动事件流与管理后台
    // ==============================================================
    async fetchEventsFeed() {
        try {
            const data = await this.api('/api/events.php?action=list&limit=20');
            const listEl = document.getElementById('events-feed-list');
            if (!listEl) return;
            listEl.innerHTML = (data.events || []).map(e => `
                <div class="panel" style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:8px; padding:16px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span class="badge" style="background:#1f6feb; color:#fff; font-size:11px; padding:2px 6px; border-radius:3px;">${e.category}</span>
                        <span style="font-size:12px; color:var(--text-muted);">${e.created_at}</span>
                    </div>
                    <h4 style="margin-bottom:6px; color:#fff; font-size:15px;">${this.escape(e.title)}</h4>
                    <p style="color:var(--text-secondary); font-size:13px; line-height:1.6;">${this.escape(e.content)}</p>
                </div>
            `).join('');
        } catch (e) {
            console.error('Events Feed Error:', e);
        }
    }

    async loadAdminDashboard() {
        try {
            const codeData = await this.api('/api/admin.php?action=invite_codes_list');
            const cTbody = document.getElementById('admin-invite-table-body');
            if (cTbody) {
                cTbody.innerHTML = (codeData.codes || []).map(c => `
                    <tr>
                        <td><code>${this.escape(c.code)}</code></td>
                        <td>${c.used_count} / ${c.max_uses}</td>
                        <td>${c.is_active ? '<span class="price up">有效</span>' : '<span class="price down">停用</span>'}</td>
                        <td>${this.escape(c.note || '-')}</td>
                        <td>
                            <button class="btn-small btn-secondary" onclick="window.game.toggleInvite(${c.id}, ${!c.is_active})">${c.is_active ? '停用' : '启用'}</button>
                        </td>
                    </tr>
                `).join('');
            }

            const uData = await this.api('/api/admin.php?action=users_list');
            const uTbody = document.getElementById('admin-user-table-body');
            if (uTbody) {
                uTbody.innerHTML = (uData.users || []).map(u => `
                    <tr>
                        <td>#${u.id}</td>
                        <td><strong>${this.escape(u.username)}</strong></td>
                        <td><span class="badge">${u.role}</span></td>
                        <td>¥${parseFloat(u.balance).toLocaleString('zh-CN', {minimumFractionDigits:2})}</td>
                        <td>¥${parseFloat(u.locked_margin).toFixed(2)}</td>
                        <td>${u.status === 'active' ? '<span class="price up">正常</span>' : '<span class="price down">封禁</span>'}</td>
                        <td>
                            <button class="btn-small btn-secondary" onclick="window.game.adjustUserFund(${u.id}, '${this.escape(u.username)}')">调整资金</button>
                        </td>
                    </tr>
                `).join('');
            }
        } catch (e) {
            this.showToast('加载管理后台失败: ' + e.message, 'error');
        }
    }

    async toggleInvite(id, isActive) {
        try {
            await this.api('/api/admin.php?action=invite_code_toggle', {
                method: 'POST',
                body: JSON.stringify({ id, is_active: isActive })
            });
            this.showToast('邀请码状态已更新', 'success');
            this.loadAdminDashboard();
        } catch (e) {
            this.showToast(e.message, 'error');
        }
    }

    adjustUserFund(userId, username) {
        const amt = parseFloat(prompt(`为玩家 [${username}] 增减资金 (正数为增，负数为扣):`, '100000'));
        if (isNaN(amt) || amt === 0) return;
        const reason = prompt('调整原因说明:', '群友活动奖励');
        this.api('/api/admin.php?action=user_adjust_fund', {
            method: 'POST',
            body: JSON.stringify({ user_id: userId, amount: amt, reason })
        }).then(() => {
            this.showToast('资金已成功调整', 'success');
            this.loadAdminDashboard();
        }).catch(err => this.showToast(err.message, 'error'));
    }

    // ==============================================================
    // 8. 自动交易模块 (服务端策略引擎驱动)
    // ==============================================================
    async loadAutoTradePage() {
        await this.fetchAutoTradeStrategies();
        if (this.autoTradeActiveTab === 'history') {
            await this.fetchAutoTradeHistory();
        }
    }

    async fetchAutoTradeStrategies(silent = false) {
        try {
            const data = await this.api('/api/auto.php?action=list');
            this.autoStrategies = data.strategies || [];
            this.autoRiskConfig = data.risk_config || {};
            this.renderAutoTradeUI(data.active_count);
        } catch (e) {
            if (!silent) this.showToast('加载自动交易策略失败: ' + e.message, 'error');
        }
    }

    renderAutoTradeUI(activeCount = 0) {
        const statusText = document.getElementById('auto-trade-status-text');
        const indicator = document.getElementById('auto-trade-status-indicator');
        const isRunning = activeCount > 0;

        if (statusText) {
            statusText.textContent = isRunning ? `运行中 (${activeCount}个活跃策略)` : '未启动';
            statusText.style.color = isRunning ? 'var(--success-color)' : 'var(--text-muted)';
        }
        if (indicator) {
            if (isRunning) indicator.classList.add('active');
            else indicator.classList.remove('active');
        }

        const pauseBtn = document.getElementById('pause-auto-trade-btn');
        const stopBtn = document.getElementById('stop-auto-trade-btn');
        const startBtn = document.getElementById('start-auto-trade-btn');
        if (pauseBtn) pauseBtn.disabled = !isRunning;
        if (stopBtn) stopBtn.disabled = (this.autoStrategies.length === 0);
        if (startBtn) startBtn.disabled = (this.autoStrategies.length === 0 || activeCount === this.autoStrategies.length);

        const container = document.getElementById('auto-trade-stocks-container');
        if (!container) return;

        if (this.autoStrategies.length === 0) {
            container.innerHTML = '<p class="empty-tip">暂无自动交易策略，请在下方表单添加</p>';
            return;
        }

        container.innerHTML = this.autoStrategies.map(s => {
            const isBuy = (s.direction === 'buy');
            const dirClass = isBuy ? 'buy' : 'sell';
            const dirBadge = isBuy ? '<span style="color:#28a745; font-weight:bold;">买入</span>' : '<span style="color:#dc3545; font-weight:bold;">卖出</span>';

            let condDesc = '';
            const opText = (s.condition_operator === 'above') ? '高于' : (s.condition_operator === 'below' ? '低于' : '等于');
            if (s.condition_type === 'price') {
                condDesc = `现价 ${opText} ¥${s.condition_value.toFixed(2)}`;
            } else if (s.condition_type === 'percentage') {
                condDesc = `当日涨跌幅 ${opText} ${s.condition_value > 0 ? '+' : ''}${s.condition_value.toFixed(2)}%`;
            } else if (s.condition_type === 'profit') {
                condDesc = `持仓盈亏金额 ${opText} ¥${s.condition_value.toFixed(2)}`;
            } else if (s.condition_type === 'time') {
                condDesc = `固定每 30 秒执行一次`;
            }

            let riskNotes = [];
            if (s.stop_loss > 0) riskNotes.push(`止损 -¥${s.stop_loss.toFixed(0)}`);
            if (s.take_profit > 0) riskNotes.push(`止盈 +¥${s.take_profit.toFixed(0)}`);
            if (s.max_amount > 0) riskNotes.push(`单笔限额 ¥${s.max_amount.toFixed(0)}`);
            const riskStr = riskNotes.length > 0 ? ` [${riskNotes.join(' | ')}]` : '';

            const statusBadge = s.is_active ? '<span class="price up">● 监控中</span>' : '<span class="price" style="color:#888;">○ 已暂停</span>';

            return `
                <div class="auto-trade-stock-item ${dirClass}">
                    <div class="auto-trade-stock-info">
                        <div class="stock-code">
                            ${this.escape(s.stock_name)} (${s.stock_code}) - ${dirBadge} ${statusBadge}
                        </div>
                        <div class="stock-condition">
                            条件: <strong>${condDesc}</strong> | 单次 ${s.quantity} 股 (${s.price_type === 'LIMIT' ? '限价 ¥' + s.limit_price.toFixed(2) : '市价'})${riskStr}
                            <br><small style="color:var(--text-muted);">已触发: ${s.triggered_count}/${s.max_trigger_count} 次 ${s.last_triggered_at ? '· 上次: ' + s.last_triggered_at : ''}</small>
                        </div>
                    </div>
                    <div class="auto-trade-stock-actions">
                        <button class="btn-small ${s.is_active ? 'btn-secondary' : 'btn-primary'}" onclick="window.game.toggleAutoStrategy(${s.id}, ${!s.is_active})">
                            ${s.is_active ? '暂停' : '启动'}
                        </button>
                        <button class="btn-delete" onclick="window.game.deleteAutoStrategy(${s.id})">删除</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    async fetchAutoTradeHistory() {
        try {
            const data = await this.api('/api/auto.php?action=records&limit=40');
            const records = data.records || [];
            const stats = data.stats || {};

            const tTrades = document.getElementById('auto-total-trades');
            const sTrades = document.getElementById('auto-success-trades');
            const fTrades = document.getElementById('auto-failed-trades');
            const tPnl = document.getElementById('auto-total-pnl');

            const succCount = records.filter(r => r.success).length;
            const failCount = records.length - succCount;

            if (tTrades) tTrades.textContent = stats.total_count ?? records.length;
            if (sTrades) sTrades.textContent = succCount;
            if (fTrades) fTrades.textContent = failCount;
            if (tPnl) {
                const pnl = stats.total_pnl || 0;
                tPnl.textContent = (pnl >= 0 ? '+' : '') + '¥' + pnl.toFixed(2);
                tPnl.style.color = pnl > 0 ? 'var(--success-color)' : (pnl < 0 ? 'var(--danger-color)' : 'inherit');
            }

            const listEl = document.getElementById('auto-trade-records-list');
            if (!listEl) return;

            if (records.length === 0) {
                listEl.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-muted);">暂无自动交易触发历史</div>';
                return;
            }

            listEl.innerHTML = records.map(r => {
                const isSucc = r.success;
                const statusClass = isSucc ? 'success' : 'failed';
                const pnlStr = r.pnl !== 0 ? ` (盈亏: ${r.pnl > 0 ? '+' : ''}¥${r.pnl.toFixed(2)})` : '';
                return `
                    <div class="record-item ${statusClass}">
                        <div class="record-info">
                            <div class="time">${r.time}</div>
                            <div class="details">
                                <strong>${r.direction === 'BUY' ? '买入' : '卖出'}</strong> ${this.escape(r.stock_name)} (${r.stock_code})
                                ${r.quantity} 股 @ ¥${r.price.toFixed(2)}: ${this.escape(r.message)}${pnlStr}
                            </div>
                        </div>
                        <div class="record-result" style="font-weight:600; color:${isSucc ? 'var(--success-color)' : 'var(--danger-color)'}">
                            ${isSucc ? '成功' : '失败'}
                        </div>
                    </div>
                `;
            }).join('');
        } catch (e) {
            console.error('Auto Trade History Error:', e);
        }
    }

    async addAutoStrategy() {
        const code = document.getElementById('auto-code')?.value.trim();
        const dir = document.querySelector('input[name="auto-direction"]:checked')?.value || 'buy';
        const condType = document.getElementById('auto-condition-type')?.value || 'price';
        const condOp = document.getElementById('auto-condition-operator')?.value || 'above';
        const condVal = parseFloat(document.getElementById('auto-condition-value')?.value) || 0;
        const qty = parseInt(document.getElementById('auto-quantity')?.value) || 0;
        const priceType = document.getElementById('auto-price-type')?.value || 'market';
        const limitPrice = parseFloat(document.getElementById('auto-limit-price')?.value) || 0;
        const stopLoss = parseFloat(document.getElementById('auto-stop-loss')?.value) || 0;
        const takeProfit = parseFloat(document.getElementById('auto-take-profit')?.value) || 0;
        const maxAmount = parseFloat(document.getElementById('auto-max-amount')?.value) || 0;

        if (!code || qty <= 0) {
            this.showToast('请输入合法的股票代码和交易数量 (正整数)', 'error');
            return;
        }

        try {
            await this.api('/api/auto.php?action=create', {
                method: 'POST',
                body: JSON.stringify({
                    stock_code: code,
                    direction: dir,
                    condition_type: condType,
                    condition_operator: condOp,
                    condition_value: condVal,
                    quantity: qty,
                    price_type: priceType.toUpperCase(),
                    limit_price: limitPrice,
                    stop_loss: stopLoss,
                    take_profit: takeProfit,
                    max_amount: maxAmount,
                    max_trigger_count: 5
                })
            });
            this.showToast('自动交易策略已添加并开启监控！', 'success');
            const qtyEl = document.getElementById('auto-quantity');
            if (qtyEl) qtyEl.value = '';
            await this.fetchAutoTradeStrategies();
        } catch (e) {
            this.showToast('添加策略失败: ' + e.message, 'error');
        }
    }

    async toggleAutoStrategy(id, active) {
        try {
            await this.api('/api/auto.php?action=toggle', {
                method: 'POST',
                body: JSON.stringify({ id, is_active: active })
            });
            this.showToast(active ? '策略已恢复监控' : '策略已暂停', 'info');
            await this.fetchAutoTradeStrategies();
        } catch (e) {
            this.showToast(e.message, 'error');
        }
    }

    async deleteAutoStrategy(id) {
        if (!confirm('确认删除该自动交易策略吗？')) return;
        try {
            await this.api('/api/auto.php?action=delete', {
                method: 'POST',
                body: JSON.stringify({ id })
            });
            this.showToast('策略已删除', 'info');
            await this.fetchAutoTradeStrategies();
        } catch (e) {
            this.showToast(e.message, 'error');
        }
    }

    async resetAutoStrategies() {
        if (!confirm('确认清空重置您全部的自动交易策略吗？')) return;
        try {
            await this.api('/api/auto.php?action=reset_config', { method: 'POST' });
            this.showToast('自动交易策略已全部清空', 'info');
            await this.fetchAutoTradeStrategies();
        } catch (e) {
            this.showToast(e.message, 'error');
        }
    }

    // ==============================================================
    // 9. 个人中心、成就墙与安全操作
    // ==============================================================
    async loadProfilePage() {
        await this.fetchPortfolio(true);

        const u = this.currentUser || {};
        const pUsername = document.getElementById('profile-username');
        const regTime = document.getElementById('reg-time');
        const statTrades = document.getElementById('stat-trades');
        const statGames = document.getElementById('stat-games');

        if (pUsername) pUsername.textContent = u.username || '--';
        if (regTime) regTime.textContent = u.created_date || '--';

        const stats = (this.portfolioData && this.portfolioData.stats) ? this.portfolioData.stats : {
            tradeCount: 0,
            totalProfit: 0,
            totalLoss: 0,
            netProfit: 0,
            maxHoldings: 0,
            maxReturn: 0,
            profitStreak: 0,
            sectorsTraded: 0,
            largeTrades: 0,
            holdMaotai: false,
            dayTrades: 0,
            doubleBaggers: 0,
            totalFees: 0
        };

        if (statTrades) statTrades.textContent = stats.tradeCount;
        if (statGames) {
            const regDate = u.created_date ? new Date(u.created_date) : new Date();
            const days = Math.max(1, Math.floor((Date.now() - regDate.getTime()) / 86400000));
            statGames.textContent = `${days}天`;
        }

        this.evaluateAndRenderAchievements(stats);
    }

    evaluateAndRenderAchievements(stats) {
        if (!window.AchievementSystem || !this.currentUser) return;

        stats.watchlistCount = this.watchlist.size;

        const storeKey = `cyberstock_achievements_${this.currentUser.username}`;
        let unlockedIds = [];
        try {
            unlockedIds = JSON.parse(localStorage.getItem(storeKey) || '[]');
        } catch (e) {
            unlockedIds = [];
        }

        const newAch = window.AchievementSystem.checkAchievements(stats, unlockedIds);
        if (newAch.length > 0) {
            newAch.forEach(a => {
                unlockedIds.push(a.id);
                this.showToast(`🏆 解锁新成就: 【${a.name}】 - ${a.desc}`, 'success');
            });
            localStorage.setItem(storeKey, JSON.stringify(unlockedIds));
        }

        const achList = window.AchievementSystem.achievements || [];
        const levels = { bronze: 0, silver: 0, gold: 0, legend: 0 };
        achList.forEach(a => {
            if (unlockedIds.includes(a.id) && levels[a.level] !== undefined) {
                levels[a.level]++;
            }
        });

        const cB = document.getElementById('count-bronze');
        const cS = document.getElementById('count-silver');
        const cG = document.getElementById('count-gold');
        const cL = document.getElementById('count-legend');
        const statAch = document.getElementById('stat-achievements');

        if (cB) cB.textContent = levels.bronze;
        if (cS) cS.textContent = levels.silver;
        if (cG) cG.textContent = levels.gold;
        if (cL) cL.textContent = levels.legend;
        if (statAch) statAch.textContent = unlockedIds.length;

        const listEl = document.getElementById('achievements-list');
        if (!listEl) return;

        listEl.innerHTML = achList.map(a => {
            const isUnlocked = unlockedIds.includes(a.id);
            const statusClass = isUnlocked ? 'unlocked' : 'locked';
            const levelClass = a.level || 'bronze';
            const levelName = window.AchievementSystem.getLevelName ? window.AchievementSystem.getLevelName(a.level) : a.level;
            const name = window.AchievementSystem.getName ? window.AchievementSystem.getName(a) : a.name;
            const desc = window.AchievementSystem.getDesc ? window.AchievementSystem.getDesc(a) : a.desc;

            return `
                <div class="achievement-card ${statusClass}">
                    <div class="achievement-icon-small">${a.icon || '🏅'}</div>
                    <div class="achievement-info">
                        <h4>${name} ${isUnlocked ? '✅' : '🔒'}</h4>
                        <p>${desc}</p>
                    </div>
                    <span class="achievement-level-badge level ${levelClass}">${levelName}</span>
                </div>
            `;
        }).join('');

        if (this.achievementsExpanded) {
            listEl.style.maxHeight = 'none';
        } else {
            listEl.style.maxHeight = '280px';
        }
    }

    toggleAchievementsExpand() {
        this.achievementsExpanded = !this.achievementsExpanded;
        const listEl = document.getElementById('achievements-list');
        const btn = document.getElementById('toggle-achievements-btn');
        if (listEl) {
            listEl.style.maxHeight = this.achievementsExpanded ? 'none' : '280px';
        }
        if (btn) {
            btn.textContent = this.achievementsExpanded ? '收起成就' : '展开全部';
        }
    }

    openChangePasswordModal() {
        const modal = document.getElementById('change-password-modal');
        const err = document.getElementById('change-password-error');
        if (err) { err.style.display = 'none'; err.textContent = ''; }
        const cP = document.getElementById('current-password');
        const nP = document.getElementById('new-password');
        const cfP = document.getElementById('confirm-password');
        if (cP) cP.value = '';
        if (nP) nP.value = '';
        if (cfP) cfP.value = '';
        if (modal) modal.classList.add('active');
    }

    closeChangePasswordModal() {
        const modal = document.getElementById('change-password-modal');
        if (modal) modal.classList.remove('active');
    }

    async submitChangePassword() {
        const oldP = document.getElementById('current-password')?.value.trim();
        const newP = document.getElementById('new-password')?.value.trim();
        const confP = document.getElementById('confirm-password')?.value.trim();
        const err = document.getElementById('change-password-error');

        if (!oldP || !newP || !confP) {
            if (err) { err.textContent = '请完整填写密码信息'; err.style.display = 'block'; }
            return;
        }
        if (newP !== confP) {
            if (err) { err.textContent = '两次输入的新密码不一致'; err.style.display = 'block'; }
            return;
        }
        if (newP.length < 6 || newP.length > 32) {
            if (err) { err.textContent = '新密码长度需在6-32位之间'; err.style.display = 'block'; }
            return;
        }

        try {
            await this.api('/api/auth.php?action=change_password', {
                method: 'POST',
                body: JSON.stringify({ old_password: oldP, new_password: newP })
            });
            this.showToast('密码修改成功，其他设备已自动退出登录', 'success');
            this.closeChangePasswordModal();
        } catch (e) {
            if (err) { err.textContent = e.message; err.style.display = 'block'; }
            this.showToast(e.message, 'error');
        }
    }

    openDeleteAccountModal() {
        const modal = document.getElementById('delete-account-modal');
        const err = document.getElementById('delete-account-error');
        const pInput = document.getElementById('delete-account-password');
        if (err) { err.style.display = 'none'; err.textContent = ''; }
        if (pInput) pInput.value = '';
        if (modal) modal.classList.add('active');
    }

    closeDeleteAccountModal() {
        const modal = document.getElementById('delete-account-modal');
        if (modal) modal.classList.remove('active');
    }

    async submitDeleteAccount() {
        const pwd = document.getElementById('delete-account-password')?.value.trim();
        const err = document.getElementById('delete-account-error');

        if (!pwd) {
            if (err) { err.textContent = '请输入登录密码以确认'; err.style.display = 'block'; }
            return;
        }

        try {
            await this.api('/api/auth.php?action=delete_account', {
                method: 'POST',
                body: JSON.stringify({ password: pwd })
            });
            localStorage.clear();
            this.currentUser = null;
            this.closeDeleteAccountModal();
            this.showToast('账户已安全注销，所有数据已清除', 'info');
            this.showScreen('auth-screen');
        } catch (e) {
            if (err) { err.textContent = e.message; err.style.display = 'block'; }
            this.showToast(e.message, 'error');
        }
    }

    // ==============================================================
    // 10. 全局事件绑定与 Toast 提示
    // ==============================================================
    bindEvents() {
        document.querySelectorAll('.auth-tabs .tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.auth-tabs .tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
                btn.classList.add('active');
                const targetTab = btn.getAttribute('data-tab');
                const form = document.getElementById(`${targetTab}-form`);
                if (form) form.classList.add('active');
            });
        });

        document.getElementById('daily-checkin-btn')?.addEventListener('click', async () => {
            try {
                const res = await this.api('/api/portfolio.php?action=checkin', { method: 'POST' });
                this.showToast(res.message || '签到成功！¥50,000 扶持金已到账', 'success');
                await this.fetchPortfolio();
            } catch (err) {
                this.showToast(err.message, 'error');
            }
        });

        document.getElementById('bankruptcy-bailout-btn')?.addEventListener('click', async () => {
            if (!confirm('确认申请破产重组低保救助金（¥200,000）吗？系统将进行资产审核并在全服广播。')) {
                return;
            }
            try {
                const res = await this.api('/api/portfolio.php?action=bailout', { method: 'POST' });
                this.showToast(res.message || '破产重组救济金已发放！祝您东山再起！', 'success');
                await this.fetchPortfolio();
            } catch (err) {
                this.showToast(err.message, 'error');
            }
        });

        document.getElementById('login-btn')?.addEventListener('click', async () => {
            const u = document.getElementById('login-username')?.value.trim();
            const p = document.getElementById('login-password')?.value.trim();
            const errEl = document.getElementById('login-error');
            if (errEl) errEl.textContent = '';
            if (!u || !p) {
                if (errEl) errEl.textContent = '请输入用户名和密码';
                return;
            }
            try {
                const res = await this.api('/api/auth.php?action=login', {
                    method: 'POST',
                    body: JSON.stringify({ username: u, password: p })
                });
                localStorage.setItem('cyberstock_token', res.token);
                this.currentUser = res.user;
                this.showToast('登录成功！欢迎进入赛博股市', 'success');
                this.showScreen('main-screen');
                this.updateHeaderUser();
                await this.fetchMarketQuotes();
            } catch (err) {
                if (errEl) errEl.textContent = err.message;
                this.showToast('登录失败: ' + err.message, 'error');
            }
        });

        document.getElementById('register-btn')?.addEventListener('click', async () => {
            const u = document.getElementById('reg-username')?.value.trim();
            const p = document.getElementById('reg-password')?.value.trim();
            const cp = document.getElementById('reg-confirm')?.value.trim();
            const c = document.getElementById('reg-invite-code')?.value.trim() || 'CYBER666';
            const errEl = document.getElementById('reg-error');
            if (errEl) errEl.textContent = '';

            if (!u || !p) {
                if (errEl) errEl.textContent = '请输入用户名和密码';
                return;
            }
            if (p !== cp) {
                if (errEl) errEl.textContent = '两次输入的密码不一致';
                return;
            }

            try {
                const res = await this.api('/api/auth.php?action=register', {
                    method: 'POST',
                    body: JSON.stringify({ username: u, password: p, invite_code: c })
                });
                localStorage.setItem('cyberstock_token', res.token);
                this.currentUser = res.user;
                this.showToast('注册成功！已获得初始虚拟资金', 'success');
                this.showScreen('main-screen');
                this.updateHeaderUser();
                await this.fetchMarketQuotes();
            } catch (err) {
                if (errEl) errEl.textContent = err.message;
                this.showToast('注册失败: ' + err.message, 'error');
            }
        });

        document.querySelectorAll('.main-nav .nav-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const page = btn.getAttribute('data-page');
                if (page) this.showPage(page);
            });
        });

        document.getElementById('stock-search')?.addEventListener('input', (e) => {
            this.searchKeyword = e.target.value.trim();
            this.renderStockList();
        });

        document.querySelectorAll('.trade-tabs .trade-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.trade-tabs .trade-tab').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.trade-form').forEach(f => f.classList.remove('active'));
                btn.classList.add('active');
                const t = btn.getAttribute('data-trade');
                const panel = document.getElementById(`${t}-panel`);
                if (panel) panel.classList.add('active');
            });
        });

        // 股票代码输入即时自动联动
        document.getElementById('buy-code')?.addEventListener('input', (e) => this.onTradeCodeInput(e.target.value, 'buy'));
        document.getElementById('sell-code')?.addEventListener('input', (e) => this.onTradeCodeInput(e.target.value, 'sell'));

        // 数量与价格输入即时联动预估
        document.getElementById('buy-quantity')?.addEventListener('input', () => this.updateTradeEstimates());
        document.getElementById('buy-price')?.addEventListener('input', () => this.updateTradeEstimates());
        document.getElementById('sell-quantity')?.addEventListener('input', () => this.updateTradeEstimates());
        document.getElementById('sell-price')?.addEventListener('input', () => this.updateTradeEstimates());
        document.getElementById('short-open-quantity')?.addEventListener('input', () => this.updateTradeEstimates());
        document.getElementById('short-close-quantity')?.addEventListener('input', () => this.updateTradeEstimates());

        // 仓位比例按钮 (1/4, 1/2, 全仓)
        document.querySelectorAll('.quantity-btns button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const panel = e.target.closest('.trade-form');
                const type = panel ? panel.id.replace('-panel', '') : 'buy';
                const ratio = parseFloat(e.target.getAttribute('data-ratio')) || 1;
                this.setTradeQuantity(type, ratio);
            });
        });

        // 做空快捷比例按钮
        document.querySelectorAll('.short-ratio-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const ratio = parseFloat(e.target.getAttribute('data-ratio')) || 1;
                this.setTradeQuantity('short-open', ratio);
            });
        });

        document.getElementById('confirm-buy-btn')?.addEventListener('click', () => this.submitBuy());
        document.getElementById('confirm-sell-btn')?.addEventListener('click', () => this.submitSell());
        document.getElementById('confirm-short-open-btn')?.addEventListener('click', () => this.submitShortOpen());
        document.getElementById('confirm-short-close-btn')?.addEventListener('click', () => this.submitShortClose());

        document.getElementById('quick-buy-btn')?.addEventListener('click', () => {
            this.showPage('trade');
            const buyTab = document.querySelector('.trade-tab[data-trade="buy"]');
            if (buyTab) buyTab.click();
        });
        document.getElementById('quick-sell-btn')?.addEventListener('click', () => {
            this.showPage('trade');
            const sellTab = document.querySelector('.trade-tab[data-trade="sell"]');
            if (sellTab) sellTab.click();
        });

        document.getElementById('logout-btn')?.addEventListener('click', () => this.logout());

        document.getElementById('theme-toggle')?.addEventListener('click', () => {
            document.body.classList.toggle('light-theme');
            this.drawKLine();
        });

        document.getElementById('admin-create-invite-btn')?.addEventListener('click', () => {
            const code = prompt('输入新邀请码 (留空自动生成):', '');
            const maxUses = parseInt(prompt('最大使用次数:', '20')) || 20;
            const note = prompt('邀请码说明:', '群友特邀');
            this.api('/api/admin.php?action=invite_code_create', {
                method: 'POST',
                body: JSON.stringify({ code, max_uses: maxUses, note })
            }).then(r => {
                this.showToast('邀请码创建成功: ' + r.code, 'success');
                this.loadAdminDashboard();
            }).catch(e => this.showToast(e.message, 'error'));
        });

        document.getElementById('admin-pause-market-btn')?.addEventListener('click', () => {
            this.api('/api/admin.php?action=market_toggle_pause', { method: 'POST' })
                .then(r => this.showToast(r.is_paused ? '市场已暂停' : '市场已恢复', 'info'))
                .catch(e => this.showToast(e.message, 'error'));
        });

        document.getElementById('refresh-events-btn')?.addEventListener('click', () => this.fetchEventsFeed());

        // 自选股切换
        document.getElementById('watchlist-toggle-btn')?.addEventListener('click', () => {
            this.showOnlyWatchlist = !this.showOnlyWatchlist;
            const btn = document.getElementById('watchlist-toggle-btn');
            if (btn) btn.textContent = this.showOnlyWatchlist ? '查看全部' : '查看自选';
            this.renderStockList();
        });

        // 自动交易 Tab 切换 (触发条件 / 风险控制 / 交易记录)
        document.querySelectorAll('.auto-trade-tabs .auto-trade-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.auto-trade-tabs .auto-trade-tab').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.auto-trade-panel .auto-trade-form').forEach(f => f.classList.remove('active'));
                btn.classList.add('active');
                const tab = btn.getAttribute('data-tab');
                this.autoTradeActiveTab = tab;
                if (tab === 'condition') {
                    document.getElementById('condition-panel')?.classList.add('active');
                } else if (tab === 'risk') {
                    document.getElementById('risk-panel')?.classList.add('active');
                } else if (tab === 'history') {
                    document.getElementById('history-panel')?.classList.add('active');
                    this.fetchAutoTradeHistory();
                }
            });
        });

        // 自动交易股票代码联动
        document.getElementById('auto-code')?.addEventListener('input', (e) => {
            const val = e.target.value.trim().toUpperCase();
            const nameEl = document.getElementById('auto-name');
            const found = this.stocks.find(s => s.code === val);
            if (nameEl) nameEl.textContent = found ? found.name : '';
        });

        // 自动交易价格类型切换 (限价输入框联动)
        document.getElementById('auto-price-type')?.addEventListener('change', (e) => {
            const lp = document.getElementById('auto-limit-price');
            if (lp) lp.disabled = (e.target.value !== 'limit');
        });

        // 自动交易数量比例按钮 (1/4, 1/2, 全仓)
        document.querySelectorAll('.add-stock-form .quantity-btns button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const ratio = parseFloat(e.target.getAttribute('data-ratio')) || 1;
                const dir = document.querySelector('input[name="auto-direction"]:checked')?.value || 'buy';
                const code = document.getElementById('auto-code')?.value.trim();
                const found = this.stocks.find(s => s.code === code);
                const price = found ? found.current_price : 10;
                let maxQty = 100;
                if (dir === 'buy') {
                    const cash = this.currentUser ? this.currentUser.balance : 0;
                    maxQty = Math.floor(cash / Math.max(0.01, price));
                } else {
                    const pos = (this.longPositions || []).find(p => p.stock_code === code);
                    maxQty = pos ? pos.quantity : 0;
                }
                const qty = Math.max(1, Math.floor(maxQty * ratio));
                const qInput = document.getElementById('auto-quantity');
                if (qInput) qInput.value = qty;
            });
        });

        // 自动交易操作按钮
        document.getElementById('add-auto-stock-btn')?.addEventListener('click', () => this.addAutoStrategy());
        document.getElementById('reset-auto-trade-config-btn')?.addEventListener('click', () => this.resetAutoStrategies());

        document.getElementById('start-auto-trade-btn')?.addEventListener('click', async () => {
            for (const s of this.autoStrategies) {
                if (!s.is_active) await this.toggleAutoStrategy(s.id, true);
            }
            this.showToast('所有策略已启动运行', 'success');
        });
        document.getElementById('pause-auto-trade-btn')?.addEventListener('click', async () => {
            for (const s of this.autoStrategies) {
                if (s.is_active) await this.toggleAutoStrategy(s.id, false);
            }
            this.showToast('所有策略已暂停', 'info');
        });
        document.getElementById('stop-auto-trade-btn')?.addEventListener('click', () => this.resetAutoStrategies());

        // 成就展开折叠
        document.getElementById('toggle-achievements-btn')?.addEventListener('click', () => this.toggleAchievementsExpand());

        // 密码修改
        document.getElementById('change-password-btn')?.addEventListener('click', () => this.openChangePasswordModal());
        document.getElementById('cancel-password-change')?.addEventListener('click', () => this.closeChangePasswordModal());
        document.getElementById('submit-password-change')?.addEventListener('click', () => this.submitChangePassword());

        // 账户注销
        document.getElementById('delete-account-btn')?.addEventListener('click', () => this.openDeleteAccountModal());
        document.getElementById('cancel-delete-account')?.addEventListener('click', () => this.closeDeleteAccountModal());
        document.getElementById('submit-delete-account')?.addEventListener('click', () => this.submitDeleteAccount());
    }

    showToast(message, type = 'info') {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3500);
    }

    escape(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
}

window.addEventListener('DOMContentLoaded', () => {
    window.game = new CyberStockApp();
});
