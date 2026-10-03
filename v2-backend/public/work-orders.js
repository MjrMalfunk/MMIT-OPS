(function () {
  'use strict';
  const html = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const label = value => String(value).toLowerCase().replaceAll('_', ' ').replace(/^./, char => char.toUpperCase());
  const statuses = ['REQUESTED','ASSIGNED','SCHEDULED','IN_PROGRESS','COMPLETED','INVOICED','PAID','CANCELLED'];
  const canEdit = () => ['OWNER','ADMIN','OPERATOR'].includes(window.opsUser?.role);
  const locked = workOrder => ['INVOICED','PAID'].includes(workOrder.status);
  let rows = [], page = 0;
  const perPage = 20;
  const host = document.getElementById('orders');
  const heading = host.closest('section').querySelector('h2');
  const toolbar = document.createElement('div');toolbar.className='work-order-toolbar';
  heading.before(toolbar);toolbar.append(heading);
  const create = document.createElement('button');create.type='button';create.textContent='New work order';create.id='newWorkOrder';toolbar.append(create);
  const filters = document.createElement('div');filters.className='work-order-filters';
  filters.innerHTML=`<label for="workOrderSearch">Search jobs<input id="workOrderSearch" type="search" placeholder="Reference, title, or client"></label><label for="workOrderStatus">Status<select id="workOrderStatus"><option value="">All statuses</option>${statuses.map(status=>`<option value="${status}">${html(label(status))}</option>`).join('')}</select></label>`;
  host.before(filters);
  const pager = document.createElement('div');pager.className='work-order-pager';
  pager.innerHTML='<p id="workOrderResults" class="muted" role="status" aria-live="polite"></p><div class="actions"><button id="workOrderPrevious" class="secondary" type="button">Previous</button><button id="workOrderNext" class="secondary" type="button">Next</button></div>';
  host.after(pager);
  const search = filters.querySelector('input'), status = filters.querySelector('select');
  const results = pager.querySelector('p'), previous = pager.querySelector('#workOrderPrevious'), next = pager.querySelector('#workOrderNext');

  function renderList() {
    create.disabled=!canEdit();
    const term=search.value.trim().toLocaleLowerCase();
    const matching=rows.filter(row=>(!status.value||row.status===status.value)&&(!term||[row.sourceReference,row.title,row.client?.name,row.id].some(value=>String(value??'').toLocaleLowerCase().includes(term))));
    matching.sort((a,b)=>Number(b.id)-Number(a.id));
    page=Math.min(page,Math.max(0,Math.ceil(matching.length/perPage)-1));
    const start=page*perPage, visible=matching.slice(start,start+perPage);
    host.innerHTML=visible.length?`<table><thead><tr><th>Work order</th><th>Status</th><th>Scheduled</th></tr></thead><tbody>${visible.map(row=>`<tr><td><button type="button" class="link-button" data-work-order-id="${html(row.id)}">${html(row.title)}</button><small>${html(row.sourceReference)} · ${html(label(row.source))}</small>${row.client?.name?`<small>${html(row.client.name)}</small>`:''}</td><td><span class="pill">${html(label(row.status))}</span></td><td>${html(row.scheduledAt?new Date(row.scheduledAt).toLocaleDateString():'Not scheduled')}</td></tr>`).join('')}</tbody></table>`:'<div class="empty">'+(rows.length?'No jobs match these filters.':'No work orders yet. Create your first job above.')+'</div>';
    results.textContent=matching.length?`${start+1}–${start+visible.length} of ${matching.length} matching jobs · ${rows.length} total`:`0 matching jobs · ${rows.length} total`;
    previous.disabled=page===0;next.disabled=start+perPage>=matching.length;
    host.querySelectorAll('[data-work-order-id]').forEach(button=>button.onclick=()=>window.openWorkOrder(button.dataset.workOrderId));
  }
  search.oninput=status.onchange=()=>{page=0;renderList()};
  previous.onclick=()=>{page--;renderList()};next.onclick=()=>{page++;renderList()};
  window.opsWorkOrders={render(data){rows=Array.isArray(data)?data:[];renderList()}};
  window.opsWorkOrders.render(window.opsWorkOrderRows||[]);

  // Dates shown in a datetime-local input remain in the operator's local timezone.
  function localDateTime(value){if(!value)return '';const date=new Date(value);if(Number.isNaN(date.getTime()))return '';const pad=n=>String(n).padStart(2,'0');return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`}
  function schedule(value){if(!value)return null;const date=new Date(value);if(Number.isNaN(date.getTime()))throw Error('Enter a valid scheduled date and time.');return date.toISOString()}
  const input=(name,text,value='',extra='')=>`<label>${html(text)}<input name="${name}" value="${html(value??'')}" ${extra}></label>`;
  const notes=value=>`<label class="full">Notes<textarea name="notes" rows="3" maxlength="10000">${html(value)}</textarea></label>`;
  const decimal=(name,text,value,extra='')=>input(name,text,value,`type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" ${extra}`);
  function payFields(workOrder={}){
    return `<fieldset><legend>Pay terms</legend><div class="work-order-pay-grid"><label>Pay type<select name="payType">${['FIXED','HOURLY','BLENDED'].map(type=>`<option value="${type}" ${workOrder.payType===type?'selected':''}>${label(type)}</option>`).join('')}</select></label>
      <div data-pay="FIXED BLENDED">${decimal('payBaseAmount','Fixed / base amount',workOrder.payBaseAmount??(workOrder.payType==='FIXED'?workOrder.grossPay:null))}</div>
      <div data-pay="BLENDED">${input('payBaseHours','Hours included in base',workOrder.payBaseHours,'type="number" min="0.01" max="168" step="0.01"')}</div>
      <div data-pay="HOURLY BLENDED">${decimal('payHourlyRate','Hourly rate',workOrder.payHourlyRate)}</div>
      <div data-pay="HOURLY BLENDED">${input('payHoursCap','Hour limit',workOrder.payHoursCap,'type="number" min="0.01" max="168" step="0.01"')}</div>
      <div data-pay="HOURLY BLENDED">${decimal('grossPay','Advertised / maximum pay (optional)',workOrder.grossPay)}</div></div>
      <p class="muted work-order-notice" data-pay-help></p></fieldset>`;
  }
  function setupPay(form){
    const update=()=>{
      const type=form.elements.payType.value;
      form.querySelectorAll('[data-pay]').forEach(node=>{node.hidden=!node.dataset.pay.split(' ').includes(type);node.querySelectorAll('input').forEach(input=>{input.disabled=node.hidden;input.required=!node.hidden&&(type==='BLENDED'&&input.name!=='grossPay'||type==='HOURLY'&&input.name==='payHourlyRate')})});
      form.querySelector('[data-pay-help]').textContent=type==='FIXED'?'Leave the amount blank if pay is not yet known.':type==='HOURLY'?'Hour limit caps total billable onsite hours. Leave it blank for uncapped hourly pay.':'Hour limit caps additional hours beyond the hours included in the base. Maximum pay can be calculated from these terms.';
    };
    form.elements.payType.onchange=update;update();
  }
  function payData(form){
    const value=name=>{const input=form.elements.namedItem(name);return input.disabled||input.value===''?null:input.value};
    const data={payType:form.elements.payType.value,grossPay:value('grossPay'),payBaseAmount:value('payBaseAmount'),payBaseHours:value('payBaseHours'),payHourlyRate:value('payHourlyRate'),payHoursCap:value('payHoursCap')};
    if(data.payType==='FIXED')data.grossPay=data.payBaseAmount;
    return data;
  }
  function dialog(title,fields,onSave,help=''){
    const node=document.createElement('dialog');node.className='work-order-dialog';
    const focus=document.activeElement;
    node.innerHTML=`<form class="work-order-form"><div class="full work-order-heading"><h2 id="workOrderDialogTitle">${html(title)}</h2><button type="button" class="secondary" data-cancel>Cancel</button></div>${help?`<p class="full muted">${html(help)}</p>`:''}${fields}<p class="full error" role="alert"></p><div class="full actions"><button type="submit">Save</button></div></form>`;
    node.setAttribute('aria-labelledby','workOrderDialogTitle');document.body.append(node);
    const form=node.querySelector('form'), save=form.querySelector('[type=submit]'), error=form.querySelector('[role=alert]');let saving=false;
    node.addEventListener('cancel',event=>{if(saving)event.preventDefault()});
    node.querySelector('[data-cancel]').onclick=()=>{if(!saving)node.close()};
    node.addEventListener('close',()=>{node.remove();if(focus?.isConnected)focus.focus()});
    if(form.elements.payType)setupPay(form);
    form.onsubmit=async event=>{
      event.preventDefault();if(saving)return;saving=true;save.disabled=true;node.querySelector('[data-cancel]').disabled=true;error.textContent='';
      try{await onSave(form);node.close()}
      catch(failure){error.textContent=failure.message}
      finally{saving=false;save.disabled=false;node.querySelector('[data-cancel]').disabled=false}
    };
    node.showModal();return node;
  }
  async function reloadSaved(form,id,current=()=>true){
    form.closest('dialog').close();
    try{await window.load();if(current())await window.openWorkOrder(id)}
    catch(error){document.getElementById('loadStatus').textContent='Saved. Refresh to reload the job: '+error.message}
  }
  create.onclick=async()=>{
    if(!canEdit())return;create.disabled=true;
    try{
      const clients=(await window.api('/api/v1/clients')).data;
      if(!Array.isArray(clients))throw Error('Client choices could not be loaded.');
      dialog('New work order',`${input('title','Job title','','required maxlength="255"')}
        <label>Source<select name="source"><option value="FIELD_NATION">FieldNation</option><option value="MANUAL">Manual</option><option value="SYNCRO">Syncro</option><option value="OTHER">Other</option></select></label>
        ${input('sourceReference','Work-order reference','','required maxlength="120"')}
        <label>Client<select name="clientId"><option value="">Unassigned</option>${clients.map(client=>`<option value="${html(client.id)}">${html(client.name)}</option>`).join('')}</select></label>
        ${input('scheduledAt','Scheduled date and time','','type="datetime-local"')}${payFields()}${notes('')}`,
        async form=>{
          const body={source:form.elements.source.value,sourceReference:form.elements.sourceReference.value.trim(),title:form.elements.title.value.trim(),clientId:form.elements.clientId.value||null,scheduledAt:schedule(form.elements.scheduledAt.value),notes:form.elements.notes.value,...payData(form)};
          if(!body.title||!body.sourceReference)throw Error('Enter a title and work-order reference.');
          const response=await window.api('/api/v1/work-orders',{method:'POST',body:JSON.stringify(body)});
          // Creation is committed once; a failed refresh must never invite a duplicate POST.
          if(!response.data?.id)throw Error('The server did not return the created work-order id. Refresh before trying again.');
          const id=response.data.id;
          await reloadSaved(form,id);
        },'Use the exact FieldNation work-order number in the phone tracker. Assign the service vehicle in the saved job below.');
    }catch(error){document.getElementById('loadStatus').textContent=error.message}
    finally{create.disabled=!canEdit()}
  };

  window.addEventListener('ops:work-order-ready',event=>{
    const view=event.detail,workOrder=view.workOrder;
    if(!canEdit())return;
    const current=()=>window.opsWorkOrderView?.sequence===view.sequence;
    const actions=document.createElement('div');actions.className='actions work-order-edit-actions';
    actions.innerHTML=`<button type="button" class="secondary" data-edit-details>${locked(workOrder)?'Edit notes':'Edit job details'}</button><button type="button" class="secondary" data-edit-pay ${locked(workOrder)?'disabled':''}>Edit pay terms</button><button type="button" class="secondary" data-edit-client ${locked(workOrder)?'disabled':''}>Assign client</button>`;
    document.getElementById('detailContent').prepend(actions);
    actions.querySelector('[data-edit-details]').onclick=()=>{
      if(!current())return;
      dialog(locked(workOrder)?'Edit notes':'Edit job details',`${locked(workOrder)?'':input('title','Job title',workOrder.title,'required maxlength="255"')+input('scheduledAt','Scheduled date and time',localDateTime(workOrder.scheduledAt),'type="datetime-local"')}${notes(workOrder.notes??'')}`,
        async form=>{
          if(!current())throw Error('The selected work order changed. Reopen this editor.');
          const data={notes:form.elements.notes.value};
          if(!locked(workOrder)){data.title=form.elements.title.value.trim();const date=form.elements.scheduledAt.value;data.scheduledAt=date===localDateTime(workOrder.scheduledAt)?workOrder.scheduledAt:schedule(date)}
          await window.api(`/api/v1/work-orders/${encodeURIComponent(view.id)}`,{method:'PATCH',body:JSON.stringify(data)});
          await reloadSaved(form,view.id,current);
        },`Reference: ${workOrder.sourceReference}. The reference stays unchanged so tracker imports continue to match.`);
    };
    actions.querySelector('[data-edit-pay]').onclick=()=>{
      if(!current()||locked(workOrder))return;
      dialog('Edit pay terms',payFields(workOrder),async form=>{
        if(!current())throw Error('The selected work order changed. Reopen this editor.');
        await window.api(`/api/v1/work-orders/${encodeURIComponent(view.id)}/pay`,{method:'PATCH',body:JSON.stringify(payData(form))});
        await reloadSaved(form,view.id,current);
      },'Saving pay terms recalculates job pay for completed work. This does not record a received payment.');
    };
    actions.querySelector('[data-edit-client]').onclick=async()=>{
      if(!current()||locked(workOrder))return;
      try{
        const clients=(await window.api('/api/v1/clients')).data;if(!current())return;
        dialog('Assign client',`<label class="full">Client<select name="clientId"><option value="">Unassigned</option>${clients.map(client=>`<option value="${html(client.id)}" ${String(client.id)===String(workOrder.clientId)?'selected':''}>${html(client.name)}</option>`).join('')}</select></label>`,async form=>{
          if(!current())throw Error('The selected work order changed. Reopen this editor.');
          await window.api(`/api/v1/work-orders/${encodeURIComponent(view.id)}/client`,{method:'PATCH',body:JSON.stringify({clientId:form.elements.clientId.value||null})});
          await reloadSaved(form,view.id,current);
        });
      }catch(error){document.getElementById('detailStatus').textContent=error.message}
    };
  });
  window.addEventListener('ops:signed-out',()=>{document.querySelectorAll('.work-order-dialog').forEach(node=>node.close());rows=[];search.value='';status.value='';renderList()});
}());
