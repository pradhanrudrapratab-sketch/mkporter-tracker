<!-- DASHBOARD -->
<div class="page-header">
    <div>
        <div class="page-title">Live <span>Dashboard</span></div>
        <div class="page-subtitle">Active Porter rides — polling every 30 seconds</div>
    </div>
</div>

<!-- ADD RIDE FORM -->
<div class="add-ride-form">
    <div class="form-group">
        <label>Add Porter Tracking URL</label>
        <input type="url" id="tracking-url" placeholder="https://porter.in/track_live_order_v2?booking_id=CRN...">
    </div>
    <button class="btn btn-primary" id="add-ride-btn" onclick="addRide()">
        ＋ Track Ride
    </button>
</div>

<!-- STATS -->
<div class="stat-grid" id="stats-grid">
    <div class="stat-card"><div class="value" id="stat-active">—</div><div class="label">Active Rides</div></div>
    <div class="stat-card"><div class="value" id="stat-total">—</div><div class="label">Total Rides</div></div>
    <div class="stat-card"><div class="value" id="stat-completed">—</div><div class="label">Completed</div></div>
    <div class="stat-card"><div class="value" id="stat-gps">—</div><div class="label">GPS Samples</div></div>
</div>

<!-- ACTIVE RIDES -->
<div class="section-header">
    <div class="section-title">🟢 Active Rides <span class="section-count" id="active-count">0</span></div>
</div>
<div class="ride-grid" id="active-rides"></div>

<div style="margin-top:32px;">
    <div class="section-header">
        <div class="section-title">✅ Completed Rides <span class="section-count" id="completed-count">0</span></div>
    </div>
    <div class="ride-grid" id="completed-rides"></div>
</div>

<template id="tpl-active-ride">
    <div class="ride-card" data-ride-id="">
        <div class="ride-card-header">
            <div>
                <div class="ride-crn"></div>
                <div style="margin-top:4px;"></div>
            </div>
            <div class="badge badge-live"><div class="pulse"></div> LIVE</div>
        </div>
        <div class="ride-meta">
            <div class="meta-item"><div class="meta-label">Vehicle</div><div class="meta-value vehicle"></div></div>
            <div class="meta-item"><div class="meta-label">Vehicle No.</div><div class="meta-value veh-num"></div></div>
            <div class="meta-item"><div class="meta-label">Pickup</div><div class="meta-value pickup-loc"></div></div>
            <div class="meta-item"><div class="meta-label">Drop</div><div class="meta-value drop-loc"></div></div>
            <div class="meta-item"><div class="meta-label">Current Location</div><div class="meta-value curr-loc"></div></div>
            <div class="meta-item"><div class="meta-label">Last Update</div><div class="meta-value last-update"></div></div>
        </div>
        <div class="tracking-progress">
            <div class="progress-wrap"><div class="progress-bar" style="width:0%"></div></div>
            <div class="progress-label">
                <span class="progress-text">Tracking activity</span>
                <span class="sample-count">0 samples</span>
            </div>
        </div>
        <div class="action-row">
            <a class="btn btn-secondary btn-sm view-btn" href="#">📍 View Map</a>
            <button class="btn btn-secondary btn-sm stop-btn" onclick="">⏸ Stop</button>
            <button class="btn btn-danger btn-sm delete-btn" onclick="">🗑 Delete</button>
        </div>
    </div>
</template>

<template id="tpl-completed-ride">
    <div class="ride-card is-completed" data-ride-id="">
        <div class="ride-card-header">
            <div>
                <div class="ride-crn"></div>
                <div style="margin-top:4px;font-size:12px;color:var(--text-muted);" class="completed-at"></div>
            </div>
            <div></div>
        </div>
        <div class="ride-meta">
            <div class="meta-item"><div class="meta-label">GPS Samples</div><div class="meta-value gps-count"></div></div>
            <div class="meta-item"><div class="meta-label">Report Status</div><div class="meta-value report-status-badge"></div></div>
            <div class="meta-item"><div class="meta-label">Vehicle</div><div class="meta-value vehicle"></div></div>
        </div>
        <div class="action-row">
            <a class="btn btn-secondary btn-sm view-btn" href="#">📋 Details</a>
            <button class="btn btn-secondary btn-sm retry-btn" style="display:none">🔄 Retry Report</button>
            <button class="btn btn-danger btn-sm delete-btn">🗑 Delete</button>
        </div>
    </div>
</template>

<div class="empty-state" id="empty-state" style="display:none">
    <div class="icon">📦</div>
    <p>No rides yet. Paste a Porter tracking URL above to get started.</p>
</div>

<script>
let allRides = [];
let pollInterval;

async function loadRides() {
    const res = await fetch('/api/rides');
    const data = await res.json();
    if (!data.success) return;

    allRides = data.data.rides;
    renderRides(allRides);
}

function renderRides(rides) {
    const active    = rides.filter(r => ['live', 'unknown'].includes(r.status));
    const stopped   = rides.filter(r => r.status === 'stopped');
    const completed = rides.filter(r => r.status === 'completed');
    const errored   = rides.filter(r => r.status === 'error');

    document.getElementById('stat-active').textContent    = active.length;
    document.getElementById('stat-total').textContent     = rides.length;
    document.getElementById('stat-completed').textContent = completed.length;
    document.getElementById('active-count').textContent   = active.length;
    document.getElementById('completed-count').textContent = completed.length;

    const totalGps = rides.reduce((sum, r) => sum + (parseInt(r.gps_count) || 0), 0);
    document.getElementById('stat-gps').textContent = totalGps;

    // Render active + stopped rides
    const activeEl = document.getElementById('active-rides');
    activeEl.innerHTML = '';
    [...active, ...stopped, ...errored].forEach(ride => {
        activeEl.appendChild(buildActiveCard(ride));
    });

    // Render completed
    const completedEl = document.getElementById('completed-rides');
    completedEl.innerHTML = '';
    completed.forEach(ride => {
        completedEl.appendChild(buildCompletedCard(ride));
    });

    document.getElementById('empty-state').style.display = rides.length === 0 ? 'block' : 'none';
}

function buildActiveCard(ride) {
    const tpl  = document.getElementById('tpl-active-ride');
    const el   = tpl.content.cloneNode(true).querySelector('.ride-card');

    el.dataset.rideId = ride.id;
    el.classList.add(`is-${ride.status}`);

    // CRN / booking ID
    el.querySelector('.ride-crn').textContent = ride.crn || ride.booking_id || `Ride #${ride.id}`;

    // Badge
    const badge = el.querySelector('.badge');
    badge.className = 'badge badge-' + (ride.status === 'live' ? 'live' : ride.status === 'stopped' ? 'stopped' : ride.status === 'error' ? 'error' : 'unknown');
    badge.innerHTML = (ride.status === 'live' ? '<div class="pulse"></div> LIVE' :
                       ride.status === 'stopped' ? '⏸ STOPPED' :
                       ride.status === 'error' ? '❌ ERROR' : '⏳ POLLING');

    el.querySelector('.vehicle').textContent    = ride.vehicle_type || '—';
    el.querySelector('.veh-num').textContent    = ride.vehicle_number || '—';
    el.querySelector('.pickup-loc').textContent  = (ride.pickup_landmark || '').substring(0, 40) || '—';
    el.querySelector('.drop-loc').textContent    = (ride.drop_landmark || '').substring(0, 40) || '—';

    const lat = ride.last_partner_lat, lng = ride.last_partner_lng;
    el.querySelector('.curr-loc').textContent = (lat && lng) ? `${parseFloat(lat).toFixed(5)}, ${parseFloat(lng).toFixed(5)}` : '—';
    el.querySelector('.last-update').textContent = ride.last_polled_at ? timeAgo(ride.last_polled_at) : 'Pending first poll';

    // Progress
    const samples  = parseInt(ride.successful_poll_count) || 0;
    const progress = Math.min(samples * 5, 100);
    el.querySelector('.progress-bar').style.width = progress + '%';
    el.querySelector('.sample-count').textContent = (parseInt(ride.gps_count) || 0) + ' GPS samples';

    // Errors
    if (ride.last_error) {
        const sub = el.querySelector('.ride-card-header > div > div:last-child');
        sub.innerHTML = `<span style="color:var(--error-red);font-size:11px;">⚠ ${ride.last_error}</span>`;
    }

    // Actions
    el.querySelector('.view-btn').href = `/ride/${ride.id}`;

    if (ride.status === 'stopped') {
        const stopBtn = el.querySelector('.stop-btn');
        stopBtn.textContent = '▶ Resume';
        stopBtn.className = 'btn btn-success btn-sm';
        stopBtn.onclick = () => resumeRide(ride.id);
    } else {
        el.querySelector('.stop-btn').onclick = () => stopRide(ride.id);
    }

    el.querySelector('.delete-btn').onclick = () => deleteRide(ride.id);

    return el;
}

function buildCompletedCard(ride) {
    const tpl = document.getElementById('tpl-completed-ride');
    const el  = tpl.content.cloneNode(true).querySelector('.ride-card');

    el.dataset.rideId = ride.id;
    el.querySelector('.ride-crn').textContent = ride.crn || ride.booking_id || `Ride #${ride.id}`;
    el.querySelector('.completed-at').textContent = 'Completed ' + formatDate(ride.completed_at);
    el.querySelector('.gps-count').textContent = (ride.gps_count || 0) + ' points';
    el.querySelector('.vehicle').textContent = ride.vehicle_type || '—';

    const rs = ride.report_status || '—';
    const rsBadge = el.querySelector('.report-status-badge');
    rsBadge.innerHTML = `<span class="badge badge-${rs}">${rs.toUpperCase()}</span>`;

    el.querySelector('.view-btn').href = `/ride/${ride.id}`;

    if (rs === 'failed') {
        const retryBtn = el.querySelector('.retry-btn');
        retryBtn.style.display = 'inline-flex';
        retryBtn.onclick = () => retryReport(ride.id);
    }

    el.querySelector('.delete-btn').onclick = () => deleteRide(ride.id);

    return el;
}

async function addRide() {
    const url = document.getElementById('tracking-url').value.trim();
    if (!url) { toast('Please paste a Porter tracking URL', 'error'); return; }

    const btn = document.getElementById('add-ride-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';

    const res = await api('POST', '/api/rides', { tracking_url: url });

    if (res.success) {
        toast('✅ ' + res.data.message, 'success');
        document.getElementById('tracking-url').value = '';
        loadRides();
    } else {
        toast('❌ ' + (res.error?.message || 'Failed to add ride'), 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '＋ Track Ride';
}

async function stopRide(id) {
    const res = await api('POST', `/api/rides/${id}/stop`);
    if (res.success) { toast('⏸ Ride tracking stopped', 'info'); loadRides(); }
    else toast('❌ ' + res.error?.message, 'error');
}

async function resumeRide(id) {
    const res = await api('POST', `/api/rides/${id}/resume`);
    if (res.success) { toast('▶ Ride tracking resumed', 'success'); loadRides(); }
    else toast('❌ ' + res.error?.message, 'error');
}

async function deleteRide(id) {
    if (!window.confirm('Delete this ride and all its GPS data?')) return;
    const res = await api('DELETE', `/api/rides/${id}`);
    if (res.success) { toast('🗑 Ride deleted', 'info'); loadRides(); }
    else toast('❌ ' + res.error?.message, 'error');
}

async function retryReport(id) {
    const res = await api('POST', `/api/rides/${id}/retry-report`);
    if (res.success) { toast('🔄 Report queued for retry', 'info'); loadRides(); }
    else toast('❌ ' + res.error?.message, 'error');
}

// Allow Enter key in URL field
document.getElementById('tracking-url').addEventListener('keydown', e => {
    if (e.key === 'Enter') addRide();
});

// Initial load + auto-refresh
loadRides();
pollInterval = setInterval(loadRides, 8000);
</script>
