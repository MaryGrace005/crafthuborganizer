<?php
// ============================================================
//  Booking Calendar — CraftHub Organizer (Admin)
// ============================================================
$pageTitle = 'Event Schedule & Calendar';
require_once __DIR__ . '/../includes/header.php';
requireRole(['admin']);

$db = getDB();

// ── Selected Month & Year ────────────────────────────────────
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// Clamp values
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$statusFilter = sanitize($_GET['status'] ?? 'all');
$venueFilter  = (int)($_GET['venue'] ?? 0);

// Navigation links
$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthName     = date('F', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth   = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayDow   = (int)date('w', strtotime("{$year}-{$month}-01")); // 0 = Sun, 6 = Sat

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

// Fetch Venues for filter
$venues = $db->query("SELECT venue_id, venue_name FROM venues ORDER BY venue_name ASC")->fetchAll();

// Fetch Bookings for this Month
$query = "
    SELECT b.booking_id, b.booking_reference, b.booking_reference AS reference_no, b.event_date, b.event_time, b.status, b.total_amount,
           b.guest_count, b.notes AS special_requests,
           u.name AS customer_name, u.email AS customer_email, u.contact_no AS customer_phone,
           p.package_name, p.base_price AS package_price,
           v.venue_name, v.location AS venue_location,
           COALESCE((SELECT SUM(amount_paid) FROM payments WHERE booking_id = b.booking_id), 0) AS amount_paid
    FROM bookings b
    JOIN users u ON b.customer_id = u.user_id
    LEFT JOIN packages p ON b.package_id = p.package_id
    LEFT JOIN venues v ON b.venue_id = v.venue_id
    WHERE b.event_date BETWEEN ? AND ?
";

$params = [$monthStart, $monthEnd];

if ($statusFilter !== 'all') {
    $query .= " AND b.status = ?";
    $params[] = $statusFilter;
}

if ($venueFilter > 0) {
    $query .= " AND b.venue_id = ?";
    $params[] = $venueFilter;
}

$query .= " ORDER BY b.event_date ASC, b.event_time ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Group by day number (1 to daysInMonth)
$eventsByDay = [];
foreach ($bookings as $b) {
    $dayNum = (int)date('j', strtotime($b['event_date']));
    $eventsByDay[$dayNum][] = $b;
}

// Summary count for current month
$totalEventsInMonth     = count($bookings);
$confirmedEventsInMonth = count(array_filter($bookings, fn($b) => in_array($b['status'], ['Confirmed', 'Paid'])));
$pendingEventsInMonth   = count(array_filter($bookings, fn($b) => $b['status'] === 'Pending'));

require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="calendar-page-wrapper">
    <!-- Header -->
    <div class="page-header" style="background:linear-gradient(135deg,rgba(78,205,196,0.1),rgba(168,85,247,0.08));border:1px solid rgba(78,205,196,0.2);border-radius:20px;padding:24px 28px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
        <div>
            <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(78,205,196,0.12);border:1px solid rgba(78,205,196,0.3);color:#4ecdc4;padding:4px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;">
                <i class="fa-solid fa-calendar-days"></i> Master Event Schedule
            </div>
            <h1 style="font-family:'Outfit',sans-serif;font-size:1.8rem;font-weight:800;margin:0 0 4px 0;">Booking Calendar</h1>
            <p style="color:var(--text-secondary);font-size:0.88rem;margin:0;">Overview of all company reservations, event timetables, and venue occupancy.</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <a href="<?= APP_URL ?>/admin/bookings.php" class="btn btn-secondary" style="height:40px;display:inline-flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-table-list"></i> Manage Bookings
            </a>
            <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);padding:6px 14px;border-radius:12px;font-size:0.82rem;display:flex;gap:12px;">
                <span><strong><?= $totalEventsInMonth ?></strong> Events</span>
                <span style="color:#27ae60;">● <strong><?= $confirmedEventsInMonth ?></strong> Confirmed</span>
                <span style="color:#f5a623;">● <strong><?= $pendingEventsInMonth ?></strong> Pending</span>
            </div>
        </div>
    </div>

    <!-- Controls Bar -->
    <div class="card" style="padding:16px 20px;margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;">
            <!-- Month Switcher -->
            <div style="display:flex;align-items:center;gap:10px;">
                <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&status=<?= urlencode($statusFilter) ?>&venue=<?= $venueFilter ?>" class="btn btn-secondary btn-sm" style="padding:8px 14px;">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <div style="font-family:'Outfit',sans-serif;font-weight:800;font-size:1.3rem;color:#fff;min-width:180px;text-align:center;">
                    <?= $monthName ?> <?= $year ?>
                </div>
                <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&status=<?= urlencode($statusFilter) ?>&venue=<?= $venueFilter ?>" class="btn btn-secondary btn-sm" style="padding:8px 14px;">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
                <a href="?year=<?= date('Y') ?>&month=<?= date('m') ?>&status=<?= urlencode($statusFilter) ?>&venue=<?= $venueFilter ?>" class="btn btn-sm" style="background:rgba(255,255,255,0.06);color:var(--text-secondary);border:1px solid rgba(255,255,255,0.1);">
                    Today
                </a>
            </div>

            <!-- View & Filter Form -->
            <form method="GET" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;">
                <input type="hidden" name="year" value="<?= $year ?>">
                <input type="hidden" name="month" value="<?= $month ?>">

                <!-- Status Filter -->
                <select name="status" onchange="this.form.submit()" class="form-control" style="width:auto;padding:7px 12px;font-size:0.85rem;background:#10101c;">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="Confirmed" <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="Cancelled" <?= $statusFilter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>

                <!-- Venue Filter -->
                <select name="venue" onchange="this.form.submit()" class="form-control" style="width:auto;padding:7px 12px;font-size:0.85rem;background:#10101c;">
                    <option value="0">All Venues</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?= $v['venue_id'] ?>" <?= $venueFilter === (int)$v['venue_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['venue_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Toggle View Mode Button -->
                <button type="button" id="toggleViewBtn" onclick="toggleCalendarView()" class="btn btn-secondary btn-sm" style="padding:7px 14px;">
                    <i class="fa-solid fa-list-ul"></i> <span id="viewBtnLabel">Agenda View</span>
                </button>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         MONTH GRID VIEW
    ══════════════════════════════════════════ -->
    <div id="calendarMonthView" class="card" style="padding:0;overflow:hidden;border:1px solid rgba(255,255,255,0.08);margin-bottom:30px;">
        <!-- Day Names Header -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);background:rgba(255,255,255,0.03);border-bottom:1px solid rgba(255,255,255,0.08);text-align:center;">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                <div style="padding:12px 6px;font-weight:700;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary);">
                    <?= $dow ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Days Grid -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);background:rgba(255,255,255,0.02);">
            <?php
            // Empty cells before first day of month
            for ($i = 0; $i < $firstDayDow; $i++):
            ?>
                <div style="min-height:115px;background:rgba(0,0,0,0.2);border-right:1px solid rgba(255,255,255,0.04);border-bottom:1px solid rgba(255,255,255,0.04);padding:8px;opacity:0.3;"></div>
            <?php endfor; ?>

            <?php
            $todayYmd = date('Y-m-d');
            for ($day = 1; $day <= $daysInMonth; $day++):
                $currentYmd = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $isToday    = ($currentYmd === $todayYmd);
                $dayEvents  = $eventsByDay[$day] ?? [];
                $eventCount = count($dayEvents);
            ?>
                <div class="calendar-day-cell <?= $isToday ? 'is-today' : '' ?>"
                     style="min-height:120px;border-right:1px solid rgba(255,255,255,0.06);border-bottom:1px solid rgba(255,255,255,0.06);padding:8px;position:relative;background:<?= $isToday ? 'rgba(78,205,196,0.04)' : 'transparent' ?>;transition:background 0.2s;">
                    
                    <!-- Day Number & Indicator -->
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-weight:<?= $isToday ? '800' : '600' ?>;font-size:0.88rem;color:<?= $isToday ? '#4ecdc4' : '#cbd5e1' ?>;<?= $isToday ? 'background:rgba(78,205,196,0.18);padding:2px 7px;border-radius:8px;' : '' ?>">
                            <?= $day ?>
                        </span>
                        <?php if ($eventCount > 0): ?>
                            <span style="font-size:0.68rem;font-weight:700;background:rgba(255,255,255,0.08);color:var(--text-secondary);padding:1px 6px;border-radius:10px;">
                                <?= $eventCount ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Event Pills -->
                    <div style="display:flex;flex-direction:column;gap:4px;">
                        <?php
                        $maxShow = 3;
                        $shown = 0;
                        foreach ($dayEvents as $evt):
                            if ($shown >= $maxShow) break;
                            $shown++;

                            $status = $evt['status'];
                            $pillBg = '#1e293b';
                            $pillBorder = 'rgba(255,255,255,0.1)';
                            $pillColor = '#fff';

                            if (in_array($status, ['Confirmed', 'Paid'])) {
                                $pillBg = 'rgba(78,205,196,0.15)';
                                $pillBorder = '#4ecdc4';
                                $pillColor = '#4ecdc4';
                            } elseif ($status === 'Pending') {
                                $pillBg = 'rgba(245,166,35,0.15)';
                                $pillBorder = '#f5a623';
                                $pillColor = '#f5a623';
                            } elseif ($status === 'Completed') {
                                $pillBg = 'rgba(59,130,246,0.15)';
                                $pillBorder = '#3b82f6';
                                $pillColor = '#60a5fa';
                            } elseif ($status === 'Cancelled') {
                                $pillBg = 'rgba(233,69,96,0.15)';
                                $pillBorder = '#e94560';
                                $pillColor = '#e94560';
                            }

                            $timeStr = !empty($evt['event_time']) ? date('g:i A', strtotime($evt['event_time'])) : '';
                            $evtJson = htmlspecialchars(json_encode($evt), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="calendar-event-pill"
                                 onclick='openEventModal(<?= $evtJson ?>)'
                                 style="background:<?= $pillBg ?>;border-left:3px solid <?= $pillBorder ?>;border-radius:4px;padding:3px 6px;font-size:0.72rem;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:all 0.15s ease;"
                                 title="<?= htmlspecialchars($evt['package_name']) ?> - <?= htmlspecialchars($evt['customer_name']) ?>">
                                <strong style="color:<?= $pillColor ?>;"><?= $timeStr ? $timeStr . ' ' : '' ?></strong>
                                <span style="color:#e2e8f0;"><?= htmlspecialchars($evt['package_name']) ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($eventCount > $maxShow): ?>
                            <div style="font-size:0.68rem;color:var(--text-muted);font-weight:700;text-align:right;padding-top:2px;">
                                +<?= ($eventCount - $maxShow) ?> more
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <?php
            // Fill remaining grid spaces to make full rows
            $totalCells = $firstDayDow + $daysInMonth;
            $remCells   = (7 - ($totalCells % 7)) % 7;
            for ($j = 0; $j < $remCells; $j++):
            ?>
                <div style="min-height:115px;background:rgba(0,0,0,0.2);border-right:1px solid rgba(255,255,255,0.04);border-bottom:1px solid rgba(255,255,255,0.04);padding:8px;opacity:0.3;"></div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         AGENDA / LIST VIEW (Hidden by default)
    ══════════════════════════════════════════ -->
    <div id="calendarAgendaView" class="card" style="display:none;margin-bottom:30px;padding:20px;">
        <h3 style="font-family:'Outfit',sans-serif;font-size:1.15rem;margin:0 0 16px 0;display:flex;align-items:center;gap:8px;">
            <i class="fa-solid fa-list-check" style="color:var(--accent-teal);"></i> Chronological Schedule for <?= $monthName ?> <?= $year ?>
        </h3>

        <?php if (empty($bookings)): ?>
            <div style="text-align:center;padding:40px;color:var(--text-muted);">
                <i class="fa-solid fa-calendar-xmark" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                No scheduled bookings found matching your selected filters for this month.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Package</th>
                            <th>Venue</th>
                            <th>Total / Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $b):
                            $balance = max(0, (float)$b['total_amount'] - (float)$b['amount_paid']);
                            $ref = getBookingRef($b);
                            $badgeClass = match($b['status']) {
                                'Confirmed' => 'badge-success',
                                'Paid'      => 'badge-success',
                                'Pending'   => 'badge-warning',
                                'Completed' => 'badge-info',
                                'Cancelled' => 'badge-danger',
                                default     => 'badge-secondary'
                            };
                            $evtJson = htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td>
                                <strong style="color:#fff;"><?= date('M j, Y (D)', strtotime($b['event_date'])) ?></strong><br>
                                <small style="color:var(--text-secondary);"><?= !empty($b['event_time']) ? date('g:i A', strtotime($b['event_time'])) : 'TBA' ?></small>
                            </td>
                            <td>
                                <code style="color:var(--accent-teal);font-weight:700;"><?= htmlspecialchars($ref) ?></code>
                            </td>
                            <td>
                                <strong style="color:#fff;"><?= htmlspecialchars($b['customer_name']) ?></strong><br>
                                <small style="color:var(--text-muted);"><?= htmlspecialchars($b['customer_phone'] ?: $b['customer_email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($b['package_name']) ?></td>
                            <td><?= htmlspecialchars($b['venue_name'] ?: 'Not assigned') ?></td>
                            <td>
                                <span style="font-weight:700;color:#fff;"><?= formatCurrency($b['total_amount']) ?></span><br>
                                <small style="color:<?= $balance > 0 ? '#f5a623' : '#27ae60' ?>;">
                                    <?= $balance > 0 ? 'Bal: ' . formatCurrency($balance) : 'Paid in full' ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?= $badgeClass ?>"><?= $b['status'] ?></span>
                            </td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-sm btn-secondary" onclick='openEventModal(<?= $evtJson ?>)' title="View Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <a href="<?= APP_URL ?>/invoice.php?id=<?= $b['booking_id'] ?>" target="_blank" class="btn btn-sm btn-secondary" title="View Invoice">
                                        <i class="fa-solid fa-file-invoice"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══════════════════════════════════════════
     EVENT DETAILS MODAL
══════════════════════════════════════════ -->
<div id="eventDetailsModal" class="modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.75);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(6px);">
    <div class="modal-content" style="background:#161626;border:1px solid rgba(255,255,255,0.12);border-radius:20px;max-width:540px;width:100%;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.7);position:relative;">
        <!-- Header Accent -->
        <div style="height:4px;background:linear-gradient(90deg,#4ecdc4,#f5a623,#e94560);"></div>
        
        <div style="padding:24px 28px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
                <div>
                    <span id="modalStatusBadge" class="badge" style="font-size:0.78rem;padding:4px 10px;text-transform:uppercase;">Status</span>
                    <h2 id="modalPackageTitle" style="font-family:'Outfit',sans-serif;font-size:1.4rem;font-weight:800;color:#fff;margin:8px 0 2px 0;">Package Name</h2>
                    <span id="modalRefCode" style="color:var(--accent-teal);font-size:0.85rem;font-weight:700;">BK-000000</span>
                </div>
                <button type="button" onclick="closeEventModal()" style="background:none;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer;padding:4px;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Details Grid -->
            <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:16px;display:flex;flex-direction:column;gap:12px;margin-bottom:20px;">
                <div style="display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:8px;">
                    <span style="color:var(--text-secondary);font-size:0.85rem;"><i class="fa-solid fa-calendar-day" style="width:18px;color:#4ecdc4;"></i> Event Date:</span>
                    <strong id="modalDate" style="color:#fff;font-size:0.88rem;">-</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:8px;">
                    <span style="color:var(--text-secondary);font-size:0.85rem;"><i class="fa-solid fa-clock" style="width:18px;color:#f5a623;"></i> Event Time:</span>
                    <strong id="modalTime" style="color:#fff;font-size:0.88rem;">-</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:8px;">
                    <span style="color:var(--text-secondary);font-size:0.85rem;"><i class="fa-solid fa-location-dot" style="width:18px;color:#e94560;"></i> Venue:</span>
                    <strong id="modalVenue" style="color:#fff;font-size:0.88rem;text-align:right;">-</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:8px;">
                    <span style="color:var(--text-secondary);font-size:0.85rem;"><i class="fa-solid fa-user" style="width:18px;color:#3b82f6;"></i> Customer:</span>
                    <strong id="modalCustomer" style="color:#fff;font-size:0.88rem;">-</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.05);padding-bottom:8px;">
                    <span style="color:var(--text-secondary);font-size:0.85rem;"><i class="fa-solid fa-phone" style="width:18px;color:#27ae60;"></i> Contact:</span>
                    <strong id="modalContact" style="color:#fff;font-size:0.88rem;">-</strong>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="color:var(--text-secondary);font-size:0.85rem;"><i class="fa-solid fa-file-invoice-dollar" style="width:18px;color:#a855f7;"></i> Total / Balance:</span>
                    <div>
                        <strong id="modalTotal" style="color:#fff;font-size:0.92rem;">-</strong>
                        <div id="modalBalance" style="font-size:0.8rem;text-align:right;">-</div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
                <a id="modalInvoiceBtn" href="#" target="_blank" class="btn btn-secondary" style="font-size:0.85rem;">
                    <i class="fa-solid fa-file-invoice"></i> View Invoice
                </a>
                <a id="modalManageBtn" href="<?= APP_URL ?>/admin/bookings.php" class="btn btn-primary" style="font-size:0.85rem;">
                    <i class="fa-solid fa-calendar-check"></i> Manage in Bookings
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleCalendarView() {
    const monthView  = document.getElementById('calendarMonthView');
    const agendaView = document.getElementById('calendarAgendaView');
    const label      = document.getElementById('viewBtnLabel');

    if (monthView.style.display === 'none') {
        monthView.style.display = 'block';
        agendaView.style.display = 'none';
        label.textContent = 'Agenda View';
    } else {
        monthView.style.display = 'none';
        agendaView.style.display = 'block';
        label.textContent = 'Month Grid';
    }
}

function openEventModal(evt) {
    const modal = document.getElementById('eventDetailsModal');
    if (!modal) return;

    // Populate data
    document.getElementById('modalPackageTitle').textContent = evt.package_name || 'Event Booking';
    document.getElementById('modalRefCode').textContent = evt.reference_no || ('BK-' + String(evt.booking_id).padStart(6, '0'));
    
    // Status Badge
    const badge = document.getElementById('modalStatusBadge');
    badge.textContent = evt.status;
    badge.className = 'badge ' + (
        evt.status === 'Confirmed' || evt.status === 'Paid' ? 'badge-success' :
        evt.status === 'Pending' ? 'badge-warning' :
        evt.status === 'Completed' ? 'badge-info' : 'badge-danger'
    );

    // Format Date & Time
    const d = new Date(evt.event_date + 'T00:00:00');
    const dateOpts = { year: 'numeric', month: 'long', day: 'numeric', weekday: 'short' };
    document.getElementById('modalDate').textContent = d.toLocaleDateString('en-US', dateOpts);
    document.getElementById('modalTime').textContent = evt.event_time ? formatTime(evt.event_time) : 'TBA';
    document.getElementById('modalVenue').textContent = (evt.venue_name || 'Not assigned') + (evt.venue_location ? ' (' + evt.venue_location + ')' : '');
    document.getElementById('modalCustomer').textContent = evt.customer_name || 'Customer';
    document.getElementById('modalContact').textContent = (evt.customer_phone || evt.customer_email || 'None');

    const total = parseFloat(evt.total_amount || 0);
    const paid = parseFloat(evt.amount_paid || 0);
    const balance = Math.max(0, total - paid);

    document.getElementById('modalTotal').textContent = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 2 });
    const balEl = document.getElementById('modalBalance');
    if (balance <= 0) {
        balEl.style.color = '#27ae60';
        balEl.textContent = 'Fully Paid (₱' + paid.toLocaleString('en-US', { minimumFractionDigits: 2 }) + ')';
    } else {
        balEl.style.color = '#f5a623';
        balEl.textContent = 'Balance: ₱' + balance.toLocaleString('en-US', { minimumFractionDigits: 2 });
    }

    // Links
    document.getElementById('modalInvoiceBtn').href = '<?= APP_URL ?>/invoice.php?id=' + evt.booking_id;

    modal.style.display = 'flex';
}

function closeEventModal() {
    const modal = document.getElementById('eventDetailsModal');
    if (modal) modal.style.display = 'none';
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    let h = parseInt(parts[0], 10);
    const m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    h = h ? h : 12;
    return h + ':' + m + ' ' + ampm;
}

// Close modal on click outside
window.addEventListener('click', function(e) {
    const modal = document.getElementById('eventDetailsModal');
    if (e.target === modal) {
        closeEventModal();
    }
});
</script>

<style>
.calendar-day-cell:hover {
    background: rgba(255,255,255,0.04) !important;
}
.calendar-event-pill:hover {
    filter: brightness(1.2);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.4);
}
@media (max-width: 768px) {
    .calendar-day-cell {
        min-height: 80px !important;
        padding: 4px !important;
    }
    .calendar-event-pill span {
        display: none;
    }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
