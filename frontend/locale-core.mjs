import i18next from 'i18next';
import ICU from 'i18next-icu';

export function createTranslator(payload) {
  const engine = i18next.createInstance();
  engine.use(ICU).init({
    resources: payload.resources,
    lng: payload.locale,
    supportedLngs: Object.keys(payload.resources),
    fallbackLng: false,
    load: 'currentOnly',
    ns: payload.namespaces,
    defaultNS: 'common',
    keySeparator: false,
    nsSeparator: false,
    initAsync: false,
    returnNull: false,
    returnEmptyString: false,
    interpolation: {escapeValue: false},
    i18nFormat: {
      // UI inserts translations through textContent or attribute setters.
      escapeVariables: false,
      parseErrorHandler: (_error, key) => key,
    },
  });
  return Object.freeze({
    locale: payload.locale,
    revision: payload.revision,
    accountUrls: Object.freeze({...payload.account_urls}),
    tr(key, parameters = {}) {
      if (typeof key !== 'string' || !/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/.test(key)) {
        throw new Error('translation_key_invalid');
      }
      const dot = key.indexOf('.');
      const namespace = key.slice(0, dot);
      const message = key.slice(dot + 1);
      return String(engine.t(message, {
        ...parameters, lng: payload.locale, ns: namespace, returnObjects: false, defaultValue: message,
      }));
    },
  });
}
