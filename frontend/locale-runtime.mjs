import {createTranslator} from './locale-core.mjs';

const element = document.querySelector('#d2s-locale-catalog');
if (element) {
  window.D2SI18n = createTranslator(JSON.parse(element.textContent));
}
