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

// Hydrate hidden photographs one at a time, in their visible DOM order.
let galleryLoading = false;
async function loadGalleryInOrder() {
  if (galleryLoading) return;
  galleryLoading = true;
  try {
    for (const img of document.querySelectorAll('.gallery-grid img, .gallery-extra img[data-src]')) {
      if (galleryExtra.hidden) break;
      img.loading = 'eager';
      img.fetchPriority = 'low';
      if (img.dataset.src) {
        img.srcset = img.dataset.srcset || '';
        img.src = img.dataset.src;
        delete img.dataset.src;
        delete img.dataset.srcset;
      }
      try { await img.decode(); } catch { /* A failed photo must not stop the next one. */ }
      if (img.naturalWidth > 1) img.classList.add('is-loaded');
    }
  } finally { galleryLoading = false; }
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
      loadGalleryInOrder();
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
// A slightly fuller pen stroke follows the same cable geometry through the name.
const signatureInk = document.createElementNS('http://www.w3.org/2000/svg','path');
signatureInk.setAttribute('class','signature-ink');
signatureInk.setAttribute('fill','none');
journeySvg.append(signatureInk);
// Cut the cable out around each rendered line of text, with breathing room.
const svgNamespace = 'http://www.w3.org/2000/svg';
const lineDefs = document.createElementNS(svgNamespace,'defs');
const textClearance = document.createElementNS(svgNamespace,'mask');
textClearance.id = 'journey-text-clearance';
textClearance.setAttribute('maskUnits','userSpaceOnUse');
lineDefs.append(textClearance);
journeySvg.prepend(lineDefs);
journeyPath.setAttribute('mask','url(#journey-text-clearance)');
signatureInk.setAttribute('mask','url(#journey-text-clearance)');
function rebuildTextClearance() {
  const main = document.querySelector('main');
  const origin = main.getBoundingClientRect();
  const white = document.createElementNS(svgNamespace,'rect');
  white.setAttribute('width',main.clientWidth);
  white.setAttribute('height',main.scrollHeight);
  white.setAttribute('fill','white');
  const cutouts = [white];
  main.querySelectorAll('h1,h2,h3,p,blockquote,.price-note>span,.about-facts strong,.scroll-cue,.contact-channel').forEach(el => {
    const walker = document.createTreeWalker(el,NodeFilter.SHOW_TEXT);
    while(walker.nextNode()) {
      const node = walker.currentNode;
      if(!node.textContent.trim() || node.parentElement.closest('.signature,[aria-hidden]')) continue;
      const range = document.createRange();
      range.selectNodeContents(node);
      for(const r of range.getClientRects()) {
        if(!r.width || !r.height) continue;
        const rect = document.createElementNS(svgNamespace,'rect');
        const moving = el.closest('.reveal') && !el.closest('.reveal').classList.contains('in-view');
        const padding = 9;
        rect.setAttribute('x',r.left-origin.left-padding);
        rect.setAttribute('y',r.top-origin.top-padding-(moving?14:0));
        rect.setAttribute('width',r.width+padding*2);
        rect.setAttribute('height',r.height+padding*2+(moving?14:0));
        rect.setAttribute('fill','black');
        cutouts.push(rect);
      }
    }
  });
  textClearance.setAttribute('x','0');
  textClearance.setAttribute('y','0');
  textClearance.setAttribute('width',main.clientWidth);
  textClearance.setAttribute('height',main.scrollHeight);
  textClearance.replaceChildren(...cutouts);
}
document.querySelector('main').addEventListener('transitionend',event => {
  if(event.propertyName==='transform' && event.target.classList.contains('reveal')) rebuildTextClearance();
});
let signatureOffset = 0;
let signatureLength = 0;
let journeyLength = 0;
let journeySamples = [];
let journeyFrame = false;
let journeyRevealedEdge = 0;
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
  const reviewTrackBox = box('.reviews-grid'), firstReview = box('.review-photo');
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
    [[30,91],[67,63],[79,29]], [[94,-4],[92,3],[80,31]],
    [[68,65],[39,120],[52,118]], [[73,118],[121,45],[145,20]],
    [[128,51],[103,112],[118,110]], [[133,108],[149,86],[159,75]],
    [[151,99],[140,132],[132,149]], [[147,117],[165,70],[180,73]],
    [[198,74],[187,105],[163,107]], [[181,108],[199,89],[209,75]],
    [[202,91],[194,110],[203,110]], [[214,110],[227,88],[235,75]],
    [[228,92],[219,110],[229,110]], [[239,110],[252,86],[258,75]],
    [[251,91],[244,108],[247,110]], [[251,97],[258,90],[275,90]],
    [[279,78],[285,67],[281,77]], [[275,92],[267,110],[280,110]],
    [[290,107],[298,81],[313,76]], [[332,65],[321,104],[303,109]],
    [[286,113],[300,78],[317,77]], [[330,76],[321,105],[332,108]],
    [[345,111],[365,104],[380,104]]
  ];
  const point = ([a,b]) => [signature.x+a*signature.w/380, signature.y+b*signature.h/160];
  const signatureStart = point([0,104]);
  const signatureEnd = point([380,104]);
  let signaturePrefix = '';
  let signatureData = '';
  function writeSignature(reverse=false) {
    let start=[0,104];
    const segments=ink.map(segment=>{const result={start,controls:segment};start=segment[2];return result;});
    if(reverse)segments.reverse();
    const strokes = segments.map(({start,controls:[a,b,end]})=>{
      const points=reverse?[b,a,start]:[a,b,end];
      return ` C ${points.map(v=>p(...point(v))).join(' ')}`;
    }).join('');
    signaturePrefix = d;
    signatureData = `M ${p(...(reverse ? signatureEnd : signatureStart))}${strokes}`;
    return strokes;
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
    const cards = [...document.querySelectorAll('.review-photo')].map(el => {const r=el.getBoundingClientRect();return {x:r.left-origin.left+r.width*.5,y:r.top-origin.top-12};});
    cards.reverse().forEach(card => {
      // Equal arches and equal closed loops, drawn with identical local coordinates.
      d += ` C ${p(card.x+110,card.y-28)} ${p(card.x+85,card.y-28)} ${p(card.x+65,card.y-18)}`;
      d += ` C ${p(card.x+48,card.y-58)} ${p(card.x-20,card.y-58)} ${p(card.x-20,card.y-8)}`;
      d += ` C ${p(card.x-30,card.y+14)} ${p(card.x-3,card.y+24)} ${p(card.x-2,card.y+2)}`;
      d += ` C ${p(card.x,card.y-17)} ${p(card.x-20,card.y-22)} ${p(card.x-20,card.y-8)}`;
      d += ` L ${p(card.x-20,card.y+14)}`;
    });
    d += ` C ${p(x(.01),y(reviews,.88))} ${p(x(.8),reviews.y+reviews.h+10)} ${p(x(.92),contacts.y+120)}`;
    d += ` C ${p(x(1.03),y(contacts,.6))} ${p(x(.98),finalButton.y+finalButton.h*.5)} ${p(x(.92),finalButton.y+finalButton.h*.5)}`;
    d += ` L ${p(finalButton.x+finalButton.w,finalButton.y+finalButton.h*.5)}`;
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
    d += ` C ${p(x(.94),reviews.y+70)} ${p(reviewTrackBox.x+reviewTrackBox.w*.65,firstReview.y-35)} ${p(reviewTrackBox.x+reviewTrackBox.w*.5+7,firstReview.y-9)}`;
    d += ` L ${p(reviewTrackBox.x+reviewTrackBox.w*.5+7,firstReview.y+10)}`;
    d += ` C ${p(x(.01),y(reviews,.28))} ${p(x(.02),y(reviews,.91))} ${p(x(.38),reviews.y+reviews.h)}`;
    d += ` C ${p(x(1.05),contacts.y+10)} ${p(x(1.04),finalButton.y+finalButton.h*.5)} ${p(x(.98),finalButton.y+finalButton.h*.5)}`;
    d += ` L ${p(finalButton.x+finalButton.w,finalButton.y+finalButton.h*.5)}`;
  }
  journeySvg.setAttribute('viewBox', `0 0 ${width} ${main.scrollHeight}`);
  journeyPath.setAttribute('d', signaturePrefix);
  signatureOffset = journeyPath.getTotalLength();
  signatureInk.setAttribute('d',signatureData);
  signatureLength = signatureInk.getTotalLength();
  signatureInk.style.strokeDasharray = signatureLength;
  journeyPath.setAttribute('d', d);
  journeyLength = journeyPath.getTotalLength();
  journeySamples = Array.from({length:301}, (_,i) => {const length=journeyLength*i/300;return {length,y:journeyPath.getPointAtLength(length).y};});
  journeyPath.style.strokeDasharray = journeyLength;
  rebuildTextClearance();
  updateJourney();
}
function updateJourney() {
  journeyFrame = false;
  if (!journeyLength) return;
  if (motionPreference.matches) {journeyPath.style.strokeDashoffset='0';signatureInk.style.strokeDashoffset='0';return;}
  const main = document.querySelector('main');
  const r = main.getBoundingClientRect();
  const edge = innerHeight*.92-r.top;
  journeyRevealedEdge = Math.max(journeyRevealedEdge, edge);
  let visibleLength = 0;
  for (const sample of journeySamples) {if(sample.y>journeyRevealedEdge)break;visibleLength=sample.length;}
  journeyPath.style.strokeDashoffset = journeyLength-visibleLength;
  signatureInk.style.strokeDashoffset = signatureLength-Math.max(0,Math.min(signatureLength,visibleLength-signatureOffset));
}
function scheduleJourney() {
  if (!journeyFrame) {journeyFrame=true;requestAnimationFrame(updateJourney);}
}
addEventListener('scroll',scheduleJourney,{passive:true});
motionPreference.addEventListener('change',updateJourney);
new ResizeObserver(rebuildJourney).observe(document.querySelector('main'));
document.fonts.ready.then(rebuildJourney);
addEventListener('load',rebuildJourney,{once:true});
