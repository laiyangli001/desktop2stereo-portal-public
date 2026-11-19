'use strict';

function addPasswordVisibility(input) {
  if (input.dataset.passwordVisibilityReady === 'true') return;
  input.dataset.passwordVisibilityReady = 'true';

  const wrapper = document.createElement('span');
  wrapper.className = 'password-visibility-field';
  input.parentNode.insertBefore(wrapper, input);
  wrapper.append(input);

  const button = document.createElement('button');
  button.className = 'password-visibility-toggle';
  button.type = 'button';
  button.textContent = '◉';
  button.setAttribute('aria-label', 'Show password');
  button.setAttribute('aria-pressed', 'false');
  button.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
  });
  wrapper.append(button);
}

document.querySelectorAll('input[type="password"]').forEach(addPasswordVisibility);

new MutationObserver((mutations) => {
  for (const mutation of mutations) {
    for (const node of mutation.addedNodes) {
      if (!(node instanceof Element)) continue;
      if (node.matches('input[type="password"]')) addPasswordVisibility(node);
      node.querySelectorAll?.('input[type="password"]').forEach(addPasswordVisibility);
    }
  }
}).observe(document.documentElement, {childList: true, subtree: true});
