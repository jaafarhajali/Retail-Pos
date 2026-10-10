<?php foreach ($problems as $problem): ?>
  <div class="alert alert-danger"><strong>Backups are not working.</strong> <?= e($problem) ?></div>
<?php endforeach; ?>
<?php $ok = $status['warnings'] === [] && $problems === []; ?>
<div class="card mb-3 backup-status <?= $ok ? 'is-ok' : 'is-warn' ?>"><div class="card-body">
  <div class="d-flex flex-wrap align-items-center gap-3">
    <div class="backup-light"><i class="bi <?= $ok ? 'bi-shield-check' : 'bi-shield-exclamation' ?>"></i></div>
    <div class="flex-grow-1">
      <div class="fw-semibold fs-5">
        <?php if ($status['last_at'] === ''): ?>No backup has run yet.
        <?php else: ?>Last backup <?= e(date('d/m/Y H:i', strtotime($status['last_at']))) ?> (<?= e($status['last_kind']) ?>)<?= $status['copy'] === 'ok' ? ' · copied to the USB folder' : '' ?><?php endif; ?>
      </div>
      <?php if ($status['warnings'] === []): ?><div class="text-muted">Hourly in opening hours, daily at 02:00, a copy on the stick. All good.</div><?php endif; ?>
      <?php foreach ($status['warnings'] as $w): ?><div class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= e($w) ?></div><?php endforeach; ?>
    </div>
  </div>
</div></div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3"><div class="card-body">
      <h2 class="h5">USB stick</h2>
      <p class="small text-muted">Leave a USB stick in the server. Every backup is copied there, and the newest of each kind are kept (<?= (int) $keep['hourly'] ?> hourly, <?= (int) $keep['daily'] ?> daily, <?= (int) $keep['monthly'] ?> monthly). If this PC dies, the stick has everything up to the last hour.</p>
      <form method="post" action="<?= url('backup/settings') ?>">
        <?= csrf_field() ?>
        <label class="form-label" for="backup_copy_dir">Copy every backup to this folder</label>
        <div class="input-group">
          <input class="form-control <?= form_error('backup_copy_dir') !== '' ? 'is-invalid' : '' ?>" id="backup_copy_dir" name="backup_copy_dir" placeholder="E:\RetailPOS-backups" value="<?= old('backup_copy_dir', $status['copy_dir']) ?>">
          <button class="btn btn-outline-primary" type="submit">Save</button>
        </div>
        <?php if (form_error('backup_copy_dir') !== ''): ?><div class="invalid-feedback d-block"><?= form_error('backup_copy_dir') ?></div><?php endif; ?>
        <div class="form-text">Empty switches the copy off. A folder on another PC of the shop (<code>\\OFFICE-PC\backups</code>) or a Google Drive folder works the same way.</div>
      </form>
    </div></div>
    <div class="card"><div class="card-body">
      <h2 class="h5">Automatic backups</h2>
      <p class="small text-muted mb-2">Once, on the server, as the Windows user who is normally signed in, run:</p>
      <p class="small wrap-code mb-2"><code><?= e(PHP_BINARY) ?> "<?= e(BASE_PATH) ?>\bin\install-backup-tasks.php"</code></p>
      <p class="small text-muted mb-3">It creates two Windows tasks: every hour from 08:00 to 23:59 and every night at 02:00. The daily backup of the 1st is kept as that month's backup for two years.</p>
      <form method="post" action="<?= url('backup/run') ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit" <?= $problems === [] ? '' : 'disabled' ?>>Back up now</button></form>
      <hr>
      <p class="small text-muted mb-1 wrap-code">Check that a backup really restores (the live data is only read): <code><?= e(PHP_BINARY) ?> "<?= e(BASE_PATH) ?>\bin\restore-drill.php"</code>. Do it after installing and once a month.</p>
      <p class="small text-muted mb-0 wrap-code">Restore on a new PC: install, then unzip the newest zip, import the .sql into MySQL (phpMyAdmin or <code>mysql retail_pos &lt; file.sql</code>) and copy the <code>uploads</code> folder to <code>public/uploads</code>. Uses <code><?= e($mysqldump) ?></code>.</p>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card"><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>File</th><th>Kind</th><th>Created</th><th class="text-end">Size</th></tr></thead>
      <tbody><?php foreach ($backups as $b): ?><tr><td><code><?= e($b['name']) ?></code></td><td><?= e($b['kind']) ?></td><td><?= e($b['at']) ?></td><td class="text-end"><?= number_format($b['size'] / 1024) ?> KB</td></tr><?php endforeach; ?>
      <?php if ($backups === []): ?><tr><td colspan="4" class="empty">No backups yet. Press Back up now, or install the automatic tasks.</td></tr><?php endif; ?></tbody>
    </table></div></div>
    <p class="small text-muted mt-2">Files are in <code>storage/backups</code>. Kept: the newest <?= (int) $keep['hourly'] ?> hourly, <?= (int) $keep['daily'] ?> daily, <?= (int) $keep['monthly'] ?> monthly and <?= (int) $keep['manual'] ?> manual backups.</p>
  </div>
</div>