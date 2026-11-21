/**
 * Kalender ketersediaan hall.
 * Tanggal terkunci (DP Terkonfirmasi / Lunas) diambil dari api/availability.php.
 *
 * new KalenderHall(elemen, {
 *   apiUrl, hallId, selectable: bool, selected: 'YYYY-MM-DD', onSelect: fn(tanggal)
 * })
 */
(function (global) {
    'use strict';

    var HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function ymd(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

    function KalenderHall(root, opt) {
        this.root = root;
        this.opt = Object.assign({ selectable: false, selected: '', hallId: null, onSelect: null }, opt);
        this.hallId = this.opt.hallId;
        this.selected = this.opt.selected || '';
        this.locked = new Set();
        this.today = null;
        this.reqId = 0;

        var base = new Date();
        if (/^\d{4}-\d{2}-\d{2}$/.test(this.selected)) {
            base = new Date(parseInt(this.selected.slice(0, 4), 10), parseInt(this.selected.slice(5, 7), 10) - 1, 1);
        }
        this.year = base.getFullYear();
        this.month = base.getMonth();

        this.root.classList.add('kal');
        this.root.innerHTML =
            '<div class="kal-head">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-nav="-1" aria-label="Bulan sebelumnya"><i class="bi bi-chevron-left"></i></button>' +
            '<span class="kal-title"></span>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-nav="1" aria-label="Bulan berikutnya"><i class="bi bi-chevron-right"></i></button>' +
            '</div>' +
            '<div class="kal-grid"></div>' +
            '<div class="kal-legend">' +
            '<span><i style="background:#e8f6ec;border:1px solid #cfe9d7"></i>Tersedia</span>' +
            '<span><i style="background:#fbe4e4;border:1px solid #f2c6c6"></i>Sudah terkunci</span>' +
            '<span><i style="background:#eef0f4"></i>Tidak dapat dipilih</span>' +
            (this.opt.selectable ? '<span><i style="background:#1f2a44"></i>Dipilih</span>' : '') +
            '</div>' +
            '<div class="small text-danger mt-2 d-none" data-err></div>';

        this.grid = this.root.querySelector('.kal-grid');
        this.title = this.root.querySelector('.kal-title');
        this.err = this.root.querySelector('[data-err]');

        var self = this;
        this.root.addEventListener('click', function (ev) {
            var nav = ev.target.closest('[data-nav]');
            if (nav) { self.geser(parseInt(nav.getAttribute('data-nav'), 10)); return; }
            var day = ev.target.closest('.kal-day.tersedia');
            if (day && self.opt.selectable) { self.pilih(day.getAttribute('data-date')); }
        });

        this.load();
    }

    KalenderHall.prototype.setHall = function (id) {
        this.hallId = id;
        this.selected = '';
        this.load();
    };

    KalenderHall.prototype.setSelected = function (tgl) {
        this.selected = tgl || '';
        this.render();
    };

    KalenderHall.prototype.geser = function (delta) {
        var d = new Date(this.year, this.month + delta, 1);
        this.year = d.getFullYear();
        this.month = d.getMonth();
        this.load();
    };

    KalenderHall.prototype.pilih = function (tgl) {
        this.selected = tgl;
        this.render();
        if (typeof this.opt.onSelect === 'function') { this.opt.onSelect(tgl); }
    };

    KalenderHall.prototype.load = function () {
        var self = this;
        var id = ++this.reqId;
        if (!this.hallId) { this.render(); return; }
        this.grid.classList.add('kal-loading');
        var bulan = this.year + '-' + pad(this.month + 1);
        fetch(this.opt.apiUrl + '?hall_id=' + encodeURIComponent(this.hallId) + '&bulan=' + bulan, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
            .then(function (data) {
                if (id !== self.reqId) { return; }
                self.locked = new Set(data.locked || []);
                self.today = data.today;
                self.err.classList.add('d-none');
                self.render();
            })
            .catch(function () {
                if (id !== self.reqId) { return; }
                self.err.textContent = 'Gagal memuat ketersediaan. Coba muat ulang halaman.';
                self.err.classList.remove('d-none');
                self.render();
            })
            .finally(function () { self.grid.classList.remove('kal-loading'); });
    };

    KalenderHall.prototype.render = function () {
        var y = this.year, m = this.month;
        this.title.textContent = new Date(y, m, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });

        var html = HARI.map(function (h) { return '<div class="kal-dow">' + h + '</div>'; }).join('');
        var offset = (new Date(y, m, 1).getDay() + 6) % 7; // minggu dimulai Senin
        var jumlah = new Date(y, m + 1, 0).getDate();
        var today = this.today || ymd(new Date().getFullYear(), new Date().getMonth(), new Date().getDate());

        for (var i = 0; i < offset; i++) { html += '<div class="kal-day kosong"></div>'; }
        for (var d = 1; d <= jumlah; d++) {
            var tgl = ymd(y, m, d);
            var cls = 'kal-day';
            var label = '';
            if (tgl <= today) {
                cls += ' lewat';
                label = 'Tidak dapat dipilih';
            } else if (this.locked.has(tgl)) {
                cls += ' terkunci';
                label = 'Sudah terkunci';
            } else {
                cls += ' tersedia';
                label = 'Tersedia';
            }
            if (tgl === today) { cls += ' hari-ini'; }
            if (tgl === this.selected && this.opt.selectable) { cls += ' dipilih'; }
            html += '<button type="button" class="' + cls + '" data-date="' + tgl + '" title="' + label + '" aria-label="' + d + ', ' + label + '">' + d + '</button>';
        }
        this.grid.innerHTML = html;

        // Tidak boleh mundur ke bulan sebelum bulan berjalan
        var now = new Date();
        var prev = this.root.querySelector('[data-nav="-1"]');
        prev.disabled = (y < now.getFullYear()) || (y === now.getFullYear() && m <= now.getMonth());
    };

    global.KalenderHall = KalenderHall;
})(window);
