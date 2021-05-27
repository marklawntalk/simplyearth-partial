<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Shop\Shipping\ShippingZone;
use App\Shop\ShoppingBoxes\ShoppingBox;
use Illuminate\Support\Carbon;
use App\Shop\Products\Product;
use App\Shop\Customers\Account;
use App\Shop\ShoppingBoxes\ShoppingBoxBuilder;
use App\Shop\Orders\Order;
use Facades\App\Shop\Cart\Cart;

class ShippingTest extends TestCase
{

    use RefreshDatabase;

    public function setUp()
    {
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(['type' => 'subscription', 'sku' => 'subscription-monthly', 'price' => 39]);

        $shop_date = Carbon::parse('first day of this month');

        for ($i = 0; $i < 12; $i++) {
            factory(ShoppingBox::class)->create([
                'name' => $shop_date->format('F Y'),
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }

    }

    function test_shipping_zone_admin_access()
    {

        $this->getJson('/admin/shipping-zones')->assertStatus(401);
        
        $this->signInAsAdmin();

        $this->getJson('/admin/shipping-zones')->assertStatus(200);

    }

    function test_shipping_creation()
    {
        $this->signInAsAdmin();

        $this->postJson('/admin/shipping-zones',[])->assertStatus(422);
        $response = $this->postJson('/admin/shipping-zones', [
            'name' => 'Canada',
            'countries' => ['CA']
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('shipping_zones', [
            'name' => 'Canada',
            'countries' => json_encode(['CA'])
        ]);
    }

    function test_rejects_invalid_countries()
    {
        $this->signInAsAdmin();

        $response = $this->postJson('/admin/shipping-zones', [
            'name' => 'USA & PH Only',
            'countries' => 'XX,US,SPAIN,PH'
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('shipping_zones', [
            'name' => 'USA & PH Only',
            'countries' => json_encode(['US','PH'])
        ]);
    }
    
    function test_rest_of_the_world()
    {
        $this->signInAsAdmin();
        
        $zone1 = ShippingZone::create(['name' => 'International 1','countries' => '*']);

        $this->postJson('/admin/shipping-zones', [
            'name' => 'Rest of the world',
            'countries' => '*'
        ])->assertStatus(422);

        $this->patchJson('/admin/shipping-zones/'.$zone1->id, [
            'name' => 'Rest of the world',
            'countries' => '*'
        ])->assertStatus(200);

        $shipping_method =$zone1->shipping_rate_prices()->create([
            'name' => 'Regular',
            'min' => 0,
            'is_free' => 0,
            'rate' => 15,
        ]);

        //Test Shipping for shopping box
            //Within US shipping should be zero
        $account = factory(Account::class)->create();
        $account->subscribe($this->subscription_monthly->sku)->setSchedule(23)->create();
        $order = (new ShoppingBoxBuilder($account->nextBox()))->setData(['status' => Order::ORDER_PROCESSING, 'checkout' => [
            'shipping_address' => ['country' => 'US']
        ]])->build();

        $this->assertEquals(0, $order->total_shipping);

        //International shipping should NOT be equal to zero
        $account2 = factory(Account::class)->create();
        $account2->subscribe($this->subscription_monthly->sku)->setSchedule(23)->create();
        $order = (new ShoppingBoxBuilder($account2->nextBox()))->setData(['status' => Order::ORDER_PROCESSING, 'checkout' => [
            'shipping_address' => ['country' => 'CA']
        ]])->build();

        $this->assertEquals(15, $order->total_shipping);
        
        //Login non US international shipping should not be equal to zero
        $this->signInAsCustomer();

        Cart::add($this->subscription_monthly);

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-nonce',
            'checkout' => [
                'email' => customer()->email,
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'US',
                    'region' => 'Bohol',
                ],
                'shipping_method_key' => $shipping_method->shipping_method_key,
                'same_as_shipping_address' => true,
            ],
        ])->assertStatus(200);

        $this->assertEquals(15, $order->total_shipping);
    }

    public function test_it_should_auto_calculate_shipping_when_adding_products_to_cart()
    {
        $this->signInAsAdmin();
        
        $zone1 = ShippingZone::create(['name' => 'International 1','countries' => '*']);

        $this->postJson('/admin/shipping-zones', [
            'name' => 'Rest of the world',
            'countries' => '*'
        ])->assertStatus(422);

        $this->patchJson('/admin/shipping-zones/'.$zone1->id, [
            'name' => 'Rest of the world',
            'countries' => '*'
        ])->assertStatus(200);

        $shipping_method =$zone1->shipping_rate_prices()->create([
            'name' => 'Regular',
            'min' => 0,
            'is_free' => 0,
            'rate' => 5.95,
        ]);

        $shipping_method2 =$zone1->shipping_rate_prices()->create([
            'name' => 'Free Shipping',
            'min' => 29,
            'is_free' => 0,
            'rate' => 0,
        ]);

        $product1 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);
        $product3 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);
        $product4 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);

        Cart::add($product1)->setShippingMethod($shipping_method);
        

        $this->assertEquals(5.95, Cart::getTotalShipping());

        $this->get("/cart/{$product2->id}/add");
        $this->get("/cart/{$product3->id}/add");
        $this->get("/cart/{$product4->id}/add");

        $this->assertEquals($shipping_method2->shipping_method_key, Cart::getCart()->get('shipping')->shipping_method_key);

        $this->assertEquals(80, Cart::getSubTotal());

        $this->assertEquals(0, Cart::getTotalShipping());

        $this->assertEquals($shipping_method2->shipping_method_key, Cart::availableShippingMethods()->first()->shipping_method_key);

    }
}
