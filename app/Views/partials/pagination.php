<?php if ($pg['pages'] > 1): ?>
  <nav class="d-flex justify-content-between align-items-center mt-3">
    <span class="text-muted small">Page <?= (int) $pg['page'] ?> of <?= (int) $pg['pages'] ?> · <?= (int) $pg['total'] ?> rows</span>
    <div class="btn-group">
      <a class="btn btn-outline-secondary<?= $pg['page'] <= 1 ? ' disabled' : '' ?>" href="<?= e(url_with(['page' => $pg['page'] - 1])) ?>">Previous</a>
      <a class="btn btn-outline-secondary<?= $pg['page'] >= $pg['pages'] ? ' disabled' : '' ?>" href="<?= e(url_with(['page' => $pg['page'] + 1])) ?>">Next</a>
    </div>
  </nav>
<?php endif; ?>
