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

// A single responsive cable connects the whole evening. Rebuild after gallery expansion.
const journeySvg = document.querySelector('.journey-line');
const journeyPath = document.querySelector('.journey-path');
let journeyLength = 0;
let journeySamples = [];
let journeyFrame = false;
function rebuildJourney() {
  const main = document.querySelector('main');
  const origin = main.getBoundingClientRect();
  const width = main.clientWidth;
  const mobile = width <= 760;
  const box = selector => {
    const r = document.querySelector(selector).getBoundingClientRect();
    return { x:r.left-origin.left, y:r.top-origin.top, w:r.width, h:r.height };
  };
  const hero = box('.hero'), photo = box('.hero-image'), cta = box('.hero .button');
  const about = box('.about'), formats = box('.formats'), comfort = box('.comfort');
  const gallery = box('.gallery'), reviews = box('.reviews'), contacts = box('.contacts');
  const finalButton = box('.contacts .button');
  const signature = box('.signature');
  const grid = box('.gallery-grid');
  // The opening composition has its own fixed geometry, independent of extra photos.
  const galleryBase = {y:gallery.y, h:grid.y+grid.h+parseFloat(getComputedStyle(document.querySelector('.gallery')).paddingBottom)-gallery.y};
  const x = fraction => width * fraction;
  const y = (r, fraction) => r.y + r.h * fraction;
  const p = (a,b) => `${a.toFixed(1)} ${b.toFixed(1)}`;
  // A handwritten, single-stroke Ирина. Each cubic joins the preceding one.
  const ink = [
    [[30,82],[68,58],[82,16]], [[96,-8],[76,16],[68,43]],
    [[56,82],[30,130],[45,132]], [[70,125],[121,45],[142,22]],
    [[124,56],[100,111],[116,112]], [[132,108],[151,83],[159,73]],
    [[151,101],[140,137],[130,158]], [[147,120],[164,69],[179,70]],
    [[198,70],[181,103],[160,101]], [[179,98],[200,79],[209,73]],
    [[201,87],[194,109],[204,104]], [[216,98],[228,77],[233,71]],
    [[226,86],[219,108],[229,104]], [[240,99],[250,76],[254,71]],
    [[245,92],[242,109],[249,103]], [[255,93],[266,87],[276,87]],
    [[284,68],[280,80],[272,101]], [[285,95],[294,82],[302,80]],
    [[322,62],[319,91],[302,103]], [[288,115],[296,87],[311,82]],
    [[329,64],[322,90],[325,99]], [[332,113],[361,91],[380,91]]
  ];
  const point = ([a,b]) => [signature.x+a*signature.w/380, signature.y+b*signature.h/160];
  const signatureStart = point([0,94]);
  const signatureEnd = point([380,91]);
  function writeSignature(reverse=false) {
    let start=[0,94];
    const segments=ink.map(segment=>{const result={start,controls:segment};start=segment[2];return result;});
    if(reverse)segments.reverse();
    return segments.map(({start,controls:[a,b,end]})=>{
      const points=reverse?[b,a,start]:[a,b,end];
      return ` C ${points.map(v=>p(...point(v))).join(' ')}`;
    }).join('');
  }
  let d = `M ${p(photo.x+photo.w*.46,photo.y+photo.h*.88)}`;
  if (!mobile) {
    d += ` C ${p(x(.72),y(hero,.94))} ${p(x(.38),cta.y-35)} ${p(cta.x+cta.w,cta.y+cta.h*.5)}`;
    d += ` C ${p(x(.23),y(hero,.88))} ${p(x(.23),y(hero,.98))} ${p(x(.72),about.y+90)}`;
    d += ` C ${p(x(1.04),about.y+170)} ${p(x(1.01),signatureEnd[1]+75)} ${p(signatureEnd[0]+90,signatureEnd[1]+20)}`;
    d += ` C ${p(signatureEnd[0]+40,signatureEnd[1]-50)} ${p(signatureEnd[0]+20,signatureEnd[1]-5)} ${p(...signatureEnd)}`;
    d += writeSignature(true);
    d += ` C ${p(signatureStart[0]-100,signatureStart[1]+70)} ${p(x(.3),y(about,.93))} ${p(x(.1),y(about,.93))}`;
    d += ` C ${p(x(-.05),about.y+about.h)} ${p(x(.92),formats.y-5)} ${p(x(.95),formats.y+185)}`;
    d += ` C ${p(x(1.06),y(formats,.52))} ${p(x(.64),y(formats,.58))} ${p(x(.57),y(formats,.55))}`;
    d += ` C ${p(x(.48),y(formats,.52))} ${p(x(.32),y(formats,.54))} ${p(x(.12),y(formats,.65))}`;
    d += ` C ${p(x(-.02),y(formats,.78))} ${p(x(.3),y(formats,.99))} ${p(x(.65),y(formats,.94))}`;
    d += ` C ${p(x(.98),y(formats,.8))} ${p(x(1.04),comfort.y+35)} ${p(x(.8),comfort.y+100)}`;
    d += ` C ${p(x(.64),comfort.y+160)} ${p(x(.64),y(comfort,.82))} ${p(x(.22),y(comfort,.87))}`;
    d += ` C ${p(x(-.08),y(comfort,.96))} ${p(x(.51),comfort.y+comfort.h-12)} ${p(x(.77),gallery.y+20)}`;
    d += ` C ${p(x(1.06),gallery.y+55)} ${p(x(.99),gallery.y+240)} ${p(x(.84),gallery.y+280)}`;
    d += ` C ${p(x(.55),y(galleryBase,.54))} ${p(x(-.04),y(galleryBase,.28))} ${p(x(.04),y(galleryBase,.68))}`;
    d += ` C ${p(x(.09),galleryBase.y+galleryBase.h-20)} ${p(x(.81),galleryBase.y+galleryBase.h-30)} ${p(x(.88),galleryBase.y+galleryBase.h)}`;
    d += ` C ${p(x(.98),galleryBase.y+galleryBase.h+70)} ${p(x(.98),reviews.y+40)} ${p(x(.88),reviews.y+100)}`;
    d += ` C ${p(x(1.04),reviews.y+170)} ${p(x(.96),reviews.y+245)} ${p(x(.89),reviews.y+260)}`;
    const cards = [...document.querySelectorAll('.review-photo')].map(el => {const r=el.getBoundingClientRect();return {x:r.left-origin.left+r.width*.5,y:r.top-origin.top-14};});
    cards.reverse().forEach(card => {d += ` C ${p(card.x+75,card.y-42)} ${p(card.x+10,card.y-36)} ${p(card.x,card.y)}`;});
    d += ` C ${p(x(.01),y(reviews,.88))} ${p(x(.8),reviews.y+reviews.h+10)} ${p(x(.92),contacts.y+120)}`;
    d += ` C ${p(x(1.03),y(contacts,.75))} ${p(x(.62),finalButton.y-10)} ${p(finalButton.x+finalButton.w+10,finalButton.y+finalButton.h*.5)}`;
    d += ` C ${p(finalButton.x+finalButton.w+40,finalButton.y+finalButton.h*.9)} ${p(finalButton.x+finalButton.w+35,finalButton.y+finalButton.h*.15)} ${p(finalButton.x+finalButton.w,finalButton.y+finalButton.h*.5)}`;
  } else {
    d += ` C ${p(x(.96),y(hero,.7))} ${p(x(.9),cta.y+cta.h+4)} ${p(x(.025),cta.y+cta.h+4)}`;
    d += ` C ${p(x(.015),cta.y+cta.h+100)} ${p(x(.015),about.y+150)} ${p(x(.04),about.y+205)}`;
    d += ` C ${p(x(.02),signatureStart[1]+30)} ${p(signatureStart[0]-50,signatureStart[1]+25)} ${p(...signatureStart)}`;
    d += writeSignature();
    d += ` C ${p(signatureEnd[0]+30,signatureEnd[1])} ${p(x(.95),y(about,.83))} ${p(x(.88),about.y+about.h-10)}`;
    d += ` C ${p(x(.93),formats.y+35)} ${p(x(.05),formats.y-20)} ${p(x(.05),formats.y+250)}`;
    d += ` C ${p(x(-.04),y(formats,.48))} ${p(x(1.09),y(formats,.44))} ${p(x(.96),y(formats,.74))}`;
    d += ` C ${p(x(.86),formats.y+formats.h)} ${p(x(.02),comfort.y-10)} ${p(x(.02),comfort.y+125)}`;
    d += ` C ${p(x(-.04),y(comfort,.55))} ${p(x(1.08),y(comfort,.67))} ${p(x(.95),y(comfort,.86))}`;
    d += ` C ${p(x(.85),comfort.y+comfort.h)} ${p(x(.05),gallery.y-10)} ${p(x(.03),gallery.y+200)}`;
    d += ` C ${p(x(-.07),y(galleryBase,.69))} ${p(x(.98),y(galleryBase,.76))} ${p(x(.97),galleryBase.y+galleryBase.h)}`;
    d += ` C ${p(x(.99),galleryBase.y+galleryBase.h+40)} ${p(x(.99),gallery.y+gallery.h)} ${p(x(.97),gallery.y+gallery.h+20)}`;
    d += ` C ${p(x(.94),reviews.y+70)} ${p(x(.64),reviews.y+120)} ${p(x(.5),reviews.y+135)}`;
    d += ` C ${p(x(.01),y(reviews,.28))} ${p(x(.02),y(reviews,.91))} ${p(x(.38),reviews.y+reviews.h)}`;
    d += ` C ${p(x(1.05),contacts.y+10)} ${p(x(1.07),y(contacts,.8))} ${p(finalButton.x+finalButton.w+7,finalButton.y+finalButton.h*.5)}`;
    d += ` C ${p(finalButton.x+finalButton.w+23,finalButton.y+finalButton.h)} ${p(finalButton.x+finalButton.w+23,finalButton.y)} ${p(finalButton.x+finalButton.w,finalButton.y+finalButton.h*.5)}`;
  }
  journeySvg.setAttribute('viewBox', `0 0 ${width} ${main.scrollHeight}`);
  journeyPath.setAttribute('d', d);
  journeyLength = journeyPath.getTotalLength();
  journeySamples = Array.from({length:301}, (_,i) => {const length=journeyLength*i/300;return {length,y:journeyPath.getPointAtLength(length).y};});
  journeyPath.style.strokeDasharray = journeyLength;
  updateJourney();
}
function updateJourney() {
  journeyFrame = false;
  if (!journeyLength) return;
  if (motionPreference.matches) {journeyPath.style.strokeDashoffset='0';return;}
  const main = document.querySelector('main');
  const r = main.getBoundingClientRect();
  const edge = innerHeight*.92-r.top;
  let visibleLength = 0;
  for (const sample of journeySamples) {if(sample.y>edge)break;visibleLength=sample.length;}
  journeyPath.style.strokeDashoffset = journeyLength-visibleLength;
}
function scheduleJourney() {
  if (!journeyFrame) {journeyFrame=true;requestAnimationFrame(updateJourney);}
}
addEventListener('scroll',scheduleJourney,{passive:true});
motionPreference.addEventListener('change',updateJourney);
new ResizeObserver(rebuildJourney).observe(document.querySelector('main'));
document.fonts.ready.then(rebuildJourney);
addEventListener('load',rebuildJourney,{once:true});
