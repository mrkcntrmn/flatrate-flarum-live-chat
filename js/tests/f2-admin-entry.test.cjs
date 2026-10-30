const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'../..');
const bundle=fs.readFileSync(path.join(root,'js/dist/admin-f2.js'),'utf8');
const backend=fs.readFileSync(path.join(root,'extend-f2.php'),'utf8');
assert.match(backend,/Frontend\('admin'\)[\s\S]*?js\/dist\/admin-f2\.js[\s\S]*?resources\/less\/admin\.less/);
for(const flag of ['0','1']){
 const settings=[],permissions=[],pages=[],initializers=[];
 const registry={for(id){assert.equal(id,'flatrate-live-chat');return this;},registerSetting(s){settings.push(s);return this;},registerPermission(p){permissions.push(p);return this;},registerPage(p){pages.push(p);return this;}};
 const app={registry,data:{settings:{'flatrate-live-chat.admin_dashboard_enabled':flag}},translator:{trans:k=>k},initializers:{add(id,fn){assert.equal(id,'flatrate-live-chat');initializers.push(fn);}}};
 const core={'admin/app':app,'common/Component':class Component{},'common/components/Button':class Button{},'admin/components/ExtensionPage':class ExtensionPage{}};
 const imports=[];const module={exports:{}};
 vm.runInNewContext(bundle,{module,flarum:{reg:{get(namespace,id){assert.equal(namespace,'core');assert.ok(Object.hasOwn(core,id),'Unmapped F2 admin import: '+id);imports.push(id);return core[id];},add(){}}},m(){},window:{}});
 assert.equal(initializers.length,1);initializers[0]();assert.equal(settings.length,11);assert.ok(permissions.length>0);assert.equal(pages.length,flag==='1'?1:0);assert.ok(imports.includes('admin/app'));assert.ok(!imports.includes('forum/app'));
}
console.log('COMPILED_F2_ADMIN_ENTRY_AND_SERVER_REGISTRATION=PASS');
