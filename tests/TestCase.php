<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\User;
use App\Shop\Customers\Account;
use App\Shop\Customers\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public function setUp()
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        set_option('store_address', [
            'address' => 'W4228 Church Rd',
            'city' => 'Waldo',
            'zip' => 53093,
            'country' => 'US',
            'region' => 'WI',
            'phone' => '8663308165',
        ]);
    }

    public static function callMethod($obj, $name, array $args)
    {
        $class = new \ReflectionClass($obj);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method->invokeArgs($obj, $args);
    }

    protected function signInAsAdmin()
    {
        $user = factory(User::class)->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        return $this;
    }

    protected function signInAsSuperAdmin()
    {
        $user = factory(User::class)->create();
        $user->assignRole('super-admin');
        $this->actingAs($user);

        return $this;
    }

    protected function signIn($user = null)
    {
        $user = $user ?: factory(User::class)->create();
        $this->actingAs($user);

        return $this;
    }

    /**
     * Create and signing customer.
     *
     * @param Account $account
     *
     * @return Customer
     */
    protected function signInAsCustomer(Account $account = null)
    {
        $account = $account ?: factory(Account::class)->create();

        $customer = $account->customer ?? factory(Customer::class)->create(['email' => $account->email]);

        $account->customer()->associate($customer);

        $this->actingAs($account, 'customer');

        return $customer;
    }

    protected function signInAsWholesaler($account = null)
    {
        $this->signInAsCustomer($account);

        customer()->assignTags('wholesale');
    }
}
