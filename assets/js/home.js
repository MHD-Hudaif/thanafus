document.body.classList.add('is-intro');

const backgrounds = document.querySelectorAll('.bg-image');
let currentBg = 0;

if (backgrounds.length > 1) {
  setInterval(() => {
    backgrounds[currentBg].classList.remove('active');
    currentBg = (currentBg + 1) % backgrounds.length;
    backgrounds[currentBg].classList.add('active');
  }, 9000);
}

// Particles disabled
let particlesMaterial = null;

const hasGsap = Boolean(window.gsap);
let skipped = false;

function revealHome() {
  document.body.classList.remove('is-intro');
  document.body.classList.add('is-live');
}

function finishIntro(immediate = false) {
  revealHome();

  if (!hasGsap) {
    document.getElementById('intro')?.style.setProperty('display', 'none');
    document.getElementById('skipBtn')?.style.setProperty('display', 'none');
    document.getElementById('home')?.style.setProperty('opacity', '1');
    document.getElementById('header')?.style.setProperty('opacity', '1');
    document.getElementById('headerLogo')?.style.setProperty('opacity', '1');
    document.querySelector('.hero-content')?.style.setProperty('opacity', '1');
    document.querySelector('.hero-content')?.style.setProperty('transform', 'none');
    return;
  }

  const duration = immediate ? 0 : 0.9;
  gsap.to('#intro', { opacity: 0, pointerEvents: 'none', duration });
  gsap.to('#skipBtn', { opacity: 0, pointerEvents: 'none', duration: immediate ? 0 : 0.35 });
  gsap.to('#home', { opacity: 1, duration: immediate ? 0 : 1.1 });
  gsap.to('#header', { opacity: 1, y: 0, duration: immediate ? 0 : 0.9, delay: immediate ? 0 : 0.2 });
  gsap.to('#headerLogo', { opacity: 1, scale: 1, duration: immediate ? 0 : 0.45, delay: immediate ? 0 : 0.35 });
  gsap.to('.hero-content', { opacity: 1, y: 0, duration: immediate ? 0 : 1, delay: immediate ? 0 : 0.45, ease: 'power3.out' });

  if (particlesMaterial) {
    gsap.to(particlesMaterial, { opacity: 0.72, duration: immediate ? 0 : 1 });
  }
}

if (hasGsap) {
  gsap.set('#home', { opacity: 0 });
  gsap.set('#header', { opacity: 0, y: -18 });
  gsap.set('#headerLogo', { opacity: 0, scale: 0.86 });
  gsap.set('.hero-content', { opacity: 0, y: 24 });
  gsap.set('#skipBtn', { opacity: 0 });

  const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });

  tl.to('#skipBtn', { opacity: 1, duration: 0.5 }, 0.7)
    .to('.intro-ring', { opacity: 1, scale: 1, stagger: 0.14, duration: 1.2 }, 0.1)
    .fromTo('.intro-ring-one', { scale: 0.72, rotation: -18 }, { scale: 1, rotation: 0, duration: 1.4 }, 0.1)
    .fromTo('.intro-ring-two', { scale: 0.62, rotation: 24 }, { scale: 1, rotation: 0, duration: 1.55 }, 0.2);

  if (particlesMaterial) {
    tl.to(particlesMaterial, { opacity: 0.88, duration: 1.15 }, 0.25);
  }

  tl.to('#kauzariyyaLogo', { opacity: 1, scale: 1, duration: 1.55 }, 0.35)
    .to('#introCopy', { opacity: 1, y: 0, duration: 1 }, 1.05)
    .to('#kauzariyyaLogo', { opacity: 0, scale: 1.08, filter: 'blur(6px)', duration: 0.9, ease: 'power2.inOut' }, 2.85)
    .to('#introCopy', { opacity: 0, y: -18, duration: 0.7, ease: 'power2.inOut' }, 2.95)
    .to('#thanafusWrapper', { opacity: 1, scale: 1, duration: 0.72 }, 3.35)
    .to('#logoGlow', { opacity: 1, scale: 1.12, duration: 0.9 }, 3.35)
    .to('#thanafusLogo', { opacity: 1, duration: 0.42 }, 3.58)
    .fromTo('#logoLight', { opacity: 0, left: '-42%' }, { opacity: 1, left: '128%', duration: 1.55, ease: 'power2.inOut' }, 3.52)
    .to('#logoLight', { opacity: 0, duration: 0.32 }, 4.8)
    .to('#thanafusWrapper', { scale: 1.08, duration: 0.55, yoyo: true, repeat: 1, ease: 'sine.inOut' }, 4.86)
    .call(() => finishIntro(false), null, 5.42);
} else {
  finishIntro(true);
}

function skipIntro() {
  if (skipped) return;
  skipped = true;
  if (hasGsap) {
    gsap.globalTimeline.clear();
  }
  finishIntro(true);
}

document.getElementById('skipBtn')?.addEventListener('click', skipIntro);

// Scroll Triggered Staggered Animations using IntersectionObserver + GSAP
if (hasGsap && 'IntersectionObserver' in window) {
  const options = {
    root: null,
    threshold: 0.1,
    rootMargin: '0px 0px -60px 0px'
  };

  const observer = new IntersectionObserver((entries, observerInstance) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const el = entry.target;
        
        if (el.id === 'about') {
          gsap.fromTo(el.querySelector('.text-panel'), 
            { opacity: 0, x: -40 },
            { opacity: 1, x: 0, duration: 0.85, ease: 'power3.out' }
          );
          gsap.fromTo(el.querySelector('.welcome-panel'), 
            { opacity: 0, x: 40 },
            { opacity: 1, x: 0, duration: 0.85, ease: 'power3.out', delay: 0.15 }
          );
        } else if (el.id === 'vision') {
          gsap.fromTo(el.querySelector('.section-heading'), 
            { opacity: 0, y: 30 },
            { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }
          );
          gsap.fromTo(el.querySelectorAll('.mission-grid article'), 
            { opacity: 0, y: 40 },
            { opacity: 1, y: 0, duration: 0.8, stagger: 0.16, ease: 'power3.out', delay: 0.2 }
          );
        } else if (el.id === 'events') {
          gsap.fromTo(el.querySelector('.section-heading'), 
            { opacity: 0, y: 30 },
            { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }
          );
          gsap.fromTo(el.querySelectorAll('.highlight-card'), 
            { opacity: 0, y: 50, scale: 0.94 },
            { opacity: 1, y: 0, scale: 1, duration: 0.8, stagger: 0.1, ease: 'power3.out', delay: 0.2 }
          );
        } else if (el.id === 'live') {
          gsap.fromTo(el.querySelector('.section-heading'), 
            { opacity: 0, y: 30 },
            { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }
          );
          gsap.fromTo(el.querySelectorAll('.feature-pill'), 
            { opacity: 0, y: 20, scale: 0.92 },
            { opacity: 1, y: 0, scale: 1, duration: 0.65, stagger: 0.05, ease: 'back.out(1.4)', delay: 0.18 }
          );
        }
        
        observerInstance.unobserve(el);
      }
    });
  }, options);

  // Configure initial state of target sections and start observing
  document.querySelectorAll('.content-section').forEach(section => {
    if (section.id === 'about') {
      gsap.set([section.querySelector('.text-panel'), section.querySelector('.welcome-panel')], { opacity: 0 });
    } else if (section.id === 'vision') {
      gsap.set([section.querySelector('.section-heading'), ...section.querySelectorAll('.mission-grid article')], { opacity: 0 });
    } else if (section.id === 'events') {
      gsap.set([section.querySelector('.section-heading'), ...section.querySelectorAll('.highlight-card')], { opacity: 0 });
    } else if (section.id === 'live') {
      gsap.set([section.querySelector('.section-heading'), ...section.querySelectorAll('.feature-pill')], { opacity: 0 });
    }
    observer.observe(section);
  });
}
