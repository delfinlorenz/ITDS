        </div><!-- end page-content -->
    </main>
</div><!-- end app -->

<!-- QR Modal -->
<div class="modal" id="qrModal">
    <div class="modal-content" style="max-width:340px;text-align:center">
        <button class="modal-close" onclick="this.closest('.modal').classList.remove('show')"><i class="fas fa-times"></i></button>
        <h3 id="qrTitle" style="margin-bottom:18px">QR Code</h3>
        <div id="qrContainer" style="display:flex;justify-content:center;margin-bottom:18px"></div>
        <button class="btn btn-info" onclick="downloadQR()"><i class="fas fa-download"></i> Download</button>
    </div>
</div>

<div id="toastContainer" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px"></div>

<script src="<?= baseUrl() ?>/assets/js/app.js"></script>
</body>
</html>
