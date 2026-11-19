'use strict';

(() => {
  const form = document.querySelector('#feedback-form');
  if (!form) return;

  const message = document.querySelector('#feedback-message');
  const submit = document.querySelector('#feedback-submit');
  const filesInput = document.querySelector('#feedback-files');
  const success = document.querySelector('#feedback-success');
  const reference = document.querySelector('#feedback-reference');
  const reset = document.querySelector('#feedback-reset');
  const endpoint = form.dataset.endpoint || '/api/v2/support/tickets';
  const submitLabel = submit.textContent;

  const csrf = () => {
    const prefix = 'D2S_USER_SESSION_CSRF=';
    const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));
    return item ? decodeURIComponent(item.slice(prefix.length)) : '';
  };

  const report = (text) => {
    message.textContent = text;
  };

  const request = async (path, data) => {
    const response = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(data),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) throw new Error(result.error || 'service_unavailable');
    return result.data;
  };

  const upload = async (ticketId, messageId, file) => {
    const payload = new FormData();
    payload.append('message_id', messageId);
    payload.append('file', file);
    const response = await fetch('/api/v2/support/tickets/' + ticketId + '/attachments', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf() },
      body: payload,
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.success) throw new Error(result.error || 'attachment_upload_invalid');
    return result.data;
  };

  const selectType = (value) => {
    if (!value) return;
    const radio = form.querySelector('input[name="category"][value="' + value.toUpperCase() + '"]');
    if (radio) radio.checked = true;
  };
  selectType(new URLSearchParams(window.location.search).get('type'));
  for (const link of document.querySelectorAll('[data-feedback-type]')) {
    link.addEventListener('click', () => {
      selectType(link.dataset.feedbackType || '');
      window.setTimeout(() => form.scrollIntoView({ behavior: 'smooth', block: 'start' }), 0);
    });
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;

    const files = Array.from(filesInput.files || []);
    if (files.length > 5 || files.some((file) => file.size > 5 * 1024 * 1024)) {
      report(form.dataset.msgFiles || '');
      return;
    }
    if ((form.elements.website?.value || '').trim() !== '') {
      report(form.dataset.msgSubmitFailed || '');
      return;
    }

    const category = form.querySelector('input[name="category"]:checked')?.value || '';
    const version = form.elements.software_version.value.trim();
    const environment = form.elements.environment.value.trim();
    const severity = form.elements.severity.selectedOptions[0]?.textContent?.trim() || '';
    const description = form.elements.body.value.trim();
    const details = [];
    if (version) details.push((form.dataset.labelVersion || '') + ': ' + version);
    if (environment) details.push((form.dataset.labelEnvironment || '') + ': ' + environment);
    if (severity) details.push((form.dataset.labelSeverity || '') + ': ' + severity);
    details.push((form.dataset.labelDescription || '') + ':\n' + description);
    const body = details.join('\n\n');
    if (body.length > 5000) {
      report(form.dataset.msgTooLong || '');
      return;
    }

    submit.disabled = true;
    submit.textContent = form.dataset.msgSubmitting || submitLabel;
    report('');
    try {
      const created = await request(endpoint, {
        category,
        subject: form.elements.subject.value.trim(),
        order_no: form.elements.order_no.value.trim(),
        body,
      });

      let uploaded = 0;
      let uploadFailed = false;
      for (const file of files) {
        try {
          await upload(created.id, created.message_id, file);
          uploaded += 1;
        } catch {
          uploadFailed = true;
          break;
        }
      }

      const progress = document.querySelector('#feedback-progress');
      if (progress) {
        for (const step of progress.querySelectorAll('li')) step.removeAttribute('aria-current');
        progress.querySelector('li:nth-child(3)')?.setAttribute('aria-current', 'step');
      }
      document.dispatchEvent(new CustomEvent('support:created', { detail: { id: created.id } }));
      for (const link of success.querySelectorAll('a[href="/tickets"]')) link.href = '/tickets?ticket=' + created.id;
      form.hidden = true;
      success.hidden = false;
      reference.textContent = created.id.slice(0, 8).toUpperCase();
      message.textContent = uploadFailed
        ? (form.dataset.msgPartial || '').replace('{uploaded}', String(uploaded))
        : form.dataset.msgCreated || '';
      success.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (error) {
      if (error.message === 'ticket_order_not_found') {
        report(form.dataset.msgOrderNotFound || '');
      } else if (error.message === 'authentication_required' || error.message === 'session_invalid') {
        report(form.dataset.msgAuthRequired || '');
      } else {
        report(form.dataset.msgSubmitFailed || '');
      }
    } finally {
      submit.disabled = false;
      submit.textContent = submitLabel;
    }
  });

  reset?.addEventListener('click', () => {
    form.reset();
    form.hidden = false;
    success.hidden = true;
    document.querySelector('#feedback-progress li:nth-child(3)')?.removeAttribute('aria-current');
    document.querySelector('#feedback-progress li:nth-child(2)')?.setAttribute('aria-current', 'step');
    message.textContent = '';
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
