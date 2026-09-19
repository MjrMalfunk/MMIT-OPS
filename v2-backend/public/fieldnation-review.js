(function () {
  'use strict';

  const style = document.createElement('style');
  style.textContent = `
    .fieldnation-review-detail{margin-top:18px}
    .fieldnation-review-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:14px}
    .fieldnation-review-heading h2{margin:0}
    .fieldnation-review-copy{color:#9db0c8;margin:8px 0 15px}
    .fieldnation-review-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    .fieldnation-review-grid>div{padding:10px;background:#0a192c;border:1px solid #213752;border-radius:10px}
    .fieldnation-review-grid small{display:block;color:#9db0c8;font-size:11px}
    .fieldnation-review-grid strong{display:block;margin-top:2px;overflow-wrap:anywhere}
    .fieldnation-review-reasons{margin-top:14px;padding:11px 13px;border-left:3px solid #f2bd67;background:#49371e;color:#f8d895;border-radius:5px}
    .fieldnation-review-reasons ul{margin:7px 0 0;padding-left:20px}
    .fieldnation-review-note{margin-top:14px;color:#9db0c8}
    .fieldnation-review-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
    .fieldnation-review-requirements{margin-top:14px;padding:11px 13px;border-left:3px solid #f2bd67;background:#49371e;color:#f8d895;border-radius:5px}
    .fieldnation-review-requirements.ready{border-left-color:#53d39b;background:#12382d;color:#b8f2da}
    .fieldnation-review-requirements ul{margin:7px 0 0;padding-left:20px}
    .fieldnation-review-form{margin-top:16px;padding:14px;border:1px solid #29415f;background:#0a192c;border-radius:10px}
    .fieldnation-review-form h3{margin:0 0 5px;font-size:16px}
    .fieldnation-review-form-copy{color:#9db0c8;margin:0 0 13px}
    .fieldnation-review-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .fieldnation-review-form label{display:block;color:#9db0c8;font-size:12px}
    .fieldnation-review-form input,.fieldnation-review-form select{display:block;width:100%;margin-top:5px;padding:9px;border:1px solid #29415f;border-radius:8px;background:#08172a;color:#e8f0fb;outline:none}
    .fieldnation-review-form input:focus,.fieldnation-review-form select:focus{border-color:#67a8ff}
    .fieldnation-review-form-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
    @media(max-width:800px){.fieldnation-review-grid,.fieldnation-review-form-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:520px){.fieldnation-review-grid,.fieldnation-review-form-grid{grid-template-columns:1fr}}
  `;
  document.head.appendChild(style);

  function html(value) {
    return String(value == null || value === '' ? '—' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
  }

  function money(value) {
    const number = Number(value);
    return value == null || !Number.isFinite(number) ? '—' : `$${number.toFixed(2)}`;
  }

  function dateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
  }

  function dateTimeLocal(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function reviewObject(item) {
    const parsedData = item && item.parsedData;
    return parsedData && typeof parsedData === 'object' && !Array.isArray(parsedData) && parsedData.review && typeof parsedData.review === 'object'
      ? parsedData.review
      : null;
  }

  function reviewRequirementsFor(item) {
    if (importValue(item, 'workOrderId')) return [];
    const requirements = [];
    if (!importValue(item, 'sourceReference')) requirements.push('source reference is required');
    if (!importValue(item, 'scheduledAt')) requirements.push('scheduled date is required');

    const payType = importValue(item, 'payType') || 'FIXED';
    const grossPay = Number(importValue(item, 'grossPay'));
    const baseAmount = Number(importValue(item, 'payBaseAmount'));
    const baseHours = Number(importValue(item, 'payBaseHours'));
    const hourlyRate = Number(importValue(item, 'payHourlyRate'));
    const hoursCap = Number(importValue(item, 'payHoursCap'));
    const payReady = payType === 'FIXED'
      ? (Number.isFinite(grossPay) && grossPay > 0) || (Number.isFinite(baseAmount) && baseAmount > 0)
      : payType === 'HOURLY'
        ? Number.isFinite(hourlyRate) && hourlyRate > 0
        : Number.isFinite(baseAmount) && baseAmount > 0 && Number.isFinite(baseHours) && baseHours >= 0
          && Number.isFinite(hourlyRate) && hourlyRate > 0 && Number.isFinite(hoursCap) && hoursCap >= 0;
    if (!payReady) requirements.push('pay terms are required');
    return requirements;
  }

  function inputValue(value) {
    return value == null ? '' : String(value);
  }

  function formField(form, name) {
    const field = form.elements.namedItem(name);
    return field && typeof field.value === 'string' ? field.value.trim() : '';
  }

  function formPayload(form) {
    const localSchedule = formField(form, 'scheduledAt');
    let scheduledAt = null;
    if (localSchedule) {
      const parsed = new Date(localSchedule);
      if (Number.isNaN(parsed.getTime())) throw new Error('Scheduled date must be valid.');
      scheduledAt = parsed.toISOString();
    }
    const optional = (name) => formField(form, name) || null;
    return {
      sourceReference: optional('sourceReference'),
      title: optional('title'),
      location: optional('location'),
      scheduledAt,
      payType: formField(form, 'payType') || 'FIXED',
      grossPay: optional('grossPay'),
      payBaseAmount: optional('payBaseAmount'),
      payBaseHours: optional('payBaseHours'),
      payHourlyRate: optional('payHourlyRate'),
      payHoursCap: optional('payHoursCap'),
      estimatedHours: optional('estimatedHours'),
      mileage: optional('mileage'),
    };
  }

  function importValue(item, key) {
    if (item && item[key] != null) return item[key];
    for (const nested of [item && item.parsed, item && item.opportunity, item && item.details]) {
      if (nested && nested[key] != null) return nested[key];
    }
    return null;
  }

  function reasonsFor(item) {
    const candidates = [
      item && item.scoreReasons,
      item && item.reasons,
      item && item.warnings,
      item && item.parsed && item.parsed.scoreReasons,
      item && item.parsed && item.parsed.reasons,
      item && item.parsed && item.parsed.warnings,
      item && item.parsedData && item.parsedData.scoreReasons,
      item && item.parsedData && item.parsedData.reasons,
      item && item.parsedData && item.parsedData.warnings,
    ];
    for (const candidate of candidates) {
      if (Array.isArray(candidate)) return candidate.filter(Boolean).map(String);
      if (typeof candidate === 'string' && candidate.trim()) return [candidate.trim()];
    }
    return [];
  }

  async function reviewApi(path, options = {}) {
    const response = await fetch(path, {
      ...options,
      headers: {
        ...(options.headers || {}),
        Authorization: `Bearer ${localStorage.getItem('mmit_ops_v2_token') || ''}`,
        'Content-Type': 'application/json',
      },
    });
    let body = {};
    try { body = await response.json(); } catch {}
    if (!response.ok) throw new Error(body.error || `Request failed (${response.status})`);
    return body;
  }

  function panel() {
    let node = document.getElementById('fieldNationImportDetail');
    if (node) return node;

    const imports = document.getElementById('imports');
    const intakeCard = imports && imports.closest('section');
    if (!intakeCard) return null;

    node = document.createElement('section');
    node.id = 'fieldNationImportDetail';
    node.className = 'card fieldnation-review-detail hidden';
    intakeCard.insertAdjacentElement('afterend', node);
    return node;
  }

  function setPanel(markup) {
    const node = panel();
    if (!node) return;
    node.classList.remove('hidden');
    node.innerHTML = markup;
    node.querySelector('[data-close-fieldnation-review]')?.addEventListener('click', () => node.classList.add('hidden'));
  }

  async function saveReview(id, form) {
    const submit = form.querySelector('button[type="submit"]');
    if (submit) { submit.disabled = true; submit.textContent = 'Saving…'; }
    try {
      await reviewApi(`/api/v1/fieldnation/imports/${id}/review`, {
        method: 'PATCH',
        body: JSON.stringify(formPayload(form)),
      });
      if (typeof window.load === 'function') await window.load();
      await window.openFieldNationImport(id);
    } catch (error) {
      if (submit) { submit.disabled = false; submit.textContent = 'Save review data'; }
      alert(error.message);
    }
  }

  async function convertImport(id) {
    if (!confirm('Create a V2 FieldNation work order from this reviewed import?')) return;
    try {
      const response = await reviewApi(`/api/v1/fieldnation/imports/${id}/convert`, { method: 'POST' });
      const workOrderId = response && response.data && response.data.workOrderId;
      if (typeof window.load === 'function') await window.load();
      if (workOrderId && typeof window.openWorkOrder === 'function') window.openWorkOrder(workOrderId);
    } catch (error) {
      alert(error.message);
    }
  }

  window.openFieldNationImport = async function openFieldNationImport(id) {
    if (!id) return;
    setPanel(`
      <div class="fieldnation-review-heading"><h2>FieldNation import review</h2><button type="button" class="secondary" data-close-fieldnation-review>Close</button></div>
      <div class="fieldnation-review-copy">Loading parsed opportunity and conversion state…</div>
    `);

    try {
      const response = await reviewApi(`/api/v1/fieldnation/imports/${id}`);
      const item = response && response.data ? response.data : response;
      const sourceReference = importValue(item, 'sourceReference');
      const workOrderId = importValue(item, 'workOrderId');
      const reasons = reasonsFor(item);
      const review = reviewObject(item);
      const remainingReasons = Array.isArray(review && review.remainingReasons)
        ? review.remainingReasons.filter(Boolean).map(String)
        : reviewRequirementsFor(item);
      const canConvert = !workOrderId && Boolean(sourceReference) && remainingReasons.length === 0;
      const editable = !workOrderId;
      const payType = importValue(item, 'payType') || 'FIXED';

      setPanel(`
        <div class="fieldnation-review-heading"><h2>${html(importValue(item, 'title') || 'FieldNation import')}</h2><button type="button" class="secondary" data-close-fieldnation-review>Close</button></div>
        <div class="fieldnation-review-copy">Review the parsed opportunity, fill any missing verified values, then perform an explicit, audited work-order conversion.</div>
        <div class="fieldnation-review-grid">
          <div><small>Status</small><strong>${html(importValue(item, 'status'))}</strong></div>
          <div><small>Score</small><strong>${html(importValue(item, 'score'))}</strong></div>
          <div><small>Advertised pay</small><strong>${html(money(importValue(item, 'grossPay')))}</strong></div>
          <div><small>Schedule</small><strong>${html(dateTime(importValue(item, 'scheduledAt')))}</strong></div>
          <div><small>Source reference</small><strong>${html(sourceReference)}</strong></div>
          <div><small>Location</small><strong>${html(importValue(item, 'location'))}</strong></div>
          <div><small>Message ID</small><strong>${html(importValue(item, 'messageId'))}</strong></div>
          <div><small>Received</small><strong>${html(dateTime(importValue(item, 'receivedAt')))}</strong></div>
        </div>
        ${remainingReasons.length ? `<div class="fieldnation-review-requirements"><strong>Requirements remaining before conversion</strong><ul>${remainingReasons.map((reason) => `<li>${html(reason)}</li>`).join('')}</ul></div>` : editable ? '<div class="fieldnation-review-requirements ready"><strong>Ready for explicit conversion.</strong></div>' : ''}
        ${reasons.length ? `<div class="fieldnation-review-reasons"><strong>Score and review reasons</strong><ul>${reasons.map((reason) => `<li>${html(reason)}</li>`).join('')}</ul></div>` : ''}
        <div class="fieldnation-review-note">The original message is retained by V2 for auditability and intentionally stays out of the dashboard list.</div>
        ${editable ? `
          <form class="fieldnation-review-form" data-fieldnation-review-form>
            <h3>Verified review data</h3>
            <p class="fieldnation-review-form-copy">These values are saved to the import and carried into the work order only when you explicitly convert it.</p>
            <div class="fieldnation-review-form-grid">
              <label>Source reference<input name="sourceReference" maxlength="191" value="${html(inputValue(sourceReference))}" required></label>
              <label>Title<input name="title" maxlength="255" value="${html(inputValue(importValue(item, 'title')))}"></label>
              <label>Location<input name="location" maxlength="255" value="${html(inputValue(importValue(item, 'location')))}"></label>
              <label>Scheduled date and time<input name="scheduledAt" type="datetime-local" value="${html(dateTimeLocal(importValue(item, 'scheduledAt')))}"></label>
              <label>Pay type<select name="payType"><option value="FIXED" ${payType === 'FIXED' ? 'selected' : ''}>Fixed amount</option><option value="HOURLY" ${payType === 'HOURLY' ? 'selected' : ''}>Hourly</option><option value="BLENDED" ${payType === 'BLENDED' ? 'selected' : ''}>Blended</option></select></label>
              <label>Advertised/max pay<input name="grossPay" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'grossPay')))}"></label>
              <label>Base amount<input name="payBaseAmount" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'payBaseAmount')))}"></label>
              <label>Base hours<input name="payBaseHours" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'payBaseHours')))}"></label>
              <label>Hourly rate<input name="payHourlyRate" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'payHourlyRate')))}"></label>
              <label>Hours cap<input name="payHoursCap" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'payHoursCap')))}"></label>
              <label>Estimated hours<input name="estimatedHours" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'estimatedHours')))}"></label>
              <label>Estimated mileage<input name="mileage" inputmode="decimal" type="number" min="0" step="0.01" value="${html(inputValue(importValue(item, 'mileage')))}"></label>
            </div>
            <div class="fieldnation-review-form-actions"><button type="submit">Save review data</button>${canConvert ? `<button type="button" data-convert-reviewed-import="${html(id)}">Create work order</button>` : ''}</div>
          </form>
        ` : ''}
        <div class="fieldnation-review-actions">
          ${workOrderId ? `<button type="button" data-open-work-order="${html(workOrderId)}">Open work order #${html(workOrderId)}</button>` : ''}
          ${!workOrderId && !canConvert ? '<span class="muted">Save the required review data before this import can become a work order.</span>' : ''}
        </div>
      `);

      const reviewPanel = panel();
      reviewPanel?.querySelector('[data-fieldnation-review-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        saveReview(id, event.currentTarget);
      });
      reviewPanel?.querySelector('[data-convert-reviewed-import]')?.addEventListener('click', () => convertImport(id));
      reviewPanel?.querySelector('[data-open-work-order]')?.addEventListener('click', (event) => {
        const workOrder = event.currentTarget.getAttribute('data-open-work-order');
        if (workOrder && typeof window.openWorkOrder === 'function') window.openWorkOrder(workOrder);
      });
    } catch (error) {
      setPanel(`
        <div class="fieldnation-review-heading"><h2>FieldNation import review</h2><button type="button" class="secondary" data-close-fieldnation-review>Close</button></div>
        <div class="error">${html(error.message)}</div>
      `);
    }
  };
}());
