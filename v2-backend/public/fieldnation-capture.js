(function () {
  'use strict';

  const params = new URLSearchParams(window.location.search);
  if (params.get('fnCapture') !== '1') return;

  const sourceReference = params.get('sourceReference');
  if (!sourceReference || !/^\d{4,32}$/.test(sourceReference)) return;

  document.documentElement.innerHTML = `
    <head><title>FieldNation capture receiver</title><meta name="viewport" content="width=device-width,initial-scale=1"></head>
    <body style="margin:0;background:#061426;color:#e8f0fb;font:16px system-ui,sans-serif">
      <main style="max-width:560px;margin:48px auto;padding:24px;border:1px solid #29415f;border-radius:12px;background:#0a192c">
        <div style="color:#67a8ff;font-size:12px;font-weight:700;letter-spacing:.08em">FIELDNATION SOURCE PACKET</div>
        <h1 style="margin:10px 0;font-size:24px">Awaiting page capture</h1>
        <p id="status" style="color:#b8c8dc;line-height:1.5">The capture bookmark reads the work-order number from this FieldNation page. It accepts only rendered page text and visible HTTP(S) links; it never receives FieldNation credentials or cookies.</p>
        <button id="close" type="button" style="display:none;padding:10px 14px;border:0;border-radius:8px;background:#5f9df4;color:#061426;font-weight:700;cursor:pointer">Close</button>
      </main>
    </body>`;

  const status = document.getElementById('status');
  const close = document.getElementById('close');
  close.addEventListener('click', () => window.close());

  function setStatus(message, success) {
    status.textContent = message;
    status.style.color = success ? '#b8f2da' : '#f8d895';
    close.style.display = 'inline-block';
  }

  function ready() {
    if (!window.opener || window.opener.closed) {
      setStatus('The OPS window that started this capture is no longer available. Close this window and start again.', false);
      return;
    }
    window.opener.postMessage({ type: 'mmit-fn-capture-ready', sourceReference }, 'https://app.fieldnation.com');
  }

  window.addEventListener('message', async (event) => {
    if (event.origin !== 'https://app.fieldnation.com' || event.source !== window.opener) return;
    const payload = event.data;
    if (!payload || payload.type !== 'mmit-fn-capture-payload' || payload.sourceReference !== sourceReference) return;
    const token = localStorage.getItem('mmit_ops_v2_token');
    if (!token) {
      setStatus('Your OPS session is unavailable. Close this window, sign in to OPS, and start the capture again.', false);
      return;
    }
    status.textContent = 'Saving the source packet to OPS…';
    try {
      const response = await fetch('/api/v1/fieldnation/captures', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sourceReference,
          sourceUrl: payload.sourceUrl,
          pageTitle: payload.pageTitle,
          visibleText: payload.visibleText,
          capturedAt: payload.capturedAt,
          links: payload.links,
        }),
      });
      let body = {};
      try { body = await response.json(); } catch {}
      if (!response.ok) throw new Error(body.error || `Capture failed (${response.status})`);
      const importId = body && body.data && body.data.id;
      if (!importId) throw new Error('Capture saved, but OPS did not return its intake record. Refresh the dashboard.');
      localStorage.setItem('mmit_ops_v2_fn_capture_notice', JSON.stringify({ importId, capturedAt: Date.now() }));
      setStatus(body.created ? 'Capture saved as a new V2 intake record. You can close this window; OPS will open its review.' : 'Capture attached to the existing V2 intake record. You can close this window; OPS will open its review.', true);
    } catch (error) {
      setStatus(error && error.message ? error.message : 'Capture could not be saved.', false);
    }
  });

  setTimeout(ready, 50);
}());
