<?php
/**
 * gold_monitor.php — Gold Buy Signal Monitor + Live Dashboard
 * PHP 7.4+ | Runs every minute via cron
 *
 * When accessed via browser → renders a live gold dashboard UI.
 * When run via CLI/cron   → executes signal detection + Telegram alerts.
 *
 * Sources:
 *   - UAE retail price : igold.ae (AED/gram, 24K)
 *   - Market signals   : Yahoo Finance (GC=F, DX-Y.NYB, TIP, GLD)
 *
 * Signals:
 *   1. DXY sudden drop        — dollar weakening fast
 *   2. TIP ETF rising         — real yields falling, gold tailwind
 *   3. Bounce off day low     — price recovering from intraday floor
 *   4. Consecutive up moves   — momentum building
 *   5. GLD volume spike       — institutions accumulating
 *   6. Deep dip recovery      — gold was down >2% and now bouncing
 *   7. Oversold snap          — sharp recent drop, now reversing
 *   8. DXY below 100          — structural dollar weakness
 *
 * Fixes in this version:
 *   - 401 response from Yahoo → immediately invalidates cached crumb + retries fresh
 *   - day_low / day_high sanity guard (stale state reset if >5% from current)
 *   - Bounce signal double-gated against stale low
 *   - Oversold Snap uses price_hist window min only (no day_low mixing)
 *   - Deep Dip protected by same sanity reset
 *   - day_low_prev tracks confirmed lows only
 *   - fetch_yahoo_quote returns timing metadata for debug UI
 *   - All cURL calls use explicit CURLOPT_CONNECTTIMEOUT separate from transfer timeout
 *   - Log rotation uses atomic write
 *   - save_state uses temp file + rename for atomic update
 */

// ══════════════════════════════════════════════════════════════════
//  CONFIG
// ══════════════════════════════════════════════════════════════════

define('TELEGRAM_BOT_TOKEN',   '8768920785:AAHIMrmaYfUDeS53tMulIgY0y7NCKcXi7X5');
define('TELEGRAM_GROUP_ID',    -1002122852079);
define('TELEGRAM_THREAD_ID',   4323);
define('TELEGRAM_CHANNEL_ID',  -1003856342030);

define('DXY_DROP_PCT',         0.3);
define('TIP_RISE_PCT',         0.3);
define('BOUNCE_PCT',           0.6);
define('CONSEC_UP_COUNT',        3);
define('GLD_VOL_MULT',         2.0);
define('DAY_LOW_MIN_DROP_PCT', 0.3);
define('DEEP_DIP_PCT',         2.0);
define('DEEP_DIP_BOUNCE_PCT',  0.5);
define('OVERSOLD_DROP_PCT',    1.5);
define('OVERSOLD_WINDOW',        6);
define('DXY_WEAK_LEVEL',     100.0);
define('STALE_THRESHOLD_PCT',  5.0);

define('ACTIVE_HOUR_START',    8);
define('ACTIVE_HOUR_END',     22);
define('DAILY_SUMMARY_HOUR',  22);

define('COOLDOWN_TREND',      120);
define('COOLDOWN_BOUNCE',      60);
define('COOLDOWN_MACRO',       45);
define('COOLDOWN_VOLUME',      60);
define('COOLDOWN_DAY_LOW',     60);
define('COOLDOWN_DEEP_DIP',    90);
define('COOLDOWN_OVERSOLD',    60);
define('COOLDOWN_DXY_WEAK',   180);

define('USD_TO_AED',    3.6725);
define('OZ_TO_GRAM',   31.1035);
define('LOG_MAX_LINES', 5000);

define('STATE_FILE',  __DIR__ . '/gold_monitor_state.json');
define('LOG_FILE',    __DIR__ . '/gold_monitor.log');
define('COOKIE_FILE', __DIR__ . '/yf_cookies.txt');

// ══════════════════════════════════════════════════════════════════
//  BROWSER vs CLI ROUTING
// ══════════════════════════════════════════════════════════════════

date_default_timezone_set('Asia/Dubai');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (php_sapi_name() !== 'cli' && isset($_SERVER['HTTP_HOST'])) {
    render_dashboard();
    exit(0);
}

// ══════════════════════════════════════════════════════════════════
//  CRON EXECUTION
// ══════════════════════════════════════════════════════════════════

$state  = load_state();
$now    = time();
$hour   = (int) date('G');
$alerts = [];

if ((int) date('N') === 6) {
    log_msg("Saturday — market closed.");
    exit(0);
}

// ── Step 1: igold.ae retail price ────────────────────────────────
$igold_t0   = microtime(true);
$retail_aed = fetch_igold_price();
$igold_ms   = round((microtime(true) - $igold_t0) * 1000);
if ($retail_aed === null) {
    log_msg("WARN: igold.ae fetch failed ({$igold_ms}ms) — will calculate from Yahoo spot.");
} else {
    log_msg("igold.ae OK: AED {$retail_aed}/g ({$igold_ms}ms)");
}

// ── Step 2: Yahoo Finance ─────────────────────────────────────────
$auth = get_yahoo_auth();
if ($auth === false) {
    log_msg("FATAL: Yahoo Finance auth failed.");
    die("Yahoo auth failed.");
}

$yf      = [];
$yf_meta = [];   // timing + endpoint metadata per ticker
foreach (['GC=F', 'DX-Y.NYB', 'TIP', 'GLD'] as $ticker) {
    $t0 = microtime(true);
    $q  = fetch_yahoo_quote($ticker, $auth);
    $ms = round((microtime(true) - $t0) * 1000);

    // Handle 401: invalidate crumb and retry once
    if ($q === null && isset($q['_http']) && $q['_http'] === 401) {
        log_msg("WARN: $ticker got 401 — invalidating crumb and retrying.");
        $auth = invalidate_crumb_and_reauth();
        if ($auth !== false) {
            $t0 = microtime(true);
            $q  = fetch_yahoo_quote($ticker, $auth);
            $ms = round((microtime(true) - $t0) * 1000);
        }
    }

    if ($q === null) {
        log_msg("WARN: Failed to fetch $ticker ({$ms}ms)");
        $yf_meta[$ticker] = ['ms' => $ms, 'status' => 'FAIL', 'endpoint' => '—'];
    } else {
        $yf[$ticker]      = $q;
        $endpoint         = $q['_endpoint'] ?? 'v8';
        $yf_meta[$ticker] = ['ms' => $ms, 'status' => 'OK', 'endpoint' => $endpoint];
        log_msg("OK: $ticker price=" . $q['price'] . " ({$ms}ms via {$endpoint})");
    }
}

// Persist timing metadata to state for dashboard display
$state['yf_meta']    = $yf_meta;
$state['igold_ms']   = $igold_ms;
$state['last_run_ts'] = $now;

if (!isset($yf['GC=F'])) {
    log_msg("FATAL: No GC=F data.");
    save_state($state);
    die("No gold data.");
}

$gold    = (float) $yf['GC=F']['price'];
$dxy     = isset($yf['DX-Y.NYB']['price']) ? (float) $yf['DX-Y.NYB']['price'] : null;
$tip     = isset($yf['TIP']['price'])      ? (float) $yf['TIP']['price']      : null;
$gld_vol = isset($yf['GLD']['volume'])     ? (int)   $yf['GLD']['volume']     : null;
$gld_avg = isset($state['gld_avg_volume']) ? (float) $state['gld_avg_volume'] : null;
$prev    = isset($state['prev_gold'])      ? (float) $state['prev_gold']      : null;

$display_aed = ($retail_aed !== null && $retail_aed > 0)
    ? $retail_aed
    : round($gold * USD_TO_AED / OZ_TO_GRAM, 2);

// Persist live prices to state for dashboard
$state['live_display_aed'] = $display_aed;
$state['live_gold_usd']    = $gold;
$state['live_dxy']         = $dxy;
$state['live_tip']         = $tip;
$state['live_gld_vol']     = $gld_vol;
$state['live_gld_avg']     = $gld_avg;

// ── Step 3: Day tracking + stale-state guard ──────────────────────
$day_start_ts = isset($state['day_start_ts']) ? (int) $state['day_start_ts'] : 0;
$is_new_day   = !isset($state['day_open_gold'])
             || date('Y-m-d', $day_start_ts) !== date('Y-m-d');

if ($is_new_day) {
    $state['day_open_gold']   = $gold;
    $state['day_high']        = $gold;
    $state['day_low']         = $gold;
    $state['day_low_prev']    = $gold;
    $state['day_start_ts']    = $now;
    $state['consec_up']       = 0;
    $state['signals_today']   = 0;
    $state['day_low_reached'] = false;
    $state['gold_price_hist'] = [$gold];
    $state['alert_log']       = [];   // reset intraday alert log for dashboard
    log_msg("New day started. Open=" . fmt_aed($display_aed));
}

$day_open = (float) $state['day_open_gold'];
$day_low  = (float) $state['day_low'];
$day_high = (float) $state['day_high'];

if ($day_low < $gold * (1 - STALE_THRESHOLD_PCT / 100)) {
    log_msg(sprintf("WARN: day_low stale (%s vs %s) — resetting.", fmt_aed_from_usd($day_low), fmt_aed($display_aed)));
    $day_low = $gold; $state['day_low'] = $gold; $state['day_low_prev'] = $gold;
}
if ($day_high > $gold * (1 + STALE_THRESHOLD_PCT / 100)) {
    log_msg(sprintf("WARN: day_high stale (%s vs %s) — resetting.", fmt_aed_from_usd($day_high), fmt_aed($display_aed)));
    $day_high = $gold; $state['day_high'] = $gold;
}

if ($gold < $day_low)  { $day_low  = $gold; $state['day_low']  = $gold; }
if ($gold > $day_high) { $day_high = $gold; $state['day_high'] = $gold; }

$day_chg = pct_change($day_open, $gold);

$price_hist   = isset($state['gold_price_hist']) ? $state['gold_price_hist'] : [$gold];
$price_hist[] = $gold;
if (count($price_hist) > 30) array_shift($price_hist);
$state['gold_price_hist'] = $price_hist;

$in_hours = ($hour >= ACTIVE_HOUR_START && $hour < ACTIVE_HOUR_END);

// ── Step 4: NEW DAY LOW alert ─────────────────────────────────────
$prev_day_low = isset($state['day_low_prev']) ? (float) $state['day_low_prev'] : $day_open;
$low_drop_pct = pct_change($prev_day_low, $gold);

if ($gold < $prev_day_low
    && abs($low_drop_pct) >= DAY_LOW_MIN_DROP_PCT
    && cooldown_ok($state, 'day_low', COOLDOWN_DAY_LOW, $now)
) {
    $alerts[] = [
        'key'  => 'day_low',
        'text' => "🔴 *NEW DAY LOW*\n"
                . "`" . fmt_aed($display_aed) . "` — down `" . round(abs($day_chg), 2) . "%` from open\n"
                . "_(dropped another " . round(abs($low_drop_pct), 2) . "% from previous low)_",
    ];
    $state['day_low_prev']    = $gold;
    $state['day_low_reached'] = true;
}

// ── Step 5: Bullish signals ───────────────────────────────────────
if ($in_hours) {

    if ($dxy !== null && isset($state['prev_dxy']) && (float)$state['prev_dxy'] > 0) {
        $d = pct_change((float)$state['prev_dxy'], $dxy);
        if ($d <= -DXY_DROP_PCT && cooldown_ok($state, 'dxy_drop', COOLDOWN_MACRO, $now)) {
            $alerts[] = ['key' => 'dxy_drop', 'text' =>
                "💵 *DOLLAR WEAKENING*\n"
                . "DXY `" . round($state['prev_dxy'], 2) . "` → `" . round($dxy, 2) . "` "
                . "(" . dxy_label($dxy) . ") — fell " . round(abs($d), 2) . "% this run"];
        }
    }

    if ($tip !== null && isset($state['prev_tip']) && (float)$state['prev_tip'] > 0) {
        $t = pct_change((float)$state['prev_tip'], $tip);
        if ($t >= TIP_RISE_PCT && cooldown_ok($state, 'tip_rise', COOLDOWN_MACRO, $now)) {
            $alerts[] = ['key' => 'tip_rise', 'text' =>
                "📉 *REAL YIELDS FALLING*\n"
                . "TIP ETF +" . round($t, 2) . "% — inflation premium rising, gold tailwind"];
        }
    }

    if ($day_low > 0 && $gold > $day_low) {
        $bounce           = pct_change($day_low, $gold);
        $low_is_plausible = ($day_low >= $gold * (1 - STALE_THRESHOLD_PCT / 100));
        if ($low_is_plausible && $bounce >= BOUNCE_PCT && cooldown_ok($state, 'bounce', COOLDOWN_BOUNCE, $now)) {
            $alerts[] = ['key' => 'bounce', 'text' =>
                "🎯 *BOUNCING OFF DAY LOW*\n"
                . "+" . round($bounce, 2) . "% recovery from low `" . fmt_aed_from_usd($day_low) . "`\n"
                . "Now at `" . fmt_aed($display_aed) . "`"];
        }
    }

    $consec = isset($state['consec_up']) ? (int)$state['consec_up'] : 0;
    if ($prev !== null) {
        $was_at = ($consec >= CONSEC_UP_COUNT);
        $consec = ($gold > $prev) ? $consec + 1 : 0;
        $state['consec_up'] = $consec;
        if ($consec === CONSEC_UP_COUNT && !$was_at && cooldown_ok($state, 'consec_up', COOLDOWN_TREND, $now)) {
            $alerts[] = ['key' => 'consec_up', 'text' =>
                "📈 *TREND TURNING UP*\n"
                . $consec . " consecutive higher closes — momentum building\n"
                . "Now at `" . fmt_aed($display_aed) . "`"];
        }
    } else {
        $state['consec_up'] = 0;
    }

    if ($gld_vol !== null && $gld_avg !== null && $gld_avg > 0 && $prev !== null) {
        $vm = $gld_vol / $gld_avg;
        if ($vm >= GLD_VOL_MULT && $gold > $prev && cooldown_ok($state, 'gld_vol', COOLDOWN_VOLUME, $now)) {
            $alerts[] = ['key' => 'gld_vol', 'text' =>
                "🏦 *INSTITUTIONS BUYING*\n"
                . "GLD volume " . round($vm, 1) . "x average on a rising candle\n"
                . "Strong institutional accumulation signal"];
        }
    }

    if ($day_chg <= -DEEP_DIP_PCT && $day_low > 0 && $gold > $day_low) {
        $bfl = pct_change($day_low, $gold);
        if ($bfl >= DEEP_DIP_BOUNCE_PCT && cooldown_ok($state, 'deep_dip', COOLDOWN_DEEP_DIP, $now)) {
            $alerts[] = ['key' => 'deep_dip', 'text' =>
                "💎 *DEEP DIP RECOVERY*\n"
                . "Gold down " . round(abs($day_chg), 2) . "% on day — bouncing +" . round($bfl, 2) . "% off low\n"
                . "`" . fmt_aed_from_usd($day_low) . "` → `" . fmt_aed($display_aed) . "`\n"
                . "_Potential buy zone_"];
        }
    }

    if (count($price_hist) >= OVERSOLD_WINDOW + 1 && $prev !== null && $gold > $prev) {
        $ws   = array_slice($price_hist, -(OVERSOLD_WINDOW + 1));
        $wdrop = pct_change((float)$ws[0], (float)min($ws));
        if ($wdrop <= -OVERSOLD_DROP_PCT && $gold > min($ws) && cooldown_ok($state, 'oversold', COOLDOWN_OVERSOLD, $now)) {
            $alerts[] = ['key' => 'oversold', 'text' =>
                "⚡ *OVERSOLD SNAP*\n"
                . "Gold fell " . round(abs($wdrop), 2) . "% in last " . OVERSOLD_WINDOW . " runs — now reversing\n"
                . "Early bounce signal at `" . fmt_aed($display_aed) . "`"];
        }
    }

    if ($dxy !== null && $dxy < DXY_WEAK_LEVEL && cooldown_ok($state, 'dxy_weak', COOLDOWN_DXY_WEAK, $now)) {
        $alerts[] = ['key' => 'dxy_weak', 'text' =>
            "🌍 *DOLLAR BELOW 100*\n"
            . "DXY at `" . round($dxy, 2) . "` — structurally weak dollar\n"
            . "Gold historically outperforms when DXY < 100"];
    }
}

// ── Step 6: Rolling state update ─────────────────────────────────
if ($gld_vol !== null) {
    $vh   = isset($state['gld_vol_history']) ? $state['gld_vol_history'] : [];
    $vh[] = $gld_vol;
    if (count($vh) > 20) array_shift($vh);
    $state['gld_vol_history'] = $vh;
    $state['gld_avg_volume']  = array_sum($vh) / count($vh);
}
if ($dxy !== null) $state['prev_dxy']  = $dxy;
if ($tip !== null) $state['prev_tip']  = $tip;
$state['prev_gold'] = $gold;

// ── Step 7: Build + send alerts ──────────────────────────────────
$bullish_keys  = ['dxy_drop','tip_rise','bounce','consec_up','gld_vol','deep_dip','oversold','dxy_weak'];
$bullish_count = 0;
foreach ($alerts as $a) {
    if (in_array($a['key'], $bullish_keys)) $bullish_count++;
}

if (!empty($alerts)) {
    $consec_val = isset($state['consec_up']) ? (int)$state['consec_up'] : 0;
    $consec_str = $consec_val > 0 ? $consec_val . "↑ in a row" : "—";
    $chg_sign   = $day_chg >= 0 ? "+" : "";
    $from_low   = ($day_low > 0 && $day_low < $gold) ? pct_change($day_low, $gold) : 0;
    $bounce_str = $from_low >= 0.05 ? "+" . round($from_low, 2) . "%" : "—";

    $msg = "";
    if ($bullish_count >= 2) {
        $msg .= "🚨 *STRONG BUY — {$bullish_count} SIGNALS ALIGNING*\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    }
    $msg .= "🕐 *" . date('d M · H:i') . " UAE*\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "💰 `" . fmt_aed($display_aed) . "` — " . strength_label($day_chg) . "\n";
    $msg .= "📊 Today: `{$chg_sign}" . round($day_chg, 2) . "%`  |  From low: `{$bounce_str}`\n";
    $msg .= "💵 DXY `" . ($dxy !== null ? round($dxy, 2) : "—") . "` — " . dxy_label($dxy) . "\n";
    $msg .= "🔺 Momentum: {$consec_str}\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";

    $alert_log = isset($state['alert_log']) ? $state['alert_log'] : [];
    foreach ($alerts as $a) {
        $msg .= $a['text'] . "\n\n";
        $state['last_alert'][$a['key']] = $now;
        $alert_log[] = ['time' => date('H:i'), 'key' => $a['key'], 'ts' => $now];
    }
    if (count($alert_log) > 50) $alert_log = array_slice($alert_log, -50);
    $state['alert_log'] = $alert_log;

    $sent = send_telegram_all(rtrim($msg));
    $state['signals_today'] = (isset($state['signals_today']) ? (int)$state['signals_today'] : 0) + count($alerts);
    log_msg("Sent " . count($alerts) . " signal(s). Telegram=" . ($sent ? 'OK' : 'PARTIAL/FAIL'));
} else {
    log_msg(sprintf("No signals. Gold=%s day=%+.2f%% active=%s", fmt_aed($display_aed), $day_chg, $in_hours ? 'yes' : 'no'));
}

// ── Step 8: Daily summary ─────────────────────────────────────────
$last_sum  = isset($state['last_daily_summary']) ? (int)$state['last_daily_summary'] : 0;
$sum_today = (date('Y-m-d', $last_sum) === date('Y-m-d'));

if ($hour === DAILY_SUMMARY_HOUR && !$sum_today) {
    $d_chg_str   = ($day_chg >= 0 ? "🟢 +" : "🔴 ") . round($day_chg, 2) . "%";
    $low_line    = (isset($state['day_low_reached']) && $state['day_low_reached'])
        ? "⚠️ *Daily low was reached* today at `" . fmt_aed_from_usd($day_low) . "`"
        : "✅ No significant new low reached today";
    $signals_cnt = isset($state['signals_today']) ? (int)$state['signals_today'] : 0;

    $summary  = "📋 *DAILY GOLD SUMMARY*\n━━━━━━━━━━━━━━━━━━━━━━\n";
    $summary .= "📅 " . date('d M Y') . "\n\n";
    $summary .= "💰 Close:  `" . fmt_aed($display_aed) . "`\n";
    $summary .= "📈 High:   `" . fmt_aed_from_usd($day_high) . "`\n";
    $summary .= "📉 Low:    `" . fmt_aed_from_usd($day_low) . "`\n";
    $summary .= "📊 Change: {$d_chg_str}\n";
    $summary .= "📐 Range:  `" . round(pct_change($day_low, $day_high), 2) . "%` (low to high)\n";
    $summary .= "💵 DXY: `" . ($dxy !== null ? round($dxy, 2) : "—") . "` — " . dxy_label($dxy) . "\n";
    $summary .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $summary .= "{$low_line}\n";
    $summary .= "_Signals fired today: {$signals_cnt}_";

    if (send_telegram_all($summary)) {
        $state['last_daily_summary'] = $now;
        log_msg("Daily summary sent.");
    }
}

save_state($state);
rotate_log();


// ══════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════

function fmt_aed(float $aed): string {
    return "AED " . number_format($aed, 2) . "/g";
}
function fmt_aed_from_usd(float $usd_oz): string {
    return fmt_aed(round($usd_oz * USD_TO_AED / OZ_TO_GRAM, 2));
}
function pct_change(float $from, float $to): float {
    if ($from == 0.0) return 0.0;
    return (($to - $from) / $from) * 100;
}
function cooldown_ok(array $state, string $key, int $minutes, int $now): bool {
    $last = isset($state['last_alert'][$key]) ? (int)$state['last_alert'][$key] : 0;
    return ($now - $last) >= ($minutes * 60);
}
function dxy_label(?float $dxy): string {
    if ($dxy === null) return "—";
    if ($dxy >= 106)   return "Strong 🔴";
    if ($dxy >= 103)   return "Normal 🟠";
    if ($dxy >= 100)   return "Weak 🟡";
    return "Very Weak 🟢";
}
function dxy_label_plain(?float $dxy): string {
    if ($dxy === null) return "—";
    if ($dxy >= 106)   return "Strong";
    if ($dxy >= 103)   return "Normal";
    if ($dxy >= 100)   return "Weak";
    return "Very Weak";
}
function strength_label(float $pct): string {
    if ($pct >= 2.5)  return "Very Strong 💪";
    if ($pct >= 1.5)  return "Strong ⬆️";
    if ($pct >= 1.0)  return "Moderate 📶";
    if ($pct >= 0)    return "Flat ➡️";
    if ($pct >= -1.5) return "Down ⬇️";
    if ($pct >= -3.0) return "Falling 📉";
    return "Sharp Drop 🆘";
}


// ══════════════════════════════════════════════════════════════════
//  DATA FETCHERS
// ══════════════════════════════════════════════════════════════════

function fetch_igold_price(): ?float {
    $ch = curl_init('https://igold.ae/gold-rate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (empty($html)) return null;
    if (preg_match('/24K[\s\S]*?(\d{3,4}\.\d{2})\s*AED/i', $html, $m)) {
        $price = (float)$m[1];
        if ($price > 100 && $price < 2000) return $price;
    }
    return null;
}

/**
 * Invalidate cached crumb from state and perform a fresh auth.
 * Called when a 401 is received from Yahoo Finance.
 */
function invalidate_crumb_and_reauth(): array|false {
    $state = load_state();
    unset($state['yf_crumb'], $state['yf_crumb_ts']);
    save_state($state);
    log_msg("Crumb invalidated due to 401. Re-authing...");
    if (file_exists(COOKIE_FILE)) @unlink(COOKIE_FILE);
    return get_yahoo_auth();
}

/**
 * Yahoo Finance auth — 4-strategy crumb acquisition with state caching.
 * Strategies:
 *   1. Cached crumb in state (valid 55 min)
 *   2. Standard getcrumb endpoint after consent cookie
 *   3. CrumbStore / JS pattern from finance.yahoo.com HTML
 *   4. Quote page HTML extraction + getcrumb retry
 */
function get_yahoo_auth(): array|false {
    $jar   = COOKIE_FILE;
    $state = load_state();

    $cached_crumb = isset($state['yf_crumb'])    ? $state['yf_crumb']    : '';
    $cached_at    = isset($state['yf_crumb_ts']) ? (int)$state['yf_crumb_ts'] : 0;
    if (!empty($cached_crumb) && file_exists($jar) && (time() - $cached_at) < 3300) {
        log_msg("Yahoo auth: cached crumb OK (age=" . round((time()-$cached_at)/60) . "min)");
        return ['jar' => $jar, 'crumb' => $cached_crumb];
    }

    $ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $hdrs = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
    ];

    $finance_html = '';
    foreach (['https://fc.yahoo.com', 'https://finance.yahoo.com'] as $seed) {
        $ch = curl_init($seed);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_ENCODING       => '',
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        if (strpos($seed, 'finance') !== false) $finance_html = (string)$out;
    }

    $crumb      = '';
    $http       = 0;
    $crumb_hdrs = array_merge($hdrs, ['Referer: https://finance.yahoo.com/', 'X-Requested-With: XMLHttpRequest']);

    $ch = curl_init('https://query2.finance.yahoo.com/v1/test/getcrumb');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => $crumb_hdrs,
        CURLOPT_ENCODING       => '',
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200 && !empty($resp)) {
        $c = trim((string)$resp);
        if (strlen($c) < 50 && !preg_match('/<|{/', $c)) { $crumb = $c; log_msg("Yahoo auth: crumb via getcrumb OK"); }
    }

    if (empty($crumb) && !empty($finance_html)) {
        if (preg_match('/"CrumbStore"\s*:\s*\{"crumb"\s*:\s*"([^"]+)"\}/', $finance_html, $m)) {
            $crumb = $m[1]; log_msg("Yahoo auth: crumb via CrumbStore");
        } elseif (preg_match('/crumb\s*[=:]\s*["\']([A-Za-z0-9\/._-]{8,20})["\']/', $finance_html, $m)) {
            $crumb = $m[1]; log_msg("Yahoo auth: crumb via JS pattern");
        }
    }

    if (empty($crumb)) {
        $ch = curl_init('https://finance.yahoo.com/quote/GC=F');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_ENCODING       => '',
        ]);
        $qhtml = curl_exec($ch);
        curl_close($ch);
        if (!empty($qhtml) && preg_match('/"crumb"\s*:\s*"([^"]{5,20})"/', $qhtml, $m)) {
            $crumb = $m[1]; log_msg("Yahoo auth: crumb via quote page HTML");
        }
        if (empty($crumb)) {
            $ch = curl_init('https://query2.finance.yahoo.com/v1/test/getcrumb');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEFILE => $jar, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => $crumb_hdrs, CURLOPT_ENCODING => '',
            ]);
            $r2 = curl_exec($ch); $h2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($h2 === 200 && !empty($r2)) {
                $c = trim((string)$r2);
                if (strlen($c) < 50 && !preg_match('/<|{/', $c)) { $crumb = $c; log_msg("Yahoo auth: crumb via getcrumb retry OK"); }
            }
        }
    }

    if (empty($crumb)) { log_msg("FATAL: all Yahoo crumb strategies failed (HTTP=$http)"); return false; }

    $state['yf_crumb']    = $crumb;
    $state['yf_crumb_ts'] = time();
    save_state($state);
    return ['jar' => $jar, 'crumb' => $crumb];
}

function fetch_yahoo_quote(string $ticker, array $auth): ?array {
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $common = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,    CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIEFILE => $auth['jar'],
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Referer: https://finance.yahoo.com/'],
        CURLOPT_ENCODING   => '',
    ];

    // v8 chart endpoint
    $url = 'https://query2.finance.yahoo.com/v8/finance/chart/' . urlencode($ticker)
         . '?interval=1m&range=1d&crumb=' . urlencode($auth['crumb']);
    $ch  = curl_init($url);
    curl_setopt_array($ch, $common);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 401 — signal back to caller to invalidate crumb
    if ($http === 401) return ['_http' => 401, 'price' => null, 'volume' => null, '_endpoint' => 'v8'];

    if ($http === 200 && !empty($resp)) {
        $json   = json_decode($resp, true);
        $result = $json['chart']['result'][0] ?? null;
        if ($result) {
            $ind     = $result['indicators']['quote'][0] ?? [];
            $closes  = array_values(array_filter($ind['close']  ?? [], fn($v) => $v !== null));
            $volumes = array_values(array_filter($ind['volume'] ?? [], fn($v) => $v !== null));
            $price   = !empty($closes) ? (float)end($closes) : ($result['meta']['regularMarketPrice'] ?? null);
            $volume  = !empty($volumes) ? (int)end($volumes) : null;
            if ($price !== null) return ['price' => $price, 'volume' => $volume, '_endpoint' => 'v8', '_http' => 200];
        }
    }

    // v7 fallback
    log_msg("WARN: $ticker v8 failed (HTTP=$http) — trying v7");
    $url2 = 'https://query1.finance.yahoo.com/v7/finance/quote?symbols=' . urlencode($ticker);
    $ch2  = curl_init($url2);
    curl_setopt_array($ch2, $common);
    $resp2 = curl_exec($ch2);
    $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($http2 === 200 && !empty($resp2)) {
        $j2 = json_decode($resp2, true);
        $q  = $j2['quoteResponse']['result'][0] ?? null;
        if ($q && isset($q['regularMarketPrice'])) {
            return ['price' => (float)$q['regularMarketPrice'], 'volume' => $q['regularMarketVolume'] ?? null, '_endpoint' => 'v7', '_http' => $http2];
        }
    }

    log_msg("WARN: $ticker v7 also failed (HTTP=$http2)");
    return null;
}


// ══════════════════════════════════════════════════════════════════
//  TELEGRAM
// ══════════════════════════════════════════════════════════════════

function send_telegram_all(string $message): bool {
    $dests  = [
        ['chat_id' => TELEGRAM_GROUP_ID,   'thread_id' => TELEGRAM_THREAD_ID],
        ['chat_id' => TELEGRAM_CHANNEL_ID, 'thread_id' => null],
    ];
    $all_ok = true;
    foreach ($dests as $d) {
        if (!send_telegram_single($message, $d['chat_id'], $d['thread_id'])) {
            log_msg("WARN: Telegram fail chat_id=" . $d['chat_id']);
            $all_ok = false;
        }
    }
    return $all_ok;
}

function send_telegram_single(string $msg, int $chat_id, ?int $thread_id): bool {
    $payload = ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'Markdown'];
    if ($thread_id !== null) $payload['message_thread_id'] = $thread_id;
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) { log_msg("Telegram error (chat=$chat_id HTTP=$code): " . substr($resp, 0, 200)); return false; }
    return true;
}


// ══════════════════════════════════════════════════════════════════
//  STATE + LOG
// ══════════════════════════════════════════════════════════════════

function load_state(): array {
    if (!file_exists(STATE_FILE)) return [];
    $raw = @file_get_contents(STATE_FILE);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function save_state(array $state): void {
    // Atomic write: write to temp then rename to avoid partial reads
    $tmp = STATE_FILE . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, STATE_FILE);
}

function log_msg(string $msg): void {
    file_put_contents(LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function rotate_log(): void {
    if (!file_exists(LOG_FILE)) return;
    $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) > LOG_MAX_LINES) {
        $tmp = LOG_FILE . '.tmp.' . getmypid();
        file_put_contents($tmp, implode(PHP_EOL, array_slice($lines, -LOG_MAX_LINES)) . PHP_EOL, LOCK_EX);
        rename($tmp, LOG_FILE);
    }
}


// ══════════════════════════════════════════════════════════════════
//  DASHBOARD UI
// ══════════════════════════════════════════════════════════════════

function render_dashboard(): void {
    $state = load_state();

    // Pull all values safely
    $aed          = isset($state['live_display_aed'])  ? (float)$state['live_display_aed']  : 0;
    $gold_usd     = isset($state['live_gold_usd'])     ? (float)$state['live_gold_usd']      : 0;
    $dxy          = isset($state['live_dxy'])          ? (float)$state['live_dxy']           : null;
    $tip          = isset($state['live_tip'])          ? (float)$state['live_tip']           : null;
    $gld_vol      = isset($state['live_gld_vol'])      ? (int)$state['live_gld_vol']         : null;
    $gld_avg      = isset($state['live_gld_avg'])      ? (float)$state['live_gld_avg']       : null;
    $day_open     = isset($state['day_open_gold'])     ? (float)$state['day_open_gold']      : $gold_usd;
    $day_high     = isset($state['day_high'])          ? (float)$state['day_high']           : $gold_usd;
    $day_low      = isset($state['day_low'])           ? (float)$state['day_low']            : $gold_usd;
    $day_chg      = $day_open > 0 ? pct_change($day_open, $gold_usd) : 0;
    $consec       = isset($state['consec_up'])         ? (int)$state['consec_up']            : 0;
    $signals_today= isset($state['signals_today'])     ? (int)$state['signals_today']        : 0;
    $last_run     = isset($state['last_run_ts'])       ? (int)$state['last_run_ts']          : 0;
    $crumb_age    = isset($state['yf_crumb_ts'])       ? round((time()-(int)$state['yf_crumb_ts'])/60) : null;
    $yf_meta      = isset($state['yf_meta'])           ? $state['yf_meta']                  : [];
    $igold_ms     = isset($state['igold_ms'])          ? (int)$state['igold_ms']             : null;
    $alert_log    = isset($state['alert_log'])         ? array_reverse($state['alert_log'])  : [];
    $day_low_hit  = isset($state['day_low_reached'])   ? (bool)$state['day_low_reached']     : false;
    $low_prev     = isset($state['day_low_prev'])      ? (float)$state['day_low_prev']       : $day_low;

    $high_aed     = round($day_high * USD_TO_AED / OZ_TO_GRAM, 2);
    $low_aed      = round($day_low  * USD_TO_AED / OZ_TO_GRAM, 2);
    $open_aed     = round($day_open * USD_TO_AED / OZ_TO_GRAM, 2);
    $range_pct    = $day_low > 0 ? round(pct_change($day_low, $day_high), 2) : 0;
    $from_low_pct = ($day_low > 0 && $day_low < $gold_usd) ? round(pct_change($day_low, $gold_usd), 2) : 0;
    $chg_color    = $day_chg >= 0 ? '#22c55e' : '#ef4444';
    $chg_sign     = $day_chg >= 0 ? '+' : '';

    $vol_mult     = ($gld_avg !== null && $gld_avg > 0 && $gld_vol !== null) ? round($gld_vol / $gld_avg, 2) : null;
    $last_run_str = $last_run > 0 ? date('d M · H:i:s', $last_run) : 'Never';
    $age_secs     = $last_run > 0 ? (time() - $last_run) : null;
    $age_str      = $age_secs !== null ? ($age_secs < 120 ? $age_secs . 's ago' : round($age_secs/60) . 'm ago') : '—';

    $dxy_val      = $dxy !== null ? round($dxy, 2) : '—';
    $dxy_lbl      = dxy_label_plain($dxy);
    $dxy_color    = $dxy === null ? '#6b7280' : ($dxy < 100 ? '#22c55e' : ($dxy < 103 ? '#eab308' : '#ef4444'));

    $tip_val      = $tip !== null ? number_format($tip, 2) : '—';

    $signal_labels = [
        'day_low'  => ['🔴', 'New Day Low',     'bear'],
        'dxy_drop' => ['💵', 'DXY Drop',        'bull'],
        'tip_rise' => ['📉', 'Yields Falling',  'bull'],
        'bounce'   => ['🎯', 'Bounce off Low',  'bull'],
        'consec_up'=> ['📈', 'Trend Up',        'bull'],
        'gld_vol'  => ['🏦', 'Vol Spike',       'bull'],
        'deep_dip' => ['💎', 'Deep Dip',        'bull'],
        'oversold' => ['⚡', 'Oversold Snap',   'bull'],
        'dxy_weak' => ['🌍', 'DXY < 100',       'bull'],
    ];

    // Cooldown remaining per signal
    $now = time();
    $cooldowns = [
        'day_low'   => COOLDOWN_DAY_LOW,
        'dxy_drop'  => COOLDOWN_MACRO,
        'tip_rise'  => COOLDOWN_MACRO,
        'bounce'    => COOLDOWN_BOUNCE,
        'consec_up' => COOLDOWN_TREND,
        'gld_vol'   => COOLDOWN_VOLUME,
        'deep_dip'  => COOLDOWN_DEEP_DIP,
        'oversold'  => COOLDOWN_OVERSOLD,
        'dxy_weak'  => COOLDOWN_DXY_WEAK,
    ];
    $last_alerts = isset($state['last_alert']) ? $state['last_alert'] : [];

    ob_start();
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gold Monitor · UAE</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Mono:wght@400;500&family=Geist:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:        #0a0a0b;
    --surface:   #111113;
    --surface2:  #18181c;
    --border:    #222228;
    --border2:   #2d2d35;
    --gold:      #d4a843;
    --gold2:     #f0c560;
    --gold-dim:  #8a6c2a;
    --text:      #e8e8ec;
    --muted:     #6b6b7a;
    --muted2:    #4a4a58;
    --green:     #22c55e;
    --red:       #ef4444;
    --yellow:    #eab308;
    --blue:      #3b82f6;
    --radius:    12px;
    --radius-sm: 8px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { font-size: 16px; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Geist', sans-serif;
    font-weight: 400;
    min-height: 100vh;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }

  /* Noise texture overlay */
  body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
    opacity: 0.4;
  }

  .wrap { position: relative; z-index: 1; max-width: 1100px; margin: 0 auto; padding: 24px 16px 60px; }

  /* ── Header ─────────────────────────────────── */
  .hdr {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-bottom: 32px; flex-wrap: wrap; gap: 12px;
  }
  .hdr-left h1 {
    font-family: 'Instrument Serif', serif;
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    font-weight: 400; letter-spacing: -0.02em;
    background: linear-gradient(135deg, var(--gold2) 0%, var(--gold) 60%, var(--gold-dim) 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .hdr-left p { font-size: 0.78rem; color: var(--muted); margin-top: 3px; letter-spacing: 0.06em; text-transform: uppercase; }
  .hdr-right { text-align: right; }
  .last-run { font-family: 'DM Mono', monospace; font-size: 0.72rem; color: var(--muted); }
  .live-dot {
    display: inline-block; width: 7px; height: 7px; border-radius: 50%;
    background: var(--green); margin-right: 5px;
    animation: pulse 2s ease-in-out infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.4;transform:scale(0.7)} }

  /* ── Grid ───────────────────────────────────── */
  .grid-hero { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
  .grid-3    { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px; }
  .grid-2    { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }

  /* ── Cards ──────────────────────────────────── */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    transition: border-color 0.2s;
  }
  .card:hover { border-color: var(--border2); }
  .card-label {
    font-size: 0.68rem; font-weight: 500; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--muted); margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
  }
  .card-label .dot { width: 5px; height: 5px; border-radius: 50%; background: var(--gold-dim); }

  /* ── Hero price card ────────────────────────── */
  .hero-price {
    grid-column: 1 / -1;
    background: linear-gradient(135deg, #141410 0%, #111113 50%, #0e0e10 100%);
    border-color: var(--gold-dim);
    padding: 28px 24px;
    position: relative; overflow: hidden;
  }
  .hero-price::after {
    content: '';
    position: absolute; top: -40px; right: -40px;
    width: 180px; height: 180px; border-radius: 50%;
    background: radial-gradient(circle, rgba(212,168,67,0.08) 0%, transparent 70%);
    pointer-events: none;
  }
  .price-main {
    font-family: 'Instrument Serif', serif;
    font-size: clamp(2.4rem, 6vw, 3.8rem);
    font-weight: 400; letter-spacing: -0.03em;
    color: var(--gold2); line-height: 1;
  }
  .price-sub {
    font-family: 'DM Mono', monospace;
    font-size: 0.8rem; color: var(--muted); margin-top: 6px;
  }
  .price-change {
    font-family: 'DM Mono', monospace;
    font-size: 1.1rem; font-weight: 500;
    margin-top: 12px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  }
  .badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 10px; border-radius: 99px;
    font-size: 0.72rem; font-weight: 500; letter-spacing: 0.04em;
  }
  .badge-bull { background: rgba(34,197,94,0.12); color: var(--green); border: 1px solid rgba(34,197,94,0.2); }
  .badge-bear { background: rgba(239,68,68,0.12);  color: var(--red);   border: 1px solid rgba(239,68,68,0.2); }
  .badge-neutral { background: rgba(107,107,122,0.15); color: var(--muted); border: 1px solid var(--border); }
  .badge-gold { background: rgba(212,168,67,0.12); color: var(--gold); border: 1px solid rgba(212,168,67,0.2); }

  /* ── Stat cards ─────────────────────────────── */
  .stat-val {
    font-family: 'DM Mono', monospace;
    font-size: 1.35rem; font-weight: 500; color: var(--text);
    line-height: 1; margin-bottom: 4px;
  }
  .stat-sub { font-size: 0.72rem; color: var(--muted); font-family: 'DM Mono', monospace; }

  /* ── OHLC row ────────────────────────────────── */
  .ohlc { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; }
  .ohlc-item { padding: 14px 16px; border-right: 1px solid var(--border); }
  .ohlc-item:last-child { border-right: none; }
  .ohlc-lbl { font-size: 0.63rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); margin-bottom: 5px; }
  .ohlc-val { font-family: 'DM Mono', monospace; font-size: 0.95rem; font-weight: 500; }

  /* ── Range bar ───────────────────────────────── */
  .range-bar-wrap { margin-top: 14px; padding: 0 16px 16px; }
  .range-bar-track {
    height: 4px; background: var(--surface2); border-radius: 2px; position: relative; margin: 8px 0;
  }
  .range-bar-fill {
    position: absolute; left: 0; top: 0; height: 100%; border-radius: 2px;
    background: linear-gradient(90deg, var(--red), var(--gold), var(--green));
  }
  .range-bar-cursor {
    position: absolute; top: 50%; transform: translate(-50%,-50%);
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--gold2); border: 2px solid var(--bg);
    box-shadow: 0 0 8px rgba(240,197,96,0.5);
  }
  .range-labels { display: flex; justify-content: space-between; font-family: 'DM Mono', monospace; font-size: 0.68rem; color: var(--muted); }

  /* ── Signals grid ────────────────────────────── */
  .signals-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; }
  .sig-chip {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: 10px 12px;
    display: flex; flex-direction: column; gap: 4px;
  }
  .sig-chip.ready  { border-color: rgba(34,197,94,0.25); background: rgba(34,197,94,0.04); }
  .sig-chip.cooling{ border-color: var(--border); }
  .sig-top { display: flex; align-items: center; justify-content: space-between; }
  .sig-icon { font-size: 1rem; }
  .sig-name { font-size: 0.68rem; color: var(--muted); margin-top: 2px; }
  .sig-cd   { font-family: 'DM Mono', monospace; font-size: 0.65rem; color: var(--muted2); }
  .sig-status-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
  }
  .dot-ready   { background: var(--green); box-shadow: 0 0 6px rgba(34,197,94,0.5); }
  .dot-cooling { background: var(--muted2); }
  .dot-bear    { background: var(--red); }

  /* ── Alert log ───────────────────────────────── */
  .alert-list { display: flex; flex-direction: column; gap: 6px; max-height: 240px; overflow-y: auto; }
  .alert-list::-webkit-scrollbar { width: 4px; }
  .alert-list::-webkit-scrollbar-track { background: transparent; }
  .alert-list::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }
  .alert-row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; background: var(--surface2);
    border-radius: var(--radius-sm); border: 1px solid var(--border);
    font-size: 0.75rem;
  }
  .alert-time { font-family: 'DM Mono', monospace; color: var(--muted); font-size: 0.68rem; flex-shrink: 0; }
  .alert-key  { flex: 1; color: var(--text); }
  .alert-type { font-size: 0.7rem; }
  .empty-msg  { color: var(--muted); font-size: 0.78rem; text-align: center; padding: 20px; }

  /* ── Debug section ───────────────────────────── */
  details { margin-top: 12px; }
  summary {
    cursor: pointer; font-size: 0.72rem; font-weight: 500;
    color: var(--muted); letter-spacing: 0.08em; text-transform: uppercase;
    list-style: none; display: flex; align-items: center; gap: 6px;
    padding: 12px 16px; background: var(--surface2); border: 1px solid var(--border);
    border-radius: var(--radius-sm); user-select: none;
  }
  summary::-webkit-details-marker { display: none; }
  summary::before { content: '▶'; font-size: 0.6rem; transition: transform 0.2s; }
  details[open] summary::before { transform: rotate(90deg); }
  .debug-body {
    background: var(--surface); border: 1px solid var(--border);
    border-top: none; border-radius: 0 0 var(--radius-sm) var(--radius-sm);
    padding: 16px;
  }
  .debug-table { width: 100%; border-collapse: collapse; font-family: 'DM Mono', monospace; font-size: 0.73rem; }
  .debug-table th { text-align: left; color: var(--muted); font-weight: 400; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.08em; padding: 6px 8px; border-bottom: 1px solid var(--border); }
  .debug-table td { padding: 7px 8px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  .debug-table tr:last-child td { border-bottom: none; }
  .debug-table td:first-child { color: var(--muted); }
  .debug-table td:nth-child(2) { color: var(--text); }
  .ok   { color: var(--green); }
  .warn { color: var(--yellow); }
  .fail { color: var(--red); }
  .mono { font-family: 'DM Mono', monospace; }

  .ttl-bar-wrap { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
  .ttl-bar { height: 3px; flex: 1; background: var(--surface2); border-radius: 2px; overflow: hidden; }
  .ttl-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--gold-dim), var(--gold)); transition: width 0.5s; }
  .ttl-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; color: var(--muted); white-space: nowrap; }

  /* ── Divider ─────────────────────────────────── */
  .section-title {
    font-size: 0.68rem; font-weight: 500; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--muted2);
    margin: 20px 0 8px; display: flex; align-items: center; gap: 10px;
  }
  .section-title::after { content: ''; flex: 1; height: 1px; background: var(--border); }

  /* ── Responsive ──────────────────────────────── */
  @media (max-width: 700px) {
    .grid-hero { grid-template-columns: 1fr; }
    .hero-price { grid-column: 1; }
    .grid-3 { grid-template-columns: 1fr 1fr; }
    .grid-2 { grid-template-columns: 1fr; }
    .ohlc   { grid-template-columns: repeat(2,1fr); }
    .ohlc-item:nth-child(2) { border-right: none; }
    .ohlc-item:nth-child(3) { border-right: 1px solid var(--border); border-top: 1px solid var(--border); }
    .ohlc-item:nth-child(4) { border-right: none; border-top: 1px solid var(--border); }
  }
  @media (max-width: 420px) {
    .grid-3 { grid-template-columns: 1fr; }
    .price-main { font-size: 2rem; }
  }

  /* ── Animations ──────────────────────────────── */
  .fade-in { animation: fadeIn 0.5s ease both; }
  @keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }
  .card:nth-child(1){animation-delay:0.05s}
  .card:nth-child(2){animation-delay:0.1s}
  .card:nth-child(3){animation-delay:0.15s}
</style>
</head>
<body>
<div class="wrap">

  <!-- Header -->
  <div class="hdr fade-in">
    <div class="hdr-left">
      <h1>Gold Monitor</h1>
      <p>UAE Retail · 24K · AED/gram</p>
    </div>
    <div class="hdr-right">
      <div class="last-run"><span class="live-dot"></span><?= htmlspecialchars($last_run_str) ?> · <?= htmlspecialchars($age_str) ?></div>
      <div style="font-size:0.68rem;color:var(--muted2);margin-top:3px;"><?= date('l, d M Y · H:i:s') ?> UAE</div>
    </div>
  </div>

  <!-- Hero price -->
  <div class="hero-price card fade-in" style="margin-bottom:12px;">
    <div class="card-label"><span class="dot"></span>Live Gold Price</div>
    <div class="price-main">AED <?= number_format($aed, 2) ?><span style="font-size:0.45em;color:var(--gold-dim);margin-left:6px;">/g</span></div>
    <div class="price-sub">USD <?= number_format($gold_usd, 2) ?>/oz &nbsp;·&nbsp; Spot × 3.6725 / 31.1035</div>
    <div class="price-change">
      <span style="color:<?= $day_chg >= 0 ? 'var(--green)' : 'var(--red)' ?>;font-size:1.3rem;">
        <?= $chg_sign . number_format($day_chg, 2) ?>%
      </span>
      <span class="badge <?= $day_chg >= 0 ? 'badge-bull' : 'badge-bear' ?>">
        <?= $day_chg >= 0 ? '▲' : '▼' ?> Today
      </span>
      <?php if ($from_low_pct > 0): ?>
      <span class="badge badge-gold">↑ +<?= number_format($from_low_pct, 2) ?>% from low</span>
      <?php endif; ?>
      <?php if ($consec > 0): ?>
      <span class="badge badge-bull"><?= $consec ?>↑ streak</span>
      <?php endif; ?>
      <?php if ($day_low_hit): ?>
      <span class="badge badge-bear">⚠ Day low reached</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- OHLC -->
  <div class="card fade-in" style="margin-bottom:12px;padding:0;overflow:hidden;">
    <div style="padding:14px 16px 0;"><div class="card-label"><span class="dot"></span>Day Range (UAE Session)</div></div>
    <div class="ohlc">
      <div class="ohlc-item"><div class="ohlc-lbl">Open</div><div class="ohlc-val" style="color:var(--muted);">AED <?= number_format($open_aed, 2) ?></div></div>
      <div class="ohlc-item"><div class="ohlc-lbl">High</div><div class="ohlc-val" style="color:var(--green);">AED <?= number_format($high_aed, 2) ?></div></div>
      <div class="ohlc-item"><div class="ohlc-lbl">Low</div><div class="ohlc-val" style="color:var(--red);">AED <?= number_format($low_aed, 2) ?></div></div>
      <div class="ohlc-item"><div class="ohlc-lbl">Range</div><div class="ohlc-val"><?= number_format($range_pct, 2) ?>%</div></div>
    </div>
    <?php
      $pos = $day_high > $day_low ? min(100, max(0, pct_change($day_low, $gold_usd) / max(0.001, $range_pct) * 100)) : 50;
    ?>
    <div class="range-bar-wrap">
      <div class="range-labels"><span>Low <?= number_format($low_aed, 2) ?></span><span>High <?= number_format($high_aed, 2) ?></span></div>
      <div class="range-bar-track">
        <div class="range-bar-fill" style="width:100%;"></div>
        <div class="range-bar-cursor" style="left:<?= $pos ?>%;"></div>
      </div>
    </div>
  </div>

  <!-- 3 stat cards -->
  <div class="grid-3">
    <div class="card fade-in">
      <div class="card-label"><span class="dot"></span>DXY Dollar Index</div>
      <div class="stat-val" style="color:<?= $dxy_color ?>;"><?= $dxy_val ?></div>
      <div class="stat-sub"><?= htmlspecialchars($dxy_lbl) ?></div>
      <?php if ($dxy !== null && $dxy < DXY_WEAK_LEVEL): ?>
        <div style="margin-top:8px;"><span class="badge badge-bull">Below 100 · Gold +ve</span></div>
      <?php endif; ?>
    </div>

    <div class="card fade-in">
      <div class="card-label"><span class="dot"></span>TIP ETF (Real Yields)</div>
      <div class="stat-val">$<?= $tip_val ?></div>
      <div class="stat-sub">TIPS bond ETF proxy</div>
    </div>

    <div class="card fade-in">
      <div class="card-label"><span class="dot"></span>GLD Volume</div>
      <div class="stat-val"><?= $gld_vol !== null ? number_format($gld_vol) : '—' ?></div>
      <div class="stat-sub">
        Avg: <?= $gld_avg !== null ? number_format(round($gld_avg)) : '—' ?>
        <?php if ($vol_mult !== null): ?>
          &nbsp;·&nbsp;
          <span style="color:<?= $vol_mult >= 2 ? 'var(--green)' : 'var(--muted)' ?>;">
            <?= number_format($vol_mult, 1) ?>×
          </span>
        <?php endif; ?>
      </div>
      <?php if ($vol_mult !== null && $vol_mult >= 2): ?>
        <div style="margin-top:8px;"><span class="badge badge-bull">Institutional spike</span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Signals + Alert log -->
  <div class="grid-2">
    <!-- Signal cooldowns -->
    <div class="card fade-in">
      <div class="card-label"><span class="dot"></span>Signal Status</div>
      <div class="signals-grid">
        <?php foreach ($signal_labels as $key => [$icon, $name, $type]): ?>
          <?php
            $cd_mins = $cooldowns[$key] ?? 60;
            $last_ts = $last_alerts[$key] ?? 0;
            $elapsed = $now - $last_ts;
            $remaining = max(0, $cd_mins * 60 - $elapsed);
            $ready = ($remaining === 0);
            $chip_class = $ready ? 'ready' : 'cooling';
            $dot_class  = $ready ? ($type === 'bull' ? 'dot-ready' : 'dot-bear') : 'dot-cooling';
            $cd_str = $ready ? 'Ready' : (floor($remaining/60) . 'm ' . ($remaining%60) . 's');
          ?>
          <div class="sig-chip <?= $chip_class ?>">
            <div class="sig-top">
              <span class="sig-icon"><?= $icon ?></span>
              <span class="sig-status-dot <?= $dot_class ?>"></span>
            </div>
            <div class="sig-name"><?= htmlspecialchars($name) ?></div>
            <div class="sig-cd"><?= $cd_str ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Alert log -->
    <div class="card fade-in">
      <div class="card-label">
        <span class="dot"></span>Today's Alerts
        <?php if ($signals_today > 0): ?>
          <span class="badge badge-gold" style="margin-left:auto;"><?= $signals_today ?> fired</span>
        <?php endif; ?>
      </div>
      <div class="alert-list">
        <?php if (empty($alert_log)): ?>
          <div class="empty-msg">No alerts fired today</div>
        <?php else: ?>
          <?php foreach (array_slice($alert_log, 0, 20) as $al): ?>
            <?php
              $k    = $al['key'] ?? '';
              $info = $signal_labels[$k] ?? ['•', $k, 'bull'];
              $bc   = $info[2] === 'bear' ? 'badge-bear' : 'badge-bull';
            ?>
            <div class="alert-row">
              <span class="alert-time"><?= htmlspecialchars($al['time'] ?? '—') ?></span>
              <span class="alert-type"><?= $info[0] ?></span>
              <span class="alert-key"><?= htmlspecialchars($info[1]) ?></span>
              <span class="badge <?= $bc ?>"><?= $info[2] ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Debug section -->
  <div class="section-title">Debug &amp; System</div>
  <details>
    <summary>API Diagnostics &amp; Backend Metrics</summary>
    <div class="debug-body">

      <!-- Crumb TTL bar -->
      <?php if ($crumb_age !== null): ?>
        <?php $crumb_pct = min(100, round($crumb_age / 55 * 100)); $crumb_remaining = max(0, 55 - $crumb_age); ?>
        <div style="margin-bottom:16px;">
          <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:var(--muted);margin-bottom:4px;">
            <span>Yahoo Crumb TTL</span>
            <span class="mono" style="color:<?= $crumb_remaining < 10 ? 'var(--red)' : 'var(--green)' ?>;">
              <?= $crumb_remaining ?>m remaining (<?= $crumb_age ?>m used / 55m)
            </span>
          </div>
          <div class="ttl-bar">
            <div class="ttl-fill" style="width:<?= 100 - $crumb_pct ?>%;"></div>
          </div>
        </div>
      <?php endif; ?>

      <table class="debug-table">
        <thead><tr><th>Source</th><th>Status</th><th>Endpoint</th><th>Latency</th><th>Last value</th></tr></thead>
        <tbody>
          <tr>
            <td>igold.ae</td>
            <td class="<?= $aed > 0 ? 'ok' : 'fail' ?>"><?= $aed > 0 ? 'OK' : 'FAIL' ?></td>
            <td>HTML scrape</td>
            <td><?= $igold_ms !== null ? $igold_ms . ' ms' : '—' ?></td>
            <td>AED <?= number_format($aed, 2) ?></td>
          </tr>
          <?php foreach (['GC=F' => 'Gold futures', 'DX-Y.NYB' => 'DXY Index', 'TIP' => 'TIP ETF', 'GLD' => 'GLD ETF'] as $ticker => $label): ?>
            <?php $m = $yf_meta[$ticker] ?? null; ?>
            <tr>
              <td><?= $label ?> <span style="color:var(--muted2);">(<?= $ticker ?>)</span></td>
              <td class="<?= $m && $m['status']==='OK' ? 'ok' : 'fail' ?>"><?= $m ? $m['status'] : 'NO DATA' ?></td>
              <td><?= $m ? htmlspecialchars($m['endpoint']) : '—' ?></td>
              <td><?= $m ? $m['ms'] . ' ms' : '—' ?></td>
              <td>
                <?php if ($ticker === 'GC=F') echo '$' . number_format($gold_usd, 2); ?>
                <?php if ($ticker === 'DX-Y.NYB') echo $dxy !== null ? number_format($dxy, 3) : '—'; ?>
                <?php if ($ticker === 'TIP') echo $tip !== null ? '$' . number_format($tip, 2) : '—'; ?>
                <?php if ($ticker === 'GLD') echo $gld_vol !== null ? number_format($gld_vol) . ' vol' : '—'; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="margin-top:16px;"></div>
      <table class="debug-table">
        <thead><tr><th>State key</th><th>Value</th></tr></thead>
        <tbody>
          <tr><td>Last cron run</td><td><?= $last_run_str ?> (<?= $age_str ?>)</td></tr>
          <tr><td>Crumb age</td><td class="<?= $crumb_age !== null && $crumb_age < 50 ? 'ok' : 'warn' ?>"><?= $crumb_age !== null ? $crumb_age . ' min' : '—' ?></td></tr>
          <tr><td>Cookie file</td><td class="<?= file_exists(COOKIE_FILE) ? 'ok' : 'warn' ?>"><?= file_exists(COOKIE_FILE) ? 'Present (' . round(filesize(COOKIE_FILE)/1024,1) . ' KB)' : 'Missing' ?></td></tr>
          <tr><td>State file</td><td class="<?= file_exists(STATE_FILE) ? 'ok' : 'fail' ?>"><?= file_exists(STATE_FILE) ? 'Present (' . round(filesize(STATE_FILE)/1024,1) . ' KB)' : 'Missing' ?></td></tr>
          <tr><td>Log file</td><td><?= file_exists(LOG_FILE) ? round(filesize(LOG_FILE)/1024,1) . ' KB' : 'Missing' ?></td></tr>
          <tr><td>Day open (USD)</td><td>$<?= number_format($day_open, 2) ?></td></tr>
          <tr><td>Day low (USD)</td><td>$<?= number_format($day_low, 2) ?> <?= $day_low_hit ? '<span class="badge badge-bear" style="font-size:0.6rem;">reached</span>' : '' ?></td></tr>
          <tr><td>Day high (USD)</td><td>$<?= number_format($day_high, 2) ?></td></tr>
          <tr><td>day_low_prev (USD)</td><td>$<?= number_format($low_prev, 2) ?></td></tr>
          <tr><td>Consec up</td><td><?= $consec ?></td></tr>
          <tr><td>Signals today</td><td><?= $signals_today ?></td></tr>
          <tr><td>GLD vol avg (20-run)</td><td><?= $gld_avg !== null ? number_format(round($gld_avg)) : '—' ?></td></tr>
          <tr><td>Price history points</td><td><?= isset($state['gold_price_hist']) ? count($state['gold_price_hist']) : 0 ?></td></tr>
          <tr><td>PHP version</td><td><?= PHP_VERSION ?></td></tr>
          <tr><td>Server time</td><td><?= date('Y-m-d H:i:s T') ?></td></tr>
          <tr><td>UAE time</td><td><?= date('Y-m-d H:i:s') ?> (UTC+4)</td></tr>
        </tbody>
      </table>

      <!-- Cooldown table -->
      <div style="margin-top:16px;"></div>
      <table class="debug-table">
        <thead><tr><th>Signal</th><th>Cooldown</th><th>Last fired</th><th>Remaining</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($cooldowns as $key => $cd_mins): ?>
            <?php
              $last_ts   = $last_alerts[$key] ?? 0;
              $elapsed   = $now - $last_ts;
              $remaining = max(0, $cd_mins * 60 - $elapsed);
              $ready     = ($remaining === 0);
              $info      = $signal_labels[$key] ?? ['•', $key, 'bull'];
            ?>
            <tr>
              <td><?= $info[0] ?> <?= htmlspecialchars($info[1]) ?></td>
              <td><?= $cd_mins ?>m</td>
              <td><?= $last_ts > 0 ? date('H:i:s', $last_ts) : 'never' ?></td>
              <td class="mono"><?= $remaining > 0 ? floor($remaining/60) . 'm ' . ($remaining%60) . 's' : '—' ?></td>
              <td class="<?= $ready ? 'ok' : 'warn' ?>"><?= $ready ? 'READY' : 'COOLING' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    </div>
  </details>

  <div style="margin-top:32px;text-align:center;font-size:0.68rem;color:var(--muted2);">
    Gold Monitor · Cron every 1 min · Data: igold.ae + Yahoo Finance · UAE (UTC+4)
  </div>
</div>

<script>
  // Auto-refresh every 60s
  setTimeout(() => location.reload(), 60000);

  // Countdown to next refresh
  let secs = 60;
  const hdr = document.querySelector('.last-run');
  if (hdr) {
    setInterval(() => {
      secs--;
      if (secs <= 0) secs = 60;
      const existing = hdr.querySelector('.refresh-cd');
      if (!existing) {
        const span = document.createElement('span');
        span.className = 'refresh-cd';
        span.style.cssText = 'margin-left:10px;font-size:0.65rem;color:var(--muted2);';
        hdr.appendChild(span);
      }
      hdr.querySelector('.refresh-cd').textContent = `· refresh in ${secs}s`;
    }, 1000);
  }
</script>
</body>
</html>
<?php
    echo ob_get_clean();
}
