<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Models\Register;
use App\Services\ProductService;
use App\Services\StockService;

/**
 * 2026-10-07 (Aya): a product sold in more than one way (charcoal by the kg and by the Box) asks "which one?" when its
 * button is tapped: a small window with one big button per way that has a price. A product with one way still goes
 * into the sale at one tap, and a scanned barcode still adds exactly its own unit.
 */
$openTill = static function (): HttpClient {
    $registerId = (new Register())->create('Register 01');
    $client = login_as('admin', TEST_ADMIN_PASSWORD);
    $client->get('registers');
    $client->post('registers/bind', ['id' => $registerId]);
    $client->get('sessions/open');
    assert_same(302, $client->post('sessions/open', ['opening_usd' => '100', 'opening_lbp' => '0'])->status);

    return $client;
};

return [
    '__before' => 'test_db_reset',

    'the till has the "which one?" window' => function () use ($openTill): void {
        $till = $openTill()->get('pos')->body;
        assert_contains('id="m-unit"', $till);
        assert_contains('id="unit-title"', $till, 'it is titled with the product');
        assert_contains('id="unit-list"', $till, 'one button per way to sell');
    },

    'the till gets every way to sell with its prices, and which one comes first' => function () use ($openTill): void {
        $products = new ProductService();
        $p = make_product('Fahem 3cm', 'g');
        $kg = $products->addUnit($p, 'kg', '1000', true, true);
        $products->setPrices($kg, '18', '15');
        $box = $products->addUnit($p, 'Box', '10000', false, false);
        $products->setPrices($box, '160', '');
        (new StockService())->adjust($p, $kg, '50', 'opening', '', '10', TEST_ADMIN_ID);

        $data = json_decode($openTill()->get('pos/data')->body, true);
        $units = array_column(array_values(array_filter($data['products'], static fn (array $x): bool => $x['id'] === $p))[0]['units'], null, 'id');
        assert_same(true, $units[$kg]['default'], 'the "Till button" unit is offered first');
        assert_same(false, $units[$box]['default']);
        assert_same('160.00', $units[$box]['retail']);
        assert_same(null, $units[$box]['wholesale'], 'a way without a price at this level is not offered');
    },

    'the till script asks only when there is a choice, and never on a barcode' => function (): void {
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/js/pos.js');
        assert_contains('function pick(p)', $js);
        assert_contains("modals['m-unit'].show()", $js);
        assert_contains('if (sold.length < 2)', $js, 'one way to sell: one tap, no window');
        assert_contains("b.addEventListener('click', function () { pick(p); });", $js, 'a tap on the product button');
        assert_contains('if (byCode) { pick(byCode);', $js, 'a typed product code names the product, not the unit');
        assert_contains('if (hit) { var p = product(hit.p); addLine(p, p.units.find(function (u) { return u.id === hit.u; }));', $js, 'a barcode belongs to one unit: no question');
    },
];
