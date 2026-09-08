<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-logo">
            <div class="brand-icon" style="width:48px;height:48px;font-size:24px;margin:0 auto 12px;">🚚</div>
            <h1>Porter <span style="color:var(--orange)">Tracker</span></h1>
            <p>Create your free account</p>
        </div>

        <div class="card">
            <div id="error-alert" class="alert alert-error" style="display:none"></div>

            <form id="register-form">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="3–32 characters, letters/numbers/_ only" required minlength="3" maxlength="32">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Minimum 8 characters" required minlength="8">
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="Re-enter your password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full" id="reg-btn">Create Account</button>
            </form>

            <div style="text-align:center;margin-top:18px;font-size:13px;color:var(--text-muted);">
                Have an account? <a href="/login" style="color:var(--orange);text-decoration:none;">Sign in</a>
            </div>
        </div>

        <p style="text-align:center;margin-top:16px;font-size:11px;color:var(--text-dim);">
            Accounts inactive for 30 days are automatically removed.
        </p>
    </div>
</div>

<style>
body { background: radial-gradient(ellipse at top, #1a0d00 0%, var(--black) 60%); }
</style>

<script>
document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('reg-btn');
    const err = document.getElementById('error-alert');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    err.style.display = 'none';

    const fd = new FormData(e.target);
    fd.append('_csrf', CSRF_TOKEN);

    const res  = await fetch('/api/auth/register', {
        method: 'POST',
        body: new URLSearchParams(fd),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });
    const data = await res.json();

    if (data.success) {
        window.location.href = data.redirect || '/dashboard';
    } else {
        err.textContent = data.error?.message || 'Registration failed.';
        err.style.display = 'flex';
        btn.disabled = false;
        btn.textContent = 'Create Account';
    }
});
</script>
