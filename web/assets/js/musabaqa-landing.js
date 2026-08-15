/* ==========================================================================
   AL JAMIATHUL KAUZARIYYA - LANDING PAGE LOGIC (VANILLA JS)
   ========================================================================== */

document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    // 1. Ensure Background Video Autoplays smoothly
    const bgVideo = document.getElementById('bgVideo');
    if (bgVideo) {
        bgVideo.muted = true;
        bgVideo.play().catch(err => {
            console.warn('Autoplay prevented by browser, fallback active:', err);
        });
    }

    // 2. Scroll Reveal Animations for Cards & Sections
    const observerOptions = {
        root: null,
        rootMargin: '0px 0px -40px 0px',
        threshold: 0.1
    };

    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                revealObserver.unobserve(entry.target);
            }
        });
    }, observerOptions);

    const revealElements = document.querySelectorAll('.section-wrap, .feature-card, .schedule-3d-card, .home-event-highlights article');
    revealElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(24px)';
        el.style.transition = 'opacity 0.65s cubic-bezier(0.16, 1, 0.3, 1), transform 0.65s cubic-bezier(0.16, 1, 0.3, 1)';
        revealObserver.observe(el);
    });

    // Custom CSS class for revealed elements
    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        .visible {
            opacity: 1 !important;
            transform: translateY(0) !important;
        }
    `;
    document.head.appendChild(styleSheet);

    // 3. Header Navigation Link Highlighting
    const navLinks = document.querySelectorAll('.site-nav a');
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            navLinks.forEach(l => l.classList.remove('active'));
            link.classList.add('active');
        });
    });
});
