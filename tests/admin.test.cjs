const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {parseHTML} = require('linkedom');
const source = fs.readFileSync(require('node:path').join(__dirname,'../assets/admin.js'),'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve,15));
const makeSession = () => ({
    id:'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',revision:1,status:'analyzed',url:'https://mailchi.mp/fixture',duplicates:[],
    analysis:{title:'Newsletter',confidence:'Ready for review',fingerprint:'test',story_boundaries:1,warnings:[],images:[],
        sections:[{id:'s1',title:'Story One',position:1,blocks:['b1','b2']},{id:'s2',title:'Story Two',position:2,blocks:['b3']}],
        blocks:[{id:'b1',section:'s1',position:1,type:'heading',text:'Story One',html:'<h1>Story One</h1>',images:[],warnings:[]},{id:'b2',section:'s1',position:2,type:'paragraph',text:'Editorial text',html:'<p>Editorial text</p>',images:[],warnings:[]},{id:'b3',section:'s2',position:3,type:'paragraph',text:'Second story',html:'<p>Second story</p>',images:[],warnings:[]}]},
    plan:{mode:'single',single:{id:'single',title:'Newsletter',slug:'',excerpt:'',category:1,author:1,date:'',featured:''},drafts:[{id:'d1',title:'Story One',category:1,author:1,slug:'',excerpt:'',date:'',featured:''},{id:'d2',title:'Story Two',category:1,author:1,slug:'',excerpt:'',date:'',featured:''}],selection:{b1:true,b2:true,b3:true},assignments:{b1:'d1',b2:'d1',b3:'d2'},order:['b1','b2','b3'],section_titles:{s1:'Story One',s2:'Story Two'},acknowledged:[]}
});
async function setup(options = {}) {
    const {window} = parseHTML('<html><body><div id="letterlift"></div></body></html>');
    window.location = {href:'http://wordpress.test/wp-admin/tools.php?page=letterlift'};
    let stored = makeSession(); const requests = [], errors = [];
    const fetch = async (endpoint, args) => {
        const u = new URL(endpoint), route = u.searchParams.get('rest_route') || u.pathname;
        const body = args.body ? JSON.parse(args.body) : null; requests.push({u,route,body});
        let data = [];
        if (route.endsWith('/history')) data = [];
        if (route.endsWith('/analyze')) data = structuredClone(stored);
        if (route.includes('/sessions/')) {
            if (body?.plan) { stored.plan = body.plan; stored.revision++; }
            if (route.endsWith('/source')) data = {source:'<html><script>untrusted()</script>Source</html>'};
            else if (route.endsWith('/preview')) {
                const destinations=stored.plan.mode==='single'?[stored.plan.single]:stored.plan.drafts;
                data={revision:stored.revision,serializer_version:'gutenberg-v2',drafts:Object.fromEntries(destinations.map(d => [d.id,{html:options.previewHTML || `<p>Server serializer preview for ${d.id}</p>`,content:'<!-- wp:paragraph --><p>Server preview</p><!-- /wp:paragraph -->',warnings:options.warnings || []}]))};
            } else data = structuredClone(stored);
        }
        return {ok:true,json:async () => data};
    };
    const context = vm.createContext({window,document:window.document,DOMParser:window.DOMParser,URL,URLSearchParams,fetch,setTimeout,clearTimeout,console,LetterliftConfig:{root:'http://wordpress.test/?rest_route=/letterlift/v1/',nonce:'nonce',categories:[{id:1,name:'News'}],authors:[{id:1,name:'Admin'}],timezone:'UTC'}});
    window.LetterliftConfig = context.LetterliftConfig;
    vm.runInContext(source,context); await tick();
    const document = window.document;
    const click = async selector => {const el = document.querySelector(selector); assert.ok(el,`Missing ${selector}`); el.dispatchEvent(new window.Event('click',{bubbles:true})); await tick();};
    const change = async (selector,value) => {const el = document.querySelector(selector); assert.ok(el,`Missing ${selector}`); if (el.type === 'checkbox') el.checked = value; else Object.defineProperty(el,'value',{value,configurable:true}); el.dispatchEvent(new window.Event('change',{bubbles:true})); await tick();};
    const analyze = async () => {document.querySelector('#ll-url').value='https://mailchi.mp/fixture';document.querySelector('#ll-analyze').dispatchEvent(new window.Event('submit',{bubbles:true,cancelable:true}));await tick();};
    return {document,window,requests,click,change,analyze,getStored:() => stored};
}
test('analysis and mixed section selection, saving, and plain-permalink REST URLs',async () => {
    const h=await setup();await h.analyze();
    assert.match(h.document.body.textContent,/Make this newsletter yours/);
    await h.click('[data-action="expand"][data-id="s1"]');
    await h.change('[data-field="block-select"][data-id="b2"]',false);
    assert.equal(h.document.querySelector('[data-section-check="s1"]').indeterminate,true);
    assert.match(h.document.querySelector('#ll-section-s1').textContent,/Partially selected/);
    await h.click('[data-action="save"]');
    assert.equal(h.getStored().plan.selection.b2,false);
    assert.ok(h.requests.every(r => !r.u.searchParams.get('rest_route').includes('?')));
});
test('mode changes preserve assignments; removing drafts retains selected unassigned content',async () => {
    const h=await setup();await h.analyze();
    await h.change('[data-field="mode"][value="multiple"]','multiple');
    await h.change('[data-field="section-draft"][data-id="s1"]','d2');
    await h.change('[data-field="mode"][value="single"]','single');
    await h.change('[data-field="mode"][value="multiple"]','multiple');
    await h.click('[data-action="save"]');assert.equal(h.getStored().plan.assignments.b1,'d2');
    await h.click('[data-action="remove-draft"][data-id="d2"]');await h.click('[data-action="save"]');
    assert.equal(h.getStored().plan.assignments.b1,'');assert.equal(h.getStored().plan.selection.b1,true);
    assert.match(h.document.body.textContent,/3 unassigned/);
});
test('section ordering, block ordering, and preview agree',async () => {
    const h=await setup();await h.analyze();
    await h.click('[data-action="section-down"][data-id="s1"]');await h.click('[data-action="save"]');
    assert.deepEqual(h.getStored().plan.order,['b3','b1','b2']);
    assert.equal(h.document.querySelector('.ll-section').id,'ll-section-s2');
    await h.click('[data-action="preview"]');assert.match(h.document.body.textContent,/Everything look good/);
    const frame=h.document.querySelector('iframe');assert.match(frame.getAttribute('srcdoc'),/Server serializer preview/);assert.doesNotMatch(frame.getAttribute('srcdoc'),/First source block/);assert.equal(frame.getAttribute('sandbox'),'');assert.match(frame.getAttribute('srcdoc'),/default-src &#39;none&#39;|default-src 'none'/);
});
test('saved original source is displayed as text, not executable markup',async () => {
    const h=await setup();await h.analyze();await h.click('[data-action="source"]');
    assert.match(h.document.querySelector('#ll-source').textContent,/<script>untrusted/);
    assert.equal(h.document.querySelector('#ll-source script'),null);
    assert.equal(h.requests.filter(r => r.route.endsWith('/source')).length,1);
});
test('preview displays server serialization warnings and never uses source HTML as an alternate renderer',async () => {
    const h=await setup({previewHTML:'<h3>Canonical server output</h3><p>Only the server serializer supplied this preview.</p>',warnings:[{block_id:'b2',message:'This complex region is preserved as Custom HTML.'}]});await h.analyze();
    assert.match(h.document.querySelector('iframe').getAttribute('srcdoc'),/Canonical server output/);
    assert.doesNotMatch(h.document.querySelector('iframe').getAttribute('srcdoc'),/<h1>Story One/);
    assert.match(h.document.querySelector('[data-preview-panel]').textContent,/preserved as Custom HTML/);
    assert.ok(h.requests.some(r => r.route.endsWith('/preview')));
});
