/**
 * Modal pembatalan: pratinjau refund berdasarkan selisih hari.
 * Perhitungan di sini hanya tampilan; angka resmi dihitung ulang oleh PHP (proses_batal.php).
 *   > 30 hari  : 75% DP | 14–30 hari : 50% DP | < 14 hari : 0% (DP hangus)
 */
(function () {
    'use strict';
    var modal = document.getElementById('modalBatal');
    if (!modal) { return; }

    var REFUND_PELUNASAN = parseInt(modal.getAttribute('data-refund-pelunasan'), 10);
    if (isNaN(REFUND_PELUNASAN)) { REFUND_PELUNASAN = 100; }
    var TODAY = modal.getAttribute('data-today'); // tanggal server (YYYY-MM-DD)
    var fmt = function (n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(n); };
    var $ = function (sel) { return modal.querySelector(sel); };

    function selisihHari(dari, ke) {
        var a = new Date(dari + 'T00:00:00'), b = new Date(ke + 'T00:00:00');
        return Math.round((b - a) / 86400000);
    }
    function persen(selisih) { return selisih > 30 ? 75 : (selisih >= 14 ? 50 : 0); }

    modal.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget;
        var tgl = b.getAttribute('data-tanggal');
        var dp = parseInt(b.getAttribute('data-dp'), 10) || 0;
        var pel = parseInt(b.getAttribute('data-pelunasan'), 10) || 0;

        $('[name=id]').value = b.getAttribute('data-id');
        $('#batalKode').textContent = b.getAttribute('data-kode');
        $('#batalTanggal').textContent = b.getAttribute('data-tanggal-teks');

        var s = selisihHari(TODAY, tgl);
        var p = persen(s);
        var rDp = Math.floor(dp * p / 100);
        var rPel = Math.floor(pel * REFUND_PELUNASAN / 100);
        var total = rDp + rPel;

        $('#batalSelisih').textContent = s + ' hari';
        var box = $('#batalRincian');
        if (dp + pel === 0) {
            box.innerHTML = 'Belum ada pembayaran terverifikasi. Reservasi akan langsung dibatalkan tanpa refund.';
        } else {
            var h = 'DP terbayar ' + fmt(dp) + ' &rarr; refund <strong>' + p + '%</strong> = ' + fmt(rDp);
            if (pel > 0) { h += '<br>Pelunasan terbayar ' + fmt(pel) + ' &rarr; refund ' + REFUND_PELUNASAN + '% = ' + fmt(rPel); }
            h += '<br><span class="fs-6">Estimasi total refund: <strong>' + fmt(total) + '</strong></span>';
            if (p === 0) { h += '<br><span class="text-danger">Pembatalan kurang dari 14 hari sebelum acara: DP hangus.</span>'; }
            box.innerHTML = h;
        }
        $('#grupRekening').classList.toggle('d-none', total === 0);
        $('[name=rekening_refund]').required = total > 0;
    });
})();
