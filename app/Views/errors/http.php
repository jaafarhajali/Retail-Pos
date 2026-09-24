<?php
// What happened, in the shop's words, and what to do next. The status code stays as a quiet chip.
$pages = [
    400 => ['bi-exclamation-circle', 'is-warn', 'That request was not valid', 'Go back and try again. If it keeps happening, sign out and back in.'],
    403 => ['bi-shield-lock', 'is-warn', 'You can’t open this page', 'Your role does not include it. Ask an administrator if you need it for your work.'],
    404 => ['bi-signpost-split', '', 'This page doesn’t exist', 'The link may be old or mistyped. Use the menu to find what you need.'],
    405 => ['bi-exclamation-circle', 'is-warn', 'That action isn’t allowed here', 'Go back and use the buttons on the page.'],
    419 => ['bi-clock-history', 'is-warn', 'This page expired', 'It was open too long or you signed in again elsewhere. Go back, refresh the page, and try again.'],
];
[$icon, $tone, $title, $message] = $pages[(int) $status] ?? ['bi-exclamation-octagon', 'is-danger', 'Something went wrong', 'The error has been logged. Go back and try again; if it happens again, tell the administrator what you were doing.'];
?>
<div class="error-wrap">
  <div class="blocked">
    <div class="blocked-icon <?= $tone ?>"><i class="bi <?= $icon ?>"></i></div>
    <h2><?= e($title) ?><span class="blocked-code">Error <?= (int) $status ?></span></h2>
    <p class="mb-0"><?= e($message) ?></p>
    <?php if (!empty($detail)): ?>
      <pre><?= e($detail) ?></pre>
    <?php endif; ?>
    <div class="blocked-actions">
      <a class="btn btn-primary" href="<?= url('dashboard') ?>">Back to the dashboard</a>
      <button class="btn btn-outline-secondary" type="button" onclick="history.back()">Go back</button>
    </div>
  </div>
</div>
