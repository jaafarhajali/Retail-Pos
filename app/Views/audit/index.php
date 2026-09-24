<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="audit">
  <div><label class="form-label" for="action">Action starts with</label><input class="form-control" id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="e.g. auth."></div>
  <div><label class="form-label" for="user_id">User</label>
    <select class="form-select" id="user_id" name="user_id">
      <option value="0">Everyone</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $filters['user_id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label class="form-label" for="from">From</label><input class="form-control" id="from" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
  <div><label class="form-label" for="to">To</label><input class="form-control" id="to" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
  <button class="btn btn-outline-primary" type="submit">Filter</button>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-sm align-middle m-0">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th class="text-end">Amount</th><th>Details</th><th>IP</th><th>Register</th></tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $row): ?>
      <tr>
        <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', strtotime((string) $row['created_at']))) ?></td>
        <td><?= e($row['username'] ?? '—') ?></td>
        <td><code><?= e($row['action']) ?></code></td>
        <td class="text-nowrap"><?= $row['entity'] !== null ? e(str_replace('_', ' ', $row['entity']) . ($row['entity_id'] !== null ? ' #' . $row['entity_id'] : '')) : '—' ?></td>
        <td class="text-end text-nowrap"><?= $row['amount'] !== null ? e($row['amount'] . ' ' . $row['currency']) : '—' ?></td>
        <td class="audit-details" dir="auto"><?= e($row['details'] ?? '') ?></td>
        <td class="small text-nowrap"><?= e($row['ip'] ?? '') ?></td>
        <td dir="auto"><?= e($row['register_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?>
      <tr><td colspan="8" class="empty">No entries match these filters.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
