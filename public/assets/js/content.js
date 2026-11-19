'use strict';

const app = document.querySelector('#content-app');
const section = app?.dataset.section || 'site';
const message = document.querySelector('#content-message');
const tr = (key, params = {}) => window.D2SI18n.tr('content_admin.' + key, params);
const publicationErrors = {
  translation_provider_disabled: window.D2SI18n.tr('operations.errors.translation_provider_disabled'),
  translation_api_key_required: window.D2SI18n.tr('operations.errors.translation_api_key_required'),
  translation_language_mapping_invalid: window.D2SI18n.tr('operations.errors.translation_language_mapping_invalid'),
};
const siteForm = document.querySelector('#site-content-form');
const announcements = document.querySelector('#announcement-editor');
const policyEditor = document.querySelector('#policy-editor');
const policyTypeSelect = document.querySelector('#policy-type');
const policyLocaleSelect = document.querySelector('#policy-locale');
const policyForm = document.querySelector('#policy-form');
const policyTitle = document.querySelector('#policy-title');
const policyBody = document.querySelector('#policy-body');
const policyStatus = document.querySelector('#policy-status');
const policyDraftButton = document.querySelector('#policy-draft');
const policyPublishButton = document.querySelector('#policy-publish');
const policyTranslationTarget = document.querySelector('#policy-translation-target');
const selectedPublicationLanguages = container => Array.from(container?.querySelectorAll('input:checked') || [], field => field.value);
function renderPublicationLanguages(container, locales, source, selected = ['en']) {
  if (!container) return;
  container.replaceChildren();
  for (const locale of locales) {
    const field = document.createElement('input');
    field.type = 'checkbox'; field.value = locale.code;
    field.checked = [source, 'en', ...selected].includes(locale.code);
    field.disabled = [source, 'en'].includes(locale.code);
    const option = document.createElement('label');
    option.append(field, document.createTextNode(locale.native_name));
    container.append(option);
  }
}
let policyCatalog = null;
let policyTranslation = null;
let state = null;
let busy = false;
let contentLocale = (section === 'announcements' ? new URLSearchParams(location.search).get('locale') : null) || document.documentElement.lang || 'zh-Hans';
let announcementEditId = app?.dataset.announcementId || '';
let announcementSourceLoaded = false;
const deletedAnnouncementIds = [];
const announcementPlacementLabels = {
  header: tr('announcements.placement_header'),
  home: tr('announcements.placement_home'),
  both: tr('announcements.placement_both'),
  popup: tr('announcements.placement_popup'),
};

function csrf() {
  const prefix = 'D2S_ADMIN_SESSION_CSRF=';
  const item = document.cookie.split('; ').find(value => value.startsWith(prefix));
  return item ? decodeURIComponent(item.slice(prefix.length)) : '';
}
function report(value) { message.textContent = value; }
function text(tag, value, className = '') {
  const node = document.createElement(tag);
  node.textContent = value ?? '';
  if (className) node.className = className;
  return node;
}
async function api(path, data) {
  const response = await fetch(path, {
    credentials: 'same-origin',
    ...(data === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(data),
    }),
  });
  const result = await response.json();
  if (!response.ok || !result.success) throw new Error(result.error || 'service_unavailable');
  return result.data;
}
function input(name, value, type = 'text', max = 255) {
  const field = document.createElement('input');
  field.name = name;
  field.type = type;
  field.maxLength = max;
  field.value = value ?? '';
  return field;
}
function timestampInputValue(value) {
  if (!Number.isSafeInteger(Number(value)) || Number(value) <= 0) return '';
  const date = new Date(Number(value) * 1000);
  if (!Number.isFinite(date.getTime())) return '';
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}
function timestampSeconds(value) {
  if (!value) return null;
  const milliseconds = new Date(value.replace(' ', 'T')).getTime();
  return Number.isFinite(milliseconds) ? Math.floor(milliseconds / 1000) : null;
}
function announcementTimeInput(name, value, caption) {
  const group = document.createElement('span'); group.className = 'announcement-date-picker';
  const field = input(name, timestampInputValue(value).replace('T', ' '));
  field.placeholder = tr('announcements.time_placeholder');
  field.pattern = '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}';
  field.maxLength = 16; field.autocomplete = 'off';
  const native = document.createElement('input'); native.type = 'datetime-local';
  native.value = timestampInputValue(value); native.tabIndex = -1; native.setAttribute('aria-hidden', 'true');
  native.dataset.calendarNative = name;
  native.style.cssText = 'position:absolute;inline-size:1px;block-size:1px;min-block-size:0;padding:0;border:0;opacity:0;pointer-events:none';
  const validate = () => {
    const seconds = timestampSeconds(field.value);
    const valid = !field.value || (seconds !== null && timestampInputValue(seconds).replace('T', ' ') === field.value);
    field.setCustomValidity(valid ? '' : tr('announcements.time_invalid'));
    native.value = valid ? field.value.replace(' ', 'T') : '';
    return valid;
  };
  field.addEventListener('input', validate);
  native.addEventListener('change', () => {
    field.value = native.value.replace('T', ' '); validate();
    field.dispatchEvent(new Event('change', {bubbles:true}));
  });
  const picker = document.createElement('button'); picker.type = 'button'; picker.className = 'announcement-calendar-toggle';
  picker.setAttribute('aria-label', caption); picker.title = caption;
  const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  icon.setAttribute('viewBox', '0 0 24 24'); icon.setAttribute('aria-hidden', 'true');
  icon.innerHTML = '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M7 3v4M17 3v4M7 14h3M14 14h3M7 17h3"/>';
  picker.append(icon); picker.disabled = typeof native.showPicker !== 'function';
  picker.addEventListener('click', () => { validate(); native.showPicker(); });
  group.append(field, picker, native);
  return {group, field, native, validate};
}
function closeLanguageMenus(except = null) {
  for (const menu of document.querySelectorAll('.announcement-language-popover[open]')) if (menu !== except) menu.open = false;
}
document.addEventListener('click', event => { if (!event.target.closest('.announcement-language-popover')) closeLanguageMenus(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeLanguageMenus(); });
function announcementLanguageMenu(codes, localeName) {
  if (!codes.length) return text('span', '—');
  const details = document.createElement('details'); details.className = 'announcement-language-popover';
  const summary = text('summary', String(codes.length)); summary.setAttribute('aria-label', tr('policy.published') + ': ' + codes.length);
  const arrow = text('span', '⌄'); arrow.setAttribute('aria-hidden', 'true'); summary.append(arrow);
  const panel = document.createElement('div'); panel.className = 'announcement-language-menu';
  panel.append(text('strong', tr('policy.published')));
  for (const code of codes) panel.append(text('span', localeName(code)));
  details.append(summary, panel);
  details.addEventListener('toggle', () => {
    if (!details.open) return;
    closeLanguageMenus(details);
    const anchor = summary.getBoundingClientRect(), size = panel.getBoundingClientRect();
    panel.style.left = Math.max(8, Math.min(anchor.left, innerWidth - size.width - 8)) + 'px';
    panel.style.top = Math.max(8, Math.min(anchor.bottom + 6, innerHeight - size.height - 8)) + 'px';
  });
  return details;
}

function announcementValues(row) {
  const read = name => row.querySelector('[name="' + name + '"]');
  return {
    id: row.dataset.id,
    title: read('title').value,
    body: read('body').value,
    enabled: read('enabled').checked,
    placement: read('placement').value,
    starts_at: timestampSeconds(read('starts_at').value),
    ends_at: timestampSeconds(read('ends_at').value),
    entity_lock_version: Number(row.dataset.entityLockVersion || 0),
    translation_lock_version: Number(row.dataset.translationLockVersion || 0),
  };
}

function publicationProgressNode(type, id) {
  const box = text('div', '', 'publication-progress');
  box.dataset.publicationType = type; box.dataset.publicationId = id;
  box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite');
  return box;
}
async function refreshPublicationProgress() {
  if (document.hidden) return;
  for (const box of document.querySelectorAll('[data-publication-id]')) {
    if (!box.dataset.publicationId) continue;
    try {
      const query = new URLSearchParams({type: box.dataset.publicationType, id: box.dataset.publicationId});
      const data = await api('/api/v2/admin/operations/publication-progress?' + query);
      if (!box.isConnected) continue;
      const names = codes => codes.map(code => state?.content_locales?.find(item => item.code === code)?.native_name || code).join(', ');
      const bar = document.createElement('progress');
      bar.max = Math.max(1, data.total); bar.value = data.completed;
      bar.setAttribute('aria-label', tr('publication.progress', data));
      box.replaceChildren(text('p', tr('publication.progress', data)), bar,
        text('p', tr('publication.translated', {languages: names(data.translated_locales) || '—'})));
      if (data.failed) box.append(text('p', tr('publication.failed', {count: data.failed})));
    } catch (error) {
      if (box.isConnected) box.textContent = tr('publication.progress_error');
    }
  }
}
let progressTimer = null;
function schedulePublicationProgress() {
  clearTimeout(progressTimer);
  refreshPublicationProgress().finally(() => {
    progressTimer = setTimeout(schedulePublicationProgress, 3000);
  });
}
window.addEventListener('pagehide', () => clearTimeout(progressTimer));

function announcementDirty(row, item) {
  const current = announcementValues(row);
  return current.title !== (item.title || '') || current.body !== (item.body || '')
    || current.enabled !== (item.enabled !== false)
    || current.placement !== (item.placement || 'header')
    || current.starts_at !== (item.starts_at ?? null)
    || current.ends_at !== (item.ends_at ?? null);
}
function label(name, field) {
  const wrapper = document.createElement('label');
  wrapper.append(document.createTextNode(name), field);
  return wrapper;
}
function renderAnnouncementList() {
  const container = document.querySelector('#announcement-list');
  if (!container || !state) return;
  container.replaceChildren();
  const filter = document.querySelector('#announcement-filter')?.value || 'all';
  const items = state.announcements.filter(item => filter === 'all'
    || (filter === 'review' ? item.needs_locale_review
      : (!item.needs_locale_review && (filter === 'published' ? item.is_published : !item.is_published))));
  if (!items.length) { container.append(text('p', tr('announcements.empty'), 'announcement-empty-list')); return; }
  const headings = [tr('announcements.title_field'), window.D2SI18n.tr('operations.edit_user.status'),
    tr('announcements.placement'), tr('announcements.starts_at') + ' / ' + tr('announcements.ends_at'),
    tr('policy.published'), window.D2SI18n.tr('operations.render_users.actions')];
  const table = document.createElement('table'); table.className = 'announcement-table';
  const caption = text('caption', tr('announcements.list')); caption.className = 'sr-only'; table.append(caption);
  const head = document.createElement('thead'), headingRow = document.createElement('tr');
  headings.forEach(value => { const th = text('th', value); th.scope = 'col'; headingRow.append(th); });
  head.append(headingRow); table.append(head);
  const tbody = document.createElement('tbody'); table.append(tbody);
  const localeName = code => state.content_locales.find(locale => locale.code === code)?.native_name || code;
  const date = value => value ? timestampInputValue(value).replace('T', ' ') : '—';
  for (const item of items) {
    const row = document.createElement('tr'); row.dataset.id = item.id;
    const cells = headings.map(heading => { const td = document.createElement('td'); td.dataset.label = heading; row.append(td); return td; });
    const draft = item.is_published ? null : item.draft;
    const title = draft?.title ?? (item.is_published ? (item.source_published_title || item.source_title) : item.source_title);
    const body = draft?.body ?? (item.is_published ? item.source_published_body : item.source_body);
    const language = draft?.locale || item.source_locale;
    const name = text('strong', title || item.title || item.id); name.lang = language; name.translate = false;
    const excerpt = text('small', (body || '').replace(/\s+/g, ' ').trim(), 'announcement-excerpt');
    excerpt.lang = language; excerpt.translate = false;
    const titleCopy = document.createElement('div'); titleCopy.className = 'announcement-title-copy';
    titleCopy.append(name, excerpt); cells[0].append(titleCopy);
    const statusLabel = item.needs_locale_review ? tr('announcements.review') : window.D2SI18n.tr(item.is_published
      ? (item.enabled ? 'operations.status.published' : 'operations.status.disabled') : 'operations.status.draft');
    const statusBadge = text('span', statusLabel, 'announcement-status');
    statusBadge.dataset.status = item.needs_locale_review ? 'review' : (item.is_published ? 'published' : 'draft');
    cells[1].append(statusBadge);
    const schedule = draft || item;
    cells[2].textContent = announcementPlacementLabels[schedule.placement];
    cells[3].append(text('span', date(schedule.starts_at)), text('span', date(schedule.ends_at)));
    cells[4].append(announcementLanguageMenu(item.needs_locale_review ? [] : item.published_locales || [], localeName));
    const actions = document.createElement('div'); actions.className = 'announcement-actions';
    const edit = text('a', window.D2SI18n.tr('operations.load.edit'), 'admin-control-link');
    edit.href = '/admin/content/announcements/' + encodeURIComponent(item.id);
    const remove = text('button', tr('publication.delete_all'), 'danger-button'); remove.type = 'button';
    remove.addEventListener('click', async () => {
      if (busy || !window.confirm(tr('publication.delete_all_confirm'))) return;
      busy = true; remove.disabled = true;
      try {
        await api('/api/v2/admin/content/announcements', {locale:contentLocale,items:[],deleted_ids:[item.id],publish:false});
        await load(contentLocale);
      } catch (error) { report(tr('announcements.save_error')); }
      finally { busy = false; remove.disabled = false; }
    });
    actions.append(edit, remove); cells[5].append(actions); tbody.append(row);
  }
  container.append(table);
}

function announcementEditorValue(item) {
  if (!item.draft || item.draft.locale !== contentLocale) return item;
  const {title, body, placement, enabled, starts_at, ends_at, target_locales} = item.draft;
  return {...item, title, body, placement, enabled, starts_at, ends_at,
    publication_locales: target_locales.length ? target_locales : item.publication_locales};
}

function renderAnnouncements() {
  if (!state) return;
  if (document.querySelector('#announcement-list')) { renderAnnouncementList(); return; }
  if (!announcements) return;
  const editorItems = announcementEditId === 'new' ? [{id:'',title:'',body:'',enabled:true,placement:'header'}]
    : state.announcements.filter(item => item.id === announcementEditId).map(announcementEditorValue);
  if (!editorItems.length) { report(tr('announcements.empty')); return; }
  const targets = document.querySelector('#announcement-publication-targets');
  if (targets && targets.dataset.locale !== contentLocale) {
    renderPublicationLanguages(targets, state.content_locales || [], contentLocale,
      [...new Set(editorItems.flatMap(item => item.publication_locales || ['en']))]);
    targets.dataset.locale = contentLocale;
  }
  announcements.replaceChildren();
  editorItems.forEach(item => {
    const row = document.createElement('div');
    row.className = 'content-row announcement-row';
    row.dataset.id = item.id || '';
    row.dataset.created = item.created_at || '';
    row.dataset.entityLockVersion = item.entity_lock_version || 0;
    row.dataset.translationLockVersion = item.translation_lock_version || 0;
    if (item.needs_locale_review) {
      const reviewNotice = text('p', tr('announcements.legacy_review_required'), 'content-review-warning');
      reviewNotice.setAttribute('role', 'status');
      row.append(reviewNotice);
    }
    const titleInput = input('title', item.title, 'text', 120); titleInput.required = true;
    row.append(label(tr('announcements.title_field'), titleInput));
    const body = document.createElement('textarea');
    body.name = 'body';
    body.rows = 12; body.required = true;
    body.maxLength = 3000;
    body.value = item.body || '';
    row.append(label(tr('announcements.body'), body));
    const schedule = document.createElement('div');
    schedule.className = 'announcement-schedule';
    const placement = document.createElement('select');
    placement.name = 'placement';
    placement.className = 'announcement-placement';
    for (const value of ['header', 'home', 'both', 'popup']) {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = announcementPlacementLabels[value];
      placement.append(option);
    }
    placement.value = Object.hasOwn(announcementPlacementLabels, item.placement) ? item.placement : 'header';
    schedule.append(label(tr('announcements.placement'), placement));
    const startControl = announcementTimeInput('starts_at', item.starts_at, tr('announcements.starts_at'));
    const endControl = announcementTimeInput('ends_at', item.ends_at, tr('announcements.ends_at'));
    const startsAt = startControl.field, endsAt = endControl.field;
    schedule.append(label(tr('announcements.starts_at'), startControl.group), label(tr('announcements.ends_at'), endControl.group));
    const enabledLabel = document.createElement('label');
    const enabled = document.createElement('input');
    enabled.type = 'checkbox';
    enabled.name = 'enabled';
    enabled.checked = item.enabled !== false;
    enabledLabel.append(enabled, document.createTextNode(tr('announcements.show')));
    schedule.append(enabledLabel);
    schedule.append(text('small', tr('announcements.schedule_help'), 'announcement-schedule-help'));
    row.append(schedule);
    const synchronizeSchedule = () => {
      const startValid = startControl.validate(), endValid = endControl.validate();
      endControl.native.min = startControl.native.value;
      if (startValid && endValid && startsAt.value && endsAt.value && timestampSeconds(endsAt.value) <= timestampSeconds(startsAt.value)) {
        endsAt.setCustomValidity(tr('announcements.time_invalid'));
      }
    };
    startsAt.addEventListener('change', synchronizeSchedule); endsAt.addEventListener('change', synchronizeSchedule);
    row.validateSchedule = synchronizeSchedule; synchronizeSchedule();
    if (item.id) row.append(publicationProgressNode('announcement', item.id));
    announcements.append(row);
  });
}
function readRows(container, kind) {
  return [...container.querySelectorAll('.content-row')].map(row => {
    const read = name => row.querySelector('[name="' + name + '"]');
    if (kind === 'announcement') return announcementValues(row);
    return {
      id: row.dataset.id,
      title: read('title').value,
      body: read('body').value,
      enabled: read('enabled').checked,
      entity_lock_version: Number(row.dataset.entityLockVersion || 0),
      translation_lock_version: Number(row.dataset.translationLockVersion || 0),
    };
  });
}
function policyFormHasChanges() {
  if (!policyTranslation || !policyTitle || !policyBody) return false;
  return policyTitle.value !== (policyTranslation.title || '') || policyBody.value !== (policyTranslation.body || '');
}
function renderPolicyEditor() {
  if (!policyEditor || !policyCatalog || !policyTranslation) return;
  policyTypeSelect.value = policyTranslation.policy_type;
  policyLocaleSelect.value = policyTranslation.locale;
  policyTitle.value = policyTranslation.title || '';
  policyBody.value = policyTranslation.body || '';
  const source = (policyCatalog.locales || []).find(locale => locale.code === policyCatalog.source_locale);
  const published = (policyCatalog.items || []).filter(item => item.published_revision_id)
    .map(item => item.native_name).join(', ');
  const outdated = (policyCatalog.items || []).filter(item => item.outdated)
    .map(item => item.native_name).join(', ');
  const d = policyEditor.dataset;
  const parts = [
    d.statusVersion + ' ' + policyCatalog.version,
    policyCatalog.effective_date ? d.statusEffective + ' ' + policyCatalog.effective_date : '',
    d.statusSource + ' ' + (source?.native_name || policyCatalog.source_locale),
    d.statusRevision + ' ' + policyCatalog.source_revision,
    d.statusPublished + ' ' + (published || d.statusMissing),
  ];
  if (outdated) parts.push(d.statusOutdated + ': ' + outdated);
  policyStatus.textContent = parts.filter(Boolean).join(' · ');
  policyTitle.disabled = !state?.can_settings;
  policyBody.disabled = !state?.can_settings;
  policyDraftButton.hidden = !state?.can_settings;
  policyPublishButton.hidden = !state?.can_settings;
  const deleteButton = document.querySelector('#policy-delete');
  if (deleteButton) deleteButton.hidden = !state?.can_settings;
  const progress = document.querySelector('#policy-publication-progress');
  if (progress) {
    progress.replaceChildren();
    if (policyCatalog.resource_id) progress.append(publicationProgressNode('policy', policyCatalog.resource_id));
  }
  renderPublicationLanguages(policyTranslationTarget, policyCatalog.locales || [], policyTranslation.locale, policyCatalog.publication_locales || ['en']);
  policyTranslationTarget.hidden = !state?.can_settings;
}
async function loadPolicy(locale = contentLocale) {
  const type = policyTypeSelect?.value || 'PRIVACY';
  const requested = new URLSearchParams({type,locale});
  const [catalog, translation] = await Promise.all([
    api('/api/v2/admin/content/policies?type=' + encodeURIComponent(type)),
    api('/api/v2/admin/content/policies/translation?' + requested.toString()),
  ]);
  policyCatalog = catalog;
  policyTranslation = translation;
  contentLocale = translation.locale;
  renderPolicyEditor();
}
async function load(locale = contentLocale) {
  contentLocale = locale;
  busy = true;
  app?.setAttribute('aria-busy', 'true');
  try {
    state = await api('/api/v2/admin/content?locale=' + encodeURIComponent(contentLocale));
    if (section === 'announcements' && announcementEditId && announcementEditId !== 'new' && !announcementSourceLoaded && !new URLSearchParams(location.search).has('locale')) {
      announcementSourceLoaded = true;
      const item = state.announcements.find(value => value.id === announcementEditId);
      const editorLocale = item?.draft?.locale || item?.source_locale;
      if (editorLocale && editorLocale !== contentLocale) {
        contentLocale = editorLocale;
        state = await api('/api/v2/admin/content?locale=' + encodeURIComponent(contentLocale));
      }
    }
    if (section === 'policies') {
      if (policyLocaleSelect) {
        policyLocaleSelect.replaceChildren(...(state.content_locales || []).map(locale => {
          const option = document.createElement('option');
          option.value = locale.code;
          option.textContent = locale.native_name;
          option.selected = locale.code === state.content_locale;
          return option;
        }));
      }
      contentLocale = state.content_locale;
      await loadPolicy(contentLocale);
    }
    if (section === 'site') {
      const selector = document.querySelector('#site-locale');
      if (selector) {
        selector.replaceChildren(...(state.content_locales || []).map(locale => {
          const option = document.createElement('option');
          option.value = locale.code;
          option.textContent = locale.native_name;
          option.selected = locale.code === state.content_locale;
          return option;
        }));
      }
      contentLocale = state.content_locale;
    }
    if (siteForm) {
      for (const [name, value] of Object.entries(state.site)) {
        if (siteForm.elements[name]) siteForm.elements[name].value = value ?? '';
      }
      siteForm.elements.tagline.placeholder = state.site.fallback_tagline || '';
      siteForm.elements.intro.placeholder = state.site.fallback_intro || '';
      for (const field of siteForm.querySelectorAll('input,textarea,button')) field.disabled = !state.can_settings;
    }
    const announcementAdd = document.querySelector('#announcement-add');
    const announcementSave = document.querySelector('#announcement-save');
    const announcementDraft = document.querySelector('#announcement-draft');
    if (announcementAdd) announcementAdd.hidden = !state.can_announcements;
    if (announcementSave) announcementSave.hidden = !state.can_announcements;
    if (announcementDraft) announcementDraft.hidden = !state.can_announcements;
    if (section === 'announcements') {
      const selector = document.querySelector('#announcement-locale');
      if (selector) {
        selector.replaceChildren(...(state.content_locales || []).map(locale => {
          const option = document.createElement('option');
          option.value = locale.code;
          option.textContent = locale.native_name;
          option.selected = locale.code === state.content_locale;
          return option;
        }));
        contentLocale = state.content_locale;
      }
      renderAnnouncements();
    }
    report('');
  } catch (error) {
    report(error.message === 'permission_denied' ? tr('error.permission') : tr('error.load'));
  } finally {
    busy = false;
    app?.setAttribute('aria-busy', 'false');
  }
}

function siteFormHasChanges() {
  if (!siteForm || !state?.site) return false;
  return [...new FormData(siteForm).entries()].some(([name, value]) => {
    if (name === 'translation_lock_version') return false;
    return String(value) !== String(state.site[name] ?? '');
  });
}
document.querySelector('#site-locale')?.addEventListener('change', event => {
  if (busy) return;
  if (siteFormHasChanges() && !window.confirm(tr('site.switch_language_discard'))) {
    event.target.value = contentLocale;
    return;
  }
  load(event.target.value);
});
siteForm?.addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || !state?.can_settings) return;
  try {
    await api('/api/v2/admin/content/site', {
      locale: contentLocale,
      values: Object.fromEntries(new FormData(siteForm)),
    });
    const success = tr('site.saved');
    await load(contentLocale);
    report(success);
  } catch (error) {
    report(tr('site.save_error'));
  }
});

async function savePolicy(publish) {
  if (busy || !policyEditor || !policyTranslation || !state?.can_settings) return;
  busy = true;
  policyDraftButton.disabled = true; policyPublishButton.disabled = true;
  try {
    const result = await api(publish ? '/api/v2/admin/content/policies/publish-languages' : '/api/v2/admin/content/policies', {
      policy_type: policyTranslation.policy_type,
      policy_version: policyTranslation.policy_version,
      locale: policyTranslation.locale,
      title: policyTitle.value,
      body: policyBody.value,
      lock_version: policyTranslation.lock_version,
      ...(publish ? {target_locales: selectedPublicationLanguages(policyTranslationTarget)} : {}),
      publish,
    });
    await load(contentLocale);
    report(publish && Object.keys(result.translation_jobs || {}).length
      ? Object.values(result.translation_jobs).map(job => tr('translation.queued', job)).join(' · ')
      : (publish ? policyEditor.dataset.successPublished : policyEditor.dataset.successDraft));
    await refreshPublicationProgress();
  } catch (error) {
    if (error.message === 'record_changed') report(policyEditor.dataset.errorConflict);
    else if (error.message === 'policy_source_missing') report(policyEditor.dataset.errorSource);
    else if (publicationErrors[error.message]) report(publicationErrors[error.message]);
    else report(policyEditor.dataset.error);
  } finally {
    busy = false;
    policyDraftButton.disabled = !state?.can_settings; policyPublishButton.disabled = !state?.can_settings;
  }
}
policyTypeSelect?.addEventListener('change', async event => {
  if (busy) return;
  if (policyFormHasChanges() && !window.confirm(policyEditor.dataset.confirmDiscard)) {
    event.target.value = policyTranslation.policy_type;
    return;
  }
  try {
    await loadPolicy(contentLocale);
    report('');
  } catch (error) {
    report(policyEditor.dataset.error);
  }
});
policyLocaleSelect?.addEventListener('change', async event => {
  if (busy) return;
  if (policyFormHasChanges() && !window.confirm(policyEditor.dataset.confirmDiscard)) {
    event.target.value = policyTranslation.locale;
    return;
  }
  try {
    await loadPolicy(event.target.value);
    report('');
  } catch (error) {
    report(policyEditor.dataset.error);
  }
});
document.querySelector('#policy-delete')?.addEventListener('click', async () => {
  if (busy || !policyTranslation || !state?.can_settings || !window.confirm(tr('publication.delete_all_confirm'))) return;
  busy = true;
  try {
    await api('/api/v2/admin/content/policies/delete', {
      policy_type: policyTranslation.policy_type, policy_version: policyTranslation.policy_version,
    });
    await load(contentLocale);
  } catch (error) { report(policyEditor.dataset.error); }
  finally { busy = false; }
});
policyDraftButton?.addEventListener('click', () => savePolicy(false));
policyPublishButton?.addEventListener('click', () => savePolicy(true));
document.querySelector('#announcement-filter')?.addEventListener('change', renderAnnouncementList);
document.querySelector('#announcement-form')?.addEventListener('submit', event => { event.preventDefault(); saveAnnouncements(false); });
async function saveAnnouncements(publish) {
  if (busy || !announcements) return;
  for (const row of announcements.querySelectorAll('.announcement-row')) row.validateSchedule?.();
  if (!document.querySelector('#announcement-form')?.reportValidity()) return;
  busy = true;
  app?.setAttribute('aria-busy', 'true');
  for (const button of document.querySelectorAll('#announcement-save, #announcement-draft')) button.disabled = true;
  try {
    const result = await api(publish ? '/api/v2/admin/content/announcements/publish-languages' : '/api/v2/admin/content/announcements/draft', {
      locale: contentLocale,
      items: readRows(announcements, 'announcement'),
      deleted_ids: [...new Set(deletedAnnouncementIds)],
      target_locales: selectedPublicationLanguages(document.querySelector('#announcement-publication-targets')),
      publish,
    });
    state.announcements = result.saved;
    deletedAnnouncementIds.length = 0;
    if (announcementEditId === 'new') {
      const created = state.announcements.find(item => item.id === result.created_ids?.[0]);
      if (created) {
        announcementEditId = created.id; app.dataset.announcementId = created.id;
        app.querySelector('h1').textContent = tr('announcements.edit');
        history.replaceState(null, '', '/admin/content/announcements/' + created.id + '?locale=' + encodeURIComponent(contentLocale));
      }
    }
    renderAnnouncements();
    const jobs = Object.values(result.translation_jobs || {}).flatMap(group => Object.values(group));
    report(publish && jobs.length ? jobs.map(job => tr('translation.queued', job)).join(' · ')
      : (publish ? tr('announcements.saved') : tr('announcements.draft_saved')));
    await refreshPublicationProgress();
  } catch (error) {
    report(publicationErrors[error.message] || (error.message === 'record_changed' ? tr('announcements.conflict') : tr('announcements.save_error')));
  } finally {
    busy = false;
    app?.setAttribute('aria-busy', 'false');
    for (const button of document.querySelectorAll('#announcement-save, #announcement-draft')) button.disabled = !state?.can_announcements;
  }
}
document.querySelector('#announcement-save')?.addEventListener('click', () => saveAnnouncements(true));
document.querySelector('#announcement-draft')?.addEventListener('click', () => saveAnnouncements(false));
document.querySelector('#announcement-locale')?.addEventListener('change', event => {
  if (busy) return;
  const hasDeleted = deletedAnnouncementIds.length > 0;
  const hasChanged = [...(announcements?.querySelectorAll('.announcement-row') || [])].some(row => {
    const item = state?.announcements.find(value => value.id === row.dataset.id);
    return !item || announcementDirty(row, announcementEditorValue(item));
  });
  if ((hasDeleted || hasChanged) && !window.confirm(tr('switch_language_discard'))) {
    event.target.value = contentLocale;
    return;
  }
  deletedAnnouncementIds.length = 0;
  load(event.target.value);
});
load().then(schedulePublicationProgress);
