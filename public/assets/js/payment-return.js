// Browser return never confirms payment; the parent must re-read its authenticated order.
if (window.parent !== window) {
  for (const origin of new Set([window.location.origin, 'https://d2s.site', 'https://d2s.top'])) {
    window.parent.postMessage({ type: 'd2s-alipay-return' }, origin);
  }
}
