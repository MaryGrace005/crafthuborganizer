<?php
// ============================================================
//  Staff / Cashier — Analytics & Sales Reports
//  Real-time auto-refresh via JS polling every 30 seconds
// ============================================================
$pageTitle = 'Sales & Analytics Reports';
require_once __DIR__ . '/../includes/header.php';
requireRole(['staff', 'cashier', 'admin']);
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<div class="page-header">
    <div>
        <h1><i class="fa-solid fa-chart-line" style="color:var(--accent-teal);"></i> Sales & Analytics</h1>
        <p id="reportSubtitle">Loading data...</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <!-- Period Toggle -->
        <div style="display:flex;gap:6px;background:var(--bg-card);padding:4px;border-radius:12px;border:1px solid var(--border-color);">
            <button class="period-btn active" data-period="today">Today</button>
            <button class="period-btn" data-period="weekly">This Week</button>
            <button class="period-btn" data-period="monthly">This Month</button>
            <button class="period-btn" data-period="yearly">This Year</button>
        </div>
        <!-- Live indicator -->
        <div id="liveIndicator" style="display:flex;align-items:center;gap:6px;font-size:0.8rem;color:var(--text-muted);">
            <span id="liveDot" style="width:8px;height:8px;border-radius:50%;background:#27ae60;display:inline-block;animation:pulse 2s infinite;"></span>
            <span id="liveLabel">Live</span>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<style>
@keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:0.4;transform:scale(1.3);} }
.period-btn {
    padding: 6px 14px;
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: 9px;
    cursor: pointer;
    font-family: 'Outfit', sans-serif;
    transition: all 0.2s ease;
}
.period-btn.active {
    background: var(--accent-teal);
    color: #0f0f1a;
}
.stat-grid-6 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    padding: 20px;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 3px;
    background: var(--kpi-color, var(--accent-teal));
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.3); }
.kpi-icon { font-size: 1.4rem; margin-bottom: 10px; }
.kpi-value { font-size: 1.55rem; font-weight: 800; color: #fff; font-family: 'Outfit', sans-serif; }
.kpi-label { font-size: 0.78rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px; }
.kpi-sub { font-size: 0.75rem; color: var(--text-secondary); margin-top: 6px; }
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
.charts-grid-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; margin-bottom: 24px; }
.chart-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 20px; }
.chart-title { font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.chart-wrap { position: relative; }

@media (max-width: 900px) {
    .charts-grid, .charts-grid-3 { grid-template-columns: 1fr; }
}
@media print {
    .page-header button, .period-btn, #liveIndicator { display: none !important; }
    .kpi-card, .chart-card { break-inside: avoid; }
}
</style>

<!-- KPI Stats Row -->
<div class="stat-grid-6" id="kpiGrid">
    <div class="kpi-card" style="--kpi-color:#27ae60;">
        <div class="kpi-icon" style="color:#27ae60;"><i class="fa-solid fa-peso-sign"></i></div>
        <div class="kpi-value" id="kpiRevenue">—</div>
        <div class="kpi-label">Total Revenue</div>
        <div class="kpi-sub" id="kpiTodayRev"></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#4ecdc4;">
        <div class="kpi-icon" style="color:#4ecdc4;"><i class="fa-solid fa-receipt"></i></div>
        <div class="kpi-value" id="kpiTodayTx">—</div>
        <div class="kpi-label">Today's Transactions</div>
        <div class="kpi-sub">Payments processed today</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#f5a623;">
        <div class="kpi-icon" style="color:#f5a623;"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="kpi-value" id="kpiBookings">—</div>
        <div class="kpi-label">Active Bookings</div>
        <div class="kpi-sub" id="kpiCancelled"></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#e94560;">
        <div class="kpi-icon" style="color:#e94560;"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="kpi-value" id="kpiOutstanding">—</div>
        <div class="kpi-label">Outstanding Balance</div>
        <div class="kpi-sub">Total unpaid / partial</div>
    </div>
</div>

<!-- Revenue & Bookings Charts -->
<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-title"><i class="fa-solid fa-chart-line" style="color:var(--accent-teal);"></i> Revenue Over Period</div>
        <div class="chart-wrap"><canvas id="revenueChart" height="120"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fa-solid fa-chart-bar" style="color:#f5a623;"></i> Bookings Over Period</div>
        <div class="chart-wrap"><canvas id="bookingsChart" height="120"></canvas></div>
    </div>
</div>

<!-- Top Packages, Payment Methods, Payment Types -->
<div class="charts-grid-3">
    <div class="chart-card">
        <div class="chart-title"><i class="fa-solid fa-box-open" style="color:#a78bfa;"></i> Top Packages</div>
        <div class="chart-wrap"><canvas id="packagesChart" height="180"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fa-solid fa-credit-card" style="color:#4ecdc4;"></i> Payment Methods</div>
        <div class="chart-wrap" style="max-height:230px;display:flex;align-items:center;justify-content:center;">
            <canvas id="methodsChart"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="chart-title"><i class="fa-solid fa-coins" style="color:#f5a623;"></i> Payment Types</div>
        <div class="chart-wrap" style="max-height:230px;display:flex;align-items:center;justify-content:center;">
            <canvas id="typesChart"></canvas>
        </div>
    </div>
</div>

<!-- Recent Transactions Feed -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--accent-teal);"></i> Recent Transactions</h2>
        <div style="display:flex;gap:10px;align-items:center;">
            <input type="text" id="txSearch" placeholder="Search customer, OR#, booking..." style="padding:8px 14px;background:var(--bg-dark);border:1px solid var(--border-color);border-radius:10px;color:var(--text-primary);font-size:0.84rem;width:260px;" oninput="filterTable()">
            <span id="txCount" style="font-size:0.8rem;color:var(--text-muted);"></span>
        </div>
    </div>
    <div class="table-wrapper">
        <table class="table" id="recentTable">
            <thead>
                <tr>
                    <th>OR #</th>
                    <th>Customer</th>
                    <th>Package</th>
                    <th>Booking Ref</th>
                    <th>Amount Paid</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Event Date</th>
                    <th>Processed By</th>
                    <th>Date & Time</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody id="recentBody">
                <tr><td colspan="11" style="text-align:center;color:var(--text-muted);padding:30px;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div style="padding:10px 16px;font-size:0.76rem;color:var(--text-muted);border-top:1px solid var(--border-color);text-align:right;" id="lastUpdate">Auto-refreshes every 30s</div>
</div>

<style>
@keyframes rowFlash { 0%{background:rgba(78,205,196,0.18);} 100%{background:transparent;} }
.tx-new { animation: rowFlash 1.5s ease-out; }
#txSearch:focus { outline:none; border-color:var(--accent-teal); box-shadow:0 0 0 2px rgba(78,205,196,0.15); }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ─── State ────────────────────────────────────────────────────
let currentPeriod = 'today';
let revenueChart, bookingsChart, packagesChart, methodsChart, typesChart;
let pollTimer;

// ─── Currency formatter ────────────────────────────────────────
const formatCurrency = v => '₱' + parseFloat(v).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

// ─── Chart defaults ────────────────────────────────────────────
Chart.defaults.color = '#a0a0c0';
Chart.defaults.borderColor = 'rgba(255,255,255,0.07)';
const COLORS = ['#4ecdc4','#f5a623','#e94560','#a78bfa','#27ae60','#3498db','#e67e22','#e74c3c'];

function buildGradient(ctx, color) {
    const g = ctx.createLinearGradient(0, 0, 0, 300);
    g.addColorStop(0, color + '55');
    g.addColorStop(1, color + '00');
    return g;
}

// ─── Initialize charts ─────────────────────────────────────────
function initCharts() {
    const rCtx = document.getElementById('revenueChart').getContext('2d');
    revenueChart = new Chart(rCtx, {
        type: 'line',
        data: { labels: [], datasets: [{ label: 'Revenue (₱)', data: [], borderColor: '#4ecdc4', backgroundColor: buildGradient(rCtx, '#4ecdc4'), tension: 0.4, fill: true, pointRadius: 4, pointHoverRadius: 7 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: v => '₱' + v.toLocaleString() } } } }
    });

    const bCtx = document.getElementById('bookingsChart').getContext('2d');
    bookingsChart = new Chart(bCtx, {
        type: 'bar',
        data: { labels: [], datasets: [{ label: 'Bookings', data: [], backgroundColor: '#f5a623cc', borderColor: '#f5a623', borderRadius: 6, borderWidth: 2 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { stepSize: 1 } } } }
    });

    packagesChart = new Chart(document.getElementById('packagesChart'), {
        type: 'bar',
        data: { labels: [], datasets: [{ label: 'Bookings', data: [], backgroundColor: COLORS, borderRadius: 6 }] },
        options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { stepSize: 1 } } } }
    });

    methodsChart = new Chart(document.getElementById('methodsChart'), {
        type: 'doughnut',
        data: { labels: [], datasets: [{ data: [], backgroundColor: COLORS, borderWidth: 2, borderColor: '#161626', hoverOffset: 8 }] },
        options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } } } }
    });

    typesChart = new Chart(document.getElementById('typesChart'), {
        type: 'pie',
        data: { labels: [], datasets: [{ data: [], backgroundColor: ['#4ecdc4','#f5a623','#27ae60'], borderWidth: 2, borderColor: '#161626', hoverOffset: 8 }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12, font: { size: 11 } } } } }
    });
}

// ─── Update charts & KPIs ─────────────────────────────────────
function updateFromData(d) {
    // KPIs
    document.getElementById('kpiRevenue').textContent    = formatCurrency(d.stats.totalRevenue);
    document.getElementById('kpiTodayRev').textContent   = 'Today: ' + formatCurrency(d.stats.todayRevenue);
    document.getElementById('kpiTodayTx').textContent    = d.stats.todayTransactions;
    document.getElementById('kpiBookings').textContent   = d.stats.totalBookings;
    document.getElementById('kpiCancelled').textContent  = d.stats.cancelledBookings + ' cancelled';
    document.getElementById('kpiOutstanding').textContent= formatCurrency(d.stats.outstandingBalance);

    // Revenue chart
    revenueChart.data.labels   = d.revenueChart.labels;
    revenueChart.data.datasets[0].data = d.revenueChart.data;
    revenueChart.update('active');

    // Bookings chart
    bookingsChart.data.labels   = d.bookingsChart.labels;
    bookingsChart.data.datasets[0].data = d.bookingsChart.data;
    bookingsChart.update('active');

    // Packages chart
    packagesChart.data.labels   = d.topPackages.labels;
    packagesChart.data.datasets[0].data = d.topPackages.data;
    packagesChart.update('active');

    // Payment methods chart
    methodsChart.data.labels   = d.paymentMethods.labels;
    methodsChart.data.datasets[0].data = d.paymentMethods.data;
    methodsChart.update('active');

    // Payment types chart
    typesChart.data.labels   = d.paymentTypes.labels;
    typesChart.data.datasets[0].data = d.paymentTypes.data;
    typesChart.update('active');

    // Recent table
    const tbody = document.getElementById('recentBody');
    const prevIds = new Set([...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id));

    if (!d.recentPayments || d.recentPayments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;color:var(--text-muted);padding:30px;"><i class="fa-solid fa-receipt" style="display:block;font-size:2rem;margin-bottom:10px;"></i>No transactions in this period.</td></tr>';
        document.getElementById('txCount').textContent = '0 transactions';
    } else {
        const BASE_URL = '<?= APP_URL ?>';
        tbody.innerHTML = d.recentPayments.map(p => {
            const isNew = !prevIds.has(String(p.payment_id));

            const typeBadge = ({
                'downpayment': '<span class="badge badge-warning"><i class="fa-solid fa-piggy-bank"></i> Downpayment</span>',
                'balance':     '<span class="badge badge-info"><i class="fa-solid fa-scale-balanced"></i> Balance</span>',
                'full':        '<span class="badge badge-success"><i class="fa-solid fa-circle-check"></i> Full Payment</span>',
            })[p.payment_type] || `<span class="badge badge-secondary">${ucw(p.payment_type || 'full')}</span>`;

            const methodBadge = `<span class="badge badge-secondary">${ucw(p.payment_method || 'cash')}</span>`
                + (p.reference_no ? `<div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;">Ref: ${p.reference_no}</div>` : '');

            const orNum = p.or_number || ('OR-' + String(p.payment_id).padStart(6, '0'));
            const bookingRef = p.booking_ref || ('BK-' + String(p.booking_id).padStart(8, '0'));
            const eventDate = p.event_date ? new Date(p.event_date).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'}) : '—';
            const payDate  = p.payment_date ? p.payment_date.replace('T',' ').substring(0,16) : '—';

            const statusColor = (p.booking_status || '').toLowerCase() === 'cancelled' ? 'var(--accent-red)' : '#27ae60';

            return `<tr data-id="${p.payment_id}" class="${isNew ? 'tx-new' : ''}">
                <td><strong style="color:var(--accent-gold);font-size:0.88rem;">${orNum}</strong></td>
                <td>
                    <div style="font-weight:600;">${p.customer}</div>
                    ${p.booking_status && p.booking_status.toLowerCase()==='cancelled' ? '<span style="font-size:0.72rem;color:var(--accent-red);font-weight:700;"><i class="fa-solid fa-ban"></i> Cancelled</span>' : ''}
                </td>
                <td>
                    <div style="font-size:0.83rem;color:var(--text-muted);">${p.package_name}</div>
                </td>
                <td><strong style="color:var(--accent-teal);">${bookingRef}</strong></td>
                <td><strong style="color:#27ae60;font-size:0.95rem;">${formatCurrency(p.amount_paid)}</strong></td>
                <td>${typeBadge}</td>
                <td>${methodBadge}</td>
                <td style="color:var(--text-secondary);font-size:0.82rem;">${eventDate}</td>
                <td style="font-size:0.85rem;">${p.cashier}</td>
                <td style="font-size:0.8rem;color:var(--text-secondary);white-space:nowrap;">${payDate}</td>
                <td>
                    <a href="${BASE_URL}/receipt.php?id=${p.payment_id}" target="_blank" class="btn btn-primary btn-sm" style="white-space:nowrap;">
                        <i class="fa-solid fa-file-invoice"></i> Receipt
                    </a>
                </td>
            </tr>`;
        }).join('');
        document.getElementById('txCount').textContent = d.recentPayments.length + ' transactions';
        filterTable(); // re-apply search if any
    }

    // Subtitle & last update
    const periodMap = {today: 'Today', weekly: 'This Week', monthly: 'This Month', yearly: 'This Year'};
    document.getElementById('reportSubtitle').textContent = periodMap[d.period] + ' — ' + d.dateFrom + ' to ' + d.dateTo;
    document.getElementById('lastUpdate').textContent = 'Last updated: ' + d.generated_at + ' (auto-refreshes every 30s)';
}

function ucw(s) { return s ? s.replace(/_/g,' ').replace(/\b\w/g, c=>c.toUpperCase()) : 'Cash'; }

// ─── Search filter ─────────────────────────────────────────────
function filterTable() {
    const q = (document.getElementById('txSearch').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#recentBody tr[data-id]');
    let visible = 0;
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const show = !q || text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    if (q) document.getElementById('txCount').textContent = visible + ' of ' + rows.length + ' transactions';
    else document.getElementById('txCount').textContent = rows.length + ' transactions';
}

// ─── Fetch ─────────────────────────────────────────────────────
function fetchData(period) {
    // Blink the live dot
    const dot = document.getElementById('liveDot');
    dot.style.background = '#f5a623';
    setTimeout(() => dot.style.background = '#27ae60', 500);

    fetch(`<?= APP_URL ?>/staff/reports_data.php?period=${period}&_t=${Date.now()}`)
        .then(r => r.json())
        .then(d => {
            if (d.error) { console.error('Reports API error:', d.error); return; }
            updateFromData(d);
        })
        .catch(err => {
            document.getElementById('liveLabel').textContent = 'Reconnecting...';
            console.error(err);
        });
}

// ─── Period switch ─────────────────────────────────────────────
document.querySelectorAll('.period-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentPeriod = btn.dataset.period;
        clearInterval(pollTimer);
        fetchData(currentPeriod);
        // Restart polling
        pollTimer = setInterval(() => fetchData(currentPeriod), 30000);
    });
});

// ─── Bootstrap ─────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initCharts();
    fetchData(currentPeriod);
    pollTimer = setInterval(() => fetchData(currentPeriod), 30000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
