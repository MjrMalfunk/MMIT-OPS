(() => {
  const original = window.renderImports;
  const esc = value => String(value ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => value == null ? '—' : `$${Number(value).toFixed(2)}`;
  const meta = item => item.parsedData?.opportunity || null;
  function mount(rows) {
    let panel = document.getElementById('fnOpportunityQueue');
    if (!panel) {
      panel = document.createElement('section'); panel.id = 'fnOpportunityQueue'; panel.className = 'card'; panel.style.marginTop = '18px';
      panel.innerHTML = '<div class="actions" style="justify-content:space-between;align-items:center"><div><h2 style="margin-bottom:3px">FieldNation opportunity queue</h2><small class="muted">Read-only scan of the existing FieldNation mail folder.</small></div><button id="fnScanMailbox" type="button">Import opportunity email</button></div><div id="fnQueueBody" class="table-wrap" style="margin-top:14px"></div>';
      document.getElementById('imports')?.closest('section')?.after(panel);
      panel.querySelector('#fnScanMailbox').onclick = scan;
    }
    // Mail folders also contain follow-up/message notifications. Keep those
    // in the regular intake list, but only show actionable opportunity events
    // here. Collapse repeated notices for the same W/O to the newest row.
    const actionable = rows.filter(meta).filter(item => ['AVAILABLE', 'ROUTED', 'ASSIGNED'].includes(meta(item).status)).filter(item => meta(item).decision !== 'IGNORED');
    const seen = new Set();
    const queue = actionable.filter(item => {
      const key = item.sourceReference || item.id;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
    const body = panel.querySelector('#fnQueueBody');
    if (!queue.length) { body.innerHTML = '<div class="empty">No imported FieldNation opportunities yet.</div>'; return; }
    body.innerHTML = '<table><thead><tr><th>Score</th><th>Recommendation</th><th>Status</th><th>W/O</th><th>Buyer / title</th><th>Location</th><th>Schedule</th><th>Pay</th><th>Real profit</th><th>Actions</th></tr></thead><tbody>' + queue.map(item => {
      const opportunity = meta(item), profit = opportunity.profitability || {}, converted = Boolean(item.workOrderId);
      return `<tr><td><span class="score">${esc(item.score)}</span></td><td><strong>${esc(opportunity.recommendation)}</strong><small>${esc((item.parsedData?.scoreReasons || []).slice(0,2).join(' · '))}</small></td><td><span class="pill ${opportunity.status === 'ROUTED' ? '' : 'warn'}">${esc(opportunity.status)}</span><small>${esc(opportunity.decision || 'REVIEW')}</small></td><td>${esc(item.sourceReference || '—')}</td><td><strong>${esc(opportunity.buyerName)}</strong><small>${esc(item.title)}</small></td><td>${esc(item.location)}<small>${item.mileage == null ? 'distance unknown' : `${Number(item.mileage).toFixed(1)} mi one-way`}</small></td><td>${item.scheduledAt ? esc(new Date(item.scheduledAt).toLocaleString()) : '—'}</td><td>${money(item.grossPay)}<small>${esc(item.estimatedHours)} hr est.</small></td><td>${profit.travel_known ? `${money(profit.estimated_net)} net` : 'Needs distance'}<small>${profit.complete ? `${money(profit.effective_hourly)}/hr door-to-door` : 'Needs assumptions'}</small></td><td>${converted ? `<small>Requested W/O #${esc(item.workOrderId)}</small>` : `<button data-fn-request="${esc(item.id)}" type="button">Create REQUESTED W/O</button>`}<div class="actions" style="justify-content:flex-start;margin-top:7px"><button class="secondary" data-fn-watch="${esc(item.id)}" type="button">Watch</button><button class="danger" data-fn-ignore="${esc(item.id)}" type="button">Ignore</button></div></td></tr>`;
    }).join('') + '</tbody></table>';
    body.querySelectorAll('[data-fn-request]').forEach(button => button.onclick = async () => { if (!confirm('Create a REQUESTED-stage work order only? This does not accept or schedule the FieldNation job.')) return; try { await window.api(`/api/v1/fieldnation/imports/${button.dataset.fnRequest}/convert`, { method: 'POST' }); await window.load(); } catch (error) { alert(error.message); } });
    body.querySelectorAll('[data-fn-watch],[data-fn-ignore]').forEach(button => button.onclick = async () => { const decision = button.dataset.fnWatch ? 'WATCHING' : 'IGNORED'; try { await window.api(`/api/v1/fieldnation/imports/${button.dataset.fnWatch || button.dataset.fnIgnore}/opportunity-decision`, { method: 'PATCH', body: JSON.stringify({ decision }) }); await window.load(); } catch (error) { alert(error.message); } });
  }
  async function scan() { const button = document.getElementById('fnScanMailbox'); button.disabled = true; button.textContent = 'Importing…'; try { const result = await window.api('/api/v1/fieldnation/mailbox/scan', { method: 'POST', body: JSON.stringify({ limit: 25 }) }); const stats = result.data; alert(`Mailbox scan complete: ${stats.imported} imported, ${stats.duplicates} already known.${stats.errors.length ? ` ${stats.errors.length} error(s); see API logs.` : ''}`); await window.load(); } catch (error) { alert(error.message); } finally { button.disabled = false; button.textContent = 'Import opportunity email'; } }
  if (typeof original === 'function') window.renderImports = rows => { original(rows); mount(rows); };
})();
