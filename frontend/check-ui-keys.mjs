import {parse} from 'acorn';
import {readFileSync, readdirSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

function collectPhpFiles(path) {
  return readdirSync(path,{withFileTypes:true}).flatMap(entry=>{
    const target=resolve(path,entry.name);
    if(entry.isDirectory())return collectPhpFiles(target);
    return entry.isFile()&&entry.name.endsWith('.php')?[target]:[];
  });
}

function scanPhpTemplateCopy(root) {
  const findings=[];
  const allowlistedAcronyms=new Set(['CNY','DESKTOP2STEREO','HW','OS','SMTP','USD','W','v']);
  const blank=value=>value.replace(/[^\n]/g,' ');
  for(const filePath of collectPhpFiles(resolve(root,'template'))){
    const source=readFileSync(filePath,'utf8');
    const relative=filePath.slice(root.length+1).replaceAll('\\','/');
    const markup=source.replace(/<\?(?:php|=)[\s\S]*?\?>/gi,blank)
      .replace(/<!--[\s\S]*?-->/g,blank)
      .replace(/<(script|style|pre|code)\b[^>]*>[\s\S]*?<\/\1\s*>/gi,blank);
    const inspect=(raw,index,kind)=>{
      const value=raw.replace(/&(?:[a-z]+|#\d+|#x[0-9a-f]+);/gi,' ').replace(/\s+/g,' ').trim();
      if(!/\p{L}/u.test(value)||allowlistedAcronyms.has(value)||/\bDesktop2Stereo\b/i.test(value)
        ||/^(?:· )?d2s\.(?:site|top)(?: · d2s\.(?:site|top))?$/.test(value)
        ||value==='IP Geolocation by DB-IP'
        ||(relative==='template/feedback-submit.php'&&kind==='text'&&value==='Website')
        ||(relative==='template/content.php'&&kind==='attribute'&&/^(?:owner\/repository|https:\/\/beian\.mps\.gov\.cn\/)$/.test(value)))return;
      findings.push({file:relative,line:source.slice(0,index).split('\n').length,kind,text:value.slice(0,120)});
    };
    for(const match of markup.matchAll(/>([^<]+)</g))inspect(match[1],match.index??0,'text');
    for(const match of markup.matchAll(/\b(?:aria-label|title|alt|placeholder)\s*=\s*(["'])([^"']*)\1/gi))inspect(match[2],match.index??0,'attribute');
  }
  return findings;
}

function phpFirstArgument(source,openIndex) {
  let quote='',escaped=false,round=0,square=0,curly=0;
  for(let index=openIndex+1;index<source.length;index++){
    const char=source[index],next=source[index+1];
    if(quote){
      if(escaped){escaped=false;continue;}
      if(char==='\\'){escaped=true;continue;}
      if(char===quote)quote='';
      continue;
    }
    if(char==="'"||char==='"'||char===String.fromCharCode(96)){quote=char;continue;}
    if(char==='/'&&next==='*'){
      const end=source.indexOf('*/',index+2);
      index=end<0?source.length:end+1;
      continue;
    }
    if((char==='/'&&next==='/')||char==='#'){
      const end=source.indexOf('\n',index+1);
      index=end<0?source.length:end;
      continue;
    }
    if(char==='('){round++;continue;}
    if(char===')'){
      if(round===0&&square===0&&curly===0)return source.slice(openIndex+1,index);
      round--;continue;
    }
    if(char==='['){square++;continue;}
    if(char===']'){square--;continue;}
    if(char==='{'){curly++;continue;}
    if(char==='}'){curly--;continue;}
    if(char===','&&round===0&&square===0&&curly===0)return source.slice(openIndex+1,index);
  }
  return source.slice(openIndex+1);
}

function phpStringLiterals(expression) {
  const values=[];
  const pattern=/'((?:\\.|[^'\\])*)'|"((?:\\.|[^"\\])*)"/g;
  for(const match of expression.matchAll(pattern))values.push(match[1]??match[2]);
  return values;
}

function staticString(node) {
  if(node?.type==='Literal'&&typeof node.value==='string')return node.value;
  if(node?.type==='TemplateLiteral'&&node.expressions.length===0)return node.quasis[0]?.value?.cooked??'';
  return null;
}

function stringPrefix(node) {
  const value=staticString(node);
  if(value!==null)return value;
  if(node?.type==='BinaryExpression'&&node.operator==='+') {
    const left=staticString(node.left);
    if(left!==null) {
      const right=staticString(node.right);
      return left+(right??'');
    }
    return stringPrefix(node.left);
  }
  if(node?.type==='TemplateLiteral')return node.quasis[0]?.value?.cooked??'';
  return null;
}

function lookupBinding(scope, name) {
  for(let current=scope;current;current=current.parent)if(current.bindings.has(name))return current.bindings.get(name);
  return null;
}

function resolveProperty(node, property, scope, seen=new Set()) {
  if(!node)return [];
  if(node.type==='ObjectExpression')return node.properties
    .filter(item=>item.type==='Property')
    .filter(item=>property===undefined||staticString(item.key)===property||(!item.computed&&item.key.name===property))
    .map(item=>item.value);
  if(node.type==='ArrayExpression') {
    if(property===undefined)return node.elements.filter(Boolean);
    if(/^(?:0|[1-9][0-9]*)$/.test(property))return node.elements[Number(property)]?[node.elements[Number(property)]]:[];
    return [];
  }
  if(node.type==='Identifier'&&!seen.has(node.name)) {
    const binding=lookupBinding(scope,node.name);
    if(!binding)return [];
    const nextSeen=new Set(seen);nextSeen.add(node.name);
    return resolveProperty(binding,property,scope,nextSeen);
  }
  if(node.type==='MemberExpression') {
    const member=node.computed?(staticString(node.property)??undefined):node.property?.name;
    const values=resolveProperty(node.object,member,scope,seen);
    if(property===undefined)return values;
    return values.flatMap(value=>resolveProperty(value,property,scope,seen));
  }
  if(node.type==='ConditionalExpression'||node.type==='LogicalExpression') {
    const branches=node.type==='ConditionalExpression'?[node.consequent,node.alternate]:[node.left,node.right];
    return branches.flatMap(branch=>resolveProperty(branch,property,scope,seen));
  }
  return [];
}

function analyzeKeyExpression(node, scope, seen=new Set()) {
  const value=staticString(node);
  if(value!==null)return {candidates:[{value,mode:'exact'}],dynamic:false};
  if(node?.type==='Identifier'&&!seen.has(node.name)) {
    const binding=lookupBinding(scope,node.name);
    if(binding) {
      const nextSeen=new Set(seen);nextSeen.add(node.name);
      return analyzeKeyExpression(binding,scope,nextSeen);
    }
  }
  if(node?.type==='ConditionalExpression'||node?.type==='LogicalExpression') {
    const branches=node.type==='ConditionalExpression'
      ? [analyzeKeyExpression(node.consequent,scope,seen),analyzeKeyExpression(node.alternate,scope,seen)]
      : [analyzeKeyExpression(node.left,scope,seen),analyzeKeyExpression(node.right,scope,seen)];
    return {candidates:branches.flatMap(branch=>branch.candidates),dynamic:branches.some(branch=>branch.dynamic)};
  }
  if(node?.type==='ArrayExpression') {
    const branches=node.elements.filter(Boolean).map(item=>analyzeKeyExpression(item,scope,seen));
    return {candidates:branches.flatMap(branch=>branch.candidates),dynamic:true};
  }
  if(node?.type==='MemberExpression') {
    const property=node.computed?(staticString(node.property)??undefined):node.property?.name;
    const values=resolveProperty(node.object,property,scope,seen);
    if(values.length) {
      const branches=values.map(item=>analyzeKeyExpression(item,scope,seen));
      return {candidates:branches.flatMap(branch=>branch.candidates),dynamic:true};
    }
  }
  if(node?.type==='BinaryExpression'&&node.operator==='+') {
    const prefix=stringPrefix(node);
    return {candidates:prefix?[{value:prefix,mode:'prefix'}]:[],dynamic:true};
  }
  return {candidates:[],dynamic:true};
}

export function checkUiKeys(root=resolve(dirname(fileURLToPath(import.meta.url)),'..')){
  const registry=JSON.parse(readFileSync(resolve(root,'resources/locales/manifest.json'),'utf8'));
  const catalogs=new Map();let keys=0;const phpTemplateKeys=new Set(),dynamic=[],phpDynamic=[],hardcodedUiCopy=[];
  const phpTemplateCopy=scanPhpTemplateCopy(root);
  const scripts=readdirSync(resolve(root,'public/assets/js')).filter(file=>file.endsWith('.js')&&file!=='i18n.js'&&!file.startsWith('qrcodegen-')).sort();
  for(const file of scripts){
    const source=readFileSync(resolve(root,'public/assets/js',file),'utf8');
    const tree=parse(source,{ecmaVersion:'latest',sourceType:'module',locations:true});
    const scopes=new WeakMap();
    const rootScope={parent:null,bindings:new Map()};
    const visibleTags=new Set(['p','span','label','button','th','td','legend','option','h1','h2','h3','h4','h5','h6']);
    const recordHardcodedCopy=(raw,line)=>{
      if(typeof raw!=='string')return;
      const copy=raw.replace(/<[^>]*>/g,' ').replace(/&(?:[a-z]+|#\d+|#x[0-9a-f]+);/gi,' ').trim();
      if(/\p{L}/u.test(copy))hardcodedUiCopy.push({file,line,text:copy.slice(0,120)});
    };
    const collectBindings=(node,scope=rootScope,functionScope=rootScope)=>{
      if(!node||typeof node!=='object'||typeof node.type!=='string')return;
      let currentScope=scope,currentFunctionScope=functionScope;
      if(['FunctionDeclaration','FunctionExpression','ArrowFunctionExpression'].includes(node.type)) {
        currentScope={parent:scope,bindings:new Map()};currentFunctionScope=currentScope;
      }else if(node.type==='BlockStatement')currentScope={parent:scope,bindings:new Map()};
      scopes.set(node,currentScope);
      if(node.type==='VariableDeclaration') {
        const target=node.kind==='var'?currentFunctionScope:currentScope;
        for(const declaration of node.declarations)if(declaration.id.type==='Identifier'&&declaration.init)target.bindings.set(declaration.id.name,declaration.init);
      }
      if(node.type==='ForOfStatement'&&node.left.type==='VariableDeclaration') {
        for(const declaration of node.left.declarations)if(declaration.id.type==='Identifier')currentScope.bindings.set(declaration.id.name,node.right);
      }
      for(const value of Object.values(node)){
        if(Array.isArray(value))value.forEach(child=>collectBindings(child,currentScope,currentFunctionScope));
        else if(value&&typeof value==='object')collectBindings(value,currentScope,currentFunctionScope);
      }
    };
    collectBindings(tree);
    const visit=node=>{
      if(!node||typeof node!=='object'||typeof node.type!=='string')return;
      const helper=node.type==='CallExpression'&&node.callee.type==='Identifier'?node.callee.name:null;
      if(helper==='text'||helper==='node'){
        const tag=staticString(node.arguments[0]),copy=staticString(node.arguments[1]);
        if(visibleTags.has(tag))recordHardcodedCopy(copy,node.loc?.start?.line??0);
      }
      if(node.type==='AssignmentExpression'&&node.left.type==='MemberExpression'){
        const property=node.left.computed?staticString(node.left.property):node.left.property?.name;
        if(['textContent','innerText','placeholder','title','innerHTML'].includes(property))recordHardcodedCopy(staticString(node.right),node.loc?.start?.line??0);
      }
      if(node.type==='CallExpression'&&node.callee.type==='MemberExpression'){
        const method=node.callee.computed?staticString(node.callee.property):node.callee.property?.name;
        if(method==='setAttribute'&&['aria-label','title','placeholder'].includes(staticString(node.arguments[0])))recordHardcodedCopy(staticString(node.arguments[1]),node.loc?.start?.line??0);
        if(['append','prepend','before','after','replaceChildren'].includes(method)&&node.arguments.length===1)recordHardcodedCopy(staticString(node.arguments[0]),node.loc?.start?.line??0);
        if(method==='createTextNode')recordHardcodedCopy(staticString(node.arguments[0]),node.loc?.start?.line??0);
        if(method==='insertAdjacentText')recordHardcodedCopy(staticString(node.arguments[1]),node.loc?.start?.line??0);
        if(method==='insertAdjacentHTML')node.arguments.slice(1).forEach(argument=>recordHardcodedCopy(staticString(argument),node.loc?.start?.line??0));
      }
      if(['tr','accountTr','auditTr','billTr','alipayTr','wechatTr'].includes(helper)||(file==='article-editor.js'&&helper==='t')||(file==='account-controls.js'&&helper==='translate')){
        const first=node.arguments[0];
        const namespacePrefix=['support-tickets.js','admin-tickets.js','support-format.js'].includes(file)?'support.':
          file==='delivery-settings.js'?'delivery.':file==='notifications.js'?'notifications.':file==='account-controls.js'?'account_controls.':file==='install.js'?'installation.':file==='content.js'?'content_admin.':['financial-reviews.js','refund-reviews.js','payment-orders.js','refunds.js'].includes(file)?'payments_admin.':file==='international-return.js'?'common.':null;
        const analysis=analyzeKeyExpression(first,scopes.get(node));
        const unresolved=[],checked=[];
        for(const candidate of analysis.candidates){
          const key=namespacePrefix?namespacePrefix+candidate.value:(helper==='t'?'editor.':'')+candidate.value;
          const dot=key.indexOf('.'),namespace=key.slice(0,dot),message=key.slice(dot+1);
          if(!/^[a-z][a-z0-9_-]*$/.test(namespace)||!message)throw new Error('ui_key_invalid: '+file+' '+key);
          if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
          const catalog=catalogs.get(namespace);
          if(candidate.mode==='prefix'){
            const matches=Object.keys(catalog).filter(messageKey=>messageKey.startsWith(message));
            if(!matches.length)throw new Error('ui_key_prefix_missing: '+file+' '+key);
            unresolved.push({prefix:key,matches});
          }else{
            if(!Object.hasOwn(catalog,message))throw new Error('ui_key_missing: '+file+' '+key);
            keys++;checked.push(key);
          }
        }
        if(analysis.dynamic)dynamic.push({file,line:first?.loc?.start?.line??node.loc?.start?.line??0,helper,
          expression:first?source.slice(first.start,first.end).trim():'',candidates:unresolved,checked});
      }
      for(const value of Object.values(node)){
        if(Array.isArray(value))value.forEach(visit);
        else if(value&&typeof value==='object')visit(value);
      }
    };
    visit(tree);
  }
  const phpUiKeys=new Set();
  const phpContracts=[
    {file:'includes/services/Backoffice.php',pattern:/['"](operations[.](?:todos|diagnostics)[.][A-Za-z0-9_.-]+)['"]/g},
    {file:'includes/services/Permissions.php',pattern:/['"](permissions[.](?:roles|modules|grants)[.][A-Za-z0-9_.-]+)['"]/g},
  ];
  for(const contract of phpContracts){
    const source=readFileSync(resolve(root,contract.file),'utf8');
    for(const match of source.matchAll(contract.pattern)){
      const key=match[1],dot=key.indexOf('.'),namespace=key.slice(0,dot),message=key.slice(dot+1);
      if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
      if(!Object.hasOwn(catalogs.get(namespace),message))throw new Error('ui_api_key_missing: '+contract.file+' '+key);
      phpUiKeys.add(key);
    }
  }
  const phpContractKeys=phpUiKeys.size;
  const dynamicApiContracts=[
    {file:'operations.js',expression:'labels',prefixes:['permissions.roles.','permissions.modules.','permissions.grants.']},
    {file:'operations.js',expression:'module.label',prefixes:['permissions.modules.']},
    {file:'operations.js',expression:'note',prefixes:['operations.diagnostics.']},
    {file:'operations.js',expression:'item.label',prefixes:['operations.todos.']},
    {file:'audit.js',expression:"'audit.action_type.' + eventTypeKey[normalized]",keys:[
      'audit.action_type.register','audit.action_type.login','audit.action_type.logout','audit.action_type.purchase',
      'audit.action_type.download','audit.action_type.password_reset','audit.action_type.profile_update','audit.action_type.withdrawal',
    ]},
    {file:'audit.js',expression:"'audit.event_result.' + normalized.toLowerCase()",keys:[
      'audit.event_result.success','audit.event_result.failure','audit.event_result.pending','audit.event_result.blocked',
    ]},
    {file:'audit.js',expression:"'audit.contact.' + normalized.toLowerCase()",keys:[
      'audit.contact.email','audit.contact.phone',
    ]},
    {file:'audit.js',expression:"'audit.boolean.' + (String(value) === '1' ? 'verified' : 'unverified')",keys:[
      'audit.boolean.verified','audit.boolean.unverified',
    ]},
    {file:'operations.js',expression:"'operations.edit_article.translation_status_' + state",keys:[
      'operations.edit_article.translation_status_pending','operations.edit_article.translation_status_running',
      'operations.edit_article.translation_status_retry','operations.edit_article.translation_status_succeeded',
      'operations.edit_article.translation_status_superseded','operations.edit_article.translation_status_failed',
    ]},
    {file:'operations.js',expression:"'operations.status.' + item.state",keys:[
      'operations.status.draft','operations.status.published','operations.status.disabled',
    ]},
    {file:'operations.js',expression:"'locales.coverage.' + item.type",keys:[
      'locales.coverage.articles','locales.coverage.products','locales.coverage.announcements',
      'locales.coverage.categories','locales.coverage.navigation','locales.coverage.site','locales.coverage.policies',
    ]},

  ];
  for(const reference of dynamic){
    if(reference.helper==='d2s_tr')continue;
    const contract=dynamicApiContracts.find(item=>item.file===reference.file&&item.expression===reference.expression);
    if(!contract){
      if(reference.checked.length===0&&reference.candidates.length>0){
        throw new Error('ui_dynamic_contract_missing: '+reference.file+':'+reference.line+' '+reference.expression);
      }
      continue;
    }
    const keys=contract.keys??[...phpUiKeys].filter(key=>contract.prefixes.some(prefix=>key.startsWith(prefix)));
    if(!keys.length)throw new Error('ui_dynamic_api_contract_empty: '+reference.expression);
    for(const key of keys){
      const separator=key.indexOf('.'),namespace=key.slice(0,separator),message=key.slice(separator+1);
      if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
      if(!Object.hasOwn(catalogs.get(namespace),message))throw new Error('ui_dynamic_contract_key_missing: '+reference.file+' '+key);
    }
    reference.checked=keys;
  }
  const accountPagesSource=readFileSync(resolve(root,'public/index.php'),'utf8');
  const accountPagesMatch=accountPagesSource.match(/\$accountPages\s*=\s*\[([\s\S]*?)\];/);
  const accountPageModes=accountPagesMatch?[...accountPagesMatch[1].matchAll(/=>\s*['"]([a-z][a-z0-9_-]*)['"]/g)].map(match=>match[1]):[];
  if(!accountPageModes.length)throw new Error('php_dynamic_contract_source_missing: account page modes');
  const feedbackSource=readFileSync(resolve(root,'template/feedback.php'),'utf8');
  const feedbackCategoryMatch=feedbackSource.match(/\$feedbackCategories\s*=\s*\[([\s\S]*?)\];/);
  const feedbackCategories=feedbackCategoryMatch?[...feedbackCategoryMatch[1].matchAll(/\[\s*['"][A-Z_]+['"]\s*,\s*['"]([^'"]+)['"]\s*,\s*['"]([^'"]+)['"]\s*\]/g)]:[];
  if(!feedbackCategories.length)throw new Error('php_dynamic_contract_source_missing: feedback categories');
  const feedbackTitleSuffixes=feedbackCategories.map(match=>match[1]);
  const feedbackDescriptionSuffixes=feedbackCategories.map(match=>match[2]);
  const phpSources=[...collectPhpFiles(resolve(root,'template')),...collectPhpFiles(resolve(root,'includes')),...collectPhpFiles(resolve(root,'api')),resolve(root,'public/index.php')];
  for(const filePath of phpSources){
    const source=readFileSync(filePath,'utf8');
    const relative=filePath.slice(root.length+1).replaceAll('\\','/');
    const translationMaps={
      'template/alipay-settings.php':['alipay.'],
      'template/wechat-settings.php':['wechat.'],
      'template/operations.php':['operations.'],
    }[relative]??[];
    const verifiedMapArgs={
      'template/alipay-settings.php':new Set(['$label','$text','$fields[$name]']),
      'template/wechat-settings.php':new Set(['$label']),
      'template/operations.php':new Set(['$operationTitles[$operationSection]']),
    }[relative]??new Set();
    const sourceLiterals=phpStringLiterals(source);
    const deliveryTitleSuffixes=[...new Set(sourceLiterals.filter(value=>/^title\.(?:smtp|ses|sms)$/.test(value)))];
    const deliveryDescriptionSuffixes=[...new Set(sourceLiterals.filter(value=>/^description\.(?:smtp|ses|sms)$/.test(value)))];
    const deliveryGroupSuffixes=[...new Set(sourceLiterals.filter(value=>/^group\.(?:smtp|ses|sms)$/.test(value)))];
    const deliveryFieldSuffixes=[...new Set(sourceLiterals.filter(value=>/^field\.[a-z0-9_.-]+$/.test(value)))];
    const phpDynamicArgumentContracts={
      'template/auth.php':{"'accounts.title.'.$pageMode":accountPageModes},
      'template/delivery-settings.php':{
        '$deliveryTitleKey':deliveryTitleSuffixes,'$deliveryDescriptionKey':deliveryDescriptionSuffixes,
        '$title':deliveryGroupSuffixes,'$label':deliveryFieldSuffixes,
      },
      'template/feedback.php':{'$title':feedbackTitleSuffixes,'$description':feedbackDescriptionSuffixes},
      'template/feedback-submit.php':{
        "'feedback.' . $category[1]":feedbackTitleSuffixes,
        "'feedback.' . $category[2]":feedbackDescriptionSuffixes,
      },
    }[relative]??{};
    const wrapperKeysByPrefix=new Map();
    const wrapperPattern=/\$(\w+)\s*=\s*static\s*fn\s*\([^)]*\)[^=]*=>[^;]*?d2s_tr\(\s*(['"])([a-z][a-z0-9_-]*)\.\2\s*\.\s*\$key\b/g;
    for(const wrapper of source.matchAll(wrapperPattern)){
      const wrapperName=wrapper[1],prefix=wrapper[3]+'.',checkedKeys=new Set();
      const wrapperInvocations=[...source.matchAll(/\$([A-Za-z_][A-Za-z0-9_]*)\s*\(/g)].filter(invocation=>invocation[1]===wrapperName);
      for(const invocation of wrapperInvocations){
        const openIndex=invocation.index+invocation[0].lastIndexOf('(');
        const expression=phpFirstArgument(source,openIndex).trim();
        const literalSuffixes=phpStringLiterals(expression).filter(value=>/^[a-z][a-z0-9_-]*(?:\.[a-zA-Z0-9_-]+)*$/.test(value));
        const suffixes=literalSuffixes.length?literalSuffixes:(phpDynamicArgumentContracts[expression]??[]);
        if(!suffixes.length){
          phpDynamic.push({file:relative,line:source.slice(0,invocation.index).split('\n').length,expression:wrapperName+'('+expression+')'});
          continue;
        }
        for(const suffix of suffixes){
          const key=prefix+suffix,separator=key.indexOf('.'),namespace=key.slice(0,separator),message=key.slice(separator+1);
          if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
          if(!Object.hasOwn(catalogs.get(namespace),message))throw new Error('php_ui_wrapper_key_missing: '+relative+' '+key);
          phpTemplateKeys.add(key);checkedKeys.add(key);
        }
      }
      wrapperKeysByPrefix.set(prefix,checkedKeys);
    }
    for(const key of sourceLiterals){
      if(!translationMaps.some(prefix=>key.startsWith(prefix)))continue;
      const separator=key.indexOf('.'),namespace=key.slice(0,separator),message=key.slice(separator+1);
      if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
      if(!Object.hasOwn(catalogs.get(namespace),message))throw new Error('php_ui_map_key_missing: '+relative+' '+key);
      phpTemplateKeys.add(key);
    }
    const callPattern=/\bd2s_tr\s*\(/g;
    for(const call of source.matchAll(callPattern)){
      if(/\bfunction\s+$/.test(source.slice(Math.max(0,call.index-24),call.index)))continue;
      const expression=phpFirstArgument(source,call.index+call[0].lastIndexOf('('));
      const relative=filePath.slice(root.length+1).replaceAll('\\','/');
      const literals=phpStringLiterals(expression);
      const prefixMatch=expression.match(/^\s*(['"])([a-z][a-z0-9_-]*(?:\.[a-zA-Z0-9_-]+)*\.)\1\s*\./);
      let checkedPrefix=false;
      if(prefixMatch){
        const prefix=prefixMatch[2],separator=prefix.indexOf('.'),namespace=prefix.slice(0,separator),messagePrefix=prefix.slice(separator+1);
        if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
        if(!Object.keys(catalogs.get(namespace)).some(key=>key.startsWith(messagePrefix)))throw new Error('php_ui_key_prefix_missing: '+relative+' '+prefix);
        const checkedKeys=new Set(wrapperKeysByPrefix.get(prefix)??[]);
        for(const suffix of phpDynamicArgumentContracts[expression.trim()]??[]){
          const key=prefix+suffix,separator=key.indexOf('.'),keyNamespace=key.slice(0,separator),message=key.slice(separator+1);
          if(!catalogs.has(keyNamespace))catalogs.set(keyNamespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,keyNamespace+'.json'),'utf8')));
          if(!Object.hasOwn(catalogs.get(keyNamespace),message))throw new Error('php_ui_dynamic_key_missing: '+relative+' '+key);
          phpTemplateKeys.add(key);checkedKeys.add(key);
        }
        checkedPrefix=checkedKeys.size>0;
        if(!checkedPrefix)phpDynamic.push({file:relative,line:source.slice(0,call.index).split('\n').length,expression:expression.trim()});
        dynamic.push({file:relative,line:source.slice(0,call.index).split('\n').length,helper:'d2s_tr',checked:[...checkedKeys],candidates:[{prefix,mode:'prefix'}]});
      }
      let matched=0;
      for(const key of literals){
        if(!/^[a-z][a-z0-9_-]*(?:\.[A-Za-z0-9_-]+)+$/.test(key))continue;
        const dot=key.indexOf('.'),namespace=key.slice(0,dot),message=key.slice(dot+1);
        if(!catalogs.has(namespace))catalogs.set(namespace,JSON.parse(readFileSync(resolve(root,'resources/locales',registry.default_locale,namespace+'.json'),'utf8')));
        if(!Object.hasOwn(catalogs.get(namespace),message))throw new Error('php_ui_key_missing: '+relative+' '+key);
        phpUiKeys.add(key);phpTemplateKeys.add(key);matched++;
      }
      if(!matched&&!checkedPrefix&&!verifiedMapArgs.has(expression.trim()))phpDynamic.push({file:relative,line:source.slice(0,call.index).split('\n').length,expression:expression.trim().slice(0,100)});
    }
  }
  return {keys,dynamic,phpKeys:phpContractKeys,phpTemplateKeys:phpTemplateKeys.size,phpDynamic,hardcodedUiCopy,phpTemplateCopy};
}
if(process.argv[1]&&resolve(process.argv[1])===fileURLToPath(import.meta.url)){
  const result=checkUiKeys();
  console.log('PASS migrated UI key references: '+JSON.stringify({keys:result.keys,dynamic:result.dynamic.length}));
  if(result.dynamic.length)console.log('Dynamic references: '+JSON.stringify(result.dynamic));
}
