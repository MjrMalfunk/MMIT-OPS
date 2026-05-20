document.addEventListener('DOMContentLoaded', function () {
    const bundleRows = document.querySelectorAll('.bundle-row');
    const includedContainer = document.getElementById('included-services');
    const addonContainer = document.getElementById('addon-services');
    const previewBox = document.getElementById('bundle-preview');

    if (!bundleRows.length || !includedContainer || !addonContainer || !previewBox) {
        return;
    }

    bundleRows.forEach((row) => {
        row.addEventListener('click', function () {
            const bundleId = this.dataset.bundleId;
            if (!bundleId) return;

            fetch(`${window.BASE_URL || ''}/ajax/get_bundle.php?bundle_id=${encodeURIComponent(bundleId)}`)
                .then((res) => res.json())
                .then((data) => {
                    if (data.error) {
                        previewBox.innerHTML = `<div>${data.error}</div>`;
                        return;
                    }
                    loadBundle(data);
                })
                .catch(() => {
                    previewBox.innerHTML = '<div>Unable to load bundle preview.</div>';
                });
        });
    });

    function loadBundle(data) {
        renderServices(includedContainer, data.included || [], true);
        renderServices(addonContainer, data.addons || [], false);
        renderPreview(data);
    }

    function renderServices(container, items, checked) {
        container.innerHTML = '';
        items.forEach((item) => {
            const el = document.createElement('div');
            el.innerHTML = `
                <label>
                    <input type="checkbox" ${checked ? 'checked' : ''} data-name="${escapeHtml(item.item_name || '')}">
                    ${escapeHtml(item.item_name || '')}
                </label>
            `;
            container.appendChild(el);
        });
        attachPreviewListeners();
    }

    function attachPreviewListeners() {
        document.querySelectorAll('#included-services input, #addon-services input').forEach((input) => {
            input.addEventListener('change', updatePreview);
        });
    }

    function updatePreview() {
        const included = getChecked('#included-services');
        const addons = getChecked('#addon-services');
        previewBox.innerHTML = `
            <h4>Bundle Preview</h4>
            <strong>Included</strong>
            <ul>${included.map((i) => `<li>${escapeHtml(i)}</li>`).join('')}</ul>
            <strong>Add-ons</strong>
            <ul>${addons.map((i) => `<li>${escapeHtml(i)}</li>`).join('')}</ul>
        `;
    }

    function renderPreview(data) {
        const included = (data.included || []).map((i) => i.item_name || 'Included service');
        const addons = (data.addons || []).map((i) => i.item_name || 'Add-on');
        previewBox.innerHTML = `
            <h4>${escapeHtml(data.bundle.bundle_name || 'Bundle')}</h4>
            <div>${escapeHtml(data.bundle.description || '')}</div>
            <strong>Included</strong>
            <ul>${included.map((i) => `<li>${escapeHtml(i)}</li>`).join('')}</ul>
            <strong>Add-ons</strong>
            <ul>${addons.map((i) => `<li>${escapeHtml(i)}</li>`).join('')}</ul>
        `;
    }

    function getChecked(selector) {
        return Array.from(document.querySelectorAll(selector + ' input:checked')).map((i) => i.dataset.name);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
