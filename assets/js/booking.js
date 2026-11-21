/**
 * Halaman booking: kalender pilih tanggal + kalkulator harga & DP.
 *   Total   = Harga Dasar Hall + Σ fasilitas tambahan yang dipilih
 *   Min DP  = DP_PERSEN% × Total (dibulatkan ke atas)
 *   Sisa    = Total − DP yang dibayar
 */
(function () {
    'use strict';
    var form = document.getElementById('formBooking');
    if (!form) { return; }

    var DP_PERSEN = parseInt(form.getAttribute('data-dp-persen'), 10) || 30;
    var fmt = function (n) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(n); };
    var $ = function (id) { return document.getElementById(id); };

    var hallSel = $('hall_id');
    var tglInput = $('tanggal_acara');
    var tamuInput = $('jumlah_tamu');
    var dpInput = $('nominal_dp');
    var dpManual = dpInput.value !== '';

    function hall() {
        var o = hallSel.options[hallSel.selectedIndex];
        return {
            nama: o ? o.getAttribute('data-nama') : '-',
            harga: o ? (parseInt(o.getAttribute('data-harga'), 10) || 0) : 0,
            kapasitas: o ? (parseInt(o.getAttribute('data-kapasitas'), 10) || 0) : 0
        };
    }

    function fasilitasDipilih() {
        var total = 0, daftar = [];
        form.querySelectorAll('.cb-fasilitas:checked').forEach(function (cb) {
            total += parseInt(cb.getAttribute('data-harga'), 10) || 0;
            daftar.push(cb.getAttribute('data-nama'));
        });
        return { total: total, daftar: daftar };
    }

    function minDp(total) { return Math.floor((total * DP_PERSEN + 99) / 100); }

    function hitung() {
        var h = hall();
        var f = fasilitasDipilih();
        var total = h.harga + f.total;
        var min = minDp(total);

        if (!dpManual || dpInput.value === '') { dpInput.value = min; }
        var dp = parseInt(dpInput.value, 10) || 0;
        var sisa = Math.max(total - dp, 0);

        $('sumHall').textContent = h.nama;
        $('sumHargaDasar').textContent = fmt(h.harga);
        $('sumFasilitas').textContent = fmt(f.total);
        $('sumFasilitasList').textContent = f.daftar.length ? f.daftar.join(', ') : 'Tidak ada fasilitas tambahan';
        $('sumTotal').textContent = fmt(total);
        $('sumMinDp').textContent = fmt(min);
        $('sumDp').textContent = fmt(dp);
        $('sumSisa').textContent = fmt(sisa);
        $('hintDp').textContent = 'Minimal ' + DP_PERSEN + '% = ' + fmt(min) + ', maksimal ' + fmt(total) + '.';
        $('hintKapasitas').textContent = 'Kapasitas maksimal ' + new Intl.NumberFormat('id-ID').format(h.kapasitas) + ' tamu.';

        dpInput.min = min;
        dpInput.max = total;
        tamuInput.max = h.kapasitas;

        var dpValid = dp >= min && dp <= total;
        dpInput.classList.toggle('is-invalid', !dpValid);
        return { total: total, min: min, dp: dp, dpValid: dpValid, kapasitas: h.kapasitas };
    }

    // Kalender
    var kal = new KalenderHall($('kalender'), {
        apiUrl: form.getAttribute('data-api'),
        hallId: hallSel.value,
        selectable: true,
        selected: tglInput.value,
        onSelect: function (tgl) {
            tglInput.value = tgl;
            $('tanggalTeks').textContent = new Date(tgl + 'T00:00:00').toLocaleDateString('id-ID', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
            });
            $('tanggalTeks').classList.remove('text-danger');
            $('sumTanggal').textContent = $('tanggalTeks').textContent;
        }
    });

    if (tglInput.value) {
        var t = new Date(tglInput.value + 'T00:00:00').toLocaleDateString('id-ID', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
        $('tanggalTeks').textContent = t;
        $('sumTanggal').textContent = t;
    }

    hallSel.addEventListener('change', function () {
        tglInput.value = '';
        $('tanggalTeks').textContent = 'Belum dipilih';
        $('sumTanggal').textContent = '-';
        kal.setHall(hallSel.value);
        hitung();
    });

    form.querySelectorAll('.cb-fasilitas').forEach(function (cb) { cb.addEventListener('change', hitung); });
    dpInput.addEventListener('input', function () { dpManual = dpInput.value !== ''; hitung(); });
    $('btnDpMin').addEventListener('click', function () { dpManual = false; dpInput.value = ''; hitung(); });
    $('btnDpFull').addEventListener('click', function () {
        dpManual = true;
        dpInput.value = hall().harga + fasilitasDipilih().total;
        hitung();
    });

    form.addEventListener('submit', function (e) {
        var r = hitung();
        var msgs = [];
        if (!tglInput.value) {
            msgs.push('Pilih tanggal acara pada kalender.');
            $('tanggalTeks').classList.add('text-danger');
        }
        var tamu = parseInt(tamuInput.value, 10) || 0;
        if (tamu < 1 || tamu > r.kapasitas) { msgs.push('Jumlah tamu harus 1 – ' + r.kapasitas + '.'); }
        if (!r.dpValid) { msgs.push('Nominal DP harus antara ' + fmt(r.min) + ' dan ' + fmt(r.total) + '.'); }
        if (msgs.length) {
            e.preventDefault();
            alert(msgs.join('\n'));
        }
    });

    hitung();
})();
