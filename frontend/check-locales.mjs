import {parse, TYPE} from '@formatjs/icu-messageformat-parser';
import {readFileSync, readdirSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

export function catalogSignature(catalog) {
  if (!catalog || Array.isArray(catalog) || typeof catalog !== 'object') throw new Error('catalog_object_required');
  const result={};
  for (const [key,message] of Object.entries(catalog)) {
    if (!/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/.test(key)) throw new Error('catalog_key_invalid: '+key);
    if (typeof message !== 'string' || !message.trim()) throw new Error('catalog_message_empty: '+key);
    const parameters=new Map();
    const visit=elements=>{
      for (const element of elements) {
        if (element.type===TYPE.literal || element.type===TYPE.pound) continue;
        if (element.type===TYPE.tag) throw new Error('catalog_html_tag_unsupported: '+key);
        let type=String(element.type);
        if (element.type===TYPE.select) type+=':'+Object.keys(element.options).sort().join(',');
        if (element.type===TYPE.plural) {
          const exact=Object.keys(element.options).filter(value=>value.startsWith('=')).sort();
          type+=':'+element.pluralType+':'+element.offset+':'+exact.join(',');
        }
        const kinds=parameters.get(element.value)||new Set();
        kinds.add(type);parameters.set(element.value,kinds);
        if (element.options) for (const option of Object.values(element.options)) visit(option.value);
      }
    };
    visit(parse(message));
    result[key]=JSON.stringify([...parameters].sort(([a],[b])=>a.localeCompare(b)).map(([name,kinds])=>[name,[...kinds].sort()]));
  }
  return result;
}

export function findUntranslatedHanCopies(source, target, localeCode) {
  const primaryLanguage=String(localeCode || '').split('-')[0].toLowerCase();
  if (primaryLanguage==='zh' || primaryLanguage==='ja') return [];
  return Object.keys(source).filter(key => typeof source[key]==='string' &&
    /\p{Script=Han}/u.test(source[key]) && target[key]===source[key]);
}

export function isValidLanguageTag(code) {
  if (typeof code !== 'string' || code.length < 2 || code.length > 35 || !/^[A-Za-z0-9-]+$/.test(code)) return false;
  const grandfathered=new Set(['art-lojban','cel-gaulish','en-gb-oed','i-ami','i-bnn','i-default','i-enochian','i-hak','i-klingon','i-lux','i-mingo','i-navajo','i-pwn','i-tao','i-tay','i-tsu','no-bok','no-nyn','sgn-be-fr','sgn-be-nl','sgn-ch-de','zh-guoyu','zh-hakka','zh-min','zh-min-nan','zh-xiang']);
  if (grandfathered.has(code.toLowerCase())) return true;
  const parts=code.split('-');
  if (parts.some(part=>part==='')) return false;
  let index=0;
  const alpha=value=>/^[A-Za-z]+$/.test(value);
  const alnum=value=>/^[A-Za-z0-9]+$/.test(value);
  if (parts[0].toLowerCase()==='x') return parts.length>1 && parts.slice(1).every(part=>part.length<=8&&alnum(part));
  const languageLength=parts[0].length;
  if (languageLength>=2&&languageLength<=3&&alpha(parts[0])) {
    index=1;
    for(let extlang=0;extlang<3&&index<parts.length&&parts[index].length===3&&alpha(parts[index]);extlang++)index++;
  } else if ((languageLength===4||(languageLength>=5&&languageLength<=8))&&alpha(parts[0])) index=1;
  else return false;
  if(index<parts.length&&parts[index].length===4&&alpha(parts[index]))index++;
  if(index<parts.length&&(/^[A-Za-z]{2}$/.test(parts[index])||/^[0-9]{3}$/.test(parts[index])))index++;
  const variants=new Set();
  while(index<parts.length&&(/^[A-Za-z0-9]{5,8}$/.test(parts[index])||/^[0-9][A-Za-z0-9]{3}$/.test(parts[index]))) {
    const variant=parts[index].toLowerCase(); if(variants.has(variant))return false; variants.add(variant); index++;
  }
  const extensions=new Set();
  while(index<parts.length&&/^[0-9A-WY-Z]$/i.test(parts[index])) {
    const singleton=parts[index].toLowerCase(); if(extensions.has(singleton))return false; extensions.add(singleton); index++;
    const start=index;
    while(index<parts.length&&parts[index].length>=2&&parts[index].length<=8&&alnum(parts[index]))index++;
    if(index===start)return false;
  }
  if(index<parts.length&&parts[index].toLowerCase()==='x') {
    index++; const start=index;
    while(index<parts.length&&parts[index].length<=8&&alnum(parts[index]))index++;
    if(index===start)return false;
  }
  return index===parts.length;
}

export function compareCatalogs(source,target,{allowMissing=false,localeCode=null}={}) {
  const expected=catalogSignature(source), actual=catalogSignature(target);
  const missing=Object.keys(expected).filter(key=>!(key in actual));
  const extra=Object.keys(actual).filter(key=>!(key in expected));
  if ((missing.length && !allowMissing) || extra.length) throw new Error('catalog_keys_differ: missing='+missing.join(',')+' extra='+extra.join(','));
  for (const key of Object.keys(expected)) {
    if (key in actual && expected[key]!==actual[key]) throw new Error('catalog_parameters_differ: '+key);
  }
  const untranslated=localeCode ? findUntranslatedHanCopies(source,target,localeCode) : [];
  if (untranslated.length) throw new Error('catalog_untranslated_source_message: '+untranslated.join(','));
}

export function validateNativeLanguageName(locale,catalog) {
  if (!locale || typeof locale.native_name !== 'string' || !locale.native_name ||
      !catalog || catalog['language.name'] !== locale.native_name) {
    throw new Error('locale_native_name_mismatch: '+(locale?.code || 'unknown'));
  }
}

export function checkCatalogs(root=resolve(dirname(fileURLToPath(import.meta.url)),'..')) {
  const directory=resolve(root,'resources/locales');
  const registry=JSON.parse(readFileSync(resolve(directory,'manifest.json'),'utf8'));
  const locales=registry.locales;
  const enabledLocales=locales.filter(locale=>locale.enabled);
  if (!enabledLocales.some(locale=>locale.code===registry.default_locale)) throw new Error('default_locale_not_enabled');
  if (!isValidLanguageTag(registry.default_locale)) throw new Error('locale_code_invalid');
  const sourceDirectory=resolve(directory,registry.default_locale);
  const namespaces=readdirSync(sourceDirectory).filter(file=>/^[a-z][a-z0-9_-]*\.json$/.test(file)).sort();
  let messages=0;
  const incompleteLocales=[];
  for (const locale of locales) {
    if (!isValidLanguageTag(locale.code)) throw new Error('locale_code_invalid');
    if (locale.catalog_mode !== undefined) throw new Error('locale_catalog_mode_unsupported: '+locale.code);
    const localeDirectory=resolve(directory,locale.code);
    const localeNamespaces=readdirSync(localeDirectory).filter(file=>/^[a-z][a-z0-9_-]*\.json$/.test(file)).sort();
    const missingNamespaces=namespaces.filter(file=>!localeNamespaces.includes(file));
    const extraNamespaces=localeNamespaces.filter(file=>!namespaces.includes(file));
    if (missingNamespaces.length||extraNamespaces.length) {
      throw new Error(locale.code+'/namespaces: missing='+missingNamespaces.join(',')+' extra='+extraNamespaces.join(','));
    }
    let incomplete=false;
    for (const file of namespaces) {
      const source=JSON.parse(readFileSync(resolve(sourceDirectory,file),'utf8'));
      const catalog=JSON.parse(readFileSync(resolve(localeDirectory,file),'utf8'));
      if (file==='common.json') validateNativeLanguageName(locale,catalog);
      try { compareCatalogs(source,catalog,{allowMissing:true,localeCode:locale.code}); }
      catch (error) { throw new Error(locale.code+'/'+file+': '+error.message); }
      if (Object.keys(source).some(key=>!(key in catalog))) incomplete=true;
      messages+=Object.keys(catalog).length;
    }
    if (incomplete) incompleteLocales.push(locale.code);
  }
  return {locales:locales.length,enabled_locales:enabledLocales.length,incomplete_locales:incompleteLocales,namespaces:namespaces.length,messages};
}

if (process.argv[1] && resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
  console.log('PASS UI catalog structure and ICU signatures: '+JSON.stringify(checkCatalogs()));
}
