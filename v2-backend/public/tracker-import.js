(function () {
  'use strict';

  let activeWorkOrderId = null;
  let selectedPacket = null;
  let selectedFileName = '';

  const style = document.createElement('style');
  style.textContent = `
    .tracker-import-card{margin-top:14px;padding:16px;background:#0a192c;border:1px solid #29415f;border-radius:12px}
    .tracker-import-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:6px}
    .tracker-import-heading strong{font-size:16px}
    .tracker-import-copy{color:#9db0c8;margin:0 0 13px}
    .tracker-import-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
    .tracker-import-actions input[type=file]{max-width:100%;color:#9db0c8}
    .tracker-import-preview{margin-top:13px}
    .tracker-import-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px}
    .tracker-import-summary div{padding:9px;background:#132640;border:1px solid #213752;border-radius:8px}
    .tracker-import-summary small{display:block;color:#9db0c8;font-size:11px}
    .tracker-import-summary strong{display:block;margin-top:2px;overflow-wrap:anywhere}
    .tracker-import-warning{margin-top:10px;padding:10px 12px;border-left:3px solid #f2bd67;background:#49371e;color:#f8d895;border-radius:5px}
    .tracker-import-success{margin-top:10px;padding:10px 12px;border-left:3px solid #53d39b;background:#123b35;color:#a9f2d2;border-radius:5px}
    .tracker-import-error{margin-top:10px;padding:10px 12px;border-left:3px solid #ff7d8b;background:#462331;color:#ffc0c8;border-radius:5px}
    @media(max-width:800px){.tracker-import-summary{grid-template-columns:repeat(2,1fr)}}
  `;
  document.head.appendChild(style);

  function html(value) {
    return String(value == null ? '—' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
  }

  function formatMoney(value) {
    return value == null ? '—' : `$${Number(value).toFixed(2)}`;
  }

  function formatDateTime(value) {
    return value ? new Date(value).toLocaleString() : '—';
  }

  async function trackerApi(path, options = {}) {
    const headers = {
      ...(options.headers || {}),
      Authorization: `Bearer ${localStorage.getItem('mmit_ops_v2_token') || ''}`,
      'Content-Type': 'application/json',
    };
    const response = await fetch(path, { ...options, headers });
    let body = {};
    try { body = await response.json(); } catch {}
    if (!response.ok) {
      const error = new Error(body.error || `Request failed (${response.status})`);
      error.status = response.status;
      throw error;
    }
    return body;
  }

  function setPreview(markup) {
    const target = document.getElementById('trackerImportPreview');
    if (target) target.innerHTML = markup;
  }

  function packetValue(packet, key) {
    if (packet && packet[key] != null) return packet[key];
    if (packet && packet.shift) {
      if (key === 'shiftId' && packet.shift.id != null) return packet.shift.id;
      if (packet.shift[key] != null) return packet.shift[key];
    }
    if (packet && packet.metadata && packet.metadata[key] != null) return packet.metadata[key];
    const events = Array.isArray(packet && packet.events) ? packet.events : [];
    for (const event of events) {
      if (event && event.payload && event.payload[key] != null) return event.payload[key];
    }
    return null;
  }

  function eventTypes(packet) {
    return Array.isArray(packet && packet.events)
      ? packet.events.map((event) => event && event.type).filter(Boolean)
      : [];
  }

  function renderPreview(packet) {
    const schema = packet && (packet.schema || packet.packetSchema) || '—';
    const workOrderNumber = packetValue(packet, 'workOrderNumber');
    const shiftId = packetValue(packet, 'shiftId');
    const roundTrip = packetValue(packet, 'roundTripExpected');
    const events = eventTypes(packet);
    const exportedAt = packetValue(packet, 'exportedAtEpochMs') || packetValue(packet, 'exportedAt');
    const exportedText = exportedAt ? formatDateTime(typeof exportedAt === 'number' ? new Date(exportedAt).toISOString() : exportedAt) : '—';

    const problems = [];
    if (!packet || typeof packet !== 'object' || Array.isArray(packet)) problems.push('The selected file is not a JSON object.');
    if (!events.length) problems.push('No event list was found; the server will reject this packet.');
    if (!workOrderNumber) problems.push('No work-order number was found in the packet.');

    setPreview(`
      <div class="muted">${html(selectedFileName)} · ${events.length} event${events.length === 1 ? '' : 's'}</div>
      <div class="tracker-import-summary">
        <div><small>Work order</small><strong>${html(workOrderNumber)}</strong></div>
        <div><small>Packet schema</small><strong>${html(schema)}</strong></div>
        <div><small>Shift ID</small><strong>${html(shiftId)}</strong></div>
        <div><small>Round trip</small><strong>${roundTrip == null ? '—' : roundTrip ? 'Yes' : 'No'}</strong></div>
        <div><small>Exported</small><strong>${html(exportedText)}</strong></div>
        <div><small>Event sequence</small><strong>${html(events.join(' → '))}</strong></div>
      </div>
      ${problems.length ? `<div class="tracker-import-error">${problems.map(html).join('<br>')}</div>` : `
        <div class="actions" style="margin-top:13px;justify-content:flex-start">
          <button type="button" id="applyTrackerImport">Apply tracker import</button>
        </div>
      `}
    `);

    const apply = document.getElementById('applyTrackerImport');
    if (apply) apply.onclick = applyImport;
  }

  async function applyImport() {
    if (!selectedPacket || !activeWorkOrderId) {
      setPreview('<div class="tracker-import-error">Select a work order and packet first.</div>');
      return;
    }
    const apply = document.getElementById('applyTrackerImport');
    if (apply) {
      apply.disabled = true;
      apply.textContent = 'Applying…';
    }
    try {
      const result = await trackerApi(`/api/v1/work-orders/${encodeURIComponent(activeWorkOrderId)}/tracker-import`, {
        method: 'POST',
        body: JSON.stringify(selectedPacket),
      });
      const data = result.data || {};
      const imported = data.trackerImport || {};
      const workOrder = data.workOrder || {};
      const warnings = Array.isArray(imported.warnings) ? imported.warnings : [];
      setPreview(`
        <div class="tracker-import-success">
          ${data.idempotent ? 'This packet was already imported; no changes were made.' : 'Tracker packet imported and work order updated.'}
          <br><small>Import ${html(imported.id)} · ${html(workOrder.status)} · ${html(workOrder.actualGrossPay == null ? 'payout unchanged' : formatMoney(workOrder.actualGrossPay))}</small>
        </div>
        ${warnings.length ? `<div class="tracker-import-warning"><strong>Review warnings</strong><br>${warnings.map(html).join('<br>')}</div>` : ''}
      `);
      if (typeof load === 'function' && typeof openWorkOrder === 'function') {
        await load();
        await openWorkOrder(activeWorkOrderId);
      } else {
        window.location.reload();
      }
    } catch (error) {
      setPreview(`<div class="tracker-import-error">${html(error.message)}</div>`);
    } finally {
      const current = document.getElementById('applyTrackerImport');
      if (current) {
        current.disabled = false;
        current.textContent = 'Apply tracker import';
      }
    }
  }

  function mount() {
    const detailContent = document.getElementById('detailContent');
    if (!detailContent || !activeWorkOrderId || !detailContent.querySelector('.detail-grid')) return;
    if (document.getElementById('trackerImportCard')) return;
    const card = document.createElement('div');
    card.id = 'trackerImportCard';
    card.className = 'tracker-import-card';
    card.innerHTML = `
      <div class="tracker-import-heading">
        <div><div class="label">FieldNation tracker</div><strong>Import completed outing</strong></div>
        <span class="pill">JSON</span>
      </div>
      <p class="tracker-import-copy">Preview the mobile tracker packet, then apply its verified time and mileage to this work order.</p>
      <div class="tracker-import-actions">
        <input id="trackerPacketFile" type="file" accept="application/json,.json">
        <button type="button" id="previewTrackerPacket" class="secondary">Preview packet</button>
      </div>
      <div id="trackerImportPreview"></div>
    `;
    detailContent.appendChild(card);
    const file = document.getElementById('trackerPacketFile');
    const preview = document.getElementById('previewTrackerPacket');
    file.onchange = () => {
      selectedPacket = null;
      selectedFileName = file.files && file.files[0] ? file.files[0].name : '';
      setPreview('');
    };
    preview.onclick = async () => {
      const selected = file.files && file.files[0];
      if (!selected) {
        setPreview('<div class="tracker-import-error">Choose a JSON packet first.</div>');
        return;
      }
      try {
        selectedPacket = JSON.parse(await selected.text());
        renderPreview(selectedPacket);
      } catch (error) {
        selectedPacket = null;
        setPreview(`<div class="tracker-import-error">The selected file is not valid JSON: ${html(error.message)}</div>`);
      }
    };
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest && event.target.closest('[data-work-order-id]');
    if (button) {
      activeWorkOrderId = button.dataset.workOrderId;
      selectedPacket = null;
      selectedFileName = '';
    }
  }, true);

  const detailContent = document.getElementById('detailContent');
  if (detailContent) {
    new MutationObserver(() => setTimeout(mount, 0)).observe(detailContent, { childList: true });
  }
})();
