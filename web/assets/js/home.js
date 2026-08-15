(() => {
  const bars = [...document.querySelectorAll('[data-progress]')];
  const fillBars = () => bars.forEach(bar => {
    const progress = Math.max(0, Math.min(100, Number(bar.dataset.progress) || 0));
    bar.style.width = `${progress}%`;
  });

  if ('IntersectionObserver' in window && bars.length) {
    const panel = document.querySelector('.mini-leaderboard');
    const observer = new IntersectionObserver(entries => {
      if (!entries.some(entry => entry.isIntersecting)) return;
      fillBars();
      observer.disconnect();
    }, { threshold: .25 });
    if (panel) observer.observe(panel);
    else fillBars();
  } else {
    fillBars();
  }

  if (!window.matchMedia('(pointer: fine)').matches) return;
  const hero = document.querySelector('.festival-hero');
  const glows = [...document.querySelectorAll('.hero-glow')];
  hero?.addEventListener('pointermove', event => {
    const x = (event.clientX / window.innerWidth - .5) * 18;
    const y = (event.clientY / window.innerHeight - .5) * 12;
    glows.forEach((glow, index) => {
      const direction = index % 2 === 0 ? 1 : -1;
      glow.style.transform = `translate3d(${x * direction}px, ${y * direction}px, 0)`;
    });
  }, { passive: true });
})();
