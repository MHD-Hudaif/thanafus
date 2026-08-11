/**
 * scroll.js - High-performance smooth scroll engine
 * Optimised for 60-120fps with GSAP fallback, requestAnimationFrame throttling,
 * zero-reflow wheel handlers, and drag-scroll momentum.
 */

import { gsap } from 'gsap';
import { ScrollToPlugin } from 'gsap/ScrollToPlugin';

if (typeof window !== 'undefined') {
  try {
    gsap.registerPlugin(ScrollToPlugin);
  } catch (err) {
    // Fallback if ScrollToPlugin is already registered or unavailable
  }
}

var DEFAULT_DURATION = 0.45;
var activeTween = null;
var dragInitTimer = null;
var dragRafId = null;

function isWindow(target) {
  return target === window || target === document || target === document.documentElement || target === document.body;
}

function getScrollPosition(target) {
  if (isWindow(target)) {
    return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
  }
  return target ? target.scrollTop : 0;
}

function setScrollPosition(target, value) {
  if (isWindow(target)) {
    window.scrollTo(window.pageXOffset || 0, value);
  } else if (target) {
    target.scrollTop = value;
  }
}

function checkReducedMotion() {
  return typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function killActiveScroll() {
  if (activeTween) {
    try {
      activeTween.kill();
    } catch (e) {}
    activeTween = null;
  }
}

export function cancelScroll() {
  killActiveScroll();
}

/**
 * Smoothly scroll a target element or window to a given position.
 */
export function scrollTo(scroller, options) {
  scroller = scroller || window;
  var targetTop = 0;
  var duration = DEFAULT_DURATION;
  var ease = 'power2.out';
  var callback = null;

  if (typeof options === 'number') {
    targetTop = options;
  } else if (options && typeof options === 'object') {
    targetTop = typeof options.top === 'number' ? options.top : getScrollPosition(scroller);
    if (typeof options.duration === 'number') {
      duration = options.duration > 10 ? options.duration / 1000 : options.duration;
    }
    ease = options.ease || options.easing || 'power2.out';
    if (ease === 'ease-in-out') ease = 'power2.inOut';
    callback = options.callback || null;
  }

  targetTop = Math.max(0, targetTop);

  if (checkReducedMotion() || duration <= 0) {
    killActiveScroll();
    setScrollPosition(scroller, targetTop);
    if (callback) callback();
    return Promise.resolve();
  }

  killActiveScroll();

  return new Promise(function (resolve) {
    if (typeof gsap !== 'undefined' && gsap.to) {
      if (isWindow(scroller)) {
        activeTween = gsap.to(window, {
          duration: duration,
          scrollTo: { y: targetTop, autoKill: true },
          ease: ease,
          overwrite: 'auto',
          onComplete: function () {
            activeTween = null;
            if (callback) callback();
            resolve();
          },
          onInterrupt: function () {
            activeTween = null;
            resolve();
          }
        });
      } else {
        activeTween = gsap.to(scroller, {
          duration: duration,
          scrollTop: targetTop,
          ease: ease,
          overwrite: 'auto',
          onComplete: function () {
            activeTween = null;
            if (callback) callback();
            resolve();
          },
          onInterrupt: function () {
            activeTween = null;
            resolve();
          }
        });
      }
    } else {
      // Native fallback using window.scrollTo or element.scrollTo
      try {
        if (isWindow(scroller)) {
          window.scrollTo({ top: targetTop, behavior: 'smooth' });
        } else {
          scroller.scrollTo({ top: targetTop, behavior: 'smooth' });
        }
      } catch (e) {
        setScrollPosition(scroller, targetTop);
      }
      if (callback) callback();
      resolve();
    }
  });
}

export function smoothScrollTo(scroller, top, duration, easing) {
  return scrollTo(scroller, { top: top, duration: duration || 450, easing: easing || 'power2.out' });
}

export function scrollIntoView(element, options) {
  if (!element || !(element instanceof HTMLElement)) return Promise.resolve();
  options = options || {};
  var offset = typeof options.offset === 'number' ? options.offset : 78;
  var scroller = options.scroller || window;

  var rect = element.getBoundingClientRect();
  var currentScroll = getScrollPosition(scroller);
  var targetTop = rect.top + currentScroll - offset;

  return scrollTo(scroller, {
    top: targetTop,
    duration: options.duration || DEFAULT_DURATION,
    ease: options.ease || 'power2.out'
  });
}

var DRAG_SELECTORS = [
  '.schedule-tabs',
  '.schedule-grid',
  '.participant-programs',
  '.participant-results-scroll',
  '.college-student-filters',
  '.public-student-grid',
  '.college-student-list',
  '.student-records-scroll',
  '.admin-command-results',
  '.admin-live-program-list',
  '.admin-sidebar nav',
  '.admin-analytics-chart-scroll',
  '[data-drag-scroll]'
];

var HORIZONTAL_SELECTOR = DRAG_SELECTORS.join(',');

function onGlobalWheel(e) {
  if (e.defaultPrevented) return;
  
  var target = e.target;
  var container = target ? target.closest(HORIZONTAL_SELECTOR) : null;
  if (!container) return;

  var maxScroll = container.scrollWidth - container.clientWidth - 2;
  if (maxScroll <= 0) return;

  var delta = e.deltaY !== 0 ? e.deltaY : e.deltaX;
  if (!delta) return;

  var canScrollLeft = container.scrollLeft > 0;
  var canScrollRight = container.scrollLeft < maxScroll;

  if ((delta > 0 && canScrollRight) || (delta < 0 && canScrollLeft)) {
    e.preventDefault();
    container.scrollTo({
      left: container.scrollLeft + delta * 1.1,
      behavior: 'smooth'
    });
  }
}

var isMouseDown = false;
var startX = 0;
var startY = 0;
var scrollLeft = 0;
var scrollTop = 0;
var activeContainer = null;
var pendingMouseX = 0;
var pendingMouseY = 0;
var dragUpdateScheduled = false;

function updateDragScroll() {
  dragUpdateScheduled = false;
  if (!isMouseDown || !activeContainer) return;
  var rect = activeContainer.getBoundingClientRect();
  var x = pendingMouseX - rect.left;
  var y = pendingMouseY - rect.top;
  activeContainer.scrollLeft = scrollLeft - (x - startX) * 1.25;
  activeContainer.scrollTop = scrollTop - (y - startY) * 1.25;
}

function initDragContainers() {
  if (typeof document === 'undefined') return;

  var containers = document.querySelectorAll(DRAG_SELECTORS.join(','));
  containers.forEach(function (container) {
    if (container.dataset.dragScrollReady) return;
    container.dataset.dragScrollReady = 'true';
    if (!container.style.cursor) container.style.cursor = 'grab';

    container.addEventListener('mousedown', function (e) {
      if (e.button !== 0) return;
      if (e.target.closest('input, select, textarea, button, a[href], [role="button"]')) return;

      isMouseDown = true;
      activeContainer = container;
      var rect = container.getBoundingClientRect();
      startX = e.pageX - rect.left;
      startY = e.pageY - rect.top;
      scrollLeft = container.scrollLeft;
      scrollTop = container.scrollTop;
      container.style.cursor = 'grabbing';
      container.style.userSelect = 'none';
    });
  });
}

function scheduleDragInit() {
  if (dragInitTimer) clearTimeout(dragInitTimer);
  dragInitTimer = setTimeout(function () {
    dragInitTimer = null;
    initDragContainers();
  }, 100);
}

function onMouseUp() {
  if (!isMouseDown || !activeContainer) return;
  isMouseDown = false;
  activeContainer.style.cursor = 'grab';
  activeContainer.style.removeProperty('user-select');
  activeContainer = null;
}

function onMouseMove(e) {
  if (!isMouseDown || !activeContainer) return;
  pendingMouseX = e.pageX;
  pendingMouseY = e.pageY;
  if (!dragUpdateScheduled) {
    dragUpdateScheduled = true;
    dragRafId = requestAnimationFrame(updateDragScroll);
  }
}

export function enableMouseScrolling() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  initDragContainers();
}

export function init() {
  if (typeof document === 'undefined') return;

  document.documentElement.style.scrollBehavior = 'auto';
  document.documentElement.classList.add('smooth-scroll-ready');

  document.addEventListener('click', function (event) {
    var anchor = event.target.closest('a[href*="#"]');
    if (!anchor) return;

    var href = anchor.getAttribute('href');
    if (!href || href === '#') return;

    var parts = href.split('#');
    var hash = parts[1];
    if (!hash) return;

    var currentPath = window.location.pathname;
    var targetPath = parts[0];

    if (targetPath && targetPath !== currentPath && targetPath !== '' && targetPath !== '/') {
      return;
    }

    var targetEl = document.getElementById(hash) || document.querySelector('[name="' + hash + '"]');
    if (targetEl) {
      event.preventDefault();
      var header = document.querySelector('.site-header');
      var headerHeight = header ? header.getBoundingClientRect().height : 78;

      scrollIntoView(targetEl, { offset: headerHeight + 16, duration: 0.45 });

      if (window.history && window.history.pushState) {
        window.history.pushState(null, null, '#' + hash);
      }
    }
  });

  // Attach wheel listeners only to horizontal scroll containers, never globally on window
  var horizContainers = document.querySelectorAll(HORIZONTAL_SELECTOR);
  horizContainers.forEach(function(c) {
    c.addEventListener('wheel', onGlobalWheel, { passive: false });
  });

  document.addEventListener('mouseup', onMouseUp);
  document.addEventListener('mouseleave', onMouseUp);
  document.addEventListener('mousemove', onMouseMove, { passive: true });

  enableMouseScrolling();
  initScrollRevealObserver();

  if (typeof MutationObserver !== 'undefined') {
    var observer = new MutationObserver(function() {
      scheduleDragInit();
      initScrollRevealObserver();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }
}

export function initScrollRevealObserver() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  document.documentElement.classList.add('js-ready');
  if (typeof IntersectionObserver === 'undefined') {
    var reveals = document.querySelectorAll('.reveal');
    for (var i = 0; i < reveals.length; i++) {
      reveals[i].classList.add('visible');
    }
    return;
  }
  var observer = new IntersectionObserver(function (entries) {
    for (var j = 0; j < entries.length; j++) {
      var entry = entries[j];
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    }
  }, { threshold: 0.05, rootMargin: '0px 0px 60px 0px' });

  var elements = document.querySelectorAll('.reveal');
  for (var k = 0; k < elements.length; k++) {
    if (!elements[k].classList.contains('visible')) {
      observer.observe(elements[k]);
    }
  }
}

if (typeof window !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.ScrollJS = {
    scrollTo: scrollTo,
    smoothScrollTo: smoothScrollTo,
    scrollIntoView: scrollIntoView,
    cancelScroll: cancelScroll,
    enableMouseScrolling: enableMouseScrolling,
    init: init,
    gsap: gsap
  };
  window.smoothScrollTo = smoothScrollTo;
  window.scrollIntoView = scrollIntoView;
  window.cancelScroll = cancelScroll;
  window.enableMouseScrolling = enableMouseScrolling;
}

const ScrollJSExports = {
  scrollTo,
  smoothScrollTo,
  scrollIntoView,
  cancelScroll,
  enableMouseScrolling,
  init
};

export default ScrollJSExports;

