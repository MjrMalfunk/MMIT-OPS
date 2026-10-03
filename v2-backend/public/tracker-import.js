(function () {
  'use strict';
  const html = value => String(value ?? '—').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const money = value => '$' + Number(value).toFixed(2);
  const editable = () => ['OWNER','ADMIN','OPERATOR'].includes(window.opsUser?.role);
  const success = new Map();
  window.addEventListener('ops:signed-out', () => success.clear());
  window.addEventListener('ops:work-order-ready', event => {
    const view = event.detail, job = view.workOrder;
    if (job.source !== 'FIELD_NATION') return;
    const current = () => window.opsWorkOrderView?.sequence === view.sequence && card.isConnected;
    const card = document.createElement('section'); card.id='trackerImportCard';card.className='tracker-import-card';
    const locked = ['INVOICED','PAID','CANCELLED'].includes(job.status) || !editable();
    card.innerHTML=`<h3>Import completed outing</h3><p>Selected work order: <strong>${html(job.sourceReference)}</strong></p><p class="muted">Preview the phone export before applying time, mileage, and material use.</p>${locked?'<p class="muted">Import is unavailable for this status or your account permissions.</p>':'<div class="tracker-import-actions"><label>Completed tracker JSON<input id="trackerPacketFile" type="file" accept="application/json,.json"></label><button type="button" id="previewTrackerPacket" class="secondary">Preview packet</button></div>'}<div id="trackerImportPreview" role="status" aria-live="polite"></div>`;
    document.getElementById('detailContent').append(card);
    const output=card.querySelector('#trackerImportPreview');
    if(success.has(view.id))output.innerHTML=success.get(view.id);
    if(locked)return;
    const file=card.querySelector('input'), preview=card.querySelector('#previewTrackerPacket');
    let generation=0, applying=false;
    file.onchange=()=>{generation++;output.innerHTML='';};
    preview.onclick=async()=>{
      if(applying)return;
      const selected=file.files?.[0], attempt=++generation;
      const fresh=()=>current() && generation===attempt;
      if(!selected){output.textContent='Choose a completed tracker export first.';return;}
      output.textContent='Reading packet…';
      try {
        if(selected.size>4*1024*1024)throw Error('Choose a JSON file no larger than 4 MiB.');
        const packet=JSON.parse(await selected.text());if(!fresh())return;
        const shift=packet?.shift;
        if(packet?.schema!=='mmit.work-tracker.v2'||!shift||shift.platform!=='FIELD_NATION')throw Error('Choose a FieldNation outing exported by MMIT Work Tracker.');
        if(typeof shift.workOrderNumber!=='string')throw Error('The packet is missing its work-order reference.');
        if(shift.workOrderNumber.trim()!==job.sourceReference)throw Error(`Reference mismatch: packet ${shift.workOrderNumber}; selected job ${job.sourceReference}. Open the matching job or export a new outing with the correct reference.`);
        const events=packet.events;
        const expected=['SHIFT_STARTED','FN_TRIP_STARTED','FN_ARRIVED_SITE','FN_CHECKED_IN','FN_WORK_COMPLETED','FN_CHECKED_OUT',...(shift.roundTripExpected?['FN_RETURN_STARTED','FN_RETURN_COMPLETED']:['OUTING_COMPLETED'])];
        const start=shift.startedAtEpochMs,end=shift.completedAtEpochMs;
        if(typeof shift.roundTripExpected!=='boolean'||!Array.isArray(events)||events.length!==expected.length||events.some((row,index)=>row.type!==expected[index]))throw Error('Export a completed outing with its original event sequence.');
        if(!Number.isSafeInteger(start)||!Number.isSafeInteger(end)||start<1577836800000||end>4102444800000||end<start||end-start>172800000)throw Error('The outing timeline is incomplete or invalid.');
        let previous=start;
        for(const row of events){const at=row.occurredAtEpochMs;if(!Number.isSafeInteger(at)||at<previous||at>end)throw Error('The packet events are out of order or outside the outing.');previous=at;}
        if(!Number.isFinite(shift.startOdometer)||!Number.isFinite(shift.endOdometer)||shift.startOdometer<0||shift.endOdometer<shift.startOdometer||shift.endOdometer>9999999||shift.endOdometer-shift.startOdometer>3000)throw Error('Starting and ending odometers must be valid and increasing.');
        const materials=packet.materials??[], seen=new Set();
        if(!Array.isArray(materials)||materials.length>50)throw Error('The packet material list is invalid.');
        const usage=materials.map(row=>{
          const sku=typeof row?.sku==='string'?row.sku.trim().toUpperCase():'';
          if(!sku||sku.length>100||/[\x00-\x1f\x7f]/.test(sku)||seen.has(sku)||!Number.isFinite(row.quantity)||row.quantity<.001||row.quantity>10000)throw Error('Materials need unique SKUs and valid positive quantities.');
          seen.add(sku);return{sku,quantity:Number(row.quantity.toFixed(3))};
        });
        let stock=[], warning=job.vehicleId?'':'No service vehicle is assigned. Assign it before importing if its odometer should be updated.';
        if(usage.length){
          try{stock=(await window.api('/api/v1/inventory')).data;if(!Array.isArray(stock))throw Error('Invalid catalog');}
          catch{stock=[];warning+=' Current stock could not be loaded. OPS will validate availability and cost when you apply.';}
          if(!fresh())return;
        }
        let total=0,known=true;
        const lines=usage.map(row=>{
          const item=stock.find(item=>item.sku===row.sku),valid=item?.active!==false&&item&&Number.isFinite(Number(item.unitCost));
          const cost=valid?Math.round((row.quantity*Number(item.unitCost)+Number.EPSILON)*100)/100:null;
          if(cost===null)known=false;else total+=cost;
          const short=valid&&Number(item.quantityOnHand)<row.quantity;
          return `<tr><td>${html(row.sku)}<small>${html(item?.description??'OPS will check this SKU')}</small></td><td>${html(row.quantity)} ${html(item?.unit??'')}</td><td>${valid?html(item.quantityOnHand):'Unknown'}</td><td>${cost===null?'Unknown':money(cost)}${short?'<small class="error">Insufficient stock; OPS will check for an existing import first.</small>':''}</td></tr>`;
        }).join('');
        output.innerHTML=`<p><strong>Reference matches ${html(job.sourceReference)}</strong> · ${html(selected.name)}</p><div class="tracker-import-summary"><div>Outing duration<strong>${((end-start)/60000).toFixed(2)} min</strong></div><div>Odometer mileage<strong>${(shift.endOdometer-shift.startOdometer).toFixed(2)} mi</strong></div><div>Estimated materials cost<strong>${known?money(total):'Unknown'}</strong></div></div>${usage.length?`<div class="table-wrap"><table><thead><tr><th>Material</th><th>Used</th><th>Current stock</th><th>Estimated cost</th></tr></thead><tbody>${lines}</tbody></table></div>`:'<p>No materials recorded in this packet.</p>'}<p class="muted">${html(warning||'Preview uses current catalog prices. OPS validates the complete packet and deducts stock only once when applied.')} This does not record a received payment.</p>${end-start<300000||shift.endOdometer===shift.startOdometer?'<p class="tracker-import-warning">Short outing or zero mileage: confirm this was intentional.</p>':''}<button type="button" id="applyTrackerImport">Apply to ${html(job.sourceReference)}</button><p class="error" data-import-error></p>`;
        const apply=output.querySelector('button');
        apply.onclick=async()=>{
          if(!fresh()||applying)return;
          applying=true;apply.disabled=true;file.disabled=true;preview.disabled=true;
          try{
            // Capture the view and packet; subsequent navigation cannot change the target.
            const result=await window.api(`/api/v1/work-orders/${encodeURIComponent(view.id)}/tracker-import`,{method:'POST',body:JSON.stringify(packet)});
            const data=result.data??{}, warnings=data.trackerImport?.warnings??[];
            const message=`<p class="tracker-import-success">${data.idempotent?'Already imported; stock was not deducted again.':'Outing imported. Time, mileage, and material use were recorded.'}</p>${warnings.length?`<p class="tracker-import-warning">${warnings.map(html).join('<br>')}</p>`:''}`;
            success.set(view.id,message);
            if(fresh())output.innerHTML=message;
            await window.load();
            if(current())await window.openWorkOrder(view.id);
          }catch(error){if(fresh()){const target=output.querySelector('[data-import-error]');if(target)target.textContent=error.message;}}
          finally{applying=false;if(fresh()){apply.disabled=false;file.disabled=false;preview.disabled=false;}}
        };
      }catch(error){if(fresh())output.innerHTML=`<p class="error">${html(error.message)}</p>`;}
    };
  });
}());
