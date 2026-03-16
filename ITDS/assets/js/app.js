/* ============================================================
   ULTIMATE ITAM — app.js
   Shared client-side utilities
   ============================================================ */

// ---- Dark Mode ----
(function () {
    const toggle = document.getElementById('darkmodeToggle');
    if (!toggle) return;
    const stored = localStorage.getItem('itam_dark');
    if (stored === '1') { document.body.classList.add('dark'); toggle.checked = true; }
    toggle.addEventListener('change', () => {
        document.body.classList.toggle('dark', toggle.checked);
        localStorage.setItem('itam_dark', toggle.checked ? '1' : '0');
    });
})();

// ---- Logout dropdown ----
window.toggleLogout = function () {
    document.getElementById('logoutDropdown').classList.toggle('show');
};
document.addEventListener('click', e => {
    const avatar = document.querySelector('.avatar');
    const dd = document.getElementById('logoutDropdown');
    if (avatar && dd && !avatar.contains(e.target)) dd.classList.remove('show');
});

// ---- Toast ----
window.showToast = function (message, type = 'info') {
    const icons = { success: 'check-circle', warning: 'exclamation-triangle', danger: 'exclamation-circle', info: 'info-circle' };
    const colors = { success: '#059669', warning: '#d97706', danger: '#dc2626', info: '#0891b2' };
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <i class="fas fa-${icons[type] || 'info-circle'}" style="color:${colors[type]};font-size:1.1rem"></i>
        <span>${message}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
    `;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4500);
};

// ---- Modal ----
window.openModal = function (html) {
    document.getElementById('sharedModalContent').innerHTML =
        `<button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>` + html;
    document.getElementById('sharedModal').classList.add('show');
};
window.closeModal = function () {
    document.getElementById('sharedModal').classList.remove('show');
};
document.getElementById('sharedModal')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});

// ---- Smart Search ----
let searchTimeout;
window.handleSmartSearch = function (term) {
    clearTimeout(searchTimeout);
    const box = document.getElementById('suggestions');
    if (!box) return;
    if (term.length < 2) { box.style.display = 'none'; return; }
    searchTimeout = setTimeout(() => {
        fetch((document.querySelector('meta[name=base-url]')?.content||'') + '/api/search.php?q=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(data => {
                if (!data.results || data.results.length === 0) { box.style.display = 'none'; return; }
                box.innerHTML = data.results.map(r => `
                    <div class="suggestion-item" onclick="window.location='${r.url}'">
                        <i class="fas fa-${r.icon}" style="margin-right:7px"></i>
                        <strong>${r.title}</strong> ${r.subtitle ? '— ' + r.subtitle : ''}
                    </div>
                `).join('');
                box.style.display = 'block';
            });
    }, 250);
};
document.addEventListener('click', e => {
    const box = document.getElementById('suggestions');
    const wrapper = document.querySelector('.search-wrapper');
    if (box && wrapper && !wrapper.contains(e.target)) box.style.display = 'none';
});

// ---- AJAX helpers ----
window.apiPost = async function (url, data) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    return res.json();
};

window.apiGet = async function (url) {
    const res = await fetch(url);
    return res.json();
};

// ---- Confirm delete helper ----
window.confirmDelete = function (url, message = 'Delete this item?') {
    if (!confirm(message)) return;
    fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ _method: 'DELETE' }) })
        .then(r => r.json())
        .then(d => {
            showToast(d.message || 'Deleted', d.error ? 'danger' : 'success');
            if (!d.error) setTimeout(() => location.reload(), 800);
        });
};

// ---- Ping simulation ----
window.pingDevice = function (ip) {
    showToast(`Pinging ${ip}...`, 'info');
    setTimeout(() => {
        showToast(Math.random() > 0.2 ? `${ip} is responding (${Math.floor(Math.random()*80)+5}ms)` : `${ip} is not responding`, Math.random() > 0.2 ? 'success' : 'danger');
    }, 1000);
};

// ---- QR code helper ----
window.showQR = function (text, label) {
    const html = `
        <h2 style="margin-bottom:16px">QR Code — ${label}</h2>
        <div class="qr-wrapper" id="qrTarget"></div>
        <div style="display:flex;gap:10px;justify-content:center;margin-top:12px">
            <button class="btn" onclick="downloadQR()"><i class="fas fa-download"></i> Download</button>
            <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
    `;
    openModal(html);
    setTimeout(() => {
        QRCode.toCanvas(document.createElement('canvas'), text, { width: 230 }, (err, canvas) => {
            if (!err) { canvas.id = 'qrCanvas'; document.getElementById('qrTarget').appendChild(canvas); }
        });
    }, 100);
};
window.downloadQR = function () {
    const canvas = document.getElementById('qrCanvas');
    if (!canvas) return;
    const a = document.createElement('a');
    a.download = 'qrcode.png';
    a.href = canvas.toDataURL();
    a.click();
};

// ---- Export table to Excel ----
window.exportTableToExcel = function (tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const wb = XLSX.utils.table_to_book(table);
    XLSX.writeFile(wb, (filename || 'export') + '.xlsx');
    showToast('Exported to Excel', 'success');
};

// ---- Format Peso (client-side) ----
window.formatPeso = function (amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// ---- Flash messages from PHP session ----
document.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('flashMessage');
    if (flash) {
        showToast(flash.dataset.message, flash.dataset.type || 'info');
    }
});
