/* Dynamic Musabaqa Front-End — Database-Connected Renderer */
(() => {
  'use strict';

  const data = window.APP_DATA || {};
  const cfg  = window.APP_CONFIG || {};
  const root = document.getElementById('react-root');
  if (!root) return;

  /* ── helpers ─────────────────────────────────────────── */
  const asset = (path) => (cfg.assetUrl || 'assets') + '/' + path;

  const pageNames = {
    home: 'Home',
    scoreboard: 'Scoreboard',
    schedule: 'Schedule',
    review: 'Review'
  };

  const icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5Zm-6 9v5l6 4 6-4v-5l-6 3-6-3Z"/></svg>';

  /* ── router ──────────────────────────────────────────── */
  const pageBgs = {
    home: data.storyImage || asset('images/kauzariyya13.png'),
    scoreboard: data.campusImages?.[0] || asset('images/kauzariyya1.png'),
    schedule: data.campusImages?.[1] || asset('images/kauzariyya2.png'),
    review: data.storyImage || asset('images/kauzariyya13.png')
  };

  function setPage(page) {
    document.body.className = page === 'plan' ? 'page-musabaqa' : `page-${page}`;
    
    // Dynamically update background campus image
    const bgImg = document.querySelector('.home-video-bg img');
    if (bgImg) {
      bgImg.src = pageBgs[page] || pageBgs.home;
    }

    root.innerHTML = header(page) + (views[page] || views.home)() + mobileNav(page);
    if (window.lenis) {
      window.lenis.scrollTo(0, { immediate: true });
    } else {
      window.scrollTo({ top: 0, behavior: 'instant' });
    }
    bindPage(page);
  }

  function navLink(page, current) {
    return `<a href="#${page}" class="${page === current ? 'active' : ''}" data-page="${page}">${pageNames[page]}</a>`;
  }

  /* ── header ──────────────────────────────────────────── */
  function header(page) {
    const user = data.user || {};
    const liveDisplayHTML = `<a href="live-display-mobile.php" class="visitor-logout visitor-logout-floating" style="text-decoration:none;background:#2563eb;color:#fff;border:none;margin-right:6px">Live Display</a>`;
    const authHTML = user.isLoggedIn
      ? `${liveDisplayHTML}
         <a href="${user.adminUrl || '#'}" class="visitor-logout visitor-logout-floating" style="text-decoration:none;background:#059669;color:#fff;border:none;margin-right:6px">Dashboard</a>
         <a href="${user.logoutUrl || '#'}" class="visitor-logout visitor-logout-floating" style="text-decoration:none">Sign out</a>`
      : `${liveDisplayHTML}
         <a href="${user.loginUrl || '#'}" class="visitor-logout visitor-logout-floating" style="text-decoration:none">Sign in</a>`;

    return `<header class="site-header">
      <a class="site-logo" href="#home" data-page="home">
        <img src="${asset('images/kauzariyya-logo.png')}" alt="Kauzariyya">
        <span><b>Al-Jamiathul Kauzariyya</b><small>Internal Management System</small></span>
      </a>
      <nav class="site-nav" aria-label="Main navigation">${Object.keys(pageNames).map(k => navLink(k, page)).join('')}</nav>
      <div class="header-account-actions">
        <div class="festival-date"><span>Festival date</span><b>${data.activeEvent?.startDate || '18 August 2026'}</b></div>
        ${authHTML}
      </div>
    </header>`;
  }

  /* ── mobile nav ──────────────────────────────────────── */
  function mobileNav(page) {
    return `<nav class="mobile-tabbar" aria-label="Mobile navigation">${Object.keys(pageNames).map(k => `<a href="#${k}" data-page="${k}" class="${k === page ? 'active' : ''}"><span class="tab-glyph-svg">${icon}</span><span>${pageNames[k]}</span></a>`).join('')}</nav>`;
  }

  /* ══════════════════════════════════════════════════════
     VIEWS
     ══════════════════════════════════════════════════════ */
  const ev = () => data.activeEvent || {};

  const views = {

    /* ── HOME ────────────────────────────────────────── */
    home: () => `<div class="home-redesign">

      <section class="home-redesign-hero">
        <div class="home-redesign-copy reveal visible">
          <div class="home-visitor-greeting home-visitor-greeting-card">
            <span class="greeting-live-dot"></span>
            <span class="greeting-label">Welcome,&nbsp;</span>
            <strong class="greeting-user-name">${data.user?.name || 'GUEST'}</strong>
          </div>
          <p class="home-hero-eyebrow">Faith · Knowledge · Creativity</p>
          <h1>Kauzariyya<br><em>Arts Festival.</em></h1>
          <p class="home-hero-intro">A celebration where students discover their voice, share their talent and grow through meaningful competition.</p>
        </div>
      </section>

      <section class="home-platform-statement section-wrap reveal visible">
        <p>Competing in excellence, growing in knowledge, and standing together in faith. The official digital platform for Kauzariyya&rsquo;s student competitions, live scores, schedules, teams and results.</p>
        <strong>Excellence through knowledge <i></i> Unity through faith <i></i> Success through sincerity</strong>
      </section>

      <section class="home-musabaqa-about section-wrap reveal visible">
        <article class="home-about-copy">
          <p class="overline">About the Musabaqa</p>
          <h2>A stage for knowledge, discipline and sincere competition.</h2>
          <p>The Kauzariyya Musabaqa is an annual academic and Islamic competition organized by Al Jamiathul Kauzariyya Arabic College. It celebrates excellence in Qur&rsquo;an, Hadith, Arabic, Islamic studies, speeches and literature.</p>
          <p>More than a competition, it strengthens brotherhood, confidence, discipline and character.</p>
        </article>
        <aside class="home-musabaqa-prayer">
          <span class="bismillah-mark" lang="ar" dir="rtl">\u0628\u0650\u0633\u0652\u0645\u0650 \u0627\u0644\u0644\u0647\u0650 \u0627\u0644\u0631\u0651\u064E\u062D\u0652\u0645\u064E\u0646\u0650 \u0627\u0644\u0631\u0651\u064E\u062D\u0650\u064A\u0652\u0645\u0650</span>
          <p>May this gathering become a means of seeking beneficial knowledge, strengthening Islamic values and encouraging every participant to strive for excellence with sincerity.</p>
        </aside>
      </section>

      <section class="home-story section-wrap reveal visible">
        <figure class="home-story-visual">
          <img src="${data.storyImage || asset('images/kauzariyya4.png')}" alt="Kauzariyya Islamic College campus" loading="lazy">
          <figcaption>Al Jamiathul Kauzariyya &middot; Edathala</figcaption>
        </figure>
        <div class="home-story-content">
          <p class="home-story-kicker">More than a competition</p>
          <h2>Knowledge in action.<br>Character in every moment.</h2>
          <p class="home-story-copy">Kauzariyya brings recitation, scholarship, language, creativity and teamwork onto one stage.</p>
          <blockquote class="home-story-verse">
            <header><span>Qur&rsquo;anic inspiration</span><b>83 : 26</b></header>
            <p lang="ar" dir="rtl">\u0648\u064E\u0641\u0650\u064A \u0630\u064E\u0670\u0644\u0650\u0643 \u0641\u064E\u0644\u0652\u064A\u064E\u062A\u064E\u0646\u064E\u0627\u0641\u064E\u0633\u0650 \u0627\u0644\u0652\u0645\u064F\u062A\u064E\u0646\u064E\u0627\u0641\u0650\u0633\u064F\u0648\u0646\u064E</p>
            <footer><span>&ldquo;For this, let the competitors compete.&rdquo;</span><cite>Surah Al-Mutaffifin</cite></footer>
          </blockquote>
        </div>
      </section>

      <section class="home-schedule-3d-deck section-wrap reveal visible">
        <div class="schedule-3d-head">
          <div class="schedule-3d-copy">
            <p class="overline">SCHEDULE DETAILS</p>
            <h2>Information of Event<br>Schedules</h2>
          </div>
          <a href="#schedule" data-page="schedule" class="schedule-3d-action-btn"><span>Explore Full Schedule</span><b>&darr;</b></a>
        </div>
        <div class="schedule-3d-grid">
          <a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-emerald">
            <div class="card-3d-inner">
              <header><span class="card-3d-badge">ON STAGE</span><span class="card-3d-pill">${ev().onStageCount || 0} PROGRAMMES</span></header>
              <div class="card-3d-body"><span class="card-3d-number">${ev().onStageCount || 0}</span><div><strong>On-Stage Events</strong><small>STAGE 1 &amp; 2</small></div></div>
              <footer><span>Qur&rsquo;an Recitation, Oratory &amp; Speeches</span><b>&rarr;</b></footer>
            </div>
          </a>
          <a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-amber">
            <div class="card-3d-inner">
              <header><span class="card-3d-badge">OFF STAGE</span><span class="card-3d-pill">${ev().offStageCount || 0} PROGRAMMES</span></header>
              <div class="card-3d-body"><span class="card-3d-number">${ev().offStageCount || 0}</span><div><strong>Off-Stage Events</strong><small>CREATIVE &amp; LITERARY</small></div></div>
              <footer><span>Calligraphy, Writing &amp; Arts</span><b>&rarr;</b></footer>
            </div>
          </a>
        </div>
      </section>

      <section class="home-event-highlights section-wrap reveal visible">
        <header><p class="overline">Event highlights</p><h2>Where faith, knowledge and creativity take the stage.</h2></header>
        <div>
          ${[
            ["Qur\u2019an &amp; Recitation","Celebrating memorisation, precise Tajweed and the beauty of Qur\u2019anic recitation."],
            ["Oratory &amp; Expression","Inspiring confident voices through thoughtful speeches and presentations."],
            ["Islamic Knowledge","Exploring the Qur\u2019an, Seerah and Islamic heritage through engaging challenges."],
            ["Language &amp; Literature","Showcasing imagination through Arabic, Malayalam and creative writing."]
          ].map(item => `<article><div class="highlight-card-head">${icon}</div><h3>${item[0]}</h3><p>${item[1]}</p></article>`).join('')}
        </div>
      </section>

    </div>`,

    /* ── SCOREBOARD ──────────────────────────────────── */
    scoreboard: () => {
      const teams = data.teams?.length ? data.teams : [
        { name: '\u0623\u0646\u0635\u0627\u0631', score: 0, color: '#078521' },
        { name: '\u0623\u0639\u0648\u0627\u0646', score: 0, color: '#f4f7f3' },
        { name: '\u0623\u062E\u064A\u0627\u0631', score: 0, color: '#1725a9' },
        { name: '\u0623\u0628\u0637\u0627\u0644', score: 0, color: '#bced16' }
      ];
      const leader = teams[0];

      return `<section class="scoreboard-experience section-wrap">
        <header class="scoreboard-box-header reveal visible">
          <div class="scoreboard-box-title-group">
            <span class="scoreboard-live-badge"><i></i> OFFICIAL LIVE RESULTS</span>
            <h1 class="scoreboard-box-title">Festival<br><span class="title-accent">Scoreboard.</span></h1>
          </div>
          <p class="scoreboard-box-subtitle">Follow every team as verified marks arrive from the judging panel.</p>
        </header>
        <div class="scoreboard-container-box reveal visible">
          <div class="scoreboard-box-grid">
            <div class="scoreboard-leader-card" style="--team:${leader.color}">
              <div class="leader-card-circles"></div>
              <span class="leader-card-rank">01</span>
              <span class="leader-card-overline">CURRENT LEADER</span>
              <h2 class="leader-card-team" lang="ar">${leader.name}</h2>
              <div class="leader-card-score-box"><span class="leader-card-score">${leader.score}</span></div>
              <span class="leader-card-footer-label">VERIFIED MARKS</span>
            </div>
            <div class="scoreboard-team-rows">
              ${teams.map((t, i) => `<div class="scoreboard-team-row" style="--team:${t.color}">
                <span class="team-row-rank">${String(i + 1).padStart(2, '0')}</span>
                <h3 class="team-row-name" lang="ar">${t.name}</h3>
                <div class="team-row-line"></div>
                <div class="team-row-score-pill"><span>${t.score}</span></div>
              </div>`).join('')}
            </div>
          </div>
          <footer class="scoreboard-box-footer">
            <span>SCORES VERIFIED BY THE JUDGING PANEL</span>
            <span>REAL-TIME LIVE DATA</span>
          </footer>
        </div>
      </section>`;
    },

    /* ── SCHEDULE ─────────────────────────────────────── */
    schedule: () => {
      const sections = data.scheduleSections || {};
      const keys = Object.keys(sections).length ? Object.keys(sections) : ['morning', 'afternoon', 'evening'];

      return `<section class="schedule-shell section-wrap">
        <header class="schedule-head reveal visible">
          <div><p class="overline"><i class="overline-dot"></i> LIVE EVENT TIMETABLE</p><h1>Full program<br><em>schedule.</em></h1></div>
          <p>All program times in one place. Please arrive at the reporting desk at least 15 minutes before your program begins.</p>
        </header>
        <div class="schedule-tabs" role="tablist">
          ${keys.map((k, i) => `<button type="button" data-session="${k}" class="${i === 0 ? 'active' : ''}">${sections[k] || k}</button>`).join('')}
        </div>
        <div class="schedule-grid">${scheduleColumns(keys, sections)}</div>
      </section>`;
    },

    /* ── REVIEW ───────────────────────────────────────── */
    review: () => `<section class="review-shell section-wrap">
      <div class="review-copy reveal visible">
        <p class="overline">Your voice matters</p>
        <h1>How was your<br><em>experience?</em></h1>
        <p>Share what you enjoyed and what we can do better. Your opinion helps shape the next Kauzariyya Arts Festival.</p>
      </div>
      <form class="review-card reveal visible" data-review-form>
        <div>
          <span class="field-label">Overall rating</span>
          <div class="rating-buttons" role="group">${[1,2,3,4,5].map(v => `<button type="button" data-rating="${v}" aria-label="${v} stars">\u2605</button>`).join('')}</div>
        </div>
        <label><span class="field-label">What did you think?</span><textarea required rows="5" placeholder="Tell us about your festival experience\u2026"></textarea></label>
        <label><span class="field-label">Your name</span><input required type="text" placeholder="Name"></label>
        <button class="button button-review-submit" type="submit"><span>Submit my review</span><b>\u2197</b></button>
      </form>
    </section>`
  };

  /* ══════════════════════════════════════════════════════
     SHARED RENDER HELPERS
     ══════════════════════════════════════════════════════ */

  function scheduleColumns(keys, sections) {
    const items = data.schedule || [];
    return keys.map((key, idx) => {
      const filtered = items.filter(i => i[0] === key);
      return `<article class="schedule-column reveal visible ${idx === 0 ? 'mobile-active' : ''}" data-session-column="${key}">
        <header><h2>${sections[key] || key}</h2><small>Scheduled Events</small></header>
        ${filtered.length ? filtered.map(i => `<div class="schedule-row ${i[4] === 'live' ? 'live' : ''}">
          <time>${i[1]}</time><div><h3>${i[2]}</h3><p>${i[3]}</p></div><small>${i[5]} min</small>
        </div>`).join('') : '<div class="schedule-empty">Programmes will be announced shortly.</div>'}
      </article>`;
    }).join('');
  }

  /* ── event binding ─────────────────────────────────── */
  function bindPage(page) {
    /* navigation links */
    document.querySelectorAll('[data-page]').forEach(el => el.addEventListener('click', e => { e.preventDefault(); setPage(el.dataset.page); }));

    /* schedule session tabs */
    document.querySelectorAll('[data-session]').forEach(btn => btn.addEventListener('click', () => {
      document.querySelectorAll('[data-session]').forEach(b => b.classList.toggle('active', b === btn));
      document.querySelectorAll('[data-session-column]').forEach(c => c.classList.toggle('mobile-active', c.dataset.sessionColumn === btn.dataset.session));
    }));

    /* review rating + submit */
    let rating = 0;
    document.querySelectorAll('[data-rating]').forEach(btn => btn.addEventListener('click', () => {
      rating = Number(btn.dataset.rating);
      document.querySelectorAll('[data-rating]').forEach(s => s.classList.toggle('active', Number(s.dataset.rating) <= rating));
    }));
    document.querySelector('[data-review-form]')?.addEventListener('submit', e => {
      e.preventDefault();
      if (!rating) return;
      e.currentTarget.innerHTML = '<div class="review-thanks"><span>\u2713</span><h2>Review saved.</h2><p>Thank you for your feedback.</p></div>';
    });
  }

  /* ── boot ───────────────────────────────────────────── */
  const hash = window.location.hash.replace('#', '');
  setPage(pageNames[hash] ? hash : 'home');

})();
