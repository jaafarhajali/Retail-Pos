<?php /* On-screen keypad for the till dialogs: pos.js types into the last number field touched in the same dialog. */ ?>
<div class="keypad" data-keypad aria-label="Keypad">
  <?php foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3'] as $key): ?><button type="button" data-key="<?= $key ?>"><?= $key ?></button><?php endforeach; ?>
  <button type="button" data-key="000">000</button><button type="button" data-key="0">0</button><button type="button" data-key=".">.</button>
  <button type="button" class="k-fn" data-key="clear">Clear</button><button type="button" class="k-fn" data-key="00">00</button><button type="button" class="k-fn" data-key="back" aria-label="Delete"><i class="bi bi-backspace"></i></button>
</div>
