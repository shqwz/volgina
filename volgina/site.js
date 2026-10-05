'use strict';

const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
const menu = document.querySelector('.menu-toggle');
const nav = document.querySelector('#navigation');
const header = document.querySelector('.header');
const navLinks = [...nav.querySelectorAll('a')];
const sections = navLinks.map(link => document.querySelector(link.hash));

function closeMenu() {
  nav.classList.remove('open');
  menu.setAttribute('aria-expanded', 'false');
  menu.setAttribute('aria-label', 'Открыть меню');
}
menu.addEventListener('click', () => {
  const open = menu.getAttribute('aria-expanded') !== 'true';
  nav.classList.toggle('open', open);
  menu.setAttribute('aria-expanded', String(open));
  menu.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
});
navLinks.forEach(link => link.addEventListener('click', closeMenu));
matchMedia('(min-width: 761px)').addEventListener('change', closeMenu);
document.addEventListener('click', event => {
  if (!header.contains(event.target)) closeMenu();
});

let scrollFrame = false;
function updateScrollEffects() {
  scrollFrame = false;
  const range = document.documentElement.scrollHeight - innerHeight;
  header.style.setProperty('--reading-progress', range > 0 ? scrollY / range : 0);
  let active = '';
  sections.forEach(section => {
    if (section.getBoundingClientRect().top <= innerHeight * .35) active = section.id;
  });
  navLinks.forEach(link => {
    const current = link.hash === '#' + active;
    link.classList.toggle('active', current);
    if (current) link.setAttribute('aria-current', 'location');
    else link.removeAttribute('aria-current');
  });
}
function scheduleScrollEffects() {
  if (!scrollFrame) {
    scrollFrame = true;
    requestAnimationFrame(updateScrollEffects);
  }
}
addEventListener('scroll', scheduleScrollEffects, { passive: true });
addEventListener('resize', scheduleScrollEffects);
updateScrollEffects();

// Text enters gently; photo tiles are never hidden by an observer.
if ('IntersectionObserver' in window) {
  const targets = document.querySelectorAll('.about-copy, .about-facts > div, .section-heading, .comfort-copy > *, .contacts > *');
  const revealObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('in-view');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { threshold: .05, rootMargin: '0px 0px 32px 0px' });
  targets.forEach((target, i) => {
    target.classList.add('reveal');
    target.style.setProperty('--reveal-delay', (i % 3) * 55 + 'ms');
    revealObserver.observe(target);
  });
  const applyMotionPreference = () => document.documentElement.classList.toggle('motion-ready', !motionPreference.matches);
  applyMotionPreference();
  motionPreference.addEventListener('change', applyMotionPreference);
  document.addEventListener('focusin', event => event.target.closest('.reveal')?.classList.add('in-view'));
}

const galleryExtra = document.querySelector('#gallery-extra');
const galleryToggle = document.querySelector('.gallery-toggle');
if (galleryExtra && galleryToggle) {
  galleryExtra.hidden = true;
  galleryToggle.hidden = false;
  galleryToggle.setAttribute('aria-expanded', 'false');
  galleryToggle.textContent = 'Посмотреть все фотографии';
  galleryToggle.addEventListener('click', () => {
    const expanding = galleryExtra.hidden;
    galleryExtra.hidden = !expanding;
    galleryToggle.setAttribute('aria-expanded', String(expanding));
    galleryToggle.textContent = expanding ? 'Скрыть фотографии' : 'Посмотреть все фотографии';
    if (expanding) {
      galleryExtra.querySelectorAll('img').forEach(img => { img.loading = 'eager'; });
    } else {
      galleryToggle.scrollIntoView({ block: 'center', behavior: 'instant' });
    }
    scheduleScrollEffects();
  });
}
document.querySelectorAll('main img').forEach(img => img.addEventListener('load', scheduleScrollEffects, { once: true }));

function arrow(direction) {
  return `<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="${direction === 'prev' ? 'M19 12H5m7-7-7 7 7 7' : 'M5 12h14m-7-7 7 7-7 7'}" stroke="currentColor" stroke-width="1.4"/></svg>`;
}

// One lightbox, separate sequences for event photographs and authentic reviews.
const dialog = document.querySelector('.lightbox');
const lightboxImage = dialog.querySelector('img');
const galleryPhotos = [...document.querySelectorAll('[data-lightbox]')];
const reviewPhotos = [...document.querySelectorAll('[data-review]')];
const previousPhoto = document.createElement('button');
previousPhoto.className = 'lightbox-prev';
previousPhoto.type = 'button';
previousPhoto.setAttribute('aria-label', 'Предыдущая фотография');
previousPhoto.innerHTML = arrow('prev');
const nextPhoto = document.createElement('button');
nextPhoto.className = 'lightbox-next';
nextPhoto.type = 'button';
nextPhoto.setAttribute('aria-label', 'Следующая фотография');
nextPhoto.innerHTML = arrow('next');
dialog.append(previousPhoto, nextPhoto);
let photoSequence = galleryPhotos;
let photoIndex = 0;
let photoOpener = null;
let savedOverflow = '';
function renderPhoto() {
  const link = photoSequence[photoIndex];
  const thumbnail = link.querySelector('img');
  lightboxImage.classList.toggle('review-clean-edges', thumbnail.classList.contains('review-clean-edges'));
  lightboxImage.alt = thumbnail.alt;
  lightboxImage.src = link.dataset.review || link.getAttribute('href');
  // Keep the dialog within the viewport; image geometry is established before decoding.
  lightboxImage.width = Number(thumbnail.getAttribute('width'));
  lightboxImage.height = Number(thumbnail.getAttribute('height'));
}
function stepPhoto(direction) {
  photoIndex = (photoIndex + direction + photoSequence.length) % photoSequence.length;
  renderPhoto();
}
[...galleryPhotos, ...reviewPhotos].forEach(link => link.addEventListener('click', event => {
  event.preventDefault();
  photoSequence = link.hasAttribute('data-review') ? reviewPhotos : galleryPhotos;
  photoIndex = photoSequence.indexOf(link);
  photoOpener = link;
  renderPhoto();
  savedOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  dialog.showModal();
}));
previousPhoto.addEventListener('click', () => stepPhoto(-1));
nextPhoto.addEventListener('click', () => stepPhoto(1));
dialog.querySelector('.lightbox-close').addEventListener('click', () => dialog.close());
dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
dialog.addEventListener('close', () => {
  document.body.style.overflow = savedOverflow;
  lightboxImage.removeAttribute('src');
  photoOpener?.focus({ preventScroll: true });
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeMenu();
  if (!dialog.open) return;
  if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
    event.preventDefault();
    stepPhoto(event.key === 'ArrowLeft' ? -1 : 1);
  }
});
let touchStart = null;
lightboxImage.addEventListener('touchstart', event => {
  if (event.touches.length === 1) touchStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
  else touchStart = null;
}, { passive: true });
lightboxImage.addEventListener('touchend', event => {
  if (!touchStart || !event.changedTouches.length) return;
  const dx = event.changedTouches[0].clientX - touchStart.x;
  const dy = event.changedTouches[0].clientY - touchStart.y;
  if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.4) stepPhoto(dx < 0 ? 1 : -1);
  touchStart = null;
}, { passive: true });
lightboxImage.addEventListener('touchcancel', () => { touchStart = null; }, { passive: true });

// Native scrolling supports swipes, keyboard focus and reduced motion.
const reviewTrack = document.querySelector('.reviews-grid');
if (reviewTrack) {
  const cards = [...reviewTrack.querySelectorAll('.review-photo')];
  const mobileReviews = matchMedia('(max-width: 760px)');
  reviewTrack.id = 'review-track';
  const controls = document.createElement('div');
  controls.className = 'review-controls';
  controls.setAttribute('aria-label', 'Перелистывание отзывов');
  controls.innerHTML = `<button class="review-step" type="button" data-direction="prev" aria-label="Предыдущий отзыв" aria-controls="review-track">${arrow('prev')}</button><div class="review-dots">${cards.map((_, i) => `<button type="button" class="review-dot" aria-label="Показать отзыв ${i + 1}" aria-controls="review-track"></button>`).join('')}</div><button class="review-step" type="button" data-direction="next" aria-label="Следующий отзыв" aria-controls="review-track">${arrow('next')}</button>`;
  reviewTrack.after(controls);
  const dots = [...controls.querySelectorAll('.review-dot')];
  const previous = controls.querySelector('[data-direction="prev"]');
  const next = controls.querySelector('[data-direction="next"]');
  let activeReview = 0;
  let reviewFrame = false;
  function updateReviewControls() {
    reviewFrame = false;
    if (!mobileReviews.matches) return;
    const center = reviewTrack.getBoundingClientRect().left + reviewTrack.clientWidth / 2;
    let nearest = Infinity;
    cards.forEach((card, i) => {
      const bounds = card.getBoundingClientRect();
      const distance = Math.abs(bounds.left + bounds.width / 2 - center);
      if (distance < nearest) { nearest = distance; activeReview = i; }
    });
    dots.forEach((dot, i) => dot.setAttribute('aria-current', String(i === activeReview)));
    previous.disabled = activeReview === 0;
    next.disabled = activeReview === cards.length - 1;
  }
  function goToReview(index) {
    const card = cards[Math.max(0, Math.min(cards.length - 1, index))];
    const distance = card.getBoundingClientRect().left + card.offsetWidth / 2 - reviewTrack.getBoundingClientRect().left - reviewTrack.clientWidth / 2;
    reviewTrack.scrollTo({ left: reviewTrack.scrollLeft + distance, behavior: motionPreference.matches ? 'instant' : 'smooth' });
  }
  dots.forEach((dot, i) => dot.addEventListener('click', () => goToReview(i)));
  previous.addEventListener('click', () => goToReview(activeReview - 1));
  next.addEventListener('click', () => goToReview(activeReview + 1));
  reviewTrack.addEventListener('scroll', () => {
    if (!reviewFrame) { reviewFrame = true; requestAnimationFrame(updateReviewControls); }
  }, { passive: true });
  addEventListener('resize', updateReviewControls);
  updateReviewControls();
}
