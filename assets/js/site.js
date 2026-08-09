(() => {
  document.documentElement.classList.add('js-ready');

  const header = document.querySelector('.site-header');
  const setHeaderState = () => header?.classList.toggle('is-scrolled', window.scrollY > 12);
  setHeaderState();
  window.addEventListener('scroll', setHeaderState, { passive: true });

  const menu = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.site-nav');
  const closeMenu = () => {
    nav?.classList.remove('open');
    menu?.setAttribute('aria-expanded', 'false');
  };
  menu?.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    menu.setAttribute('aria-expanded', String(open));
  });
  nav?.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
  document.addEventListener('click', event => {
    if (!nav?.classList.contains('open')) return;
    if (nav.contains(event.target) || menu?.contains(event.target)) return;
    closeMenu();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      closeMenu();
      menu?.focus();
    }
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth >= 1100) closeMenu();
  }, { passive: true });

  const routeLinks = [...document.querySelectorAll('.site-nav a[href^="#"]')];
  const routeSections = routeLinks
    .map(link => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);
  if (routeLinks.length && routeSections.length) {
    const setActiveRoute = () => {
      const current = routeSections
        .filter(section => section.getBoundingClientRect().top <= 130)
        .at(-1) || routeSections[0];
      routeLinks.forEach(link => link.classList.toggle('active', link.getAttribute('href') === `#${current.id}`));
    };
    setActiveRoute();
    window.addEventListener('scroll', setActiveRoute, { passive: true });
  }

  if (window.matchMedia('(pointer:fine)').matches) {
    document.querySelectorAll('.magnetic').forEach(item => {
      item.addEventListener('pointermove', event => {
        const rect = item.getBoundingClientRect();
        const x = (event.clientX - rect.left - rect.width / 2) * .12;
        const y = (event.clientY - rect.top - rect.height / 2) * .18;
        item.style.transform = `translate(${x}px, ${y}px) translateY(-3px)`;
      });
      item.addEventListener('pointerleave', () => {
        item.style.transform = '';
      });
    });
  }

  const gate = document.querySelector('[data-intro]');
  if (gate) {
    const video = gate.querySelector('video');
    const skipForPreview = new URLSearchParams(window.location.search).has('nointro');
    const close = () => {
      gate.classList.add('is-hidden');
      sessionStorage.setItem('kauzariyya-intro', 'seen');
      window.setTimeout(() => gate.remove(), 850);
    };
    if (skipForPreview || sessionStorage.getItem('kauzariyya-intro') === 'seen') gate.remove();
    else {
      gate.querySelector('.skip-intro')?.addEventListener('click', close);
      video?.addEventListener('ended', close);
      video?.addEventListener('error', () => window.setTimeout(close, 1200));
      window.setTimeout(close, 9000);
    }
  }

  const observer = new IntersectionObserver(entries => entries.forEach(entry => {
    if (entry.isIntersecting) {
      const index = [...document.querySelectorAll('.reveal')].indexOf(entry.target);
      entry.target.style.transitionDelay = `${Math.min(index % 4, 3) * 70}ms`;
      entry.target.classList.add('visible');
      observer.unobserve(entry.target);
    }
  }), { threshold: .12 });
  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

  document.querySelectorAll('[data-score]').forEach(el => {
    const target = Number(el.dataset.score);
    let start;
    const animate = time => {
      start ??= time;
      const progress = Math.min((time - start) / 1200, 1);
      el.textContent = String(Math.round(target * (1 - Math.pow(1 - progress, 3))));
      if (progress < 1) requestAnimationFrame(animate);
    };
    requestAnimationFrame(animate);
  });

  const setClock = () => document.querySelectorAll('[data-time]').forEach(el => {
    el.textContent = new Intl.DateTimeFormat('en-GB', { hour:'2-digit', minute:'2-digit', hour12:false }).format(new Date());
  });
  setClock(); window.setInterval(setClock, 30000);

  const setUpdated = () => document.querySelectorAll('[data-clock]').forEach(el => {
    el.textContent = new Intl.DateTimeFormat('en-GB', { hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false }).format(new Date());
  });
  setUpdated(); window.setInterval(setUpdated, 15000);

  const search = document.querySelector('.participant-search input');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    document.querySelectorAll('.participant-row').forEach(row => row.hidden = !row.dataset.name.includes(query));
  });

  const speakerCard = document.querySelector('.speaker-card');
  document.querySelectorAll('.participant-row').forEach(row => row.addEventListener('click', () => {
    document.querySelectorAll('.participant-row').forEach(item => item.classList.remove('active'));
    row.classList.add('active');
    const name = row.querySelector('strong')?.textContent;
    const details = row.querySelector('small')?.textContent;
    const time = row.querySelector('time')?.textContent;
    if (!speakerCard || !name || !details || !time) return;
    speakerCard.classList.remove('updated');
    void speakerCard.offsetWidth;
    speakerCard.classList.add('updated');
    speakerCard.querySelector('h2').textContent = name;
    speakerCard.querySelector('footer strong').textContent = time;
    speakerCard.querySelector('footer span').textContent = details;
  }));

  const tabs = [...document.querySelectorAll('[data-session]')];
  const columns = [...document.querySelectorAll('[data-session-column]')];
  const choose = session => {
    tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.session === session));
    columns.forEach(column => column.classList.toggle('mobile-active', column.dataset.sessionColumn === session));
  };
  tabs.forEach(tab => tab.addEventListener('click', () => choose(tab.dataset.session)));
  const initialActive = tabs.find(t => t.classList.contains('active')) || tabs[0];
  if (initialActive) choose(initialActive.dataset.session);

  // Background Live Scoreboard Auto-Polling
  const scoreboardEl = document.querySelector('[data-refresh="scoreboard"]');
  if (scoreboardEl) {
    const pollScores = async () => {
      if (document.hidden) return; // Pause polling when tab is inactive
      try {
        const base = window.APP_BASE_URL || '/';
        const response = await fetch(`${base}tv/api/leaderboard.php`, { cache: 'no-cache' });
        if (response.status === 304) return; // Not modified
        const res = await response.json();
        if (!res.success || !Array.isArray(res.data?.leaderboard)) return;

        const leaderboard = res.data.leaderboard;
        const maxScore = Math.max(...leaderboard.map(t => Number(t.total_score || 0)), 1);
        
        const rows = document.querySelectorAll('.standing-row');
        leaderboard.forEach((team, idx) => {
          if (rows[idx]) {
            const nameEl = rows[idx].querySelector('h2');
            const scoreEl = rows[idx].querySelector('strong');
            const barEl = rows[idx].querySelector('span i');
            if (nameEl) nameEl.textContent = team.team_name;
            if (scoreEl) scoreEl.textContent = Math.round(Number(team.total_score || 0));
            if (barEl) barEl.style.width = `${(Number(team.total_score || 0) / maxScore) * 100}%`;
          }
        });
      } catch (e) {
        // Suppress polling errors silently
      }
    };
    window.setInterval(pollScores, 10000);
  }

  // Site-Wide Zero Refresh SPA Link & Form Interception
  if (typeof window.navigateTo === 'undefined') {
    function isSpaUrl(url) {
      try {
        const u = new URL(url, location.origin);
        if (u.origin !== location.origin) return false;
        if (u.pathname.startsWith('/uploads/')) return false;
        if (u.pathname.startsWith('/assets/')) return false;
        if (u.pathname.includes('/live-display/dashboard.php')) return false;
        if (u.pathname.includes('/live-display/index.php')) return false;
        if (u.pathname.includes('/live-display/pages/')) return false;
        if (u.searchParams.get('action') === 'logout') return false;
        return true;
      } catch { return false; }
    }

    async function navigateSpa(url, pushState = true) {
      try {
        const resp = await fetch(url, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        if (resp.redirected && !isSpaUrl(resp.url)) {
          location.href = resp.url;
          return;
        }
        const contentType = resp.headers.get('Content-Type') || '';
        if (contentType.includes('application/json')) {
          const data = await resp.json();
          if (data.redirect) return navigateSpa(data.redirect, pushState);
        }
        const html = await resp.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        let container = document.querySelector('main, .main-content, #mainAppShell, .site-content, body');
        let newContainer = doc.querySelector('main, .main-content, #mainAppShell, .site-content, body');
        if (container && newContainer) {
          container.innerHTML = newContainer.innerHTML;
          doc.querySelectorAll('script').forEach(s => {
            if (!s.src && s.textContent) {
              try { new Function(s.textContent)(); } catch(e){}
            }
          });
          if (pushState) history.pushState({ spaUrl: url }, '', url);
          window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
          location.href = url;
        }
      } catch(e) {
        location.href = url;
      }
    }

    window.navigateTo = navigateSpa;

    document.addEventListener('click', (e) => {
      const a = e.target.closest('a');
      if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-ajax-ignore')) return;
      if (a.getAttribute('href')?.startsWith('#')) return;
      if (!isSpaUrl(a.href)) return;
      e.preventDefault();
      navigateSpa(a.href);
    });

    document.addEventListener('submit', async (e) => {
      if (e.defaultPrevented) return;
      const form = e.target.closest('form');
      if (!form || form.hasAttribute('data-ajax-ignore')) return;
      const action = form.action || location.href;
      if (!isSpaUrl(action)) return;
      e.preventDefault();
      const method = (form.method || 'GET').toUpperCase();
      try {
        let url = action;
        let options = { method, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };
        if (method === 'POST') {
          options.body = new FormData(form);
        } else {
          const params = new URLSearchParams(new FormData(form));
          url += (url.includes('?') ? '&' : '?') + params.toString();
          return navigateSpa(url);
        }
        const resp = await fetch(url, options);
        const contentType = resp.headers.get('Content-Type') || '';
        if (contentType.includes('application/json')) {
          const data = await resp.json();
          if (data.redirect) return navigateSpa(data.redirect);
        }
        const html = await resp.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        let container = document.querySelector('main, .main-content, #mainAppShell, .site-content, body');
        let newContainer = doc.querySelector('main, .main-content, #mainAppShell, .site-content, body');
        if (container && newContainer) {
          container.innerHTML = newContainer.innerHTML;
        } else {
          location.href = resp.url || action;
        }
      } catch(err) {
        form.submit();
      }
    });

    window.addEventListener('popstate', (e) => {
      if (e.state && e.state.spaUrl) {
        navigateSpa(e.state.spaUrl, false);
      }
    });
  }
})();
