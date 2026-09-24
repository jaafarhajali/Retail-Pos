<?php $imageUrl = \App\Services\ProductImageService::url($product['image_file']); ?>
<div class="card mb-3"><div class="card-body">
  <h2 class="h5">Image</h2>
  <div class="d-flex gap-3 align-items-start flex-wrap">
    <?php if ($imageUrl !== null): ?>
      <img class="prod-photo" src="<?= e($imageUrl) ?>" alt="">
    <?php else: ?>
      <div class="prod-photo prod-photo-empty"><i class="bi bi-image"></i></div>
    <?php endif; ?>
    <?php if ($canManage): ?>
      <div class="flex-fill">
        <form method="post" action="<?= url('products/image-store') ?>" enctype="multipart/form-data" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= $pid ?>">
          <input class="form-control mb-2" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required>
          <button class="btn btn-outline-primary" type="submit">Upload</button>
          <div class="form-text">JPG, PNG, WEBP or GIF up to 5 MB and 50 megapixels; resized to 400 px.</div>
        </form>
        <?php if ($imageUrl !== null): ?>
          <form method="post" action="<?= url('products/image-delete') ?>" data-confirm="Remove the image?">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit">Remove image</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div></div>
