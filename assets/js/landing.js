// ============================================================
//  CraftHub Organizer — Landing Page & Splash Screen JS
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // ──────────── SPLASH SCREEN ────────────
    const splash = document.getElementById('splash-screen');
    if (splash) {
        // Hide splash after ~2.4s (loader animation finishes)
        setTimeout(() => {
            splash.classList.add('hidden');
        }, 2600);

        // Remove from DOM after transition
        splash.addEventListener('transitionend', () => {
            if (splash.classList.contains('hidden')) {
                splash.remove();
            }
        });
    }

    // ──────────── NAVBAR: SCROLL EFFECT ────────────
    const nav = document.getElementById('landing-nav');
    if (nav) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 40) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });
    }

    // ──────────── MOBILE NAV HAMBURGER ────────────
    const hamburger = document.getElementById('nav-hamburger');
    const navMenu   = document.getElementById('nav-menu');
    const navCTA    = document.getElementById('nav-cta');

    if (hamburger && navMenu) {
        hamburger.addEventListener('click', () => {
            navMenu.classList.toggle('mobile-open');
            if (navCTA) navCTA.style.display = navCTA.style.display === 'flex' ? 'none' : 'flex';

            // Animate hamburger lines
            const spans = hamburger.querySelectorAll('span');
            hamburger.classList.toggle('open');
            if (hamburger.classList.contains('open')) {
                spans[0].style.transform = 'rotate(45deg) translate(5px, 5px)';
                spans[1].style.opacity = '0';
                spans[2].style.transform = 'rotate(-45deg) translate(5px, -5px)';
            } else {
                spans[0].style.transform = '';
                spans[1].style.opacity = '';
                spans[2].style.transform = '';
            }
        });
    }

    // ──────────── SCROLL REVEAL ANIMATIONS ────────────
    const revealEls = document.querySelectorAll('.reveal');

    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12 });

    revealEls.forEach(el => revealObserver.observe(el));

    // ──────────── SMOOTH SCROLL FOR ANCHOR LINKS ────────────
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // ──────────── ANIMATED COUNTERS (hero stats) ────────────
    const counters = document.querySelectorAll('[data-count]');
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                animateCounter(entry.target);
                counterObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    counters.forEach(el => counterObserver.observe(el));

    function animateCounter(el) {
        const target = parseInt(el.getAttribute('data-count'), 10);
        const suffix = el.getAttribute('data-suffix') || '';
        const duration = 1800;
        const step = target / (duration / 16);
        let current = 0;
        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            el.textContent = Math.floor(current).toLocaleString() + suffix;
        }, 16);
    }

    // ──────────── PACKAGE CARD TILT EFFECT ────────────
    document.querySelectorAll('.pkg-preview-card').forEach(card => {
        card.addEventListener('mousemove', (e) => {
            const rect = card.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width  - 0.5) * 10;
            const y = ((e.clientY - rect.top)  / rect.height - 0.5) * -10;
            card.style.transform = `perspective(800px) rotateX(${y}deg) rotateY(${x}deg) translateY(-6px)`;
        });
        card.addEventListener('mouseleave', () => {
            card.style.transform = '';
        });
    });
});
