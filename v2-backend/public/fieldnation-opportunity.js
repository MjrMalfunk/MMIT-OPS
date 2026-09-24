(() => {
  const original = window.renderImports;
  const esc = value => String(value ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => value == null ? '—' : `$${Number(value).toFixed(2)}`;
  const meta = item => item.parsedData?.opportunity || null;
  const ensureStyles = () => {
    if (document.getElementById('fnOpportunityQueueStyles')) return;
    const style = document.createElement('style');
    style.id = 'fnOpportunityQueueStyles';
    style.textContent = `
      #fnOpportunityQueue .fn-queue-list { display: grid; gap: 12px; }
      #fnOpportunityQueue .fn-opp-card { border: 1px solid rgba(148, 163, 184, .24); border-radius: 12px; padding: 16px; background: rgba(15, 23, 42, .46); }
      #fnOpportunityQueue .fn-opp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
      #fnOpportunityQueue .fn-opp-scoreline { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
      #fnOpportunityQueue .fn-opp-score { min-width: 48px; color: #62e58b; font-size: 1.12rem; font-weight: 700; }
      #fnOpportunityQueue .fn-opp-recommendation { display: block; font-size: 1rem; }
      #fnOpportunityQueue .fn-opp-reasons { display: block; margin-top: 3px; color: #a9b7cc; font-size: .82rem; line-height: 1.35; }
      #fnOpportunityQueue .fn-opp-status { flex: 0 0 auto; text-align: right; }
      #fnOpportunityQueue .fn-opp-status small { display: block; margin-top: 4px; color: #a9b7cc; }
      #fnOpportunityQueue .fn-opp-title { display: flex; flex-wrap: wrap; gap: 6px 12px; margin-top: 13px; padding-top: 13px; border-top: 1px solid rgba(148, 163, 184, .18); }
      #fnOpportunityQueue .fn-opp-title strong { overflow-wrap: anywhere; }
      #fnOpportunityQueue .fn-opp-title .fn-opp-wo { color: #cbd5e1; white-space: nowrap; }
      #fnOpportunityQueue .fn-opp-title small { flex-basis: 100%; color: #a9b7cc; overflow-wrap: anywhere; }
      #fnOpportunityQueue .fn-opp-details { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-top: 13px; }
      #fnOpportunityQueue .fn-opp-detail { min-width: 0; }
      #fnOpportunityQueue .fn-opp-detail label { display: block; color: #93a4ba; font-size: .72rem; letter-spacing: .04em; text-transform: uppercase; }
      #fnOpportunityQueue .fn-opp-detail strong { display: block; margin-top: 4px; overflow-wrap: anywhere; }
      #fnOpportunityQueue .fn-opp-detail small { display: block; margin-top: 3px; color: #a9b7cc; line-height: 1.3; }
      #fnOpportunityQueue .fn-opp-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 14px; }
      #fnOpportunityQueue .fn-opp-action-buttons { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
      @media (max-width: 1000px) { #fnOpportunityQueue .fn-opp-details { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
      @media (max-width: 640px) {
        #fnOpportunityQueue .fn-opp-head { flex-direction: column; }
        #fnOpportunityQueue .fn-opp-status { text-align: left; }
        #fnOpportunityQueue .fn-opp-details { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        #fnOpportunityQueue .fn-opp-action-buttons { margin-left: 0; width: 100%; }
      }
    `;
    document.head.appendChild(style);
  };
  function mount(rows) {
    let panel = document.getElementById('fnOpportunityQueue');
    if (!panel) {
      panel = document.createElement('section'); panel.id = 'fnOpportunityQueue'; panel.className = 'card'; panel.style.marginTop = '18px';
      panel.innerHTML = '<div class="actions" style="justify-content:space-between;align-items:center"><div><h2 style="margin-bottom:3px">FieldNation opportunity queue</h2><small class="muted">Read-only scan of the existing FieldNation mail folder.</small></div><div class="actions" style="justify-content:flex-end"><button id="fnRefreshParser" class="secondary" type="button">Refresh parsed data</button><button id="fnScanMailbox" type="button">Import opportunity email</button></div></div><div id="fnQueueBody" style="margin-top:14px"></div>';
      document.getElementById('imports')?.closest('section')?.after(panel);
      panel.querySelector('#fnScanMailbox').onclick = scan;
      panel.querySelector('#fnRefreshParser').onclick = refreshParser;
    }
    ensureStyles();
    // Mail folders also contain follow-up/message notifications. Keep those
    // in the regular intake list, but only show actionable opportunity events
    // here. Collapse repeated notices for the same W/O to the newest row.
    const looksLikeFollowUp = item => {
      const subject = String(item.subject || '');
      const title = String(item.title || '');
      return /\bnew message\b|still need(?: a)? tech|anyone assist|technician that can complete|available to assist|we are in need of a technician|good morning.*available/i.test(`${subject} ${title}`);
    };
    const actionable = rows.filter(meta).filter(item => ['AVAILABLE', 'ROUTED', 'ASSIGNED'].includes(meta(item).status)).filter(item => !looksLikeFollowUp(item)).filter(item => meta(item).decision !== 'IGNORED');
    const seen = new Set();
    const queue = actionable.filter(item => {
      const key = item.sourceReference || item.id;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
    const body = panel.querySelector('#fnQueueBody');
    if (!queue.length) { body.innerHTML = '<div class="empty">No imported FieldNation opportunities yet.</div>'; return; }
    body.innerHTML = '<div class="fn-queue-list">' + queue.map(item => {
      const opportunity = meta(item), profit = opportunity.profitability || {}, converted = Boolean(item.workOrderId);
      return `<article class="fn-opp-card"><div class="fn-opp-head"><div class="fn-opp-scoreline"><span class="fn-opp-score">${esc(item.score)}</span><div><strong class="fn-opp-recommendation">${esc(opportunity.recommendation)}</strong><small class="fn-opp-reasons">${esc((item.parsedData?.scoreReasons || []).slice(0,2).join(' · '))}</small></div></div><div class="fn-opp-status"><span class="pill ${opportunity.status === 'ROUTED' ? '' : 'warn'}">${esc(opportunity.status)}</span><small>${esc(opportunity.decision || 'REVIEW')}</small></div></div><div class="fn-opp-title"><strong>${esc(opportunity.buyerName)}</strong><span class="fn-opp-wo">W/O #${esc(item.sourceReference || '—')}</span><small>${esc(item.title)}</small></div><div class="fn-opp-details"><div class="fn-opp-detail"><label>Location</label><strong>${esc(item.location)}</strong><small>${item.mileage == null ? 'distance unknown' : `${Number(item.mileage).toFixed(1)} mi one-way`}</small></div><div class="fn-opp-detail"><label>Schedule</label><strong>${item.scheduledAt ? esc(new Date(item.scheduledAt).toLocaleString()) : '—'}</strong></div><div class="fn-opp-detail"><label>Pay</label><strong>${money(item.grossPay)}</strong><small>${esc(item.estimatedHours)} hr est.</small></div><div class="fn-opp-detail"><label>Real profit</label><strong>${profit.travel_known ? `${money(profit.estimated_net)} net` : 'Needs distance'}</strong><small>${profit.complete ? `${money(profit.effective_hourly)}/hr door-to-door` : 'Needs assumptions'}</small></div><div class="fn-opp-detail"><label>Decision</label><strong>${esc(opportunity.decision || 'REVIEW')}</strong><small>${esc(opportunity.status)}</small></div></div><div class="fn-opp-actions">${converted ? `<small>Requested W/O #${esc(item.workOrderId)}</small>` : `<button data-fn-request="${esc(item.id)}" type="button">Create REQUESTED W/O</button>`}<div class="fn-opp-action-buttons"><button class="secondary" data-fn-watch="${esc(item.id)}" type="button">Watch</button><button class="danger" data-fn-ignore="${esc(item.id)}" type="button">Ignore</button></div></div></article>`;
    }).join('') + '</div>';
    body.querySelectorAll('[data-fn-request]').forEach(button => button.onclick = async () => { if (!confirm('Create a REQUESTED-stage work order only? This does not accept or schedule the FieldNation job.')) return; try { await window.api(`/api/v1/fieldnation/imports/${button.dataset.fnRequest}/convert`, { method: 'POST' }); await window.load(); } catch (error) { alert(error.message); } });
    body.querySelectorAll('[data-fn-watch],[data-fn-ignore]').forEach(button => button.onclick = async () => { const decision = button.dataset.fnWatch ? 'WATCHING' : 'IGNORED'; try { await window.api(`/api/v1/fieldnation/imports/${button.dataset.fnWatch || button.dataset.fnIgnore}/opportunity-decision`, { method: 'PATCH', body: JSON.stringify({ decision }) }); await window.load(); } catch (error) { alert(error.message); } });
  }
  async function refreshParser() { const button = document.getElementById('fnRefreshParser'); button.disabled = true; button.textContent = 'Refreshing…'; try { const result = await window.api('/api/v1/fieldnation/mailbox/reparse', { method: 'POST' }); const stats = result.data; alert(`Parser refresh complete: ${stats.refreshed} email opportunities refreshed.${stats.errors.length ? ` ${stats.errors.length} error(s); see API logs.` : ''}`); await window.load(); } catch (error) { alert(error.message); } finally { button.disabled = false; button.textContent = 'Refresh parsed data'; } }
  async function scan() { const button = document.getElementById('fnScanMailbox'); button.disabled = true; button.textContent = 'Importing…'; try { const result = await window.api('/api/v1/fieldnation/mailbox/scan', { method: 'POST', body: JSON.stringify({ limit: 25 }) }); const stats = result.data; alert(`Mailbox scan complete: ${stats.imported} imported, ${stats.duplicates} already known.${stats.errors.length ? ` ${stats.errors.length} error(s); see API logs.` : ''}`); await window.load(); } catch (error) { alert(error.message); } finally { button.disabled = false; button.textContent = 'Import opportunity email'; } }
  if (typeof original === 'function') window.renderImports = rows => { original(rows); mount(rows); };
})();
