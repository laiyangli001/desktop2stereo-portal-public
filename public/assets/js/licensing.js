'use strict';

const app = document.querySelector('#license-app');
const notice = document.querySelector('#license-message');
const entitlementTitle = document.querySelector('#entitlement-title');
const entitlementDetail = document.querySelector('#entitlement-detail');
const current = document.querySelector('#current-session');
const list = document.querySelector('#device-list');
const locale = document.documentElement.lang;
const tr = (key, params = {}) => globalThis.D2SI18n.tr(key, params);
const logout = document.querySelector('#client-logout');
const refresh = document.querySelector('#license-refresh');
const selector = document.querySelector('#license-product');
const productLabel = document.querySelector('#license-product-title');
const purchase = document.querySelector('#license-purchase');
const overview = document.querySelector('#license-overview');
const overviewList = document.querySelector('#license-overview-list');
const downloads = document.querySelector('#license-downloads');
const downloadList = document.querySelector('#license-download-list');
const defaultTitle = productLabel.textContent.trim();
const initialQuery = new URLSearchParams(location.search);
let selectedProduct = initialQuery.get('product') || 'desktop2stereo';
let selectionExplicit = initialQuery.has('product');
let sequence = 0;
let mutating = false;
let session = null;
const statusText = { active: tr('licensing.state.active'), revoked: tr('licensing.state.revoked'), expired: tr('licensing.state.expired') };
const kindText = { trial: tr('licensing.kind.trial'), formal: tr('licensing.kind.formal') };

function date(value) { return value ? new Date(Number(value) * 1000).toLocaleString(locale) : '—'; }
function shortHash(value) { return value ? value.slice(0, 12) + '…' + value.slice(-8) : '—'; }
function text(tag, value, className = '') {
  const node = document.createElement(tag);
  node.textContent = value ?? '';
  if (className) node.className = className;
  return node;
}
function state(license) {
  return license.status === 'active' && license.expires_at && Number(license.expires_at) <= Date.now() / 1000
    ? 'expired' : license.status;
}
function licenseDetail(license) {
  return tr(license.expires_at ? 'licensing.license.expires' : 'licensing.license.permanent',
    {state:statusText[state(license)] || state(license), date:date(license.expires_at)});
}
function controls() {
  const busy = mutating || app.getAttribute('aria-busy') === 'true';
  refresh.disabled = busy;
  selector.disabled = busy;
  logout.disabled = busy || !session;
  for (const button of overviewList.querySelectorAll('button')) button.disabled = busy;
}
function switchProduct(product) {
  if (mutating) return;
  selectedProduct = product;
  selectionExplicit = true;
  const url = new URL(location.href);
  url.searchParams.set('product', product);
  history.replaceState(null, '', url.pathname + url.search);
  void load();
}
function renderProducts(data) {
  const selected = data.selected_product || {product:selectedProduct,
    title:selectedProduct === 'desktop2stereo' ? defaultTitle : selectedProduct, content_locale:locale, retired:false};
  const licenses = Array.isArray(data.licenses) ? data.licenses : (data.license ? [{...data.license,...selected}] : []);
  const products = Array.isArray(data.products) ? [...data.products] : [selected];
  if (!products.some(item => item.product === selectedProduct)) products.push(selected);
  selector.replaceChildren();
  for (const item of products) {
    const option = new Option(item.title + ' · ' + item.product, item.product);
    selector.append(option);
  }
  selector.value = selectedProduct;
  document.querySelector('#license-product-picker').hidden = products.length <= 1;
  productLabel.textContent = selected.title;
  productLabel.lang = selected.content_locale || locale;
  const target = new URL(globalThis.D2SI18n.accountUrls['/shop'], location.origin);
  if (selectedProduct !== 'desktop2stereo') target.searchParams.set('product', selectedProduct);
  purchase.href = target.pathname + target.search;
  purchase.hidden = selected.retired || data.license?.kind === 'formal' || Boolean(data.license && data.license.status !== 'active');
  overview.hidden = licenses.length === 0 && products.length <= 1;
  overviewList.replaceChildren();
  if (!licenses.length) overviewList.append(text('p', tr('licensing.products.empty')));
  for (const item of licenses) {
    const button = text('button', null, 'license-overview-card');
    button.type = 'button';
    button.dataset.licenseProduct = item.product;
    button.setAttribute('aria-pressed', String(item.product === selectedProduct));
    const title = text('strong', item.title || item.product);
    title.lang = item.content_locale || locale;
    button.append(title, text('span', kindText[item.kind] || item.kind), text('span', licenseDetail(item)));
    if (item.retired) button.append(text('small', tr('licensing.products.retired')));
    button.addEventListener('click', () => switchProduct(item.product));
    overviewList.append(button);
  }
}

async function loadSoftwareDownloads(currentRequest) {
  try {
    const response = await fetch('/api/v2/me/software-downloads', { credentials:'same-origin', headers:{'X-D2S-Locale':locale} });
    const result = await response.json();
    if (currentRequest !== sequence) return;
    if (!response.ok || !result.success) throw new Error(result.error || 'service_unavailable');
    const items = Array.isArray(result.data?.items) ? result.data.items : [];
    downloadList.replaceChildren();
    downloads.hidden = items.length === 0;
    for (const product of items) {
      const card = document.createElement('article'); card.className = 'device-item';
      const title = text('h3', product.title || product.product); title.lang = product.content_locale || locale; card.append(title);
      for (const release of product.platforms || []) {
        const platform = document.createElement('section');
        platform.append(text('h4', release.platform + ' · ' + release.version_name + ' (' + release.version_code + ')'));
        if (release.release_notes) platform.append(text('p', release.release_notes));
        for (const file of release.files || []) {
          const filePanel = document.createElement('div'); filePanel.className = 'device-item';
          filePanel.append(text('strong', file.file_name));
          filePanel.append(text('p', file.architecture + ' · ' + file.file_format + ' · ' + Number(file.byte_size).toLocaleString(locale) + ' B'));
          const hashRow = document.createElement('div'); hashRow.className = 'operation-panel-actions';
          hashRow.append(text('code', 'SHA-256: ' + file.sha256));
          const copy = text('button', tr('licensing.downloads.copy')); copy.type = 'button';
          copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(file.sha256); copy.textContent = tr('licensing.downloads.copied'); }
            catch { copy.textContent = file.sha256; }
          });
          hashRow.append(copy); filePanel.append(hashRow);
          for (const channel of file.channels || []) {
            const channelRow = document.createElement('p');
            const link = text('a', channel.channel_name + (channel.provider ? ' · ' + channel.provider : '') + ' · ' + channel.region_code);
            link.href = channel.download_url; link.target = '_blank'; link.rel = 'noopener noreferrer';
            channelRow.append(link);
            if (channel.passcode) channelRow.append(text('span', ' · ' + tr('licensing.downloads.passcode') + ': ' + channel.passcode));
            filePanel.append(channelRow);
          }
          if (!(file.channels || []).length) filePanel.append(text('p', tr('licensing.downloads.no_channels')));
          platform.append(filePanel);
        }
        card.append(platform);
      }
      downloadList.append(card);
    }
  } catch {
    if (currentRequest === sequence) downloads.hidden = true;
  }
}

async function load(preferOwned = false) {
  const currentRequest = ++sequence;
  app.setAttribute('aria-busy', 'true');
  notice.textContent = tr('licensing.loading.detail');
  session = null;
  controls();
  try {
    const query = selectedProduct === 'desktop2stereo' ? '' : '?product=' + encodeURIComponent(selectedProduct);
    const response = await fetch('/api/v2/me/license/devices' + query, { credentials: 'same-origin', headers:{'X-D2S-Locale':locale} });
    const result = await response.json();
    if (currentRequest !== sequence) return;
    if (!response.ok || !result.success) throw new Error(result.error || 'service_unavailable');
    const data = result.data;
    await loadSoftwareDownloads(currentRequest);
    if (currentRequest !== sequence) return;
    if (preferOwned && !selectionExplicit && Array.isArray(data.licenses) && data.licenses.length &&
        (!data.license || state(data.license) !== 'active')) {
      const preferred = data.licenses.find(item => state(item) === 'active') || data.licenses[0];
      if (preferred.product !== selectedProduct) {
        selectedProduct = preferred.product;
        const url = new URL(location.href); url.searchParams.set('product', selectedProduct);
        history.replaceState(null, '', url.pathname + url.search);
        await load();
        return;
      }
    }
    renderProducts(data);
    if (!data.license) {
      entitlementTitle.textContent = tr('licensing.trial.not_started');
      entitlementDetail.textContent = tr('licensing.trial.description');
    } else {
      entitlementTitle.textContent = kindText[data.license.kind] || data.license.kind;
      entitlementDetail.textContent = licenseDetail(data.license);
    }
    session = data.current_session ? {...data.current_session,product:selectedProduct} : null;
    logout.hidden = !session;
    current.replaceChildren();
    if (session) {
      current.append(text('p', tr('licensing.session.digest',{digest:shortHash(session.device_hash)})));
      current.append(text('p', tr('licensing.session.verified',{date:date(session.verified_at)})));
      current.append(text('p', tr('licensing.session.expires',{date:date(session.expires_at)})));
    } else {
      current.append(text('p', tr('licensing.session.empty')));
    }
    list.replaceChildren();
    document.querySelector('#device-count').textContent = tr('licensing.devices.count',{count:data.devices.length});
    if (data.devices.length === 0) list.append(text('p', tr('licensing.devices.empty')));
    for (const device of data.devices) {
      const row = document.createElement('article');
      row.className = 'device-item';
      row.append(text('strong', shortHash(device.device_hash)));
      row.append(text('p', tr('licensing.devices.seen',{first:date(device.first_seen_at),last:date(device.last_seen_at)})));
      list.append(row);
    }
    notice.textContent = '';
  } catch (error) {
    if (currentRequest !== sequence) return;
    notice.textContent = error.message === 'session_invalid' || error.message === 'authentication_required'
      ? tr('licensing.errors.session') : tr('licensing.errors.unavailable');
    logout.hidden = true;
  } finally {
    if (currentRequest === sequence) {
      app.setAttribute('aria-busy', 'false');
      controls();
    }
  }
}

logout.addEventListener('click', async () => {
  if (!session || logout.disabled) return;
  if (!window.confirm(tr('licensing.logout.confirm'))) return;
  const snapshot = {...session};
  mutating = true;
  controls();
  const cookie = document.cookie.split('; ').find(value => value.startsWith('D2S_USER_SESSION_CSRF='));
  try {
    const response = await fetch('/api/v2/me/license/session/logout', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-D2S-Locale':locale, 'X-CSRF-Token': cookie ? decodeURIComponent(cookie.split('=')[1]) : '' },
      body: JSON.stringify({ product:snapshot.product, device_hash:snapshot.device_hash, session_version:Number(snapshot.session_version) })
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.error);
    await load();
    notice.textContent = tr('licensing.logout.success');
  } catch (error) {
    notice.textContent = error.message === 'client_session_changed' ? tr('licensing.logout.changed') : tr('licensing.logout.failed');
  } finally {
    mutating = false;
    controls();
  }
});

selector.addEventListener('change', () => switchProduct(selector.value));
refresh.addEventListener('click', () => void load());
void load(true);
