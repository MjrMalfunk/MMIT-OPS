(function () {
  'use strict';

  const style = document.createElement('style');
  style.textContent = `
    .work-order-profitability{margin-top:16px;padding:16px;background:#0a192c;border:1px solid #29415f;border-radius:12px}
    .work-order-profitability-heading{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
    .work-order-profitability h3{margin:0;font-size:16px}
    .work-order-profitability p{margin:7px 0 0;color:#b8c8dc;line-height:1.45}
    .work-order-profitability-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:13px}
    .work-order-profitability-grid div{padding:10px;background:#132640;border:1px solid #213752;border-radius:8px}
    .work-order-profitability-grid small{display:block;color:#9db0c8;font-size:11px}.work-order-profitability-grid strong{display:block;margin-top:3px}
    .work-order-profitability-note{font-size:12px}.work-order-profitability-warning{color:#f8d895!important}
    @media(max-width:800px){.work-order-profitability-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  `;
  document.head.appendChild(style);

  const html = value => String(value == null ? '—' : value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const money = value => value == null ? 'Not recorded' : `$${Number(value).toFixed(2)}`;
  const miles = value => value == null ? 'Not recorded' : `${Number(value).toFixed(2)} mi`;
  const minutes = value => value == null ? 'Not recorded' : `${Math.round(Number(value))} min`;
  const mileageLabel = value => value === 'ODOMETER' ? 'Odometer-backed' : value === 'MANUAL_RECORDED' ? 'Recorded manually — review when needed' : 'Not recorded';
  const rateLabel = value => `${(Number(value || 0) * 100).toFixed(2)}%`;

  async function request(path) {
    const response = await fetch(path, { headers: { Authorization: `Bearer ${localStorage.getItem('mmit_ops_v2_token') || ''}` } });
    let body = {};
    try { body = await response.json(); } catch {}
    if (!response.ok) throw Error(body.error || `Request failed (${response.status})`);
    return body;
  }

  function render(profit, vehicleName) {
    const readiness = profit.complete ? 'Complete' : 'Needs records';
    const missing = Array.isArray(profit.missing) && profit.missing.length
      ? `Needs ${profit.missing.join(', ')} before true profit can be calculated.`
      : 'Actual payout, recorded miles, vehicle cost, and direct costs are included.';
    const vehicleDetail = profit.vehicleCostPerMile == null
      ? 'Assign a complete vehicle cost model'
      : `${vehicleName || 'Assigned vehicle'} · $${Number(profit.vehicleCostPerMile).toFixed(4)}/mi snapshot`;
    const fees = profit.fieldNationFeeEstimate;
    const feeDetails = fees ? `
        <div><small>FieldNation platform fee · ${html(rateLabel(fees.platformRate))}</small><strong>${html(money(fees.platformFee))}</strong></div>
        <div><small>Insurance fee · ${html(rateLabel(fees.insuranceRate))}</small><strong>${html(money(fees.insuranceFee))}</strong></div>
        <div><small>OAI fee · ${html(rateLabel(fees.oaiRate))}</small><strong>${html(fees.oaiApplies ? money(fees.oaiFee) : 'Not applied')}</strong></div>
        <div><small>Total FieldNation fees · configured rates</small><strong>${html(money(fees.totalFee))}</strong></div>` : '';
    const payoutLabel = profit.payoutBeforeFees ? 'Work-order pay before FieldNation fees' : 'Actual payout';
    const profitDetail = fees ? 'After vehicle, estimated FieldNation fees, and direct costs' : 'After vehicle and direct costs';
    const feeNote = 'FieldNation fees use the configured rates shown above; the settlement statement has not been imported.';
    const note = fees
      ? `${feeNote}${profit.complete ? '' : ` ${missing}`}`
      : missing;
    return `<section class="work-order-profitability" aria-label="Actual job profitability">
      <div class="work-order-profitability-heading"><div><div class="label">Completed-work review</div><h3>Actual job profitability</h3></div><span class="pill ${profit.complete ? '' : 'warn'}">${html(readiness)}</span></div>
      <div class="work-order-profitability-grid">
        <div><small>${html(payoutLabel)}</small><strong>${html(money(profit.actualPayout))}</strong></div>
        <div><small>Recorded mileage</small><strong>${html(miles(profit.recordedMileage))}</strong><small>${html(mileageLabel(profit.mileageEvidence))}</small></div>
        <div><small>Vehicle operating cost</small><strong>${html(money(profit.vehicleCost))}</strong><small>${html(vehicleDetail)}</small></div>
        <div><small>Direct job costs</small><strong>${html(money(profit.directCost))}</strong><small>Materials and recorded expenses</small></div>
        ${feeDetails}
        <div><small>True profit</small><strong>${html(money(profit.trueProfit))}</strong><small>${html(profitDetail)}</small></div>
        <div><small>Door-to-door time</small><strong>${html(minutes(profit.totalMinutes))}</strong><small>${profit.timeSource === 'TRACKER_OUTING' ? 'Tracker outing' : 'Recorded work-order totals'}</small></div>
        <div><small>Profit / door-to-door hour</small><strong>${html(money(profit.profitPerDoorToDoorHour))}</strong><small>Uses the recorded total above</small></div>
      </div>
      <p class="work-order-profitability-note ${profit.complete && !fees ? '' : 'work-order-profitability-warning'}">${html(note)}</p>
    </section>`;
  }

  const original = window.openWorkOrder;
  let activeWorkOrderId = null;
  window.openWorkOrder = async id => {
    activeWorkOrderId = String(id);
    await original(id);
    const content = document.getElementById('detailContent');
    if (!content || content.querySelector(`[data-profitability-work-order="${String(id)}"]`)) return;
    try {
      const response = await request(`/api/v1/work-orders/${encodeURIComponent(id)}/profitability`);
      if (activeWorkOrderId !== String(id)) return;
      const section = document.createElement('div');
      section.dataset.profitabilityWorkOrder = String(id);
      section.innerHTML = render(response.data || {}, response.data?.vehicleName);
      content.append(section);
    } catch (error) {
      console.warn('Work-order profitability could not load:', error.message);
    }
  };
})();
