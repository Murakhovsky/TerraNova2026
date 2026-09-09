(()=>{
const root=document.querySelector('[data-studio]');
if(!root)return;
const q=s=>root.querySelector(s),qa=s=>[...root.querySelectorAll(s)];
let currentPack='',criteriaCacheKey='',criteria=[],methodologyEntities=[];

function notify(message,error=true){
 const toast=q('[data-toast]');
 if(!toast)return;
 toast.textContent=message;
 toast.classList.toggle('error',error);
 toast.hidden=false;
 setTimeout(()=>toast.hidden=true,3500);
}
function groupChildren(group){return group.querySelector(':scope > .condition-children')}
function groupSelect(group){return group.querySelector(':scope > .condition-head [data-group-type]')}
function syncNotGroup(group){
 const select=groupSelect(group),children=groupChildren(group);if(!select||!children)return;
 const isNot=select.value==='not',full=isNot&&children.children.length>=1;
 group.querySelectorAll(':scope > .condition-head [data-add-leaf],:scope > .condition-head [data-add-group]').forEach(button=>button.disabled=full);
 group.dataset.v054GroupType=select.value;
}
function syncAllGroups(){qa('.condition-node:not(.condition-root)').forEach(syncNotGroup)}
function invalidNotGroup(){return qa('.condition-node:not(.condition-root)').find(group=>groupSelect(group)?.value==='not'&&groupChildren(group)?.children.length!==1)}

root.addEventListener('click',event=>{
 const add=event.target.closest('[data-add-leaf],[data-add-group]');
 if(!add)return;
 const group=add.closest('.condition-node'),select=group&&groupSelect(group),children=group&&groupChildren(group);
 if(select?.value==='not'&&children&&children.children.length>=1){
  event.preventDefault();event.stopImmediatePropagation();
  notify('NOT accepts exactly one condition or group. Remove the existing child first.');
 }
},true);

root.addEventListener('change',event=>{
 if(event.target.matches('[data-group-type]')){
  const group=event.target.closest('.condition-node'),children=groupChildren(group),previous=group.dataset.v054GroupType||group.dataset.group||'all';
  if(event.target.value==='not'&&children.children.length>1){
   event.target.value=previous;
   notify('NOT accepts exactly one child. Remove extra conditions before switching this group to NOT.');
  }
  syncNotGroup(group);
 }
 if(event.target.matches('[data-version]')){criteriaCacheKey='';criteria=[];methodologyEntities=[];queueAssessmentSubjects();}
},true);

const editor=q('[data-editor]');
if(editor)editor.addEventListener('submit',event=>{
 if(invalidNotGroup()){
  event.preventDefault();event.stopImmediatePropagation();
  notify('A NOT group must contain exactly one condition or nested group.');
 }
},true);

async function loadCriteria(){
 const version=q('[data-version]')?.value||'';
 if(!currentPack||!version)return [];
 const key=currentPack+'|'+version;if(criteriaCacheKey===key)return criteria;
 const response=await fetch(`/api/admin/diagnostics/packs/${encodeURIComponent(currentPack)}/versions/${encodeURIComponent(version)}/entities`,{headers:{'Accept':'application/json'}});
 const payload=await response.json();
 if(!response.ok||!payload.ok)throw new Error(payload.error||'Unable to load methodology entities.');
 methodologyEntities=payload.data?.entities||[];criteria=methodologyEntities.filter(row=>row.entity_type==='CRITERION');criteriaCacheKey=key;return criteria;
}
function conditionSubjects(node,out=[]){
 if(!node||typeof node!=='object')return out;
 if(node.subject||node.field){out.push(node.subject||node.field);return out;}
 for(const key of ['all','any'])for(const child of node[key]||[])conditionSubjects(child,out);
 if(node.not)conditionSubjects(node.not,out);
 return out;
}
async function addAssessmentSubjects(){
 if((q('[data-section-title]')?.textContent||'').trim().toUpperCase()!=='RULE')return;
 const rows=await loadCriteria(),selects=qa('[data-condition-field="conditions"] select[data-condition="subject"]');
 selects.forEach(select=>{
  rows.forEach(row=>{const label=row.payload?.name||row.entity_id;['score','coverage','confidence'].forEach(property=>{const value=`assessment.${row.entity_id}.${property}`;if([...select.options].some(option=>option.value===value))return;const option=document.createElement('option');option.value=value;option.textContent=`${label} · ${property}`;select.append(option);});});
  const empty=select.querySelector('option[value=""]');if(empty)empty.textContent='Select fact, metric or assessment';
 });
 const state=(q('[data-edit-state]')?.textContent||'').trim();
 if(state.startsWith('Editing ')){const id=state.slice(8),rule=methodologyEntities.find(row=>row.entity_type==='RULE'&&row.entity_id===id),subjects=conditionSubjects(rule?.payload?.conditions);selects.forEach((select,index)=>{const expected=subjects[index]||'';if(expected.startsWith('assessment.'))select.value=expected;});}
}
let assessmentTimer=0;
function queueAssessmentSubjects(){clearTimeout(assessmentTimer);assessmentTimer=setTimeout(()=>addAssessmentSubjects().catch(error=>notify(error.message)),40)}

root.addEventListener('click',event=>{
 const packButton=event.target.closest('[data-pack]');
 if(packButton){currentPack=packButton.dataset.pack||'';criteriaCacheKey='';criteria=[];methodologyEntities=[];queueAssessmentSubjects();}
 if(event.target.closest('[data-type="RULE"],[data-add-leaf],[data-add-group]'))queueAssessmentSubjects();
},true);

const observer=new MutationObserver(()=>{syncAllGroups();queueAssessmentSubjects()});
observer.observe(root,{childList:true,subtree:true});
syncAllGroups();
})();
