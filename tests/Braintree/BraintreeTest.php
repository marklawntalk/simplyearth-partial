<?php

namespace Tests\Feature;

use App\Shop\Customers\Account;
use App\Shop\Customers\Customer;
use App\Shop\Orders\Order;
use App\Shop\Orders\ShippingAddress;
use App\Shop\Products\Product;
use App\Shop\Shipping\ShippingRatePrice;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;
use App\Shop\Discounts\Discount;
use App\Shop\Discounts\GiftCard;

class BraintreeTest extends TestCase
{
    use RefreshDatabase;

    public function setUp()
    {
        parent::setUp();

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
    }

    public function test_order_is_created_upon_checkout()
    {

        $product1 = factory(Product::class)->create(['price' => 500, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 400, 'shipping' => 1]);

        Cart::add($product1);
        Cart::add($product2);

        $shipping_rate_price = ShippingRatePrice::first();

        Mail::fake();

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-nonce',
            'checkout' => [
                'email' => 'checkoutemail@test.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $shipping_rate_price->shipping_method_key,
            ],
        ])->assertStatus(200);
            
        $order = Order::where(['email' => 'checkoutemail@test.com'])->first();
        
        Mail::assertQueued(\App\Mail\OrderProcessed::class, function ($mail) use ($order) {
            return $mail->order->id === $order->id;
        });

        $this->assertNotNull($order);
        $this->assertCount(0, Cart::getProducts());
        $this->assertNotNull($order->order_name);
        $this->assertNotNull($order->payment_details);
        $this->assertNotNull($order->processed_at);
        $this->assertNotNull($order->ip_address);
        $this->assertNotNull($order->customer_id);
        $this->assertNotNull($order->token);
        $this->assertEquals($order->total_shipping, 15);
        $this->assertEquals($shipping_rate_price->name, $order->requested_shipping_service);
        $this->assertEquals(Order::ORDER_PROCESSING, $order->status);

        $this->assertEquals([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'checkoutemail@test.com',
        ], [
            'first_name' => $order->customer->first_name,
            'last_name' => $order->customer->last_name,
            'email' => $order->customer->email,
        ]);

        $this->assertTrue($order->customer->addresses()->where([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'primary' => 1,
            'address1' => 'Kamagong RD Uptown',
            'city' => 'Tagbilaran',
            'zip' => '6300',
            'country' => 'PH',
            'region' => 'Bohol',
        ])->exists());
    }

    public function test_order_shipping_address()
    {

        sleep(2);
        $product1 = factory(Product::class)->create(['price' => 111, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 222, 'shipping' => 1]);

        Cart::add($product1);
        Cart::add($product2);

        $shipping_rate_price = ShippingRatePrice::first();

        $this->signInAsCustomer();

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-visa-nonce',
            'checkout' => [
                'email' => 'checkoutemail@test2.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $shipping_rate_price->shipping_method_key,
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('shipping_addresses', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => 'Kamagong RD Uptown',
            'city' => 'Tagbilaran',
            'zip' => '6300',
            'country' => 'PH',
            'region' => 'Bohol',
        ]);
    }

    public function test_order_guest_shipping_address()
    {
        Event::fake();
        
        $product1 = factory(Product::class)->create(['price' => 500, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 400, 'shipping' => 1]);

        Cart::add($product1);
        Cart::add($product2);

        $gift_card = new GiftCard([
            'code' => 'LLL',
            'customer_id' => 0,
            'remaining' => 25,
            'token' => str_random(30)]);

        $gift_card->order_item_id = 0;
        $gift_card->save();

        Cart::applyGiftCard($gift_card->code);

        Discount::create([
            'code' => 'aaa',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 20,
            ],
        ]);
        
        Cart::applyDiscount('aaa');

        $shipping_rate_price = ShippingRatePrice::first();

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-nonce',
            'checkout' => [
                'email' => 'checkoutemail@test.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'PH',
                    'region' => 'Bohol',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => $shipping_rate_price->shipping_method_key,
            ],
        ])->assertStatus(200);

        $order = Order::first();

        $this->assertEquals(710, $order->total_price); // 900 subtotal + 15 shipping - 205 discount
        $this->assertEquals(205, $order->total_discounts); //180 Discount code plus 25 gift card
        $this->assertContains('aaa', $order->discount_details);

        $shipping_address = ShippingAddress::first();

        $this->assertDatabaseHas('shipping_addresses', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address1' => 'Kamagong RD Uptown',
            'city' => 'Tagbilaran',
            'zip' => '6300',
            'country' => 'PH',
            'region' => 'Bohol',
        ]);
    }

    public function test_it_saves_braintree_account()
    {
        $this->signInAsCustomer();

        customer()->charge(10, 'fake-valid-nonce');

        $this->assertNotNull(customer()->account->fresh()->braintree_id);

        $response = customer()->account->charge(20);

        $this->assertTrue($response->success);
    }

}
