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
  const eyeIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>';
  const eyeOffIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18"></path><path d="M10.6 6.2A10.8 10.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3 3.7"></path><path d="M6.2 6.2C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6c1.2 0 2.3-.3 3.3-.7"></path></svg>';
  button.innerHTML = eyeIcon;
  button.setAttribute('aria-label', 'Show password');
  button.setAttribute('aria-pressed', 'false');
  button.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.innerHTML = visible ? eyeOffIcon : eyeIcon;
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
