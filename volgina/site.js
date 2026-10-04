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
