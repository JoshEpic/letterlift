/**
 * Executes the installed WordPress core Gutenberg parser and save functions in jsdom.
 * This catches invalid block markup. It is NOT evidence of manual browser/editor acceptance.
 * Export manifests with tests/export-gutenberg-runtime.php and cases with tests/serializer.php.
 */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM, VirtualConsole} = require('jsdom');
const manifestPath = process.env.LETTERLIFT_GUTENBERG_RUNTIME;
const casesPath = process.env.LETTERLIFT_GUTENBERG_CASES;

test('generated native blocks validate against official WordPress save functions', {skip:!manifestPath || !casesPath}, async () => {
    const manifest = JSON.parse(fs.readFileSync(manifestPath,'utf8'));
    const cases = JSON.parse(fs.readFileSync(casesPath,'utf8')).cases;
    const virtualConsole = new VirtualConsole();
    const diagnostics = [];
    virtualConsole.on('error', (...args) => diagnostics.push(args.join(' ')));
    const dom = new JSDOM('<!doctype html><html><body></body></html>', {url:'http://letterlift-runtime.test',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole});
    const w = dom.window;
    w.TextEncoder = TextEncoder; w.TextDecoder = TextDecoder;
    w.matchMedia = () => ({matches:false,addListener(){},removeListener(){},addEventListener(){},removeEventListener(){}});
    w.ResizeObserver = class {observe(){} unobserve(){} disconnect(){}};
    w.IntersectionObserver = class {observe(){} unobserve(){} disconnect(){}};
    w.requestIdleCallback = fn => w.setTimeout(() => fn({timeRemaining:() => 10}),0);
    w.cancelIdleCallback = w.clearTimeout;
    w.fetch = () => Promise.reject(new Error('Network is disabled in Gutenberg validation tests.'));
    w.wpApiSettings = {root:'http://letterlift-runtime.test/wp-json/',nonce:'test'};
    try {
        for (const script of manifest.scripts) {
            try { vm.runInContext(fs.readFileSync(script.path,'utf8'),dom.getInternalVMContext(),{filename:script.path}); }
            catch (error) { throw new Error(`${script.handle}: ${error.message}`); }
        }
        w.wp.blockLibrary.registerCoreBlocks();
        let validated = 0;
        function check(blocks, name) {
            for (const block of blocks) {
                assert.ok(block.name.startsWith('core/'),`${name}: only core blocks`);
                assert.equal(block.isValid,true,`${name}: ${block.name} is invalid\n${JSON.stringify(block.validationIssues)}\n${block.originalContent}`);
                // core/html intentionally bypasses normal save validation in current core.
                // Its raw markup is checked for loss across the editor save round trip below.
                if (block.name !== 'core/html') {
                    const [valid,issues] = w.wp.blocks.validateBlock(block);
                    assert.equal(valid,true,`${name}: ${block.name} save validation failed: ${JSON.stringify(issues)}`);
                }
                check(block.innerBlocks,name); validated++;
            }
        }
        for (const [name,content] of Object.entries(cases)) {
            const blocks = w.wp.blocks.parse(content); check(blocks,name);
            const saved = w.wp.blocks.serialize(blocks);
            const signature = html => {const body=new w.DOMParser().parseFromString(html,'text/html').body; return {text:body.textContent.replace(/\s+/g,''),images:[...body.querySelectorAll('img')].map(i=>[i.getAttribute('src'),i.alt]),links:[...body.querySelectorAll('a')].map(a=>a.getAttribute('href')),formatting:[...body.querySelectorAll('strong,em,u,s,sup,sub,code,br')].map(e=>[e.tagName,e.textContent.replace(/\s+/g,'')])};};
            assert.deepEqual(signature(saved),signature(content),`${name}: content survives editor save`);
            check(w.wp.blocks.parse(saved),`${name} after editor save`);
        }
        // Exercise native editing data flows as well as loading existing saved markup.
        const editableParagraph = w.wp.blocks.cloneBlock(w.wp.blocks.parse(cases.paragraph)[0],{content:'Edited paragraph <strong>without HTML knowledge</strong>.'});
        const editableHeading = w.wp.blocks.cloneBlock(w.wp.blocks.parse(cases.heading)[0],{level:4,content:'Edited heading'});
        const editableImage = w.wp.blocks.cloneBlock(w.wp.blocks.parse(cases.image)[0],{id:789,url:'https://local.test/uploads/replacement.png',alt:'Replacement image',caption:'Edited image caption'});
        const editableList = w.wp.blocks.cloneBlock(w.wp.blocks.parse(cases.nested)[0],{},[w.wp.blocks.createBlock('core/list-item',{content:'New editable list item'})]);
        const edited = w.wp.blocks.serialize([editableParagraph,editableHeading,editableImage,editableList]);
        const editedBlocks = w.wp.blocks.parse(edited); check(editedBlocks,'native block editing');
        assert.equal(editedBlocks[1].attributes.level,4);
        assert.equal(editedBlocks[2].attributes.id,789);
        assert.equal(editedBlocks[2].attributes.url,'https://local.test/uploads/replacement.png');
        assert.match(edited,/Edited image caption/);
        assert.match(edited,/New editable list item/);
        console.log(`Validated ${validated} block instances using WordPress ${manifest.wordpress} core JS, including editor-save round trips.`);
    } finally { w.close(); }
});
