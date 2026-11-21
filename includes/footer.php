</main>
<footer class="bg-brand text-white-50 py-4 mt-5 no-print">
    <div class="container d-flex flex-column flex-md-row justify-content-between small">
        <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. Seluruh hak cipta dilindungi.</span>
        <span>Sistem Rancang Reservasi Hall Berbasis Web</span>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php foreach (($extraScripts ?? []) as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
