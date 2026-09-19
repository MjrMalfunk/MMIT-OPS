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
    @media(max-width:800px){.fieldnation-review-grid{grid-template-columns:repeat(2,1fr)}}
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
      const canConvert = !workOrderId && Boolean(sourceReference);

      setPanel(`
        <div class="fieldnation-review-heading"><h2>${html(importValue(item, 'title') || 'FieldNation import')}</h2><button type="button" class="secondary" data-close-fieldnation-review>Close</button></div>
        <div class="fieldnation-review-copy">Review the parsed opportunity before an explicit, audited work-order conversion.</div>
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
        ${reasons.length ? `<div class="fieldnation-review-reasons"><strong>Score and review reasons</strong><ul>${reasons.map((reason) => `<li>${html(reason)}</li>`).join('')}</ul></div>` : ''}
        <div class="fieldnation-review-note">The original message is retained by V2 for auditability and intentionally stays out of the dashboard list.</div>
        <div class="fieldnation-review-actions">
          ${workOrderId ? `<button type="button" data-open-work-order="${html(workOrderId)}">Open work order #${html(workOrderId)}</button>` : ''}
          ${canConvert ? `<button type="button" data-convert-reviewed-import="${html(id)}">Create work order</button>` : ''}
          ${!workOrderId && !canConvert ? '<span class="muted">A source reference is required before this import can become a work order.</span>' : ''}
        </div>
      `);

      document.querySelector('[data-convert-reviewed-import]')?.addEventListener('click', () => convertImport(id));
      document.querySelector('[data-open-work-order]')?.addEventListener('click', (event) => {
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
