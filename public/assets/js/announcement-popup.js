'use strict';

(() => {
  const popup = document.querySelector('#announcement-popup');
  if (typeof popup?.showModal !== 'function') return;
  const now = new Date();
  const endsAt = Number(popup.dataset.endsAt);
  if (endsAt && endsAt * 1000 <= now.getTime()) return;
  const day = now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
  const key = 'd2s-announcement-popup';
  const dismissed = popup.dataset.id + ':' + day;
  try { if (localStorage.getItem(key) === dismissed) return; }
  catch { /* The announcement remains available when storage is disabled. */ }
  popup.addEventListener('close', () => {
    try { localStorage.setItem(key, dismissed); }
    catch { /* Dismissal is still effective on the current page. */ }
  });
  popup.addEventListener('click', event => {
    if (event.target === popup) popup.close();
  });
  popup.showModal();
})();
