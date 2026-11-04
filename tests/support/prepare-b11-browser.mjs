// Additive copies of the retained harness: never write the original acceptance results.
// Run with the dedicated UAT root, a new artifact directory, and optionally a new origin.
import fs from 'node:fs';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

const [rootArg, outputArg, origin = 'http://127.0.0.1:18082'] = process.argv.slice(2);
const root = fs.realpathSync(rootArg).replaceAll('\\', '/');
const output = path.resolve(outputArg).replaceAll('\\', '/');
if (root !== 'C:/laragon/uat/portax-3c2-36fd0d29' || !output.startsWith(root + '/b11-')) throw Error('Unexpected UAT artifact boundary');
if (fs.existsSync(output)) throw Error('Refusing to overwrite B11 evidence');
fs.mkdirSync(output, {recursive: true});
for (const name of ['harness.mjs', 'run.mjs']) {
  let source = fs.readFileSync(root + '/automation/' + name, 'utf8');
  source = 'const evidenceRoot = ' + JSON.stringify(output) + ';\n' + source;
  source = source.replaceAll("root+'/evidence/", "evidenceRoot+'/");
  source = source.replace("export const results=fs.existsSync(evidenceRoot+'/browser-results.json')?JSON.parse(fs.readFileSync(evidenceRoot+'/browser-results.json')):{};", 'export const results={};');
  source = source.replace("root+'/browser/acceptance-'", "evidenceRoot+'/profile-'");
  source = source.replace("export const origin='http://127.0.0.1:18082';", 'export const origin=' + JSON.stringify(origin) + ';');
  // Successful cross-tab revalidation can render B in both the header and body.
  source = source.replaceAll("getByText('Synthetic UAT 3C2 foreign',{exact:true}).waitFor()", "getByText('Synthetic UAT 3C2 foreign',{exact:true}).first().waitFor()");
  if (origin === 'http://127.0.0.1:18083') {
    source = source.replace("assert.ok(proof.afterIdentityB,'B server identity must persist');", "assert.ok(proof.afterIdentityB,'B server identity must persist');assert.equal(proof.delayedSetCookie,false,'Identity probe must not emit cookies');assert.equal(proof.sessionCookieChangedOnRelease,false,'Delayed identity response must not replace B cookie');for(let i=0;i<2;i++){const next=await raw('/api/me');assert.equal(next.status,200);assert.equal(next.json.data.id,fixture.actors.foreign.id);}");
  }
  // Dependencies remain the retained, pinned Playwright installation.
  source = source.replace("from 'playwright'", "from " + JSON.stringify(pathToFileURL(root + '/automation/node_modules/playwright/index.mjs').href));
  fs.writeFileSync(output + '/' + name, source);
}
console.log(output);
