'use strict';
const menu = document.querySelector('.menu-toggle');
const nav = document.querySelector('#navigation');
function closeMenu(){ nav.classList.remove('open');menu.setAttribute('aria-expanded','false');menu.setAttribute('aria-label','Открыть меню'); }
menu.addEventListener('click',()=>{const open=menu.getAttribute('aria-expanded')!=='true';nav.classList.toggle('open',open);menu.setAttribute('aria-expanded',String(open));menu.setAttribute('aria-label',open?'Закрыть меню':'Открыть меню');});
nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',closeMenu));
const dialog=document.querySelector('.lightbox');
const image=dialog.querySelector('img');
document.querySelectorAll('[data-lightbox],[data-review]').forEach(trigger=>trigger.addEventListener('click',event=>{event.preventDefault();image.src=trigger.dataset.review||trigger.getAttribute('href');image.alt=trigger.querySelector('img')?.alt||trigger.querySelector('h3').textContent;dialog.showModal();document.body.style.overflow='hidden';}));
function closeLightbox(){dialog.close();}
dialog.querySelector('button').addEventListener('click',closeLightbox);
dialog.addEventListener('click',event=>{if(event.target===dialog)closeLightbox();});
dialog.addEventListener('close',()=>{document.body.style.overflow='';image.removeAttribute('src');});
document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMenu();});
const links=[...nav.querySelectorAll('a')];
const observer=new IntersectionObserver(entries=>{for(const entry of entries){if(entry.isIntersecting)links.forEach(link=>{const active=link.hash==='#'+entry.target.id;link.classList.toggle('active',active);if(active)link.setAttribute('aria-current','location');else link.removeAttribute('aria-current');});}},{rootMargin:'-15% 0px -55% 0px'});
links.forEach(link=>{const section=document.querySelector(link.hash);if(section)observer.observe(section);});

// Progressive motion: content stays visible if JS or browser features are unavailable.
const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');
const header = document.querySelector('.header');
const hero = document.querySelector('.hero');
const portrait = document.querySelector('.hero-image');
let framePending = false;
function updateScrollEffects() {
  framePending = false;
  const range = document.documentElement.scrollHeight - window.innerHeight;
  header.style.setProperty('--reading-progress', range > 0 ? window.scrollY / range : 0);
  header.classList.toggle('scrolled', window.scrollY > 35);
  if (!motionPreference.matches && finePointer.matches && window.scrollY < hero.offsetHeight) {
    portrait.style.setProperty('--portrait-shift', Math.min(18, window.scrollY * .025) + 'px');
  }
}
function scheduleScrollEffects() {
  if (!framePending) {
    framePending = true;
    requestAnimationFrame(updateScrollEffects);
  }
}
window.addEventListener('scroll', scheduleScrollEffects, { passive: true });
window.addEventListener('resize', scheduleScrollEffects);
updateScrollEffects();

if ('IntersectionObserver' in window) {
  const revealTargets = document.querySelectorAll('.about-photo, .about-copy, .about-facts > div, .section-heading, .format-card, .comfort-copy > *, .comfort-photo, .gallery-photo, .review-card, .contacts > *');
  const revealObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('in-view');
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: .08, rootMargin: '0px 0px -24px 0px' });
  revealTargets.forEach((target, index) => {
    target.classList.add('reveal');
    target.style.setProperty('--reveal-delay', (index % 3) * 75 + 'ms');
    revealObserver.observe(target);
  });
  function applyMotionPreference() {
    document.documentElement.classList.toggle('motion-ready', !motionPreference.matches);
  }
  applyMotionPreference();
  motionPreference.addEventListener('change', applyMotionPreference);
  // Keyboard navigation must never land on an invisible control.
  document.addEventListener('focusin', event => {
    event.target.closest('.reveal')?.classList.add('in-view');
  });
}

document.querySelectorAll('.button').forEach(button => {
  button.addEventListener('pointermove', event => {
    if (motionPreference.matches || !finePointer.matches) return;
    const bounds = button.getBoundingClientRect();
    button.style.setProperty('--mx', ((event.clientX - bounds.left - bounds.width / 2) * .045) + 'px');
    button.style.setProperty('--my', ((event.clientY - bounds.top - bounds.height / 2) * .1) + 'px');
  });
  button.addEventListener('pointerleave', () => {
    button.style.setProperty('--mx', '0px');
    button.style.setProperty('--my', '0px');
  });
});
