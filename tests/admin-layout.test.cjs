const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const wpPath = process.env.LETTERLIFT_WORDPRESS_PATH;
const pause = () => new Promise(resolve => setTimeout(resolve,30));

test('WordPress unpins a tall admin menu after Letterlift replaces a long view with a short view', {skip:!wpPath}, async () => {
    // jsdom supplies no layout. Supply the measured dimensions for this regression and
    // run the installed WordPress menu implementation, rather than copying its algorithm.
    const dom = new JSDOM('<html><body class="wp-admin"><div id="wpadminbar"></div><div id="wpwrap"><div id="adminmenuwrap"><ul id="adminmenu"></ul></div><div id="wpcontent"><div id="wpbody"><div id="wpbody-content"><div id="letterlift"></div></div></div></div></div></body></html>', {url:'https://wp.test/wp-admin/tools.php?page=letterlift',runScripts:'outside-only',pretendToBeVisual:true});
    const w = dom.window, d = w.document;
    let pageHeight = 2400, resizeCallback;
    const observed = [];
    w.ResizeObserver = class {constructor(callback){resizeCallback=callback;} observe(element){observed.push(element.id);}};
    Object.defineProperties(d.documentElement,{clientWidth:{value:1280},clientHeight:{value:700}});
    w.userSettings = {url:'/',uid:'1',time:'1',secure:''}; w.pagenow = 'tools_page_letterlift'; w.ajaxurl='https://wp.test/wp-admin/admin-ajax.php';
    w.matchMedia = () => ({matches:false,addListener(){},removeListener(){}});
    const load = file => vm.runInContext(fs.readFileSync(path.join(wpPath,file),'utf8'),dom.getInternalVMContext(),{filename:file});
    try {
        for (const file of ['wp-includes/js/jquery/jquery.min.js','wp-includes/js/hoverIntent.min.js','wp-includes/js/utils.min.js','wp-includes/js/dist/dom-ready.min.js','wp-includes/js/dist/hooks.min.js','wp-includes/js/dist/i18n.min.js','wp-includes/js/dist/a11y.min.js']) load(file);
        const $ = w.jQuery, originalHeight = $.fn.height;
        $.fn.height = function(...args) {
            if (!args.length) {
                if (this[0] === w) return 700;
                if (this[0] === d) return pageHeight;
                if (this[0]?.id === 'wpwrap') return pageHeight;
                if (this[0]?.id === 'wpadminbar') return 32;
                if (this[0]?.id === 'adminmenuwrap') return 1100;
            }
            return originalHeight.apply(this,args);
        };
        load('wp-admin/js/common.js'); await pause();
        w.scrollY = w.pageYOffset = 1710; $(w).trigger('scroll');
        const menu = d.getElementById('adminmenuwrap');
        assert.equal(menu.style.position,'fixed','WordPress pins the taller menu at the bottom of the long view');
        pageHeight = 700; w.scrollY = w.pageYOffset = 0; $(w).trigger('scroll');
        assert.equal(menu.style.position,'fixed','Before notification, WordPress retains stale long-view dimensions and its pinned menu');
        w.LetterliftConfig = {root:'https://wp.test/wp-json/letterlift/v1/',nonce:'test',categories:[],authors:[],timezone:'UTC'};
        w.fetch = async () => ({ok:true,json:async () => []});
        vm.runInContext(fs.readFileSync(path.join(__dirname,'../assets/admin.js'),'utf8'),dom.getInternalVMContext()); await pause();
        assert.equal(menu.style.position,'','Rendering the short Letterlift view lets WordPress release the menu into document flow');
        assert.equal(menu.style.bottom,'','No stale bottom pin remains to hide the upper menu');
        assert.equal(d.body.classList.contains('sticky-menu'),false,'An oversized menu is not fixed by the sticky-menu class');
        assert.deepEqual(observed.sort(),['adminmenuwrap','letterlift'],'Menu and workspace height changes are observed');
        // A native details toggle or async content change can resize the page without rendering.
        pageHeight = 2400; $(d).trigger('wp-pin-menu'); w.scrollY = w.pageYOffset = 1710; $(w).trigger('scroll');
        assert.equal(menu.style.position,'fixed');
        pageHeight = 700; w.scrollY = w.pageYOffset = 0; $(w).trigger('scroll'); resizeCallback(); await pause();
        assert.equal(menu.style.position,'','Observed layout changes also release stale menu pinning');
        $(d.getElementById('adminmenu')).data('wp-responsive',1); resizeCallback(); await pause();
        assert.equal(menu.style.position,'','Responsive WordPress menu mode remains in control');
    } finally { w.close(); }
});
