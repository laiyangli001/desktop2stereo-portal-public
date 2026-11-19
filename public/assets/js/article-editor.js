'use strict';
(() => {
  const editors = new WeakMap();
  const t = (key, parameters) => window.D2SI18n.tr('editor.' + key, parameters);
  const numberFormat = new Intl.NumberFormat(window.D2SI18n.locale);
  function mount(form, name, label, value = '', format = 'plain', required = false) {
    if (!window.Quill) throw new Error(t('the_rich_text_editor_failed_to_load_refresh_the_page'));
    const wrapper = document.createElement('section');
    wrapper.className = 'article-editor wide';
    const heading = document.createElement('label');
    heading.id = name + '-label'; heading.textContent = label + (required ? ' *' : '');
    const container = document.createElement('div');
    container.id = name + '-editor';
    const hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = name;
    const count = document.createElement('small');
    count.id = name + '-count'; count.className = 'portal-help';
    wrapper.append(heading, container, hidden, count); form.append(wrapper);
    const quill = new Quill(container, {
      theme: 'snow',
      formats: ['header', 'bold', 'italic', 'underline', 'strike', 'blockquote', 'code-block', 'list', 'link', 'align', 'indent'],
      modules: { toolbar: [
        [{header: [1, 2, 3, false]}],
        ['bold', 'italic', 'underline', 'strike'],
        [{list: 'ordered'}, {list: 'bullet'}, {indent: '-1'}, {indent: '+1'}],
        ['blockquote', 'code-block', 'link'], [{align: []}], ['clean'],
      ] },
      placeholder: t('toolbar.placeholder'),
    });
    const editable = quill.root;
    editable.setAttribute('role', 'textbox'); editable.setAttribute('aria-multiline', 'true');
    editable.setAttribute('aria-labelledby', heading.id);
    editable.setAttribute('aria-describedby', count.id);
    editable.setAttribute('aria-required', String(required));
    heading.addEventListener('click', () => quill.focus());
    if (format === 'html') quill.setContents(quill.clipboard.convert({html: value || ''}), 'silent');
    else quill.setText(value || '', 'silent');
    const labels = {
      bold: t('toolbar.bold'), italic: t('toolbar.italic'), underline: t('toolbar.underline'),
      strike: t('toolbar.strike'), blockquote: t('toolbar.blockquote'), 'code-block': t('toolbar.code_block'),
      link: t('toolbar.link'), clean: t('toolbar.clean'),
      list: t('toolbar.list'), indent: t('toolbar.indent'),
    };
    wrapper.querySelectorAll('.ql-toolbar button').forEach(button => {
      const formatName = [...button.classList].find(item => item.startsWith('ql-'))?.slice(3);
      let title = labels[formatName] || formatName;
      if (formatName === 'list') title = button.value === 'ordered' ? t('numbered_list') : t('bullet_list');
      if (formatName === 'indent') title = button.value === '+1' ? t('increase_indent') : t('decrease_indent');
      button.title = title; button.setAttribute('aria-label', title);
    });
    wrapper.querySelectorAll('.ql-picker-label').forEach(item => {
      item.setAttribute('aria-label', item.closest('.ql-header') ? t('heading_level') : t('alignment'));
    });
    const text = () => quill.getText().replace(/\n$/, '');
    function sync() {
      hidden.value = text().trim() ? quill.getSemanticHTML() : '';
      hidden.dispatchEvent(new Event('input', {bubbles: true}));
      count.textContent = t('counter', {
        count: numberFormat.format([...text()].length),
        limit: numberFormat.format(14000),
      });
      editable.removeAttribute('aria-invalid');
    }
    quill.on('text-change', sync); sync();
    const instances = editors.get(form) || [];
    instances.push({quill, editable, text, sync, required, label});
    editors.set(form, instances);
  }
  function validate(form) {
    for (const editor of editors.get(form) || []) {
      editor.sync();
      if ((editor.required && !editor.text().replace(/[\u00a0\u200b-\u200d\ufeff]/g, ' ').trim()) || [...editor.text()].length > 14000) {
        editor.editable.setAttribute('aria-invalid', 'true'); editor.quill.focus();
        throw new Error(t('invalid_content', {label: editor.label, limit: 14000}));
      }
    }
  }
  window.ArticleEditor = Object.freeze({mount, validate});
})();
