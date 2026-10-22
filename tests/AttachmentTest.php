<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Attachment;
use App\Services\AttachmentService;
use App\Services\ExpenseService;
use App\Services\ProductService;
use App\Services\PurchaseService;

/**
 * 2026-10-22 (Aya, as in Retail Production since 2026-10-18): the paper behind a record. The supplier's invoice on a purchase, the receipt on an
 * expense: photos or PDFs, several per record, kept under uploads/attachments, shown on the purchase page and the
 * expenses list, added at creation or later. Evidence only: the record does not change.
 */
$png = static function (int $w = 2000, int $h = 1200): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 240));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
};
$tmp = static function (string $bytes): string {
    $path = tempnam(sys_get_temp_dir(), 'att');
    file_put_contents($path, $bytes);

    return $path;
};
$upload = static fn (string $name, string $bytes, ?int $size = null): array => ['name' => $name, 'tmp_name' => $tmp($bytes), 'size' => $size ?? strlen($bytes), 'error' => UPLOAD_ERR_OK];
$purchase = static function (): int {
    $svc = new ProductService();
    $sugar = make_product('Sugar', 'g');
    $kg = $svc->addUnit($sugar, 'kg', '1000', true, true);

    return (new PurchaseService())->create(null, 'SUP-77', date('Y-m-d'), [['product_id' => $sugar, 'unit_id' => $kg, 'qty' => '10', 'unit_cost' => '0.90']], '', 'outside', '', TEST_ADMIN_ID);
};
$expense = static fn (): int => (new ExpenseService())->create(['kind' => 'expense', 'category' => 'Rent', 'description' => 'October', 'usd' => '500', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside', 'supplier_id' => 0], TEST_ADMIN_ID);
$clean = static function (): void {
    foreach (glob(UPLOADS_PATH . '/attachments/*') ?: [] as $f) {
        @unlink($f);
    }
};

return [
    '__before' => function () use ($clean): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
        $clean();
    },

    'a photo is re-encoded as a readable JPEG no wider than 1600, a PDF is kept; both hang on the purchase with who and when' => function () use ($purchase, $upload, $png): void {
        $id = $purchase();
        $svc = new AttachmentService();
        $a = $svc->store('purchase', $id, $upload('IMG_2041.PNG', $png()), TEST_ADMIN_ID);
        $b = $svc->store('purchase', $id, $upload('facture.pdf', "%PDF-1.4\n1 0 obj << >> endobj\n%%EOF"), TEST_ADMIN_ID);
        $files = $svc->forOwner('purchase', $id);
        assert_same(2, count($files));
        assert_same(['image/jpeg', 'IMG_2041.PNG', false, 'admin'], [$files[0]['mime'], $files[0]['original_name'], $files[0]['is_pdf'], $files[0]['username']]);
        assert_same(['application/pdf', 'facture.pdf', true], [$files[1]['mime'], $files[1]['original_name'], $files[1]['is_pdf']]);
        assert_true(preg_match('/^p' . $id . '-[a-f0-9]{8}\.jpg$/', $files[0]['file']) === 1, 'named after the purchase: ' . $files[0]['file']);
        $saved = UPLOADS_PATH . '/attachments/' . $files[0]['file'];
        assert_true(is_file($saved), 'the file is on disk');
        [$w, $h] = getimagesize($saved);
        assert_same([1600, 960], [$w, $h], 'scaled to 1600 wide, same proportions');
        assert_same((int) filesize($saved), (int) $files[0]['size_bytes']);
        assert_same('uploads/test/attachments/' . $files[1]['file'], $files[1]['url']);
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'purchase.attached'")->fetchColumn());
        // the purchase itself did not change
        assert_same('9.00', Database::pdo()->query('SELECT total_usd FROM purchases WHERE id = ' . $id)->fetchColumn());
        assert_same([$a, $b], array_map(static fn (array $f): int => (int) $f['id'], $files));
    },

    'refused: a text file, a file over 8 MB, an unknown record; nothing is written then' => function () use ($purchase, $upload, $png): void {
        $id = $purchase();
        $svc = new AttachmentService();
        $e = assert_throws(DomainException::class, fn () => $svc->store('purchase', $id, $upload('notes.txt', 'just text'), TEST_ADMIN_ID));
        assert_contains('photo (JPG, PNG, WEBP) or a PDF', $e->getMessage());
        $e = assert_throws(DomainException::class, fn () => $svc->store('purchase', $id, $upload('big.pdf', '%PDF-1.4 x', 9 * 1024 * 1024), TEST_ADMIN_ID));
        assert_contains('8 MB', $e->getMessage());
        assert_throws(DomainException::class, fn () => $svc->store('purchase', 999, $upload('a.png', $png(10, 10)), TEST_ADMIN_ID));
        assert_throws(DomainException::class, fn () => $svc->store('expense', 999, $upload('a.png', $png(10, 10)), TEST_ADMIN_ID));
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM attachments')->fetchColumn());
        assert_same([], glob(UPLOADS_PATH . '/attachments/*') ?: []);
    },

    'through the browser: a purchase posted with its invoice shows it; a second file is added later; a cashier may not' => function () use ($png): void {
        $svc = new ProductService();
        $sugar = make_product('Sugar', 'g');
        $kg = $svc->addUnit($sugar, 'kg', '1000', true, true);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $r = $client->postMultipart('purchases/store', ['supplier_id' => '0', 'supplier_invoice_ref' => 'SUP-1', 'purchase_date' => date('Y-m-d'), 'notes' => '', 'paid_now' => '',
            'lines[0][product_id]' => (string) $sugar, 'lines[0][unit_id]' => (string) $kg, 'lines[0][qty]' => '5', 'lines[0][unit_cost]' => '1'], ['attachments[]' => ['invoice.png', $png(800, 600), 'image/png']]);
        assert_same(302, $r->status, $r->body);
        $page = $client->get('purchases/view', ['id' => 1])->body;
        assert_contains('1 file attached', $page);
        assert_contains('class="att-file"', $page);
        assert_contains('uploads/test/attachments/p1-', $page);
        assert_contains('name="attachments[]"', $page, 'Add stays available');
        // a second page, later
        $r = $client->postMultipart('purchases/attach', ['purchase_id' => '1'], ['attachments[]' => ['page2.pdf', "%PDF-1.4\n%%EOF", 'application/pdf']]);
        assert_same(302, $r->status);
        $page = $client->get('purchases/view', ['id' => 1])->body;
        assert_contains('The file is attached.', $page);
        assert_same(2, substr_count($page, 'class="att-file"'));
        assert_contains('bi-file-earmark-pdf', $page);
        // nothing chosen
        $r = $client->post('purchases/attach', ['purchase_id' => '1']);
        assert_contains('Choose a photo or a PDF first.', $client->get('purchases/view', ['id' => 1])->body);
        // the cashier has no purchase.manage
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->postMultipart('purchases/attach', ['purchase_id' => '1'], ['attachments[]' => ['x.png', $png(10, 10), 'image/png']])->status);
    },

    'an expense: the receipt is recorded with it and shows on the list; added later with the row\'s Add button' => function () use ($expense, $png): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $r = $client->postMultipart('expenses/store', ['kind' => 'expense', 'category' => 'Electricity', 'description' => 'EDL', 'usd' => '120', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside', 'supplier_id' => '0'],
            ['attachments[]' => ['edl.png', $png(600, 900), 'image/png']]);
        assert_same(302, $r->status, $r->body);
        $list = $client->get('expenses')->body;
        assert_contains('Expense recorded. 1 file attached.', $list);
        assert_contains('<th>Receipt</th>', $list);
        assert_contains('uploads/test/attachments/e1-', $list);
        assert_contains('attachments is-compact', $list);
        $second = $expense();   // no receipt yet: the row offers Attach
        $list = $client->get('expenses')->body;
        assert_same(2, substr_count($list, 'name="attachments[]"') - 1, 'an Attach form on each row, besides the New expense form');
        $r = $client->postMultipart('expenses/attach', ['expense_id' => (string) $second], ['attachments[]' => ['rent.pdf', "%PDF-1.7\n%%EOF", 'application/pdf']]);
        assert_same(302, $r->status);
        assert_same(1, count((new Attachment())->forOwner('expense', $second)));
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'expense.attached'")->fetchColumn(), 'one per receipt');
        // a bad file does not lose the expense
        $r = $client->postMultipart('expenses/store', ['kind' => 'expense', 'category' => 'Fuel', 'description' => '', 'usd' => '20', 'lbp' => '', 'expense_date' => date('Y-m-d'), 'paid_from' => 'outside', 'supplier_id' => '0'],
            ['attachments[]' => ['scan.txt', 'not a file we take', 'text/plain']]);
        assert_same(302, $r->status);
        $list = $client->get('expenses')->body;
        assert_contains('scan.txt: Choose a photo', $list);
        assert_same(3, (int) Database::pdo()->query('SELECT COUNT(*) FROM expenses')->fetchColumn());
    },
];
