<?php
$messages = [
    400 => 'The request was not valid.',
    403 => 'You do not have permission to open this page.',
    404 => 'Page not found.',
    405 => 'This action is not allowed here.',
    419 => 'Your session expired. Go back, refresh the page and try again.',
];
$message = $messages[(int) $status] ?? 'Something went wrong. The error has been logged.';
?>
<div class="text-center py-5 px-3">
  <div class="display-4 fw-bold text-secondary"><?= (int) $status ?></div>
  <p class="lead"><?= e($message) ?></p>
  <?php if (!empty($detail)): ?>
    <pre class="text-danger small text-start d-inline-block"><?= e($detail) ?></pre>
  <?php endif; ?>
  <p><a class="btn btn-primary" href="<?= url('dashboard') ?>">Back to the dashboard</a></p>
</div>
