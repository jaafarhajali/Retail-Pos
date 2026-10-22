<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\StockMovement;
use App\Services\ProductService;
use App\Services\StockService;

/**
 * 2026-10-22 (Aya): the Stock page says what happened in real words, in the Type filter and in the Type column:
 * "Opening stock", "Returned to stock", "Thrown away", "Correction", "Stocktaking", never "return_restock" or "stocktake".
 * The filter offers only the kinds of movement that really happened (as in Retail Production since 2026-10-18).
 */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'every movement type has its words, and the filter lists only the types that happened' => function (): void {
        assert_same(['opening', 'purchase', 'sale', 'sale_void', 'return_restock', 'waste', 'adjustment', 'stocktake'], array_keys(StockMovement::LABELS));
        assert_same('Returned to stock', StockMovement::LABELS['return_restock']);
        assert_same([], (new StockMovement())->typesUsed(), 'nothing happened yet');
        $p = make_product('Nakhla');
        $piece = (new ProductService())->addUnit($p, 'Piece', '1', false, true);
        (new StockService())->adjust($p, $piece, '10', 'opening', '', null, TEST_ADMIN_ID);
        (new StockService())->adjust($p, $piece, '-2', 'waste', 'broken', null, TEST_ADMIN_ID);
        assert_same(['opening', 'waste'], (new StockMovement())->typesUsed(), 'in the list\'s order, only what occurred');

        $page = login_as('admin', TEST_ADMIN_PASSWORD)->get('stock')->body;
        assert_contains('<option value="opening" >Opening stock</option>', $page);
        assert_contains('<option value="waste" >Thrown away</option>', $page);
        assert_not_contains('<option value="stocktake"', $page, 'no stocktake happened');
        assert_not_contains('return_restock', $page);
        assert_not_contains('>return restock<', $page);
        assert_contains('<td class="text-nowrap">Thrown away</td>', $page, 'the Type column uses the same words');
        assert_contains('<td class="text-nowrap">Opening stock</td>', $page);
    },
];
