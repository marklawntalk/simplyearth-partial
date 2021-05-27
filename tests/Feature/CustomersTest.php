<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\User;
use App\Shop\Customers\Account;
use App\Shop\Customers\{Customer, Address};
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Facades\App\Shop\Customers\CustomerRepository;
use Illuminate\Support\Facades\Queue;
use App\Jobs\KlaviyoIdentify;
use App\Mail\AccountManagement;

class CustomersTest extends TestCase
{

    use RefreshDatabase;

    public function setUp()
    {
        // first include all the normal setUp operations
        parent::setUp();

        // now re-register all the roles and permissions
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->registerPermissions();
    }

    function test_customer_registration()
    {
        $account = factory(Account::class)->create();

        $this->get('/register')->assertStatus(200);

        $this->json('POST','/register',[
            'first_name' => 'Test',
            'last_name' => 'L',
            'email' => $account->email,
            'password' => 'password',
            'password_confirmation' => 'password'

        ])->assertStatus(422);

    }

    function test_customer_repository_checkout()
    {
        $checkout_data = [
            "shipping_address" => [
                "country" => "US",
                "first_name" => "Mark",
                "last_name" => "L",
                "address1" => "21321",
                "address2" => "432",
                "city" => "32131",
                "zip" => "6300",
                "region" => "NY",
            ],
            "billing_address" => [
                "country" => "US"
            ],
            "email" => "mharkrollen@gmail.com",
            "first_name" => "Mark",
            "last_name" => "Lontoc",
            "phone" => "1234",
            "shipping_method" => null,
            "same_as_shipping_address" => true,
        ];

        $customer = CustomerRepository::createCustomerFromCheckout($checkout_data);

        $this->assertDatabaseHas(
            'customers',
            [
                "email" => "mharkrollen@gmail.com",
                "first_name" => "Mark",
                "last_name" => "Lontoc",
                "phone" => "1234"
            ]
        );



        $this->assertDatabaseHas(
            'addresses',
            [
                "country" => "US",
                "first_name" => "Mark",
                "last_name" => "L",
                "address1" => "21321",
                "address2" => "432",
                "city" => "32131",
                "zip" => "6300",
                "region" => "NY",
            ]
        );


        $customer = CustomerRepository::createCustomerFromCheckout($checkout_data);

        $this->assertCount(1, Customer::all());
        $this->assertCount(1, $customer->addresses);
        $this->assertEquals(1, $customer->addresses->first()->primary);
    }

    function test_saving_multiple_addresses()
    {
        $customer = factory(Customer::class)->create(['email' => 'mharkrollen@gmail.com']);

        CustomerRepository::updateOrCreateAddress(
            $customer,
            [
                "country" => "US",
                "first_name" => "Mark",
                "last_name" => "L",
                "address1" => "21321",
                "address2" => "432",
                "city" => "32131",
                "zip" => "6300",
                "region" => "NY",
            ]
        );

        CustomerRepository::updateOrCreateAddress(
            $customer,
            [
                "country" => "US",
                "first_name" => "Weak",
                "last_name" => "L",
                "address1" => "21321",
                "address2" => "432",
                "city" => "32131",
                "zip" => "6300",
                "region" => "NY",
            ]
        );

        $customer->refresh();

        $this->assertCount(2, $customer->addresses);
        $this->assertCount(1, $customer->addresses->where('primary', 1));
        $this->assertDatabaseHas(
            'addresses',
            [
                'customer_id' => $customer->id,
                "country" => "US",
                "first_name" => "Weak",
                "last_name" => "L",
                "address1" => "21321",
                "address2" => "432",
                "city" => "32131",
                "zip" => "6300",
                "region" => "NY"
            ]
        );
    }

    /*function test_it_adds_default_address()
    {
        $this->postJson('/register', [
            'first_name' => 'Test',
            'last_name' => 'L',
            'email' => 'test@email.com',
            'password' => 'password',
            'password_confirmation' => 'password'
        ]);

        $this->assertTrue(Customer::first()->addresses()->where([
            'first_name' => 'Test',
            'last_name' => 'L',
            'primary' => 1,
            'country' => config('app.country')
        ])->exists());

    }*/

    function test_send_account_management()
    {
        Mail::fake();

        $customer = factory(Customer::class)->create(['email' => 'mharkrollen@gmail.com']);

        $customer->sendAccountManagement();

        $this->assertNotNull($customer->account);
        $this->assertEquals('mharkrollen@gmail.com', $customer->account->email);

        Mail::assertSent(AccountManagement::class);
    }
    
}
