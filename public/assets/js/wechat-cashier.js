// Render provider code URLs locally. Neither pixels nor the browser can prove payment.
const tr = key => globalThis.D2SI18n.tr(key);
export function renderWechatCode(container, data, order) {
  if (!data || data.status !== 'pending' || typeof data.code_url !== 'string' ||
      data.code_url.length > 1024 || !Number.isInteger(data.expires_at) ||
      data.expires_at <= Date.now() / 1000 || data.expires_at > Number(order.expires_at)) {
    throw new Error(tr('common.qr.expired'));
  }
  const uri = new URL(data.code_url);
  if (uri.protocol !== 'weixin:' || uri.username || uri.password || uri.port || uri.hash ||
      !((uri.hostname === 'wxpay' && uri.pathname === '/bizpayurl') ||
        (uri.hostname === 'pay.weixin.qq.com' && uri.pathname === '/bizpayurl/up')) ||
      !/^\?pr=[A-Za-z0-9_.~%+=&\/-]{1,800}$/.test(uri.search) || /%(?![a-f0-9]{2})/i.test(uri.search)) {
    throw new Error(tr('common.qr.invalid'));
  }
  if (!globalThis.qrcodegen) throw new Error(tr('common.qr.unavailable'));
  const qr = globalThis.qrcodegen.QrCode.encodeText(data.code_url, globalThis.qrcodegen.QrCode.Ecc.MEDIUM);
  const dimension = qr.size + 8;
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 ' + dimension + ' ' + dimension);
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', tr('common.qr.wechat'));
  svg.setAttribute('shape-rendering', 'crispEdges');
  const background = document.createElementNS(svg.namespaceURI, 'rect');
  background.setAttribute('width', '100%'); background.setAttribute('height', '100%');
  background.setAttribute('fill', '#fff'); svg.append(background);
  const path = document.createElementNS(svg.namespaceURI, 'path');
  const cells = [];
  for (let y = 0; y < qr.size; y++) for (let x = 0; x < qr.size; x++) {
    if (qr.getModule(x, y)) cells.push('M' + (x + 4) + ',' + (y + 4) + 'h1v1h-1z');
  }
  path.setAttribute('d', cells.join('')); path.setAttribute('fill', '#000'); svg.append(path);
  container.replaceChildren(svg);
}
