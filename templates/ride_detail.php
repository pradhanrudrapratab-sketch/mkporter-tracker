<div class="page-header">
    <div>
        <a href="/dashboard" style="color:var(--text-muted);text-decoration:none;font-size:13px;">← Dashboard</a>
        <div class="page-title" style="margin-top:4px;">Ride <span>Details</span></div>
    </div>
    <div id="status-badge"></div>
</div>

<!-- SUMMARY STATS -->
<div class="stat-grid" style="margin-bottom:24px;" id="ride-stats">
    <div class="stat-card"><div class="value" id="s-samples">—</div><div class="label">GPS Samples</div></div>
    <div class="stat-card"><div class="value" id="s-polls">—</div><div class="label">Poll Cycles</div></div>
    <div class="stat-card"><div class="value" id="s-errors">—</div><div class="label">Errors</div></div>
    <div class="stat-card"><div class="value" id="s-duration">—</div><div class="label">Duration</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;" id="detail-grid">
    <!-- RIDE INFO -->
    <div class="card" id="info-card">
        <div style="font-weight:600;margin-bottom:14px;font-size:14px;">📋 Ride Information</div>
        <div id="info-body" style="display:grid;gap:10px;"></div>
    </div>

    <!-- ROUTE -->
    <div class="card" id="route-card">
        <div style="font-weight:600;margin-bottom:14px;font-size:14px;">🗺 Route</div>
        <div class="route-steps" id="route-steps"></div>
    </div>
</div>

<!-- MAP -->
<div class="card" style="margin-bottom:24px;">
    <div style="font-weight:600;margin-bottom:14px;font-size:14px;">📍 Live Map</div>
    <div class="map-container" id="map"></div>
</div>

<!-- LOCATION HISTORY -->
<div class="card">
    <div style="font-weight:600;margin-bottom:14px;font-size:14px;">📊 Location History</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Time (IST)</th>
                    <th>Latitude</th>
                    <th>Longitude</th>
                </tr>
            </thead>
            <tbody id="location-table"></tbody>
        </table>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const RIDE_ID = <?= (int)($rideId ?? 0) ?>;
let map, partnerMarker, trackPolyline;

async function loadRideDetail() {
    const res  = await fetch(`/api/rides/${RIDE_ID}`);
    const data = await res.json();

    if (!data.success) {
        toast('Failed to load ride', 'error');
        return;
    }

    const ride = data.data;

    // Status badge
    document.getElementById('status-badge').innerHTML =
        `<span class="badge badge-${ride.status}">${ride.status.toUpperCase()}</span>`;

    // Stats
    document.getElementById('s-samples').textContent = ride.gps_count || 0;
    document.getElementById('s-polls').textContent   = ride.poll_count || 0;
    document.getElementById('s-errors').textContent  = ride.error_count || 0;

    if (ride.accepted_at && ride.completed_at) {
        const diff = Math.floor((new Date(ride.completed_at) - new Date(ride.accepted_at)) / 1000);
        const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
        document.getElementById('s-duration').textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
    }

    // Info
    const fields = [
        ['CRN',          ride.crn || '—'],
        ['Booking ID',   ride.booking_id],
        ['Driver',       ride.partner_name || '—'],
        ['Mobile',       ride.partner_mobile || '—'],
        ['Vehicle',      ride.vehicle_type || '—'],
        ['Vehicle No.',  ride.vehicle_number || '—'],
        ['Accepted',     formatDate(ride.accepted_at)],
        ['Completed',    formatDate(ride.completed_at)],
        ['Next Poll',    ride.next_poll_at ? formatDate(ride.next_poll_at) : 'N/A'],
        ['Last Error',   ride.last_error || '—'],
        ['Report',       ride.report_status || '—'],
    ];

    document.getElementById('info-body').innerHTML = fields.map(([k, v]) => `
        <div style="display:flex;justify-content:space-between;gap:12px;font-size:13px;">
            <span style="color:var(--text-dim)">${k}</span>
            <span style="color:var(--text);text-align:right;word-break:break-all">${v}</span>
        </div>
    `).join('');

    // Route
    const stepsEl = document.getElementById('route-steps');
    stepsEl.innerHTML = '';

    function addRouteStep(type, text, last = false) {
        const cls = { pickup: 'pickup', waypoint: 'waypoint', drop: 'drop' }[type];
        const label = type.charAt(0).toUpperCase() + type.slice(1);
        stepsEl.innerHTML += `
            <div class="route-step">
                <div class="route-step-connector">
                    <div class="route-dot ${cls}"></div>
                    ${!last ? '<div class="route-line"></div>' : ''}
                </div>
                <div class="route-step-text">
                    <div class="route-step-type">${label}</div>
                    <div>${text || '—'}</div>
                </div>
            </div>`;
    }

    addRouteStep('pickup', ride.pickup_landmark, false);
    (ride.waypoints || []).forEach(wp => addRouteStep('waypoint', wp.landmark, false));
    addRouteStep('drop', ride.drop_landmark, true);

    // Location table
    const tbody = document.getElementById('location-table');
    tbody.innerHTML = (ride.locations || []).map(l => `
        <tr>
            <td>${l.sequence}</td>
            <td style="color:var(--text-muted);font-family:inherit;">${formatDate(l.recorded_at)}</td>
            <td>${parseFloat(l.latitude).toFixed(6)}</td>
            <td>${parseFloat(l.longitude).toFixed(6)}</td>
        </tr>
    `).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--text-dim)">No GPS data yet</td></tr>';

    // Map
    buildMap(ride);
}

function buildMap(ride) {
    if (!map) {
        map = L.map('map', { zoomControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);
    } else {
        map.eachLayer(l => { if (!(l instanceof L.TileLayer)) map.removeLayer(l); });
    }

    const bounds = [];
    const locs   = (ride.locations || []).filter(l => l.latitude && l.longitude);

    // Pickup
    if (ride.pickup_lat && ride.pickup_lng) {
        const icon = L.divIcon({ className: '', html: '<div style="width:14px;height:14px;background:#00D46A;border:2px solid #fff;border-radius:50%;"></div>' });
        L.marker([ride.pickup_lat, ride.pickup_lng], { icon }).addTo(map)
            .bindPopup('📦 <b>Pickup</b><br>' + (ride.pickup_landmark || ''));
        bounds.push([ride.pickup_lat, ride.pickup_lng]);
    }

    // Drop
    if (ride.drop_lat && ride.drop_lng) {
        const icon = L.divIcon({ className: '', html: '<div style="width:14px;height:14px;background:#FF4444;border:2px solid #fff;border-radius:50%;"></div>' });
        L.marker([ride.drop_lat, ride.drop_lng], { icon }).addTo(map)
            .bindPopup('🏁 <b>Drop</b><br>' + (ride.drop_landmark || ''));
        bounds.push([ride.drop_lat, ride.drop_lng]);
    }

    // Waypoints
    (ride.waypoints || []).forEach((wp, i) => {
        if (wp.latitude && wp.longitude) {
            const icon = L.divIcon({ className: '', html: `<div style="width:12px;height:12px;background:#FFB800;border:2px solid #fff;border-radius:50%;"></div>` });
            L.marker([wp.latitude, wp.longitude], { icon }).addTo(map)
                .bindPopup(`📍 <b>Waypoint ${i+1}</b><br>${wp.landmark || ''}`);
            bounds.push([wp.latitude, wp.longitude]);
        }
    });

    // GPS Track
    if (locs.length > 0) {
        const pts = locs.map(l => [l.latitude, l.longitude]);
        trackPolyline = L.polyline(pts, { color: '#FF6B00', weight: 3, opacity: 0.8 }).addTo(map);
        pts.forEach(p => bounds.push(p));

        // Live partner marker (last point)
        const last = pts[pts.length - 1];
        const icon = L.divIcon({ className: '', html: `
            <div style="position:relative;">
                <div style="width:18px;height:18px;background:#FF6B00;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 4px rgba(255,107,0,0.3);"></div>
            </div>` });
        partnerMarker = L.marker(last, { icon }).addTo(map).bindPopup('🚚 <b>Last known position</b>');
    }

    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [30, 30] });
    } else {
        map.setView([20.5937, 78.9629], 5);
    }
}

loadRideDetail();

// Auto-refresh if ride is live
setTimeout(async () => {
    const res  = await fetch(`/api/rides/${RIDE_ID}`);
    const data = await res.json();
    if (data.success && data.data.status === 'live') {
        setInterval(loadRideDetail, 10000);
    }
}, 500);
</script>
