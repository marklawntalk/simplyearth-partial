<?php

namespace Tests\Feature;

use App\Shop\Checkout\Cart as CheckoutCart;
use App\Shop\Customers\Customer;
use App\Shop\Orders\Order;
use Facades\App\Shop\Cart\Cart;
use App\Shop\Products\Product;
use App\Shop\Shipping\ShippingRatePrice;
use Facades\App\Shop\Checkout\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_chooses_available_shipping_methods()
    {
        //create shippingzone

        $this->signInAsAdmin();

        $this->postJson('/admin/shipping-zones', [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => '49 below',
                    'min' => 0,
                    'max' => 49,
                    'is_free' => 0,
                    'rate' => 10,
                ],
                [
                    'name' => '49 below Express',
                    'min' => 0,
                    'max' => 49,
                    'is_free' => 0,
                    'rate' => 10,
                ],
                [
                    'name' => '50-100 Below',
                    'min' => 50,
                    'max' => 100,
                    'is_free' => 0,
                    'rate' => 20,
                ],
                [
                    'name' => 'Free shipping 150',
                    'min' => 150,
                    'is_free' => 1,
                ],
            ],
        ])->assertStatus(200);

        $this->assertCount(4, ShippingRatePrice::all());

        $this->signInAsCustomer();

        $this->assertAuthenticated('customer');

        $customer = Auth::guard('customer')->user()->customer()->first();

        $customer->addresses()->create([
            'country' => 'US',
            'primary' => 1,
        ]);

        $product1 = factory(Product::class)->create(['price' => 10, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $product3 = factory(Product::class)->create(['price' => 100, 'shipping' => 1]);

        Cart::add($product1);

        $this->assertCount(2, Cart::availableShippingMethods());

        $this->assertNotNull(Cart::autoSetShipping()->getShipping());

        Cart::add($product2);

        $this->assertCount(1, Cart::availableShippingMethods());
        $this->assertEquals(Cart::availableShippingMethods()->first()->name, '50-100 Below');

        Cart::add($product3);

        $this->assertCount(1, Cart::availableShippingMethods());

        $this->assertEquals(Cart::availableShippingMethods()->first()->name, 'Free shipping 150');
    }

    public function test_it_can_accept_posst_requests()
    {
        $this->postJson('checkout', [])->assertStatus(422);
    }

    public function test_it_gets_customer_from_login_customer()
    {
        $this->signInAsCustomer();

        $this->assertEquals(customer(), Checkout::getOrCreateCustomer());
    }

    public function test_it_gets_customer_from_guest_customer()
    {
        //check if it auto creates non-existing customer email
        $guest = Checkout::setData(['checkout' => [
            'email' => 'testsomethingrandom@email',
        ]])->getOrCreateCustomer();

        $this->assertInstanceOf(Customer::class, $guest);
        $this->assertNotNull($guest->default_address);

        //Check if it pulls existing csutomer
        $customer = factory(Customer::class)->create();
        $customer2 = Checkout::setData([
            'checkout' => [
                'email' => $customer->email,
            ],
        ])->getOrCreateCustomer();

        $this->assertEquals($customer->id, $customer2->id);
    }

    public function test_it_stores_address_for_newly_registered_users()
    {
        $this->signInAsAdmin();

        $this->postJson('/admin/shipping-zones', [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Regular',
                    'min' => 0,
                    'is_free' => 0,
                    'rate' => 15,
                ],
            ],
        ])->assertStatus(200);

        Auth::logout();

        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 500, 'shipping' => 1]);

        Cart::add($product1);

        $shipping_rate_price = ShippingRatePrice::first();

        customer()->assignTags(['installment-failed-charge']);

        customer()->refresh();

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-nonce',
            'checkout' => [
                'email' => 'checkoutemail@test.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'US',
                    'region' => 'Bohol',
                ],
                'shipping_method_key' => $shipping_rate_price->shipping_method_key,
                'same_as_shipping_address' => true,
            ],
        ])->assertStatus(422);

        customer()->removeTags(['installment-failed-charge'])->refresh();

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-nonce',
            'checkout' => [
                'email' => 'checkoutemail@test.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'US',
                    'region' => 'Bohol',
                ],
                'shipping_method_key' => $shipping_rate_price->shipping_method_key,
                'same_as_shipping_address' => true,
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('addresses', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => 'Kamagong RD Uptown',
            'city' => 'Tagbilaran',
            'zip' => '6300',
            'country' => 'US',
            'region' => 'Bohol',
        ]);
    }

    public function test_checkout_with_kount()
    {
        config(['app.enable_advanced_fraud_tools' => true]);

        $this->signInAsAdmin();

        $this->postJson('/admin/shipping-zones', [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Regular',
                    'min' => 0,
                    'is_free' => 0,
                    'rate' => 15,
                ],
            ],
        ])->assertStatus(200);

        Auth::logout();

        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 500, 'shipping' => 1]);

        Cart::add($product1);

        $shipping_rate_price = ShippingRatePrice::first();

        $this->postJson('checkout', [
            'nonce' => 'fake-gateway-rejected-kount-nonce',
            'checkout' => [
                'deviceData' => 'fjdkaslfjdsklafjds',
                'email' => 'checkoutemail@test.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'US',
                    'region' => 'Bohol',
                ],
                'shipping_method_key' => $shipping_rate_price->shipping_method_key,
                'same_as_shipping_address' => true,
            ],
        ])->assertStatus(422);
    }

    function test_expidited()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 500, 'shipping' => 1, 'sku' => 'ACC-RUSH-SHIPPING']);

        Cart::add($product1)->setData(['status' => Order::ORDER_PROCESSING])->setCustomer(customer())->build();

        $this->assertEquals('EXPIDITED', Order::first()->requested_shipping_service);
    }
}
