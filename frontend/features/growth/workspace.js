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

const engagementBase=(candidateId)=>'/api/v1/growth/candidates/'+encodeURIComponent(candidateId)+'/engagement/recommendations';

const runEngagementGenerate=async(root,button)=>{
  const candidateId=root.dataset.candidateId||'';
  if(!candidateId)return;
  const status=root.querySelector('[data-growth-engagement-status]');
  button.disabled=true;
  setStatus(status,'Generating next best action…','loading');
  try{
    const response=await mutation(engagementBase(candidateId),{},root,button);
    const data=response?.data??response;
    if(data?.run?.status==='failed'){
      delete button.dataset.idempotencyKey;
      throw new Error(data?.run?.error_summary||'Engagement recommendation generation failed.');
    }
    delete button.dataset.idempotencyKey;
    setStatus(status,'Recommendation generated. Refreshing…','success');
    window.setTimeout(()=>window.location.reload(),250);
  }catch(error){
    setStatus(status,error.message||'Engagement recommendation generation failed.','error');
    button.disabled=false;
  }
};

const runEngagementDecision=async(root,form)=>{
  const candidateId=root.dataset.candidateId||'';
  const recommendationId=form.dataset.recommendationId||'';
  const decision=form.dataset.growthEngagementDecision||'';
  if(!candidateId||!recommendationId||!['accept','dismiss'].includes(decision))return;
  const status=form.querySelector('[data-growth-form-status]');
  const button=form.querySelector('button[type="submit"]');
  const reason=String(new FormData(form).get('reason')||'').trim();
  if(!reason){
    setStatus(status,'Decision reason is required.','error');
    return;
  }
  button?.setAttribute('disabled','disabled');
  setStatus(status,decision==='accept'?'Accepting recommendation…':'Dismissing recommendation…','loading');
  try{
    await mutation(
      engagementBase(candidateId)+'/'+encodeURIComponent(recommendationId)+'/'+decision,
      {reason},root,form,
    );
    delete form.dataset.idempotencyKey;
    setStatus(status,'Decision saved. Refreshing…','success');
    window.setTimeout(()=>window.location.reload(),250);
  }catch(error){
    setStatus(status,error.message||'Engagement decision failed.','error');
    button?.removeAttribute('disabled');
  }
};

const runEngagementExecution=async(root,form)=>{
  const candidateId=root.dataset.candidateId||'';
  const recommendationId=form.dataset.recommendationId||'';
  if(!candidateId||!recommendationId)return;
  const status=form.querySelector('[data-growth-form-status]');
  const button=form.querySelector('button[type="submit"]');
  const body=String(new FormData(form).get('body')||'').trim();
  if(!body){
    setStatus(status,'Approved message or call brief is required.','error');
    return;
  }
  button?.setAttribute('disabled','disabled');
  setStatus(status,'Proposing governed Action…','loading');
  try{
    const response=await mutation(
      engagementBase(candidateId)+'/'+encodeURIComponent(recommendationId)+'/execution',
      {body},root,form,
    );
    const data=response?.data??response;
    delete form.dataset.idempotencyKey;
    const action=data?.action??{};
    setStatus(status,'Action '+String(action?.status||'proposed')+'. Refreshing…','success');
    window.setTimeout(()=>window.location.reload(),300);
  }catch(error){
    setStatus(status,error.message||'Governed Action proposal failed.','error');
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
  root.querySelectorAll('[data-growth-engagement-generate]').forEach((button)=>{
    button.addEventListener('click',()=>runEngagementGenerate(root,button));
  });
  root.querySelectorAll('[data-growth-engagement-decision]').forEach((form)=>{
    form.addEventListener('submit',(event)=>{
      event.preventDefault();
      runEngagementDecision(root,event.currentTarget);
    });
  });
  root.querySelector('[data-growth-engagement-execution]')?.addEventListener('submit',(event)=>{
    event.preventDefault();
    runEngagementExecution(root,event.currentTarget);
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

  root.querySelector('[data-growth-signal-feed-create]')?.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const form=event.currentTarget;
    const status=form.querySelector('[data-growth-form-status]');
    const button=form.querySelector('button[type="submit"]');
    const values=new FormData(form);
    const confidence=Number(values.get('confidence')??0.75);
    const payload={
      name:String(values.get('name')||'').trim(),
      url:String(values.get('url')||'').trim(),
      subject_type:String(values.get('subject_type')||'').trim(),
      subject_id:String(values.get('subject_id')||'').trim(),
      signal_type:String(values.get('signal_type')||'').trim(),
      confidence,
      enabled:values.get('enabled')!==null,
    };
    if(!payload.name||!payload.url||!payload.subject_type||!payload.subject_id||!payload.signal_type){
      setStatus(status,'All feed mapping fields are required.','error');
      return;
    }
    if(!Number.isFinite(confidence)||confidence<0||confidence>1){
      setStatus(status,'Confidence must be between 0 and 1.','error');
      return;
    }

    button?.setAttribute('disabled','disabled');
    setStatus(status,'Adding feed…','loading');
    try{
      await mutation('/api/v1/growth/signal-feeds',payload,root,form);
      delete form.dataset.idempotencyKey;
      setStatus(status,'Feed added. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(status,error.message||'Feed creation failed.','error');
      button?.removeAttribute('disabled');
    }
  });

  root.querySelectorAll('[data-growth-signal-feed-toggle]').forEach((form)=>{
    form.addEventListener('submit',async(event)=>{
      event.preventDefault();
      const feedId=form.dataset.feedId||'';
      const action=form.dataset.action||'';
      if(!feedId||!['enable','disable'].includes(action))return;
      const status=form.querySelector('[data-growth-form-status]');
      const button=form.querySelector('button[type="submit"]');
      button?.setAttribute('disabled','disabled');
      setStatus(status,action==='enable'?'Enabling…':'Disabling…','loading');
      try{
        await mutation('/api/v1/growth/signal-feeds/'+encodeURIComponent(feedId)+'/'+action,{},root,form);
        delete form.dataset.idempotencyKey;
        setStatus(status,'Feed updated. Refreshing…','success');
        window.setTimeout(()=>window.location.reload(),250);
      }catch(error){
        setStatus(status,error.message||'Feed update failed.','error');
        button?.removeAttribute('disabled');
      }
    });
  });

  root.querySelector('[data-growth-collector-alert-create]')?.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const form=event.currentTarget;
    const status=form.querySelector('[data-growth-form-status]');
    const button=form.querySelector('button[type="submit"]');
    const values=new FormData(form);
    const payload={
      recipient_email:String(values.get('recipient_email')||'').trim().toLowerCase(),
      recipient_name:String(values.get('recipient_name')||'').trim()||null,
      locale:String(values.get('locale')||'en').trim(),
      enabled:values.get('enabled')!==null,
    };
    if(!payload.recipient_email||!payload.locale){
      setStatus(status,'Email and locale are required.','error');
      return;
    }

    button?.setAttribute('disabled','disabled');
    setStatus(status,'Adding incident alert recipient…','loading');
    try{
      await mutation('/api/v1/growth/collector-alert-subscriptions',payload,root,form);
      delete form.dataset.idempotencyKey;
      setStatus(status,'Alert recipient added. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(status,error.message||'Alert recipient creation failed.','error');
      button?.removeAttribute('disabled');
    }
  });

  root.querySelectorAll('[data-growth-collector-alert-toggle]').forEach((form)=>{
    form.addEventListener('submit',async(event)=>{
      event.preventDefault();
      const subscriptionId=form.dataset.subscriptionId||'';
      const action=form.dataset.action||'';
      if(!subscriptionId||!['enable','disable'].includes(action))return;
      const status=form.querySelector('[data-growth-form-status]');
      const button=form.querySelector('button[type="submit"]');
      button?.setAttribute('disabled','disabled');
      setStatus(status,action==='enable'?'Enabling alerts…':'Disabling alerts…','loading');
      try{
        await mutation('/api/v1/growth/collector-alert-subscriptions/'+encodeURIComponent(subscriptionId)+'/'+action,{},root,form);
        delete form.dataset.idempotencyKey;
        setStatus(status,'Alert recipient updated. Refreshing…','success');
        window.setTimeout(()=>window.location.reload(),250);
      }catch(error){
        setStatus(status,error.message||'Alert recipient update failed.','error');
        button?.removeAttribute('disabled');
      }
    });
  });

  root.querySelector('[data-growth-json-source-create]')?.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const form=event.currentTarget;
    const status=form.querySelector('[data-growth-form-status]');
    const button=form.querySelector('button[type="submit"]');
    const values=new FormData(form);
    const confidence=Number(values.get('confidence')??0.75);
    const authMode=String(values.get('auth_mode')||'').trim();
    const apiKeyHeader=String(values.get('api_key_header')||'').trim();
    const payload={
      name:String(values.get('name')||'').trim(),
      url:String(values.get('url')||'').trim(),
      auth_mode:authMode,
      credential_reference:String(values.get('credential_reference')||'').trim(),
      api_key_header:authMode==='api_key_header'?apiKeyHeader:null,
      subject_type:String(values.get('subject_type')||'').trim(),
      subject_id:String(values.get('subject_id')||'').trim(),
      signal_type:String(values.get('signal_type')||'').trim(),
      confidence,
      enabled:values.get('enabled')!==null,
    };
    if(!payload.name||!payload.url||!payload.auth_mode||!payload.credential_reference||!payload.subject_type||!payload.subject_id||!payload.signal_type){
      setStatus(status,'All JSON source mapping and credential fields are required.','error');
      return;
    }
    if(!['bearer','api_key_header'].includes(authMode)){
      setStatus(status,'Unsupported authentication mode.','error');
      return;
    }
    if(authMode==='api_key_header'&&!/^X-[A-Za-z0-9-]{1,63}$/.test(apiKeyHeader)){
      setStatus(status,'API key header must be a safe X-* header name.','error');
      return;
    }
    if(!Number.isFinite(confidence)||confidence<0||confidence>1){
      setStatus(status,'Confidence must be between 0 and 1.','error');
      return;
    }

    button?.setAttribute('disabled','disabled');
    setStatus(status,'Adding JSON source…','loading');
    try{
      await mutation('/api/v1/growth/json-signal-sources',payload,root,form);
      delete form.dataset.idempotencyKey;
      setStatus(status,'JSON source added. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(status,error.message||'JSON source creation failed.','error');
      button?.removeAttribute('disabled');
    }
  });

  root.querySelectorAll('[data-growth-json-source-toggle]').forEach((form)=>{
    form.addEventListener('submit',async(event)=>{
      event.preventDefault();
      const sourceId=form.dataset.sourceId||'';
      const action=form.dataset.action||'';
      if(!sourceId||!['enable','disable'].includes(action))return;
      const status=form.querySelector('[data-growth-form-status]');
      const button=form.querySelector('button[type="submit"]');
      button?.setAttribute('disabled','disabled');
      setStatus(status,action==='enable'?'Enabling…':'Disabling…','loading');
      try{
        await mutation('/api/v1/growth/json-signal-sources/'+encodeURIComponent(sourceId)+'/'+action,{},root,form);
        delete form.dataset.idempotencyKey;
        setStatus(status,'JSON source updated. Refreshing…','success');
        window.setTimeout(()=>window.location.reload(),250);
      }catch(error){
        setStatus(status,error.message||'JSON source update failed.','error');
        button?.removeAttribute('disabled');
      }
    });
  });
};

const optimizationEndpoint=(recommendationId,suffix='')=>{
  const base='/api/v1/growth/learning/optimization/recommendations';
  return recommendationId
    ? base+'/'+encodeURIComponent(recommendationId)+(suffix?'/'+suffix:'')
    : base;
};

const initGrowthLearning=(root)=>{
  const status=root.querySelector('[data-growth-optimization-status]');

  root.querySelector('[data-growth-optimization-generate]')?.addEventListener('click',async(event)=>{
    const button=event.currentTarget;
    button.disabled=true;
    setStatus(status,'Generating evidence-bound optimization…','loading');
    try{
      const response=await mutation(optimizationEndpoint(''),{},root,button);
      const data=response?.data??response;
      if(data?.run?.status==='failed'){
        delete button.dataset.idempotencyKey;
        throw new Error(data?.run?.error_summary||'Optimization generation failed.');
      }
      delete button.dataset.idempotencyKey;
      setStatus(status,'Recommendation generated. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(status,error.message||'Optimization generation failed.','error');
      button.disabled=false;
    }
  });

  root.querySelectorAll('[data-growth-optimization-decision]').forEach((form)=>{
    form.addEventListener('submit',async(event)=>{
      event.preventDefault();
      const decision=form.dataset.growthOptimizationDecision||'';
      const recommendationId=form.dataset.recommendationId||'';
      if(!recommendationId||!['accept','dismiss'].includes(decision))return;
      const localStatus=form.querySelector('[data-growth-form-status]')||status;
      const button=form.querySelector('button[type="submit"]');
      const reason=String(new FormData(form).get('reason')||'').trim();
      if(!reason){
        setStatus(localStatus,'Decision reason is required.','error');
        return;
      }
      button?.setAttribute('disabled','disabled');
      setStatus(localStatus,decision==='accept'?'Accepting recommendation…':'Dismissing recommendation…','loading');
      try{
        await mutation(optimizationEndpoint(recommendationId,decision),{reason},root,form);
        delete form.dataset.idempotencyKey;
        setStatus(localStatus,'Decision saved. Refreshing…','success');
        window.setTimeout(()=>window.location.reload(),250);
      }catch(error){
        setStatus(localStatus,error.message||'Optimization decision failed.','error');
        button?.removeAttribute('disabled');
      }
    });
  });

  root.querySelector('[data-growth-optimization-materialize]')?.addEventListener('click',async(event)=>{
    const button=event.currentTarget;
    const recommendationId=button.dataset.recommendationId||'';
    if(!recommendationId)return;
    button.disabled=true;
    setStatus(status,'Creating draft revision…','loading');
    try{
      const response=await mutation(optimizationEndpoint(recommendationId,'materialize'),{},root,button);
      const data=response?.data??response;
      const recommendation=data?.recommendation??{};
      delete button.dataset.idempotencyKey;
      if(recommendation?.status==='stale'){
        setStatus(status,'Recommendation became stale because the base policy changed. Refreshing…','warning');
      }else{
        setStatus(status,'Draft revision created. Activation remains separate. Refreshing…','success');
      }
      window.setTimeout(()=>window.location.reload(),350);
    }catch(error){
      setStatus(status,error.message||'Optimization materialization failed.','error');
      button.disabled=false;
    }
  });
};


const experimentEndpoint=(experimentId,suffix='')=>{
  const base='/api/v1/growth/experiments';
  return experimentId
    ? base+'/'+encodeURIComponent(experimentId)+(suffix?'/'+suffix:'')
    : base;
};

const experimentVariant=(row)=>{
  const key=String(row.querySelector('[data-variant-key]')?.value||'').trim().toLowerCase();
  const name=String(row.querySelector('[data-variant-name]')?.value||'').trim();
  const weight=Number(row.querySelector('[data-variant-weight]')?.value||0);
  const rawConfig=String(row.querySelector('[data-variant-config]')?.value||'').trim();
  if(!key||!name||!Number.isInteger(weight)||weight<1)throw new Error('Each variant requires key, name and positive integer weight.');
  let config;
  try{config=JSON.parse(rawConfig);}catch{throw new Error('Variant configuration must be valid JSON.');}
  if(!config||Array.isArray(config)||typeof config!=='object'||Object.keys(config).length===0){
    throw new Error('Variant configuration must be a non-empty JSON object.');
  }
  return {key,name,allocation_weight:weight,config};
};

const bindVariantRemoval=(root)=>{
  root.querySelectorAll('[data-growth-experiment-variant-remove]').forEach((button)=>{
    if(button.dataset.bound==='true')return;
    button.dataset.bound='true';
    button.addEventListener('click',()=>{
      const rows=root.querySelectorAll('[data-growth-experiment-variant]');
      if(rows.length<=2){
        setStatus(root.querySelector('[data-growth-form-status]'),'An experiment requires at least two variants.','error');
        return;
      }
      button.closest('[data-growth-experiment-variant]')?.remove();
    });
  });
};

const initGrowthExperiments=(root)=>{
  const form=root.querySelector('[data-growth-experiment-create]');
  if(!form)return;

  bindVariantRemoval(root);
  root.querySelector('[data-growth-experiment-variant-add]')?.addEventListener('click',()=>{
    const container=root.querySelector('[data-growth-experiment-variants]');
    const template=root.querySelector('[data-growth-experiment-variant-template]');
    if(!container||!template)return;
    const count=container.querySelectorAll('[data-growth-experiment-variant]').length;
    const status=form.querySelector('[data-growth-form-status]');
    if(count>=12){
      setStatus(status,'An experiment supports at most twelve variants.','error');
      return;
    }
    container.append(template.content.cloneNode(true));
    bindVariantRemoval(root);
  });

  form.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const status=form.querySelector('[data-growth-form-status]');
    const button=form.querySelector('button[type="submit"]');
    const values=new FormData(form);
    let variants;
    try{
      variants=[...form.querySelectorAll('[data-growth-experiment-variant]')].map(experimentVariant);
    }catch(error){
      setStatus(status,error.message||'Variant configuration is invalid.','error');
      return;
    }
    if(variants.length<2||variants.length>12){
      setStatus(status,'An experiment requires between two and twelve variants.','error');
      return;
    }

    const data={
      name:String(values.get('name')||'').trim(),
      hypothesis:String(values.get('hypothesis')||'').trim(),
      dimension:String(values.get('dimension')||'').trim(),
      primary_outcome:String(values.get('primary_outcome')||'').trim(),
      variants,
    };

    button?.setAttribute('disabled','disabled');
    setStatus(status,'Creating experiment…','loading');
    try{
      const response=await mutation(experimentEndpoint(''),data,root,form);
      const payload=response?.data??response;
      const experimentId=payload?.experiment?.experiment_id||'';
      delete form.dataset.idempotencyKey;
      if(!experimentId)throw new Error('Created experiment did not return an identifier.');
      setStatus(status,'Experiment created. Opening…','success');
      window.setTimeout(()=>{window.location.href='/growth/experiments/'+encodeURIComponent(experimentId);},250);
    }catch(error){
      setStatus(status,error.message||'Experiment creation failed.','error');
      button?.removeAttribute('disabled');
    }
  });
};

const initGrowthExperiment=(root)=>{
  const experimentId=root.dataset.experimentId||'';
  if(!experimentId)return;
  const status=root.querySelector('[data-growth-experiment-status]');

  root.querySelectorAll('[data-growth-experiment-transition]').forEach((button)=>{
    button.addEventListener('click',async()=>{
      const transition=button.dataset.growthExperimentTransition||'';
      if(!['start','pause','resume','complete','archive'].includes(transition))return;
      button.disabled=true;
      setStatus(status,transition.charAt(0).toUpperCase()+transition.slice(1)+' experiment…','loading');
      try{
        await mutation(experimentEndpoint(experimentId,transition),{},root,button);
        delete button.dataset.idempotencyKey;
        setStatus(status,'Experiment updated. Refreshing…','success');
        window.setTimeout(()=>window.location.reload(),250);
      }catch(error){
        setStatus(status,error.message||'Experiment transition failed.','error');
        button.disabled=false;
      }
    });
  });

  root.querySelector('[data-growth-experiment-assign]')?.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const form=event.currentTarget;
    const localStatus=form.querySelector('[data-growth-form-status]')||status;
    const button=form.querySelector('button[type="submit"]');
    const values=new FormData(form);
    const candidateId=String(values.get('candidate_id')||'').trim();
    const variantKey=String(values.get('variant_key')||'').trim();
    if(!candidateId){
      setStatus(localStatus,'Candidate ID is required.','error');
      return;
    }

    button?.setAttribute('disabled','disabled');
    setStatus(localStatus,'Assigning Candidate…','loading');
    try{
      await mutation(
        experimentEndpoint(experimentId,'assignments/'+encodeURIComponent(candidateId)),
        {variant_key:variantKey||null},
        root,
        form,
      );
      delete form.dataset.idempotencyKey;
      setStatus(localStatus,'Candidate assigned. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(localStatus,error.message||'Candidate assignment failed.','error');
      button?.removeAttribute('disabled');
    }
  });
};

const initGrowthSettings=(root)=>{
  const limitForm=root.querySelector('[data-growth-limit-settings]');
  if(limitForm)limitForm.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const status=limitForm.querySelector('[data-growth-form-status]');
    const button=limitForm.querySelector('button[type="submit"]');
    const values=new FormData(limitForm);
    const dailyLimit=Number.parseInt(String(values.get('daily_limit')||''),10);
    const cooldown=Number.parseInt(String(values.get('contact_cooldown_hours')||''),10);
    const emailLimit=Number.parseInt(String(values.get('email_daily_limit')||''),10);
    const linkedInLimit=Number.parseInt(String(values.get('linkedin_daily_limit')||''),10);
    const phoneLimit=Number.parseInt(String(values.get('phone_daily_limit')||''),10);
    const reason=String(values.get('reason')||'').trim();
    const channelLimits=[emailLimit,linkedInLimit,phoneLimit];
    if(!Number.isInteger(dailyLimit)||dailyLimit<1||!Number.isInteger(cooldown)||cooldown<1||channelLimits.some((value)=>!Number.isInteger(value)||value<0)||!reason){
      setStatus(status,'Daily limit, channel quotas, cooldown and change reason are required.','error');
      return;
    }
    button?.setAttribute('disabled','disabled');
    setStatus(status,'Saving tenant outreach limits…','loading');
    try{
      await mutation('/api/v1/growth/engagement/limits',{
        daily_limit:dailyLimit,
        contact_cooldown_hours:cooldown,
        channel_daily_limits:{email:emailLimit,linkedin:linkedInLimit,phone:phoneLimit},
        reason,
      },root,limitForm);
      delete limitForm.dataset.idempotencyKey;
      setStatus(status,'Tenant limits saved. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(status,error.message||'Tenant outreach limits update failed.','error');
      button?.removeAttribute('disabled');
    }
  });

  const activationForm=root.querySelector('[data-growth-activation-settings]');
  if(activationForm)activationForm.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const status=activationForm.querySelector('[data-growth-form-status]');
    const button=activationForm.querySelector('button[type="submit"]');
    const values=new FormData(activationForm);
    const email=String(values.get('email_mode')||'').trim();
    const linkedin=String(values.get('linkedin_mode')||'').trim();
    const phone=String(values.get('phone_mode')||'').trim();
    const reason=String(values.get('reason')||'').trim();
    const allowed=new Set(['blocked','approval_required','auto']);
    if(!allowed.has(email)||!allowed.has(linkedin)||!allowed.has(phone)||!reason){
      setStatus(status,'Channel activation modes and change reason are required.','error');
      return;
    }
    button?.setAttribute('disabled','disabled');
    setStatus(status,'Saving outreach activation policy…','loading');
    try{
      await mutation('/api/v1/growth/engagement/activation',{channel_modes:{email,linkedin,phone},reason},root,activationForm);
      delete activationForm.dataset.idempotencyKey;
      setStatus(status,'Activation policy saved. Refreshing…','success');
      window.setTimeout(()=>window.location.reload(),250);
    }catch(error){
      setStatus(status,error.message||'Outreach activation policy update failed.','error');
      button?.removeAttribute('disabled');
    }
  });
};

const boot=()=>{
  document.querySelectorAll('[data-growth-candidate]').forEach(initGrowthCandidate);
  document.querySelectorAll('[data-growth-collectors]').forEach(initGrowthCollectors);
  document.querySelectorAll('[data-growth-learning]').forEach(initGrowthLearning);
  document.querySelectorAll('[data-growth-experiments]').forEach(initGrowthExperiments);
  document.querySelectorAll('[data-growth-experiment]').forEach(initGrowthExperiment);
  document.querySelectorAll('[data-growth-settings]').forEach(initGrowthSettings);
};

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
else boot();
