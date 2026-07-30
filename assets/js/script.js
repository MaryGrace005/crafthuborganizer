// ============================================================
//  CraftHub Organizer — Main JavaScript
// ============================================================

document.addEventListener('DOMContentLoaded', () => {

    // ──────────── SIDEBAR TOGGLE ────────────
    const sidebar        = document.getElementById('sidebar');
    const mainWrapper    = document.querySelector('.main-wrapper');
    const sidebarToggle  = document.getElementById('sidebarToggle');
    const topbarToggle   = document.getElementById('topbarToggle');

    function toggleSidebar() {
        if (window.innerWidth <= 768) {
            sidebar?.classList.toggle('mobile-open');
        } else {
            sidebar?.classList.toggle('collapsed');
            mainWrapper?.classList.toggle('collapsed');
            // Save state
            const isCollapsed = sidebar?.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
        }
    }

    // Restore sidebar state
    if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === '1') {
        sidebar?.classList.add('collapsed');
        mainWrapper?.classList.add('collapsed');
    }

    sidebarToggle?.addEventListener('click', toggleSidebar);
    topbarToggle?.addEventListener('click', toggleSidebar);

    // Close sidebar on mobile overlay click
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 &&
            sidebar?.classList.contains('mobile-open') &&
            !sidebar.contains(e.target) &&
            !topbarToggle?.contains(e.target)) {
            sidebar.classList.remove('mobile-open');
        }
    });

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
