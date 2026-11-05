<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\Register;
use App\Services\CashService;
use App\Services\ProductService;
use App\Services\SaleService;
use App\Services\StockService;

/**
 * 2026-11-05 (Aya): every printout also exports as a PDF file, to keep or to send on WhatsApp. The browser itself
 * draws the page (html2canvas, vendored like Bootstrap), so the PDF looks exactly like the paper — Arabic names
 * included — and jsPDF (vendored) saves it. A receipt is one tall 80 mm page like the roll; reports are A4 pages.
 * Proved in headless Chrome: both modes produce files starting %PDF.
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the two PDF libraries are vendored locally, never a CDN' => function (): void {
        foreach (['assets/vendor/pdf/jspdf.umd.min.js', 'assets/vendor/pdf/html2canvas.min.js', 'assets/js/pdf-export.js'] as $f) {
            assert_true(is_file(BASE_PATH . '/public/' . $f), $f . ' exists');
        }
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pdf-export.js');
        assert_contains("'roll'", $js);
        assert_contains('doc.save', $js);
        foreach ([BASE_PATH . '/app/Views/layouts/receipt.php', BASE_PATH . '/app/Views/layouts/print.php'] as $layout) {
            assert_not_contains('cdn.', (string) file_get_contents($layout), 'no CDN in ' . basename($layout));
        }
    },

    'the receipt, the X/Z report, a report and the product list all offer Export PDF' => function (): void {
        $svc = new ProductService();
        $p = make_product('Nakhla');
        $piece = $svc->addUnit($p, 'Piece', '1', false, true);
        $svc->setPrices($piece, '5', '');
        (new StockService())->adjust($p, $piece, '10', 'opening', '', '1', TEST_ADMIN_ID);
        $register = (new Register())->create('Register 01');
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
        $sale = (new SaleService())->complete([
            'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $p, 'unit_id' => $piece, 'qty' => '1']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '5']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);
        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        $receipt = $c->get('sales/receipt', ['id' => $sale['id']])->body;
        assert_contains('Export PDF', $receipt);
        assert_contains("exportPdf('.receipt'", $receipt);
        assert_contains('vendor/pdf/jspdf.umd.min.js', $receipt);
        assert_contains('Export PDF', $c->get('sessions/print', ['id' => $session])->body, 'the X/Z report');
        $report = $c->get('reports', ['type' => 'sales', 'print' => 1])->body;
        assert_contains("exportPdf('.print-sheet'", $report);
        assert_contains('Export PDF', $c->get('products/print')->body, 'the product list');

        // 2026-11-05 (Aya): the button could not be found from the screens, so every screen with a Print button
        // offers Export PDF right next to it; the link opens the print view with pdf=1, which downloads by itself.
        $c->get('registers');
        $c->post('registers/bind', ['id' => $register]);
        foreach ([['reports', ['type' => 'stock']], ['products', []], ['sales/view', ['id' => $sale['id']]],
                  ['sessions/view', ['id' => $session]]] as [$route, $params]) {
            $body = $c->get($route, $params)->body;
            assert_contains('Export PDF', $body, $route . ' offers Export PDF on the screen');
            assert_contains('pdf=1', $body, $route . ' links the self-downloading print view');
        }
        $till = $c->get('pos')->body;   // the till's links are filled by its script when the sale or return is done
        assert_contains('id="done-pdf"', $till);
        assert_contains('id="ret-pdf"', $till);
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_contains("\$('done-pdf').href = j.receipt + '&pdf=1'", $js, 'the till fills the done-screen link');
        assert_contains("\$('ret-pdf').href = j.receipt + '&pdf=1'", $js, 'and the return done-screen link');
    },
];
