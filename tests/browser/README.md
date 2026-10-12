# Browser checks of the till (headless Chrome)

The PHP test suite (`tests/run.php`) proves the rules. These scripts prove what the cashier sees: the dialogs, the
PIN prompts, the cart, the screenshots. They run only on the throw-away test database.

Needs: XAMPP MySQL running, Google Chrome at `C:\Program Files\Google\Chrome\Application\chrome.exe`, node 18+.

```
bash tests/browser/till_test.sh <out dir> tests/browser/scenarios/discount-limit-per-line.txt
MAXPCT=10 PREFIX=n bash tests/browser/till_test.sh <out dir> tests/browser/scenarios/discount-limit-per-line.txt
```

- `<out dir>`: any folder for the screenshots (`<PREFIX>*.png` in `<out dir>/till`), the cookie jars and the Chrome profile.
- `MAXPCT`: the cashier's allowed discount set on the test database before the run (default 0).
- The runner seeds the demo data, sets the admin PIN to 2468, binds Register 2, signs the cashier `sara` in
  (password123) with a $50 float, serves the site on port 8191 and drives Chrome on port 9333. Ports are freed at the end.
- A scenario is plain JavaScript spliced into `till_drive.mjs`. Helpers: `ev(js)` evaluates in the page, `waitFor(js, what)`,
  `shot(name)` saves a PNG, `type(id, text)`, `click(selector)`, `shown(modalId)`, `log(label, value)`, `send(cdpMethod, params)`, `sleep(ms)`.
- `set_max.php` and `float_price.php` are helpers the runner and some scenarios call on the test database.

Never run this while `tests/run.php` is running: both use `retail_pos_test`.