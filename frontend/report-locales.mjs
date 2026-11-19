import {readFileSync, readdirSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {catalogSignature, findUntranslatedHanCopies, isValidLanguageTag} from './check-locales.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const localeRoot = resolve(root, 'resources/locales');
const registry = JSON.parse(readFileSync(resolve(localeRoot, 'manifest.json'), 'utf8'));
const sourceLocale = registry.default_locale;
const sourceRoot = resolve(localeRoot, sourceLocale);
const namespaces = readdirSync(sourceRoot).filter(file => /^[a-z][a-z0-9_-]*\.json$/.test(file)).sort();
const args = process.argv.slice(2);
const localeArg = args.find(value => value.startsWith('--locale='));
const requestedLocale = localeArg?.slice('--locale='.length);
const jsonOutput = args.includes('--json');
const summaryOnly = args.includes('--summary');
const strict = args.includes('--strict');
const unknownArgs = args.filter(value => value !== '--json' && value !== '--summary' && value !== '--strict' && !value.startsWith('--locale='));

if (unknownArgs.length) throw new Error('unknown_argument: ' + unknownArgs.join(' '));
if (registry.locales.some(locale => !isValidLanguageTag(locale.code))) throw new Error('locale_code_invalid');
if (requestedLocale && !registry.locales.some(locale => locale.code === requestedLocale)) {
  throw new Error('locale_not_registered: ' + requestedLocale);
}

const sourceCatalogs = new Map();
const sourceMessages = new Map();
const sourceNamespaceKeys = new Map();
for (const file of namespaces) {
  const catalog = JSON.parse(readFileSync(resolve(sourceRoot, file), 'utf8'));
  const signature = catalogSignature(catalog);
  sourceCatalogs.set(file, signature);
  sourceMessages.set(file, catalog);
  sourceNamespaceKeys.set(file, Object.keys(signature));
}

function inspectLocale(locale) {
  const directory = resolve(localeRoot, locale.code);
  let files = [];
  try {
    files = readdirSync(directory).filter(file => /^[a-z][a-z0-9_-]*\.json$/.test(file));
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
  const fileSet = new Set(files);
  const namespaceReports = [];
  for (const file of namespaces) {
    const expected = sourceCatalogs.get(file);
    const expectedKeys = sourceNamespaceKeys.get(file);
    if (!fileSet.has(file)) {
      namespaceReports.push({namespace: file.slice(0, -5), missing_file: true,
        missing_keys: expectedKeys, extra_keys: [], parameter_mismatches: [], untranslated_keys: [], invalid: null});
      continue;
    }
    try {
      const catalog = JSON.parse(readFileSync(resolve(directory, file), 'utf8'));
      const actual = catalogSignature(catalog);
      const missingKeys = Object.keys(expected).filter(key => !(key in actual));
      const extraKeys = Object.keys(actual).filter(key => !(key in expected));
      const parameterMismatches = Object.keys(expected).filter(key => key in actual && expected[key] !== actual[key]);
      const untranslatedKeys = findUntranslatedHanCopies(sourceMessages.get(file),catalog,locale.code);
      namespaceReports.push({namespace: file.slice(0, -5), missing_file: false,
        missing_keys: missingKeys, extra_keys: extraKeys, parameter_mismatches: parameterMismatches,
        untranslated_keys: untranslatedKeys, invalid: null});
    } catch (error) {
      namespaceReports.push({namespace: file.slice(0, -5), missing_file: false,
        missing_keys: expectedKeys, extra_keys: [], parameter_mismatches: [], untranslated_keys: [],
        invalid: String(error.message || error)});
    }
  }
  const extraNamespaces = files.filter(file => !sourceCatalogs.has(file)).map(file => file.slice(0, -5)).sort();
  const missingCount = namespaceReports.reduce((sum, item) => sum + item.missing_keys.length, 0);
  const extraCount = namespaceReports.reduce((sum, item) => sum + item.extra_keys.length, 0) + extraNamespaces.length;
  const mismatchCount = namespaceReports.reduce((sum, item) => sum + item.parameter_mismatches.length, 0);
  const untranslatedCount = namespaceReports.reduce((sum, item) => sum + item.untranslated_keys.length, 0);
  const invalidCount = namespaceReports.filter(item => item.invalid).length;
  return {
    locale: locale.code,
    native_name: locale.native_name,
    enabled: Boolean(locale.enabled),
    public_enabled: Boolean(locale.public_enabled),
    catalog_state: missingCount === 0 && extraCount === 0 && mismatchCount === 0 && untranslatedCount === 0 && invalidCount === 0 ? 'complete' : 'incomplete',
    fallback_locales: locale.fallback_locales || [],
    source_locale: sourceLocale,
    namespace_count: namespaces.length,
    message_count: [...sourceNamespaceKeys.values()].reduce((sum, keys) => sum + keys.length, 0),
    missing_count: missingCount,
    extra_count: extraCount,
    parameter_mismatch_count: mismatchCount,
    untranslated_source_count: untranslatedCount,
    invalid_catalog_count: invalidCount,
    complete: missingCount === 0 && extraCount === 0 && mismatchCount === 0 && untranslatedCount === 0 && invalidCount === 0,
    extra_namespaces: extraNamespaces,
    namespaces: namespaceReports,
  };
}

const selectedLocales = registry.locales.filter(locale => !requestedLocale || locale.code === requestedLocale);
const report = {
  source_locale: sourceLocale,
  checked_locales: selectedLocales.length,
  complete_locales: 0,
  incomplete_locales: 0,
  locales: selectedLocales.map(inspectLocale),
};
report.complete_locales = report.locales.filter(locale => locale.complete).length;
report.incomplete_locales = report.locales.length - report.complete_locales;

if (jsonOutput) {
  process.stdout.write(JSON.stringify(report, null, 2) + '\n');
} else {
  for (const locale of report.locales) {
    process.stdout.write(`${locale.locale} (${locale.native_name})${locale.enabled ? ' enabled' : ' disabled'}: ` +
      `${locale.complete ? 'complete' : 'incomplete'}, ${locale.missing_count} missing, ${locale.extra_count} extra, ` +
      `${locale.parameter_mismatch_count} parameter mismatches, ${locale.untranslated_source_count} untranslated source messages, ` +
      `${locale.invalid_catalog_count} invalid catalogs\n`);
    if (summaryOnly) continue;
    for (const namespace of locale.namespaces) {
      const hasIssues = namespace.missing_file || namespace.missing_keys.length || namespace.extra_keys.length ||
        namespace.parameter_mismatches.length || namespace.untranslated_keys.length || namespace.invalid;
      if (!hasIssues) continue;
      process.stdout.write(`  ${namespace.namespace}: ${namespace.missing_keys.length} missing` +
        `${namespace.missing_file ? ' (file missing)' : ''}; ${namespace.extra_keys.length} extra; ` +
        `${namespace.parameter_mismatches.length} parameter mismatches` +
        `${namespace.invalid ? '; invalid: ' + namespace.invalid : ''}\n`);
      if (namespace.missing_keys.length) process.stdout.write(`    missing keys: ${namespace.missing_keys.join(', ')}\n`);
      if (namespace.extra_keys.length) process.stdout.write(`    extra keys: ${namespace.extra_keys.join(', ')}\n`);
      if (namespace.parameter_mismatches.length) process.stdout.write(`    parameter mismatches: ${namespace.parameter_mismatches.join(', ')}\n`);
      if (namespace.untranslated_keys.length) process.stdout.write(`    untranslated source messages: ${namespace.untranslated_keys.join(', ')}\n`);
    }
  }
  process.stdout.write(`Summary: ${report.complete_locales} complete, ${report.incomplete_locales} incomplete of ${report.checked_locales} registered locales.\n`);
}

const enabledIncomplete = report.locales.some(locale => locale.enabled && !locale.complete);
const selectedIncomplete = Boolean(requestedLocale && report.locales.some(locale=>locale.locale===requestedLocale && !locale.complete));
if (enabledIncomplete || selectedIncomplete || (strict && report.incomplete_locales)) process.exitCode = 1;
