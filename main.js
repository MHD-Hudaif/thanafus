/* Plain JavaScript front-end: no React, build step, API, or dependencies. */
const data = window.INITIAL_DATA || {
  event: {
    title: 'Al-Jamiathul Kauzariyya · Arts Festival',
    date: '18 August 2026',
    start_date: '2026-08-18'
  },
  teams: [],
  schedule: [],
  participants: [],
  committee: [],
  programs: [],
  students: [],
  sections: []
};

const root = document.getElementById('react-root');
const pageNames = { home: 'Home', scoreboard: 'Scoreboard', schedule: 'Schedule', review: 'Review' };
const icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5Zm-6 9v5l6 4 6-4v-5l-6 3-6-3Z"/></svg>';

function getDayNumber(dateStr, startDateStr) {
  if (!dateStr || !startDateStr) return 1;
  const d = new Date(dateStr);
  const start = new Date(startDateStr);
  d.setHours(0,0,0,0);
  start.setHours(0,0,0,0);
  const diffTime = d - start;
  const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));
  return diffDays + 1;
}

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
    <div class="header-account-actions"><div class="festival-date"><span>Festival day</span><b>${data.event?.date || '18 August 2026'}</b></div><button class="visitor-logout visitor-logout-floating" type="button" aria-label="Sign out" data-signout>Sign out</button></div>
  </header>`;
}

function mobileNav(page) {
  return `<nav class="mobile-tabbar" aria-label="Mobile navigation">${Object.keys(pageNames).map(key => `<a href="#${key}" data-page="${key}" class="${key === page ? 'active' : ''}"><span class="tab-glyph-svg">${icon}</span><span>${pageNames[key]}</span></a>`).join('')}</nav>`;
}

const views = {
  home: () => {
    const startDateStr = data.event?.start_date || '2026-08-18';
    const d1 = new Date(startDateStr);
    const d2 = new Date(d1);
    d2.setDate(d1.getDate() + 1);
    const d3 = new Date(d1);
    d3.setDate(d1.getDate() + 2);
    
    const day1Num = d1.getDate();
    const day1MonthYear = d1.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const day1Weekday = d1.toLocaleDateString('en-US', { weekday: 'long' }).toUpperCase();
    
    const day2Num = d2.getDate();
    const day2MonthYear = d2.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const day2Weekday = d2.toLocaleDateString('en-US', { weekday: 'long' }).toUpperCase();

    const day3Num = d3.getDate();
    const day3MonthYear = d3.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const day3Weekday = d3.toLocaleDateString('en-US', { weekday: 'long' }).toUpperCase();

    const rawTitle = data.event?.title || 'Kauzariyya Arts Festival';
    let formattedTitle = rawTitle;
    if (rawTitle.includes('Arts Festival')) {
      formattedTitle = rawTitle.replace('Arts Festival', '<em>Arts Festival.</em>');
    } else {
      const parts = rawTitle.split(' ');
      if (parts.length > 1) {
        const last = parts.pop();
        formattedTitle = parts.join(' ') + `<br><em>${last}.</em>`;
      } else {
        formattedTitle = `<em>${rawTitle}.</em>`;
      }
    }

    return `<div class="home-redesign">
      <section class="home-redesign-hero"><div class="home-redesign-copy reveal visible"><div class="home-visitor-greeting home-visitor-greeting-card"><span class="greeting-live-dot"></span><span class="greeting-label">Welcome,&nbsp;</span><strong class="greeting-user-name">GUEST</strong></div><p class="home-hero-eyebrow">Faith · Knowledge · Creativity</p><h1>${formattedTitle}</h1><p class="home-hero-intro">A celebration where students discover their voice, share their talent and grow through meaningful competition.</p></div></section>
      <section class="home-platform-statement section-wrap reveal visible"><p>Competing in excellence, growing in knowledge, and standing together in faith. The official digital platform for Kauzariyya’s student competitions, live scores, schedules, teams and results.</p><strong>Excellence through knowledge <i></i> Unity through faith <i></i> Success through sincerity</strong></section>
      <section class="home-musabaqa-about section-wrap reveal visible"><article class="home-about-copy"><p class="overline">About the Musabaqa</p><h2>A stage for knowledge, discipline and sincere competition.</h2><p>The Kauzariyya Musabaqa is an annual academic and Islamic competition organized by Al Jamiathul Kauzariyya Arabic College. It celebrates excellence in Qur’an, Hadith, Arabic, Islamic studies, speeches and literature.</p><p>More than a competition, it strengthens brotherhood, confidence, discipline and character.</p></article><aside class="home-musabaqa-prayer"><span class="bismillah-mark" lang="ar" dir="rtl">بِسْمِ اللهِ الرَّحْمٰنِ الرَّحِيْمِ</span><p>May this gathering become a means of seeking beneficial knowledge, strengthening Islamic values and encouraging every participant to strive for excellence with sincerity.</p></aside></section>
      <section class="home-story section-wrap reveal visible"><figure class="home-story-visual"><img src="assets/kauzariyya4.webp" alt="Kauzariyya Islamic College campus"><figcaption>Al Jamiathul Kauzariyya · Edathala</figcaption></figure><div class="home-story-content"><p class="home-story-kicker">More than a competition</p><h2>Knowledge in action.<br>Character in every moment.</h2><p class="home-story-copy">Kauzariyya brings recitation, scholarship, language, creativity and teamwork onto one stage.</p><blockquote class="home-story-verse"><header><span>Qur’anic inspiration</span><b>83 : 26</b></header><p lang="ar" dir="rtl">وَفِي ذَٰلِكَ فَلْيَتَنَافَسِ الْمُتَنَافِسُونَ</p><footer><span>“For this, let the competitors compete.”</span><cite>Surah Al-Mutaffifin</cite></footer></blockquote></div></section>
      <section class="home-schedule-3d-deck section-wrap reveal visible"><div class="schedule-3d-head"><div class="schedule-3d-copy"><p class="overline">SCHEDULE DETAILS</p><h2>Information of Event<br>Schedules</h2></div><a href="#schedule" data-page="schedule" class="schedule-3d-action-btn"><span>Explore Full Schedule</span><b>↓</b></a></div><div class="schedule-3d-grid"><a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-emerald"><div class="card-3d-inner"><header><span class="card-3d-badge">DAY 01</span><span class="card-3d-pill">SCHEDULED</span></header><div class="card-3d-body"><span class="card-3d-number">${day1Num}</span><div><strong>${day1MonthYear}</strong><small>${day1Weekday} · STAGE 1 & 2</small></div></div><footer><span>Qur'an Recitation, Oratory & Literary Arts</span><b>→</b></footer></div></a><a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-amber"><div class="card-3d-inner"><header><span class="card-3d-badge">DAY 02</span><span class="card-3d-pill">SCHEDULED</span></header><div class="card-3d-body"><span class="card-3d-number">${day2Num}</span><div><strong>${day2MonthYear}</strong><small>${day2Weekday} · STAGE 1 & 2</small></div></div><footer><span>Grand Finale, Cultural Arts & Awards</span><b>→</b></footer></div></a><a href="#schedule" data-page="schedule" class="schedule-3d-card card-3d-blue"><div class="card-3d-inner"><header><span class="card-3d-badge">DAY 03</span><span class="card-3d-pill">SCHEDULED</span></header><div class="card-3d-body"><span class="card-3d-number">${day3Num}</span><div><strong>${day3MonthYear}</strong><small>${day3Weekday} · STAGE 1 & 2</small></div></div><footer><span>Valedictory Session & Special Events</span><b>→</b></footer></div></a></div></section>
      <section class="home-event-highlights section-wrap reveal visible"><header><p class="overline">Event highlights</p><h2>Where faith, knowledge and creativity take the stage.</h2></header><div>${[['Qur’an & Recitation','Celebrating memorisation, precise Tajweed and the beauty of Qur’anic recitation.'],['Oratory & Expression','Inspiring confident voices through thoughtful speeches and presentations.'],['Islamic Knowledge','Exploring the Qur’an, Seerah and Islamic heritage through engaging challenges.'],['Language & Literature','Showcasing imagination through Arabic, Malayalam and creative writing.']].map(item => `<article><div class="highlight-card-head">${icon}</div><h3>${item[0]}</h3><p>${item[1]}</p></article>`).join('')}</div></section>
    </div>`;
  },

  scoreboard: () => `<section class="scoreboard-experience section-wrap"><header class="scoreboard-box-header reveal visible"><div class="scoreboard-box-title-group"><span class="scoreboard-live-badge"><i></i> OFFICIAL LIVE RESULTS</span><h1 class="scoreboard-box-title">Festival<br><span class="title-accent">Scoreboard.</span></h1></div><p class="scoreboard-box-subtitle">Follow every team as verified marks arrive from the judging panel.</p></header><div class="scoreboard-container-box reveal visible"><div class="scoreboard-box-grid"><div class="scoreboard-leader-card" style="--team:${data.teams[0].color}"><div class="leader-card-circles"></div><span class="leader-card-rank">01</span><span class="leader-card-overline">CURRENT LEADER</span><h2 class="leader-card-team" lang="ar">${data.teams[0].name}</h2><div class="leader-card-score-box"><span class="leader-card-score">${data.teams[0].score}</span></div><span class="leader-card-footer-label">VERIFIED MARKS</span></div><div class="scoreboard-team-rows">${data.teams.map((team, index) => `<div class="scoreboard-team-row" style="--team:${team.color}"><span class="team-row-rank">0${index + 1}</span><h3 class="team-row-name" lang="ar">${team.name}</h3><div class="team-row-line"></div><div class="team-row-score-pill"><span>${team.score}</span></div></div>`).join('')}</div></div><footer class="scoreboard-box-footer"><span>SCORES VERIFIED BY THE JUDGING PANEL</span><span>STATIC FRONT-END COPY</span></footer></div></section>`,

  schedule: () => {
    const days = [];
    data.schedule.forEach(item => {
      if (item[6]) {
        const dayNum = getDayNumber(item[6], data.event?.start_date);
        if (dayNum > 0 && !days.includes(dayNum)) {
          days.push(dayNum);
        }
      }
    });
    days.sort((a, b) => a - b);

    const groups = days.length > 0
      ? days.map(d => [`day_${d}`, `Day ${d}`])
      : [['day_1', 'Day 1'], ['day_2', 'Day 2'], ['day_3', 'Day 3']];

    return `<section class="schedule-shell section-wrap"><header class="schedule-head reveal visible"><div><p class="overline"><i class="overline-dot"></i> FESTIVAL TIMETABLE</p><h1>Festival<br><em>schedule.</em></h1></div><p>Browse programs scheduled for each day of the festival. Select a day tab below to view all running orders and stage details.</p></header><div class="schedule-tabs" role="tablist">${groups.map((g, i) => `<button type="button" data-session="${g[0]}" class="${i === 0 ? 'active' : ''}">${g[1]}</button>`).join('')}</div><div class="schedule-day-container">${scheduleColumns()}</div></section>`;
  },

  participants: () => {
    const all = data.participants || [];
    const programsSet = new Set();
    all.forEach(p => {
      const prog = p[2] || p.program || '';
      if (prog) programsSet.add(prog);
    });
    const uniquePrograms = Array.from(programsSet).sort();
    const totalProgCount = data.programs && data.programs.length > 0 ? data.programs.length : uniquePrograms.length;

    const first = all[0];
    const hasFirst = !!first;
    const name = hasFirst ? (first[0] || first.name) : 'No entries loaded';
    const rawCode = hasFirst ? (first[1] || first.code || '') : '';
    const code = rawCode ? `#${rawCode}` : '';
    const program = hasFirst ? (first[2] || first.program || 'Arts Festival') : 'Arts Festival';
    const category = hasFirst ? (first[3] || first.category || 'General') : 'General';
    const reportingTime = hasFirst ? (first[4] || first.reporting_time || '00:00') : '00:00';
    const teamName = hasFirst ? (first[5] || first.team_name || '') : '';
    const teamColor = hasFirst ? (first[6] || first.team_color || '#4ee883') : '#4ee883';
    const stageOrder = hasFirst ? String(first[7] || 1).padStart(2, '0') : '01';
    
    return `<section class="participant-shell participant-directory-page section-wrap">
      <header class="participant-title reveal visible">
        <div class="participant-heading-copy">
          <p class="overline"><i class="overline-dot"></i> LIVE STAGE DIRECTORY</p>
          <h1>Participant<br><em>roster.</em></h1>
          <p>Real-time reporting times, active programmes, and complete stage running order.</p>
        </div>
        <div class="participant-page-summary">
          <div class="summary-pill">
            <strong data-summary-participants>${all.length}</strong>
            <small>Participants</small>
          </div>
          <div class="summary-pill">
            <strong data-summary-programs>${String(totalProgCount).padStart(2, '0')}</strong>
            <small data-summary-programs-label>${totalProgCount === 1 ? 'Programme' : 'Programmes'}</small>
          </div>
        </div>
      </header>

      <div class="participant-layout participant-stage-layout two-column-layout">
        <aside class="participant-left-box reveal visible" data-spotlight-box>
          <div class="spotlight-bg-mesh"></div>
          <div class="spotlight-top-badge">
            <span class="live-spotlight-badge"><i class="live-pulse"></i> SPEAKING NOW</span>
          </div>
          <div class="spotlight-hero-center">
            <span class="spotlight-hero-category" data-spotlight-program>- ${program.toUpperCase()}</span>
            <h2 class="spotlight-hero-giant-name" data-spotlight-name>${name}</h2>
            <div class="spotlight-bottom-details">
              <span class="spotlight-category-pill" data-spotlight-category>${category}</span>
              <span class="spotlight-code-pill" data-spotlight-code ${rawCode ? '' : 'style="display:none;"'}>${code}</span>
              <span class="spotlight-team-pill team-name-ar" data-spotlight-team lang="ar" style="border-color:${teamColor};color:#fff;">
                <span class="team-dot" style="background:${teamColor};"></span>
                <span class="team-text">${teamName}</span>
              </span>
            </div>
          </div>
          <footer class="spotlight-card-footer">
            <div class="footer-meta-item">
              <small>REPORTING TIME</small>
              <strong data-spotlight-time>${reportingTime}</strong>
            </div>
            <div class="footer-meta-divider"></div>
            <div class="footer-meta-item">
              <small>STAGE ORDER</small>
              <strong data-spotlight-order>#${stageOrder}</strong>
            </div>
          </footer>
        </aside>

        <main class="participant-list reveal visible">
          <header class="participant-column-head">
            <h3 class="column-title">PARTICIPANTS</h3>
            <span class="speakers-count-badge" data-count>${all.length} speakers</span>
          </header>
          <div class="participant-roster-controls">
            <div class="participant-program-filter">
              <span>Programme</span>
              <select data-program-filter aria-label="Filter by Programme">
                <option value="">All Programmes (${uniquePrograms.length})</option>
                ${uniquePrograms.map(pName => `<option value="${pName}">${pName}</option>`).join('')}
              </select>
            </div>
            <div class="controls-row search-row">
              <div class="control-group search-group" style="width:100%;">
                <span class="filter-overline"><i class="overline-dot"></i> SEARCH</span>
                <div class="participant-search">
                  <input aria-label="Search name or ID" type="search" placeholder="Search name, chest number or team..." data-participant-search>
                </div>
              </div>
            </div>
          </div>
          <div class="participant-results-scroll" data-participant-list>${participantRows(all)}</div>
        </main>
      </div>
    </section>`;
  },

  students: () => {
    const totalStudents = data.students.length;
    const activeStudents = data.students.length;
    const totalTeams = data.teams.length;
    return `<section class="all-students-page section-wrap"><header class="students-page-hero reveal visible"><div class="students-hero-text"><p class="overline"><i class="overline-dot"></i> COLLEGE DIRECTORY</p><h1>All<br><em>students.</em></h1><p>Browse the complete student directory by name, chess number, class or group.</p><div class="students-page-stats"><span><strong>${totalStudents}</strong><small>Total students</small></span><span><strong>${activeStudents}</strong><small>Active</small></span><span><strong>${totalTeams}</strong><small>Teams</small></span></div></div></header><section class="public-student-directory"><header class="student-directory-head"><div><p class="overline">Directory</p><h2>Student records</h2></div></header><div class="public-student-filters"><label><span>Search students</span><input type="search" placeholder="Name, chess number or place" data-student-search></label></div><div class="public-student-grid" data-student-list>${data.students.map((student) => `<article><b>${student.code || ''}</b><div><h3>${student.name}</h3><p>${student.category_name || 'Student'}</p></div><footer><span>Class ${student.class_name || '-'}</span><span class="student-group-tag" lang="ar">${student.team_name}</span><i>active</i></footer></article>`).join('')}</div></section></section>`;
  },

  plan: () => {
    const offstage = data.programs.filter(p => p.category === 'off_stage').map(p => p.title);
    const onstage = data.programs.filter(p => p.category === 'on_stage').map(p => p.title);
    return `<section class="musabaqa-plan section-wrap"><header class="musabaqa-plan-hero reveal visible"><div><p class="overline"><i class="overline-dot"></i> OFFICIAL PROGRAMME · 2026–27</p><h1>Musabaqa<br><em>programme plan.</em></h1><p>Programme responsibilities and the working committee for the 2026–27 Kauzariyya Musabaqa.</p></div><div class="musabaqa-plan-stats"><span><strong>${data.programs.length}</strong><small>Programmes</small></span><span><strong>${data.committee.length}</strong><small>Committee roles</small></span></div></header><div class="musabaqa-program-grid">${programmeGroup('Off stage',offstage,1)}${programmeGroup('On stage',onstage,offstage.length + 1)}</div><section class="musabaqa-committee reveal visible"><header><div><p class="overline">Festival leadership</p><h2>Working committee</h2></div><p>The team coordinating programmes, stage operations, documentation, evaluation and awards.</p></header><div>${data.committee.map((member, index) => `<article><span>${String(index + 1).padStart(2,'0')}</span><h3>${member.name}</h3><p>${member.role}</p></article>`).join('')}</div></section></section>`;
  },

  review: () => `<section class="review-shell section-wrap"><div class="review-copy reveal visible"><p class="overline">Your voice matters</p><h1>How was your<br><em>experience?</em></h1><p>Share what you enjoyed and what we can do better. Your opinion helps shape the next Kauzariyya Arts Festival.</p></div><form class="review-card reveal visible" data-review-form><div><span class="field-label">Overall rating</span><div class="rating-buttons" role="group">${[1,2,3,4,5].map(value => `<button type="button" data-rating="${value}" aria-label="${value} stars">★</button>`).join('')}</div></div><label><span class="field-label">What did you think?</span><textarea required rows="5" placeholder="Tell us about your festival experience…"></textarea></label><label><span class="field-label">Your name</span><input required type="text" placeholder="Name"></label><button class="button button-review-submit" type="submit"><span>Submit my review</span><b>↗</b></button></form></section>`,
};

function scheduleColumns() {
  const days = [];
  data.schedule.forEach(item => {
    if (item[6]) {
      const dayNum = getDayNumber(item[6], data.event?.start_date);
      if (dayNum > 0 && !days.includes(dayNum)) {
        days.push(dayNum);
      }
    }
  });
  days.sort((a, b) => a - b);

  const groups = days.length > 0
    ? days.map(d => [`day_${d}`, `Day ${d}`])
    : [['day_1', 'Day 1'], ['day_2', 'Day 2'], ['day_3', 'Day 3']];

  const startDateStr = data.event?.start_date || '2026-08-18';
  const baseDate = new Date(startDateStr);

  return groups.map(([key, label], index) => {
    const dayNum = Number(key.replace('day_', ''));
    const dayPrograms = data.schedule.filter(item => getDayNumber(item[6], data.event?.start_date) === dayNum);

    const thisDayDate = new Date(baseDate);
    thisDayDate.setDate(baseDate.getDate() + (dayNum - 1));
    const dayDateFormatted = thisDayDate.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    return `<article class="schedule-day-panel reveal visible ${index === 0 ? 'active' : ''}" data-session-column="${key}">
      <header class="schedule-day-header">
        <div class="day-header-info">
          <span class="day-header-badge">DAY 0${dayNum}</span>
          <h2>${label} Schedule</h2>
          <p class="day-header-date">${dayDateFormatted}</p>
        </div>
        <div class="day-header-count">
          <strong>${dayPrograms.length}</strong>
          <small>Programmes</small>
        </div>
      </header>
      
      <div class="schedule-cards-grid">
        ${dayPrograms.map((item, pIndex) => {
          const parts = (item[3] || '').split(' · ');
          const venue = parts[0] || 'Main Stage';
          const category = parts[1] || 'Open Category';
          return `
          <div class="schedule-card-rich ${item[4] === 'live' ? 'live' : ''}">
            <div class="card-rich-side">
              <span class="card-rich-time">${item[1]}</span>
              <span class="card-rich-duration">${item[5]} min</span>
            </div>
            <div class="card-rich-main">
              <div class="card-rich-top">
                <span class="card-rich-order">#${String(pIndex + 1).padStart(2, '0')}</span>
                ${item[4] === 'live' ? '<span class="live-pill"><i class="live-pulse"></i> LIVE NOW</span>' : ''}
              </div>
              <h3 class="card-rich-title">${item[2]}</h3>
              <div class="card-rich-meta">
                <span class="card-rich-venue">📍 ${venue}</span>
                <span class="card-rich-category">🏷️ ${category}</span>
              </div>
            </div>
          </div>`;
        }).join('') || '<div class="schedule-empty-rich"><p>No programmes scheduled for this day yet.</p></div>'}
      </div>
    </article>`;
  }).join('');
}

function participantRows(people) {
  if (!people || people.length === 0) {
    return '<div class="admin-roster-empty" style="padding:32px 16px;text-align:center;color:rgba(255,255,255,0.45);"><p>No participants found matching your selection.</p></div>';
  }
  return people.map((person, index) => {
    const name = person[0] || person.name || 'Participant';
    const code = person[1] || person.code || '';
    const program = person[2] || person.program || '';
    const time = person[4] || person.reporting_time || '00:00';
    const team = person[5] || person.team_name || '';
    const color = person[6] || person.team_color || '#4ee883';
    const order = String(person[7] || (index + 1)).padStart(2, '0');

    return `<article class="participant-row ${index === 0 ? 'active' : ''}" data-p-index="${index}" style="--team:${color}">
      <time class="row-time">${time}</time>
      <div class="row-details">
        <strong class="row-name">${name}</strong>
        <div class="row-sub">
          <span class="row-team" lang="ar">${team}</span>
          ${code ? `<span class="row-sep"> - </span><span class="row-code">#${code}</span>` : ''}
          ${program ? `<span class="row-sep"> · </span><span class="row-program" style="color:rgba(78,232,131,0.7);">${program}</span>` : ''}
        </div>
      </div>
      <span class="row-order-tile">${order}</span>
    </article>`;
  }).join('');
}

function programmeGroup(name, programmes, start) {
  return `<section class="musabaqa-program-section reveal visible"><header><div><p class="overline">Chapter ${start === 1 ? '01' : '02'}</p><h2>${name}</h2></div><span>${String(programmes.length).padStart(2,'0')} programmes</span></header><div class="musabaqa-program-list">${programmes.map((programme, index) => `<article><b>${String(start + index).padStart(2,'0')}</b><div><h3>${programme}</h3><p>Programme committee</p></div><span>●</span></article>`).join('')}</div></section>`;
}

function bindPage(page) {
  document.querySelectorAll('[data-page]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); window.location.hash = link.dataset.page; }));
  document.querySelector('[data-signout]')?.addEventListener('click', () => window.location.hash = 'home');
  document.querySelectorAll('[data-session]').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('[data-session]').forEach(item => item.classList.toggle('active', item === button));
    document.querySelectorAll('[data-session-column]').forEach(item => {
      item.classList.toggle('active', item.dataset.sessionColumn === button.dataset.session);
    });
  }));

  if (page === 'participants') {
    let currentFiltered = [...(data.participants || [])];

    function updateSpotlight(person, orderIndex) {
      if (!person) return;
      const name = person[0] || person.name || 'Participant';
      const rawCode = person[1] || person.code || '';
      const code = rawCode ? `#${rawCode}` : '';
      const program = person[2] || person.program || 'Arts Festival';
      const category = person[3] || person.category || 'General';
      const time = person[4] || person.reporting_time || '00:00';
      const team = person[5] || person.team_name || '';
      const color = person[6] || person.team_color || '#4ee883';
      const order = String(person[7] || (orderIndex + 1)).padStart(2, '0');

      const nameEl = document.querySelector('[data-spotlight-name]');
      const progEl = document.querySelector('[data-spotlight-program]');
      const catEl = document.querySelector('[data-spotlight-category]');
      const codeEl = document.querySelector('[data-spotlight-code]');
      const teamEl = document.querySelector('[data-spotlight-team]');
      const timeEl = document.querySelector('[data-spotlight-time]');
      const orderEl = document.querySelector('[data-spotlight-order]');

      if (nameEl) nameEl.textContent = name;
      if (progEl) progEl.textContent = `- ${program.toUpperCase()}`;
      if (catEl) catEl.textContent = category;
      if (codeEl) {
        codeEl.textContent = code;
        codeEl.style.display = rawCode ? '' : 'none';
      }
      if (teamEl) {
        teamEl.style.borderColor = color;
        const dot = teamEl.querySelector('.team-dot');
        if (dot) dot.style.background = color;
        const text = teamEl.querySelector('.team-text') || teamEl;
        text.textContent = team;
      }
      if (timeEl) timeEl.textContent = time;
      if (orderEl) orderEl.textContent = `#${order}`;
    }

    function bindParticipantRowClicks() {
      document.querySelectorAll('[data-p-index]').forEach(row => {
        row.addEventListener('click', () => {
          document.querySelectorAll('[data-p-index]').forEach(r => r.classList.remove('active'));
          row.classList.add('active');
          const idx = parseInt(row.dataset.pIndex, 10);
          if (currentFiltered[idx]) {
            updateSpotlight(currentFiltered[idx], idx);
          }
        });
      });
    }

    function applyParticipantFilters() {
      const searchVal = document.querySelector('[data-participant-search]')?.value.trim().toLowerCase() || '';
      const progVal = document.querySelector('[data-program-filter]')?.value || '';

      currentFiltered = (data.participants || []).filter(person => {
        const pName = (person[0] || person.name || '').toLowerCase();
        const pCode = (person[1] || person.code || '').toLowerCase();
        const pProg = (person[2] || person.program || '');
        const pCat = (person[3] || person.category || '').toLowerCase();
        const pTeam = (person[5] || person.team_name || '').toLowerCase();

        const matchesProg = !progVal || pProg === progVal;
        const matchesSearch = !searchVal || 
          pName.includes(searchVal) || 
          pCode.includes(searchVal) || 
          pProg.toLowerCase().includes(searchVal) || 
          pCat.includes(searchVal) || 
          pTeam.includes(searchVal);

        return matchesProg && matchesSearch;
      });

      const listEl = document.querySelector('[data-participant-list]');
      if (listEl) {
        listEl.innerHTML = participantRows(currentFiltered);
        bindParticipantRowClicks();
      }
      const countEl = document.querySelector('[data-count]');
      if (countEl) {
        countEl.textContent = `${currentFiltered.length} speakers`;
      }
      const partSummaryEl = document.querySelector('[data-summary-participants]');
      if (partSummaryEl) {
        partSummaryEl.textContent = currentFiltered.length;
      }
      const progSummaryEl = document.querySelector('[data-summary-programs]');
      if (progSummaryEl) {
        const filteredProgs = new Set(currentFiltered.map(p => p[2] || p.program || '')).size;
        progSummaryEl.textContent = String(filteredProgs).padStart(2, '0');
        const progLabelEl = document.querySelector('[data-summary-programs-label]');
        if (progLabelEl) {
          progLabelEl.textContent = filteredProgs === 1 ? 'Programme' : 'Programmes';
        }
      }
      if (currentFiltered.length > 0) {
        updateSpotlight(currentFiltered[0], 0);
      }
    }

    document.querySelector('[data-participant-search]')?.addEventListener('input', applyParticipantFilters);
    document.querySelector('[data-program-filter]')?.addEventListener('change', applyParticipantFilters);
    bindParticipantRowClicks();
  }

  document.querySelector('[data-student-search]')?.addEventListener('input', event => {
    const value = event.target.value.trim().toLowerCase();
    document.querySelectorAll('[data-student-list] article').forEach(card => { card.hidden = !card.textContent.toLowerCase().includes(value); });
  });
  let rating = 0;
  document.querySelectorAll('[data-rating]').forEach(button => button.addEventListener('click', () => {
    rating = Number(button.dataset.rating);
    document.querySelectorAll('[data-rating]').forEach(star => star.classList.toggle('active', Number(star.dataset.rating) <= rating));
  }));

  document.querySelector('[data-review-form]')?.addEventListener('submit', event => {
    event.preventDefault();
    if (!rating) {
      alert('Please select a star rating (1 to 5).');
      return;
    }
    const form = event.currentTarget;
    const comment = form.querySelector('textarea')?.value.trim() || '';
    const name = form.querySelector('input[type="text"]')?.value.trim() || '';
    const submitBtn = form.querySelector('button[type="submit"]');

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<span>Saving review...</span>';
    }
    
    fetch('api.php?action=submit_review', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'submit_review',
        rating: rating,
        comment: comment,
        name: name
      })
    })
    .then(res => res.json())
    .then(res => {
      if (res.success) {
        form.innerHTML = `<div class="review-thanks"><span>✓</span><h2>Review saved.</h2><p>${res.message || 'Thank you for your feedback.'}</p></div>`;
      } else {
        form.innerHTML = `<div class="review-thanks"><span style="background:#ea4335">✗</span><h2>Submission Failed</h2><p>${res.message || res.error || 'Something went wrong. Please try again.'}</p><button type="button" class="button button-review-submit" style="margin-top:20px;" onclick="setPage('review')"><span>Try Again</span><b>↺</b></button></div>`;
      }
    })
    .catch(err => {
      form.innerHTML = `<div class="review-thanks"><span style="background:#ea4335">✗</span><h2>Submission Failed</h2><p>Something went wrong. Please check your connection and try again.</p><button type="button" class="button button-review-submit" style="margin-top:20px;" onclick="setPage('review')"><span>Try Again</span><b>↺</b></button></div>`;
    });
  });
}

// Render immediately with initial server data
const initialPage = window.location.hash.replace('#', '') || 'home';
setPage(initialPage);

window.addEventListener('hashchange', () => {
  const page = window.location.hash.replace('#', '') || 'home';
  setPage(page);
});

// Fetch live data from database asynchronously to catch any changes
fetch('api.php?action=get_data')
  .then(res => res.json())
  .then(res => {
    if (res.success) {
      data.event = res.event;
      data.teams = res.teams;
      data.schedule = res.schedule;
      data.participants = res.participants;
      data.committee = res.committee;
      data.programs = res.programs || [];
      data.students = res.students || [];
      data.sections = res.sections || [];
      
      // Re-render the current active page with updated data
      const currentPage = window.location.hash.replace('#', '') || 'home';
      setPage(currentPage);
    }
  })
  .catch(err => {
    console.error('Failed to load database data:', err);
  });
