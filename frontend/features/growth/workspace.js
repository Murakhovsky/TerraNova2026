import { requestJson } from '../../api/client.js';
import './workspace.css';

const createMutationKey=()=>globalThis.crypto?.randomUUID?.()
  || 'growth-'+Date.now()+'-'+Math.random().toString(16).slice(2);

const setStatus=(target,message,state='neutral')=>{
  if(!target)return;
  target.textContent=message;
  target.dataset.state=state;
};

const mutation=async(endpoint,data,root,control)=>{
  const csrf=root.dataset.csrf||'';
  control.dataset.idempotencyKey ||= createMutationKey();
  return requestJson(endpoint,{
    method:'POST',
    headers:{
      'Content-Type':'application/json',
      'X-CSRF-Token':csrf,
      'X-Idempotency-Key':control.dataset.idempotencyKey,
    },
    body:JSON.stringify(data||{}),
  });
};

const runAction=async(root,button)=>{
  const candidateId=root.dataset.candidateId||'';
  const action=button.dataset.growthAction||'';
  const status=button.closest('.tn-ui-panel')?.querySelector('[data-growth-action-status]')
    || root.querySelector('[data-growth-action-status]');
  if(!candidateId||!action)return;

  let endpoint='';
  if(action==='research-generate')endpoint='/api/v1/growth/candidates/'+encodeURIComponent(candidateId)+'/research/proposals';
  if(action==='research-accept'){
    const proposalId=button.dataset.proposalId||'';
    if(!proposalId)return;
    endpoint='/api/v1/growth/candidates/'+encodeURIComponent(candidateId)+'/research/proposals/'+encodeURIComponent(proposalId)+'/accept';
  }
  if(action==='handoff-dispatch')endpoint='/api/v1/growth/candidates/'+encodeURIComponent(candidateId)+'/handoff/dispatch';
  if(!endpoint)return;

  button.disabled=true;
  setStatus(status,'Running…','loading');
  try{
    const response=await mutation(endpoint,{},root,button);
    const data=response?.data??response;
    const failedRun=data?.run?.status==='failed';
    const failedAttempt=data?.attempt?.status==='failed';
    if(failedRun||failedAttempt){
      delete button.dataset.idempotencyKey;
      throw new Error(data?.run?.error_summary||data?.attempt?.error_summary||'Growth runtime operation failed.');
    }
    delete button.dataset.idempotencyKey;
    setStatus(status,'Completed. Refreshing…','success');
    window.setTimeout(()=>window.location.reload(),250);
  }catch(error){
    setStatus(status,error.message||'Growth operation failed.','error');
    button.disabled=false;
  }
};

const runPrepare=async(root,form)=>{
  const candidateId=root.dataset.candidateId||'';
  if(!candidateId)return;
  const status=form.querySelector('[data-growth-form-status]');
  const button=form.querySelector('button[type="submit"]');
  const data=Object.fromEntries([...new FormData(form).entries()].map(([key,value])=>[
    key,typeof value==='string'?value.trim():value,
  ]));
  button?.setAttribute('disabled','disabled');
  setStatus(status,'Preparing handoff…','loading');
  try{
    await mutation(
      '/api/v1/growth/candidates/'+encodeURIComponent(candidateId)+'/handoff/prepare',
      data,root,form,
    );
    delete form.dataset.idempotencyKey;
    setStatus(status,'Handoff package prepared.','success');
    window.setTimeout(()=>window.location.reload(),250);
  }catch(error){
    setStatus(status,error.message||'Handoff preparation failed.','error');
    button?.removeAttribute('disabled');
  }
};

const initGrowthCandidate=(root)=>{
  root.querySelectorAll('[data-growth-action]').forEach((button)=>{
    button.addEventListener('click',()=>runAction(root,button));
  });
  root.querySelector('[data-growth-handoff-prepare]')?.addEventListener('submit',(event)=>{
    event.preventDefault();
    runPrepare(root,event.currentTarget);
  });
};

const initGrowthCollectors=(root)=>{
  root.querySelectorAll('[data-growth-collector-run]').forEach((form)=>{
    form.addEventListener('submit',async(event)=>{
      event.preventDefault();
      const collectorName=form.dataset.collectorName||'';
      if(!collectorName)return;

      const status=form.querySelector('[data-growth-form-status]');
      const button=form.querySelector('button[type="submit"]');
      const raw=Object.fromEntries([...new FormData(form).entries()].map(([key,value])=>[
        key,typeof value==='string'?value.trim():value,
      ]));
      const data={
        cursor:raw.cursor||null,
        limit:Number(raw.limit||100),
      };

      button?.setAttribute('disabled','disabled');
      setStatus(status,'Running collector…','loading');
      try{
        const response=await mutation(
          '/api/v1/growth/collectors/'+encodeURIComponent(collectorName)+'/run',
          data,root,form,
        );
        const run=response?.data??response;
        delete form.dataset.idempotencyKey;
        const runStatus=String(run?.status||'').toLowerCase();
        if(runStatus==='failed'){
          throw new Error(run?.error_summary||'Collector failed.');
        }
        if(runStatus==='partial'){
          setStatus(
            status,
            'Partial: '+Number(run?.accepted_count||0)+' accepted, '+Number(run?.duplicate_count||0)+' duplicates, '+Number(run?.failed_count||0)+' failed.',
            'warning',
          );
        }else{
          setStatus(
            status,
            'Completed: '+Number(run?.accepted_count||0)+' accepted, '+Number(run?.duplicate_count||0)+' duplicates.',
            'success',
          );
        }
        window.setTimeout(()=>window.location.reload(),500);
      }catch(error){
        setStatus(status,error.message||'Collector run failed.','error');
        button?.removeAttribute('disabled');
      }
    });
  });
};

const boot=()=>{
  document.querySelectorAll('[data-growth-candidate]').forEach(initGrowthCandidate);
  document.querySelectorAll('[data-growth-collectors]').forEach(initGrowthCollectors);
};

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
else boot();
