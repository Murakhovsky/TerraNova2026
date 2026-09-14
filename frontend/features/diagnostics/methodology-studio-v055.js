(()=>{
const root=document.querySelector('[data-studio]');if(!root)return;
const csrf=root.dataset.csrf||'';const q=s=>root.querySelector(s),qa=s=>[...root.querySelectorAll(s)];
const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;').replaceAll('>','&gt;');
const request=async(url,opt={})=>{opt.headers={...(opt.headers||{}),'Content-Type':'application/json','X-CSRF-Token':csrf};const response=await fetch(url,opt);const payload=await response.json();if(!payload.ok)throw new Error(payload.error||'Request failed');return payload.data};
const post=(url,body={})=>request(url,{method:'POST',body:JSON.stringify(body)});
let currentPack='';let scenarioCache=[];let runCache=new Map();let graphBusy=false;let capabilities={view:true,edit:false,publish:false};

function version(){return q('[data-version]')?.value||''}
function context(){return {pack:currentPack||q('[data-pack].selected')?.dataset.pack||'',version:version()}}
function notify(message,error=false){const el=q('[data-toast]');if(!el)return;el.textContent=message;el.classList.toggle('error',error);el.hidden=false;setTimeout(()=>el.hidden=true,3500)}
function openDrawer(name){qa('[data-drawer]').forEach(x=>x.hidden=x.dataset.drawer!==name)}
function versionEditable(){return !/^(PUBLISHED|ARCHIVED)\b/i.test((q('[data-version-status]')?.textContent||'').trim())}
function canEditScenario(){return !!capabilities.edit&&versionEditable()}
function json(value){return esc(JSON.stringify(value??{},null,2))}
function statusBadge(status){const value=String(status||'NOT RUN').toUpperCase();return `<span class="v055-badge ${value.toLowerCase()}">${esc(value)}</span>`}

function ensureWorkbenchUi(){
 const actions=q('.studio-head .actions');
 if(actions&&!actions.querySelector('[data-v055-action="permissions"]'))actions.insertAdjacentHTML('afterbegin','<button type="button" data-v055-action="permissions" hidden>Access</button>');
 const workspace=q('.workspace');
 if(workspace&&!q('[data-drawer="permissions"]'))workspace.insertAdjacentHTML('beforeend',`
  <section class="drawer" hidden data-drawer="permissions">
   <header><div><small>Diagnostic Methodology Studio</small><h2>Access Manager</h2></div><button type="button" data-close>Close</button></header>
   <p class="v055-muted">Role inheritance stays the default. User overrides are explicit and audited.</p>
   <div data-v055-permissions>Loading permissions…</div>
  </section>`);
 if(workspace&&!q('[data-drawer="run-detail"]'))workspace.insertAdjacentHTML('beforeend',`
  <section class="drawer drawer-wide" hidden data-drawer="run-detail">
   <header><div><small>Diagnostic Runs</small><h2>Run drill-down</h2></div><button type="button" data-v055-action="runs-back">Back</button></header>
   <div data-v055-run-detail></div>
  </section>`);
 if(!document.querySelector('[data-v055-clone-dialog]'))document.body.insertAdjacentHTML('beforeend',`
  <dialog class="v055-dialog" data-v055-clone-dialog>
   <form method="dialog" data-v055-clone-form>
    <h3>Clone scenario</h3>
    <input type="hidden" name="source">
    <label>Scenario ID<input name="id" pattern="[a-z][a-z0-9_.-]+" required></label>
    <label>Name<input name="name" required></label>
    <div class="v055-actions"><button value="cancel">Cancel</button><button value="save" type="submit">Clone</button></div>
   </form>
  </dialog>`);
}

async function configureAccessButton(){
 try{
   capabilities=await request('/api/admin/diagnostics/permissions');
   const button=q('[data-v055-action="permissions"]');if(button)button.hidden=!capabilities.publish;
   if(scenarioCache.length)renderScenarioManager();
   if(q('[data-v055-graph]'))renderDependencyGraph(true);
 }catch(_){}
}

async function loadScenarioManager(){
 const {pack,version:methodology}=context();if(!pack||!methodology)return;
 const data=await request(`/api/admin/diagnostics/packs/${encodeURIComponent(pack)}/versions/${encodeURIComponent(methodology)}/workbench/scenarios`);
 scenarioCache=data.scenarios||[];renderScenarioManager();
}

function renderScenarioManager(){
 const mount=q('[data-scenario-list]');if(!mount)return;
 const editable=canEditScenario(),canRun=!!capabilities.edit;
 mount.innerHTML=`<div class="v055-toolbar"><div><strong>Scenario Manager</strong><small>${scenarioCache.length} regression scenarios</small></div><button type="button" data-v055-action="new-scenario" ${editable?'':'disabled'}>New scenario</button></div>`+
 (scenarioCache.map(row=>`<article class="scenario-item v055-row">
   <div><strong>${esc(row.name)}</strong><br><small>${esc(row.scenario_id)}${row.last_run_at?' · '+esc(row.last_run_at):''}</small></div>
   <div>${statusBadge(row.last_status)}</div>
   <div class="v055-actions">
    <button type="button" data-v055-scenario-action="edit" data-id="${esc(row.scenario_id)}" ${editable?'':'disabled'}>Edit</button>
    <button type="button" data-v055-scenario-action="clone" data-id="${esc(row.scenario_id)}" ${editable?'':'disabled'}>Clone</button>
    <button type="button" data-v055-scenario-action="run" data-id="${esc(row.scenario_id)}" ${canRun?'':'disabled'}>Run</button>
    <button type="button" data-v055-scenario-action="delete" data-id="${esc(row.scenario_id)}" ${editable?'':'disabled'}>Delete</button>
   </div>
   ${row.last_result?`<details><summary>Latest result</summary><pre>${json(row.last_result)}</pre></details>`:''}
  </article>`).join('')||'<p>No scenarios yet.</p>');
 const form=q('[data-scenario-form]');if(form)[...form.elements].forEach(control=>control.disabled=!editable);
}

function editScenario(id){
 const row=scenarioCache.find(x=>x.scenario_id===id),form=q('[data-scenario-form]');if(!row||!form)return;
 form.elements.id.value=row.scenario_id;form.elements.name.value=row.name;
 q('[data-scenario-inputs]')?.querySelectorAll('[data-runtime]').forEach(control=>{
   const bucket=control.dataset.runtime==='fact'?'facts':'metrics';const value=row.input?.[bucket]?.[control.dataset.id];
   control.value=value===undefined?'':String(value);
 });
 q('[data-scenario-expected]')?.querySelectorAll('[data-choices]').forEach(group=>{
   const key=group.dataset.choices==='expected_findings'?'findings':'recommendations';
   const selected=new Set(row.expected?.[key]||[]);
   group.querySelectorAll('input[type=checkbox]').forEach(input=>input.checked=selected.has(input.value));
 });
 const score=form.elements.score_min;if(score)score.value=row.expected?.score_min??'';
 form.scrollIntoView({behavior:'smooth',block:'start'});
}

function openCloneDialog(id){
 const row=scenarioCache.find(x=>x.scenario_id===id),dialog=document.querySelector('[data-v055-clone-dialog]');if(!row||!dialog)return;
 const form=dialog.querySelector('form');form.elements.source.value=id;form.elements.id.value=`${id}-copy`;form.elements.name.value=`${row.name} (copy)`;dialog.showModal();
}

async function runScenario(id){
 const {pack,version:methodology}=context();
 const data=await post(`/api/admin/diagnostics/packs/${encodeURIComponent(pack)}/versions/${encodeURIComponent(methodology)}/workbench/scenarios/${encodeURIComponent(id)}/run`);
 notify(`${id}: ${data.result.status}`);await loadScenarioManager();
}

async function deleteScenario(id){
 if(!confirm(`Delete regression scenario ${id}?`))return;
 const {pack,version:methodology}=context();
 await post(`/api/admin/diagnostics/packs/${encodeURIComponent(pack)}/versions/${encodeURIComponent(methodology)}/workbench/scenarios/${encodeURIComponent(id)}/delete`);
 notify('Scenario deleted');await loadScenarioManager();
}

async function loadDependencyEntities(){
 const {pack,version:methodology}=context();if(!pack||!methodology)return [];
 const data=await request(`/api/admin/diagnostics/packs/${encodeURIComponent(pack)}/versions/${encodeURIComponent(methodology)}/entities`);
 return data.entities||[];
}

function graphLayout(criteria,dependencies){
 const ids=criteria.map(x=>x.entity_id),incoming=new Map(ids.map(x=>[x,0])),out=new Map(ids.map(x=>[x,[]]));
 dependencies.forEach(edge=>{const a=edge.payload?.source,b=edge.payload?.target;if(incoming.has(b)&&out.has(a)){incoming.set(b,incoming.get(b)+1);out.get(a).push(b)}});
 const queue=ids.filter(x=>incoming.get(x)===0),rank=new Map(ids.map(x=>[x,0]));while(queue.length){const id=queue.shift();for(const next of out.get(id)||[]){rank.set(next,Math.max(rank.get(next)||0,(rank.get(id)||0)+1));incoming.set(next,incoming.get(next)-1);if(incoming.get(next)===0)queue.push(next)}}
 const groups=new Map();ids.forEach(id=>{const r=rank.get(id)||0;if(!groups.has(r))groups.set(r,[]);groups.get(r).push(id)});
 const positions=new Map();[...groups.entries()].sort((a,b)=>a[0]-b[0]).forEach(([r,list])=>list.forEach((id,i)=>positions.set(id,{x:40+r*260,y:35+i*90})));
 return positions;
}

async function renderDependencyGraph(force=false){
 if(graphBusy)return;if((q('[data-section-title]')?.textContent||'').trim().toUpperCase()!=='DEPENDENCY')return;
 const canvas=q('[data-dependency-canvas]');if(!canvas)return;
 let graph=q('[data-v055-graph]');if(graph&&!force)return;if(graph)graph.remove();
 graphBusy=true;
 try{
  const entities=await loadDependencyEntities(),criteria=entities.filter(x=>x.entity_type==='CRITERION'),dependencies=entities.filter(x=>x.entity_type==='DEPENDENCY'),pos=graphLayout(criteria,dependencies);
  const maxX=Math.max(700,...[...pos.values()].map(p=>p.x+220)),maxY=Math.max(250,...[...pos.values()].map(p=>p.y+70));
  graph=document.createElement('section');graph.dataset.v055Graph='';graph.className='v055-graph';
  graph.innerHTML=`<div class="v055-graph-head"><div><strong>Dependency map</strong><small>Methodology causality and prerequisites</small></div><span>${dependencies.length} links</span></div>
   <div class="v055-graph-scroll"><div class="v055-graph-surface" style="width:${maxX}px;height:${maxY}px">
    <svg width="${maxX}" height="${maxY}" aria-hidden="true">${dependencies.map(edge=>{const a=pos.get(edge.payload?.source),b=pos.get(edge.payload?.target);if(!a||!b)return'';return `<path d="M ${a.x+190} ${a.y+25} C ${a.x+225} ${a.y+25}, ${b.x-35} ${b.y+25}, ${b.x} ${b.y+25}"/>`}).join('')}</svg>
    ${criteria.map(row=>{const p=pos.get(row.entity_id)||{x:20,y:20};return `<div class="v055-graph-node" style="left:${p.x}px;top:${p.y}px"><strong>${esc(row.payload?.name||row.entity_id)}</strong><small>${esc(row.entity_id)}</small></div>`}).join('')}
   </div></div>
   <div class="v055-edge-list">${dependencies.map(edge=>`<div><span><strong>${esc(edge.payload?.source)}</strong> → ${esc(edge.payload?.type||'depends_on')} → <strong>${esc(edge.payload?.target)}</strong></span><button type="button" data-v055-remove-dependency="${esc(edge.entity_id)}" ${capabilities.edit&&versionEditable()?'':'disabled'}>Remove</button></div>`).join('')||'<p>No dependencies yet.</p>'}</div>`;
  canvas.parentElement.insertBefore(graph,canvas);
 }catch(error){notify(error.message,true)}
 finally{graphBusy=false}
}

async function removeDependency(id){
 const {pack,version:methodology}=context();
 await post(`/api/admin/diagnostics/packs/${encodeURIComponent(pack)}/versions/${encodeURIComponent(methodology)}/entities/dependency/${encodeURIComponent(id)}/delete`);
 notify('Dependency removed');
 q('[data-version]')?.dispatchEvent(new Event('change',{bubbles:true}));
 setTimeout(()=>renderDependencyGraph(true),100);
}

function recordName(record){return record.statement||record.reference_code||record.record_id}
function byId(list,key){return new Map((list||[]).map(x=>[x[key],x]))}
function methodologyIndex(methodology){
 const index={rules:new Map(),criteria:new Map(),recommendations:new Map()};
 (methodology?.rules||[]).forEach(x=>index.rules.set(x.id,x));(methodology?.criteria||[]).forEach(x=>index.criteria.set(x.id,x));(methodology?.recommendations||[]).forEach(x=>index.recommendations.set(x.id,x));return index;
}

function renderWhy(run,findingId){
 const panel=q('[data-v055-why-panel]');if(!panel)return;
 const records=byId(run.records,'record_id'),evidence=byId(run.evidence,'evidence_id'),finding=records.get(findingId);if(!finding)return;
 const chain=[],visited=new Set();
 const visit=id=>{if(visited.has(id))return;visited.add(id);const row=records.get(id);if(!row)return;(row.upstream_record_ids||[]).forEach(visit);chain.push(row)};
 visit(findingId);
 const evidenceIds=[...new Set(chain.flatMap(x=>x.evidence_ids||[]))],idx=methodologyIndex(run.methodology),rule=idx.rules.get(finding.reference_code);
 const criterion=rule?idx.criteria.get(rule.criterion):null;
 const recommendations=[...idx.recommendations.values()].filter(x=>(x.trigger_rules||[]).includes(rule?.id||finding.reference_code));
 panel.hidden=false;panel.innerHTML=`<div class="v055-trace"><div class="v055-trace-head"><div><small>Explainability</small><h3>WHY trace</h3></div><button type="button" data-v055-action="close-why">Close</button></div>
  <div class="v055-trace-flow">
   ${evidenceIds.map(id=>{const row=evidence.get(id);return `<article class="v055-trace-node"><small>Evidence</small><strong>${esc(row?.title||id)}</strong><span>${esc(row?.source_reference||'')}</span></article>`}).join('')}
   ${chain.filter(x=>['fact','metric','assessment'].includes(x.record_type)).map(x=>`<article class="v055-trace-node"><small>${esc(x.record_type)}</small><strong>${esc(recordName(x))}</strong><span>${esc(JSON.stringify(x.value??''))}</span></article>`).join('')}
   ${criterion?`<article class="v055-trace-node"><small>Criterion</small><strong>${esc(criterion.name||criterion.id)}</strong></article>`:''}
   ${rule?`<article class="v055-trace-node"><small>Rule</small><strong>${esc(rule.name||rule.id)}</strong><span>${esc(rule.severity||'')}</span></article>`:''}
   <article class="v055-trace-node finding"><small>Finding</small><strong>${esc(recordName(finding))}</strong></article>
   ${recommendations.map(x=>`<article class="v055-trace-node recommendation"><small>Recommendation</small><strong>${esc(x.title||x.id)}</strong></article>`).join('')}
  </div></div>`;
 panel.scrollIntoView({behavior:'smooth',block:'start'});
}

function renderRunDetail(run){
 const mount=q('[data-v055-run-detail]');if(!mount)return;
 const s=run.session||{},summary=run.summary||{},findings=(run.records||[]).filter(x=>x.record_type==='finding');
 mount.innerHTML=`<div class="v055-run-summary">
  <article><small>Status</small><strong>${esc(s.status)}</strong></article><article><small>Coverage</small><strong>${esc(s.coverage_percent??'n/a')}%</strong></article>
  <article><small>Evidence</small><strong>${summary.evidence_count||0}</strong></article><article><small>Findings</small><strong>${findings.length}</strong></article>
  <article><small>Interview</small><strong>${summary.interview_turns||0}</strong></article><article><small>AI calls</small><strong>${summary.ai_calls||0}</strong></article></div>
  <section class="v055-section"><h3>Findings & explainability</h3>${findings.map(x=>`<article class="v055-finding"><div><strong>${esc(recordName(x))}</strong><small>${esc(x.reference_code)}</small></div><button type="button" data-v055-why="${esc(x.record_id)}">WHY?</button></article>`).join('')||'<p>No findings.</p>'}</section>
  <div data-v055-why-panel hidden></div>
  <section class="v055-section"><h3>Assessments</h3><div class="v055-card-grid">${(run.assessments||[]).map(x=>`<article><strong>${esc(x.criterion_id)}</strong><span>score ${esc(x.score??'n/a')}</span><span>coverage ${Math.round(Number(x.coverage||0)*100)}%</span><span>confidence ${Math.round(Number(x.confidence||0)*100)}%</span></article>`).join('')||'<p>No assessments.</p>'}</div></section>
  <section class="v055-section"><h3>Evidence</h3>${(run.evidence||[]).map(x=>`<article class="v055-record"><strong>${esc(x.title)}</strong><span>${esc(x.evidence_type)} · ${esc(x.source_reference)}</span></article>`).join('')||'<p>No evidence.</p>'}</section>
  <section class="v055-section"><h3>Contradictions</h3>${(run.contradictions||[]).map(x=>`<article class="v055-contradiction"><strong>${esc(x.severity)} · ${esc(x.status)}</strong><p>${esc(x.statement_a)} ↔ ${esc(x.statement_b)}</p><small>${esc(x.required_clarification)}</small></article>`).join('')||'<p>No contradictions.</p>'}</section>
  <details class="v055-section"><summary>Interview turns (${summary.interview_turns||0})</summary><pre>${json(run.interview_turns)}</pre></details>
  <details class="v055-section"><summary>AI audit (${summary.ai_calls||0})</summary><pre>${json(run.ai_audit)}</pre></details>`;
}

async function openRun(id){
 openDrawer('run-detail');const drawer=q('[data-drawer="run-detail"]');if(drawer)drawer.dataset.sessionId=id;const mount=q('[data-v055-run-detail]');mount.textContent='Loading run…';
 let run=runCache.get(id);if(!run){const data=await request(`/api/admin/diagnostics/runs/${encodeURIComponent(id)}/details`);run=data.run;runCache.set(id,run)}
 renderRunDetail(run);
}

function enhanceRuns(){
 const mount=q('[data-runs]');if(!mount)return;
 mount.querySelectorAll('.run-item').forEach((row,index)=>{
   if(row.querySelector('[data-v055-open-run]'))return;
   const summaries=row.querySelector('small')?.textContent||'',all=qa('[data-runs] .run-item');
   const sessionId=(window.__diagnosticRuns||[])[index]?.session_id;
   if(sessionId)row.insertAdjacentHTML('beforeend',`<div class="v055-actions"><button type="button" data-v055-open-run="${esc(sessionId)}" ${capabilities.edit?'':'disabled'}>Open details</button></div>`);
 });
}
async function loadRunsEnhanced(){
 const data=await request('/api/admin/diagnostics/runs');window.__diagnosticRuns=data.runs||[];
 const mount=q('[data-runs]');if(!mount)return;
 mount.innerHTML=(data.runs||[]).map(x=>`<article class="run-item v055-row"><div><strong>${esc(x.target_subject_id)} · ${esc(x.pack_id)} v${esc(x.methodology_version)}</strong><br><span>${esc(x.status)} · coverage ${x.coverage===null?'n/a':esc(x.coverage)+'%'} · findings ${esc(x.findings)} · critical ${esc(x.critical_findings)}</span><br><small>${esc(x.started_at||'Not started')}</small></div><button type="button" data-v055-open-run="${esc(x.session_id)}" ${capabilities.edit?'':'disabled'}>Open details</button></article>`).join('')||'No diagnostic runs yet.';
}

function permissionSelect(user,permission){
 const state=user.permissions?.[permission]||{},label=permission.split('.').at(-1);
 return `<label class="v055-permission"><span>${esc(label)} <small>${state.effective?'effective':'off'}${state.inherited?' · inherited':''}</small></span><select data-v055-permission data-user="${esc(user.user_id)}" data-permission="${esc(permission)}">
  ${['inherit','allow','deny'].map(mode=>`<option value="${mode}" ${state.mode===mode?'selected':''}>${mode}</option>`).join('')}</select></label>`;
}
function renderPermissions(matrix){
 const mount=q('[data-v055-permissions]');if(!mount)return;
 const permissions=['diagnostic.methodology.view','diagnostic.methodology.edit','diagnostic.methodology.publish'];
 mount.innerHTML=`<div class="v055-table-scroll"><table><thead><tr><th>User</th><th>Role</th><th>Capabilities</th></tr></thead><tbody>${(matrix.users||[]).map(user=>`<tr><td><strong>${esc(user.full_name)}</strong><br><small>${esc(user.email)} · #${esc(user.user_id)}</small></td><td>${esc(user.role)}</td><td>${permissions.map(p=>permissionSelect(user,p)).join('')}</td></tr>`).join('')}</tbody></table></div>
  <h3>Permission audit</h3>${(matrix.audit||[]).map(row=>`<article class="v055-line"><strong>${esc(row.target_name||'#'+row.target_user_id)}</strong><span>${esc(row.permission)}: ${esc(row.old_mode)} → ${esc(row.new_mode)}</span><small>by ${esc(row.actor_name||'#'+row.actor_user_id)} · ${esc(row.created_at)}</small></article>`).join('')||'<p>No permission changes recorded.</p>'}`;
}
async function openPermissions(){openDrawer('permissions');const data=await request('/api/admin/diagnostics/permissions/matrix');renderPermissions(data.matrix)}

ensureWorkbenchUi();configureAccessButton();

root.addEventListener('click',event=>{
 const packButton=event.target.closest('[data-pack]');if(packButton){currentPack=packButton.dataset.pack||'';scenarioCache=[];setTimeout(()=>renderDependencyGraph(true),120)}
 const action=event.target.closest('[data-action]')?.dataset.action;
 if(action==='scenarios')setTimeout(()=>loadScenarioManager().catch(e=>notify(e.message,true)),120);
 if(action==='runs')setTimeout(()=>loadRunsEnhanced().catch(e=>notify(e.message,true)),120);
 if(event.target.closest('[data-type="DEPENDENCY"]'))setTimeout(()=>renderDependencyGraph(true),120);
},true);

root.addEventListener('click',async event=>{
 const action=event.target.closest('[data-v055-action]')?.dataset.v055Action;
 const scenarioAction=event.target.closest('[data-v055-scenario-action]'),open=event.target.closest('[data-v055-open-run]'),why=event.target.closest('[data-v055-why]'),remove=event.target.closest('[data-v055-remove-dependency]');
 try{
  if(action==='permissions')await openPermissions();
  if(action==='new-scenario'){const form=q('[data-scenario-form]');form?.reset();q('[data-scenario-inputs]')?.querySelectorAll('[data-runtime]').forEach(x=>x.value='');q('[data-scenario-expected]')?.querySelectorAll('input[type=checkbox]').forEach(x=>x.checked=false);form?.scrollIntoView({behavior:'smooth'})}
  if(action==='runs-back'){openDrawer('runs');await loadRunsEnhanced()}
  if(action==='close-why'){const panel=q('[data-v055-why-panel]');if(panel)panel.hidden=true}
  if(scenarioAction){const id=scenarioAction.dataset.id,kind=scenarioAction.dataset.v055ScenarioAction;if(kind==='edit')editScenario(id);if(kind==='clone')openCloneDialog(id);if(kind==='run')await runScenario(id);if(kind==='delete')await deleteScenario(id)}
  if(open)await openRun(open.dataset.v055OpenRun);
  if(why){const drawer=q('[data-drawer="run-detail"]'),id=drawer?.dataset.sessionId;const run=id?runCache.get(id):[...runCache.values()].at(-1);if(run)renderWhy(run,why.dataset.v055Why)}
  if(remove)await removeDependency(remove.dataset.v055RemoveDependency);
 }catch(error){notify(error.message,true)}
});

root.addEventListener('change',async event=>{
 if(event.target.matches('[data-version]')){scenarioCache=[];setTimeout(()=>renderDependencyGraph(true),120)}
 if(event.target.matches('[data-v055-permission]')){
  try{
   const data=await post('/api/admin/diagnostics/permissions/override',{target_user_id:Number(event.target.dataset.user),permission:event.target.dataset.permission,mode:event.target.value});
   renderPermissions(data.matrix);await configureAccessButton();notify('Permission updated');
  }catch(error){notify(error.message,true);await openPermissions()}
 }
},true);

document.querySelector('[data-v055-clone-form]')?.addEventListener('submit',async event=>{
 if(event.submitter?.value==='cancel')return;event.preventDefault();
 const form=event.currentTarget,dialog=form.closest('dialog'),fd=new FormData(form),{pack,version:methodology}=context();
 try{
  await post(`/api/admin/diagnostics/packs/${encodeURIComponent(pack)}/versions/${encodeURIComponent(methodology)}/workbench/scenarios/${encodeURIComponent(fd.get('source'))}/clone`,{id:fd.get('id'),name:fd.get('name')});
  dialog.close();notify('Scenario cloned');await loadScenarioManager();
 }catch(error){notify(error.message,true)}
});

const observer=new MutationObserver(mutations=>{
 const scenarioChanged=mutations.some(m=>m.target.closest?.('[data-scenario-list]'));
 if(scenarioChanged&&!q('[data-v055-action="new-scenario"]'))setTimeout(()=>loadScenarioManager().catch(()=>{}),80);
 if((q('[data-section-title]')?.textContent||'').trim().toUpperCase()==='DEPENDENCY'&&!q('[data-v055-graph]'))setTimeout(()=>renderDependencyGraph(),80);
});
observer.observe(root,{childList:true,subtree:true});
})();
