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
?>
<div class="attachments<?= $attCompact ? ' is-compact' : '' ?>">
  <?php if ($attFiles !== []): ?>
    <div class="att-list">
      <?php foreach ($attFiles as $f): ?>
        <a class="att-file" href="<?= e($f['url']) ?>" target="_blank" title="<?= e($f['original_name']) ?> · <?= e(date('d/m/Y H:i', strtotime($f['created_at']))) ?><?= isset($f['username']) ? ' · ' . e($f['username']) : '' ?>">
          <?php if ($f['is_pdf']): ?><span class="att-pdf"><i class="bi bi-file-earmark-pdf"></i><?= $attCompact ? '' : ' PDF' ?></span>
          <?php else: ?><img src="<?= e($f['url']) ?>" alt="" loading="lazy"><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php elseif (!$attCompact): ?>
    <p class="text-muted small mb-2">No file yet. Photograph <?= e($attLabel) ?> or add its PDF: it stays with this record, so it can be checked later.</p>
  <?php endif; ?>
  <?php if (\App\Core\Gate::allows($attOwner === 'purchase' ? 'purchase.manage' : 'expense.manage')): ?>
    <form method="post" action="<?= url($attRoute) ?>" enctype="multipart/form-data" class="att-add" id="<?= $formId ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="<?= $attOwner ?>_id" value="<?= (int) $attId ?>">
      <label class="btn btn-outline-secondary btn-sm mb-0" for="<?= $formId ?>-file"><i class="bi bi-paperclip"></i> <?= $attFiles === [] ? 'Attach' : 'Add' ?></label>
      <input class="visually-hidden" type="file" id="<?= $formId ?>-file" name="<?= $field ?>" accept="image/jpeg,image/png,image/webp,application/pdf" multiple onchange="this.form.submit()">
    </form>
  <?php endif; ?>
</div>
