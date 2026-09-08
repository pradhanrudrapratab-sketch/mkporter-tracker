<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-logo">
            <div class="brand-icon" style="width:48px;height:48px;font-size:24px;margin:0 auto 12px;">🚚</div>
            <h1>Porter <span style="color:var(--orange)">Tracker</span></h1>
            <p>Sign in to monitor your rides</p>
        </div>

        <div class="card">
            <div id="error-alert" class="alert alert-error" style="display:none"></div>

            <form id="login-form">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="your_username" autocomplete="username" required>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full" id="login-btn">
                    Sign In
                </button>
            </form>

            <div style="text-align:center; margin-top:18px; font-size:13px; color:var(--text-muted);">
                No account? <a href="/register" style="color:var(--orange); text-decoration:none;">Create one</a>
            </div>
        </div>
    </div>
</div>

<style>
body { background: radial-gradient(ellipse at top, #1a0d00 0%, var(--black) 60%); }
</style>

<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    const err = document.getElementById('error-alert');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    err.style.display = 'none';

    const fd = new FormData(e.target);
    fd.append('_csrf', CSRF_TOKEN);

    const res = await fetch('/api/auth/login', {
        method: 'POST',
        body: new URLSearchParams(fd),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });
    const data = await res.json();

    if (data.success) {
        window.location.href = data.redirect || '/dashboard';
    } else {
        err.textContent = data.error?.message || 'Login failed.';
        err.style.display = 'flex';
        btn.disabled = false;
        btn.textContent = 'Sign In';
    }
});
</script>
