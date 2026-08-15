document.addEventListener('DOMContentLoaded', () => {
    // 1. Lenis Smooth Scrolling (momentum scroll like igloo.org)
    if (typeof Lenis !== 'undefined') {
        const lenis = new Lenis({
            duration: 1.2,
            easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)), // Custom easing for smooth stop
            direction: 'vertical',
            gestureDirection: 'vertical',
            smooth: true,
            mouseMultiplier: 1,
            smoothTouch: false,
            touchMultiplier: 2,
            infinite: false,
        });

        function raf(time) {
            lenis.raf(time);
            requestAnimationFrame(raf);
        }

        requestAnimationFrame(raf);
        
        // Connect Lenis scroll
        lenis.on('scroll', () => {
            // Lenis smooth scroll active
        });

        // Smooth scroll navigation clicks
        const navLinks = document.querySelectorAll('.nav-link, .logo-link');
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                const targetId = link.getAttribute('href');
                if (targetId && targetId.startsWith('#')) {
                    e.preventDefault();
                    const targetEl = document.querySelector(targetId);
                    if (targetEl) {
                        lenis.scrollTo(targetEl, {
                            offset: -80, // Offset for the fixed header height
                            duration: 1.2,
                            easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
                        });
                    }
                }
            });
        });
    }

    // 2. Live Countdown Timer
    const countdownEl = document.getElementById('countdown');
    if (countdownEl) {
        const targetDateStr = countdownEl.getAttribute('data-target-date');
        const targetDate = targetDateStr ? new Date(targetDateStr).getTime() : new Date('2027-05-04T09:00:00').getTime();
        
        const daysVal = document.getElementById('days-val');
        const hoursVal = document.getElementById('hours-val');
        const minutesVal = document.getElementById('minutes-val');
        const secondsVal = document.getElementById('seconds-val');
        
        function updateCountdown() {
            const now = new Date().getTime();
            const distance = targetDate - now;
            
            if (distance < 0) {
                countdownEl.className = 'countdown-container-live';
                countdownEl.innerHTML = `<div class="event-live-banner"><span class="live-dot-pulse"></span><span class="live-text-glow">Event Live!</span></div>`;
                return;
            }
            
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            if (daysVal) daysVal.innerText = String(days).padStart(2, '0');
            if (hoursVal) hoursVal.innerText = String(hours).padStart(2, '0');
            if (minutesVal) minutesVal.innerText = String(minutes).padStart(2, '0');
            if (secondsVal) secondsVal.innerText = String(seconds).padStart(2, '0');
        }
        
        updateCountdown();
        setInterval(updateCountdown, 1000);
    }

    // 2.5. Typewriter & Backspace Catchphrase Rotator
    const typewriterEl = document.getElementById('typewriter-text');
    if (typewriterEl) {
        const phrases = [
            "where talent finds its stage.",
            "One stage, endless talent welcome to Al-Thanafus",
            "Qur'an Recitation, Oratory, and Literary contests.",
            "a grand gathering of creative and intellectual minds.",
            "fostering academic leadership and spiritual growth.",
            "where knowledge meets art on a grand stage.",
            "nurturing talent, inspiring generations of scholars.",
            "witnessing the clash of intellects and stage skills.",
            "an annual festival of creativity, devotion, and style.",
            "empowering students to excel in extracurricular fields.",
            "where knowledge, art, and character unite."
        ];
        
        let phraseIndex = 0;
        let charIndex = 0;
        let isDeleting = false;
        let typingSpeed = 80;
        
        function typeEffect() {
            const currentPhrase = phrases[phraseIndex];
            
            if (isDeleting) {
                typewriterEl.innerText = currentPhrase.substring(0, charIndex - 1);
                charIndex--;
                typingSpeed = 40;
            } else {
                typewriterEl.innerText = currentPhrase.substring(0, charIndex + 1);
                charIndex++;
                typingSpeed = 80;
            }
            
            if (!isDeleting && charIndex === currentPhrase.length) {
                isDeleting = true;
                typingSpeed = 2000;
            } else if (isDeleting && charIndex === 0) {
                isDeleting = false;
                phraseIndex = (phraseIndex + 1) % phrases.length;
                typingSpeed = 500;
            }
            
            setTimeout(typeEffect, typingSpeed);
        }
        
        setTimeout(typeEffect, 1000);
    }
    
    // 3. Celebrate Button Confetti Effect
    const celebrateBtn = document.getElementById('celebrate-btn');
    if (celebrateBtn) {
        const emojis = ['🎉', '✨', '🎈', '🥳', '🏆', '⭐', '🌈', '🎊'];
        
        celebrateBtn.addEventListener('click', (e) => {
            for (let i = 0; i < 20; i++) {
                createParticle(e.clientX, e.clientY);
            }
        });
        
        function createParticle(x, y) {
            const particle = document.createElement('div');
            particle.classList.add('celebrate-particle');
            particle.innerText = emojis[Math.floor(Math.random() * emojis.length)];
            
            const angle = Math.random() * Math.PI * 2;
            const velocity = 40 + Math.random() * 120;
            const targetX = Math.cos(angle) * velocity;
            const targetY = Math.sin(angle) * velocity - 80;
            const rotation = Math.random() * 360;
            
            particle.style.left = `${x}px`;
            particle.style.top = `${y}px`;
            particle.style.setProperty('--x', `${targetX}px`);
            particle.style.setProperty('--y', `${targetY}px`);
            particle.style.setProperty('--r', `${rotation}deg`);
            
            document.body.appendChild(particle);
            
            setTimeout(() => {
                particle.remove();
            }, 1000);
        }
    }
    
    // 4. High-Performance IntersectionObserver Scroll Reveal
    const revealElements = document.querySelectorAll('.reveal-3d');
    const isMobileViewport = window.innerWidth <= 991;
    
    const grids = document.querySelectorAll('.stats-grid, .scholars-grid, .categories-grid, .stages-grid, .articles-grid');
    grids.forEach(grid => {
        const children = grid.querySelectorAll('.reveal-3d');
        children.forEach((child, index) => {
            if (!isMobileViewport) {
                child.style.transitionDelay = `${index * 0.08}s`;
            }
        });
    });

    if (isMobileViewport) {
        revealElements.forEach(el => el.classList.add('visible'));
    } else if ('IntersectionObserver' in window) {
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, {
            root: null,
            rootMargin: '0px 0px -30px 0px',
            threshold: 0.05
        });

        revealElements.forEach(el => revealObserver.observe(el));
    } else {
        revealElements.forEach(el => el.classList.add('visible'));
    }

    // 5. Mobile Menu Toggle
    const menuToggle = document.querySelector('.menu-toggle-btn');
    const navMenu = document.querySelector('.nav-menu');
    
    if (menuToggle && navMenu) {
        const toggleMenu = () => {
            const isOpen = navMenu.classList.toggle('open');
            menuToggle.classList.toggle('open', isOpen);
            menuToggle.setAttribute('aria-expanded', String(isOpen));
        };
        
        const closeMenu = () => {
            navMenu.classList.remove('open');
            menuToggle.classList.remove('open');
            menuToggle.setAttribute('aria-expanded', 'false');
        };
        
        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleMenu();
        });
        
        // Close menu on link clicks
        const menuLinks = navMenu.querySelectorAll('a');
        menuLinks.forEach(link => {
            link.addEventListener('click', closeMenu);
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!navMenu.contains(e.target) && !menuToggle.contains(e.target)) {
                closeMenu();
            }
        });

        // Close menu on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeMenu();
            }
        });
    }

    // 6. Live Website Update Checker (OTA)
    const CURRENT_VERSION = '1.0.0';
    let checkInterval = null;

    function checkForUpdates() {
        const isMobileAppSubdir = window.location.pathname.includes('/mobile-app');
        const url = isMobileAppSubdir ? '../uploads/app-web-version.json' : 'uploads/app-web-version.json';
        
        fetch(url + '?t=' + Date.now())
            .then(response => {
                if (response.ok) return response.json();
                throw new Error('Version file not found');
            })
            .then(data => {
                if (data && data.version && data.version !== CURRENT_VERSION) {
                    showUpdatePopup();
                    clearInterval(checkInterval);
                }
            })
            .catch(error => console.warn('Update check bypassed:', error.message));
    }

    function showUpdatePopup() {
        if (document.getElementById('web-update-banner')) return;

        const popup = document.createElement('div');
        popup.id = 'web-update-banner';
        popup.innerHTML = `
            <div class="update-banner-content">
                <div class="update-banner-icon"><i class="fa-solid fa-cloud-arrow-down"></i></div>
                <div class="update-banner-text">
                    <strong>Update Found!</strong>
                    <span>New layouts & standings are available.</span>
                </div>
                <button id="web-update-btn" class="update-banner-btn">Update Now</button>
            </div>
        `;
        
        const style = document.createElement('style');
        style.innerHTML = `
            #web-update-banner {
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%) translateY(120px);
                width: 90%;
                max-width: 400px;
                background: #ffffff;
                border: 1px solid rgba(27, 67, 50, 0.15);
                box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
                border-radius: 16px;
                padding: 16px;
                z-index: 999999;
                transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            }
            #web-update-banner.show {
                transform: translateX(-50%) translateY(0);
            }
            .update-banner-content {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .update-banner-icon {
                width: 40px;
                height: 40px;
                border-radius: 10px;
                background: rgba(27, 67, 50, 0.08);
                color: #1b4332;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                flex-shrink: 0;
            }
            .update-banner-text {
                display: flex;
                flex-direction: column;
                gap: 2px;
                flex-grow: 1;
                font-size: 12px;
                color: #5b665c;
                text-align: left;
            }
            .update-banner-text strong {
                font-size: 14px;
                font-weight: 700;
                color: #1b4332;
            }
            .update-banner-btn {
                background: #1b4332;
                color: #ffffff;
                border: none;
                padding: 8px 14px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
                transition: background 0.2s;
                white-space: nowrap;
            }
            .update-banner-btn:hover {
                background: #2d6a4f;
            }
        `;
        
        document.body.appendChild(style);
        document.body.appendChild(popup);
        
        setTimeout(() => popup.classList.add('show'), 150);
        
        document.getElementById('web-update-btn').addEventListener('click', () => {
            const btn = document.getElementById('web-update-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            setTimeout(() => {
                window.location.reload(true);
            }, 800);
        });
    }

    // Start checking for updates after 5s, then check every 30s (ONLY if inside the mobile app)
    const isMobileApp = !!window.Capacitor || 
                        window.location.search.includes('platform=app') || 
                        navigator.userAgent.includes('Capacitor');

    if (isMobileApp) {
        setTimeout(() => {
            checkForUpdates();
            checkInterval = setInterval(checkForUpdates, 30000);
        }, 5000);
    }
});
