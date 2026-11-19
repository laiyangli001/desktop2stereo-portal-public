'use strict';

// Build a small formatting subset with DOM nodes; stored HTML is always plain text.
window.SupportFormat = (() => {
  const tr = (key, parameters = {}) => window.D2SI18n.tr('support.' + key, parameters);
  const node = (tag, value) => {
    const result = document.createElement(tag);
    if (value !== undefined) result.textContent = value;
    return result;
  };
  function inline(parent, source) {
    const pattern = /\*\*([^*\n]+)\*\*|\*([^*\n]+)\*|`([^`\n]+)`|\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g;
    let previous = 0;
    for (const match of source.matchAll(pattern)) {
      parent.append(document.createTextNode(source.slice(previous, match.index)));
      if (match[1]) parent.append(node('strong', match[1]));
      else if (match[2]) parent.append(node('em', match[2]));
      else if (match[3]) parent.append(node('code', match[3]));
      else {
        const link = node('a', match[4]);
        link.href = match[5];
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        parent.append(link);
      }
      previous = match.index + match[0].length;
    }
    parent.append(document.createTextNode(source.slice(previous)));
  }
  function render(source, format = 'PLAIN') {
    const result = node('div');
    result.className = 'support-rich-body';
    if (String(format).toUpperCase() === 'HTML') {
      result.classList.add('html');
      result.innerHTML = String(source);
      for (const link of result.querySelectorAll('a')) {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }
      return result;
    }
    let list = null;
    for (const line of String(source).split('\n')) {
      const bullet = /^[-*] (.*)$/.exec(line);
      if (bullet) {
        if (!list) { list = node('ul'); result.append(list); }
        const item = node('li');
        inline(item, bullet[1]);
        list.append(item);
      } else {
        list = null;
        const quote = /^> ?(.*)$/.exec(line);
        const row = node(quote ? 'blockquote' : 'p');
        inline(row, quote ? quote[1] : line);
        if (!line) row.append(node('br'));
        result.append(row);
      }
    }
    return result;
  }
  function enhance(input) {
    if (!input) return;
    const toolbar = node('div');
    toolbar.className = 'support-format-toolbar';
    toolbar.setAttribute('role', 'group');
    toolbar.setAttribute('aria-label', tr('format.group'));
    const preview = node('div');
    preview.className = 'support-format-preview';
    preview.hidden = true;
    preview.setAttribute('aria-label', tr('format.preview_aria'));
    const tools = [
      [tr('format.bold'), '**', '**'], [tr('format.italic'), '*', '*'],
      [tr('format.list'), '- ', ''], [tr('format.quote'), '> ', ''],
      [tr('format.code'), '`', '`'], [tr('format.link'), '[', '](https://example.com)'],
    ];
    for (const [label, before, after] of tools) {
      const button = node('button', label);
      button.type = 'button';
      button.addEventListener('click', () => {
        if (input.disabled || input.readOnly) return;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selected = input.value.slice(start, end) || tr('format.text');
        const value = before + selected + after;
        if (input.value.length - (end - start) + value.length > input.maxLength) return;
        input.setRangeText(value, start, end, 'select');
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
      toolbar.append(button);
    }
    const toggle = node('button', tr('format.preview'));
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', () => {
      preview.hidden = !preview.hidden;
      toggle.setAttribute('aria-expanded', String(!preview.hidden));
      preview.replaceChildren(render(input.value));
    });
    toolbar.append(toggle);
    input.before(toolbar);
    input.after(preview);
    input.addEventListener('input', () => {
      if (!preview.hidden) preview.replaceChildren(render(input.value));
    });
  }
  function attachments(files, prefix) {
    const result = node('div');
    result.className = 'support-attachments';
    for (const file of files) {
      const card = node('div');
      const url = prefix + encodeURIComponent(file.id);
      if (['image/jpeg', 'image/png'].includes(file.mime_type)) {
        const imageLink = node('a');
        imageLink.href = url;
        const image = node('img');
        image.src = url;
        image.alt = file.original_name;
        image.loading = 'lazy';
        image.className = 'support-attachment-image';
        imageLink.append(image);
        card.append(imageLink);
      }
      const link = node('a', file.original_name + ' (' + Math.ceil(Number(file.byte_size) / 1024) + ' KB)');
      link.href = url;
      card.append(link);
      result.append(card);
    }
    return result;
  }
  return { render, enhance, attachments, tr };
})();
