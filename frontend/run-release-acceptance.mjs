import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const php=process.env.PHP_EXE||process.env.PHP_BINARY||'php';
const supplied=process.argv.slice(2);
const args=supplied.length>0?supplied:['--profile=quick','--only=docs-links,release-manifest'];
const result=spawnSync(php,[path.join(root,'bin','run-release-acceptance.php'),...args],{
  cwd:root,env:process.env,stdio:'inherit',
});
if(result.error){
  console.error(result.error.message);
  process.exit(1);
}
process.exit(result.status??1);
