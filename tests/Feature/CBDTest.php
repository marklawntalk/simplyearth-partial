<?php

namespace Tests\Feature;

use App\Jobs\ProcessShoppingBox;
use App\Shop\Categories\Category;
use App\Shop\Customers\Account;
use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use App\Shop\Shipping\ShippingZone;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Shop\ShoppingBoxes\ShoppingBox;

class CBDTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_monthly;

    public function setUp()
    {

        parent::setUp();

        $this->shipping_zone = ShippingZone::create(
            [
                'name' => 'Test 2',
                'countries' => '["US"]',
            ]
        );

        $this->shipping_zone->shipping_rate_prices()->create([
            'name' => 'Regular',
            'min' => 0,
            'is_free' => 0,
            'rate' => 15,
        ]);

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY_SKU', 'subscription-monthly'), 'price' => 5]
        );

        $shop_date = Carbon::parse('first day of last month');

        for ($i = 0; $i < 13; $i++) {
            factory(ShoppingBox::class)->create([
                'name' => $shop_date->format('F Y'),
                'stock' => 999,
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }
    }

    public function test_cbd_product()
    {
        $product = factory(Product::class)->create(['price' => 10, 'shipping' => 1]);
        $cbd_product = factory(Product::class)->create(['price' => 20, 'shipping' => 0]);

        $category = Category::create(['name' => 'CBD', 'slug' => 'cbd']);

        $category->products()->save($cbd_product);

        //NON-CBD
        Cart::add($product);
        $this->assertFalse(Cart::hasCBDProduct());

        //CBD
        Cart::add($cbd_product->refresh());
        $this->assertTrue(Cart::hasCBDProduct());

        $this->postJson('checkout', [
            'square_nonce' => 'cnon:card-nonce-ok',
            'checkout' => [
                'email' => 'mharkrollen@gmail.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $this->shipping_zone->shipping_rate_prices->first()->shipping_method_key,
            ],
        ]);

        $this->assertNotNull(Order::where('email', 'mharkrollen@gmail.com')->first());

        //Test if the square id is saved against customer account after checkout
        $this->signInAsCustomer();
        Cart::add($cbd_product);

        $this->postJson('checkout', [
            'square_nonce' => 'cnon:card-nonce-ok',
            'checkout' => [
                'email' => 'mharkrollen@gmail.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $this->shipping_zone->shipping_rate_prices->first()->shipping_method_key,
            ],
        ])->assertStatus(200);

        $this->assertNotNull(customer()->refresh()->account->square_id);
        $this->assertCount(2, Order::all());

        //Test the save card on new purchases

        Cart::add($cbd_product);

        $this->postJson('checkout', [
            'checkout' => [
                'email' => 'mharkrollen@gmail.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $this->shipping_zone->shipping_rate_prices->first()->shipping_method_key,
            ],
        ])->assertStatus(200);

        $this->assertCount(3, Order::all());

        //Test square with non cbd products

        Cart::add($product);
        
        $this->postJson('checkout', [
            'checkout' => [
                'email' => 'mharkrollen@gmail.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $this->shipping_zone->shipping_rate_prices->first()->shipping_method_key,
            ],
        ])->assertStatus(200);

        //Test subscription
        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $nextBox = customer()->account->refresh()->nextBox();

        ProcessShoppingBox::dispatch($nextBox);

        $this->assertDatabaseHas('orders', [
            'email' => customer()->email,
            'status' => Order::ORDER_PROCESSING,
            'box_key' => $nextBox->month_key
        ]);
    }

    public function test_cbd_charge()
    {
        $customer = $this->signInAsCustomer();

        //Saving a card
        $square_customer = $customer->account->createasSquareCustomer('cnon:card-nonce-ok');
        $customer->refresh();
        $this->assertNotNull($customer->account->square_id);

        //Charge
        $response = $customer->account->chargeSquare(55.53);

        $this->assertEquals(5553, $response->getPayment()->getTotalMoney()->getAmount());
    }
}
