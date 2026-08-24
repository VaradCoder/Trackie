<!-- footer.php: included after the page closes its own .page-content div -->
</div><!-- /.main-wrap -->
</div><!-- /.app-shell -->

<?php
$vApp  = filemtime(ROOT_PATH . '/assets/js/app.js');
$vTour = filemtime(ROOT_PATH . '/assets/js/tour.js');
?>
<?php // motion.dev's JS engine (spring physics, WAAPI-backed). app.js checks
      // window.Motion and degrades gracefully if this CDN load ever fails. ?>
<script src="https://cdn.jsdelivr.net/npm/motion@11.15.0/dist/motion.js"></script>
<script src="<?= assetUrl('assets/js/app.js') ?>"></script>
<script src="<?= assetUrl('assets/js/tour.js') ?>"></script>
<?php if (!empty($extraJs)): ?>
  <?= $extraJs ?>
<?php endif; ?>
</body>
</html>
