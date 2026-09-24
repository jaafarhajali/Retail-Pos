<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <p>A backup is one zip: the database dump plus the product images. Keep copies on a USB stick or another PC.</p>
      <form method="post" action="<?= url('backup/run') ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Back up now</button></form>
      <hr>
      <p class="small text-muted mb-1">Daily automatic backup: in Windows Task Scheduler run<br><code><?= e(PHP_BINARY) ?> "<?= e(BASE_PATH) ?>\bin\backup.php"</code> every night. Uses <code><?= e($mysqldump) ?></code>.</p>
      <p class="small text-muted mb-0">Restore: unzip, import the .sql into MySQL (phpMyAdmin or <code>mysql retail_pos &lt; file.sql</code>), copy the <code>uploads</code> folder to <code>public/uploads</code>.</p>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>File</th><th>Created</th><th class="text-end">Size</th></tr></thead>
      <tbody><?php foreach ($backups as $b): ?><tr><td><code><?= e($b['name']) ?></code></td><td><?= e($b['at']) ?></td><td class="text-end"><?= number_format($b['size'] / 1024) ?> KB</td></tr><?php endforeach; ?>
      <?php if ($backups === []): ?><tr><td colspan="3" class="empty">No backups yet.</td></tr><?php endif; ?></tbody>
    </table></div></div>
    <p class="small text-muted mt-2">Files are in <code>storage/backups</code>; the last 30 are kept.</p>
  </div>
</div>
