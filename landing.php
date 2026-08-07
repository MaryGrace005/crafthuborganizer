<?php
// ============================================================
//  Landing Page — CraftHub Organizer
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Ensure sample images exist in assets/images/
@include_once __DIR__ . '/copy_images.php';

// If already logged in, go straight to dashboard
if (isLoggedIn()) {
    redirectByRole($_SESSION['user_role']);
}

// Load active packages for preview (max 3)
function getLandingPackages(): array {
    $db   = getDB();
    $stmt = $db->query("SELECT p.*, p.package_id AS id, p.package_name AS name, p.base_price AS price FROM packages p WHERE p.status = 'active' ORDER BY p.base_price ASC LIMIT 3");
    $pkgs = $stmt->fetchAll();

    foreach ($pkgs as &$pkg) {
        $cStmt = $db->prepare("SELECT name FROM package_components WHERE package_id = ? LIMIT 4");
        $cStmt->execute([$pkg['id']]);
        $pkg['components'] = $cStmt->fetchAll();
    }
    unset($pkg);
    return $pkgs;
}

$packages = getLandingPackages();

$defaultImages = [
    'Wedding'    => 'assets/images/packages/wedding.png',
    'Birthday'   => 'assets/images/packages/birthday.png',
    'Debut'      => 'assets/images/packages/debut.png',
    'Christening'=> 'assets/images/packages/christening.png',
];

// Package icon map by position
$pkgIcons    = ['fa-wand-magic-sparkles', 'fa-star', 'fa-crown'];
$pkgFeatured = [false, true, false]; // middle = featured
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CraftHub Organizer — The all-in-one platform to browse craft packages, book venues, and manage events with ease.">
    <title>CraftHub Organizer — Your Creative Event Hub</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/landing.css">

    <style>
        /* Inline reset so landing page doesn't inherit sidebar styles */
        body { font-family: 'Inter', sans-serif; background: #0d0d1a; color: #f0f0ff; margin: 0; padding: 0; overflow-x: hidden; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }
        img { max-width: 100%; height: auto; }
        button { cursor: pointer; font-family: inherit; border: none; background: none; }
    </style>
</head>
<body class="landing-body">

<!-- ══════════════════════════════════════
     SPLASH / FLASH SCREEN
══════════════════════════════════════ -->
<div id="splash-screen">
    <div class="splash-logo-wrap">
        <div class="splash-icon">
            <i class="fa-solid fa-palette"></i>
        </div>
        <div class="splash-name">CraftHub</div>
        <div class="splash-tagline">Organizer Platform</div>
    </div>
    <div class="splash-loader">
        <div class="splash-loader-bar"></div>
    </div>
</div>

<!-- ══════════════════════════════════════
     NAVIGATION
══════════════════════════════════════ -->
<nav class="landing-nav" id="landing-nav">
    <a href="<?= APP_URL ?>/landing.php" class="nav-logo">
        <div class="nav-logo-icon"><i class="fa-solid fa-palette"></i></div>
        <span class="nav-logo-text">CraftHub</span>
    </a>

    <ul class="nav-links" id="nav-menu">
        <li><a href="#features">Features</a></li>
        <li><a href="#how-it-works">How it Works</a></li>
        <li><a href="#packages">Packages</a></li>
        <li><a href="#testimonials">Reviews</a></li>
    </ul>

    <div class="nav-cta" id="nav-cta">
        <a href="<?= APP_URL ?>/login.php" class="btn-nav-login">Sign In</a>
        <a href="<?= APP_URL ?>/signup.php" class="btn-nav-register">
            <i class="fa-solid fa-user-plus"></i>
            Create Account
        </a>
    </div>

    <button class="nav-hamburger" id="nav-hamburger" aria-label="Toggle navigation">
        <span></span><span></span><span></span>
    </button>
</nav>

<!-- ══════════════════════════════════════
     HERO SECTION
══════════════════════════════════════ -->
<section class="hero" id="home">
    <!-- Background orbs -->
    <div class="hero-bg-orbs">
        <div class="hero-orb hero-orb-1"></div>
        <div class="hero-orb hero-orb-2"></div>
        <div class="hero-orb hero-orb-3"></div>
    </div>

    <!-- Floating decoration cards -->
    <div class="hero-floating-cards">
        <div class="floating-card floating-card-1">
            <i class="fa-solid fa-calendar-check"></i>
            Booking Confirmed!
        </div>
        <div class="floating-card floating-card-2">
            <i class="fa-solid fa-box-open"></i>
            12 Active Packages
        </div>
        <div class="floating-card floating-card-3">
            <i class="fa-solid fa-money-bill-wave"></i>
            Payment Received ✓
        </div>
        <div class="floating-card floating-card-4">
            <i class="fa-solid fa-location-dot"></i>
            Venue Reserved
        </div>
    </div>

    <!-- Hero Content -->
    <div class="hero-content">
        <div class="hero-badge">
            <i class="fa-solid fa-sparkles"></i>
            Craft Event Management Simplified
        </div>

        <h1 class="hero-title">
            Plan, Book &<br>
            <span>Celebrate</span> Every<br>
            Craft Moment
        </h1>

        <p class="hero-subtitle">
            CraftHub Organizer is your all-in-one platform to discover creative packages,
            book stunning venues, and manage your events — beautifully and effortlessly.
        </p>

        <div class="hero-actions">
            <a href="<?= APP_URL ?>/signup.php" class="btn-hero-primary" id="hero-cta-register">
                <i class="fa-solid fa-user-plus"></i>
                Create Your Account
            </a>
            <a href="<?= APP_URL ?>/login.php" class="btn-hero-secondary" id="hero-cta-login">
                <i class="fa-solid fa-right-to-bracket"></i>
                Sign In
            </a>
        </div>

        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat-num" data-count="500" data-suffix="+">500+</div>
                <div class="hero-stat-label">Events Organized</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-num" data-count="50" data-suffix="+">50+</div>
                <div class="hero-stat-label">Craft Packages</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-num" data-count="98" data-suffix="%">98%</div>
                <div class="hero-stat-label">Happy Customers</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat-num" data-count="24" data-suffix="/7">24/7</div>
                <div class="hero-stat-label">Support</div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     FEATURES SECTION
══════════════════════════════════════ -->
<section class="section features-section" id="features">
    <div class="section-inner">
        <div class="features-header reveal">
            <div class="section-badge badge-red">
                <i class="fa-solid fa-bolt"></i> Features
            </div>
            <h2 class="section-title">Everything You Need to<br><span>Run Perfect Events</span></h2>
            <p class="section-subtitle">A powerful, intuitive platform built for craft organizers — from solo creators to full teams.</p>
        </div>

        <div class="features-grid">
            <div class="feature-card reveal reveal-delay-1" style="--fc-color: rgba(233,69,96,0.08); --fc-border: rgba(233,69,96,0.3);">
                <div class="feature-icon" style="background: linear-gradient(135deg, #e94560, #c0392b);">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div class="feature-title">Craft Package Catalog</div>
                <div class="feature-desc">Browse and filter through a rich catalog of craft packages — each with detailed descriptions, pricing, capacity, and duration.</div>
            </div>

            <div class="feature-card reveal reveal-delay-2" style="--fc-color: rgba(78,205,196,0.08); --fc-border: rgba(78,205,196,0.3);">
                <div class="feature-icon" style="background: linear-gradient(135deg, #4ecdc4, #1a9e96);">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <div class="feature-title">Smart Booking System</div>
                <div class="feature-desc">Instant booking with real-time availability checks, automated reference numbers, and status tracking from pending to confirmed.</div>
            </div>

            <div class="feature-card reveal reveal-delay-3" style="--fc-color: rgba(245,166,35,0.08); --fc-border: rgba(245,166,35,0.3);">
                <div class="feature-icon" style="background: linear-gradient(135deg, #f5a623, #e67e22);">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
                <div class="feature-title">Payment Tracking</div>
                <div class="feature-desc">Full payment lifecycle management — record deposits, partial payments, and final settlements with detailed transaction history.</div>
            </div>

            <div class="feature-card reveal reveal-delay-1" style="--fc-color: rgba(155,89,182,0.08); --fc-border: rgba(155,89,182,0.3);">
                <div class="feature-icon" style="background: linear-gradient(135deg, #9b59b6, #7d3c98);">
                    <i class="fa-solid fa-location-dot"></i>
                </div>
                <div class="feature-title">Venue Management</div>
                <div class="feature-desc">Manage multiple venues with capacity limits, availability schedules, and instant booking conflict detection.</div>
            </div>

            <div class="feature-card reveal reveal-delay-2" style="--fc-color: rgba(39,174,96,0.08); --fc-border: rgba(39,174,96,0.3);">
                <div class="feature-icon" style="background: linear-gradient(135deg, #27ae60, #1e8449);">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div class="feature-title">Multi-Role Access</div>
                <div class="feature-desc">Separate dashboards for Admins, Cashiers, and Customers — each with tailored permissions and workflows.</div>
            </div>

            <div class="feature-card reveal reveal-delay-3" style="--fc-color: rgba(233,69,96,0.08); --fc-border: rgba(233,69,96,0.3);">
                <div class="feature-icon" style="background: linear-gradient(135deg, #e94560, #f5a623);">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div class="feature-title">Analytics & Audit Logs</div>
                <div class="feature-desc">Real-time revenue dashboards, booking statistics, and detailed audit trails to keep your business fully transparent.</div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════ -->
<section class="section how-section" id="how-it-works">
    <div class="section-inner">
        <div class="text-center reveal">
            <div class="section-badge badge-teal" style="display:inline-flex; margin-bottom:16px;">
                <i class="fa-solid fa-map"></i> Process
            </div>
            <h2 class="section-title" style="text-align:center;">How It <span>Works</span></h2>
            <p class="section-subtitle" style="margin: 0 auto; text-align:center;">From sign-up to celebration — in just four simple steps.</p>
        </div>

        <div class="steps-grid">
            <div class="step-card reveal reveal-delay-1">
                <div class="step-num" style="color: #e94560;">01</div>
                <div class="step-title">Create Your Account</div>
                <div class="step-desc">Sign up in seconds with your email. No credit card required to explore packages and venues.</div>
            </div>
            <div class="step-card reveal reveal-delay-2">
                <div class="step-num" style="color: #f5a623;">02</div>
                <div class="step-title">Browse Packages</div>
                <div class="step-desc">Explore our curated craft packages. Filter by price, duration, and capacity to find your perfect fit.</div>
            </div>
            <div class="step-card reveal reveal-delay-3">
                <div class="step-num" style="color: #4ecdc4;">03</div>
                <div class="step-title">Book &amp; Pay</div>
                <div class="step-desc">Select your date, choose a venue, and book instantly. Make partial or full payments at your convenience.</div>
            </div>
            <div class="step-card reveal reveal-delay-4">
                <div class="step-num" style="color: #9b59b6;">04</div>
                <div class="step-title">Celebrate!</div>
                <div class="step-desc">Show up and enjoy! Your booking is confirmed, your venue is ready, and your crafts await.</div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     PACKAGES PREVIEW
══════════════════════════════════════ -->
<section class="section packages-section" id="packages">
    <div class="section-inner">
        <div class="reveal" style="display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:20px; margin-bottom:0;">
            <div>
                <div class="section-badge badge-gold" style="display:inline-flex; margin-bottom:16px;">
                    <i class="fa-solid fa-box-open"></i> Packages
                </div>
                <h2 class="section-title">Our Most Popular<br><span>Craft Packages</span></h2>
                <p class="section-subtitle">Handpicked experiences for every occasion and budget.</p>
            </div>
            <a href="<?= APP_URL ?>/register.php" class="btn-hero-primary" style="height:fit-content;">
                View All Packages <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <div class="packages-preview-grid">
            <?php if (!empty($packages)): ?>
                <?php foreach ($packages as $i => $pkg): ?>
                    <?php
                        $icon     = $pkgIcons[$i] ?? 'fa-star';
                        $featured = $pkgFeatured[$i] ?? false;
                        $img      = !empty($pkg['image_url']) ? $pkg['image_url'] : ($defaultImages[$pkg['event_type'] ?? 'Wedding'] ?? 'assets/images/packages/wedding.png');
                    ?>
                    <div class="pkg-preview-card reveal reveal-delay-<?= $i + 1 ?>" style="overflow:hidden;padding:0;">
                        
                        <!-- Package Sample Image Banner -->
                        <div style="position:relative;height:180px;overflow:hidden;background:#1a1a2e;">
                            <img src="<?= APP_URL ?>/<?= htmlspecialchars($img) ?>"
                                 alt="<?= htmlspecialchars($pkg['name']) ?>"
                                 style="width:100%;height:100%;object-fit:cover;display:block;"
                                 onerror="this.style.display='none';">
                            <?php if ($featured): ?>
                                <div class="pkg-featured-badge" style="position:absolute;top:12px;right:12px;z-index:2;">⭐ Most Popular</div>
                            <?php endif; ?>
                            <div style="position:absolute;bottom:12px;left:12px;background:rgba(15,15,26,0.8);backdrop-filter:blur(6px);color:var(--accent-teal,#4ecdc4);padding:4px 12px;border-radius:20px;font-size:0.78rem;font-weight:600;">
                                <i class="fa-solid fa-gift"></i> <?= htmlspecialchars($pkg['event_type'] ?? 'Event') ?>
                            </div>
                        </div>

                        <div class="pkg-preview-top" style="padding-top:16px;">
                            <div class="pkg-preview-name"><?= htmlspecialchars($pkg['name']) ?></div>
                            <div class="pkg-preview-price">
                                ₱<?= number_format($pkg['price'], 0) ?>
                                <small>/event</small>
                            </div>
                        </div>
                        <div class="pkg-preview-body">
                            <ul class="pkg-preview-features">
                                <li><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($pkg['description'] ?: 'Complete craft experience') ?></li>
                                <?php if (!empty($pkg['components'])): ?>
                                    <?php foreach ($pkg['components'] as $comp): ?>
                                        <li><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($comp['name']) ?></li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li><i class="fa-solid fa-circle-check"></i>Venue selection & coordination</li>
                                    <li><i class="fa-solid fa-circle-check"></i>Dedicated event support</li>
                                <?php endif; ?>
                            </ul>
                            <a href="<?= APP_URL ?>/register.php" class="btn-pkg-book" id="pkg-book-<?= $pkg['id'] ?>">
                                <i class="fa-solid fa-calendar-plus"></i> Book This Package
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Fallback placeholder packages if DB is empty -->
                <div class="pkg-preview-card reveal reveal-delay-1">
                    <div class="pkg-preview-top">
                        <div class="pkg-preview-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                        <div class="pkg-preview-name">Starter Craft</div>
                        <div class="pkg-preview-price">₱2,500<small>/event</small></div>
                    </div>
                    <div class="pkg-preview-body">
                        <ul class="pkg-preview-features">
                            <li><i class="fa-solid fa-check-circle"></i>Basic craft workshop setup</li>
                            <li><i class="fa-solid fa-check-circle"></i>Up to 15 guests</li>
                            <li><i class="fa-solid fa-check-circle"></i>2 hours duration</li>
                            <li><i class="fa-solid fa-check-circle"></i>Venue selection included</li>
                            <li><i class="fa-solid fa-check-circle"></i>Event support</li>
                        </ul>
                        <a href="<?= APP_URL ?>/register.php" class="btn-pkg-book">Book This Package</a>
                    </div>
                </div>
                <div class="pkg-preview-card reveal reveal-delay-2">
                    <div class="pkg-preview-top">
                        <div class="pkg-featured-badge">⭐ Most Popular</div>
                        <div class="pkg-preview-icon"><i class="fa-solid fa-star"></i></div>
                        <div class="pkg-preview-name">Premium Craft</div>
                        <div class="pkg-preview-price">₱5,000<small>/event</small></div>
                    </div>
                    <div class="pkg-preview-body">
                        <ul class="pkg-preview-features">
                            <li><i class="fa-solid fa-check-circle"></i>Full craft studio experience</li>
                            <li><i class="fa-solid fa-check-circle"></i>Up to 30 guests</li>
                            <li><i class="fa-solid fa-check-circle"></i>4 hours duration</li>
                            <li><i class="fa-solid fa-check-circle"></i>Priority venue access</li>
                            <li><i class="fa-solid fa-check-circle"></i>Dedicated coordinator</li>
                        </ul>
                        <a href="<?= APP_URL ?>/register.php" class="btn-pkg-book">Book This Package</a>
                    </div>
                </div>
                <div class="pkg-preview-card reveal reveal-delay-3">
                    <div class="pkg-preview-top">
                        <div class="pkg-preview-icon"><i class="fa-solid fa-crown"></i></div>
                        <div class="pkg-preview-name">Elite Craft</div>
                        <div class="pkg-preview-price">₱9,500<small>/event</small></div>
                    </div>
                    <div class="pkg-preview-body">
                        <ul class="pkg-preview-features">
                            <li><i class="fa-solid fa-check-circle"></i>VIP all-inclusive experience</li>
                            <li><i class="fa-solid fa-check-circle"></i>Up to 60 guests</li>
                            <li><i class="fa-solid fa-check-circle"></i>Full-day access</li>
                            <li><i class="fa-solid fa-check-circle"></i>Exclusive premium venue</li>
                            <li><i class="fa-solid fa-check-circle"></i>Full event management</li>
                        </ul>
                        <a href="<?= APP_URL ?>/register.php" class="btn-pkg-book">Book This Package</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     TESTIMONIALS
══════════════════════════════════════ -->
<section class="section testimonials-section" id="testimonials">
    <div class="section-inner">
        <div class="text-center reveal" style="margin-bottom:0;">
            <div class="section-badge badge-purple" style="display:inline-flex; margin-bottom:16px;">
                <i class="fa-solid fa-heart"></i> Reviews
            </div>
            <h2 class="section-title" style="text-align:center;">What Our <span>Customers Say</span></h2>
        </div>

        <div class="testimonials-grid">
            <div class="testimonial-card reveal reveal-delay-1">
                <div class="testimonial-stars">
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                </div>
                <p class="testimonial-text">"CraftHub made booking our kids' craft birthday party incredibly smooth. The booking system is so easy — I had a confirmed venue in under 5 minutes!"</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar" style="background: linear-gradient(135deg, #e94560, #c0392b);">JL</div>
                    <div>
                        <div class="testimonial-name">Joyce Lim</div>
                        <div class="testimonial-role">Parent & Craft Enthusiast</div>
                    </div>
                </div>
            </div>

            <div class="testimonial-card reveal reveal-delay-2">
                <div class="testimonial-stars">
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i>
                </div>
                <p class="testimonial-text">"As a cashier, the payment tracking dashboard is a lifesaver. I can see pending payments, process transactions, and generate reports all in one place."</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar" style="background: linear-gradient(135deg, #4ecdc4, #1a9e96);">MR</div>
                    <div>
                        <div class="testimonial-name">Marco Reyes</div>
                        <div class="testimonial-role">Event Cashier</div>
                    </div>
                </div>
            </div>

            <div class="testimonial-card reveal reveal-delay-3">
                <div class="testimonial-stars">
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                    <i class="fa-solid fa-star-half-stroke"></i>
                </div>
                <p class="testimonial-text">"The admin dashboard gives me full visibility into our business. Audit logs, revenue stats, and user management — everything is clean and accessible."</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar" style="background: linear-gradient(135deg, #f5a623, #e67e22);">AC</div>
                    <div>
                        <div class="testimonial-name">Ana Cruz</div>
                        <div class="testimonial-role">Studio Owner & Admin</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- ══════════════════════════════════════
     FOOTER
══════════════════════════════════════ -->
<footer class="landing-footer">
    <div class="landing-footer-inner">
        <a href="<?= APP_URL ?>/landing.php" class="footer-logo">
            <div class="footer-logo-icon"><i class="fa-solid fa-palette"></i></div>
            <span class="footer-logo-text">CraftHub Organizer</span>
        </a>

        <p class="footer-copy">&copy; <?= date('Y') ?> CraftHub Organizer. All rights reserved.</p>

        <ul class="footer-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#packages">Packages</a></li>
            <li><a href="<?= APP_URL ?>/login.php">Login</a></li>
            <li><a href="<?= APP_URL ?>/register.php">Register</a></li>
        </ul>
    </div>
</footer>

<!-- Landing Page JS -->
<script src="<?= APP_URL ?>/assets/js/landing.js"></script>

</body>
</html>
