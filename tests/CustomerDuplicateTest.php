<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Models\Customer;
use App\Services\PartyService;

/** U9: a phone that another customer has is refused; the same name is asked about. */
$customer = static fn (string $name, string $phone, array $extra = []): array => $extra + ['name' => $name, 'phone' => $phone, 'notes' => '', 'default_price_level' => 'retail', 'credit_limit_usd' => '', 'is_active' => true];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'one phone number is one customer, however it is written' => function () use ($customer): void {
        $svc = new PartyService();
        $svc->saveCustomer(0, $customer('Ahmad Saleh', '03 111 222'));
        foreach (['03 111 222', '03-111222', '+961 3 111 222', '009613111222', '3111222'] as $same) {
            $e = assert_throws(DomainException::class, fn () => $svc->saveCustomer(0, $customer('Rami Khoury', $same)));
            assert_contains('belongs to Ahmad Saleh', $e->getMessage(), $same);
        }
        $svc->saveCustomer(0, $customer('Rami Khoury', '03 111 223'));
        $svc->saveCustomer(0, $customer('No Phone One', ''));
        $svc->saveCustomer(0, $customer('No Phone Two', ''));   // an empty phone is nobody's number
        assert_same(4, count((new Customer())->all()));
    },

    'the same name needs a confirmation' => function () use ($customer): void {
        $svc = new PartyService();
        $svc->saveCustomer(0, $customer('Ahmad Saleh', '03 111 222'));
        $e = assert_throws(DomainException::class, fn () => $svc->saveCustomer(0, $customer('  ahmad saleh ', '70 999 888')));
        assert_same(PartyService::SAME_NAME, $e->getCode());
        assert_contains('already exists (phone 03 111 222)', $e->getMessage());
        $second = $svc->saveCustomer(0, $customer('Ahmad Saleh', '70 999 888', ['allow_same_name' => true]));
        assert_true($second > 0);
        // Even confirmed, the phone rule stays.
        assert_throws(DomainException::class, fn () => $svc->saveCustomer(0, $customer('Ahmad Saleh', '03 111 222', ['allow_same_name' => true])));
    },

    'editing a customer checks only what was changed' => function () use ($customer): void {
        $svc = new PartyService();
        $first = $svc->saveCustomer(0, $customer('Ahmad Saleh', '03 111 222'));
        $other = $svc->saveCustomer(0, $customer('Rami Khoury', '70 999 888'));
        $svc->saveCustomer($first, $customer('Ahmad Saleh', '03 111 222', ['notes' => 'pays on Fridays']));   // nothing changed that could collide
        assert_same('pays on Fridays', (new Customer())->find($first)['notes']);
        assert_throws(DomainException::class, fn () => $svc->saveCustomer($other, $customer('Rami Khoury', '03 111 222')));
        $e = assert_throws(DomainException::class, fn () => $svc->saveCustomer($other, $customer('Ahmad Saleh', '70 999 888')));
        assert_same(PartyService::SAME_NAME, $e->getCode());
    },

    'the form offers "different person" only after the name was found' => function () use ($customer): void {
        (new PartyService())->saveCustomer(0, $customer('Ahmad Saleh', '03 111 222'));
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $form = ['id' => '0', 'name' => 'Ahmad Saleh', 'phone' => '70 999 888', 'notes' => '', 'default_price_level' => 'retail', 'credit_limit_usd' => ''];
        assert_not_contains('allow_same_name', $client->get('customers/edit')->body);
        assert_same(302, $client->post('customers/save', $form)->status);
        $back = $client->get('customers/edit')->body;
        assert_contains('already exists (phone 03 111 222)', $back);
        assert_contains('name="allow_same_name"', $back);
        assert_same(1, count((new Customer())->all()));
        assert_same(302, $client->post('customers/save', $form + ['allow_same_name' => '1'])->status);
        assert_same(2, count((new Customer())->all()));

        $client->get('customers/edit');
        assert_same(302, $client->post('customers/save', ['name' => 'Somebody Else', 'phone' => '03-111-222'] + $form)->status);
        assert_contains('belongs to Ahmad Saleh', $client->get('customers/edit')->body);
        assert_same(2, count((new Customer())->all()));
    },
];
