# File 1: Asset Inventory:
>Asset Inventory 2026.1 (Zakaat Calculator).

## Features:
* Use as an asset inventory for the household.
* Add various sources of income and various sources where money could be lying around digitally.
* Calculate zakat in common but extraordinary circumstances, as in:
 * If you earn in 1 country but want to support poor in another country.
 * If you have gold in two different countries and the value differs and you want to calculate the zakat amount with greater accuracy.
 * If you want to know your zakat in various currencies and in gold (amount).
 
Important Note: The currency exchange rates are fetched online from two different websites. It will require that permission to enable content to load 

---
---

# 🪙 Gold Monitor — UAE Retail + Signal Engine

> Real-time gold tracking + macro signal detection + Telegram alerts  
> Built for UAE markets (AED/gram, 24K)

---

## 🚀 Overview

**Gold Monitor** is a dual-mode PHP system:

- **Cron Engine (CLI)** → detects trading signals every minute  
- **Live Dashboard (Web UI)** → renders a real-time analytics panel  

It aggregates:

- UAE retail gold price (igold.ae)
- Global macro indicators (Yahoo Finance)
- Internal state + momentum tracking

---

## ⚙️ Core Features

### 📡 Data Sources
- UAE Retail Price → `igold.ae`
- Gold Futures → `GC=F`
- Dollar Index → `DX-Y.NYB`
- Real Yields → `TIP ETF`
- Institutional Flow → `GLD volume`

---

### 🧠 Signal Engine (8 Signals)

| Signal | Logic |
|------|------|
| 💵 DXY Drop | USD weakening fast |
| 📉 TIP Rising | Real yields falling |
| 🎯 Bounce | Recovery from day low |
| 📈 Consecutive Up | Momentum build-up |
| 🏦 Volume Spike | Institutional buying |
| 💎 Deep Dip | >2% drop + bounce |
| ⚡ Oversold Snap | Sharp drop reversal |
| 🌍 DXY < 100 | Structural weakness |

---

### 📊 Dashboard (Frontend)

- Live AED/g price (auto-refresh every 60s)
- OHLC + intraday range visualization
- Signal cooldown tracker
- Alert history (intraday)
- API latency + debug panel
- Crumb/session health monitoring
- Responsive UI + animated elements

---

### 📲 Telegram Alerts

- Sends alerts to:
  - Group (threaded)
  - Channel
- Auto-formats:
  - Signal strength
  - Market context
  - Momentum + macro indicators

---

## 🧩 Architecture

```
gold_monitor.php

├── CLI Mode (cron)
│   ├── Fetch igold price
│   ├── Fetch Yahoo data (crumb auth)
│   ├── Run signal engine
│   ├── Send Telegram alerts
│   └── Persist state

└── Web Mode (dashboard)
    ├── Load state
    ├── Render UI
    └── Auto-refresh
```

---

## 🛠️ Setup

### 1. Requirements
- PHP **7.4+**
- cURL enabled
- Cron access

---

### 2. Clone

```bash
git clone https://github.com/your-repo/gold-monitor.git
cd gold-monitor
```

---

### 3. Configure

```php
define('TELEGRAM_BOT_TOKEN', 'YOUR_TOKEN');
define('TELEGRAM_GROUP_ID', 'YOUR_GROUP');
define('TELEGRAM_THREAD_ID', 'THREAD_ID');
define('TELEGRAM_CHANNEL_ID', 'CHANNEL_ID');
```

---

### 4. Run Cron

```bash
* * * * * php /path/to/gold_monitor.php
```

---

### 5. Open Dashboard

```
http://your-server/gold_monitor.php
```

---

## 📁 State & Logs

| File | Purpose |
|-----|--------|
| `gold_monitor_state.json` | Persistent runtime state |
| `gold_monitor.log` | Execution logs |
| `yf_cookies.txt` | Yahoo session cookies |

---

## 🔐 Reliability Features

- Yahoo crumb auto-refresh + 401 recovery
- Atomic state writes (no corruption)
- Log rotation (max 5000 lines)
- Stale price protection (>5% drift reset)
- Multi-endpoint fallback (v8 → v7)

---

## 📈 Signal Logic Highlights

- Multi-factor confirmation before alerts
- Cooldown per signal (anti-spam)
- Intraday context awareness
- Momentum + macro fusion

---

## 🎯 Example Alert

```
🚨 STRONG BUY — 3 SIGNALS ALIGNING

🕐 06 Apr · 14:21 UAE
💰 AED 285.34/g — Strong ⬆️
📊 Today: +1.42% | From low: +0.88%
💵 DXY 99.87 — Very Weak

🎯 BOUNCING OFF DAY LOW
📈 TREND TURNING UP
🏦 INSTITUTIONS BUYING
```

---

## 🧪 Debug Panel

Includes:

- API latency (ms)
- Endpoint used (v8/v7)
- Crumb TTL tracking
- State inspection
- Signal cooldown timers

---

## 📌 Notes

- Optimized for **UAE timezone (UTC+4)**
- Saturday disabled (market closed)
- Retail price fallback → calculated from futures

---

## ⚠️ Limitations

- Depends on HTML scraping (igold.ae)
- Yahoo Finance rate limits possible
- No historical storage (intraday only)

---

## 🔮 Future Improvements

- WebSocket live updates (no refresh)
- Multi-metal support (silver, platinum)
- Historical charts
- ML-based signal weighting
- REST API layer

---

## 👨‍💻 Author

**Aamer Shah**  
Red Team / Offensive Security  

---

## 🪪 License

MIT License

currency values.
