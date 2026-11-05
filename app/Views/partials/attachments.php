<?php
/**
 * The paper behind a record (2026-10-18): its files as thumbnails or PDF links, and a form to add more.
 * Needs $attOwner ('purchase' | 'expense'), $attId, $attFiles (AttachmentService::forOwner), $attRoute (the POST route),
 * optional $attLabel (what the paper is called) and $attCompact (true inside a table row).
 */
$attLabel = $attLabel ?? 'the paper';
$attCompact = $attCompact ?? false;
$field = \App\Services\AttachmentService::FIELD . '[]';
$formId = 'att-' . $attOwner . '-' . (int) $attId;
$canManage = \App\Core\Gate::allows($attOwner === 'purchase' ? 'purchase.manage' : 'expense.manage');
$detachRoute = $attOwner === 'purchase' ? 'purchases/detach' : 'expenses/detach';
?>
<div class="attachments<?= $attCompact ? ' is-compact' : '' ?>">
  <?php if ($attFiles !== []): ?>
    <div class="att-list">
      <?php foreach ($attFiles as $f): ?>
        <span class="att-item">
        <a class="att-file" href="<?= e($f['url']) ?>" target="_blank" title="<?= e($f['original_name']) ?> · <?= e(date('d/m/Y H:i', strtotime($f['created_at']))) ?><?= isset($f['username']) ? ' · ' . e($f['username']) : '' ?>">
          <?php if ($f['is_pdf']): ?><span class="att-pdf"><i class="bi bi-file-earmark-pdf"></i><?= $attCompact ? '' : ' PDF' ?></span>
          <?php else: ?><img src="<?= e($f['url']) ?>" alt="" loading="lazy"><?php endif; ?>
        </a>
        <?php if ($canManage): ?><?php /* a wrong file comes off (Aya, 2026-11-05); the audit log keeps what it was */ ?>
          <form method="post" action="<?= url($detachRoute) ?>" class="att-del" onsubmit="return confirm('Remove this file?')">
            <?= csrf_field() ?><input type="hidden" name="attachment_id" value="<?= (int) $f['id'] ?>">
            <button type="submit" title="Remove this file" aria-label="Remove this file">&times;</button>
          </form>
        <?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php elseif (!$attCompact): ?>
    <p class="text-muted small mb-2">No file yet. Photograph <?= e($attLabel) ?> or add its PDF: it stays with this record, so it can be checked later.</p>
  <?php endif; ?>
  <?php if ($canManage): ?>
    <form method="post" action="<?= url($attRoute) ?>" enctype="multipart/form-data" class="att-add" id="<?= $formId ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="<?= $attOwner ?>_id" value="<?= (int) $attId ?>">
      <label class="btn btn-outline-secondary btn-sm mb-0" for="<?= $formId ?>-file"><i class="bi bi-paperclip"></i> <?= $attFiles === [] ? 'Attach' : 'Add' ?></label>
      <input class="visually-hidden" type="file" id="<?= $formId ?>-file" name="<?= $field ?>" accept="image/jpeg,image/png,image/webp,application/pdf" multiple onchange="this.form.submit()">
    </form>
  <?php endif; ?>
</div>
