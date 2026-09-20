const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname,'../../src/CatalogStorefrontAdminhtml/view/adminhtml/web/js/form/resource-fieldset.js'),'utf8');
function observable(value) {
    const listeners = [];
    const result = function(next) {if (!arguments.length) return value; if(next===value)return; value=next; listeners.forEach(fn=>fn(next));};
    result.subscribe = fn=>listeners.push(fn);return result;
}
function form(kind, values, id=0, editable=true) {
    let definition;const requests=[];
    const jquery={getJSON(url,parameters){const request={parameters,done(fn){this.success=fn;return this;},fail(fn){this.failure=fn;return this;},abort(){this.aborted=true;this.failure?.({},'abort');}};requests.push(request);return request;}};
    const fields={};
    const names=['type','enabled','name','code','source_id','stock_id','protection','book_mode','book_ids','book_id','layer_ids','policy_ids','native_store_id','parent_id','attribute','option_values','values','trigger','value_source','operator'];
    names.forEach(name=>fields[name]={value:observable(values[name]??''),visible:observable(true),disabled:observable(false),error:observable(false),setOptions(options){this.options=options;}});
    const registry={get(names,callback){callback(...names.map(name=>fields[name.split('.').at(-1)]));}};
    vm.runInNewContext(script,{define(deps,factory){definition=factory({extend:value=>value},registry,jquery,value=>value);}});
    const instance=Object.assign({name:'form.general',fieldNames:names,resourceKind:kind,resourceId:id,editable,metadataUrl:'/metadata',_super(){}},definition);
    instance.initialize();return {instance,fields,requests};
}
test('managed View exposes only Store View and freezes saved type',()=>{
    const {fields:f}=form('views',{type:'platform_store_view',book_mode:'selected'},7);
    assert.equal(f.type.disabled(),true);assert.equal(f.native_store_id.visible(),true);
    ['name','code','source_id','stock_id','protection','book_mode','book_ids','book_id','layer_ids','policy_ids','enabled'].forEach(name=>assert.equal(f[name].visible(),false,name));
});
test('private View keeps selected Books and permits a single Book',()=>{
    const {fields:f}=form('views',{type:'generic',protection:'public',book_mode:'all'});
    assert.equal(f.book_id.visible(),false);assert.equal(f.book_ids.visible(),false);
    f.book_mode.value('selected');assert.equal(f.book_ids.visible(),true);assert.equal(f.book_id.visible(),false);
    f.book_ids.value(['2','3']);
    f.protection.value('private');assert.equal(f.book_mode.value(),'selected');assert.equal(f.book_mode.disabled(),false);
    assert.deepEqual(f.book_ids.value(),['2','3']);assert.equal(f.book_ids.visible(),true);
    f.book_mode.value('single');
    assert.equal(f.book_id.visible(),true);assert.equal(f.book_ids.visible(),false);
    f.protection.value('public');assert.equal(f.book_mode.disabled(),false);
});
test('private View requires an explicit Book selection',()=>{
    const {fields:f}=form('views',{type:'generic',protection:'public',book_mode:'all'});
    f.protection.value('private');assert.equal(f.book_mode.value(),'selected');assert.equal(f.book_ids.visible(),true);
    f.book_mode.value('all');assert.equal(f.book_mode.value(),'selected');
    f.protection.value('public');f.book_mode.value('all');assert.equal(f.book_mode.value(),'all');
    assert.equal(f.book_ids.visible(),false);assert.equal(f.book_id.visible(),false);
});
test('read-only protection cannot unlock editing',()=>{
    const {fields:f}=form('views',{type:'generic',protection:'private',book_mode:'selected'},0,false);
    assert.equal(f.type.disabled(),true);assert.equal(f.book_mode.disabled(),true);
    f.protection.value('public');assert.equal(f.book_mode.disabled(),true);
});
const metadata=label=>({available:true,attributes:[{value:'color',label,options:[{value:'49',label}],numeric:true}]});
test('Source changes clear values and ignore old cloud responses',()=>{
    const {fields:f,requests}=form('policies',{type:'generic',source_id:'1',attribute:'color',value_source:'static',option_values:['49'],values:'49'});
    requests[0].success(metadata('Old cloud'));assert.equal(f.option_values.options[0].label,'Old cloud');
    assert.equal(f.option_values.visible(),true);assert.equal(f.values.visible(),false);
    f.source_id.value('2');assert.equal(requests[0].aborted,true);assert.equal(f.attribute.value(),'');assert.equal(f.option_values.value().length,0);assert.equal(f.values.value(),'');
    requests[0].success(metadata('Stale cloud'));assert.equal(f.attribute.disabled(),true);
    requests[1].success(metadata('New cloud'));f.attribute.value('color');assert.equal(f.option_values.options[0].label,'New cloud');
    f.value_source.value('trigger');assert.equal(f.trigger.visible(),true);assert.equal(f.option_values.visible(),false);
});
test('empty cloud metadata disables picker with an explicit error',()=>{
    const {fields:f,requests}=form('policies',{type:'generic',source_id:'1'});
    requests[0].success({available:false,attributes:[]});assert.equal(f.attribute.disabled(),true);assert.match(f.attribute.error(),/no imported/);
});
test('cloud failure stays unavailable and form destroy aborts request',()=>{
    const {fields:f,requests,instance}=form('policies',{type:'generic',source_id:'1'});
    requests[0].failure({},'error');assert.equal(f.attribute.disabled(),true);assert.match(f.attribute.error(),/unavailable/);
    instance.destroy();assert.equal(requests[0].aborted,true);
});
