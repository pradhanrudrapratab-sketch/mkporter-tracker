<!-- TELEGRAM SETTINGS -->
<div class="page-header">
    <div>
        <div class="page-title">Telegram <span>Settings</span></div>
        <div class="page-subtitle">Configure your Telegram bot for ride completion reports</div>
    </div>
</div>

<div style="max-width: 560px;">

    <!-- HOW TO -->
    <div class="alert alert-info" style="margin-bottom:24px;">
        <div>
            <strong>How to set up:</strong><br>
            1. Create a bot via <a href="https://t.me/BotFather" target="_blank" style="color:var(--info-blue)">@BotFather</a> and copy the token.<br>
            2. Add the bot to your channel/group and get the Chat ID.<br>
            3. Paste both below and hit <strong>Save</strong>, then <strong>Test</strong>.
        </div>
    </div>

    <div class="card">
        <div id="current-status" style="margin-bottom:20px;"></div>

        <form id="tg-form">
            <div class="form-group">
                <label>Bot Token</label>
                <input type="text" name="bot_token" id="bot-token" placeholder="123456789:ABCDEFGabcdefg..." autocomplete="off">
                <div style="font-size:11px;color:var(--text-dim);margin-top:5px;">Leave blank to keep existing token.</div>
            </div>
            <div class="form-group">
                <label>Chat ID / Channel ID</label>
                <input type="text" name="chat_id" id="chat-id" placeholder="-100123456789 or 123456789">
                <div style="font-size:11px;color:var(--text-dim);margin-top:5px;">Can be negative for groups/channels.</div>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
                <input type="checkbox" name="enabled" id="tg-enabled" value="1" style="width:auto;" checked>
                <label for="tg-enabled" style="margin:0;cursor:pointer;">Enable Telegram reports</label>
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" id="save-btn">💾 Save Settings</button>
                <button type="button" class="btn btn-secondary" id="test-btn" onclick="testTelegram()">📨 Test</button>
                <button type="button" class="btn btn-danger" id="delete-btn" onclick="deleteSettings()">🗑 Remove</button>
            </div>
        </form>
    </div>

    <div class="card" style="margin-top:20px;">
        <div style="font-weight:600;margin-bottom:10px;font-size:13px;">What gets sent?</div>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.8;">
            After a Porter ride completes, the system waits <strong style="color:var(--orange)">10 minutes</strong>, then sends:<br>
            • A formatted ride summary message (CRN, driver, route, duration)<br>
            • A CSV file with all recorded GPS coordinates
        </div>
    </div>
</div>

<script>
async function loadSettings() {
    const res  = await fetch('/api/telegram/settings');
    const data = await res.json();

    if (data.success && data.data) {
        const s = data.data;
        document.getElementById('chat-id').value   = s.chat_id || '';
        document.getElementById('tg-enabled').checked = !!s.enabled;

        document.getElementById('current-status').innerHTML = `
            <div class="alert alert-success">
                ✅ Telegram configured — Token: <code>${s.token_masked || '••••••••'}</code>
            </div>`;
    } else {
        document.getElementById('current-status').innerHTML = `
            <div class="alert alert-warning">⚠ Not configured yet.</div>`;
        document.getElementById('delete-btn').disabled = true;
    }
}

document.getElementById('tg-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn  = document.getElementById('save-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    const fd = new FormData(e.target);
    fd.append('_csrf', CSRF_TOKEN);

    // Only send bot_token if user typed something
    if (!fd.get('bot_token').trim()) fd.delete('bot_token');

    const res  = await fetch('/api/telegram/settings', {
        method: 'POST',
        body: new URLSearchParams(fd),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });
    const data = await res.json();

    if (data.success) {
        toast('✅ ' + data.message, 'success');
        loadSettings();
    } else {
        toast('❌ ' + (data.error?.message || 'Save failed'), 'error');
    }
    btn.disabled = false;
    btn.innerHTML = '💾 Save Settings';
});

async function testTelegram() {
    const btn = document.getElementById('test-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    const res  = await api('POST', '/api/telegram/test');
    if (res.success) toast('✅ Test message sent!', 'success');
    else toast('❌ ' + (res.error?.message || 'Test failed'), 'error');

    btn.disabled = false;
    btn.innerHTML = '📨 Test';
}

async function deleteSettings() {
    if (!window.confirm('Remove Telegram settings? Reports will no longer be sent.')) return;
    const res = await api('DELETE', '/api/telegram/settings');
    if (res.success) { toast('🗑 Settings removed', 'info'); loadSettings(); }
    else toast('❌ ' + res.error?.message, 'error');
}

loadSettings();
</script>
