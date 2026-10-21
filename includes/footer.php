<!-- footer.php: included after the page closes its own .page-content div -->
</div><!-- /.main-wrap -->
</div><!-- /.app-shell -->

<?php
$vApp  = filemtime(ROOT_PATH . '/assets/js/app.js');
$vTour = filemtime(ROOT_PATH . '/assets/js/tour.js');
?>
<?php // motion.dev (spring physics, WAAPI-backed), self-hosted: built into
      // assets/vendor by scripts/build_vendor.js so the PWA and the Android
      // app don't depend on a CDN. app.js still degrades if window.Motion is missing. ?>
<script src="<?= assetUrl('assets/vendor/motion.min.js') ?>"></script>
<script src="<?= assetUrl('assets/js/app.js') ?>"></script>
<script src="<?= assetUrl('assets/js/ui.js') ?>"></script>
<script src="<?= assetUrl('assets/js/tour.js') ?>"></script>
<?php if (!empty($extraJs)): ?>
  <?= $extraJs ?>
<?php endif; ?>
</body>
</html>
