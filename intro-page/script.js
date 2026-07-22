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
        
        // Connect Lenis scroll to reveal updates
        lenis.on('scroll', () => {
            revealOnScroll();
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
                            duration: 1.4,
                            easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)), // smooth ease out
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
                if (daysVal) daysVal.innerText = '00';
                if (hoursVal) hoursVal.innerText = '00';
                if (minutesVal) minutesVal.innerText = '00';
                if (secondsVal) secondsVal.innerText = '00';
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
            "celebrating Islamic arts and literature.",
            "bridging classical traditions with modern expressions.",
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
                typingSpeed = 40; // Faster deleting speed
            } else {
                typewriterEl.innerText = currentPhrase.substring(0, charIndex + 1);
                charIndex++;
                typingSpeed = 80; // Typing speed
            }
            
            if (!isDeleting && charIndex === currentPhrase.length) {
                isDeleting = true;
                typingSpeed = 2000; // Pause at full word
            } else if (isDeleting && charIndex === 0) {
                isDeleting = false;
                phraseIndex = (phraseIndex + 1) % phrases.length;
                typingSpeed = 500; // Pause before typing next word
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
            for (let i = 0; i < 30; i++) {
                createParticle(e.clientX, e.clientY);
            }
        });
        
        function createParticle(x, y) {
            const particle = document.createElement('div');
            particle.classList.add('celebrate-particle');
            particle.innerText = emojis[Math.floor(Math.random() * emojis.length)];
            
            const angle = Math.random() * Math.PI * 2;
            const velocity = 50 + Math.random() * 150;
            const targetX = Math.cos(angle) * velocity;
            const targetY = Math.sin(angle) * velocity - 100;
            const rotation = Math.random() * 360;
            
            particle.style.left = `${x}px`;
            particle.style.top = `${y}px`;
            particle.style.setProperty('--x', `${targetX}px`);
            particle.style.setProperty('--y', `${targetY}px`);
            particle.style.setProperty('--r', `${rotation}deg`);
            
            document.body.appendChild(particle);
            
            setTimeout(() => {
                particle.remove();
            }, 1200);
        }
    }
    
    // 4. Staggered 3D Scroll Reveal Animation (Intersection Observer)
    const revealElements = document.querySelectorAll('.reveal-3d');
    
    // Configure delay helper for child grids
    const grids = document.querySelectorAll('.stats-grid, .scholars-grid, .categories-grid, .stages-grid, .articles-grid');
    grids.forEach(grid => {
        const children = grid.querySelectorAll('.reveal-3d');
        children.forEach((child, index) => {
            child.style.transitionDelay = `${index * 0.15}s`;
        });
    });

    const revealOnScroll = () => {
        const triggerBottom = window.innerHeight * 0.95;
        const triggerTop = 20;
        revealElements.forEach(el => {
            const rect = el.getBoundingClientRect();
            if (rect.top < triggerBottom && rect.bottom > triggerTop) {
                el.classList.add('visible');
            } else {
                el.classList.remove('visible'); // Fade and slide out when out of view
            }
        });
    };
    
    window.addEventListener('scroll', revealOnScroll);
    revealOnScroll(); // Trigger initial check
});
