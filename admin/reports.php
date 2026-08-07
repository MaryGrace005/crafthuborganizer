<?php
// ============================================================
//  Reports & Analytics — CraftHub Organizer
//  Real-time data visualization with Weekly, Monthly & Yearly charts
// ============================================================
$pageTitle = 'Reports & Analytics';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);
?>

<!-- Include Chart.js via CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- Page Header -->
<div class="page-header" style="background:linear-gradient(135deg,rgba(78,205,196,0.1),rgba(168,85,247,0.08));border:1px solid rgba(78,205,196,0.2);border-radius:20px;padding:28px 32px;margin-bottom:24px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#4ecdc4,#a855f7,#f5a623,#e94560);"></div>
    <div>
        <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(78,205,196,0.12);border:1px solid rgba(78,205,196,0.3);color:#4ecdc4;padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:10px;">
            <i class="fa-solid fa-chart-line"></i> Real-time Analytics &amp; Reports
        </div>
        <h1 style="font-family:'Outfit',sans-serif;font-size:1.9rem;font-weight:800;margin-bottom:4px;">Business Performance Dashboard</h1>
        <p style="color:var(--text-secondary);font-size:0.92rem;">Interactive graphic data, revenue analytics, package breakdown, and automatic polling.</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;">
        <span id="livePulse" style="display:inline-flex;align-items:center;gap:6px;background:rgba(39,174,96,0.15);border:1px solid rgba(39,174,96,0.35);color:#27ae60;padding:6px 14px;border-radius:20px;font-size:0.8rem;font-weight:700;">
            <span style="width:8px;height:8px;border-radius:50%;background:#27ae60;box-shadow:0 0 8px #27ae60;animation:pulse 1.5s infinite;"></span> LIVE
        </span>
        <button class="btn btn-secondary" onclick="window.print()" style="height:42px;">
            <i class="fa-solid fa-print"></i> Print Report
        </button>
    </div>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(0.95); opacity: 0.8; }
    50% { transform: scale(1.15); opacity: 1; }
    100% { transform: scale(0.95); opacity: 0.8; }
}

/* Period Tabs */
.period-btn {
    padding: 10px 22px;
    border-radius: 12px;
    font-family: 'Outfit', sans-serif;
    font-weight: 700;
    font-size: 0.88rem;
    color: var(--text-secondary);
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.09);
    cursor: pointer;
    transition: all 0.25s ease;
}
.period-btn:hover {
    color: #fff;
    background: rgba(255,255,255,0.09);
    border-color: rgba(255,255,255,0.18);
}
.period-btn.active {
    background: linear-gradient(135deg, var(--accent-teal), #2b938b);
    color: #0f0f1a;
    border-color: var(--accent-teal);
    box-shadow: 0 4px 16px rgba(78,205,196,0.35);
}

.chart-card {
    background: var(--bg-card, rgba(22,22,38,0.85));
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}
.chart-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
</style>

<!-- Control Bar: Period Selection & Custom Dates -->
<div class="card" style="margin-bottom:24px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;">
            <button class="period-btn" data-period="weekly" onclick="setPeriod('weekly')">
                <i class="fa-solid fa-calendar-week"></i> Weekly
            </button>
            <button class="period-btn active" data-period="monthly" onclick="setPeriod('monthly')">
                <i class="fa-solid fa-calendar-days"></i> Monthly
            </button>
            <button class="period-btn" data-period="yearly" onclick="setPeriod('yearly')">
                <i class="fa-solid fa-calendar"></i> Yearly
            </button>
        </div>

        <form id="customDateForm" onsubmit="applyCustomDates(event)" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-size:0.85rem;color:var(--text-muted);font-weight:600;">Custom Range:</span>
            <input type="date" id="dateFrom" class="form-control" style="width:auto;padding:6px 12px;font-size:0.85rem;">
            <span style="color:var(--text-muted);">to</span>
            <input type="date" id="dateTo" class="form-control" style="width:auto;padding:6px 12px;font-size:0.85rem;">
            <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;">
                <i class="fa-solid fa-filter"></i> Apply
            </button>
        </form>
    </div>
</div>

<!-- Real-time Stats Cards Grid -->
<div class="stats-grid" style="margin-bottom:24px;display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;">
    <div class="stat-card" style="--stat-color:#27ae60;background:rgba(39,174,96,0.06);border:1px solid rgba(39,174,96,0.2);border-radius:16px;padding:20px;display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;border-radius:14px;background:rgba(39,174,96,0.18);color:#27ae60;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
            <i class="fa-solid fa-peso-sign"></i>
        </div>
        <div>
            <div id="statRevenue" style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:800;color:#27ae60;">₱0.00</div>
            <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Total Revenue</div>
        </div>
    </div>

    <div class="stat-card" style="--stat-color:#4ecdc4;background:rgba(78,205,196,0.06);border:1px solid rgba(78,205,196,0.2);border-radius:16px;padding:20px;display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;border-radius:14px;background:rgba(78,205,196,0.18);color:#4ecdc4;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <div>
            <div id="statBookings" style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:800;color:#4ecdc4;">0</div>
            <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Confirmed Bookings</div>
        </div>
    </div>

    <div class="stat-card" style="--stat-color:#a855f7;background:rgba(168,85,247,0.06);border:1px solid rgba(168,85,247,0.2);border-radius:16px;padding:20px;display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;border-radius:14px;background:rgba(168,85,247,0.18);color:#a855f7;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
            <i class="fa-solid fa-user-plus"></i>
        </div>
        <div>
            <div id="statCustomers" style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:800;color:#a855f7;">0</div>
            <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">New Customers</div>
        </div>
    </div>

    <div class="stat-card" style="--stat-color:#f5a623;background:rgba(245,166,35,0.06);border:1px solid rgba(245,166,35,0.2);border-radius:16px;padding:20px;display:flex;align-items:center;gap:16px;">
        <div style="width:48px;height:48px;border-radius:14px;background:rgba(245,166,35,0.18);color:#f5a623;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">
            <i class="fa-solid fa-chart-simple"></i>
        </div>
        <div>
            <div id="statAvgBooking" style="font-family:'Outfit',sans-serif;font-size:1.6rem;font-weight:800;color:#f5a623;">₱0.00</div>
            <div style="font-size:0.78rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Avg Booking Value</div>
        </div>
    </div>
</div>

<!-- Charts Row 1: Line & Bar -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
    <div class="chart-card">
        <div class="chart-title">
            <i class="fa-solid fa-chart-line" style="color:#27ae60;"></i> Revenue Trend over Time
        </div>
        <div style="position:relative;height:280px;">
            <canvas id="revenueChartCanvas"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-title">
            <i class="fa-solid fa-chart-column" style="color:#4ecdc4;"></i> Booking Volume Breakdown
        </div>
        <div style="position:relative;height:280px;">
            <canvas id="bookingsChartCanvas"></canvas>
        </div>
    </div>
</div>

<!-- Charts Row 2: Doughnut & Pie -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
    <div class="chart-card">
        <div class="chart-title">
            <i class="fa-solid fa-chart-pie" style="color:#a855f7;"></i> Package Popularity Share
        </div>
        <div style="position:relative;height:280px;display:flex;align-items:center;justify-content:center;">
            <canvas id="packageChartCanvas"></canvas>
        </div>
    </div>

    <div class="chart-card">
        <div class="chart-title">
            <i class="fa-solid fa-wallet" style="color:#f5a623;"></i> Payment Method Distribution
        </div>
        <div style="position:relative;height:280px;display:flex;align-items:center;justify-content:center;">
            <canvas id="paymentMethodChartCanvas"></canvas>
        </div>
    </div>
</div>

<!-- Recent Transactions Table -->
<div class="card" style="margin-bottom:30px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h2 class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Period Transactions Log</h2>
        <span id="lastUpdatedTag" style="font-size:0.78rem;color:var(--text-muted);"></span>
    </div>
    <div class="table-wrapper">
        <table class="table" id="transactionsTable">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Booking Ref</th>
                    <th>Customer Name</th>
                    <th>Amount Paid</th>
                    <th>Method</th>
                    <th>Cashier / Processed By</th>
                </tr>
            </thead>
            <tbody id="transactionsTbody">
                <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">Loading live data...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentPeriod = 'monthly';
let customFrom    = '';
let customTo      = '';
let revenueChart, bookingsChart, packageChart, paymentMethodChart;

// Helper: Format PHP Currency
function formatPHP(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Chart.js Theme Defaults
Chart.defaults.color = '#a0a0c0';
Chart.defaults.font.family = "'Outfit', sans-serif";

function initCharts() {
    // 1. Revenue Line Chart
    const ctxRev = document.getElementById('revenueChartCanvas').getContext('2d');
    revenueChart = new Chart(ctxRev, {
        type: 'line',
        data: { labels: [], datasets: [{ label: 'Revenue (₱)', data: [], borderColor: '#27ae60', backgroundColor: 'rgba(39,174,96,0.12)', fill: true, tension: 0.35, pointRadius: 4, pointBackgroundColor: '#27ae60' }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.06)' } }, x: { grid: { display: false } } }
        }
    });

    // 2. Bookings Bar Chart
    const ctxBk = document.getElementById('bookingsChartCanvas').getContext('2d');
    bookingsChart = new Chart(ctxBk, {
        type: 'bar',
        data: { labels: [], datasets: [{ label: 'Bookings', data: [], backgroundColor: '#4ecdc4', borderRadius: 8 }] },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(255,255,255,0.06)' } }, x: { grid: { display: false } } }
        }
    });

    // 3. Package Popularity Doughnut Chart
    const ctxPkg = document.getElementById('packageChartCanvas').getContext('2d');
    packageChart = new Chart(ctxPkg, {
        type: 'doughnut',
        data: { labels: [], datasets: [{ data: [], backgroundColor: ['#a855f7','#4ecdc4','#f5a623','#e94560','#27ae60','#3498db'] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });

    // 4. Payment Method Pie Chart
    const ctxPm = document.getElementById('paymentMethodChartCanvas').getContext('2d');
    paymentMethodChart = new Chart(ctxPm, {
        type: 'pie',
        data: { labels: [], datasets: [{ data: [], backgroundColor: ['#27ae60','#f5a623','#3498db','#9b59b6'] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
    });
}

function loadReportsData() {
    let url = '<?= APP_URL ?>/admin/reports_data.php?period=' + currentPeriod;
    if (customFrom && customTo) {
        url += '&from=' + customFrom + '&to=' + customTo;
    }

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (data.error) return;

            // Update Stat Cards
            document.getElementById('statRevenue').textContent    = formatPHP(data.stats.totalRevenue);
            document.getElementById('statBookings').textContent   = data.stats.totalBookings;
            document.getElementById('statCustomers').textContent  = data.stats.newCustomers;
            document.getElementById('statAvgBooking').textContent = formatPHP(data.stats.avgBooking);

            // Update Date Form Inputs
            if (data.dateFrom && data.dateTo) {
                document.getElementById('dateFrom').value = data.dateFrom;
                document.getElementById('dateTo').value   = data.dateTo;
            }

            // Update Revenue Line Chart
            revenueChart.data.labels = data.revenueChart.labels;
            revenueChart.data.datasets[0].data = data.revenueChart.data;
            revenueChart.update();

            // Update Bookings Bar Chart
            bookingsChart.data.labels = data.bookingsChart.labels;
            bookingsChart.data.datasets[0].data = data.bookingsChart.data;
            bookingsChart.update();

            // Update Package Doughnut Chart
            packageChart.data.labels = data.topPackages.labels;
            packageChart.data.datasets[0].data = data.topPackages.data;
            packageChart.update();

            // Update Payment Method Pie Chart
            paymentMethodChart.data.labels = data.paymentMethods.labels;
            paymentMethodChart.data.datasets[0].data = data.paymentMethods.data;
            paymentMethodChart.update();

            // Update Transactions Table
            const tbody = document.getElementById('transactionsTbody');
            tbody.innerHTML = '';
            if (!data.recentPayments || data.recentPayments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">No payment records in selected period.</td></tr>';
            } else {
                data.recentPayments.forEach(p => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-size:0.85rem;color:var(--text-secondary);">${p.payment_date}</td>
                        <td><span style="color:var(--accent-teal);font-weight:700;">${p.booking_reference || 'BK-0000'}</span></td>
                        <td><strong>${p.customer}</strong></td>
                        <td style="color:#27ae60;font-weight:800;">${formatPHP(p.amount_paid)}</td>
                        <td><span class="badge badge-info">${(p.payment_method || 'cash').toUpperCase()}</span></td>
                        <td>${p.cashier}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            document.getElementById('lastUpdatedTag').textContent = 'Auto-updated: ' + data.generated_at;
        })
        .catch(err => console.error('Data fetch error:', err));
}

function setPeriod(period) {
    currentPeriod = period;
    customFrom = ''; customTo = '';
    document.querySelectorAll('.period-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.period === period);
    });
    loadReportsData();
}

function applyCustomDates(e) {
    e.preventDefault();
    customFrom = document.getElementById('dateFrom').value;
    customTo   = document.getElementById('dateTo').value;
    document.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
    loadReportsData();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    initCharts();
    loadReportsData();
    // Auto-refresh every 60 seconds (realtime update)
    setInterval(loadReportsData, 60000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
