/* Letterlift admin workspace. No runtime libraries or build step required. */
(() => {
    'use strict';
    const root = document.getElementById('letterlift');
    if (!root) return;
    const cfg = window.LetterliftConfig;
    let session = null, view = 'new', step = 'organize', history = [], settings = null;
    let busy = false, running = false, message = '', error = '', duplicate = null, url = '';
    let active = 'single', generation = 0, savedGeneration = 0, saveTimer, savePromise = null, historyPage = 1;
    const expanded = new Set();
    let sourceText = null;
    const imagePreviews = new Map();
    let serverPreview = null;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const button = (label, action, attrs = '', primary = false) => `<button type="button" class="button ${primary ? 'button-primary' : ''}" data-action="${action}" ${attrs} ${busy ? 'disabled' : ''}>${esc(label)}</button>`;
    const select = (field, options, value, attrs = '') => `<select data-field="${field}" ${attrs}>${options.map(o => `<option value="${esc(o.id)}" ${String(value) === String(o.id) ? 'selected' : ''}>${esc(o.name)}</option>`).join('')}</select>`;
    const status = s => ({analyzed:'Analyzed',in_review:'In Review',ready:'Ready to Import',importing:'Importing',complete:'Complete',needs_attention:'Needs Attention',failed:'Failed'}[s] || s);
    async function api(path, body) {
        const endpoint = new URL(cfg.root, window.location.href);
        const [route, query = ''] = path.split('?');
        if (endpoint.searchParams.has('rest_route')) endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route') + route);
        else endpoint.pathname += route;
        new URLSearchParams(query).forEach((value, key) => endpoint.searchParams.set(key, value));
        const response = await fetch(endpoint.toString(), {method: body === undefined ? 'GET' : 'POST', credentials:'same-origin', headers:{'X-WP-Nonce':cfg.nonce, 'Content-Type':'application/json'}, ...(body === undefined ? {} : {body:JSON.stringify(body)})});
        let data;
        try { data = await response.json(); } catch (_) { throw new Error('The server returned an unexpected response. Reload the import to recover saved work.'); }
        if (!response.ok) { const problem = new Error(data.message || 'The request failed. Try again.'); problem.data = data; throw problem; }
        return data;
    }
    const drafts = () => session.plan.mode === 'single' ? [session.plan.single] : session.plan.drafts;
    const blocksFor = id => session.plan.order.map(bid => session.analysis.blocks.find(b => b.id === bid)).filter(b => session.plan.selection[b.id] && (session.plan.mode === 'single' || session.plan.assignments[b.id] === id));
    const draftOptions = () => [{id:'',name:'Unassigned'}, ...session.plan.drafts.map(d => ({id:d.id,name:d.title || 'Untitled draft'}))];
    const activeDraft = () => drafts().find(d => d.id === active) || drafts()[0];
    function counts() {
        const p = session.plan, all = session.analysis.blocks;
        const included = all.filter(b => p.selection[b.id]);
        return {included:included.length, excluded:all.length-included.length, unassigned:p.mode === 'single' ? 0 : included.filter(b => !p.assignments[b.id]).length, images:new Set(included.flatMap(b => b.images)).size};
    }
    function markDirty() {
        generation++; serverPreview = null; root.querySelectorAll('[data-preview-panel]').forEach(panel => { panel.innerHTML = articlePreview(activeDraft()?.id || '', true); }); message = 'Saving review…'; clearTimeout(saveTimer); saveTimer = setTimeout(() => save().catch(showError), 700);
    }
    async function refreshPreview() {
        if (!session || session.execution) return;
        const id = session.id, revision = session.revision, gen = generation;
        const preview = await api(`sessions/${id}/preview`);
        if (session?.id !== id || session.revision !== revision || generation !== gen) return;
        serverPreview = preview;
        // Update only the preview panel: do not remount text controls or steal their caret.
        root.querySelectorAll('[data-preview-panel]').forEach(panel => {
            panel.innerHTML = articlePreview(activeDraft()?.id || '', true);
        });
    }
    async function save() {
        clearTimeout(saveTimer);
        if (savePromise) { await savePromise; if (generation !== savedGeneration) return save(); return; }
        if (!session || session.execution || generation === savedGeneration) return;
        const gen = generation, localId = session.id;
        const snapshot = JSON.parse(JSON.stringify(session.plan));
        savePromise = api(`sessions/${localId}`, {revision:session.revision, plan:snapshot});
        try {
            const result = await savePromise;
            if (session && session.id === localId) { session.revision = result.revision; session.status = result.status; savedGeneration = gen; message = generation === gen ? 'All review changes saved.' : 'Saving review…'; }
        } finally { savePromise = null; }
        if (generation !== savedGeneration) return save();
        await refreshPreview();
        const note = document.getElementById('ll-save-status'); if (note) note.textContent = message;
    }
    function showError(e) { error = e.message; render(); document.getElementById('ll-errors')?.focus(); }
    function render() {
        const focusId = document.activeElement?.id;
        root.innerHTML = `<header class="ll-header"><div><h1>Letterlift <span>Newsletter → WordPress</span></h1><p>Good stories deserve a second home.</p></div><span class="ll-version">1.0</span></header>
        <nav class="nav-tab-wrapper" aria-label="Letterlift views">${[['new','New Import'],['history','Import History'],['settings','Settings']].map(([id,label]) => `<button class="nav-tab ${view === id ? 'nav-tab-active' : ''}" data-action="nav" data-view="${id}" ${busy ? 'disabled' : ''}>${label}</button>`).join('')}</nav>
        ${error ? `<div class="notice notice-error ll-notice" id="ll-errors" role="alert" tabindex="-1"><p>${esc(error)}</p><p>${button('Reload saved import','reload')} ${button('Dismiss','dismiss')}</p></div>` : ''}
        <p class="ll-status" id="ll-save-status" role="status" aria-live="polite">${esc(message)}</p>
        ${view === 'settings' ? settingsView() : view === 'history' ? historyView() : session ? workspace() : startView()}`;
        root.querySelectorAll('[data-section-check]').forEach(el => { const section = session.analysis.sections.find(s => s.id === el.dataset.sectionCheck); const count = section.blocks.filter(id => session.plan.selection[id]).length; el.indeterminate = count > 0 && count < section.blocks.length; });
        root.querySelectorAll('[data-field]').forEach(el => { if (!el.id) el.id = `ll-field-${el.dataset.field}-${el.dataset.id || (['radio','checkbox'].includes(el.type) ? el.value : 'default')}`; });
        if (busy) root.querySelectorAll('input,select,textarea').forEach(el => { el.disabled = true; });
        if (focusId) document.getElementById(focusId)?.focus({preventScroll:true});
    }
    function startView() {
        const unfinished = history.filter(s => !['complete'].includes(s.status)).slice(0,3);
        return `<section class="ll-card ll-welcome"><span class="ll-eyebrow">NEW IMPORT</span><h2>Give your newsletter a new home.</h2><p>Paste a link to a published newsletter. We'll help you turn its content into WordPress posts.</p>
        <form id="ll-analyze"><label for="ll-url">Public Mailchimp newsletter URL</label><div class="ll-url-row"><input id="ll-url" name="url" type="url" value="${esc(url)}" required placeholder="https://mailchi.mp/your-newsletter/story" ${busy ? 'disabled' : ''}> <button class="button button-primary" ${busy ? 'disabled' : ''}>${busy ? 'Retrieving and analyzing…' : 'Analyze Newsletter'}</button></div></form>
        <p class="description">Supports public Mailchimp campaign archives on mailchi.mp, campaign-archive.com, and list-manage.com (including legacy variants). Analysis creates no posts or Media Library attachments.</p><p class="ll-rights">Public access does not grant republication rights. Only import content you have permission to republish.</p>
        ${duplicate ? `<aside class="ll-warning"><h3>We've seen this newsletter before.</h3><p>Resume an existing import, inspect its posts, or deliberately start a separate import.</p>${duplicate.map(s => `<p>${esc(s.title)} — ${esc(status(s.status))} ${s.id ? button('Review existing import','resume',`data-id="${esc(s.id)}"`) : s.posts.map(p => `<a class="button" href="${esc(p.edit_url)}">Edit Post #${p.id}</a>`).join(' ')}</p>`).join('')}${button('Analyze as a separate import','separate')}</aside>` : ''}
        ${unfinished.length ? `<h3>Pick up where you left off</h3>${unfinished.map(s => `<p>${esc(s.title)} <span class="ll-badge">${esc(status(s.status))}</span> ${button('Resume Existing Import','resume',`data-id="${esc(s.id)}"`)}</p>`).join('')}` : ''}</section>`;
    }
    function workspace() {
        if (session.execution) return executionView();
        const a = session.analysis;
        return `<div class="ll-workflow"><ol aria-label="Import steps" class="ll-steps"><li>1. Add newsletter ✓</li><li>2. Analyze ✓</li><li ${step === 'organize' ? 'aria-current="step"' : ''}>3. Organize</li><li ${step === 'preview' ? 'aria-current="step"' : ''}>4. Preview</li><li>5. Create drafts</li></ol>
        <div class="ll-title-row"><div><h2>${step === 'preview' ? 'Everything look good?' : 'Make this newsletter yours.'}</h2><p>${step === 'preview' ? 'Review your WordPress drafts before we create them.' : 'Choose what to keep, organize your stories, and decide what becomes a WordPress post.'}</p></div>${button('Start Another Import','new')}</div>
        <details class="ll-card ll-analysis" ${sourceText !== null ? 'open' : ''}><summary>Analysis: ${esc(a.title)} · ${esc(a.confidence)}</summary><dl class="ll-facts"><div><dt>Provider</dt><dd>Mailchimp</dd></div><div><dt>Detected sections</dt><dd>${a.sections.length}</dd></div><div><dt>Content blocks</dt><dd>${a.blocks.length}</dd></div><div><dt>Referenced images</dt><dd>${a.images.length}</dd></div><div><dt>Suggested boundaries</dt><dd>${a.story_boundaries}</dd></div><div><dt>Sections with suggested exclusions</dt><dd>${a.sections.filter(s => a.blocks.some(b => b.section === s.id && b.suggested_exclusion)).length}</dd></div></dl><p>Story boundaries and boilerplate exclusions are heuristic suggestions. Review them against the original.</p>${sourceLink()}<p>Source fingerprint: <code>${esc(a.fingerprint)}</code></p>${button('Inspect saved original HTML','source')}<pre id="ll-source" ${sourceText === null ? 'hidden' : ''}>${esc(sourceText || '')}</pre></details>
        ${session.duplicates.length ? '<p class="ll-warning">Duplicate source: a previous import exists. This separate import will create additional drafts.</p>' : ''}
        ${warningPanel()}
        ${step === 'organize' ? organizer() : previewView()}
        <footer class="ll-toolbar">${step === 'preview' ? button('Back to Organize','organize') : button('Save Review','save')}${step === 'organize' ? button('Continue to Preview','preview','',true) : button(drafts().length === 1 ? 'Create Draft' : `Create ${drafts().length} Drafts`,'approve','',true)}</footer></div>`;
    }
    function sourceLink() { return `<p><a href="${esc(session.url)}" target="_blank" rel="noopener noreferrer">Inspect original newsletter ↗</a></p>`; }
    function warningPanel() {
        const a = session.analysis, p = session.plan;
        return a.warnings.length ? `<section class="ll-warning"><h3>Content integrity review</h3>${a.warnings.map(w => `<div class="ll-integrity"><p>${esc(w.message)} ${w.id !== 'source-integrity' ? `<a href="#ll-block-${esc(w.id)}" data-reveal="${esc(w.id)}">Inspect block</a>` : ''}</p><label><input type="checkbox" data-field="ack" value="${esc(w.id)}" ${p.acknowledged.includes(w.id) ? 'checked' : ''}> I compared the original and approve this reviewed ${w.id === 'source-integrity' ? 'content selection' : 'safe representation'}.</label></div>`).join('')}${sourceLink()}</section>` : '';
    }
    function organizer() {
        const p = session.plan;
        return `<div class="ll-mode ll-card"><fieldset><legend>Import mode</legend>${['single','multiple'].map(mode => `<label><input type="radio" name="ll-mode" data-field="mode" value="${mode}" ${p.mode === mode ? 'checked' : ''}> ${mode === 'single' ? 'Single Post' : 'Multiple Posts'}</label>`).join('')}<p class="description">Changing mode changes the planned output. Your multi-post assignments and extracted source are preserved.</p></fieldset></div>
        <div class="ll-columns"><section aria-labelledby="ll-organizer-title"><h3 id="ll-organizer-title">Content Organizer</h3><p>Expand any section for individual block selection and destination controls.</p>${[...session.analysis.sections].sort((a,b) => Math.min(...a.blocks.map(id => p.order.indexOf(id))) - Math.min(...b.blocks.map(id => p.order.indexOf(id)))).map(sectionView).join('')}</section>
        <aside class="ll-card ll-outline"><h3>Draft Outline and Preview</h3>${p.mode === 'multiple' ? button('Create destination draft','add-draft') : ''}${draftList()}${outline()}${summary()}</aside></div>`;
    }
    function sectionView(s) {
        const p = session.plan, blocks = s.blocks.map(id => session.analysis.blocks.find(b => b.id === id)), n = s.blocks.filter(id => p.selection[id]).length;
        const assigned = new Set(s.blocks.map(id => p.assignments[id]));
        return `<section class="ll-card ll-section" id="ll-section-${esc(s.id)}"><div class="ll-section-head"><input id="ll-check-${esc(s.id)}" type="checkbox" data-section-check="${esc(s.id)}" aria-label="Select section ${esc(p.section_titles[s.id])}" ${n === s.blocks.length ? 'checked' : ''}><div><h4>${esc(p.section_titles[s.id])}</h4><p>Suggested story · ${blocks.length} blocks · Original position ${s.position}</p><p>${n === 0 ? 'Not selected' : n === blocks.length ? 'Selected' : 'Partially selected'}${blocks.some(b => b.suggested_exclusion) ? ' · Contains suggested boilerplate' : ''}</p></div></div>
        <div class="ll-section-tools">${button(expanded.has(s.id) ? 'Collapse blocks' : 'Expand blocks','expand',`data-id="${esc(s.id)}" aria-expanded="${expanded.has(s.id)}" aria-controls="ll-children-${esc(s.id)}" id="ll-expand-${esc(s.id)}"`)} ${button('Move section up','section-up',`data-id="${esc(s.id)}"`)} ${button('Move section down','section-down',`data-id="${esc(s.id)}"`)}</div>
        <label>Section title<input type="text" data-field="section-title" data-id="${esc(s.id)}" value="${esc(p.section_titles[s.id])}"></label>
        ${p.mode === 'multiple' ? `<label>Assign entire section ${select('section-draft',[{id:'__mixed',name:'Mixed assignments'}, ...draftOptions()], assigned.size === 1 ? [...assigned][0] : '__mixed',`data-id="${esc(s.id)}"`)}</label>` : '<p class="description">Assigned to single destination draft.</p>'}
        <div id="ll-children-${esc(s.id)}" ${expanded.has(s.id) ? '' : 'hidden'}>${blocks.sort((a,b) => p.order.indexOf(a.id)-p.order.indexOf(b.id)).map(blockView).join('')}</div></section>`;
    }
    function blockView(b) {
        const p = session.plan;
        return `<article class="ll-block" id="ll-block-${esc(b.id)}"><label><input id="ll-select-${esc(b.id)}" type="checkbox" data-field="block-select" data-id="${esc(b.id)}" ${p.selection[b.id] ? 'checked' : ''}> ${esc(b.type)} · Original block ${b.position}</label>
        ${b.suggested_exclusion ? '<p class="ll-badge">Suggested boilerplate exclusion — your choice</p>' : ''}<details><summary>${esc(b.text ? (b.text.slice(0,160) + (b.text.length > 160 ? '…' : '')) : 'Image / divider — expand to inspect')}</summary><div class="ll-full-text">${esc(b.text)}</div>${b.images.map(id => {const img = session.analysis.images.find(i => i.id === id);return `<p>Image: ${esc(img.alt || 'No alternate text')}<br><code class="ll-break">${esc(img.url)}</code></p>`;}).join('')}</details>
        ${b.warnings.map(w => `<p class="ll-warning">${esc(w.message)}</p>`).join('')}
        ${p.mode === 'multiple' ? `<label>Destination ${select('block-draft',draftOptions(),p.assignments[b.id],`data-id="${esc(b.id)}"`)}</label>` : ''}
        <div class="ll-actions">${button('Move Up','up',`data-id="${esc(b.id)}" id="ll-up-${esc(b.id)}"`)} ${button('Move Down','down',`data-id="${esc(b.id)}" id="ll-down-${esc(b.id)}"`)}</div></article>`;
    }
    function draftList() {
        return `<div class="ll-draft-list" aria-label="Destination drafts">${drafts().map(d => `<div class="${activeDraft()?.id === d.id ? 'll-active' : ''}">${button(d.title || 'Untitled draft','active',`data-id="${esc(d.id)}" aria-pressed="${activeDraft()?.id === d.id}"`)}<span>${blocksFor(d.id).length} blocks · ${new Set(blocksFor(d.id).map(b => b.section)).size} sections${serverPreview?.drafts?.[d.id]?.warnings.length ? ` · ${serverPreview.drafts[d.id].warnings.length} formatting review(s)` : ''}</span>${session.plan.mode === 'multiple' ? button('Remove destination','remove-draft',`data-id="${esc(d.id)}"`) : ''}</div>`).join('')}</div>`;
    }
    function outline() {
        const d = activeDraft();
        if (!d) return '<p>Create a draft to assign content. Selected unassigned blocks remain in the organizer.</p>';
        const blocks = blocksFor(d.id);
        return `<h4>${esc(d.title)}</h4><ol class="ll-outline-list">${blocks.map(b => `<li>${esc(b.type)}: ${esc(b.text.slice(0,80) || 'Image / divider')} <span>${button('↑','up',`data-id="${esc(b.id)}" aria-label="Move block ${b.position} up"`)} ${button('↓','down',`data-id="${esc(b.id)}" aria-label="Move block ${b.position} down"`)}</span></li>`).join('')}</ol>${articlePreview(d.id)}`;
    }
    function articlePreview(draftId, inner = false) {
        // WordPress sanitizes source markup. The sandbox permits only validated image data
        // returned by our preview endpoint; the browser never retrieves remote image URLs.
        const preview = serverPreview?.revision === session.revision ? serverPreview.drafts?.[draftId] : null;
        const html = preview?.html || '<p>Preparing the WordPress block preview. Save your review to refresh.</p>';
        const doc = new DOMParser().parseFromString(`<!doctype html><html><body>${html}</body></html>`, 'text/html');
        doc.querySelectorAll('img').forEach(img => {
            const source = session.analysis.images.find(i => i.url === img.getAttribute('src'));
            const preview = source && imagePreviews.get(source.id);
            if (preview && /^data:image\/(jpeg|png|gif|webp);base64,[A-Za-z0-9+/=]+$/.test(preview)) { img.setAttribute('src',preview); return; }
            const placeholder = doc.createElement('div'); placeholder.className = 'image-placeholder'; placeholder.textContent = `Image: ${img.alt || 'Newsletter image'} (imported during execution)`; img.replaceWith(placeholder); });
        const srcdoc = `<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; base-uri 'none'; form-action 'none'"><style>body{font:16px/1.6 Georgia,serif;color:#1d2327;padding:16px;overflow-wrap:anywhere}h1,h2,h3,h4{line-height:1.25}img{max-width:100%;height:auto}table{border-collapse:collapse;max-width:100%;display:block;overflow:auto}td,th{border:1px solid #bbb;padding:8px}blockquote{border-left:3px solid #b2bdb9;padding-left:16px}.image-placeholder{background:#eff3f1;border:1px dashed #879c91;padding:24px;margin:12px 0;font:14px sans-serif}a{color:#176a50}pre{white-space:pre-wrap}</style></head><body>${doc.body.innerHTML}</body></html>`;
        return `${inner ? '' : '<div data-preview-panel>'}${(preview?.warnings || []).map(w => `<p class="ll-warning">${esc(w.message)} <a href="#ll-block-${esc(w.block_id)}" data-reveal="${esc(w.block_id)}">Inspect block</a></p>`).join('')}<p class="description">WordPress block preview. Theme styling may differ. Load images safely through WordPress without creating attachments.</p>${button('Load image previews','image-previews')}<iframe class="ll-preview" sandbox="" referrerpolicy="no-referrer" title="Draft content preview" srcdoc="${esc(srcdoc)}"></iframe>${inner ? '' : '</div>'}`;
    }
    function summary() {
        const c = counts();
        return `<div class="ll-summary"><p><strong>${drafts().length} destination ${drafts().length === 1 ? 'draft' : 'drafts'}</strong> · ${c.included} included · ${c.excluded} excluded · <strong>${c.unassigned} unassigned</strong> · ${c.images} images</p><p>All posts will be created as drafts. No content is published.</p></div>`;
    }
    function previewView() {
        const d = activeDraft();
        if (!d) return '<p>No destinations. Return to Organize to create a draft.</p>';
        const availableImages = new Set(blocksFor(d.id).flatMap(b => b.images));
        return `<div class="ll-columns"><aside class="ll-card"><h3>Planned Drafts</h3>${draftList()}<h3>Post settings</h3>
        ${[['title','Title'],['slug','Slug (leave blank to generate)'],['excerpt','Excerpt (leave blank to suggest)']].map(([field,label]) => `<label>${label}${field === 'excerpt' ? `<textarea data-field="draft-${field}" data-id="${esc(d.id)}">${esc(d[field])}</textarea>` : `<input type="text" data-field="draft-${field}" data-id="${esc(d.id)}" value="${esc(d[field])}">`}</label>`).join('')}
        <label>Category ${select('draft-category',cfg.categories,d.category,`data-id="${esc(d.id)}"`)}</label><label>Author ${select('draft-author',cfg.authors,d.author,`data-id="${esc(d.id)}"`)}</label>
        <label>Publication date (${esc(cfg.timezone)}; blank = creation time)<input type="datetime-local" data-field="draft-date" data-id="${esc(d.id)}" value="${esc(d.date)}"></label>
        <label>Featured image ${select('draft-featured',[{id:'',name:'No featured image'}, ...(d.featured && !availableImages.has(d.featured) ? [{id:d.featured,name:'Previously selected image excluded — choose another or None'}] : []), ...session.analysis.images.filter(i => availableImages.has(i.id)).map(i => ({id:i.id,name:i.alt || i.url.split('/').pop()}))],d.featured,`data-id="${esc(d.id)}"`)}</label><p>Status: <strong>Draft</strong> · Post type: Post</p></aside>
        <section class="ll-card"><h3>${esc(d.title)}</h3>${articlePreview(d.id)}${summary()}<h4>Final review</h4>${sourceLink()}<ul>${drafts().map(draft => `<li>${esc(draft.title || 'Missing title')} — ${blocksFor(draft.id).length} blocks${!blocksFor(draft.id).length ? ' · Needs content' : ''}</li>`).join('')}</ul><p>${session.analysis.warnings.length} integrity warnings to review above. Excluded and unassigned blocks remain in the saved source session.</p></section></div>`;
    }
    function executionView() {
        const e = session.execution;
        return `<section class="ll-card"><h2>${session.status === 'complete' ? 'Your drafts are ready!' : session.status === 'needs_attention' ? 'Some drafts need attention.' : 'Bringing your stories into WordPress.'}</h2><p role="status">${esc(e.phase)}</p><p>Approved ${esc(e.approved_at)}. ${Object.values(e.images).filter(i => i.status === 'complete').length} of ${Object.keys(e.images).length} images imported.</p>
        <ul class="ll-results">${Object.values(e.posts).map(p => `<li><strong>${esc(p.title)}</strong><span class="ll-badge">${esc(p.status)}</span>${p.error ? `<p class="ll-warning">${esc(p.error)}</p>` : ''}${p.edit_url ? `<a class="button" href="${esc(p.edit_url)}">Edit Post #${p.id}</a>` : ''}</li>`).join('')}</ul>
        ${Object.entries(e.images).filter(([,i]) => i.status === 'failed').map(([id,i]) => `<p class="ll-warning">Image ${esc(id)}: ${esc(i.error)}</p>`).join('')}
        ${running ? button('Pause after current operation','pause') : session.status === 'importing' ? button('Resume Processing','run','',true) : session.status === 'needs_attention' ? button('Retry incomplete processing','retry','',true) : ''}
        ${button('View Import Details','details')} ${button('Start Another Import','new')}
        <details ${step === 'details' ? 'open' : ''}><summary>Import details and approved plan</summary>${sourceLink()}<p>Session: <code>${esc(session.id)}</code></p><p>Source fingerprint: <code>${esc(session.analysis.fingerprint)}</code></p><p>Plan fingerprint: <code>${esc(e.plan_hash)}</code></p><p>Associated attachment IDs: ${Object.values(e.images).filter(i => i.id).map(i => i.id).join(', ') || 'None'}</p><pre class="ll-plan">${esc(JSON.stringify(e.snapshot,null,2))}</pre></details><p class="description">Leaving this page pauses processing after the current request. Resume from Import History. Completed posts are reused on retry.</p></section>`;
    }
    function historyView() {
        return `<section class="ll-card"><h2>Import History</h2><p>Resume reviews and unfinished imports, or return to completed WordPress posts.</p><div class="ll-table-scroll"><table class="widefat striped"><thead><tr><th>Newsletter</th><th>Source</th><th>Import date (UTC)</th><th>Destinations</th><th>Status</th><th>Action</th></tr></thead><tbody>${history.length ? history.map(s => `<tr><td>${esc(s.title)}</td><td><a href="${esc(s.url)}" target="_blank" rel="noopener noreferrer">Mailchimp ↗</a></td><td>${esc(s.created)}</td><td>${s.drafts}</td><td>${esc(status(s.status))}</td><td>${button('Resume / View Details','resume',`data-id="${esc(s.id)}"`)}</td></tr>`).join('') : '<tr><td colspan="6">No imports on this page.</td></tr>'}</tbody></table></div><p>${historyPage > 1 ? button('Previous page','previous') : ''} Page ${historyPage} ${history.length === 50 ? button('Next page','next') : ''}</p></section>`;
    }
    function settingsView() {
        if (!settings) return '<p>Loading settings…</p>';
        return `<section class="ll-card ll-settings"><h2>Settings</h2><form id="ll-settings"><label>Default category ${select('setting-category',cfg.categories,settings.category)}</label><label>Default post author ${select('setting-author',[{id:0,name:'Current authorized user'},...cfg.authors],settings.author)}</label><label>Default import mode ${select('setting-mode',[{id:'single',name:'Single Post'},{id:'multiple',name:'Multiple Posts'}],settings.mode)}</label><label><input type="checkbox" data-field="setting-featured" ${settings.featured ? 'checked' : ''}> Suggest the first included image as featured image</label><label><input type="checkbox" data-field="setting-exclude_boilerplate" ${settings.exclude_boilerplate ? 'checked' : ''}> Initially deselect suggested newsletter boilerplate (always recoverable)</label><button class="button button-primary" ${busy ? 'disabled' : ''}>Save Settings</button></form><hr><h3>Data retention</h3><label>Age in days for manual history cleanup<input type="number" min="1" max="3650" data-field="setting-retention_days" value="${settings.retention_days}"></label><p>Save Settings before cleanup. Cleanup removes old analysis sessions and diagnostics, including unfinished reviews. Active executions, imported posts, and Media Library attachments remain.</p>${button('Remove old analysis and history records','cleanup')}<hr><h3>Privacy and processing</h3><p>Processing happens on this WordPress installation. No account, cloud AI, analytics, or telemetry is required. Only the selected public source and included images are retrieved.</p><p>Limits: 2 MB HTML, 500 blocks, 100 images, 10 MB and 40 megapixels per image. Operations run one at a time to accommodate shared hosting.</p><p>Uninstall removes Letterlift sessions and settings. Imported posts and media remain usable without Letterlift.</p></section>`;
    }
    async function loadSession(id) {
        await save();
        session = await api(`sessions/${id}`); generation = savedGeneration = 0; sourceText = null; serverPreview = null; imagePreviews.clear(); active = session.plan.mode === 'single' ? 'single' : session.plan.drafts[0]?.id; view = 'new'; step = session.execution ? 'details' : 'organize'; message = 'Saved import restored.'; duplicate = null; expanded.clear(); await refreshPreview();
    }
    async function analyze(separate = false) {
        url = document.getElementById('ll-url')?.value || url;
        busy = true; error = ''; message = 'Retrieving newsletter, identifying content and checking integrity…'; render();
        try { session = await api('analyze',{url,separate}); generation = savedGeneration = 0; sourceText = null; serverPreview = null; imagePreviews.clear(); active = session.plan.mode === 'single' ? 'single' : session.plan.drafts[0]?.id; step = 'organize'; duplicate = null; message = 'Analysis saved. No posts or media were created.'; await refreshPreview(); }
        catch (e) { if (e.data?.code === 'duplicate') { duplicate = e.data.duplicates; message = ''; } else throw e; }
        finally { busy = false; render(); }
    }
    function moveBlock(id, direction) {
        const order = session.plan.order, index = order.indexOf(id);
        const eligible = session.plan.mode === 'single' ? order : order.filter(bid => session.plan.assignments[bid] === session.plan.assignments[id]);
        const target = eligible[eligible.indexOf(id) + direction]; if (!target) return;
        const targetIndex = order.indexOf(target); [order[index],order[targetIndex]] = [order[targetIndex],order[index]]; markDirty();
    }
    async function process() {
        running = true; error = ''; render();
        try {
            while (running && session.status === 'importing') {
                const result = await api(`sessions/${session.id}/tick`,{revision:session.revision}); session = result;
                message = session.execution.phase; render();
            }
        } finally { running = false; render(); }
    }
    root.addEventListener('submit', event => {
        event.preventDefault();
        if (event.target.id === 'll-analyze') analyze().catch(showError);
        if (event.target.id === 'll-settings') perform(async () => { settings = await api('settings', settings); message = 'Settings saved.'; });
    });
    async function perform(fn) {
        if (busy) return;
        busy = true; error = ''; render();
        try { await fn(); } catch (e) { showError(e); }
        finally { busy = false; render(); }
    }
    root.addEventListener('click', async event => {
        const reveal = event.target.closest('[data-reveal]');
        if (reveal && session) {
            event.preventDefault(); step = 'organize'; const b = session.analysis.blocks.find(b => b.id === reveal.dataset.reveal); if (b) { expanded.add(b.section); render(); const el = document.getElementById(`ll-block-${b.id}`); el?.scrollIntoView({block:'center'}); el?.querySelector('input')?.focus(); } return;
        }
        const control = event.target.closest('[data-action]'); if (!control || busy) return;
        const action = control.dataset.action, id = control.dataset.id;
        if (action === 'dismiss') { error = ''; render(); return; }
        if (action === 'pause') { running = false; message = 'Pausing after the current operation…'; render(); return; }
        if (running) { message = 'Pause processing before switching views.'; render(); return; }
        if (action === 'run') { process().catch(showError); return; }
        if (action === 'retry') { await perform(async () => { session = await api(`sessions/${session.id}/retry`,{revision:session.revision}); }); if (!error) process().catch(showError); return; }
        if (action === 'separate') { analyze(true).catch(showError); return; }
        if (action === 'expand') { expanded.has(id) ? expanded.delete(id) : expanded.add(id); render(); return; }
        if (action === 'active') { active = id; render(); return; }
        if (action === 'up' || action === 'down') { moveBlock(id,action === 'up' ? -1 : 1); render(); return; }
        if (action === 'section-up' || action === 'section-down') {
            const sections = [...session.analysis.sections].sort((a,b) => Math.min(...a.blocks.map(bid => session.plan.order.indexOf(bid))) - Math.min(...b.blocks.map(bid => session.plan.order.indexOf(bid))));
            const section = sections.find(s => s.id === id), index = sections.indexOf(section), target = sections[index + (action === 'section-up' ? -1 : 1)];
            if (target) { const moving = session.plan.order.filter(bid => section.blocks.includes(bid)); const remaining = session.plan.order.filter(bid => !section.blocks.includes(bid)); const indices = target.blocks.map(bid => remaining.indexOf(bid)); const at = action === 'section-up' ? Math.min(...indices) : Math.max(...indices) + 1; remaining.splice(at,0,...moving); session.plan.order = remaining; markDirty(); }
            render(); return;
        }
        if (action === 'add-draft') { const draft = {...session.plan.single,id:`d-${Date.now()}-${Math.random().toString(36).slice(2,7)}`,title:`Draft ${session.plan.drafts.length+1}`,slug:'',excerpt:'',featured:''}; session.plan.drafts.push(draft); active = draft.id; markDirty(); render(); return; }
        if (action === 'remove-draft') {
            session.plan.drafts = session.plan.drafts.filter(d => d.id !== id); Object.keys(session.plan.assignments).forEach(bid => { if (session.plan.assignments[bid] === id) session.plan.assignments[bid] = ''; }); active = session.plan.drafts[0]?.id; markDirty(); render(); return;
        }
        if (action === 'details') { step = 'details'; render(); return; }
        if (action === 'image-previews') {
            await perform(async () => {
                const ids = [...new Set(session.analysis.blocks.filter(b => session.plan.selection[b.id]).flatMap(b => b.images))];
                for (const image of ids) {
                    if (imagePreviews.has(image)) continue;
                    const data = await api(`sessions/${session.id}/preview-image?image=${encodeURIComponent(image)}`);
                    imagePreviews.set(image,data.image); message = `${imagePreviews.size} image previews loaded.`; render();
                }
            }); return;
        }
        if (action === 'source') {
            await perform(async () => { const data = await api(`sessions/${session.id}/source`); sourceText = data.source; message = 'Original source loaded for inspection.'; }); return;
        }
        await perform(async () => {
            if (action === 'nav') { await save(); view = control.dataset.view; if (view === 'history') { historyPage = 1; history = await api('history'); } if (view === 'settings') settings = await api('settings'); }
            if (action === 'resume') await loadSession(id);
            if (action === 'reload') { if (session) { clearTimeout(saveTimer); if (savePromise) await savePromise.catch(() => {}); session = await api(`sessions/${session.id}`); generation = savedGeneration = 0; serverPreview = null; await refreshPreview(); message = 'Saved import reloaded.'; } else { message = ''; } }
            if (action === 'new') { await save(); session = null; serverPreview = null; sourceText = null; generation = savedGeneration = 0; view = 'new'; url = ''; message = ''; duplicate = null; history = await api('history'); }
            if (action === 'save') { await save(); message = 'All review changes saved.'; }
            if (action === 'preview') { await save(); step = 'preview'; }
            if (action === 'organize') { await save(); step = 'organize'; }
            if (action === 'approve') { await save(); session = await api(`sessions/${session.id}/approve`,{revision:session.revision}); message = 'Approved plan recorded. Starting import…'; }
            if (action === 'cleanup') { const result = await api('cleanup',{}); message = `${result.removed} old records removed. Imported posts and media are preserved.`; }
            if (action === 'next' || action === 'previous') { historyPage += action === 'next' ? 1 : -1; history = await api(`history?page=${historyPage}`); }
        });
        if (action === 'approve' && !error) process().catch(showError);
    });
    root.addEventListener('change', event => {
        const el = event.target, field = el.dataset.field, id = el.dataset.id;
        if (el.dataset.sectionCheck && session && !session.execution) {
            const section = session.analysis.sections.find(s => s.id === el.dataset.sectionCheck); section.blocks.forEach(bid => {session.plan.selection[bid] = el.checked;}); markDirty(); render(); return;
        }
        if (!field) return;
        if (field.startsWith('setting-')) { const key = field.slice(8); settings[key] = el.type === 'checkbox' ? el.checked : el.value; return; }
        if (!session || session.execution) return;
        const p = session.plan;
        if (field === 'mode') { p.mode = el.value; active = drafts()[0]?.id; }
        if (field === 'ack') p.acknowledged = el.checked ? [...new Set([...p.acknowledged,el.value])] : p.acknowledged.filter(w => w !== el.value);
        if (field === 'section-title') p.section_titles[id] = el.value;
        if (field === 'block-select') p.selection[id] = el.checked;
        if (field === 'block-draft') p.assignments[id] = el.value;
        if (field === 'section-draft' && el.value !== '__mixed') session.analysis.sections.find(s => s.id === id).blocks.forEach(bid => {p.assignments[bid] = el.value;});
        if (field.startsWith('draft-')) { const d = id === 'single' ? p.single : p.drafts.find(d => d.id === id); if (d) d[field.slice(6)] = el.value; }
        markDirty();
        // Keep text controls mounted so typing and tabbing retain the user's caret and focus.
        if (['text','datetime-local','textarea','number'].includes(el.type)) {
            if (field === 'section-title') document.querySelector(`#ll-section-${id} h4`).textContent = el.value;
            if (field === 'draft-title') root.querySelectorAll('[data-action="active"]').forEach(button => { if (button.dataset.id === id) button.textContent = el.value || 'Untitled draft'; });
        } else render();
    });
    window.addEventListener('beforeunload', event => { if (generation !== savedGeneration) { event.preventDefault(); event.returnValue = ''; } });
    render();
    api('history').then(data => {history = data;if (!session && view === 'new') render();}).catch(showError);
})();
