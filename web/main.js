/* Plain JavaScript front-end: no React, build step, API, or dependencies. */
const data = window.INITIAL_DATA || {
  teams: [],
  schedule: [],
  participants: [],
  committee: []
};

const root = document.getElementById('react-root');
const pageNames = { home: 'Home', scoreboard: 'Scoreboard', schedule: 'Schedule', participants: 'Participants', students: 'Students', plan: 'Program Plan', review: 'Review' };
const icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5Zm-6 9v5l6 4 6-4v-5l-6 3-6-3Z"/></svg>';

function setPage(page) {
  document.body.className = page === 'plan' ? 'page-musabaqa' : `page-${page}`;
  root.innerHTML = header(page) + (views[page] || views.home)() + mobileNav(page);
  window.scrollTo({ top: 0, behavior: 'instant' });
  bindPage(page);
}

function navLink(page, current) {
  return `<a href="#${page}" class="${page === current ? 'active' : ''}" data-page="${page}">${pageNames[page]}</a>`;
}

function header(page) {
  return `<header class="site-header">
    <a class="site-logo" href="#home" data-page="home"><img src="assets/kauzariyya-brand-icon.png" alt="Kauzariyya"><span><b>Al-Jamiathul Kauzariyya</b><small>Internal Management System</small></span></a>
    <nav class="site-nav" aria-label="Main navigation">${Object.keys(pageNames).map(key => navLink(key, page)).join('')}</nav>
    <div class="header-account-actions"><div class="festival-date"><span>Festival day</span><b>18 August 2026</b></div><button class="visitor-logout visitor-logout-floating" type="button" aria-label="Sign out" data-signout>Sign out</button></div>
  </header>`;
}

function mobileNav(page) {
  return `<nav class="mobile-tabbar" aria-label="Mobile navigation">${Object.keys(pageNames).map(key => `<a href="#${key}" data-page="${key}" class="${key === page ? 'active' : ''}"><span class="tab-glyph-svg">${icon}</span><span>${pageNames[key]}</span></a>`).join('')}</nav>`;
}

const views = {
  home: () => `<div class="home-redesign">
    <section class="home-redesign-hero"><div class="home-redesign-copy reveal visible"><div class="home-visitor-greeting home-visitor-greeting-card"><span class="greeting-live-dot"></span><span class="greeting-label">Welcome,&nbsp;</span><strong class="greeting-user-name">GUEST</strong></div><p class="home-hero-eyebrow">Faith · Knowledge · Creativity</p><h1>Kauzariyya<br><em>Arts Festival.</em></h1><p class="home-hero-intro">A celebration where students discover their voice, share their talent and grow through meaningful competition.</p></div></section>
    <section class="home-platform-statement section-wrap reveal visible"><p>Competing in excellence, growing in knowledge, and standing together in faith. The official digital platform for Kauzariyya’s student competitions, live scores, schedules, teams and results.</p><strong>Excellence through knowledge <i></i> Unity through faith <i></i> Success through sincerity</strong></section>
    <section class="home-musabaqa-about section-wrap reveal visible"><article class="home-about-copy"><p class="overline">About the Musabaqa</p><h2>A stage for knowledge, discipline and sincere competition.</h2><p>The Kauzariyya Musabaqa is an annual academic and Islamic competition organized by Al Jamiathul Kauzariyya Arabic College. It celebrates excellence in Qur’an, Hadith, Arabic, Islamic studies, speeches and literature.</p><p>More than a competition, it strengthens brotherhood, confidence, discipline and character.</p></article><aside class="home-musabaqa-prayer"><span class="bismillah-mark" lang="ar" dir="rtl">بِسْمِ اللهِ الرَّحْمٰنِ الرَّحِيْمِ</span><p>May this gathering become a means of seeking beneficial knowledge, strengthening Islamic values and encouraging every participant to strive for excellence with sincerity.</p></aside></section>
    <section class="home-story section-wrap reveal visible"><figure class="home-story-visual"><img src="assets/kauzariyya4.webp" alt="Kauzariyya Islamic College campus"><figcaption>Al Jamiathul Kauzariyya · Edathala</figcaption></figure><div class="home-story-content"><p class="home-story-kicker">More than a competition</p><h2>Knowledge in action.<br>Character in every moment.</h2><p class="home-story-copy">Kauzariyya brings recitation, scholarship, language, creativity and teamwork onto one stage.</p><blockquote class="home-story-verse"><header><span>Qur’anic inspiration</span><b>83 : 26</b></header><p lang="ar" dir="rtl">وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ</p><footer><span>“For this, let the competitors compete.”</span><cite>Surah Al-Mutaffifin</cite></footer></blockquote></div></section>
    <section class="home-schedule-3d-deck section-wrap reveal visible"><div class="schedule-3d-head"><div class="schedule-3d-copy"><p class="overline">SCHEDULE DETAILS</p><h2>Information of Event<br>Schedules</h2></div><a href="#schedule" data-page="schedule" class="schedule-3d-action-btn"><span>Explore Full Schedule</span><b>↓</b></a></div><div class="schedule-3d-grid"><a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-emerald"><div class="card-3d-inner"><header><span class="card-3d-badge">DAY 01</span><span class="card-3d-pill">12 PROGRAMMES</span></header><div class="card-3d-body"><span class="card-3d-number">18</span><div><strong>August 2026</strong><small>TUESDAY · STAGE 1 & 2</small></div></div><footer><span>Qur'an Recitation, Oratory & Literary Arts</span><b>→</b></footer></div></a><a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-amber"><div class="card-3d-inner"><header><span class="card-3d-badge">DAY 02</span><span class="card-3d-pill">14 PROGRAMMES</span></header><div class="card-3d-body"><span class="card-3d-number">19</span><div><strong>August 2026</strong><small>WEDNESDAY · STAGE 1 & 2</small></div></div><footer><span>Grand Finale, Cultural Arts & Awards</span><b>→</b></footer></div></a></div></section>
    <section class="home-event-highlights section-wrap reveal visible"><header><p class="overline">Event highlights</p><h2>Where faith, knowledge and creativity take the stage.</h2></header><div>${[['Qur’an & Recitation','Celebrating memorisation, precise Tajweed and the beauty of Qur’anic recitation.'],['Oratory & Expression','Inspiring confident voices through thoughtful speeches and presentations.'],['Islamic Knowledge','Exploring the Qur’an, Seerah and Islamic heritage through engaging challenges.'],['Language & Literature','Showcasing imagination through Arabic, Malayalam and creative writing.']].map(item => `<article><div class="highlight-card-head">${icon}</div><h3>${item[0]}</h3><p>${item[1]}</p></article>`).join('')}</div></section>
  </div>`,

  scoreboard: () => `<section class="scoreboard-experience section-wrap"><header class="scoreboard-box-header reveal visible"><div class="scoreboard-box-title-group"><span class="scoreboard-live-badge"><i></i> OFFICIAL LIVE RESULTS</span><h1 class="scoreboard-box-title">Festival<br><span class="title-accent">Scoreboard.</span></h1></div><p class="scoreboard-box-subtitle">Follow every team as verified marks arrive from the judging panel.</p></header><div class="scoreboard-container-box reveal visible"><div class="scoreboard-box-grid"><div class="scoreboard-leader-card" style="--team:${data.teams[0].color}"><div class="leader-card-circles"></div><span class="leader-card-rank">01</span><span class="leader-card-overline">CURRENT LEADER</span><h2 class="leader-card-team" lang="ar">${data.teams[0].name}</h2><div class="leader-card-score-box"><span class="leader-card-score">${data.teams[0].score}</span></div><span class="leader-card-footer-label">VERIFIED MARKS</span></div><div class="scoreboard-team-rows">${data.teams.map((team, index) => `<div class="scoreboard-team-row" style="--team:${team.color}"><span class="team-row-rank">0${index + 1}</span><h3 class="team-row-name" lang="ar">${team.name}</h3><div class="team-row-line"></div><div class="team-row-score-pill"><span>${team.score}</span></div></div>`).join('')}</div></div><footer class="scoreboard-box-footer"><span>SCORES VERIFIED BY THE JUDGING PANEL</span><span>STATIC FRONT-END COPY</span></footer></div></section>`,

  schedule: () => `<section class="schedule-shell section-wrap"><header class="schedule-head reveal visible"><div><p class="overline"><i class="overline-dot"></i> ONE DAY · ONE STAGE</p><h1>Full program<br><em>schedule.</em></h1></div><p>All program times in one place. Please arrive at the reporting desk at least 15 minutes before your program begins.</p></header><div class="schedule-tabs" role="tablist">${['subahi','morning','afternoon','evening','night'].map((name, i) => `<button type="button" data-session="${name}" class="${i === 0 ? 'active' : ''}">${name}</button>`).join('')}</div><div class="schedule-grid">${scheduleColumns()}</div></section>`,

  participants: () => `<section class="participant-shell participant-directory-page section-wrap"><header class="participant-title reveal visible"><div class="participant-heading-copy"><p class="overline"><i class="overline-dot"></i> LIVE STAGE DIRECTORY</p><h1>Participant<br><em>roster.</em></h1><p>Real-time reporting times, active programmes, and complete stage running order.</p></div><div class="participant-page-summary"><div class="summary-pill"><strong>${data.participants.length}</strong><small>Participants</small></div><div class="summary-pill"><strong>01</strong><small>Programme</small></div></div></header><div class="participant-layout participant-stage-layout two-column-layout"><aside class="participant-left-box reveal visible"><div class="spotlight-bg-mesh"></div><div class="spotlight-top-badge"><span class="live-spotlight-badge"><i class="live-pulse"></i> SPEAKING NOW</span></div><div class="spotlight-hero-center"><span class="spotlight-hero-category">- ENGLISH SPEECH</span><h2 class="spotlight-hero-giant-name">Mohammed Hafiz</h2><div class="spotlight-bottom-details"><span class="spotlight-category-pill">Senior</span><span class="spotlight-code-pill">#KZ-001</span><span class="spotlight-team-pill" lang="ar">أنصار</span></div></div><footer class="spotlight-card-footer"><div class="footer-meta-item"><small>REPORTING TIME</small><strong>09:40</strong></div><div class="footer-meta-divider"></div><div class="footer-meta-item"><small>STAGE ORDER</small><strong>#01</strong></div></footer></aside><main class="participant-list reveal visible"><header class="participant-column-head"><h3 class="column-title">PARTICIPANTS</h3><span class="speakers-count-badge" data-count>4 speakers</span></header><div class="participant-roster-controls"><div class="controls-row search-row"><div class="control-group search-group"><span class="filter-overline"><i class="overline-dot"></i> SEARCH</span><div class="participant-search"><input aria-label="Search name or ID" type="search" placeholder="Search name or ID" data-participant-search></div></div></div></div><div class="participant-results-scroll" data-participant-list>${participantRows(data.participants)}</div></main></div></section>`,

  students: () => `<section class="all-students-page section-wrap"><header class="students-page-hero reveal visible"><div class="students-hero-text"><p class="overline"><i class="overline-dot"></i> COLLEGE DIRECTORY</p><h1>All<br><em>students.</em></h1><p>Browse the complete student directory by name, chess number, class or group.</p><div class="students-page-stats"><span><strong>04</strong><small>Total students</small></span><span><strong>04</strong><small>Active</small></span><span><strong>04</strong><small>Teams</small></span></div></div></header><section class="public-student-directory"><header class="student-directory-head"><div><p class="overline">Directory</p><h2>Student records</h2><p>Static front-end demonstration.</p></div></header><div class="public-student-filters"><label><span>Search students</span><input type="search" placeholder="Name, chess number or place" data-student-search></label></div><div class="public-student-grid" data-student-list>${data.participants.map((person, index) => `<article><b>${person[1]}</b><div><h3>${person[0]}</h3><p>Senior student</p></div><footer><span>Class ${index + 1}</span><span class="student-group-tag" lang="ar">${person[5]}</span><i>active</i></footer></article>`).join('')}</div></section></section>`,

  plan: () => `<section class="musabaqa-plan section-wrap"><header class="musabaqa-plan-hero reveal visible"><div><p class="overline"><i class="overline-dot"></i> OFFICIAL PROGRAMME · 2026–27</p><h1>Musabaqa<br><em>programme plan.</em></h1><p>Programme responsibilities and the working committee for the 2026–27 Kauzariyya Musabaqa.</p></div><div class="musabaqa-plan-stats"><span><strong>15</strong><small>Programmes</small></span><span><strong>${data.committee.length}</strong><small>Committee roles</small></span></div></header><div class="musabaqa-program-grid">${programmeGroup('Off stage',['Calligraphy','News Writing','Malayalam Leganam','Arabic Leganam','Poem Writing'],1)}${programmeGroup('On stage',['Malayalam Speech','English Speech','Arabic Speech','Urdu Speech','Qirath','Urdu Song','Ebaarath Vazhana','Muhadasa','Padapayat','Musagala'],6)}</div><section class="musabaqa-committee reveal visible"><header><div><p class="overline">Festival leadership</p><h2>Working committee</h2></div><p>The team coordinating programmes, stage operations, documentation, evaluation and awards.</p></header><div>${data.committee.map((member, index) => `<article><span>${String(index + 1).padStart(2,'0')}</span><h3>${member.name}</h3><p>${member.role}</p></article>`).join('')}</div></section></section>`,

  review: () => `<section class="review-shell section-wrap"><div class="review-copy reveal visible"><p class="overline">Your voice matters</p><h1>How was your<br><em>experience?</em></h1><p>Share what you enjoyed and what we can do better. Your opinion helps shape the next Kauzariyya Arts Festival.</p></div><form class="review-card reveal visible" data-review-form><div><span class="field-label">Overall rating</span><div class="rating-buttons" role="group">${[1,2,3,4,5].map(value => `<button type="button" data-rating="${value}" aria-label="${value} stars">★</button>`).join('')}</div></div><label><span class="field-label">What did you think?</span><textarea required rows="5" placeholder="Tell us about your festival experience…"></textarea></label><label><span class="field-label">Your name</span><input required type="text" placeholder="Name"></label><button class="button button-review-submit" type="submit"><span>Submit my review</span><b>↗</b></button></form></section>`,
};

function scheduleColumns() {
  const groups = [['subahi','Subahi','5:00 AM - 7:00 AM'],['morning','Morning','9:00 AM - 12:45 PM'],['afternoon','Afternoon','2:30 PM - 4:30 PM'],['evening','Evening','7:30 PM - 8:45 PM'],['night','Night','9:30 PM - 11:00 PM']];
  return groups.map(([key, label, time], index) => `<article class="schedule-column reveal visible ${index === 0 ? 'mobile-active' : ''}" data-session-column="${key}"><header><h2>${label}</h2><small>${time}</small></header>${data.schedule.filter(item => item[0] === key).map(item => `<div class="schedule-row ${item[4] === 'live' ? 'live' : ''}"><time>${item[1]}</time><div><h3>${item[2]}</h3><p>${item[3]}</p></div><small>${item[5]} min</small></div>`).join('') || '<div class="schedule-empty">Programmes will be announced shortly.</div>'}</article>`).join('');
}

function participantRows(people) {
  return people.map((person, index) => `<article class="participant-row ${index === 0 ? 'active' : ''}"><time class="row-time">${person[4]}</time><div class="row-details"><strong class="row-name">${person[0]}</strong><div class="row-sub"><span class="row-team" lang="ar">${person[5]}</span><span class="row-sep"> - </span><span class="row-code">${person[1]}</span></div></div><span class="row-order-tile">${String(index + 1).padStart(2,'0')}</span></article>`).join('');
}

function programmeGroup(name, programmes, start) {
  return `<section class="musabaqa-program-section reveal visible"><header><div><p class="overline">Chapter ${start === 1 ? '01' : '02'}</p><h2>${name}</h2></div><span>${String(programmes.length).padStart(2,'0')} programmes</span></header><div class="musabaqa-program-list">${programmes.map((programme, index) => `<article><b>${String(start + index).padStart(2,'0')}</b><div><h3>${programme}</h3><p>Programme committee</p></div><span>●</span></article>`).join('')}</div></section>`;
}

function bindPage(page) {
  document.querySelectorAll('[data-page]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); setPage(link.dataset.page); }));
  document.querySelector('[data-signout]')?.addEventListener('click', () => setPage('home'));
  document.querySelectorAll('[data-session]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('[data-session]').forEach(item => item.classList.toggle('active', item === button));
    document.querySelectorAll('[data-session-column]').forEach(item => item.classList.toggle('mobile-active', item.dataset.sessionColumn === button.dataset.session));
  }));
  document.querySelector('[data-participant-search]')?.addEventListener('input', event => {
    const value = event.target.value.trim().toLowerCase();
    const people = data.participants.filter(person => person.join(' ').toLowerCase().includes(value));
    document.querySelector('[data-participant-list]').innerHTML = participantRows(people);
    document.querySelector('[data-count]').textContent = `${people.length} speakers`;
  });
  document.querySelector('[data-student-search]')?.addEventListener('input', event => {
    const value = event.target.value.trim().toLowerCase();
    document.querySelectorAll('[data-student-list] article').forEach(card => { card.hidden = !card.textContent.toLowerCase().includes(value); });
  });
  let rating = 0;
  document.querySelectorAll('[data-rating]').forEach(button => button.addEventListener('click', () => { rating = Number(button.dataset.rating); document.querySelectorAll('[data-rating]').forEach(star => star.classList.toggle('active', Number(star.dataset.rating) <= rating)); }));
  document.querySelector('[data-review-form]')?.addEventListener('submit', event => {
    event.preventDefault();
    if (!rating) return;
    const form = event.currentTarget;
    const comment = form.querySelector('textarea').value;
    const name = form.querySelector('input[type="text"]').value;
    
    fetch('api.php?action=submit_review', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        rating: rating,
        comment: comment,
        name: name
      })
    })
    .then(res => res.json())
    .then(res => {
      form.innerHTML = `<div class="review-thanks"><span>✓</span><h2>${res.success ? 'Review saved.' : 'Error'}</h2><p>${res.message || 'Thank you for your feedback.'}</p></div>`;
    })
    .catch(err => {
      form.innerHTML = '<div class="review-thanks"><span style="background:#ea4335">✗</span><h2>Error</h2><p>Something went wrong. Please try again.</p></div>';
    });
  });
}

// Render immediately with initial server data
const initialPage = window.location.hash.replace('#', '') || 'home';
setPage(initialPage);

// Fetch live data from database asynchronously to catch any changes
fetch('api.php?action=get_data')
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      data.teams = res.teams;
      data.schedule = res.schedule;
      data.participants = res.participants;
      data.committee = res.committee;
      
      // Re-render the current active page with updated data
      const currentPage = window.location.hash.replace('#', '') || 'home';
      setPage(currentPage);
    }
  })
  .catch(err => {
    console.error('Failed to load database data:', err);
  });
