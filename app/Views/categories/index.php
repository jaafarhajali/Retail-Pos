<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table align-middle m-0">
        <thead><tr><th>Order</th><th>Colour</th><th>Name</th><th>Products</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><?= (int) $c['sort_order'] ?></td>
            <td><span class="cat-swatch" style="background: <?= e($c['color']) ?>"></span> <code class="small"><?= e($c['color']) ?></code></td>
            <td dir="auto"><?= e($c['name']) ?></td>
            <td><?= (int) $c['product_count'] ?></td>
            <td><?= (int) $c['is_active'] ? 'yes' : '<span class="text-muted">no</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('categories/edit', ['id' => $c['id']]) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No categories yet. Create the first one on the right.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h5">New category</h2>
      <form method="post" action="<?= url('categories/store') ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="name">Name</label>
          <input class="form-control" id="name" name="name" dir="auto" maxlength="80" required value="<?= old('name') ?>">
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" for="color">Tile colour</label>
            <input class="form-control form-control-color w-100" id="color" name="color" type="color" value="<?= old('color', '#e67e22') ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="sort_order">Order</label>
            <input class="form-control" id="sort_order" name="sort_order" inputmode="numeric" value="<?= old('sort_order', '0') ?>">
          </div>
        </div>
        <button class="btn btn-primary w-100" type="submit">Create category</button>
      </form>
    </div></div>
  </div>
</div>
