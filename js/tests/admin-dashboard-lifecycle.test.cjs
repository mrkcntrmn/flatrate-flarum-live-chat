const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { transformSync } = require('@babel/core');
function load(source, app, timers) {
  const code = transformSync(source, {configFile:false,babelrc:false,plugins:[['@babel/plugin-transform-react-jsx',{pragma:'m'}],'@babel/plugin-transform-modules-commonjs']}).code;
  class Component { oninit(vnode) { this.attrs=vnode.attrs || {}; } }
  const module={exports:{}};
  vm.runInNewContext(code,{module,exports:module.exports,require:name=>({__esModule:true,default:name==='flarum/app'?app:Component}),m:{redraw(){}},window:{setTimeout:(fn,delay)=>{const id=timers.size+1;timers.set(id,{fn,delay});return id;},clearTimeout:id=>timers.delete(id)},Promise,Set,Date,Number,String,encodeURIComponent});
  return module.exports.default;
}
const source=fs.readFileSync(path.resolve(__dirname,'../src/admin/components/LiveChatRoomsDashboard.js'),'utf8');
function fixture() {
  const pending=[],timers=new Map(),app={forum:{attribute:()=>'/api'},request:options=>new Promise((resolve,reject)=>pending.push({options,resolve,reject}))};
  const Dashboard=load(source,app,timers),page=new Dashboard();page.oninit({attrs:{}});return{page,pending,timers};
}
(async()=>{
  {
    const{page,pending,timers}=fixture();
    const first=page.load('ford-live'),second=page.load('toyota-live');
    pending[1].resolve({room:'toyota-live'});await second;
    pending[0].resolve({room:'ford-live'});await first;
    assert.equal(page.data.room,'toyota-live','Late previous-room response must not replace selected-room presence');
    assert.equal(timers.size,1);assert.equal([...timers.values()][0].delay,60000);
  }
  for(const reject of [false,true]) {
    const{page,pending,timers}=fixture();const request=page.load('ford-live');page.onremove();
    reject?pending[0].reject(Error('offline')):pending[0].resolve({room:'ford-live'});await request;
    assert.equal(timers.size,0,'Leaving the page must not restart background polling');assert.equal(page.data,null);assert.equal(page.error,'');
  }
  {
    const{page,pending,timers}=fixture();let request=page.load();pending[0].resolve({ok:true});await request;
    request=page.load();assert.equal(timers.size,0,'Manual refresh cancels the preceding scheduled refresh');pending[1].reject(Error('offline'));await request;
    assert.ok(page.error);assert.equal([...timers.values()][0].delay,120000);assert.equal(page.data.ok,true,'Failure preserves the timestamped last snapshot');
    request=page.load();pending[2].resolve({ok:'recovered'});await request;assert.equal(page.error,'');assert.equal(page.data.ok,'recovered');assert.equal([...timers.values()][0].delay,60000);
  }
  {
    const source=fs.readFileSync(path.resolve(__dirname,'../src/admin/registerLiveAdmin.js'),'utf8');
    for(const [flag,expected] of [[undefined,false],['0',false],['false',false],['1',true],[true,true]]) {
      const settings=[],permissions=[],pages=[],registry={for(id){assert.equal(id,'flatrate-live-chat');return this;},registerSetting(v){settings.push(v);return this;},registerPermission(v){permissions.push(v);return this;},registerPage(v){pages.push(v);return this;}};
      const app={data:{settings:{'flatrate-live-chat.admin_dashboard_enabled':flag}},translator:{trans:x=>x}};
      const register=load(source,app,new Map());class Page{}register(app,registry,Page);
      assert.equal(settings.length,11);assert.ok(permissions.length>0);assert.equal(pages.length,expected?1:0);
      assert.equal(settings.find(v=>v.setting==='flatrate-live-chat.admin_dashboard_enabled').default,false);
    }
  }
  console.log('ADMIN_DASHBOARD_LIFECYCLE_AND_REGISTRY=PASS');
})().catch(error=>{console.error(error);process.exitCode=1;});
