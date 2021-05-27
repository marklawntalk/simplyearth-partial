<?php

namespace Tests\Feature;

use App\Shop\Discounts\Discount;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Products\Product;
use Illuminate\Support\Carbon;
use App\Shop\ShoppingBoxes\ShoppingBox;
use App\Shop\Subscriptions\SubscriptionNormalDiscount;
use Facades\App\Shop\Cart\Cart;

class ReactivationTest extends TestCase
{
    use RefreshDatabase;

    public $subscription_monthly;

    public function setUp()
    {
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY_SKU', 'subscription-monthly'), 'price' => 39]
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
    function test_reactivation()
    {
        $discount = Discount::create([
            'code' => 'REACTIVATE',
            'type' => 'percentage',
            'options' => [
                'is_subscription_box_only' => 1,
                'is_reactivation_only' => 1,
                'discount_value' => 10,
            ]
        ]);

        set_option('settings_subscription.reactivation_code', $discount->code);

        //Login
        $this->signInAsCustomer();

        //Subscribe
        customer()->account->subscribe($this->subscription_monthly->sku)
        ->create();

        //Subscribed users shouldt see the reactivation popup
        $this->getJson('/profile')->assertStatus(200)->assertDontSeeText('LIMITED TIME OFFER');

        //Only the cancelled subscription can see the reactivation popup
        customer()->account->subscription->stop();

        $this->getJson('/profile')->assertStatus(200)->assertSeeText('LIMITED TIME OFFER');

        customer()->refresh();

        customer()->account->subscription->resume();

        $this->getJson('/profile')->assertStatus(200)->assertSeeText('Yay! Your Recipe Box is Resumed!');


        $this->assertDatabaseHas('subscription_normal_discounts', ['code' => $discount->code, 'subscription_id' => customer()->account->subscription->id]);

        customer()->refresh();

        $this->assertEquals(3.9, customer()->account->nextBox()->getBuilder()->getDiscountTotal());
    }

    function test_reactivation_cant_be_applied_to_normal_checkout()
    {
        $discount = Discount::create([
            'code' => 'REACTIVATE',
            'type' => 'percentage',
            'options' => [
                'is_subscription_box_only' => 1,
                'is_reactivation_only' => 1,
                'discount_value' => 10,
            ]
        ]);

        set_option('settings_subscription.reactivation_code', $discount->code);

        $product = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);

        Cart::add($product, 1);

        Cart::applyDiscount($discount->code);

        $this->assertEquals(0, Cart::getDiscountTotal());

        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
        ->create();

        //Fail on normal order
        $this->postJson('/cart/gift-card-discount', ['code' => $discount->code])->assertStatus(422);

        //Fail reactivation only discount on subscription box
        $this->postJson('/subscription/discount', ['code' => $discount->code])->assertStatus(422);
    }
}
