<!-- CHANGE PASSWORD -->
<div class="page-header">
    <div>
        <div class="page-title">Change <span>Password</span></div>
        <div class="page-subtitle">Your current password is required to set a new one</div>
    </div>
</div>

<div style="max-width: 440px;">
    <div class="card">
        <div id="msg" style="margin-bottom:16px;display:none;"></div>

        <form id="pw-form">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" placeholder="Your existing password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" placeholder="Minimum 8 characters" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group" style="margin-bottom:24px;">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_new_password" placeholder="Re-enter new password" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary" id="pw-btn">🔑 Change Password</button>
        </form>
    </div>

    <div class="alert alert-warning" style="margin-top:16px;">
        ⚠ There is no email-based password recovery. If you forget your password, the account cannot be recovered.
    </div>
</div>

<script>
document.getElementById('pw-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('pw-btn');
    const msg = document.getElementById('msg');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    msg.style.display = 'none';

    const fd = new FormData(e.target);
    fd.append('_csrf', CSRF_TOKEN);

    const res  = await fetch('/api/password/change', {
        method: 'POST',
        body: new URLSearchParams(fd),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    });
    const data = await res.json();

    if (data.success) {
        msg.className = 'alert alert-success';
        msg.textContent = '✅ Password changed successfully.';
        msg.style.display = 'flex';
        e.target.reset();
        toast('✅ Password updated', 'success');
    } else {
        msg.className = 'alert alert-error';
        msg.textContent = '❌ ' + (data.error?.message || 'Failed to change password.');
        msg.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '🔑 Change Password';
});
</script>
