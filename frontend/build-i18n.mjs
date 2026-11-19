import {build} from 'esbuild';
import {checkCatalogs} from './check-locales.mjs';
import {checkUiKeys} from './check-ui-keys.mjs';
import {existsSync, readFileSync, writeFileSync} from 'node:fs';
import {dirname, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const catalogs = checkCatalogs(root);
console.log('Validated '+catalogs.messages+' UI messages before building.');
const references = checkUiKeys(root);
const prefixChecked=references.dynamic.filter(reference=>reference.candidates.length>0).length;
const unresolvedDynamic=references.dynamic.filter(reference=>!reference.checked?.length&&!reference.candidates.length);
if(unresolvedDynamic.length||references.phpDynamic.length)throw new Error('ui_dynamic_key_unresolved: '+JSON.stringify({javascript:unresolvedDynamic,php:references.phpDynamic}));
if(references.hardcodedUiCopy.length||references.phpTemplateCopy.length)throw new Error('ui_hardcoded_visible_copy: '+JSON.stringify({javascript:references.hardcodedUiCopy,php_template:references.phpTemplateCopy}));
const hardcodedVisibleCopyCount=references.hardcodedUiCopy.length+references.phpTemplateCopy.length;
const javascriptDynamicChecked=references.dynamic.filter(reference=>reference.helper!=='d2s_tr'&&(reference.checked.length>0||reference.candidates.length>0)).length;
console.log('Validated '+references.keys+' static JS UI key references, '+references.phpTemplateKeys+' literal PHP UI references and '+references.phpKeys+' PHP/API contract keys; resolved '+javascriptDynamicChecked+' dynamic JS references, checked '+prefixChecked+' dynamic prefixes and found '+hardcodedVisibleCopyCount+' direct hardcoded visible strings across '+(references.dynamic.length+references.phpDynamic.length)+' inventoried references.');
const output = resolve(root, 'public/assets/vendor/i18n-runtime.js');
const result = await build({
  absWorkingDir:root, entryPoints:['frontend/locale-runtime.mjs'], bundle:true, minify:true,
  format:'iife', target:'es2022', legalComments:'external', outfile:output, metafile:true,
});
const packages = new Map();
for (const input of Object.keys(result.metafile.inputs)) {
  if (!input.startsWith('node_modules/')) continue;
  let directory = dirname(resolve(root, input));
  while (directory.startsWith(resolve(root, 'node_modules') + sep)) {
    const manifest = resolve(directory, 'package.json');
    if (existsSync(manifest)) {
      const data = JSON.parse(readFileSync(manifest, 'utf8'));
      if (data.name && data.version) {
        const license = ['LICENSE','LICENSE.md','LICENSE.txt','LICENCE'].map(name=>resolve(directory,name)).find(existsSync);
        if (!license) throw new Error('license_notice_missing: '+data.name);
        packages.set(data.name, data.name+' '+data.version+'\n'+readFileSync(license,'utf8').trim());
        break;
      }
    }
    directory = dirname(directory);
  }
}
if (packages.size === 0) throw new Error('bundle_dependency_notices_missing');
writeFileSync(resolve(root, 'public/assets/vendor/i18n-runtime.LICENSE.txt'),
  [...packages.entries()].sort(([a],[b])=>a.localeCompare(b)).map(([,notice])=>notice).join('\n\n'+'='.repeat(72)+'\n\n')+'\n','utf8');
console.log('Built i18n runtime with '+packages.size+' dependency notices.');
