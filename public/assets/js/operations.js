'use strict';
(() => {
  const root = document.querySelector('#operations');
  const dashboard = document.querySelector('#operations-dashboard');
  if (!root && !dashboard) return;
  const locale = document.documentElement.lang;
  const tr = (key, parameters) => window.D2SI18n.tr(key, parameters);
  const todoSeverityNames = {
    critical: tr('operations.load_dashboard.severity_critical'),
    high: tr('operations.load_dashboard.severity_high'),
  };
  const section = root?.dataset.section || 'dashboard';
  const permissions = new Set(JSON.parse(root?.dataset.permissions || '[]'));
  const results = document.querySelector('#operation-results');
  const filters = document.querySelector('#operation-filters');
  const notice = document.querySelector('#operation-message');
  const dialog = document.querySelector('#operation-dialog');
  const detail = document.querySelector('#operation-detail');
  const dialogMessage = document.querySelector('#operation-dialog-message');
  const base = '/api/v2/admin/operations';
  const messages = {
    payment_configuration_incomplete: tr('operations.errors.payment_configuration_incomplete'),
    payment_identity_immutable_while_enabled: tr('operations.errors.payment_identity_immutable_while_enabled'),
    locale_settings_invalid: tr('locales.errors.invalid'),
    update_stage_input_invalid: tr('updates.errors.input'),
    update_url_invalid: tr('updates.errors.url'),
    update_host_denied: tr('updates.errors.host'),
    update_host_unresolvable: tr('updates.errors.host'),
    update_host_not_public: tr('updates.errors.host'),
    update_public_key_invalid: tr('updates.errors.configuration'),
    update_signature_invalid: tr('updates.errors.signature'),
    update_metadata_invalid: tr('updates.errors.metadata'),
    update_github_repository_invalid: tr('updates.github.error_source'),
    update_github_unavailable: tr('updates.github.error_source'),
    update_github_release_mismatch: tr('updates.github.error_mismatch'),
    update_github_selection_changed: tr('updates.github.error_selection'),
    update_artifact_invalid: tr('updates.errors.metadata'),
    update_artifact_hash_invalid: tr('updates.errors.hash'),
    update_download_failed: tr('updates.errors.download'),
    update_download_too_large: tr('updates.errors.size'),
    update_staging_unavailable: tr('updates.errors.storage'),
    update_staging_write_failed: tr('updates.errors.storage'),
    update_staging_public_path: tr('updates.errors.configuration'),
    update_stage_path_invalid: tr('updates.errors.storage'),
    update_stage_conflict: tr('updates.errors.conflict'),
    update_prepare_input_invalid: tr('updates.errors.input'),
    update_install_unavailable: tr('updates.errors.install'),
    update_install_launcher_failed: tr('updates.errors.install'),
    update_install_backup_failed: tr('updates.errors.install'),
    update_install_backup_unavailable: tr('updates.errors.install'),
    update_install_task_invalid: tr('updates.errors.install'),
    update_install_task_not_found: tr('updates.errors.install'),
    update_install_failed: tr('updates.errors.install'),
    update_restart_not_configured: tr('updates.errors.install'),
    update_service_restart_failed: tr('updates.errors.install'),
    update_install_health_failed: tr('updates.errors.install'),
    update_path_invalid: tr('updates.errors.prepare'),
    update_candidate_public_path: tr('updates.errors.prepare'),
    update_candidate_path_invalid: tr('updates.errors.prepare'),
    update_candidate_exists: tr('updates.errors.prepare'),
    update_candidate_invalid: tr('updates.errors.prepare'),
    update_candidate_unavailable: tr('updates.errors.prepare'),
    update_candidate_cleanup_failed: tr('updates.errors.prepare'),
    update_archive_path_invalid: tr('updates.errors.prepare'),
    update_archive_path_conflict: tr('updates.errors.prepare'),
    update_archive_duplicate: tr('updates.errors.prepare'),
    update_archive_file_limit: tr('updates.errors.prepare'),
    update_archive_size_limit: tr('updates.errors.prepare'),
    update_archive_extract_failed: tr('updates.errors.prepare'),
    update_release_manifest_missing: tr('updates.errors.prepare'),
    update_release_manifest_invalid: tr('updates.errors.prepare'),
    update_release_manifest_mismatch: tr('updates.errors.prepare'),
    update_migration_inventory_invalid: tr('updates.errors.prepare'),
    update_migration_inventory_mismatch: tr('updates.errors.prepare'),
    update_migration_hash_mismatch: tr('updates.errors.prepare'),
    update_php_symlink_invalid: tr('updates.errors.prepare'),
    update_php_file_too_large: tr('updates.errors.prepare'),
    update_php_unreadable: tr('updates.errors.prepare'),
    update_php_syntax_invalid: tr('updates.errors.prepare'),
    locale_enable_dependency: tr('locales.errors.dependency'),
    locale_catalog_missing: tr('locales.errors.catalog_missing'),
    locale_snapshot_publish_failed: tr('locales.errors.publication_failed'),
    locale_catalog_history_invalid: tr('locales.errors.catalog_history_invalid'),
    locale_catalog_history_bundle_missing: tr('locales.errors.catalog_history_bundle_missing'),
    locale_catalog_history_current: tr('locales.errors.catalog_history_current'),
    translation_locale_invalid: tr('locales.errors.translation_locale_invalid'),
    translation_catalog_query_invalid: tr('locales.errors.translation_catalog_query_invalid'),
    translation_catalog_input_invalid: tr('locales.errors.translation_catalog_input_invalid'),
    translation_source_changed: tr('locales.errors.translation_source_changed'),
    translation_message_invalid: tr('locales.errors.translation_message_invalid'),
    translation_catalog_invalid: tr('locales.errors.translation_catalog_invalid'),
    translation_package_invalid: tr('locales.errors.translation_package_invalid'),
    translation_package_too_large: tr('locales.errors.translation_package_too_large'),
    translation_package_mismatch: tr('locales.errors.translation_package_mismatch'),
    task_schedule_invalid: tr('operations.errors.task_schedule_invalid'),
    translation_provider_disabled: tr('operations.errors.translation_provider_disabled'),
    translation_api_key_required: tr('operations.errors.translation_api_key_required'),
    translation_settings_invalid: tr('operations.errors.translation_settings_invalid'),
    translation_language_mapping_invalid: tr('operations.errors.translation_language_mapping_invalid'),
    translation_source_stale: tr('operations.errors.translation_source_stale'),
    translation_target_is_source: tr('operations.errors.translation_target_is_source'),
    translation_monthly_budget_exceeded: tr('operations.errors.translation_monthly_budget_exceeded'),
    translation_job_not_found: tr('operations.errors.translation_job_not_found'),
    translation_source_missing: tr('operations.errors.translation_source_missing'),
    translation_result_structure_invalid: tr('operations.errors.translation_result_structure_invalid'),
    translation_result_invalid: tr('operations.errors.translation_result_invalid'),
    translation_provider_temporary: tr('operations.errors.translation_provider_temporary'),
    translation_provider_rejected: tr('operations.errors.translation_provider_rejected'),
    translation_provider_unavailable: tr('operations.errors.translation_provider_unavailable'),
    translation_provider_response_invalid: tr('operations.errors.translation_provider_response_invalid'),
    translation_source_language_unsupported: tr('operations.errors.translation_source_language_unsupported'),
    translation_language_mapping_missing: tr('operations.errors.translation_language_mapping_missing'),
    translation_transport_unavailable: tr('operations.errors.translation_transport_unavailable'),
    translation_source_invalid: tr('operations.errors.translation_source_invalid'),
    translation_source_hash_invalid: tr('operations.errors.translation_source_hash_invalid'),
    translation_glossary_invalid: tr('translation_glossary.errors.invalid'),
    translation_glossary_duplicate_term: tr('translation_glossary.errors.duplicate'),
    translation_glossary_placeholder_invalid: tr('translation_glossary.errors.placeholder_invalid'),
    translation_source_empty: tr('operations.errors.translation_source_empty'),
    translation_retry_invalid: tr('operations.errors.translation_retry_invalid'),
    article_archived: tr('operations.errors.article_archived'),
    permission_denied: tr('operations.errors.permission_denied'),
    record_changed: tr('operations.errors.record_changed'),
    product_download_invalid: tr('content_admin.product.download_invalid'),
    client_session_changed: tr('licensing.logout.changed'),
    client_session_invalid: tr('licensing.logout.failed'),
    last_super_administrator: tr('operations.errors.last_super_administrator'),
    permissions_invalid: tr('operations.errors.permissions_invalid'),
    account_or_contact_exists: tr('operations.errors.account_or_contact_exists'),
    username_invalid: tr('operations.errors.username_invalid'),
    email_invalid: tr('operations.errors.email_invalid'),
    phone_invalid: tr('operations.errors.phone_invalid'),
    verified_contact_required: tr('operations.errors.verified_contact_required'),
    wallet_balance_out_of_range: tr('operations.errors.wallet_balance_out_of_range'),
    export_too_many_rows: tr('operations.errors.export_too_many_rows'),
    password_policy: tr('operations.errors.password_policy'),
    field_required: tr('operations.errors.field_required'),
    session_invalid: tr('operations.errors.session_invalid'),
    article_body_required: tr('operations.errors.article_body_required'),
    article_content_invalid: tr('operations.errors.article_content_invalid'),
    article_content_too_long: tr('operations.errors.article_content_too_long'),
    article_slug_invalid: tr('operations.errors.article_slug_invalid'),
    article_category_invalid: tr('editor.category.errors.invalid'),
    article_category_not_found: tr('editor.category.errors.not_found'),
    article_translation_required: tr('editor.category.errors.translation_required'),
    idempotency_conflict: tr('operations.errors.idempotency_conflict'),
  };
  let offset = 0;
  let total = 0;
  let sequence = 0;
  let staffRoles = {};
  let staffModules = [];
  let staffRoleLabels = {};
  let staffDependencies = {};
  let paymentFields = {};
  let paymentSecretFields = {};
  let selectedPaymentProvider = location.hash.slice(1) || 'paypal';
  let selectedProductLocale = '';
  function node(tag, text, className) {
    const item = document.createElement(tag);
    if (text !== undefined && text !== null) item.textContent = String(text);
    if (className) item.className = className;
    return item;
  }
  function button(text, handler) {
    const item = node('button', text);
    item.type = 'button';
    item.addEventListener('click', handler);
    return item;
  }
  const statusNames = {
    active: tr('operations.status.active'), disabled: tr('operations.status.disabled'), pending: tr('operations.status.pending'),
    paid: tr('operations.status.paid'), payment_review: tr('operations.status.payment_review'), refund_pending: tr('operations.status.refund_pending'),
    partially_refunded: tr('operations.status.partially_refunded'), refunded: tr('operations.status.refunded'), cancelled: tr('operations.status.cancelled'),
    draft: tr('operations.status.draft'), published: tr('operations.status.published'), archived: tr('operations.status.archived'),
    paused: tr('operations.status.paused'), healthy: tr('operations.status.healthy'), missing: tr('operations.status.missing'), optional_missing: tr('operations.status.optional_missing'),
    stale: tr('operations.status.stale'), failed: tr('operations.status.failed'), running: tr('operations.status.running'),
  };
  function status(value) { return statusNames[value] || value; }
  function badge(text, muted = false) { return node('span', text, 'operation-badge' + (muted ? ' muted' : '')); }
  function date(value) {
    return value ? new Intl.DateTimeFormat(locale, {dateStyle: 'medium', timeStyle: 'short'}).format(Number(value) * 1000) : '—';
  }
  function money(value, currency) {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'code' }).format(Number(value) / 100);
  }
  function country(value) {
    if (value?.kind === 'country' && /^[A-Z]{2}$/.test(value.country_code || '')) {
      try { return new Intl.DisplayNames([locale], {type: 'region'}).of(value.country_code) || value.country_code; }
      catch { return value.country_code; }
    }
    if (value?.kind === 'local') return tr(value.local_scope === 'loopback' ? 'operations.geo.loopback' : 'operations.geo.private');
    return tr(value?.kind === 'unavailable' ? 'operations.geo.unavailable' : 'operations.geo.unknown');
  }
  function minor(value) {
    const match = /^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/.exec(value.trim());
    if (!match) throw new Error(tr('operations.minor.use_at_most_two_decimal_places'));
    const result = Number(match[2]) * 100 + Number((match[3] || '').padEnd(2, '0'));
    if (!Number.isSafeInteger(result) || result > 1000000000000) throw new Error(tr('operations.minor.amount_out_of_range'));
    return match[1] ? -result : result;
  }
  function message(error, target = notice) {
    if (target) target.textContent = error instanceof Error ? error.message : String(error || '');
  }
  async function request(path, body) {
    const options = { credentials: 'same-origin', cache: 'no-store', headers: {'X-D2S-Locale': document.documentElement.lang} };
    if (body !== undefined) {
      const prefix = 'D2S_ADMIN_SESSION_CSRF=';
      const cookie = document.cookie.split('; ').find(item => item.startsWith(prefix));
      options.method = 'POST';
      options.headers = { ...options.headers, 'Content-Type': 'application/json', 'X-CSRF-Token': cookie ? decodeURIComponent(cookie.slice(prefix.length)) : '' };
      options.body = JSON.stringify(body);
    }
    const response = await fetch(base + path, options);
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(messages[payload.error] || tr('operations.request.request_failed') + (payload.error || response.status));
    return payload.data;
  }
  function table(columns, rows) {
    const wrap = node('div', null, 'operation-table-wrap');
    const grid = node('table', null, 'operation-table');
    const head = node('thead'), heading = node('tr'), body = node('tbody');
    columns.forEach(column => heading.append(node('th', column[0])));
    head.append(heading);
    rows.forEach(row => {
      const line = node('tr');
      columns.forEach(column => {
        const cell = node('td');
        const value = column[1](row);
        if (value instanceof Node) cell.append(value); else cell.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
        line.append(cell);
      });
      body.append(line);
    });
    if (!rows.length) {
      const line = node('tr'), cell = node('td', tr('operations.table.no_records'), 'operation-empty');
      cell.colSpan = columns.length; line.append(cell); body.append(line);
    }
    grid.append(head, body); wrap.append(grid); return wrap;
  }
  function stack(primary, secondary) {
    const box = node('div');
    box.append(primary instanceof Node ? primary : node('span', primary));
    if (secondary) box.append(node('small', secondary));
    return box;
  }
  function showDialog(title) {
    document.querySelector('#operation-dialog-title').textContent = title;
    detail.replaceChildren(); message('', dialogMessage);
    if (!dialog.open) dialog.showModal();
  }
  function description(entries) {
    const list = node('dl', null, 'operation-detail-grid');
    entries.forEach(([label, value]) => {
      const cell = node('div'); cell.append(node('dt', label), node('dd', value === null || value === undefined || value === '' ? '—' : value)); list.append(cell);
    });
    return list;
  }
  function field(form, key, label, type = 'text', value = '', options = {}) {
    const wrapper = node('label', label, options.wide ? 'wide' : '');
    let input;
    if (options.choices) {
      input = node('select');
      options.choices.forEach(([optionValue, title]) => {
        const option = node('option', title); option.value = optionValue; input.append(option);
      });
    } else if (type === 'textarea') input = node('textarea');
    else { input = node('input'); input.type = type; }
    input.name = key;
    if (type === 'checkbox') input.checked = Boolean(value); else input.value = value ?? '';
    if (options.required) input.required = true;
    if (options.maxLength) input.maxLength = options.maxLength;
    if (type === 'password') input.autocomplete = 'new-password';
    if (options.rows) input.rows = options.rows;
    if (type === 'checkbox') { wrapper.classList.add('operation-checkbox'); wrapper.prepend(input); } else wrapper.append(input);
    form.append(wrapper); return input;
  }
  function makeForm(handler, successText = '') {
    const form = node('form', null, 'operation-form');
    const footer = node('footer');
    const submit = node('button', tr('operations.make_form.save'), 'portal-primary'); submit.type = 'submit';
    footer.append(submit);
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!form.reportValidity() || submit.disabled) return;
      submit.disabled = true; message('', dialog.open ? dialogMessage : notice);
      if (notice) notice.dataset.state = 'error';
      try {
        await handler(form, event); if (dialog.open && form.dataset.keepOpen !== 'true') dialog.close(); await load();
        if (successText && notice) { message(successText, notice); notice.dataset.state = 'success'; }
      }
      catch (error) { message(error, dialog.open ? dialogMessage : notice); }
      finally { submit.disabled = false; }
    });
    form.finish = () => form.append(footer);
    return form;
  }
  function licenseStatus(item) {
    const state = item.status === 'active' && item.expires_at && Number(item.expires_at) <= Date.now() / 1000 ? 'expired' : item.status;
    const names = {active: tr('licensing.state.active'), revoked: tr('licensing.state.revoked'), expired: tr('licensing.state.expired')};
    return names[state] || state;
  }
  function renderUserLicenses(user) {
    const licenses = Array.isArray(user.licenses) ? user.licenses : user.license ? [{...user.license, product: 'desktop2stereo', title: 'Desktop2Stereo'}] : [];
    const sessions = new Map((user.client_sessions || []).map(item => [item.product, item]));
    detail.append(node('h3', tr('licensing.products.title'), 'operation-section-title'));
    detail.append(table([
      [tr('licensing.products.label'), item => stack(item.title || item.product, item.product)],
      [tr('operations.user_detail.license'), item => stack(tr(item.kind === 'formal' ? 'licensing.kind.formal' : 'licensing.kind.trial'), licenseStatus(item))],
      [tr('operations.user_detail.license_expiry'), item => item.expires_at ? date(item.expires_at) : tr('operations.user_detail.no_expiry')],
      [tr('licensing.session.title'), item => {
        const session = sessions.get(item.product);
        return session ? stack(session.device_hash.slice(0, 16) + '…', date(session.verified_at)) : tr('licensing.session.empty');
      }],
      [tr('operations.render_users.actions'), item => {
        const session = sessions.get(item.product);
        if (!session || !permissions.has('users.manage')) return '—';
        const action = button(tr('licensing.session.logout'), async () => {
          if (!window.confirm(tr('licensing.products.logout_confirm', {product: item.title || item.product}))) return;
          action.disabled = true;
          try {
            await request('/users/' + user.id + '/client-session/logout', {product: item.product, device_hash: session.device_hash, session_version: Number(session.session_version)});
            await userDetail(user.id);
            message(tr('licensing.logout.success'), dialogMessage);
          } catch (error) { message(error, dialogMessage); }
          finally { action.disabled = false; }
        });
        action.dataset.sessionProduct = item.product;
        return action;
      }],
    ], licenses));
    const payments = user.software_payments || [];
    if (payments.length) {
      detail.append(node('h3', tr('licensing.products.payments'), 'operation-section-title'));
      const names = new Map(licenses.map(item => [item.product, item.title || item.product]));
      detail.append(table([
        [tr('licensing.products.label'), item => stack(names.get(item.product) || item.product, item.product)],
        [tr('operations.user_detail.currency'), item => item.currency],
        [tr('operations.user_detail.paid_orders'), item => item.paid_orders],
        [tr('operations.user_detail.paid'), item => money(item.paid_amount, item.currency)],
        [tr('operations.user_detail.refunded'), item => money(item.refunded_amount, item.currency)],
        [tr('operations.user_detail.net_paid'), item => money(item.net_amount, item.currency)],
        [tr('operations.user_detail.last_payment'), item => date(item.last_payment_at)],
      ], payments));
    }
  }
  async function userDetail(id) {
    showDialog(tr('operations.user_detail.user_details'));
    try {
      const user = await request('/users/' + id);
      const kind = Array.isArray(user.licenses) ? tr('licensing.products.count', {count: user.licenses.length}) : user.license?.kind === 'formal' ? tr('operations.user_detail.licensed') : user.license?.kind === 'trial' ? tr('operations.user_detail.trial') : tr('operations.user_detail.no_license');
      detail.append(description([
        [tr('operations.user_detail.username'), user.username], [tr('operations.user_detail.display_name'), user.display_name],
        [tr('operations.user_detail.account_status'), user.status], [tr('operations.user_detail.license'), kind],
        [tr('operations.user_detail.created'), date(user.created_at)], [tr('operations.user_detail.updated'), date(user.updated_at)],
        [tr('operations.user_detail.last_sign_in'), date(user.last_login_at)], [tr('operations.user_detail.sign_in_ip'), user.last_login_ip],
        [tr('operations.user_detail.sign_in_country_region'), country(user.login_country)],
        [tr('operations.user_detail.registration_ip'), user.registration?.ip],
        [tr('operations.user_detail.registration_country_region'), country(user.registration?.country)],
        [tr('operations.user_detail.recorded_successful_sign_ins'), user.login_count],
        [tr('operations.user_detail.password_changed'), date(user.password_changed_at)],
        [tr('operations.user_detail.referred_buyers'), user.effective_referrals],
        [tr('operations.user_detail.staff_note'), user.remark],
      ]));
      renderUserLicenses(user);
      user.contacts.forEach(contact => detail.append(node('p', (contact.kind === 'email' ? tr('operations.user_detail.email') : tr('operations.user_detail.phone')) + ': ' + contact.value + (contact.verified_at ? tr('operations.user_detail.verified') : tr('operations.user_detail.unverified')))));
      detail.append(node('h3', tr('operations.user_detail.payments_by_currency'), 'operation-section-title'));
      detail.append(table([
        [tr('operations.user_detail.currency'), item => item.currency], [tr('operations.user_detail.paid_orders'), item => item.paid_orders],
        [tr('operations.user_detail.paid'), item => money(item.paid_amount, item.currency)],
        [tr('operations.user_detail.refunded'), item => money(item.refunded_amount, item.currency)],
        [tr('operations.user_detail.net_paid'), item => money(item.net_amount, item.currency)],
        [tr('operations.user_detail.last_payment'), item => date(item.last_payment_at)],
      ], user.payments_by_currency));
      detail.append(node('h3', tr('operations.user_detail.wallet_and_commissions'), 'operation-section-title'));
      const balances = [
        ...user.wallets.map(item => ({...item, kind: tr('operations.user_detail.wallet'), available: item.balance, reserved: 0})),
        ...user.commission_wallets.map(item => ({...item, kind: tr('operations.user_detail.commission')})),
      ];
      detail.append(table([
        [tr('operations.user_detail.account'), item => item.kind], [tr('operations.user_detail.available'), item => money(item.available, item.currency)],
        [tr('operations.user_detail.withdrawal_reserve'), item => money(item.reserved, item.currency)],
      ], balances));
      user.commissions.forEach(item => detail.append(node('p', tr('operations.user_detail.total_net_commission') + ': ' + money(item.total_commission, item.currency))));
      const actions = node('div', null, 'operation-panel-actions');
      if (permissions.has('users.manage')) actions.append(button(tr('operations.user_detail.edit_account_reset_password'), () => editUser(user)));
      if (permissions.has('wallets.adjust')) actions.append(button(tr('operations.user_detail.adjust_wallet'), () => adjustWallet(user)));
      detail.append(actions, node('h3', tr('operations.user_detail.recent_sign_ins'), 'operation-section-title'));
      detail.append(table([
        [tr('operations.user_detail.time'), item => date(item.created_at)], [tr('operations.user_detail.result'), item => item.outcome],
        ['IP', item => item.source_ip], [tr('operations.user_detail.country_region'), item => country(item.country)],
      ], user.recent_logins));
    } catch (error) { message(error, dialogMessage); }
  }
  function editTask(task) {
    showDialog(tr('operations.edit_task.task_schedule') + ' · ' + task.task);
    const form = makeForm(async item => {
      await request('/tasks', {task: task.task, enabled: item.elements.enabled.value === 'true', interval: Number(item.elements.interval.value)});
    });
    field(form, 'enabled', tr('operations.edit_task.execution'), 'text', task.enabled ? 'true' : 'false', {choices: [['true', tr('operations.edit_task.enabled')], ['false', tr('operations.edit_task.paused')]]});
    field(form, 'interval', tr('operations.edit_task.interval_in_seconds'), 'number', task.interval);
    detail.append(node('p', tr('operations.edit_task.changes_apply_on_the_next_scheduler_call_pausing_payment_or')));
    form.finish(); detail.append(form);
  }
  function editUser(user) {
    showDialog(tr('operations.edit_user.edit_user') + ' · ' + user.username);
    const form = makeForm(async item => {
      const values = Object.fromEntries(new FormData(item));
      values.expected_session_version = Number(user.session_version);
      await request('/users/' + user.id, values);
    });
    field(form, 'username', tr('operations.edit_user.username_sign_in_id'), 'text', user.username, {required: true, maxLength: 32});
    field(form, 'display_name', tr('operations.user_detail.display_name'), 'text', user.display_name, {maxLength: 80});
    field(form, 'region', tr('operations.edit_user.account_region'), 'text', user.region, {choices: [['CN', tr('operations.edit_user.mainland_china')], ['INTL', tr('operations.edit_user.international')]]});
    form.append(node('p', tr('operations.edit_user.account_region_controls_registration_and_contact_rules_ip_country_is'), 'wide portal-help'));
    const contacts = Object.fromEntries(user.contacts.map(contact => [contact.kind, contact.value]));
    field(form, 'email', tr('operations.user_detail.email'), 'email', contacts.email || '', {maxLength: 254});
    const phone = field(form, 'phone', tr('operations.user_detail.phone'), 'tel', contacts.phone || '', {maxLength: 16});
    phone.placeholder = tr('operations.edit_user.mainland_china_mobile_number_optionally_86');
    form.append(node('p', tr('operations.edit_user.edit_email_and_phone_directly_saved_contacts_are_used_for'), 'wide portal-help'));
    field(form, 'status', tr('operations.edit_user.status'), 'text', user.status, {choices: [['active', tr('operations.status.active')], ['disabled', tr('operations.status.disabled')]]});
    field(form, 'remark', tr('operations.user_detail.staff_note'), 'textarea', user.remark, {wide: true, maxLength: 1000});
    field(form, 'new_password', tr('operations.edit_user.new_password_leave_blank_to_keep'), 'password');
    form.finish(); detail.append(form);
  }
  function adjustWallet(user) {
    showDialog(tr('operations.user_detail.adjust_wallet') + ' · ' + user.username);
    const key = crypto.randomUUID();
    const form = makeForm(async item => {
      const values = Object.fromEntries(new FormData(item));
      values.amount = minor(values.amount);
      values.idempotency_key = key;
      await request('/users/' + user.id + '/wallet', values);
    });
    field(form, 'currency', tr('operations.user_detail.currency'), 'text', 'CNY', {choices: [['CNY', 'CNY'], ['USD', 'USD']]});
    field(form, 'amount', tr('operations.adjust_wallet.adjustment_positive_adds_negative_deducts'), 'text', '', {required: true});
    field(form, 'reason', tr('operations.adjust_wallet.reason'), 'textarea', '', {required: true, wide: true, maxLength: 500});
    form.finish(); detail.append(form);
  }
  async function manageArticleCategories() {
    const localeData = await request('/articles/locales');
    const localeItems = localeData.items || [];
    if (!localeItems.length) throw new Error(tr('operations.edit_article.no_editable_languages_are_configured'));
    let currentLocale = localeItems.some(item => item.code === locale) ? locale : localeItems[0].code;
    showDialog(tr('editor.category.manage'));
    const toolbar = node('div', null, 'operation-form');
    const language = field(toolbar, 'locale', tr('editor.category.language'), 'text', currentLocale, {
      choices: localeItems.map(item => [item.code, item.native_name]),
    });
    const list = node('div');
    const addForm = node('form', null, 'operation-form');
    const newLabel = field(addForm, 'label', tr('editor.category.name'), 'text', '', {maxLength: 80, required: true});
    field(addForm, 'description', tr('editor.category.description'), 'textarea', '', {maxLength: 500, rows: 2});
    const addButton = node('button', tr('editor.category.add'), 'portal-primary');
    addButton.type = 'submit';
    addForm.append(addButton);
    detail.append(toolbar, addForm, list);

    const refresh = async () => {
      currentLocale = language.value;
      const data = await request('/articles/categories?locale=' + encodeURIComponent(currentLocale));
      list.replaceChildren();
      if (!(data.items || []).length) {
        list.append(node('p', tr('editor.category.empty'), 'portal-help'));
        return;
      }
      for (const category of data.items) {
        const form = node('form', null, 'operation-form');
        form.append(node('code', category.code, 'wide'));
        const labelInput = field(form, 'label', tr('editor.category.name'), 'text', category.current_label || '', {
          maxLength: 80, required: true,
        });
        labelInput.placeholder = category.fallback_label || category.code;
        field(form, 'description', tr('editor.category.description'), 'textarea', category.description || '', {
          maxLength: 500, rows: 2,
        });
        const save = node('button', tr('editor.category.save'), 'portal-primary');
        save.type = 'submit';
        form.append(save);
        form.addEventListener('submit', async event => {
          event.preventDefault();
          if (!form.reportValidity() || save.disabled) return;
          save.disabled = true;
          try {
            await request('/articles/categories', {
              code: category.code,
              locale: currentLocale,
              label: labelInput.value,
              description: form.elements.description.value,
              lock_version: category.lock_version,
            });
            message(tr('editor.category.saved'), dialogMessage);
            await refresh();
          } catch (error) {
            message(error, dialogMessage);
          } finally {
            save.disabled = false;
          }
        });
        list.append(form);
      }
    };
    language.addEventListener('change', () => {
      language.disabled = true;
      refresh().catch(error => message(error, dialogMessage)).finally(() => { language.disabled = false; });
    });
    addForm.addEventListener('submit', async event => {
      event.preventDefault();
      if (!addForm.reportValidity() || addButton.disabled) return;
      addButton.disabled = true;
      try {
        await request('/articles/categories', {
          locale: currentLocale,
          label: newLabel.value,
          description: addForm.elements.description.value,
        });
        addForm.reset();
        message(tr('editor.category.saved'), dialogMessage);
        await refresh();
      } catch (error) {
        message(error, dialogMessage);
      } finally {
        addButton.disabled = false;
      }
    });
    await refresh();
  }

  function publicationTargets(locales, source, selected = ['en']) {
    const group = node('fieldset', null, 'wide');
    group.append(node('legend', tr('content_admin.translation.target_language')),
      node('p', tr('content_admin.publication.targets_help'), 'portal-help'));
    group.selectSource = code => {
      for (const input of group.querySelectorAll('input')) {
        const required = [code, 'en'].includes(input.value);
        if (input.dataset.required === 'true' && !required && !selected.includes(input.value)) input.checked = false;
        input.disabled = required; input.dataset.required = String(required);
        if (required) input.checked = true;
      }
    };
    for (const item of locales) {
      const input = node('input'); input.type = 'checkbox'; input.value = item.code;
      input.checked = selected.includes(item.code);
      const label = node('label', item.native_name); label.prepend(input); group.append(label);
    }
    group.selectSource(source);
    group.values = () => [...group.querySelectorAll('input:checked')].map(input => input.value);
    return group;
  }
  function watchPublication(type, id, locales, container) {
    let stopped = false;
    const refresh = async () => {
      if (stopped || !container.isConnected) return;
      try {
        const data = await request('/publication-progress?' + new URLSearchParams({type, id}));
        if (!container.isConnected) return;
        const names = data.translated_locales.map(code => locales.find(item => item.code === code)?.native_name || code).join(', ');
        const bar = node('progress'); bar.max = Math.max(1, data.total); bar.value = data.completed;
        bar.setAttribute('aria-label', tr('content_admin.publication.progress', data));
        container.replaceChildren(node('p', tr('content_admin.publication.progress', data)), bar,
          node('p', tr('content_admin.publication.translated', {languages: names || '—'})));
        if (data.failed) container.append(node('p', tr('content_admin.publication.failed', {count: data.failed})));
      } catch (error) {
        if (container.isConnected) container.textContent = tr('content_admin.publication.progress_error');
      }
      if (!stopped && container.isConnected) setTimeout(refresh, 3000);
    };
    container.setAttribute('role', 'status'); container.setAttribute('aria-live', 'polite');
    void refresh();
    return () => { stopped = true; };
  }
  async function editArticle(article = {}) {
    if (!window.ArticleEditor || !window.Quill) {
      message(tr('operations.edit_article.the_rich_text_editor_failed_to_load_refresh_the_page'));
      return;
    }
    const localeItems = (await request('/articles/locales')).items || [];
    let currentLocale = article.source_locale || locale;
    if (!localeItems.some(item => item.code === currentLocale)) currentLocale = localeItems[0]?.code;
    if (!currentLocale) throw new Error(tr('operations.edit_article.no_editable_languages_are_configured'));
    const progress = article.id ? await request('/publication-progress?' + new URLSearchParams({type: 'article', id: article.id})) : null;
    let data = article.id ? await request('/articles/' + article.id + '/translations/' + encodeURIComponent(currentLocale)) : {};
    const categories = (await request('/articles/categories?locale=' + encodeURIComponent(currentLocale))).items || [];
    let publishIntent = false;
    showDialog(article.id ? tr('operations.edit_article.edit_article') : tr('operations.edit_article.new_article'));
    const form = makeForm(async item => {
      window.ArticleEditor.validate(pane.firstElementChild);
      const values = {...Object.fromEntries(new FormData(item)), locale: currentLocale, body_format: 'html',
        lock_version: Number(data.lock_version || 0)};
      if (publishIntent) {
        const result = await request('/articles/publish-languages', {...values, id: article.id || '', target_locales: targets.values()});
        form.dataset.keepOpen = 'true';
        await editArticle({...article, id: result.id, source_locale: currentLocale, slug: values.slug, category_id: values.category_id});
      } else if (article.id) {
        await request('/articles/' + article.id + '/translations/' + encodeURIComponent(currentLocale), values);
      } else {
        await request('/articles', values);
      }
      form.dataset.dirty = 'false';
    });
    field(form, 'slug', tr('operations.edit_article.slug'), 'text', article.slug || data.slug || '', {maxLength: 120, required: true});
    field(form, 'category_id', tr('operations.edit_article.category'), 'text', article.category_id || categories[0]?.code || '', {
      choices: categories.map(item => [item.code, item.label || item.code]), required: true,
    });
    const language = field(form, 'locale', tr('operations.edit_article.content_language'), 'text', currentLocale, {
      choices: localeItems.map(item => [item.code, item.native_name]), required: true,
    });
    const pane = node('div', null, 'article-locale-pane wide'); form.append(pane);
    const render = () => {
      const body = node('div'); pane.replaceChildren(body);
      field(body, 'title', tr('operations.edit_article.title'), 'text', data.title || '', {maxLength: 240, required: true});
      field(body, 'summary', tr('operations.edit_article.summary'), 'textarea', data.summary || '', {maxLength: 500, rows: 3, wide: true});
      window.ArticleEditor.mount(body, 'body', tr('operations.edit_article.content'), data.body || '', data.body_format || 'html', true);
      field(body, 'seo_title', tr('operations.edit_article.seo_title'), 'text', data.seo_title || '', {maxLength: 240});
      field(body, 'seo_description', tr('operations.edit_article.seo_description'), 'textarea', data.seo_description || '', {maxLength: 500, rows: 2, wide: true});
      for (const input of body.querySelectorAll('input, textarea, .ql-editor')) {
        input.lang = currentLocale; input.dir = localeItems.find(item => item.code === currentLocale)?.direction || 'ltr';
        input.setAttribute('translate', 'no');
      }
      form.dataset.dirty = 'false';
    };
    const targets = publicationTargets(localeItems, currentLocale, progress?.publication_locales || ['en']);
    form.append(targets);
    if (article.id) {
      const status = node('div', null, 'wide'); form.append(status);
      detail.append(form);
      const stop = watchPublication('article', article.id, localeItems, status);
      dialog.addEventListener('close', stop, {once: true});
    }
    language.addEventListener('change', async () => {
      const next = language.value;
      if (form.dataset.dirty === 'true' && !window.confirm(tr('content_admin.switch_language_discard'))) {
        language.value = currentLocale; return;
      }
      language.disabled = true;
      try {
        data = article.id ? await request('/articles/' + article.id + '/translations/' + encodeURIComponent(next)) : {};
        currentLocale = next; targets.selectSource(next); render();
      } catch (error) { language.value = currentLocale; message(error, dialogMessage); }
      finally { language.disabled = false; }
    });
    form.addEventListener('input', event => { if (event.target !== language) form.dataset.dirty = 'true'; });
    render(); form.finish();
    const draft = form.querySelector('footer button');
    draft.textContent = tr('content_admin.policy.save_draft');
    draft.addEventListener('click', () => { publishIntent = false; });
    const publish = node('button', tr('content_admin.publication.publish'), 'portal-primary'); publish.type = 'submit';
    publish.addEventListener('click', () => { publishIntent = true; });
    form.querySelector('footer').append(publish); detail.append(form);
  }
  function staffRole(staff) {
    const granted = new Set(staff.permissions || []);
    return Object.entries(staffRoles).find(([, values]) => values.length === granted.size && values.every(value => granted.has(value)))?.[0] || 'custom';
  }
  function roleName(role) {
    const labels = staffRoleLabels[role];
    return labels ? tr(labels) : tr('operations.role_name.custom_permissions');
  }
  function editStaff(staff = {}) {
    showDialog(staff.id ? tr('operations.edit_staff.edit_staff') : tr('operations.edit_staff.new_staff'));
    const form = makeForm(async item => {
      const values = Object.fromEntries(new FormData(item));
      values.id = staff.id || '';
      values.roles = role.value === 'custom' ? [] : [role.value];
      values.permissions = [...item.querySelectorAll('input[name=permissions]:checked')].map(input => input.value);
      const saved = await request('/staff', values);
      if (saved.login_required) location.assign(window.D2SI18n.accountUrls['/admin/login']);
    }, tr('operations.edit_staff.staff_details_and_permissions_saved'));
    field(form, 'username', tr('operations.user_detail.username'), 'text', staff.username, {required: true, maxLength: 32});
    field(form, 'email', tr('operations.user_detail.email'), 'email', staff.email, {required: true});
    field(form, 'new_password', tr('operations.edit_staff.password_optional_for_existing_staff'), 'password', '', {required: !staff.id});
    field(form, 'status', tr('operations.edit_user.status'), 'text', staff.status || 'active', {choices: [['active', tr('operations.status.active')], ['disabled', tr('operations.status.disabled')]]});
    const role = field(form, 'role', tr('operations.edit_staff.role'), 'text', staff.id ? staffRole(staff) : 'operations',
      {choices: [...Object.keys(staffRoles).map(key => [key, roleName(key)]), ['custom', roleName('custom')]]});
    field(form, 'remark', tr('operations.edit_staff.note'), 'textarea', staff.remark, {wide: true, maxLength: 1000});
    form.append(node('p', tr('operations.edit_staff.select_a_role_to_apply_its_default_permissions_or_customize'), 'wide portal-help'));
    const controls = node('div', null, 'wide operation-panel-actions');
    const boxes = [];
    const setPermissions = values => {
      const selected = new Set(values);
      values.forEach(value => (staffDependencies[value] || []).forEach(needed => selected.add(needed)));
      boxes.forEach(input => { input.checked = selected.has(input.value); });
    };
    controls.append(button(tr('operations.staff.select_all_permissions'), () => { setPermissions(Object.keys(staffRoles).includes('superadmin') ? staffRoles.superadmin : []); role.value = 'superadmin'; }),
      button(tr('operations.edit_staff.clear_all'), () => { setPermissions([]); role.value = 'custom'; }));
    form.append(controls);
    for (const module of staffModules) {
      const group = node('fieldset', null, 'staff-permission-group');
      const title = tr(module.label);
      group.append(node('legend', title));
      const own = [];
      const tools = node('div', null, 'staff-permission-actions');
      tools.append(button(tr('operations.staff.select_all'), () => { own.forEach(input => { input.checked = true; }); setPermissions(boxes.filter(input => input.checked).map(input => input.value)); role.value = 'custom'; }),
        button(tr('operations.edit_staff.clear'), () => { own.forEach(input => { input.checked = false; }); boxes.forEach(input => { if ((staffDependencies[input.value] || []).some(value => own.some(item => item.value === value))) input.checked = false; }); setPermissions(boxes.filter(input => input.checked).map(input => input.value)); role.value = 'custom'; }));
      group.append(tools);
      for (const [permission, labels] of Object.entries(module.permissions)) {
        const label = node('label', tr(labels)), input = node('input');
        input.type = 'checkbox'; input.name = 'permissions'; input.value = permission;
        input.addEventListener('change', () => {
          role.value = 'custom';
          if (!input.checked) boxes.forEach(other => { if ((staffDependencies[other.value] || []).includes(input.value)) other.checked = false; });
          setPermissions(boxes.filter(other => other.checked).map(other => other.value));
        });
        label.prepend(input); group.append(label); boxes.push(input); own.push(input);
      }
      form.append(group);
    }
    role.addEventListener('change', () => { if (role.value !== 'custom') setPermissions(staffRoles[role.value] || []); });
    setPermissions(staff.id ? staff.permissions : staffRoles.operations || []);
    form.finish(); detail.append(form);
  }
  function renderPaymentForm(item, target) {
    const provider = item.provider;
    const form = makeForm(async element => {
      const values = Object.fromEntries(new FormData(element)), configuration = {};
      for (const key of Object.keys(paymentFields[provider] || {})) if (key !== 'enabled') configuration[key] = values['cfg_' + key] || '';
      await request('/payment-interfaces', {
        provider, enabled: element.elements.enabled.checked,
        currencies: [...element.querySelectorAll('input[name=currencies]:checked')].map(input => input.value), configuration,
      });
    }, tr('operations.render_payment_form.payment_interface_saved'));
    field(form, 'enabled', tr('operations.render_payment_form.enable_this_payment_interface'), 'checkbox', Boolean(item.enabled));
    const currencies = node('fieldset'); currencies.append(node('legend', tr('operations.render_payment_form.currencies')));
    const supported = ['alipay_pc', 'wechat_native'].includes(provider) ? ['CNY'] : provider === 'paypal' ? ['USD'] : ['CNY', 'USD'];
    supported.forEach(currency => {
      const label = node('label', currency), input = node('input'); input.type = 'checkbox'; input.name = 'currencies'; input.value = currency;
      input.checked = (item.currencies || supported).includes(currency); input.disabled = supported.length === 1; label.prepend(input); currencies.append(label);
    });
    form.append(currencies);
    const labels = {
      environment: tr('operations.render_payment_form.environment'), client_id: 'Client ID', client_secret: 'Client Secret', merchant_id: 'Merchant ID',
      webhook_id: 'Webhook ID', secret_key: 'Secret key', webhook_secret: 'Webhook signing secret', account_id: 'Stripe Account ID',
      app_id: 'App ID', seller_id: 'Seller ID', private_key: tr('operations.render_payment_form.merchant_private_key'), alipay_public_key: tr('operations.render_payment_form.alipay_public_key'),
      notify_url: tr('operations.render_payment_form.payment_notification_url'), return_url: tr('operations.render_payment_form.return_url'), app_gateway_url: tr('operations.render_payment_form.application_gateway_url'),
      qr_pay_mode: tr('operations.render_payment_form.qr_display_mode'), qrcode_width: tr('operations.render_payment_form.custom_qr_width_pixels'), mchid: tr('operations.render_payment_form.wechat_merchant_id'),
      merchant_serial: tr('operations.render_payment_form.merchant_certificate_serial'), api_v3_key: 'API v3 key', verification_id: tr('operations.render_payment_form.verification_id'),
      verification_key: tr('operations.render_payment_form.verification_key_certificate'), previous_verification_id: tr('operations.render_payment_form.previous_verification_id'),
      previous_verification_key: tr('operations.render_payment_form.previous_verification_key'), refund_notify_url: tr('operations.render_payment_form.refund_notification_url'),
    };
    for (const [key, defaultValue] of Object.entries(paymentFields[provider] || {})) {
      if (key === 'enabled') continue;
      const secret = (paymentSecretFields[provider] || []).includes(key);
      const large = ['alipay_pc', 'wechat_native'].includes(provider) && key.includes('key') && key !== 'api_v3_key';
      const options = {maxLength: 16384, wide: large};
      if (key === 'environment') options.choices = provider === 'stripe' ? [['test', tr('operations.render_payment_form.test')], ['production', tr('operations.render_payment_form.live')]] : [['sandbox', tr('operations.render_payment_form.sandbox')], ['production', tr('operations.render_payment_form.live')]];
      if (provider === 'alipay_pc' && key === 'qr_pay_mode') options.choices = [
        ['0', tr('operations.render_payment_form.label_0_compact_front_mode_at_least_600_300')],
        ['1', tr('operations.render_payment_form.label_1_front_mode_at_least_300_600')],
        ['3', tr('operations.render_payment_form.label_3_mini_front_mode_at_least_75_75')],
        ['4', tr('operations.render_payment_form.label_4_embedded_qr_with_custom_width')],
      ];
      const numericWidth = provider === 'alipay_pc' && key === 'qrcode_width';
      const input = field(form, 'cfg_' + key, labels[key] || key, numericWidth ? 'number' : large ? 'textarea' : secret ? 'password' : 'text', item.configuration?.[key] ?? (secret ? '' : defaultValue), options);
      if (numericWidth) { input.min = '75'; input.max = '600'; input.step = '1'; }
      if (secret && item.secret_set?.[key]) input.placeholder = tr('operations.render_payment_form.configured_leave_blank_to_keep');
    }
    if (provider === 'alipay_pc') {
      const mode = form.elements.cfg_qr_pay_mode, width = form.elements.cfg_qrcode_width;
      const help = node('div', null, 'wide portal-help');
      help.id = 'alipay-qr-help';
      help.append(node('strong', tr('operations.render_payment_form.qr_display_modes')));
      [
        tr('operations.render_payment_form.label_0_compact_front_compact_scan_to_pay_view_requiring_at'),
        tr('operations.render_payment_form.label_1_front_scan_to_pay_view_requiring_at_least_300'),
        tr('operations.render_payment_form.label_3_mini_front_small_qr_view_requiring_at_least_75'),
        tr('operations.render_payment_form.label_4_embedded_qr_code_at_a_custom_width_this_site'),
      ].forEach(text => help.append(node('p', text)));
      const widthHelp = node('p', tr('operations.render_payment_form.custom_width_applies_only_to_mode_4_other_modes_use'));
      widthHelp.id = 'alipay-qr-width-help'; help.append(widthHelp);
      mode.setAttribute('aria-describedby', help.id);
      width.setAttribute('aria-describedby', widthHelp.id);
      const updateWidth = () => { width.readOnly = mode.value !== '4'; };
      mode.addEventListener('change', updateWidth); updateWidth();
      form.append(help);
    }
    if (item.webhook_url && ['paypal', 'stripe'].includes(provider)) {
      const row = node('p', tr('operations.render_payment_form.provider_webhook_url') + item.webhook_url, 'wide portal-help'); form.append(row);
    }
    form.append(node('p', tr('operations.render_payment_form.secrets_are_encrypted_leave_blank_to_retain_existing_values'), 'wide portal-help'));
    form.finish(); target.append(form);
  }
  function renderPaymentInterfaces(data) {
    paymentFields = data.fields; paymentSecretFields = data.secret_fields;
    const names = {paypal: 'PayPal', stripe: 'Stripe', wechat_native: tr('operations.render_payment_interfaces.wechat_pay'), alipay_pc: tr('operations.render_payment_interfaces.alipay')};
    if (!data.items.some(item => item.provider === selectedPaymentProvider)) selectedPaymentProvider = 'paypal';
    const tabs = node('div', null, 'operation-panel-actions payment-interface-tabs'); tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', tr('operations.render_payment_interfaces.payment_methods'));
    const panels = [];
    const activate = provider => {
      selectedPaymentProvider = provider;
      for (const tab of tabs.children) { const active = tab.dataset.provider === provider; tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1; }
      for (const panel of panels) panel.hidden = panel.dataset.provider !== provider;
    };
    data.items.forEach((item, index) => {
      const tab = button(names[item.provider], () => activate(item.provider)); tab.dataset.provider = item.provider;
      tab.id = 'payment-tab-' + item.provider; tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', 'payment-panel-' + item.provider);
      tab.addEventListener('keydown', event => {
        let next = index;
        if (event.key === 'ArrowRight') next = (index + 1) % data.items.length;
        else if (event.key === 'ArrowLeft') next = (index + data.items.length - 1) % data.items.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = data.items.length - 1;
        else return;
        event.preventDefault(); activate(data.items[next].provider); tabs.children[next].focus();
      });
      tabs.append(tab);
      const panel = node('section', null, 'payment-interface-panel'); panel.id = 'payment-panel-' + item.provider; panel.dataset.provider = item.provider;
      panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id);
      panel.append(node('h2', names[item.provider]), badge(item.enabled ? tr('operations.render_payment_interfaces.enabled') : tr('operations.render_payment_interfaces.disabled'), !item.enabled));
      renderPaymentForm(item, panel); panels.push(panel);
    });
    results.append(tabs, ...panels); activate(selectedPaymentProvider);
  }
  function externalRefund(payment) {
    showDialog(tr('operations.external_refund.request_original_method_refund'));
    detail.append(node('p', payment.provider + ' · ' + money(Number(payment.amount) - Number(payment.refunded_amount), payment.currency)));
    const id = crypto.randomUUID().replaceAll('-', '');
    const form = makeForm(async element => {
      const values = Object.fromEntries(new FormData(element));
      await request('/external/refunds', {request_id: id, payment_id: payment.id, amount: minor(values.amount), reason: values.reason});
    });
    field(form, 'amount', tr('operations.external_refund.refund_amount'), 'text', '', {required: true});
    field(form, 'reason', tr('operations.external_refund.reason'), 'textarea', '', {required: true, maxLength: 500, wide: true});
    form.append(node('p', tr('operations.external_refund.the_payment_worker_submits_and_verifies_the_refund_it_is'), 'wide portal-help'));
    form.finish(); detail.append(form);
  }

  function renderProduct(data) {
    const locales = data.locales || [];
    const create = document.querySelector('#operation-create');
    create.textContent = tr('content_admin.product.add');
    create.onclick = () => editProduct({}, locales);
    create.hidden = false;

    const products = (data.items || []).map(item => {
      const published = item.published;
      const state = !published ? 'draft' : item.enabled ? 'published' : 'disabled';
      return {...item, state};
    });
    products.sort((left, right) => Number(right.state === 'published') - Number(left.state === 'published'));
    results.append(table([
      [tr('operations.render_product.product_name'), item => stack(item.presentation?.title || item.draft?.title || item.code, item.code)],
      [tr('operations.edit_user.status'), item => badge(tr('operations.status.' + item.state), item.state !== 'published')],
      [tr('operations.page_help.prices_and_purchasing'), item => {
        const prices = item.prices.filter(price => price.enabled).map(price => money(price.amount, price.currency));
        const availability = tr(item.purchases_enabled ? 'commerce.settings.on' : 'commerce.settings.off');
        return stack(prices.join(' · ') || '—', availability);
      }],
      [tr('operations.render_users.actions'), item => button(tr('operations.load.edit'), () => editProduct(item, locales))],
    ], products));
  }

  function editProduct(product, localeItems) {
    let version = Number(product.lock_version || 0);
    const draft = product.draft || {};
    showDialog(product.code ? (product.presentation?.title || draft.title || product.code) : tr('content_admin.product.add'));
    const progress = node('div');
    function showProgress(productCode) {
      if (progress.isConnected) return;
      detail.append(progress);
      const stop = watchPublication('product', productCode, localeItems, progress);
      dialog.addEventListener('close', stop, {once: true});
    }
    const form = makeForm(async (_, event) => {
      const shouldPublish = event.submitter === publish;
      form.dataset.keepOpen = 'false';
      const values = {
        code: code.value, source_locale: language.value, title: title.value, description: description.value,
        enabled: enabled.checked, purchases_enabled: purchases.checked, sort_order: draft.sort_order ?? product.sort_order ?? 0,
        lock_version: version, target_locales: targets.values(), publish: shouldPublish, prices: {},
        download_url: download.value, download_quark: quark.value, download_baidu: baidu.value,
      };
      for (const currency of ['CNY', 'USD']) {
        const {amount: input, enabled} = priceInputs[currency];
        if (input.value === '' && !enabled.checked) continue;
        const amount = Number(input.value);
        if (!Number.isSafeInteger(amount) || amount <= 0) throw new Error(tr('commerce.settings.price_invalid'));
        values.prices[currency] = {amount, enabled: enabled.checked};
      }
      if (image.files.length) {
        const saved = await request('/products', {...values, publish: false});
        version = saved.lock_version; code.readOnly = true;
        const upload = new FormData();
        upload.append('image', image.files[0]); upload.append('lock_version', String(version));
        const prefix = 'D2S_ADMIN_SESSION_CSRF=';
        const cookie = document.cookie.split('; ').find(item => item.startsWith(prefix));
        const response = await fetch(base + '/products/' + encodeURIComponent(values.code) + '/image', {
          method: 'POST', credentials: 'same-origin', body: upload,
          headers: {'X-CSRF-Token': cookie ? decodeURIComponent(cookie.slice(prefix.length)) : ''},
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || tr('operations.request.request_failed'));
        version = result.data.lock_version; values.lock_version = version;
        imagePreview.src = result.data.image_path;
        imagePreview.alt = title.value || code.value;
        imagePreview.hidden = false;
        image.value = '';
        if (!shouldPublish) return;
      }
      const saved = await request('/products', values);
      version = saved.lock_version;
      if (shouldPublish && form.isConnected && dialog.open) {
        code.readOnly = true;
        form.dataset.productCode = saved.code;
        form.dataset.keepOpen = 'true';
        showProgress(saved.code);
      }
    });
    form.dataset.productCode = product.code || '';
    const code = field(form, 'code', tr('content_admin.product.code'), 'text', product.code || '', {required: true, maxLength: 32});
    code.readOnly = Boolean(product.code); code.pattern = '[a-z][a-z0-9_-]{2,31}';
    const language = field(form, 'source_locale', tr('operations.edit_article.content_language'), 'text', draft.source_locale || product.source_locale || locale, {
      choices: localeItems.map(item => [item.code, item.native_name]), required: true,
    });
    const title = field(form, 'title', tr('operations.render_product.product_name'), 'text', draft.title || '', {required: true, maxLength: 160});
    const description = field(form, 'description', tr('operations.render_product.product_description'), 'textarea', draft.description || '', {wide: true, maxLength: 5000, rows: 8});
    const enabled = field(form, 'enabled', tr('operations.render_product.published'), 'checkbox', draft.enabled ?? product.enabled ?? true);
    const purchases = field(form, 'purchases_enabled', tr('commerce.settings.purchases_label'), 'checkbox', draft.purchases_enabled ?? product.purchases_enabled ?? false);
    const priceInputs = {};
    form.append(node('p', tr('commerce.settings.prices_help'), 'portal-help wide'));
    for (const currency of ['CNY', 'USD']) {
      const price = draft.prices?.[currency] ?? product.prices?.find(item => item.currency === currency);
      priceInputs[currency] = {
        amount: field(form, 'price_' + currency, tr('commerce.settings.prices') + ' · ' + currency, 'number', price?.amount || '', {required: Boolean(price)}),
        enabled: field(form, 'show_' + currency, tr('commerce.settings.currency_enabled') + ' · ' + currency, 'checkbox', price?.enabled ?? false),
      };
    }
    const download = field(form, 'download_url', tr('content_admin.product.download_link'), 'url', draft.download_url ?? product.download_url ?? '', {maxLength: 2048});
    const quark = field(form, 'download_quark', tr('content_admin.site.quark_download'), 'url', draft.download_quark ?? product.download_quark ?? '', {maxLength: 2048});
    const baidu = field(form, 'download_baidu', tr('content_admin.site.baidu_download'), 'url', draft.download_baidu ?? product.download_baidu ?? '', {maxLength: 2048});
    const image = field(form, 'image', tr('content_admin.product.image'), 'file', '', {});
    image.accept = 'image/jpeg,image/png';
    const imagePath = draft.image_path || product.image_path;
    const imagePreview = node('img');
    if (imagePath) imagePreview.src = imagePath;
    imagePreview.alt = draft.title || product.code || '';
    imagePreview.hidden = !imagePath;
    imagePreview.width = 160;
    imagePreview.height = 160;
    imagePreview.style.objectFit = 'contain';
    form.append(imagePreview);
    const targets = publicationTargets(localeItems, language.value, draft.target_locales ?? product.publication_locales ?? ['en']);
    language.addEventListener('change', () => {
      targets.selectSource(language.value);
      title.lang = language.value; description.lang = language.value;
    });
    form.append(targets); form.finish();
    const save = form.querySelector('footer button');
    save.textContent = tr('content_admin.policy.save_draft');
    const publish = node('button', tr('content_admin.publication.publish'), 'portal-primary'); publish.type = 'submit';
    form.querySelector('footer').append(publish);
    if (product.code) form.querySelector('footer').append(button(tr('content_admin.publication.delete_all'), async () => {
      if (!window.confirm(tr('content_admin.publication.delete_all_confirm'))) return;
      try {
        await request('/products/' + encodeURIComponent(product.code) + '/delete', {});
        dialog.close();
        await load();
      } catch (error) { message(error, dialogMessage); }
    }));
    detail.append(form);
    if (product.code) showProgress(product.code);
  }
  function renderLocales(data) {
    const workflow = node('section', null, 'locale-workflow');
    workflow.append(node('h2', tr('locales.catalog.title')));
    workflow.append(node('p', tr('locales.catalog.help'), 'portal-help'));
    results.append(workflow);
    const registry = node('section', null, 'locale-workflow');
    registry.append(node('p', tr('locales.help.registry'), 'portal-help'));
    const fallbackOrder = [];
    const form = makeForm(async item => {
      const items = [...item.querySelectorAll('.locale-setting-row')].map(row => ({
        code: row.dataset.code,
        enabled: row.querySelector('[name=enabled]').checked,
        public_enabled: row.querySelector('[name=public_enabled]').checked,
        admin_enabled: row.querySelector('[name=admin_enabled]').checked,
        fallback_locales: row.querySelector('[name=fallback_locales]').value.split(',').map(value => value.trim()).filter(Boolean),
        sort_order: Number(row.querySelector('[name=sort_order]').value),
      }));
      const addRow = item.querySelector('.locale-create-row');
      const code = addRow.querySelector('[name=new_code]').value.trim();
      const requiredFields = ['new_prefix','new_name'];
      const partialFields = [...requiredFields, 'new_aliases'];
      const hasPartialLocale = fallbackOrder.length > 0 || partialFields.some(name => addRow.querySelector('[name=' + name + ']').value.trim() !== '');
      const newLocales = code ? [{
        code,
        url_prefix: addRow.querySelector('[name=new_prefix]').value.trim(),
        native_name: addRow.querySelector('[name=new_name]').value.trim(),
        direction: addRow.querySelector('[name=new_direction]').value,
        fallback_locales: [...fallbackOrder],
        aliases: addRow.querySelector('[name=new_aliases]').value.split(',').map(value => value.trim()).filter(Boolean),
        sort_order: Number(addRow.querySelector('[name=new_sort]').value),
      }] : [];
      if (!code && hasPartialLocale) throw new Error(tr('locales.errors.new_code_required'));
      if (code && (!fallbackOrder.length || requiredFields.some(name => !addRow.querySelector('[name=' + name + ']').value.trim()))) {
        throw new Error(tr('locales.errors.new_fields_required'));
      }
      await request('/locales', {items, new_locales: newLocales, revision: data.revision});
    }, tr('locales.notices.saved'));
    const registration = node('details', null, 'locale-registration');
    registration.append(node('summary', tr('locales.add.title')));
    const addRow = node('div', null, 'locale-create-row');
    addRow.append(node('p', tr('locales.add.help'), 'portal-help wide'));
    field(addRow, 'new_code', tr('locales.add.code'), 'text', '', {maxLength: 35});
    field(addRow, 'new_prefix', tr('locales.add.url_prefix'), 'text', '', {maxLength: 32});
    field(addRow, 'new_name', tr('locales.add.native_name'), 'text', '', {maxLength: 100});
    field(addRow, 'new_direction', tr('locales.add.direction'), 'text', 'ltr', {
      choices: [['ltr', tr('locales.add.ltr')], ['rtl', tr('locales.add.rtl')]],
    });
    const fallbacks = node('fieldset', null, 'locale-fallback-choices wide');
    const fallbackHelp = node('p', tr('locales.add.fallbacks_help'), 'portal-help');
    fallbackHelp.id = 'new-locale-fallback-help';
    fallbacks.setAttribute('aria-describedby', fallbackHelp.id);
    fallbacks.append(node('legend', tr('locales.add.fallbacks')), fallbackHelp);
    const choices = node('div', null, 'locale-fallback-options');
    const positions = new Map();
    for (const item of data.locales) {
      const checkbox = node('input');
      checkbox.type = 'checkbox';
      checkbox.name = 'new_fallbacks';
      checkbox.value = item.code;
      const position = node('small');
      position.hidden = true;
      positions.set(item.code, position);
      checkbox.addEventListener('change', () => {
        if (checkbox.checked) fallbackOrder.push(item.code);
        else fallbackOrder.splice(fallbackOrder.indexOf(item.code), 1);
        for (const [code, rank] of positions) {
          const index = fallbackOrder.indexOf(code);
          rank.hidden = index < 0;
          rank.textContent = index < 0 ? '' : String(index + 1);
        }
      });
      const label = node('label');
      label.append(checkbox, node('span', item.native_name + ' · ' + item.code), position);
      choices.append(label);
    }
    fallbacks.append(choices);
    addRow.append(fallbacks);
    field(addRow, 'new_aliases', tr('locales.add.aliases'), 'text', '', {wide: true});
    field(addRow, 'new_sort', tr('locales.add.sort'), 'number', data.locales.length * 10 + 10);
    registration.append(addRow);
    const setting = (item, name, label, type, value) => {
      const input = node('input');
      input.name = name;
      input.type = type;
      input.setAttribute('aria-label', label + ' · ' + item.native_name);
      if (type === 'checkbox') input.checked = value;
      else input.value = value;
      return input;
    };
    const localeTable = table([
      [tr('locales.coverage.locale'), item => stack(item.native_name, item.code + (item.code === data.default_locale ? tr('locales.labels.default') : ''))],
      [tr('locales.labels.ui_enabled'), item => setting(item, 'enabled', tr('locales.labels.ui_enabled'), 'checkbox', item.enabled)],
      [tr('locales.labels.public_enabled'), item => setting(item, 'public_enabled', tr('locales.labels.public_enabled'), 'checkbox', item.public_enabled)],
      [tr('locales.labels.admin_enabled'), item => setting(item, 'admin_enabled', tr('locales.labels.admin_enabled'), 'checkbox', item.admin_enabled)],
      [tr('locales.labels.fallbacks'), item => setting(item, 'fallback_locales', tr('locales.labels.fallbacks'), 'text', item.fallback_locales.join(', '))],
      [tr('locales.labels.sort'), item => setting(item, 'sort_order', tr('locales.labels.sort'), 'number', item.sort_order)],
    ], data.locales);
    localeTable.classList.add('locale-settings-table', 'wide');
    localeTable.querySelectorAll('tbody tr').forEach((row, index) => {
      row.classList.add('locale-setting-row');
      row.dataset.code = data.locales[index].code;
    });
    form.append(localeTable, registration);
    form.addEventListener('input', () => { form.dataset.dirty = 'true'; });
    form.finish();
    form.querySelector('[type=submit]').textContent = tr('locales.actions.save');
    const publish = button(tr('locales.actions.publish'), async () => {
      if (form.dataset.dirty === 'true') {
        message(tr('locales.notices.save_first'));
        return;
      }
      publish.disabled = true;
      try {
        await request('/locales/publish', {revision: data.revision});
        await load();
        message(tr('locales.notices.published'));
      } catch (error) { message(error); }
      finally { publish.disabled = false; }
    });
    publish.classList.add('portal-primary');
    registry.append(form);
    results.append(registry);

    const coverage = node('details', null, 'locale-content-coverage');
    coverage.append(node('summary', tr('locales.coverage.title')));
    coverage.append(node('p', tr('locales.coverage.help'), 'portal-help'));
    const coverageRows = [];
    data.locales.forEach(locale => {
      Object.entries(data.content_coverage?.[locale.code] || {}).forEach(([type, counts]) => {
        coverageRows.push({
          locale:locale.native_name + ' · ' + locale.code,
          type,
          translated:counts.translated,
          total:counts.total,
        });
      });
    });
    coverage.append(table([
      [tr('locales.coverage.locale'), item => item.locale],
      [tr('locales.coverage.type'), item => tr('locales.coverage.' + item.type)],
      [tr('locales.coverage.translations'), item => item.translated + ' / ' + item.total],
    ], coverageRows));
    results.append(coverage);



    const exportControls = node('div', null, 'locale-package-controls locale-package-field-row');
    const targetLocales = data.locales.filter(item => item.code !== data.default_locale);
    const target = field(exportControls, 'catalog_locale', tr('locales.catalog.target_locale'), 'text', targetLocales[0]?.code, {
      choices: targetLocales.map(item => [item.code, item.native_name + ' · ' + item.code]),
    });

    const exportButton = button(tr('locales.catalog.export'), async () => {
      const selected = {locale:target.value};
      exportButton.disabled = true;
      try {
        const pack = await request('/ui-catalog/export?' + new URLSearchParams(selected));
        const blob = new Blob([JSON.stringify(pack, null, 2) + '\n'], {type:'application/json;charset=utf-8'});
        const link = node('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'd2s-ui-catalog-' + selected.locale + '-all.json';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 0);
      } catch (error) { message(error); }
      finally { exportButton.disabled = false; }
    });
    exportButton.disabled = !target.value;
    exportControls.append(exportButton);

    const importControls = node('div', null, 'locale-package-controls locale-package-field-row');
    const fileLabel = node('label', tr('locales.catalog.import_file'));
    const importFile = node('input');
    importFile.type = 'file';
    importFile.accept = 'application/json,.json';
    fileLabel.append(importFile);
    const importButton = button(tr('locales.catalog.import'), async () => {
      const file = importFile.files?.[0];
      if (!file || file.size > 2097152) return;
      importButton.disabled = true;
      importFile.disabled = true;
      try {
        const pack = JSON.parse(await file.text());
        const result = await request('/ui-catalog/import', pack);
        if (form.dataset.dirty === 'true') {
          if (result.imported || result.removed) publishStatus.textContent = tr('locales.status.pending');
          importFile.value = '';
        } else {
          await load();
        }
        message(tr('locales.catalog.imported', {count:result.imported, removed:result.removed, skipped:result.skipped_empty}));
      } catch (error) {
        message(error instanceof SyntaxError ? tr('locales.errors.translation_package_invalid') : error);
      } finally {
        importFile.disabled = false;
        importButton.disabled = !importFile.files?.length;
      }
    });
    importButton.disabled = true;
    importFile.addEventListener('change', () => {
      const file = importFile.files?.[0];
      importButton.disabled = !file || file.size > 2097152;
      message(file && file.size > 2097152 ? tr('locales.errors.translation_package_too_large') : '');
    });
    importControls.append(fileLabel, importButton);

    const publishControls = node('div', null, 'locale-package-controls locale-publish-controls');
    const publishStatus = node('p', tr(data.publication_pending ? 'locales.status.pending' : 'locales.status.published'), 'portal-help');
    publishControls.append(publishStatus, publish);
    if (data.catalog_error) publishControls.prepend(node('p', messages[data.catalog_error] || data.catalog_error, 'portal-help'));
    workflow.append(exportControls, importControls, publishControls);

    const previous = data.catalog_history?.[0];
    if (previous) {
      const history = node('section', null, 'locale-workflow locale-catalog-history');
      history.append(node('h2', tr('locales.history.title')));
      const revision = node('code', previous.catalog_revision.slice(0, 12));
      revision.title = previous.catalog_revision;
      const rollback = button(tr('locales.history.rollback'), async () => {
        if (form.dataset.dirty === 'true') { message(tr('locales.notices.save_first')); return; }
        if (!window.confirm(tr('locales.history.confirm'))) return;
        rollback.disabled = true;
        try {
          await request('/locales/catalog-rollback', {revision: data.revision, catalog_revision: previous.catalog_revision});
          await load();
          message(tr('locales.history.rolled_back'));
        } catch (error) { message(error); }
        finally { rollback.disabled = false; }
      });
      const row = node('div', null, 'locale-package-controls');
      row.append(revision, node('span', date(previous.created_at)), rollback);
      history.append(row);
      results.insertBefore(history, coverage);
    }
  }
  async function renderTranslationSettings() {
    const data = await request('/translation-settings');
    results.append(node('h2', tr('operations.translation_settings.title')));
    results.append(node('p', tr('operations.translation_settings.description'), 'portal-help'));
    const usageKey = Number(data.monthly_character_budget) === 0
      ? 'operations.translation_settings.usage_unlimited'
      : 'operations.translation_settings.usage';
    const formatNumber = value => new Intl.NumberFormat(locale).format(Number(value) || 0);
    results.append(node('p', tr(usageKey, {
      used: formatNumber(data.monthly_characters_used),
      budget: formatNumber(data.monthly_character_budget),
    }), 'portal-help'));
    const form = makeForm(async item => {
      const values = Object.fromEntries(new FormData(item));
      const targetMap = {};
      for (const localeItem of data.locales || []) {
        const input = item.elements.namedItem('target_' + localeItem.code);
        if (input && input.value.trim() !== '') targetMap[localeItem.code] = input.value.trim().toUpperCase();
      }
      await request('/translation-settings', {
        enabled: item.elements.namedItem('enabled').checked,
        api_key: values.api_key || '',
        target_map: targetMap,
        monthly_character_budget: Number(values.monthly_character_budget),
        concurrency: Number(values.concurrency),
        retry_limit: Number(values.retry_limit),
      });
    }, tr('operations.translation_settings.saved'));
    field(form, 'enabled', tr('operations.translation_settings.enabled'), 'checkbox', data.enabled);
    const key = field(form, 'api_key', tr('operations.translation_settings.api_key'), 'password', '', {wide: true});
    key.placeholder = tr('operations.translation_settings.api_key_placeholder');
    const budget = field(form, 'monthly_character_budget', tr('operations.translation_settings.monthly_budget'), 'number', data.monthly_character_budget, {required: true});
    budget.min = '0'; budget.max = '100000000'; budget.step = '1';
    const concurrency = field(form, 'concurrency', tr('common.translation.concurrency'), 'number', data.concurrency, {required: true});
    concurrency.min = '1'; concurrency.max = '10'; concurrency.step = '1';
    const retries = field(form, 'retry_limit', tr('operations.translation_settings.retry_limit'), 'number', data.retry_limit, {required: true});
    retries.min = '0'; retries.max = '8'; retries.step = '1';
    form.append(node('h3', tr('operations.translation_settings.languages'), 'operation-section-title'));
    for (const localeItem of data.locales || []) {
      field(form, 'target_' + localeItem.code, localeItem.native_name + ' · ' + localeItem.code, 'text',
        data.target_map?.[localeItem.code] || '', {maxLength: 12});
    }
    form.finish();
    results.append(form);

    const glossaryPanel = node('section', null, 'operation-glossary-panel');
    glossaryPanel.append(node('h3', tr('translation_glossary.title'), 'operation-section-title'));
    glossaryPanel.append(node('p', tr('translation_glossary.description'), 'portal-help'));
    const localeRow = node('div', null, 'operation-form-grid');
    const sourceSelect = node('select'); const targetSelect = node('select');
    for (const item of data.locales || []) {
      const label = item.native_name + ' · ' + item.code;
      sourceSelect.append(new Option(label, item.code));
      targetSelect.append(new Option(label, item.code));
    }
    sourceSelect.value = 'zh-Hans';
    targetSelect.value = (data.locales || []).find(item => item.code === 'en')?.code || '';
    const sourceLabel = node('label', tr('translation_glossary.source_locale')); sourceLabel.append(sourceSelect);
    const targetLabel = node('label', tr('translation_glossary.target_locale')); targetLabel.append(targetSelect);
    const loadGlossaryButton = button(tr('translation_glossary.load'), () => void loadGlossary().catch(error => message(error)));
    localeRow.append(sourceLabel, targetLabel, loadGlossaryButton);
    const glossaryRows = node('div', null, 'operation-glossary-rows');
    const glossaryRevision = node('span', '0', 'operation-badge muted');
    const glossaryHistory = node('select');
    const glossaryActions = node('div', null, 'operation-actions');
    const addTermButton = button(tr('translation_glossary.add_term'), () => addGlossaryRow());
    const saveGlossaryButton = button(tr('translation_glossary.save'), async () => {
      try {
        const terms = [...glossaryRows.children].map(row => ({
          source_term: row.querySelector('[name="source_term"]').value,
          target_term: row.querySelector('[name="target_term"]').value,
          mode: row.querySelector('[name="mode"]').value,
        })).filter(term => term.source_term.trim() !== '' || term.target_term.trim() !== '');
        const saved = await request('/translation-glossary', {
          source_locale: sourceSelect.value,
          target_locale: targetSelect.value,
          expected_revision: Number(glossaryRevision.textContent),
          terms,
        });
        glossaryRevision.textContent = String(saved.revision);
        message(tr('translation_glossary.saved', {revision: saved.revision}));
      } catch (error) { message(error); }
    });
    const restoreGlossaryButton = button(tr('translation_glossary.restore'), async () => {
      const restoreRevision = Number(glossaryHistory.value);
      if (!restoreRevision || !window.confirm(tr('translation_glossary.confirm_restore', {revision: restoreRevision}))) return;
      try {
        const restored = await request('/translation-glossary/restore', {
          source_locale: sourceSelect.value, target_locale: targetSelect.value,
          expected_revision: Number(glossaryRevision.textContent), restore_revision: restoreRevision,
        });
        message(tr('translation_glossary.saved', {revision: restored.revision}));
        await loadGlossary();
      } catch (error) { message(error); }
    });
    glossaryActions.append(addTermButton, saveGlossaryButton, node('span', tr('translation_glossary.revision')), glossaryRevision,
      glossaryHistory, restoreGlossaryButton);
    glossaryPanel.append(localeRow, glossaryRows, glossaryActions);
    results.append(glossaryPanel);

    function addGlossaryRow(term = {}) {
      const row = node('div', null, 'operation-glossary-row');
      const source = node('input'); source.name = 'source_term'; source.type = 'text'; source.maxLength = 100; source.value = term.source_term || ''; source.placeholder = tr('translation_glossary.source_term');
      const target = node('input'); target.name = 'target_term'; target.type = 'text'; target.maxLength = 100; target.value = term.target_term || ''; target.placeholder = tr('translation_glossary.target_term');
      const mode = node('select'); mode.name = 'mode';
      mode.append(new Option(tr('translation_glossary.translate_as'), 'translate_as'), new Option(tr('translation_glossary.preserve'), 'preserve'));
      mode.value = term.mode || 'translate_as';
      row.append(source, target, mode, button(tr('translation_glossary.remove'), () => row.remove()));
      glossaryRows.append(row);
    }
    async function loadGlossary() {
      if (!sourceSelect.value || !targetSelect.value || sourceSelect.value === targetSelect.value) {
        message(tr('translation_glossary.errors.select_distinct_locales')); return;
      }
      const query = new URLSearchParams({source_locale: sourceSelect.value, target_locale: targetSelect.value});
      const glossary = await request('/translation-glossary?' + query.toString());
      glossaryRevision.textContent = String(glossary.revision);
      glossaryRows.replaceChildren();
      for (const term of glossary.terms || []) addGlossaryRow(term);
      if (!glossaryRows.children.length) addGlossaryRow();
      const history = await request('/translation-glossary/history?' + query.toString());
      glossaryHistory.replaceChildren(new Option(tr('translation_glossary.history_empty'), ''));
      for (const revision of history.revisions || []) {
        glossaryHistory.append(new Option(tr('translation_glossary.history_option', {
          revision: revision.revision, count: revision.term_count,
        }), String(revision.revision)));
      }
    }
    void loadGlossary().catch(error => message(error));
  }
  function queryString() {
    const query = new URLSearchParams();
    if (filters) for (const [key, value] of new FormData(filters)) if (value !== '') {
      query.set(key, ['from', 'until'].includes(key) ? String(Math.floor(new Date(value).getTime() / 1000)) : value);
    }
    query.set('offset', String(offset)); return query.toString();
  }
  function buildFilters() {
    if (!filters || !['users', 'orders', 'ledger'].includes(section)) return;
    filters.hidden = false;
    if (section !== 'ledger') {
      const query = section === 'orders' ? new URLSearchParams(location.search).get('q') || '' : '';
      field(filters, 'q', tr('operations.build_filters.search'), 'search', query.slice(0, 100));
    }
    else field(filters, 'user_id', tr('operations.build_filters.user_reference_optional'), 'number');
    if (section === 'users') {
      const product = new URLSearchParams(location.search).get('product') || '';
      field(filters, 'product', tr('licensing.products.label'), 'text', product, {choices: [['', tr('operations.build_filters.all')], ...(product ? [[product, product]] : [])]});
      field(filters, 'status', tr('operations.user_detail.account_status'), 'text', '', {choices: [['', tr('operations.build_filters.all')], ['active', tr('operations.status.active')], ['disabled', tr('operations.status.disabled')], ['pending', tr('operations.filters.pending_verification')]]});
      field(filters, 'license_kind', tr('operations.user_detail.license'), 'text', '', {choices: [['', tr('operations.build_filters.all')], ['trial', tr('operations.user_detail.trial')], ['formal', tr('operations.user_detail.licensed')]]});
    } else if (section === 'orders') {
      field(filters, 'status', tr('operations.build_filters.order_status'), 'text', new URLSearchParams(location.search).get('status') || '', {choices: [['', tr('operations.build_filters.all')], ...['pending', 'paid', 'payment_review', 'refund_pending', 'partially_refunded', 'refunded', 'cancelled'].map(value => [value, value])]});
      field(filters, 'payment_method', tr('operations.build_filters.payment_method'), 'text', '', {choices: [['', tr('operations.build_filters.all')], ['wallet', tr('operations.labels.wallet')], ['alipay_pc', tr('operations.render_payment_interfaces.alipay')], ['wechat_native', tr('operations.build_filters.wechat')], ['paypal', 'PayPal'], ['stripe', 'Stripe']]});
      field(filters, 'from', tr('operations.build_filters.from'), 'datetime-local');
      field(filters, 'until', tr('operations.build_filters.until_exclusive'), 'datetime-local');
    } else {
      field(filters, 'account', tr('operations.build_filters.account'), 'text', 'wallet', {choices: [['wallet', tr('operations.filters.recharge_wallet')], ['commission', tr('operations.user_detail.commission')]]});
      field(filters, 'event_type', tr('operations.build_filters.entry_type'), 'text', '', {choices: [['', tr('operations.build_filters.all')], ...['PURCHASE', 'REFUND', 'RECHARGE', 'ADMIN_ADJUSTMENT', 'COMMISSION', 'REVERSAL', 'WITHDRAWAL_HOLD', 'WITHDRAWAL_PAID', 'WITHDRAWAL_REJECTED'].map(value => [value, value])]});
    }
    field(filters, 'currency', tr('operations.build_filters.payment_currency'), 'text', '', {choices: [['', tr('operations.build_filters.all_currencies')], ['CNY', tr('operations.build_filters.cny_chinese_yuan')], ['USD', tr('operations.build_filters.usd_us_dollar')]]});
    const submit = node('button', tr('operations.filters.search'), 'portal-primary'); submit.type = 'submit'; filters.append(submit);
    if (section === 'orders' && permissions.has('orders.export')) filters.append(button(tr('operations.build_filters.export_filtered_csv'), async () => {
      try {
        const response = await fetch(base + '/orders/export?' + queryString(), {credentials: 'same-origin'});
        if (!response.ok) { const payload = await response.json(); throw new Error(messages[payload.error] || payload.error); }
        const url = URL.createObjectURL(await response.blob()), link = node('a');
        link.href = url; link.download = 'orders.csv'; link.click(); URL.revokeObjectURL(url);
      } catch (error) { message(error); }
    }));
    filters.addEventListener('submit', event => { event.preventDefault(); offset = 0; void load(); });
  }
  function renderUsers(data) {
    const selector = filters?.querySelector('[name="product"]');
    if (selector && Array.isArray(data.license_products)) {
      const selected = selector.value;
      selector.replaceChildren();
      [['', tr('operations.build_filters.all')], ...data.license_products.map(item => [item.product, item.title])].forEach(([code, title]) => {
        const option = node('option', title); option.value = code; selector.append(option);
      });
      if (selected && !Array.from(selector.options).some(item => item.value === selected)) {
        const option = node('option', selected); option.value = selected; selector.append(option);
      }
      selector.value = selected;
    }
    results.append(table([
      [tr('operations.render_users.user'), user => stack(user.display_name || user.username, user.username)],
      [tr('operations.render_users.email_phone'), user => stack(user.email || '—', user.phone)],
      [tr('operations.render_users.license'), user => {
        if (user.license_count === undefined) return stack(badge(user.license_kind === 'formal' ? tr('operations.user_detail.licensed') : user.license_kind === 'trial' ? tr('operations.user_detail.trial') : tr('operations.render_users.no_license'), !user.license_kind), user.license_expires_at ? date(user.license_expires_at) : '');
        const box = stack(badge(tr('licensing.products.count', {count: Number(user.license_count)}), !Number(user.license_count)), tr('licensing.products.summary', {formal: Number(user.formal_license_count), trial: Number(user.active_trial_count)}));
        const names = [...(user.licenses || [])].sort((a, b) => Number(b.kind === 'formal') - Number(a.kind === 'formal')).map(item => item.title || item.product);
        if (names.length) {
          const preview = node('small', names.slice(0, 2).join(' · ') + (names.length > 2 ? ' …' : ''));
          preview.title = names.join('\n');
          box.append(preview);
        }
        return box;
      }],
      [tr('operations.edit_user.status'), user => badge(user.login_blocked ? tr('operations.render_users.sign_in_blocked') : status(user.status), user.status !== 'active')],
      [tr('operations.render_users.latest_payment_currency'), user => stack(user.last_payment_currency || tr('operations.render_users.no_payment'), user.payment_currencies)],
      [tr('operations.user_detail.last_sign_in'), user => stack(date(user.last_login_at), user.last_login_ip)],
      [tr('operations.user_detail.sign_in_country_region'), user => country(user.login_country)],
      [tr('operations.user_detail.registration_country_region'), user => country(user.register_country)],
      [tr('operations.user_detail.created'), user => date(user.created_at)],
      [tr('operations.render_users.actions'), user => button(tr('operations.render_users.details'), () => void userDetail(user.id))],
    ], data.items));
  }
  async function diagnostics(id) {
    showDialog(tr('operations.diagnostics.callback_diagnostics'));
    try {
      const data = await request('/orders/' + id + '/diagnostics');
      detail.append(description([[tr('operations.diagnostics.order'), data.order.order_no], [tr('operations.edit_user.status'), data.order.status], [tr('operations.diagnostics.amount'), money(data.order.amount, data.order.currency)]]));
      detail.append(table([[tr('operations.diagnostics.event'), item => item.event_type], [tr('operations.user_detail.result'), item => item.result], [tr('operations.user_detail.time'), item => date(item.received_at)]], data.events));
      data.notes.forEach(note => detail.append(node('p', tr(note), 'portal-help')));
      if (['paypal', 'stripe'].includes(data.order.payment_method)) {
        const financial = await request('/external/orders/' + id);
        if (financial.checkout) detail.append(description([
          [tr('operations.diagnostics.merchant'), financial.checkout.label], [tr('operations.diagnostics.checkout_state'), financial.checkout.status],
          [tr('operations.diagnostics.provider_order'), financial.checkout.provider_order_id || '—'],
          [tr('operations.diagnostics.last_updated'), date(financial.checkout.updated_at)],
        ]));
        detail.append(node('h3', tr('operations.diagnostics.payments_and_refunds'), 'operation-section-title'));
        detail.append(table([
          [tr('operations.diagnostics.provider'), item => item.provider], [tr('operations.diagnostics.received'), item => money(item.amount, item.currency)],
          [tr('operations.status.refunded'), item => money(item.refunded_amount, item.currency)],
          [tr('operations.render_users.actions'), item => permissions.has('refunds.manage') && Number(item.amount) > Number(item.refunded_amount) ? button(tr('operations.diagnostics.refund'), () => externalRefund(item)) : '—'],
        ], financial.payments));
        detail.append(table([
          [tr('operations.diagnostics.refund_reference'), item => item.id], [tr('operations.diagnostics.minor_unit_amount'), item => item.amount],
          [tr('operations.edit_user.status'), item => status(item.status)], [tr('operations.diagnostics.reason'), item => item.reason],
          [tr('operations.diagnostics.last_error'), item => item.last_error || '—'],
        ], financial.refunds));
      }
    } catch (error) { message(error, dialogMessage); }
  }
  function renderMaintenance(data) {
    const environment = data.environment;
    const byteSize = value => {
      const amount=Number(value||0), units=['B','KiB','MiB','GiB','TiB'];
      let index=0, current=amount;
      while(current>=1024&&index<units.length-1){current/=1024;index++;}
      return current.toFixed(index===0?0:1)+' '+units[index];
    };
    results.append(node('h2', tr('operations.render_maintenance.runtime_environment')));
    results.append(description([
      ['PHP', environment.php_version], ['MySQL', environment.database_version],
      [tr('operations.render_maintenance.tables'), environment.tables], [tr('operations.render_maintenance.applied_migrations'), environment.migrations],
      [tr('operations.render_maintenance.storage'), environment.storage_writable ? tr('operations.render_maintenance.writable') : tr('operations.render_maintenance.not_writable')],
      [tr('operations.render_maintenance.free_disk_space'), environment.storage_free_bytes === null ? tr('operations.country.unknown') : (Number(environment.storage_free_bytes) / 1073741824).toFixed(1) + ' GiB'],
      [tr('operations.render_maintenance.ip_country_database'), environment.geoip.ready ? date(environment.geoip.build_time) : tr('operations.maintenance.not_installed')],
    ]));
    results.append(node('h3', tr('operations.render_maintenance.php_extensions'), 'operation-section-title'));
    results.append(table([['Extension', item => item.name], [tr('operations.render_maintenance.state'), item => badge(item.ready ? tr('operations.maintenance.installed') : tr('operations.maintenance.missing'), !item.ready)]], data.extensions));
    if (data.data) {
      const inventory=data.data.database, attachment=data.data.attachments, manifests=data.data.manifests;
      results.append(node('h3', tr('maintenance.inventory.database_inventory'), 'operation-section-title'));
      results.append(description([
        [tr('maintenance.inventory.tables'), inventory.table_count],
        [tr('maintenance.inventory.data_size'), byteSize(inventory.data_bytes)],
        [tr('maintenance.inventory.index_size'), byteSize(inventory.index_bytes)],
        [tr('maintenance.inventory.allocated_size'), byteSize(inventory.allocated_bytes)],
      ]));
      results.append(table([
        [tr('maintenance.inventory.table'), item => item.name],
        [tr('maintenance.inventory.estimated_rows'), item => item.estimated_rows],
        [tr('maintenance.inventory.data_size'), item => byteSize(item.data_bytes)],
        [tr('maintenance.inventory.index_size'), item => byteSize(item.index_bytes)],
      ], inventory.tables.slice(0,20)));
      results.append(node('h4', tr('maintenance.inventory.key_business_row_counts'), 'operation-section-title'));
      results.append(description(Object.entries(inventory.key_row_counts).map(([name,count]) => [name, count===null ? tr('operations.country.unknown') : count])));

      results.append(node('h3', tr('maintenance.inventory.attachment_integrity'), 'operation-section-title'));
      results.append(description([
        [tr('maintenance.inventory.directory'), attachment.configured ? (attachment.writable ? tr('operations.render_maintenance.writable') : tr('maintenance.inventory.read_only')) : tr('operations.maintenance.missing')],
        [tr('maintenance.inventory.database_records'), attachment.database_records],
        [tr('maintenance.inventory.database_bytes'), byteSize(attachment.database_bytes)],
        [tr('maintenance.inventory.files_scanned'), attachment.files_scanned],
        [tr('maintenance.inventory.file_bytes'), byteSize(attachment.files_bytes)],
        [tr('maintenance.inventory.missing_files'), attachment.missing_files],
        [tr('maintenance.inventory.orphan_files'), attachment.orphan_files],
        [tr('maintenance.inventory.size_mismatches'), attachment.size_mismatches],
        [tr('maintenance.inventory.hash_checked'), attachment.hash_checked],
        [tr('maintenance.inventory.hash_mismatches'), attachment.hash_mismatches],
        [tr('maintenance.inventory.invalid_files'), attachment.invalid_files],
        [tr('maintenance.inventory.symlinks_skipped'), attachment.symlinks_skipped],
        [tr('maintenance.inventory.scan_truncated'), attachment.scan_truncated ? tr('operations.status.active') : tr('operations.status.disabled')],
        [tr('operations.render_maintenance.free_disk_space'), attachment.disk_free_bytes===null ? tr('operations.country.unknown') : byteSize(attachment.disk_free_bytes)],
      ]));
      results.append(node('h3', tr('maintenance.inventory.deletion_manifests'), 'operation-section-title'));
      results.append(description([
        [tr('maintenance.inventory.directory'), manifests.configured ? tr('operations.maintenance.available') : tr('operations.maintenance.missing')],
        [tr('maintenance.inventory.files_scanned'), manifests.files],
        [tr('maintenance.inventory.file_bytes'), byteSize(manifests.bytes)],
        [tr('maintenance.inventory.latest_manifest'), manifests.latest_at ? date(manifests.latest_at) : tr('maintenance.inventory.none')],
        [tr('maintenance.inventory.invalid_files'), manifests.invalid_files],
      ]));
      results.append(node('h3', tr('maintenance.inventory.retention_history'), 'operation-section-title'));
      results.append(table([
        [tr('maintenance.inventory.task'), item => item.task],
        [tr('operations.render_maintenance.state'), item => badge(item.status, item.status==='failed')],
        [tr('maintenance.inventory.deleted_records'), item => item.deleted],
        [tr('maintenance.inventory.time'), item => date(item.started_at)],
      ], data.data.retention_runs));
      results.append(node('p', tr('maintenance.inventory.data_inventory_is_read_only_and_never_runs'), 'portal-help'));
    }
    results.append(node('h3', tr('operations.render_maintenance.backup_and_maintenance_tools'), 'operation-section-title'));
    const names = {backup: tr('operations.render_maintenance.create_backup_set'), 'verify-backup': tr('operations.render_maintenance.verify_backup_set'), preflight: tr('operations.render_maintenance.deployment_preflight'), 'prune-support': tr('maintenance.inventory.prune_support_tickets'), 'prune-login-history': tr('maintenance.inventory.prune_login_history')};
    const commands = {backup: 'php bin/backup.php --output=<absolute-private-directory>', 'verify-backup': 'php bin/verify-backup.php --input=<absolute-private-directory>', preflight: 'php bin/preflight.php', 'prune-support': 'php bin/prune-support.php --apply', 'prune-login-history': 'php bin/prune-login-history.php --apply'};
    results.append(table([
      [tr('operations.render_maintenance.tool'), item => names[item.name]], [tr('operations.render_maintenance.state'), item => item.available ? tr('operations.maintenance.available') : tr('operations.maintenance.missing')],
      [tr('operations.render_maintenance.server_command'), item => node('code', commands[item.name])],
    ], data.tools));
    results.append(node('p', tr('operations.render_maintenance.run_backup_and_verification_commands_on_the_server_after_configuring'), 'portal-help'));
  }
  let updateAddress;
  function renderUpgrade(data) {
    const byteSize = value => {
      const amount=Number(value||0), units=['B','KiB','MiB','GiB','TiB'];
      let index=0, current=amount;
      while(current>=1024&&index<units.length-1){current/=1024;index++;}
      return current.toFixed(index===0?0:1)+' '+units[index];
    };
    const github = node('section');
    github.append(node('h2', tr('updates.github.title')));
    const versionSummary = description([
      [
        data.release_source === 'git_baseline' ? tr('operations.render_upgrade.code_baseline') : tr('operations.render_upgrade.deployment_release'),
        data.release?.slice(0, 12) || tr('operations.render_upgrade.not_configured'),
      ],
      [tr('updates.github.latest_release'), tr('updates.github.not_checked')],
    ]);
    const latestVersion = versionSummary.querySelectorAll('dd')[1];
    github.append(versionSummary);
    const sourceField = node('div', null, 'operation-form');
    const sourceLabel = node('label', tr('updates.github.title') + ' URL');
    const address = node('input');
    address.inputMode = 'url';
    address.spellcheck = false;
    address.autocomplete = 'off';
    address.maxLength = 240;
    address.placeholder = 'https://github.com/owner/repository';
    address.value = updateAddress ?? (data.github_repository ? 'https://github.com/' + data.github_repository : '');
    sourceLabel.append(address);
    sourceField.append(sourceLabel);
    const toolbar = node('div', null, 'operation-panel-actions');
    const feedback = node('p', '', 'portal-help');
    feedback.setAttribute('role','status');
    const releaseDetails = node('div');
    const statusLabels = {
      available:tr('updates.github.available'),
      current:tr('updates.github.current'),
      no_release:tr('updates.github.no_release'),
      not_newer:tr('updates.github.not_newer'),
      unknown_version:tr('updates.github.unknown_version'),
    };
    let candidate = null;
    const check = button(tr('updates.github.check'), async () => {
      candidate = null;
      download.hidden = true; check.disabled = true; address.disabled = true;
      const checkedAddress = address.value.trim();
      releaseDetails.replaceChildren();
      latestVersion.textContent = tr('updates.github.not_checked');
      feedback.textContent = tr('updates.github.checking');
      try {
        const latest = await request('/upgrade/check?' + new URLSearchParams({address:checkedAddress}));
        feedback.textContent = statusLabels[latest.state] || tr('updates.github.error_source');
        const releaseSha = typeof latest.release === 'string' && /^[a-f0-9]{40}$/i.test(latest.release)
          ? ' · ' + latest.release.slice(0, 12)
          : '';
        latestVersion.textContent = latest.tag
          ? latest.tag + releaseSha
          : tr('updates.github.unknown_version');
        if (latest.artifact_size) {
          releaseDetails.append(description([
            [tr('updates.artifact_size'), byteSize(latest.artifact_size)],
          ]));
        }
        if (latest.state === 'available') { candidate = {...latest, address:checkedAddress}; download.hidden = false; }
      } catch (error) { message(error, feedback); }
      finally { address.disabled = false; check.disabled = !address.value.trim(); }
    });
    check.classList.add('portal-primary');
    check.disabled = !address.value.trim();
    const download = button(tr('updates.stage_button'), async () => {
      if (!candidate || candidate.address !== address.value.trim()) return;
      check.disabled = true; download.disabled = true; address.disabled = true;
      feedback.textContent = tr('updates.github.downloading');
      try {
        await request('/upgrade/download', {address:candidate.address, release:candidate.release});
        await load();
        message(tr('updates.stage_success'), notice);
        notice.dataset.state = 'success';
      } catch (error) { message(error, feedback); }
      finally { address.disabled = false; check.disabled = !address.value.trim(); download.disabled = false; }
    });
    download.hidden = true;
    address.addEventListener('input', () => {
      updateAddress = address.value;
      candidate = null;
      download.hidden = true;
      check.disabled = !address.value.trim();
      feedback.textContent = '';
      latestVersion.textContent = tr('updates.github.not_checked');
      releaseDetails.replaceChildren();
    });
    toolbar.append(check, download);
    github.append(sourceField, toolbar, feedback, releaseDetails);
    results.append(github);
    const manifestNames = {unconfigured: tr('operations.render_upgrade.not_configured'), missing: tr('operations.render_upgrade.manifest_missing'), verified: tr('operations.render_upgrade.verified'), invalid: tr('operations.render_upgrade.invalid')};
    const advanced = node('details', null, 'system-diagnostics');
    advanced.append(node('summary', tr('operations.render_upgrade.website_version_and_release_verification')));
    results.append(advanced);
    advanced.append(description([
      [data.release_source === 'git_baseline' ? tr('operations.render_upgrade.code_baseline') : tr('operations.render_upgrade.deployment_release'), data.release || tr('operations.render_upgrade.not_configured')],
      ...(data.git_baseline && data.git_baseline !== data.release ? [[tr('operations.render_upgrade.code_baseline'), data.git_baseline]] : []),
      [tr('operations.render_upgrade.release_manifest'), manifestNames[data.manifest.state]],
      [tr('operations.render_upgrade.release_files'), data.manifest.file_count],
      [tr('operations.render_upgrade.online_upgrade'), data.github_repository && data.online_update?.staging_writable ? tr('updates.status.configured') : tr('updates.status.not_configured')],
      [tr('operations.render_upgrade.pending_migrations'), data.migrations.pending],
      [tr('operations.render_upgrade.blocked_migrations'), data.migrations.blocked],
    ]));
    advanced.append(node('p', data.migrations.ready ? tr('operations.render_upgrade.database_migrations_match_the_current_code') :
      data.migrations.blocked ? tr('operations.render_upgrade.resolve_migration_file_or_journal_inconsistencies_first') :
      tr('operations.render_upgrade.pending_migrations_require_the_server_upgrade_procedure')));
    if (data.manifest.errors.length) {
      const labels = {
        release_git_head_mismatch: tr('operations.render_upgrade.deployment_identifier_differs_from_the_code_baseline'),
        release_file_unlisted: tr('operations.render_upgrade.new_files_absent_from_the_manifest'),
        release_file_hash_mismatch: tr('operations.render_upgrade.changed_file_contents'),
        release_file_size_mismatch: tr('operations.render_upgrade.changed_file_sizes'),
      };
      const groups = {};
      data.manifest.errors.forEach(error => { const key = error.split(':')[0]; groups[key] = (groups[key] || 0) + 1; });
      const summary = node('ul', null, 'operation-negative');
      Object.entries(groups).forEach(([key, count]) => summary.append(node('li', (labels[key] || tr('operations.render_upgrade.other_release_verification_issues')) + ' · ' + count)));
      const diagnostics = node('details', null, 'system-diagnostics');
      diagnostics.append(node('summary', tr('operations.render_upgrade.view_verification_details') + ' (' + data.manifest.errors.length + ')'));
      const entries = node('ul'); data.manifest.errors.forEach(error => entries.append(node('li', error))); diagnostics.append(entries);
      advanced.append(summary, diagnostics);
    }
    const names = {applied: tr('operations.render_upgrade.applied'), pending: tr('operations.upgrade.pending'), incomplete: tr('operations.render_upgrade.incomplete'),
      checksum_mismatch: tr('operations.render_upgrade.checksum_mismatch'), missing_code: tr('operations.render_upgrade.migration_file_missing'), unreadable: tr('operations.render_upgrade.unreadable')};
    advanced.append(node('h3', tr('operations.render_upgrade.database_migration_checks'), 'operation-section-title'));
    const journal = node('details', null, 'system-migration-journal'); journal.open = !data.migrations.ready;
    journal.append(node('summary', tr('operations.render_upgrade.view_migration_records') + ' (' + data.migrations.items.length + ')'));
    journal.append(table([
      [tr('operations.render_upgrade.migration'), item => item.version],
      [tr('operations.render_maintenance.state'), item => badge(names[item.state] || item.state, item.state !== 'applied')],
      [tr('operations.upgrade.applied_time'), item => date(item.applied_at)],
    ], data.migrations.items)); advanced.append(journal);
    const online=data.online_update||{};
    advanced.append(node('h3', tr('updates.title'), 'operation-section-title'));
    advanced.append(node('p', tr('updates.description'), 'portal-help'));
    advanced.append(description([
      [tr('updates.staging_directory'), online.staging_writable ? tr('updates.status.writable') : tr('updates.status.unavailable')],
      [tr('updates.candidate_directory'), online.candidate_writable!==false ? tr('updates.status.writable') : tr('updates.status.unavailable')],
      [tr('updates.staged_packages'), online.pending_install||0],
      [tr('updates.prepared_packages'), online.prepared_packages||0],
      [tr('updates.installation'), data.online_update_install_enabled ? tr('updates.status.configured') : tr('updates.install_unavailable')],
    ]));
    const task=data.online_update_task;
    const activeTask=task&&['queued','running'].includes(task.status);
    const phaseLabel=phase=>tr('updates.install_progress.'+phase);
    const watchInstall=async(taskId,progress)=>{
      progress.hidden=false;
      for(let attempt=0;attempt<1800;attempt++){
        const state=await request('/upgrade/progress?'+new URLSearchParams({task_id:taskId}));
        progress.textContent=(state.percent??0)+'% · '+phaseLabel(state.phase||state.status);
        if(state.status==='failed')throw new Error(state.error||'update_install_failed');
        if(state.status==='completed'){
          progress.textContent=tr('updates.install_complete');
          if(notice){message(tr('updates.install_complete'),notice);notice.dataset.state='success';}
          await load();
          return;
        }
        await new Promise(resolve=>setTimeout(resolve,1000));
      }
      throw new Error('update_install_timeout');
    };
    if ((online.items||[]).length) {
      advanced.append(table([
        [tr('updates.release'), item => item.release],
        [tr('updates.artifact_hash'), item => item.artifact_sha256],
        [tr('updates.artifact_size'), item => byteSize(item.artifact_size)],
        [tr('updates.manifest_root_hash'), item => item.manifest_root_hash],
        [tr('updates.migrations'), item => item.migrations?.length],
        [tr('operations.render_maintenance.state'), item => badge(item.valid ? tr('updates.status.verified') : tr('updates.status.invalid'), !item.valid)],
        [tr('updates.candidate_state'), item => badge(item.prepared?.ready ? tr('updates.prepared') : tr('updates.not_prepared'), !item.prepared?.ready)],
        [tr('updates.actions'), item => {
          const actions=node('div',null,'operation-panel-actions');
          const prepare=button(item.prepared?.ready ? tr('updates.prepared') : tr('updates.prepare_button'),async()=>{
            prepare.disabled=true;
            try{
              await request('/upgrade/prepare',{release:item.release});
              await load();
              if(notice){message(tr('updates.prepare_success'),notice);notice.dataset.state='success';}
            }catch(error){message(error,notice);prepare.disabled=false;}
          });
          prepare.disabled=!item.valid||item.prepared?.ready||online.candidate_writable===false;
          actions.append(prepare);
          if(item.prepared?.ready){
            const install=button(tr('updates.install_button'),async()=>{
              install.disabled=true;
              const progress=node('p',tr('updates.install_starting'),'portal-help');
              actions.append(progress);
              try{
                const started=await request('/upgrade/install',{release:item.release});
                await watchInstall(started.task_id,progress);
              }catch(error){message(error,progress);install.disabled=false;}
            });
            install.disabled=!data.online_update_install_enabled||Boolean(activeTask)||item.release===data.release;
            actions.append(install);
          }
          return actions;
        }],
      ], online.items));
    } else {
      advanced.append(node('p', tr('updates.no_staged_packages'), 'portal-help'));
    }
    if(task){
      const progress=node('p', '', 'portal-help');
      progress.hidden=false;
      progress.textContent=task.status==='completed'?tr('updates.install_complete'):
        task.status==='failed'?tr('updates.install_failed'):((task.percent??0)+'% · '+phaseLabel(task.phase||task.status));
      advanced.append(progress);
      if(activeTask)watchInstall(task.task_id,progress).catch(error=>message(error,progress));
    }
    advanced.append(node('p', tr('updates.stage_boundary'), 'portal-help'));
  }
  async function loadDashboard() {
    const data = await request('/dashboard');
    const target = dashboard || results; target.replaceChildren();
    const names = {users: tr('operations.load_dashboard.users'), registrations_today: tr('operations.load_dashboard.new_users_today'), formal_users: tr('operations.load_dashboard.active_licenses'), formal_licenses: tr('operations.load_dashboard.active_licenses'), active_trials: tr('operations.load_dashboard.active_trials'), orders_today: tr('operations.load_dashboard.orders_today')};
    const metrics = node('div', null, 'admin-dashboard-grid');
    Object.entries(data.metrics).forEach(([key, value]) => {
      if (key === 'formal_users' && data.metrics.formal_licenses !== undefined) return;
      const card = node('article', null, 'admin-summary-card'); card.append(node('span', names[key] || key), node('strong', value)); metrics.append(card);
    });
    (data.sales_today || []).forEach(item => {
      const card = node('article', null, 'admin-summary-card'); card.append(node('span', tr('operations.load_dashboard.net_payments_today') + item.currency), node('strong', money(item.net_amount, item.currency))); metrics.append(card);
    });
    const todoItems = Array.isArray(data.todos) ? data.todos : [];
    const pendingTotal = Number(data.todos_total ?? todoItems.reduce((sum, item) => sum + Number(item.count || 0), 0));
    const todoHeading = node('div', null, 'operation-todo-heading');
    todoHeading.append(
      node('h2', tr('operations.load_dashboard.tasks_requiring_attention')),
      node('span', tr('operations.load_dashboard.pending_items', {count: pendingTotal}), 'operation-todo-total'),
    );
    const todos = node('div', null, 'operation-todo-grid');
    if (!todoItems.length) {
      todos.append(node('p', tr('operations.load_dashboard.no_pending_items'), 'operation-empty operation-todo-empty'));
    } else {
      todoItems.forEach(item => {
        const count = Number(item.count);
        const severity = item.severity === 'critical' ? 'critical' : 'high';
        const link = node('a', null, 'operation-todo');
        link.href = item.url;
        link.dataset.severity = severity;
        const meta = node('div', null, 'operation-todo-meta');
        meta.append(
          node('span', todoSeverityNames[severity], 'operation-todo-severity'),
          node('strong', new Intl.NumberFormat(locale).format(count)),
        );
        link.append(meta, node('span', tr(item.label), 'operation-todo-label'));
        if (Number.isSafeInteger(item.oldest_at)) {
          const waiting = node('time', tr('operations.load_dashboard.oldest_wait', {time: date(item.oldest_at)}), 'operation-todo-time');
          waiting.dateTime = new Date(Number(item.oldest_at) * 1000).toISOString();
          link.append(waiting);
        }
        todos.append(link);
      });
    }
    target.append(metrics, todoHeading, todos, node('p', tr('operations.load_dashboard.business_timezone') + data.timezone, 'portal-help'));
  }
  async function load() {
    const current = ++sequence;
    message('');
    try {
      if (section === 'dashboard') { await loadDashboard(); return; }
      const endpoint = section === 'users' ? '/users' : section === 'ledger' ? '/ledger' : section === 'product' ? '/products' : '/' + section;
      const data = await request(endpoint + '?' + queryString());
      if (current !== sequence) return;
      results.replaceChildren(); total = Number(data.total ?? 0);
      if (section === 'users') renderUsers(data);
      else if (section === 'locales') renderLocales(data);
      else if (section === 'orders') results.append(table([
        [tr('operations.diagnostics.order'), item => item.order_no], [tr('operations.render_users.user'), item => item.username], [tr('operations.load.product'), item => item.product],
        [tr('operations.load.paid_refunded'), item => stack(money(item.amount, item.currency), money(item.refunded_amount, item.currency))],
        [tr('operations.load.method'), item => item.payment_method], [tr('operations.edit_user.status'), item => badge(status(item.status))],
        [tr('operations.user_detail.created'), item => date(item.created_at)], [tr('operations.render_users.actions'), item => button(tr('operations.load.diagnostics'), () => void diagnostics(item.id))],
      ], data.items));
      else if (section === 'ledger') results.append(table([
        [tr('operations.render_users.user'), item => item.username], [tr('operations.load.type'), item => item.event_type],
        [tr('operations.load.change'), item => node('span', money(item.amount, item.currency), item.amount >= 0 ? 'operation-positive' : 'operation-negative')],
        [tr('operations.load.available_balance_after'), item => money(item.balance_after, item.currency)],
        ...(data.account === 'commission' ? [[tr('operations.load.reserve_change'), item => money(item.reserved_delta, item.currency)],
          [tr('operations.load.withdrawal_reserve_after'), item => money(item.reserved_after, item.currency)]] : []),
        [tr('operations.load.reference'), item => item.reference], [tr('operations.user_detail.time'), item => date(item.created_at)],
      ], data.items));
      else if (section === 'articles') results.append(table([
        [tr('operations.load.source_title_and_translation_status'), item => stack(
          item.source_title,
          (item.translation_status || []).map(translation => {
            const state = translation.published_revision_id ? tr('operations.status.published') : translation.draft_revision_id ? tr('operations.status.draft') : tr('operations.render_product.not_started');
            return translation.native_name + ': ' + state + (translation.outdated ? tr('operations.render_product.source_changed') : '');
          }).join(' · '))], [tr('operations.edit_article.category'), item => item.category],
        [tr('operations.edit_user.status'), item => badge(status(item.status))], [tr('operations.user_detail.updated'), item => date(item.updated_at)],
        [tr('operations.render_users.actions'), item => {
          const box = node('div'); box.append(button(tr('operations.load.edit'), () => editArticle(item)));
          box.append(button(tr('content_admin.publication.delete_all'), async () => {
            if (!window.confirm(tr('content_admin.publication.delete_all_confirm'))) return;
            try { await request('/articles/' + item.id + '/delete', {}); await load(); }
            catch (error) { message(error, notice); }
          }));
          if (item.status === 'published') { const link = node('a', tr('operations.load.view')); link.href = '/help/' + encodeURIComponent(item.slug); link.target = '_blank'; link.rel = 'noopener'; box.append(link); } return box;
        }],
      ], data.items));
      else if (section === 'product') renderProduct(data);
      else if (section === 'payment-interfaces') renderPaymentInterfaces(data);
      else if (section === 'staff') {
        staffRoles = data.roles; staffModules = data.modules; staffRoleLabels = data.role_labels; staffDependencies = data.dependencies;
        results.append(table([
          [tr('operations.load.staff'), item => stack(item.username, item.email)], [tr('operations.edit_user.status'), item => status(item.status)],
          [tr('operations.edit_staff.role'), item => roleName(staffRole(item))],
          [tr('operations.user_detail.last_sign_in'), item => stack(date(item.last_login_at), item.last_login_ip)], [tr('operations.load.permissions'), item => item.permissions.length],
          [tr('operations.render_users.actions'), item => button(tr('operations.load.edit'), () => editStaff(item))],
        ], data.items));
      } else if (section === 'maintenance') renderMaintenance(data);
      else if (section === 'upgrade') renderUpgrade(data);
      else if (section === 'tasks') {
        await renderTranslationSettings();
        results.append(table([
          [tr('operations.load.task'), item => item.task], [tr('operations.render_maintenance.state'), item => badge(status(item.state), !item.ok)],
          [tr('operations.edit_task.interval_in_seconds'), item => item.interval],
          [tr('operations.load.last_run'), item => date(item.finished_at)], [tr('operations.load.age_in_seconds'), item => item.age_seconds],
          [tr('operations.render_users.actions'), item => button(tr('operations.load.settings'), () => editTask(item))],
        ], data.items));
      }
      const pagination = document.querySelector('#operation-pagination');
      pagination.hidden = !['users', 'orders', 'ledger'].includes(section);
      document.querySelector('#operation-page').textContent = tr('operations.pagination.summary', {total, page: Math.floor(offset / 50) + 1});
      document.querySelector('#operation-previous').disabled = offset === 0;
      document.querySelector('#operation-next').disabled = offset + 50 >= total;
    } catch (error) { message(error, notice || dashboard); }
  }
  document.querySelector('#recharge-settings-form')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = form.querySelector('button');
    const feedback = document.querySelector('#recharge-settings-message');
    submit.disabled = true;
    message('', feedback);
    try {
      await request('/wallet-recharge', {enabled: form.elements.enabled.checked});
      message(tr('commerce.settings.recharge_saved'), feedback);
    } catch (error) {
      message(error, feedback);
    } finally {
      submit.disabled = false;
    }
  });
  document.querySelector('#operation-close')?.addEventListener('click', () => dialog.close());
  document.querySelector('#operation-refresh')?.addEventListener('click', () => void load());
  const categoryManager = document.querySelector('#article-category-manage');
  if (section === 'articles' && categoryManager) {
    categoryManager.hidden = false;
    categoryManager.addEventListener('click', () => manageArticleCategories().catch(error => message(error)));
  }
  document.querySelector('#operation-previous')?.addEventListener('click', () => { offset = Math.max(0, offset - 50); void load(); });
  document.querySelector('#operation-next')?.addEventListener('click', () => { offset += 50; void load(); });
  const create = document.querySelector('#operation-create');
  if (create && ['articles', 'staff'].includes(section)) {
    create.hidden = false; create.addEventListener('click', () => section === 'articles' ? editArticle() : editStaff());
  }
  buildFilters();
  void load();
})();
