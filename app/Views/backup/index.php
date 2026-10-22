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
        <?php else: ?>Last backup <?= e(date('d/m/Y H:i', strtotime($status['last_at']))) ?> (<?= e($status['last_kind']) ?>)<?= $status['copy'] === 'ok' ? ' · copied to the USB folder' : '' ?><?= $status['drive']['connected'] && $status['drive']['last_at'] !== '' && $status['drive']['error'] === '' ? ' · on Google Drive ' . e(date('d/m/Y H:i', strtotime($status['drive']['last_at']))) : '' ?><?php endif; ?>
      </div>
      <?php if ($status['warnings'] === []): ?><div class="text-muted">Hourly in opening hours, daily at 02:00, a copy on the stick<?= $status['drive']['connected'] ? ', the daily one on Google Drive' : '' ?>. All good.</div><?php endif; ?>
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
        <div class="form-text">Empty switches the copy off. A folder on another PC of the shop (<code>\\OFFICE-PC\backups</code>) or a Google Drive for Desktop folder works the same way.</div>
      </form>
    </div></div>
    <div class="card mb-3"><div class="card-body">
      <?php $g = $status['drive']; ?>
      <h2 class="h5">Google Drive</h2>
      <?php if ($g['connected']): ?>
        <p class="small text-muted">Connected as <strong><?= e($g['account']) ?></strong>. Every daily and monthly backup goes to the folder <strong><?= e($g['folder_name']) ?></strong> in that Google Drive, and a manual one when you press the button. Kept there: <?= (int) $driveKeep['daily'] ?> daily, <?= (int) $driveKeep['monthly'] ?> monthly, <?= (int) $driveKeep['manual'] ?> manual. Hourly backups stay on this PC and the stick.</p>
        <p class="small mb-3"><?php if ($g['error'] !== ''): ?><span class="text-danger"><i class="bi bi-exclamation-triangle"></i> <?= e($g['error']) ?></span>
          <?php elseif ($g['last_at'] !== ''): ?>Last upload <?= e(date('d/m/Y H:i', strtotime($g['last_at']))) ?>: <code><?= e($g['last_file']) ?></code><?= $g['folder_id'] !== '' ? ' · <a href="https://drive.google.com/drive/folders/' . e($g['folder_id']) . '" target="_blank" rel="noopener">open the folder</a>' : '' ?>
          <?php else: ?>Nothing uploaded yet: the first daily backup goes tonight, or press the button.<?php endif; ?></p>
        <div class="d-flex flex-wrap gap-2">
          <form method="post" action="<?= url('backup/google-upload') ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit" <?= $backups === [] ? 'disabled' : '' ?>><i class="bi bi-cloud-upload"></i> Upload the newest backup now</button></form>
          <form method="post" action="<?= url('backup/google-off') ?>" data-confirm="Disconnect Google Drive? The backups already there stay; new ones will not go there."><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit">Disconnect</button></form>
        </div>
      <?php elseif ($g['configured']): ?>
        <p class="small text-muted">Step 2 of 2: on <strong>this server</strong>, in its own browser at <code>http://localhost/…</code>, press the button and sign in to the Google account that should keep the backups. Google then shows the app's name and asks to allow it; it will see only the files it uploads.</p>
        <a class="btn btn-primary" href="<?= url('backup/google-connect') ?>"><i class="bi bi-google"></i> Connect Google Drive</a>
      <?php else: ?>
        <p class="small text-muted">Step 1 of 2, once per installation: in <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> enable the <em>Google Drive API</em>, then create an OAuth client of type <em>Web application</em> with this redirect URI:</p>
        <p class="small wrap-code"><code><?= e($callbackUrl) ?></code></p>
      <?php endif; ?>
      <?php if (!$g['connected']): ?>
        <form method="post" action="<?= url('backup/google') ?>" class="mt-3">
          <?= csrf_field() ?>
          <?php if (form_error('gdrive') !== ''): ?><div class="alert alert-danger py-2"><?= form_error('gdrive') ?></div><?php endif; ?>
          <div class="row g-2">
            <div class="col-md-7"><label class="form-label" for="gdrive_client_id">Client ID</label><input class="form-control form-control-sm" id="gdrive_client_id" name="gdrive_client_id" value="<?= old('gdrive_client_id', setting('gdrive_client_id', '')) ?>" placeholder="….apps.googleusercontent.com"></div>
            <div class="col-md-5"><label class="form-label" for="gdrive_client_secret">Client secret</label><input class="form-control form-control-sm" id="gdrive_client_secret" name="gdrive_client_secret" value="<?= old('gdrive_client_secret', setting('gdrive_client_secret', '')) ?>" placeholder="GOCSPX-…"></div>
          </div>
          <button class="btn btn-outline-primary btn-sm mt-2" type="submit"><?= $g['configured'] ? 'Save the client' : 'Save and go to step 2' ?></button>
          <?php if ($g['configured']): ?><span class="form-text ms-2">Empty both to switch Google Drive off.</span><?php endif; ?>
        </form>
      <?php endif; ?>
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