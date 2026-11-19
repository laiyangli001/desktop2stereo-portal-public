'use strict';

const widget = document.querySelector('#support-widget');
const handle = document.querySelector('#support-widget-handle');
const qqLink = widget?.querySelector('.support-widget-qq');
const qqDialog = document.querySelector('#support-qq-dialog');
if (qqLink && typeof qqDialog?.showModal === 'function') {
  qqLink.addEventListener('click', event => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const image = qqDialog.querySelector('img');
    if (!image.hasAttribute('src')) image.src = qqLink.href;
    qqDialog.showModal();
  });
}
if (widget && handle) {
  const key = widget.dataset.storageKey || 'd2s-support-widget-position';
  const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
  const apply = (left, top) => {
    const maxLeft = Math.max(8, window.innerWidth - widget.offsetWidth - 8);
    const maxTop = Math.max(8, window.innerHeight - widget.offsetHeight - 8);
    const x = clamp(left, 8, maxLeft);
    const y = clamp(top, 8, maxTop);
    widget.style.left = x + 'px';
    widget.style.top = y + 'px';
    widget.style.right = 'auto';
    widget.style.bottom = 'auto';
    return { x, y };
  };
  const defaultPosition = () => {
    const edge = window.innerWidth <= 560 ? 16 : 24;
    const bottom = window.innerWidth <= 560 ? 76 : 88;
    return apply(window.innerWidth - widget.offsetWidth - edge, window.innerHeight - widget.offsetHeight - bottom);
  };
  try {
    const saved = JSON.parse(localStorage.getItem(key) || 'null');
    if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) apply(saved.x, saved.y);
    else defaultPosition();
  } catch {
    defaultPosition();
  }
  let dragging = false;
  let offsetX = 0;
  let offsetY = 0;
  let moved = false;
  handle.addEventListener('pointerdown', (event) => {
    dragging = true;
    moved = false;
    const box = widget.getBoundingClientRect();
    offsetX = event.clientX - box.left;
    offsetY = event.clientY - box.top;
    handle.setPointerCapture(event.pointerId);
    event.preventDefault();
  });
  handle.addEventListener('pointermove', (event) => {
    if (!dragging) return;
    moved = true;
    apply(event.clientX - offsetX, event.clientY - offsetY);
  });
  handle.addEventListener('pointerup', (event) => {
    if (!dragging) return;
    dragging = false;
    handle.releasePointerCapture(event.pointerId);
    const box = widget.getBoundingClientRect();
    const position = { x: box.left, y: box.top, moved };
    if (moved) localStorage.setItem(key, JSON.stringify({ x: position.x, y: position.y }));
  });
  window.addEventListener('resize', () => {
    const box = widget.getBoundingClientRect();
    const position = apply(box.left, box.top);
    localStorage.setItem(key, JSON.stringify(position));
  });
}
