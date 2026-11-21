/* Util umum: konfirmasi form, validasi & pratinjau unggahan gambar. */
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
    }
});

document.querySelectorAll('input[type=file][data-maxmb]').forEach(function (inp) {
    inp.addEventListener('change', function () {
        var f = inp.files && inp.files[0];
        var prev = inp.getAttribute('data-preview') ? document.querySelector(inp.getAttribute('data-preview')) : null;
        if (prev) { prev.classList.add('d-none'); }
        if (!f) { return; }
        var maxMb = parseFloat(inp.getAttribute('data-maxmb'));
        if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
            alert('Format file harus JPG, PNG, atau WEBP.');
            inp.value = '';
            return;
        }
        if (f.size > maxMb * 1048576) {
            alert('Ukuran file maksimal ' + maxMb + ' MB.');
            inp.value = '';
            return;
        }
        if (prev) {
            prev.src = URL.createObjectURL(f);
            prev.classList.remove('d-none');
        }
    });
});
