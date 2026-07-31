// ============================================================
//  CraftHub Organizer — Main JavaScript
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    // ──────────── SIDEBAR TOGGLE ────────────
    const sidebar        = document.getElementById('sidebar');
    const mainWrapper    = document.querySelector('.main-wrapper');
    const topbarToggle   = document.getElementById('topbarToggle');

    // Create backdrop element for mobile overlay
    const backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    document.body.appendChild(backdrop);

    function isMobile() { return window.innerWidth <= 768; }

    function openMobileSidebar() {
        sidebar?.classList.add('mobile-open');
        backdrop.classList.add('visible');
        document.body.style.overflow = 'hidden';
        if (topbarToggle) topbarToggle.querySelector('i').className = 'fa-solid fa-xmark';
    }

    function closeMobileSidebar() {
        sidebar?.classList.remove('mobile-open');
        backdrop.classList.remove('visible');
        document.body.style.overflow = '';
        if (topbarToggle) topbarToggle.querySelector('i').className = 'fa-solid fa-bars';
    }

    function toggleDesktopSidebar() {
        sidebar?.classList.toggle('collapsed');
        mainWrapper?.classList.toggle('collapsed');
        const isCollapsed = sidebar?.classList.contains('collapsed');
        localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
        if (topbarToggle) {
            topbarToggle.querySelector('i').className = isCollapsed
                ? 'fa-solid fa-bars'
                : 'fa-solid fa-bars';
        }
    }

    function toggleSidebar() {
        if (isMobile()) {
            sidebar?.classList.contains('mobile-open') ? closeMobileSidebar() : openMobileSidebar();
        } else {
            toggleDesktopSidebar();
        }
    }

    // Restore sidebar state on desktop
    if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') {
        sidebar?.classList.add('collapsed');
        mainWrapper?.classList.add('collapsed');
    }

    topbarToggle?.addEventListener('click', toggleSidebar);

    // Close on backdrop click
    backdrop.addEventListener('click', closeMobileSidebar);

    // Close mobile sidebar when a nav link is clicked
    sidebar?.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (isMobile()) closeMobileSidebar();
        });
    });

    // Handle window resize
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            closeMobileSidebar();
            document.body.style.overflow = '';
            // Restore desktop collapse state
            if (localStorage.getItem('sidebarCollapsed') === '1') {
                sidebar?.classList.add('collapsed');
                mainWrapper?.classList.add('collapsed');
            }
        }
    });

    // ── Swipe-to-close on mobile ──
    let touchStartX = 0;
    document.addEventListener('touchstart', (e) => { touchStartX = e.touches[0].clientX; }, { passive: true });
    document.addEventListener('touchend', (e) => {
        if (!isMobile()) return;
        const dx = touchStartX - e.changedTouches[0].clientX;
        if (dx > 60 && sidebar?.classList.contains('mobile-open')) {
            closeMobileSidebar(); // swipe left to close
        }
        if (dx < -60 && !sidebar?.classList.contains('mobile-open') && touchStartX < 30) {
            openMobileSidebar(); // swipe right from left edge to open
        }
    }, { passive: true });

    // ──────────── MODALS ────────────
    // Open modal
    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-modal');
            const modal    = document.getElementById(targetId);
            if (modal) {
                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
        });
    });

    // Close modal
    function closeModal(modal) {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-overlay');
            if (modal) closeModal(modal);
        });
    });

    // Close on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal(overlay);
        });
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(modal => closeModal(modal));
        }
    });

    // ──────────── EDIT MODAL DATA FILL ────────────
    document.querySelectorAll('[data-edit]').forEach(btn => {
        btn.addEventListener('click', () => {
            const data    = JSON.parse(btn.getAttribute('data-edit') || '{}');
            const modalId = btn.getAttribute('data-modal');
            const modal   = document.getElementById(modalId);
            if (!modal) return;

            // Fill in form fields
            Object.entries(data).forEach(([key, value]) => {
                const field = modal.querySelector(`[name="${key}"]`);
                if (field) field.value = value;
            });

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        });
    });

    // ──────────── AUTO-DISMISS ALERTS ────────────
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity    = '0';
            alert.style.transform  = 'translateY(-8px)';
            alert.style.transition = 'all 0.4s ease';
            setTimeout(() => alert.remove(), 400);
        }, 4000);
    });

    // ──────────── TABLE SEARCH ────────────
    const searchInputs = document.querySelectorAll('[data-search-table]');
    searchInputs.forEach(input => {
        const tableId = input.getAttribute('data-search-table');
        const table   = document.getElementById(tableId);
        if (!table) return;

        input.addEventListener('input', () => {
            const query = input.value.toLowerCase().trim();
            table.querySelectorAll('tbody tr').forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    });

    // ──────────── CONFIRM DELETE ────────────
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            const msg = el.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(msg)) e.preventDefault();
        });
    });

    // ──────────── PACKAGE BOOKING — Auto-fill price ────────────
    const packageSelect = document.getElementById('package_id');
    const venueSelect   = document.getElementById('venue_id');
    const totalDisplay  = document.getElementById('total_display');
    const totalHidden   = document.getElementById('total_amount');
    const packagePrices = window.packagePrices || {};
    const venuePrices   = window.venuePrices   || {};

    function recalcTotal() {
        if (!packageSelect || !totalDisplay) return;
        const pkgId   = packageSelect.value;
        const venueId = venueSelect ? venueSelect.value : '0';
        const pkgPrice   = parseFloat(packagePrices[pkgId]   || 0);
        const venuePrice = parseFloat(venuePrices[venueId]   || 0);
        const total = pkgPrice + venuePrice;
        totalDisplay.textContent = '₱ ' + total.toLocaleString('en-PH', {minimumFractionDigits: 2});
        if (totalHidden) totalHidden.value = total.toFixed(2);
    }

    packageSelect?.addEventListener('change', recalcTotal);
    venueSelect?.addEventListener('change', recalcTotal);

    // ──────────── FORM VALIDATION ────────────
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', (e) => {
            let valid = true;
            form.querySelectorAll('[required]').forEach(field => {
                field.style.borderColor = '';
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--accent-red)';
                    field.style.boxShadow = '0 0 0 3px rgba(233,69,96,0.2)';
                    valid = false;
                }
            });

            // Password match check
            const pw  = form.querySelector('[name="password"]');
            const pw2 = form.querySelector('[name="confirm_password"]');
            if (pw && pw2 && pw.value !== pw2.value) {
                pw2.style.borderColor = 'var(--accent-red)';
                alert('Passwords do not match!');
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                // Scroll to first error
                const firstError = form.querySelector('[style*="accent-red"]');
                firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });

    // ──────────── PRINT ────────────
    document.querySelectorAll('[data-print]').forEach(btn => {
        btn.addEventListener('click', () => window.print());
    });

    // ──────────── TOOLTIP INIT ────────────
    document.querySelectorAll('[title]').forEach(el => {
        el.setAttribute('aria-label', el.getAttribute('title'));
    });
});
