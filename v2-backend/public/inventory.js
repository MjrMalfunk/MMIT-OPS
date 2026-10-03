(function () {
  'use strict';
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const number = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
  const money = value => `$${Number(value || 0).toFixed(2)}`;
  const style = document.createElement('style');
  style.textContent = `.inventory-card{margin-top:18px}.inventory-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.inventory-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.inventory-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.inventory-form .field{margin:0}.inventory-form .wide{grid-column:span 3}.inventory-table .low-stock{color:var(--amber);font-weight:800}.inventory-help{color:var(--muted);font-size:13px;margin:0 0 13px}.inventory-status{min-height:22px;margin-top:10px;color:var(--muted)}@media(max-width:800px){.inventory-grid{grid-template-columns:1fr}.inventory-form{grid-template-columns:1fr 1fr}.inventory-form .wide{grid-column:span 2}}`;
  document.head.appendChild(style);
  function card() {
    if (document.getElementById('inventoryCard')) return;
    const host = document.getElementById('loadStatus'); if (!host) return;
    host.insertAdjacentHTML('afterend', `<section id="inventoryCard" class="card inventory-card"><div class="inventory-head"><div><h2>Inventory</h2><p class="inventory-help">Stock here is the source of truth. Use its SKU in the mobile tracker while you are onsite.</p></div><button id="inventoryRefresh" class="secondary" type="button">Refresh stock</button></div><div class="inventory-grid"><div class="table-wrap inventory-table" id="inventoryRows"></div><div><h2 style="font-size:16px">Add stocked item</h2><form id="inventoryCreate" class="inventory-form"><div class="field"><label>SKU</label><input name="sku" maxlength="100" placeholder="RJ45-CAT6" required></div><div class="field"><label>Description</label><input name="description" maxlength="255" placeholder="Cat6 RJ45 connector" required></div><div class="field"><label>Unit</label><input name="unit" maxlength="32" placeholder="each or ft" required></div><div class="field"><label>Starting stock</label><input name="quantityOnHand" type="number" min="0.001" step="0.001" required></div><div class="field"><label>Reorder point</label><input name="reorderPoint" type="number" min="0" step="0.001" value="0" required></div><div class="field"><label>Unit cost</label><input name="unitCost" type="number" min="0" step="0.01" value="0" required></div><div class="field"><label>Opening note</label><input name="notes" maxlength="10000" placeholder="Optional"></div><button class="wide" type="submit">Add to inventory</button></form></div></div><div id="inventoryStatus" class="inventory-status"></div></section>`);
    document.getElementById('inventoryRefresh').onclick = refresh;
    document.getElementById('inventoryCreate').onsubmit = createItem;
    const actions = document.createElement('div');
    actions.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end';
    const refreshButton = document.getElementById('inventoryRefresh');
    refreshButton.replaceWith(actions);
    actions.appendChild(refreshButton);
    const downloadButton = document.createElement('button');
    downloadButton.id = 'inventoryDownload';
    downloadButton.type = 'button';
    downloadButton.className = 'secondary';
    downloadButton.textContent = 'Download catalog';
    downloadButton.onclick = downloadCatalog;
    actions.appendChild(downloadButton);
    document.getElementById('inventoryStatus').setAttribute('role', 'status');
    document.getElementById('inventoryStatus').setAttribute('aria-live', 'polite');
  }
  async function request(path, options = {}) {
    const headers = { Authorization: `Bearer ${localStorage.getItem('mmit_ops_v2_token') || ''}`, ...(options.headers || {}) };
    if (options.body) headers['Content-Type'] = 'application/json';
    const response = await fetch(path, { ...options, headers }); let body = {};
    try { body = await response.json(); } catch {}
    if (!response.ok) throw Error(body.error || `Request failed (${response.status})`); return body;
  }
  function render(items) {
    const target = document.getElementById('inventoryRows'); if (!target) return;
    if (!items.length) { target.innerHTML = '<div class="empty">No stock items yet. Add your first cable, connector, or other supply on the right.</div>'; return; }
    target.innerHTML = `<table><thead><tr><th>Item</th><th>On hand</th><th>Cost</th><th></th></tr></thead><tbody>${items.map(item => `<tr><td><strong>${escapeHtml(item.sku)}</strong><small>${escapeHtml(item.description)}</small></td><td class="${item.lowStock ? 'low-stock' : ''}">${number(item.quantityOnHand)} ${escapeHtml(item.unit)}<small>Reorder at ${number(item.reorderPoint)}</small></td><td>${money(item.unitCost)}</td><td><button type="button" class="secondary" data-adjust="${escapeHtml(item.id)}" data-sku="${escapeHtml(item.sku)}">Adjust</button></td></tr>`).join('')}</tbody></table>`;
    target.querySelectorAll('[data-adjust]').forEach(button => button.onclick = () => adjustItem(button.dataset.adjust, button.dataset.sku));
  }
  async function refresh() {
    card(); const status = document.getElementById('inventoryStatus'); if (!status) return; status.textContent = 'Loading inventory…';
    try { const response = await request('/api/v1/inventory'); render(Array.isArray(response.data) ? response.data : []); status.textContent = 'Inventory is current.'; } catch (error) { status.textContent = error.message; }
  }
  async function downloadCatalog() {
    const button = document.getElementById('inventoryDownload');
    const status = document.getElementById('inventoryStatus');
    button.disabled = true;
    status.textContent = 'Downloading inventory catalog…';
    try {
      // Read-only authenticated requests; never include the login token or stock movement history in the file.
      const [inventory, identity] = await Promise.all([request('/api/v1/inventory'), request('/api/v1/auth/me')]);
      const user = identity.data;
      if (!Array.isArray(inventory.data) || !user?.id || !user?.email) throw Error('The inventory catalog could not be downloaded.');
      const catalog = {
        schema: 'mmit.inventory-catalog.v1',
        serverUrl: window.location.origin,
        userId: String(user.id), email: user.email,
        syncedAtEpochMs: Date.now(),
        items: inventory.data.filter(item => item.active).map(item => ({
          sku: item.sku, description: item.description, unit: item.unit,
          quantityOnHand: item.quantityOnHand, unitCost: item.unitCost, active: true,
        })),
      };
      const blob = new Blob([JSON.stringify(catalog, null, 2) + '\n'], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `mmit-inventory-catalog-${new Date(catalog.syncedAtEpochMs).toISOString().slice(0, 10)}.json`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
      status.textContent = 'Catalog downloaded. Send it to your phone and choose Import catalog JSON in the tracker.';
    } catch (error) { status.textContent = error.message; }
    finally { button.disabled = false; }
  }
  async function createItem(event) {
    event.preventDefault(); const form = event.currentTarget; const status = document.getElementById('inventoryStatus'); const values = Object.fromEntries(new FormData(form).entries()); status.textContent = 'Adding stock item…';
    try { await request('/api/v1/inventory', { method: 'POST', body: JSON.stringify(values) }); form.reset(); form.reorderPoint.value = '0'; form.unitCost.value = '0'; await refresh(); status.textContent = 'Stock item added.'; } catch (error) { status.textContent = error.message; }
  }
  async function adjustItem(id, sku) {
    const quantityDelta = prompt(`Adjustment for ${sku}. Use a positive number for stock received or a negative number for a correction.`, ''); if (quantityDelta === null) return;
    const notes = prompt('Why is this stock changing?', ''); if (!notes) return; const status = document.getElementById('inventoryStatus'); status.textContent = 'Saving adjustment…';
    try { await request(`/api/v1/inventory/${encodeURIComponent(id)}/adjustments`, { method: 'POST', body: JSON.stringify({ quantityDelta, notes }) }); await refresh(); status.textContent = `Inventory adjusted for ${sku}.`; } catch (error) { status.textContent = error.message; }
  }
  const originalLoad = window.load;
  window.load = async function () { await originalLoad(); await refresh(); };
  setTimeout(() => { if (localStorage.getItem('mmit_ops_v2_token')) refresh(); }, 300);
}());
