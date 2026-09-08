<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Porter Live Tracker</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        /* ═══════════════════════════════════════════════════════════
           PORTER TRACKER — DESIGN TOKENS
           Orange #FF6B00 · Black #0A0A0A · White #FFFFFF
           ═══════════════════════════════════════════════════════════ */
        :root {
            --orange:       #FF6B00;
            --orange-dark:  #E05A00;
            --orange-glow:  rgba(255,107,0,0.15);
            --orange-border:rgba(255,107,0,0.3);
            --black:        #0A0A0A;
            --surface:      #141414;
            --surface-2:    #1C1C1C;
            --surface-3:    #242424;
            --border:       rgba(255,255,255,0.08);
            --border-light: rgba(255,255,255,0.14);
            --text:         #F0F0F0;
            --text-muted:   #888;
            --text-dim:     #555;
            --live-green:   #00D46A;
            --error-red:    #FF4444;
            --warn-yellow:  #FFB800;
            --info-blue:    #4DA9FF;
            --radius:       10px;
            --radius-sm:    6px;
            --radius-lg:    14px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { height: 100%; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--black);
            color: var(--text);
            min-height: 100%;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--surface); }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--orange); }

        /* ── LAYOUT ── */
        .app {
            display: grid;
            grid-template-columns: 240px 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
        }

        /* ── TOP BAR ── */
        .topbar {
            grid-column: 1 / -1;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 24px;
            height: 58px;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex: 0 0 auto;
        }

        .brand-icon {
            width: 32px;
            height: 32px;
            background: var(--orange);
            border-radius: 8px;
            display: grid;
            place-items: center;
            font-size: 16px;
        }

        .brand-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .brand-name span { color: var(--orange); }

        .topbar-spacer { flex: 1; }

        .topbar-user {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .topbar-user strong { color: var(--text); font-weight: 500; }

        .btn-logout {
            background: transparent;
            border: 1px solid var(--border-light);
            color: var(--text-muted);
            padding: 5px 12px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
        }

        .btn-logout:hover {
            border-color: var(--error-red);
            color: var(--error-red);
        }

        /* ── SIDEBAR ── */
        .sidebar {
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 20px 0;
            position: sticky;
            top: 58px;
            height: calc(100vh - 58px);
            overflow-y: auto;
        }

        .nav-section-label {
            padding: 0 20px 6px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.8px;
            color: var(--text-dim);
            text-transform: uppercase;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 20px;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 13.5px;
            font-weight: 450;
            transition: all 0.12s;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            color: var(--text);
            background: var(--surface-2);
        }

        .nav-link.active {
            color: var(--orange);
            background: var(--orange-glow);
            border-left-color: var(--orange);
        }

        .nav-icon { width: 18px; text-align: center; opacity: 0.8; font-size: 15px; }
        .nav-link.active .nav-icon { opacity: 1; }

        .nav-divider {
            height: 1px;
            background: var(--border);
            margin: 14px 20px;
        }

        /* ── MAIN CONTENT ── */
        .main {
            padding: 28px 32px;
            overflow-y: auto;
        }

        /* ── AUTH PAGES (no sidebar) ── */
        .auth-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--black);
        }

        .auth-box {
            width: 100%;
            max-width: 400px;
        }

        .auth-logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-logo .brand-icon {
            width: 48px;
            height: 48px;
            font-size: 24px;
            margin: 0 auto 12px;
        }

        .auth-logo h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.5px;
        }

        .auth-logo p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px;
        }

        /* ── FORMS ── */
        .form-group { margin-bottom: 16px; }
        .form-group:last-child { margin-bottom: 0; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 6px;
            letter-spacing: 0.2px;
        }

        input[type="text"],
        input[type="password"],
        input[type="email"],
        textarea,
        select {
            width: 100%;
            background: var(--surface-2);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-family: inherit;
            font-size: 13.5px;
            padding: 9px 12px;
            outline: none;
            transition: border-color 0.15s;
        }

        input:focus, textarea:focus, select:focus {
            border-color: var(--orange);
        }

        input::placeholder { color: var(--text-dim); }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--orange);
            color: #fff;
            border-color: var(--orange);
        }
        .btn-primary:hover { background: var(--orange-dark); border-color: var(--orange-dark); }

        .btn-secondary {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--border-light);
        }
        .btn-secondary:hover { color: var(--text); border-color: rgba(255,255,255,0.3); }

        .btn-danger {
            background: transparent;
            color: var(--error-red);
            border-color: rgba(255,68,68,0.3);
        }
        .btn-danger:hover { background: rgba(255,68,68,0.1); }

        .btn-success {
            background: transparent;
            color: var(--live-green);
            border-color: rgba(0,212,106,0.3);
        }
        .btn-success:hover { background: rgba(0,212,106,0.1); }

        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn-full { width: 100%; }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── PAGE HEADER ── */
        .page-header {
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .page-title span { color: var(--orange); }

        .page-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* ── STATUS BADGES ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .badge-live    { background: rgba(0,212,106,0.12); color: var(--live-green); border: 1px solid rgba(0,212,106,0.25); }
        .badge-completed { background: rgba(77,169,255,0.12); color: var(--info-blue); border: 1px solid rgba(77,169,255,0.25); }
        .badge-error   { background: rgba(255,68,68,0.12); color: var(--error-red); border: 1px solid rgba(255,68,68,0.25); }
        .badge-stopped { background: rgba(255,184,0,0.12); color: var(--warn-yellow); border: 1px solid rgba(255,184,0,0.25); }
        .badge-unknown { background: rgba(255,255,255,0.06); color: var(--text-muted); border: 1px solid var(--border); }
        .badge-sent    { background: rgba(77,169,255,0.12); color: var(--info-blue); border: 1px solid rgba(77,169,255,0.25); }
        .badge-pending { background: rgba(255,184,0,0.12); color: var(--warn-yellow); border: 1px solid rgba(255,184,0,0.25); }
        .badge-failed  { background: rgba(255,68,68,0.12); color: var(--error-red); border: 1px solid rgba(255,68,68,0.25); }

        .pulse {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 1.6s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: 0.4; transform: scale(0.7); }
        }

        /* ── RIDE CARD ── */
        .ride-grid {
            display: grid;
            gap: 16px;
        }

        .ride-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            transition: border-color 0.2s;
        }

        .ride-card:hover { border-color: var(--border-light); }
        .ride-card.is-live { border-left: 3px solid var(--live-green); }
        .ride-card.is-completed { border-left: 3px solid var(--info-blue); }
        .ride-card.is-error { border-left: 3px solid var(--error-red); }
        .ride-card.is-stopped { border-left: 3px solid var(--warn-yellow); }

        .ride-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            gap: 12px;
        }

        .ride-crn {
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }

        .ride-meta {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .meta-item {}

        .meta-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dim);
            margin-bottom: 2px;
        }

        .meta-value {
            font-size: 13px;
            color: var(--text);
            font-weight: 450;
        }

        /* ── PROGRESS BAR ── */
        .progress-wrap {
            background: var(--surface-3);
            border-radius: 99px;
            height: 4px;
            overflow: hidden;
            margin-bottom: 4px;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--orange), #FFa000);
            border-radius: 99px;
            transition: width 1s ease;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--text-muted);
        }

        /* ── ROUTE DISPLAY ── */
        .route-steps {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .route-step {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .route-step-connector {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 0 0 auto;
            padding-top: 2px;
        }

        .route-dot {
            width: 10px; height: 10px;
            border-radius: 50%;
            border: 2px solid currentColor;
            flex: 0 0 auto;
        }

        .route-dot.pickup  { color: var(--live-green); }
        .route-dot.waypoint { color: var(--warn-yellow); }
        .route-dot.drop    { color: var(--error-red); }

        .route-line {
            width: 2px;
            flex: 1;
            min-height: 20px;
            background: var(--border-light);
            margin: 3px 0;
        }

        .route-step-text {
            padding-bottom: 14px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .route-step-type {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dim);
        }

        /* ── TABLE ── */
        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            text-align: left;
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dim);
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 12px;
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--surface-2); }

        /* ── MAP ── */
        .map-container {
            height: 360px;
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border);
        }

        /* ── ALERTS ── */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 16px;
        }

        .alert-success { background: rgba(0,212,106,0.1); border: 1px solid rgba(0,212,106,0.25); color: var(--live-green); }
        .alert-error   { background: rgba(255,68,68,0.1); border: 1px solid rgba(255,68,68,0.25); color: var(--error-red); }
        .alert-warning { background: rgba(255,184,0,0.1); border: 1px solid rgba(255,184,0,0.25); color: var(--warn-yellow); }
        .alert-info    { background: rgba(77,169,255,0.1); border: 1px solid rgba(77,169,255,0.25); color: var(--info-blue); }

        /* ── TOAST ── */
        #toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
            pointer-events: none;
        }

        .toast-item {
            background: var(--surface-2);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 12px 16px;
            font-size: 13px;
            max-width: 320px;
            pointer-events: all;
            animation: slideIn 0.2s ease;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
        }

        .toast-item.success { border-left: 3px solid var(--live-green); color: var(--live-green); }
        .toast-item.error   { border-left: 3px solid var(--error-red); color: var(--error-red); }
        .toast-item.info    { border-left: 3px solid var(--orange); color: var(--orange); }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to   { transform: translateX(0); opacity: 1; }
        }

        /* ── ADD RIDE FORM ── */
        .add-ride-form {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .add-ride-form .form-group {
            flex: 1;
            margin: 0;
        }

        /* ── SECTION HEADER ── */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-count {
            background: var(--orange);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 7px;
            border-radius: 99px;
            min-width: 20px;
            text-align: center;
        }

        /* ── ACTION BUTTONS ROW ── */
        .action-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        /* ── STAT GRID ── */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            text-align: center;
        }

        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--orange);
            letter-spacing: -1px;
            font-variant-numeric: tabular-nums;
        }

        .stat-card .label {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-dim);
        }

        .empty-state .icon { font-size: 40px; margin-bottom: 12px; }
        .empty-state p { font-size: 14px; }
        .empty-state .hint { font-size: 12px; margin-top: 6px; }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .app { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { padding: 16px; }
            .add-ride-form { flex-direction: column; }
            .ride-meta { grid-template-columns: 1fr 1fr; }
        }

        /* ── LOADING SPINNER ── */
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid var(--border-light);
            border-top-color: var(--orange);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<?php if (in_array($page ?? '', ['login', 'register', '404'])): ?>
    <?php require $tplFile; ?>
<?php else: ?>
<div class="app">
    <!-- TOP BAR -->
    <header class="topbar">
        <a href="/dashboard" class="topbar-brand">
            <div class="brand-icon">🚚</div>
            <div class="brand-name">Porter <span>Tracker</span></div>
        </a>
        <div class="topbar-spacer"></div>
        <?php if (Auth::isLoggedIn()): ?>
        <div class="topbar-user">
            <span>Hello, <strong><?= htmlspecialchars($username ?? '') ?></strong></span>
            <form method="POST" action="/logout" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
                <button class="btn-logout" type="submit">Sign Out</button>
            </form>
        </div>
        <?php endif; ?>
    </header>

    <!-- SIDEBAR -->
    <nav class="sidebar">
        <div style="margin-bottom: 20px;">
            <div class="nav-section-label">Main</div>
            <a href="/dashboard" class="nav-link <?= ($page === 'dashboard' ? 'active' : '') ?>">
                <span class="nav-icon">📊</span> Dashboard
            </a>
        </div>
        <div style="margin-bottom: 20px;">
            <div class="nav-section-label">Settings</div>
            <a href="/settings/telegram" class="nav-link <?= ($page === 'settings_telegram' ? 'active' : '') ?>">
                <span class="nav-icon">📨</span> Telegram
            </a>
            <a href="/settings/password" class="nav-link <?= ($page === 'settings_password' ? 'active' : '') ?>">
                <span class="nav-icon">🔑</span> Change Password
            </a>
        </div>
        <div class="nav-divider"></div>
        <div style="padding: 0 20px;">
            <div style="font-size:11px; color: var(--text-dim); line-height: 1.6;">
                Porter Live Tracker<br>
                <span style="color: var(--orange);">● </span>Background worker active
            </div>
        </div>
    </nav>

    <!-- MAIN -->
    <main class="main">
        <?php require $tplFile; ?>
    </main>
</div>
<?php endif; ?>

<!-- TOAST CONTAINER -->
<div id="toast"></div>

<script>
// ── GLOBAL UTILITIES ────────────────────────────────────────────────────────

const CSRF_TOKEN = <?= json_encode($csrfToken ?? '') ?>;

function toast(msg, type = 'info', duration = 4000) {
    const el = document.createElement('div');
    el.className = `toast-item ${type}`;
    el.textContent = msg;
    document.getElementById('toast').appendChild(el);
    setTimeout(() => el.remove(), duration);
}

async function api(method, url, data = null) {
    const opts = {
        method,
        headers: { 'X-CSRF-TOKEN': CSRF_TOKEN, 'Content-Type': 'application/x-www-form-urlencoded' },
    };
    if (data) {
        const fd = new URLSearchParams(data);
        fd.append('_csrf', CSRF_TOKEN);
        opts.body = fd.toString();
    }
    const res  = await fetch(url, opts);
    const json = await res.json();
    return json;
}

async function apiJson(method, url, payload = {}) {
    const res  = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: method !== 'GET' ? JSON.stringify({ ...payload, _csrf: CSRF_TOKEN }) : undefined,
    });
    return res.json();
}

function confirm(msg) {
    return window.confirm(msg);
}

function formatDate(ts) {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', hour12: true });
}

function timeAgo(ts) {
    if (!ts) return '—';
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
    if (diff < 60)  return diff + 's ago';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    return Math.floor(diff/3600) + 'h ago';
}
</script>

</body>
</html>
