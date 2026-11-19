import {parse} from 'acorn';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const file=process.argv[2];
if(!file)throw new Error('source_path_required');
const source=readFileSync(resolve(file),'utf8');
const tree=parse(source,{ecmaVersion:'latest',sourceType:'module'});
const calls=[],unsupported=[];
function visit(node,ancestors=[]){
  if(!node||typeof node!=='object'||typeof node.type!=='string')return;
  if(node.type==='CallExpression'&&node.callee.type==='Identifier'&&node.callee.name==='t'){
    const scope=[...ancestors].reverse().find(parent=>/Function/.test(parent.type)&&parent.id)?.id.name||'module';
    const property=[...ancestors].reverse().find(parent=>parent.type==='Property')?.key;
    const hint=property?.name||property?.value||'';
    if(node.arguments.length===2&&node.arguments.every(argument=>argument.type==='Literal'&&typeof argument.value==='string')){
      calls.push({start:node.start,end:node.end,zh:node.arguments[0].value,en:node.arguments[1].value,scope,hint});
    }else unsupported.push({start:node.start,end:node.end,scope});
  }
  for(const value of Object.values(node)){
    if(Array.isArray(value))for(const child of value)visit(child,[...ancestors,node]);
    else if(value&&typeof value==='object')visit(value,[...ancestors,node]);
  }
}
visit(tree);
console.log(JSON.stringify({file,calls,unsupported}));
