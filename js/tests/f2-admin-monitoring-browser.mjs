import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdirSync,rmSync} from 'node:fs';
import {createRequire} from 'node:module';
import {resolve} from 'node:path';
const {chromium,expect}=createRequire(process.env.PLAYWRIGHT_PACKAGE_JSON)('@playwright/test');
const fixture=JSON.parse(readFileSync(process.env.F2_AUTH_FILE,'utf8')),base=fixture.baseUrl,out=process.env.F2_EVIDENCE_DIR;
assert.equal(new URL(base).hostname,'127.0.0.1','Synthetic loopback fixture only');mkdirSync(out,{recursive:true});
const failureFlag=process.env.F2_PRESENCE_FAILURE_FLAG;assert.ok(failureFlag?.startsWith('/tmp/fr-f2-admin-monitoring-')&&failureFlag.endsWith('/private/presence-fail'));
const browser=await chromium.launch({headless:true}),contexts=[],results=[],errors=[];
const endpoint='/api/flatrate-live-chat/admin/dashboard';
async function actor(role,width=1440,height=1000){const c=await browser.newContext({viewport:{width,height}});contexts.push(c);if(role)await c.addCookies([{name:'flarum_remember',value:fixture.users[role].token,domain:'127.0.0.1',path:'/'}]);const p=await c.newPage();p.on('pageerror',e=>errors.push({role,error:e.message}));return p;}
async function record(name,fn){await fn();results.push({name,status:'PASS'});writeFileSync(resolve(out,'results.json'),JSON.stringify({results,errors},null,2));console.log('PASS '+name);}
async function noOverflow(p){const v=await p.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth}));assert.ok(v.scrollWidth<=v.width+1,JSON.stringify(v));}
const card=(p,label)=>p.locator('.FlatRateLiveAdminCard').filter({has:p.locator('span').filter({hasText:new RegExp('^'+label+'$')})});
const refresh=p=>p.getByRole('button',{name:'Refresh',exact:true});
const dashboard=p=>p.locator('.FlatRateLiveAdminDashboard');
async function api(p,query=''){const r=await p.request.get(base+endpoint+query);assert.equal(r.status(),200);return r.json();}
try{
 const p=await actor('admin');await p.clock.install();const requests=[];p.on('request',r=>{if(new URL(r.url()).pathname===endpoint)requests.push({method:r.method(),roomKey:new URL(r.url()).searchParams.get('roomKey')});});
 await record('compiled F2 admin registry boots dashboard with real aggregate counts and no private content',async()=>{
  const r=await p.goto(base+'/admin#/extension/flatrate-live-chat',{waitUntil:'networkidle'});assert.equal(r.status(),200);await expect(dashboard(p).getByRole('heading',{name:'Live Chat Rooms',exact:true})).toBeVisible();
  await expect(card(p,'Expected rooms').locator('strong')).toHaveText('46');await expect(card(p,'Present rooms').locator('strong')).toHaveText('46');await expect(card(p,'Messages · 24h').locator('strong')).toHaveText('3');await expect(card(p,'System events · 24h').locator('strong')).toHaveText('1');
  await expect(dashboard(p)).toContainText('Catalog reconciled');await expect(dashboard(p)).toContainText('not instrumented');
  const data=await api(p);assert.equal(data.rooms.length,46);assert.equal(data.totals.messages24h,3);assert.equal(data.totals.eventMessages24h,1);assert.equal(data.health.presenceMode,'selected-room-on-demand');assert.ok(data.rooms.every(r=>r.presence===null));
  const raw=JSON.stringify(data);assert.ok(!raw.includes('SYNTHETIC_PRIVATE_BODY'));assert.doesNotMatch(raw,/"(?:message|content|username|email|userId|token|participants|clients)"\s*:/);await noOverflow(p);await p.screenshot({path:resolve(out,'desktop-overview.png'),fullPage:true});
 });
 await record('guest ordinary beta and non-admin moderator cannot load admin page or monitoring API',async()=>{
  for(const role of [null,'peer','member','outsider']){const other=await actor(role);const headers=role?{Authorization:'Token '+fixture.users[role].token}:{};const r=await other.request.get(base+endpoint,{headers});assert.equal(r.status(),403);const page=await other.goto(base+'/admin#/extension/flatrate-live-chat',{waitUntil:'domcontentloaded'});assert.equal(page.status(),403);await expect(dashboard(other)).toHaveCount(0);}
 });
 await record('room directory searches sorts and handles an empty result without page overflow',async()=>{
  await p.getByRole('tab',{name:'All Rooms',exact:true}).click();await expect(p.locator('.FlatRateLiveAdminTable tbody tr')).toHaveCount(46);await p.getByRole('searchbox',{name:'Search rooms'}).fill('FORD');await expect(p.locator('.FlatRateLiveAdminTable tbody tr')).toHaveCount(1);await expect(p.locator('.FlatRateLiveAdminTable tbody tr')).toContainText('Ford Live');
  await p.getByRole('searchbox',{name:'Search rooms'}).fill('no-such-synthetic-room');await expect(dashboard(p)).toContainText('No rooms match this search.');await p.getByRole('searchbox',{name:'Search rooms'}).fill('');await p.getByRole('combobox',{name:'Sort rooms'}).selectOption('messages24h');await expect(p.locator('.FlatRateLiveAdminTable tbody tr').first()).toContainText('Toyota Live');await noOverflow(p);await p.screenshot({path:resolve(out,'desktop-rooms.png'),fullPage:true});
 });
 await record('selected Ford detail samples real zero presence and links to the canonical Messages route',async()=>{
  await p.getByRole('searchbox',{name:'Search rooms'}).fill('ford');await expect(p.locator('.FlatRateLiveAdminTable tbody tr')).toHaveCount(1);await p.getByRole('button',{name:'Inspect',exact:true}).click();await expect(dashboard(p).getByRole('heading',{name:'Ford Live',exact:true})).toBeVisible();await expect(card(p,'Live users').locator('strong')).toHaveText('0');await expect(card(p,'Live users')).toContainText('Sampled');await expect(p.getByRole('link',{name:'Open room',exact:true})).toHaveAttribute('href',base+'/messages/live/ford-live');
  const data=await api(p,'?roomKey=ford-live');assert.equal(data.rooms.filter(r=>r.presence!==null).length,1);const ford=data.rooms.find(r=>r.roomKey==='ford-live');assert.equal(ford.presence.available,true);assert.equal(ford.presence.uniqueUsers,0);await p.screenshot({path:resolve(out,'desktop-room-zero.png'),fullPage:true});
 });
 await record('real authorized WebSocket participant changes selected-room count without exposing identity',async()=>{
  const live=await actor('member');await live.goto(base+'/messages/live/ford-live',{waitUntil:'networkidle'});await expect(live.locator('.ChatInput textarea')).toBeVisible();await expect.poll(async()=>{const d=await api(p,'?roomKey=ford-live');return d.rooms.find(r=>r.roomKey==='ford-live').presence.uniqueUsers;},{timeout:15000}).toBe(1);
  await refresh(p).click();await expect(card(p,'Live users').locator('strong')).toHaveText('1');await expect(dashboard(p)).not.toContainText('tech_qual42');await live.close();await expect.poll(async()=>{const d=await api(p,'?roomKey=ford-live');return d.rooms.find(r=>r.roomKey==='ford-live').presence.uniqueUsers;},{timeout:15000}).toBe(0);
 });
 await record('provider failure is unavailable rather than a false zero and recovers',async()=>{
  writeFileSync(failureFlag,'local-test-only');try{await refresh(p).click();await expect(card(p,'Live users').locator('strong')).toHaveText('—');await expect(card(p,'Live users')).toContainText('Provider unavailable');const d=await api(p,'?roomKey=ford-live');assert.equal(d.rooms.find(r=>r.roomKey==='ford-live').presence.available,false);}finally{rmSync(failureFlag,{force:true});}
  await refresh(p).click();await expect(card(p,'Live users').locator('strong')).toHaveText('0');
 });
 await record('automatic refresh and failed-request backoff recover with the last snapshot intact',async()=>{
  let count=requests.length;await p.clock.runFor(60050);await expect.poll(()=>requests.length).toBeGreaterThan(count);await expect(refresh(p)).toBeEnabled();
  await p.route('**'+endpoint+'*',route=>route.fulfill({status:503,contentType:'application/json',body:'{"errors":[{"code":"synthetic_failure"}]}'}),{times:1});await refresh(p).click();await expect(dashboard(p)).toContainText('Live Chat monitoring data could not be loaded.');await expect(dashboard(p).getByRole('heading',{name:'Ford Live'})).toBeVisible();count=requests.length;await p.clock.runFor(120050);await expect.poll(()=>requests.length).toBeGreaterThan(count);await expect(dashboard(p)).not.toContainText('Live Chat monitoring data could not be loaded.');await expect(card(p,'Live users').locator('strong')).toHaveText('0');
 });
 await record('unmount stops monitoring polling even when its final request resolves late',async()=>{
  let release,seen;const started=new Promise(r=>seen=r);const held=new Promise(r=>release=r);await p.route('**'+endpoint+'*',async route=>{seen();await held;await route.continue();},{times:1});await refresh(p).click();await started;await p.goto(base+'/admin#/');await expect(dashboard(p)).toHaveCount(0);release();await p.waitForLoadState('networkidle');const count=requests.length;await p.clock.runFor(180050);assert.equal(requests.length,count,'No dashboard polling after unmount');
 });
 await record('mobile portrait and landscape preserve readable cards controls and a contained table',async()=>{
  const mobile=await actor('admin',392,800);await mobile.goto(base+'/admin#/extension/flatrate-live-chat',{waitUntil:'networkidle'});await expect(dashboard(mobile)).toBeVisible();await noOverflow(mobile);await mobile.screenshot({path:resolve(out,'mobile-overview.png'),fullPage:true});await mobile.getByRole('tab',{name:'All Rooms',exact:true}).click();await mobile.getByRole('searchbox',{name:'Search rooms'}).fill('ford');await expect(mobile.locator('.FlatRateLiveAdminTable tbody tr')).toHaveCount(1);await noOverflow(mobile);await mobile.locator('.FlatRateLiveAdminTableWrap').scrollIntoViewIfNeeded();await expect(mobile.getByRole('button',{name:'Inspect',exact:true})).toBeInViewport();const touch=await mobile.getByRole('button',{name:'Inspect',exact:true}).boundingBox();assert.ok(touch.height>=44);await mobile.getByRole('button',{name:'Inspect',exact:true}).click();await expect(card(mobile,'Live users').locator('strong')).toHaveText('0');await mobile.screenshot({path:resolve(out,'mobile-room-detail.png'),fullPage:true});await mobile.setViewportSize({width:801,height:392});await noOverflow(mobile);await expect(refresh(mobile)).toBeVisible();await mobile.screenshot({path:resolve(out,'landscape-room-detail.png'),fullPage:true});
 });
 await record('monitoring uses only GET requests and causes no browser exceptions',async()=>{assert.ok(requests.every(r=>r.method==='GET'));assert.deepEqual(errors,[]);});
 console.log('F2_ADMIN_MONITORING_BROWSER=PASS scenarios='+results.length);
}finally{rmSync(failureFlag,{force:true});for(const c of contexts)await c.close();await browser.close();writeFileSync(resolve(out,'results.json'),JSON.stringify({results,errors},null,2));}
