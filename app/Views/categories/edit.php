<div class="card" style="max-width: 560px"><div class="card-body">
  <form method="post" action="<?= url('categories/update') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
    <div class="mb-3">
      <label class="form-label" for="name">Name</label>
      <input class="form-control" id="name" name="name" dir="auto" maxlength="80" required value="<?= old('name', $category['name']) ?>">
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6">
        <label class="form-label" for="color">Tile colour</label>
        <input class="form-control form-control-color w-100" id="color" name="color" type="color" value="<?= old('color', $category['color']) ?>">
      </div>
      <div class="col-6">
        <label class="form-label" for="sort_order">Order</label>
        <input class="form-control" id="sort_order" name="sort_order" inputmode="numeric" value="<?= old('sort_order', $category['sort_order']) ?>">
      </div>
    </div>
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $category['is_active'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="is_active">Active (shown on the POS grid)</label>
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-link" href="<?= url('categories') ?>">Back to categories</a>
  </form>
  <p class="text-muted small mt-3 mb-0"><?= (int) $category['product_count'] ?> product(s) in this category.</p>
</div></div>
<?php if ((int) $category['product_count'] === 0): ?>
  <form method="post" action="<?= url('categories/delete') ?>" class="mt-3" data-confirm="Delete this category?">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
    <button class="btn btn-outline-danger" type="submit">Delete category</button>
  </form>
<?php endif; ?>
