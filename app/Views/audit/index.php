<form class="card card-body mb-3" method="get">
  <input type="hidden" name="r" value="audit">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label" for="action">Action starts with</label>
      <input class="form-control" id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="e.g. auth.">
    </div>
    <div class="col-md-3">
      <label class="form-label" for="user_id">User</label>
      <select class="form-select" id="user_id" name="user_id">
        <option value="0">Everyone</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $filters['user_id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label" for="from">From</label>
      <input class="form-control" id="from" type="date" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label" for="to">To</label>
      <input class="form-control" id="to" type="date" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
  </div>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-sm align-middle m-0">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Amount</th><th>Details</th><th>IP</th><th>Register</th></tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $row): ?>
      <tr>
        <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', strtotime((string) $row['created_at']))) ?></td>
        <td><?= e($row['username'] ?? '—') ?></td>
        <td><code><?= e($row['action']) ?></code></td>
        <td><?= $row['entity'] !== null ? e($row['entity'] . ($row['entity_id'] !== null ? ' #' . $row['entity_id'] : '')) : '—' ?></td>
        <td><?= $row['amount'] !== null ? e($row['amount'] . ' ' . $row['currency']) : '—' ?></td>
        <td class="small" dir="auto"><?= e($row['details'] ?? '') ?></td>
        <td class="small"><?= e($row['ip'] ?? '') ?></td>
        <td dir="auto"><?= e($row['register_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">No entries.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
