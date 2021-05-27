<?php

namespace Tests\Unit;

use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use App\Shop\ShoppingBoxes\ShoppingBoxBuilder;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Carbon;
use App\Shop\ShoppingBoxes\ShoppingBox;
use App\Shop\Orders\OrderItem;
use App\Shop\Subscriptions\Subscription;
use App\Shop\Subscriptions\SubscriptionAddons;
use App\Shop\Subscriptions\SubscriptionExchanges;

class CartTest extends TestCase
{

    use RefreshDatabase;

    protected $subscription_monthly;

    public function setUp()
    {
        Queue::fake();
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'name' => 'Monthly Subscription Box', 'sku' => config('subscription.monthly'), 'price' => 5]
        );

        $shop_date = Carbon::parse('first day of this month');

        for ($i = 0; $i < 12; $i++) {
            factory(ShoppingBox::class)->create([
                'name' => $shop_date->format('F Y'),
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }
    }

    public function test_cart_is_adding()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product2 = factory(Product::class)->create(['price' => 400]);

        Cart::add($product1);

        $this->assertCount(1, Cart::getProducts());

        $this->assertEquals(1, Cart::getProducts()->first()->qty);

        Cart::add($product2);

        $this->assertCount(2, Cart::getProducts());

    }

    public function test_cart_removing()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product2 = factory(Product::class)->create(['price' => 400]);
        $product3 = factory(Product::class)->create(['price' => 900]);

        Cart::add($product1);
        Cart::add($product2);
        Cart::remove($product3);

        $this->assertCount(2, Cart::getProducts());

        Cart::remove($product2);

        $this->assertCount(1, Cart::getProducts());

        Cart::remove($product1);

        $this->assertCount(0, Cart::getProducts());
    }

    public function test_adding_existing_product_would_only_increment_the_quantity()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        Cart::add($product1);
        Cart::add($product1);

        $this->assertCount(1, Cart::getProducts());

    }

    public function test_setting_quantity_to_zero_or_below_will_remove_product()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product2 = factory(Product::class)->create(['price' => 400]);

        Cart::add($product1, 0);
        $this->assertCount(0, Cart::getProducts());
        Cart::add($product1, -1);
        $this->assertCount(0, Cart::getProducts());
        Cart::add($product1, 10);
        $this->assertCount(1, Cart::getProducts());
        Cart::update($product1, 0);
        $this->assertCount(0, Cart::getProducts());
    }

    public function test_get_product()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        Cart::add($product1);

        $this->assertEquals($product1->id, Cart::getProductById($product1->id)->id);
    }

    public function test_cart_quantity()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        Cart::add($product1, 5);

        $this->assertEquals(5, Cart::getProductById($product1->id)->qty);

        Cart::update($product1, 20);

        $this->assertEquals(20, Cart::getProductById($product1->id)->qty);
    }

    public function test_if_cart_products_matches_session_items()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product2 = factory(Product::class)->create(['price' => 400]);

        Cart::add($product1, 1);
        Cart::add($product2, 5);

        $this->assertEquals(collect([
            ['id' => $product1->id, 'qty' => 1],
            ['id' => $product2->id, 'qty' => 5]]), Session::get('cart.items'));

    }

    public function test_it_adds_cart_via_patch_method()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $response = $this->patchJson('/cart/' . $product1->id . '/edit', ['qty' => 3]);
        $response->assertStatus(200);
        $this->assertEquals(3, json_decode($response->content())->data->count);
    }

    public function test_order_builder_shipping_total()
    {
        $product1 = factory(Product::class)->create(['price' => 500, 'shipping' => 1, 'weight' => 0]);
        $product2 = factory(Product::class)->create(['price' => 400, 'shipping' => 1, 'weight' => 350]);
        $product3 = factory(Product::class)->create(['price' => 300, 'weight' => 200]);

        Cart::add($product1, 4);
        Cart::add($product2);
        Cart::add($product3);

        $this->assertEquals(2400, Cart::getShippablePriceTotal());
        $this->assertEquals(350, Cart::getShippableWeightTotal());

    }

    public function test_if_cart_replace_existing_subscription_product()
    {
        $product = factory(Product::class)->create();
        $subscription1 = factory(Product::class)->create(['type' => 'subscription']);
        $subscription2 = factory(Product::class)->create(['type' => 'subscription']);

        Cart::add($product);

        $this->assertCount(1, Cart::getProducts());

        Cart::add($subscription1);

        $this->assertCount(2, Cart::getProducts());

        Cart::add($subscription2);

        $this->assertCount(2, Cart::getProducts());

        $this->assertTrue(Cart::hasSubscriptionProduct());
    }

    public function test_it_checks_addons()
    {
        $this->signInAsCustomer();

        $product = factory(Product::class)->create();

        $this->patchJson('/cart/' . $product->id . '/edit')->assertStatus(200);
        $this->assertEquals(1, Cart::count());

        customer()->account->subscribe($this->subscription_monthly->sku)->create();

        customer()->account->refresh();

        $this->patchJson('/cart/' . $product->id . '/edit')->assertStatus(200)->assertJsonFragment(
            [
                'when_to_ship' => true,
                'available_shipping' => Cart::availableShippingMethods()->first(),
            ]
        );

        $this->patchJson('/cart/' . $product->id . '/edit', [
            'ship_now' => true,
            'qty' => 1,
        ])->assertStatus(200);

        $this->assertEquals(1, Cart::count());

        //add addons
        $this->patchJson('/cart/' . $product->id . '/edit', [
            'ship_now' => false,
            'qty' => 1,
        ])->assertStatus(200);

        $this->assertCount(1, customer()->account->refresh()->nextBox()->getAddons());

        //update addons
        $this->patchJson('/box/' . customer()->account->nextBox()->monthKey . '/addon/' . $product->id, [
            'qty' => 3,
        ])->assertStatus(200);

        $this->assertEquals(3, customer()->account->refresh()->nextBox()->getAddons()->first()->qty);

        $this->assertCount(1, SubscriptionAddons::where('subscription_id', customer()->account->subscription->id)->get());

        //Delete addons
        $this->patchJson('/box/' . customer()->account->nextBox()->monthKey . '/addon/' . $product->id, [
            'qty' => 0,
        ])->assertStatus(200);

        $this->assertCount(0, SubscriptionAddons::where('subscription_id', customer()->account->subscription->id)->get());

    }

    public function test_box_exchanges()
    {
        Queue::fake();

        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 10, 'name' => 'Product 1']);
        $product2 = factory(Product::class)->create(['price' => 12, 'name' => 'Product 2']);
        $product3 = factory(Product::class)->create(['price' => 18, 'name' => 'Product 3']);
        $bonus1 = factory(Product::class)->create(['price' => 10, 'name' => 'Bonus Box']);

        customer()->account->subscribe($this->subscription_monthly->sku)->create();

        customer()->account->refresh();

        $nextbox = customer()->account->nextBox();
        $nextbox->shopping_box->products()->save($product1);

        //if the product is equal to the product that will be replaced, it should return to original product
        $this->patchJson('/box/' . customer()->account->nextBox()->monthKey . '/exchange/' . $product1->id, [
            'product' => $product1->id,
        ])->assertStatus(200);

        $this->assertCount(0, customer()->account->refresh()->nextBox()->getExchanges());

        //Exchange product should not be a subscription type product
        $this->patchJson('/box/' . customer()->account->nextBox()->monthKey . '/exchange/' . $product1->id, [
            'product' => $this->subscription_monthly->id,
        ])->assertStatus(422);

        //Exchange product should not be over 5 dollars
        $this->patchJson('/box/' . customer()->account->nextBox()->monthKey . '/exchange/' . $product1->id, [
            'product' => $product3->id,
        ])->assertStatus(422);

        $this->patchJson('/box/' . customer()->account->nextBox()->monthKey . '/exchange/' . $product1->id, [
            'product' => $product2->id,
        ])->assertStatus(200);

        $this->assertCount(1, customer()->account->refreshBox()->nextBox()->getExchanges());

        //BUILD

        $nextbox = customer()->account->nextBox();
        
        $nextbox->addBonus($bonus1->sku);

        $builder = $nextbox->getBuilder();

        $this->assertCount(2, $builder->getProducts());

        $order = $builder->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertDatabaseHas('order_items', ['product_id' => $product2->id, 'order_id' => $order->id, 'price' => 2]);
        $this->assertDatabaseHas('order_items', ['product_id' => $this->subscription_monthly->id, 'order_id' => $order->id]);
        $this->assertDatabaseHas('order_items', ['product_id' => $bonus1->id, 'order_id' => $order->id]);

        $this->assertCount(3, $order->order_items);

        $this->assertEquals(7, $order->total_price);
    }

    public function test_cart_can_add_bonus()
    {

        $this->signInAsCustomer();

        $bonus1 = factory(Product::class)->create(['price' => 10]);
        $bonus2 = factory(Product::class)->create(['price' => 10]);

        Cart::add($this->subscription_monthly);
        Cart::addBonus($bonus1->sku);
        Cart::addBonus($bonus2->sku);

        $this->assertCount(2, Cart::getBonus());

        $order = Cart::setCustomer(customer())->setData(['status' => ORDER::ORDER_PROCESSING])->build();

        $this->assertDatabaseHas('order_items', ['product_id' => $bonus1->id, 'order_id' => $order->id]);
        $this->assertDatabaseHas('order_items', ['product_id' => $bonus2->id, 'order_id' => $order->id]);

        $order = Order::first();

        $this->assertEquals(0, $order->total_discounts);

        $this->assertFalse(customer()->refresh()->canGetBonusBox());

        $this->assertEquals(5, Cart::getSubTotal());

        Cart::add($this->subscription_monthly)->setData(['status' => ORDER::ORDER_PROCESSING])->build();
        Cart::add($this->subscription_monthly)->setData(['status' => ORDER::ORDER_PROCESSING])->build();
        Cart::add($this->subscription_monthly)->setData(['status' => ORDER::ORDER_PROCESSING])->build();
        Cart::add($this->subscription_monthly)->setData(['status' => ORDER::ORDER_PROCESSING])->build();
        Cart::add($this->subscription_monthly)->setData(['status' => ORDER::ORDER_PROCESSING])->build();

        $this->assertTrue(customer()->refresh()->canGetBonusBox()); //Should be allowed to get Bonus box after 6

        Cart::add($this->subscription_monthly)->build();

        $this->assertFalse(customer()->refresh()->canGetBonusBox()); //7month

        $this->assertTrue(customer()->canGetBonusBox(19)); //Check if allowed with custom count
        $this->assertFalse(customer()->canGetBonusBox(17));

        //Apply adjustment
        customer()->forceFill(['bonus_adjustment' => 1])->save();
        customer()->refresh();
        $this->assertFalse(customer()->refresh()->canGetBonusBox());

    }

    public function test_free_shipping_products()
    {
        $product1 = factory(Product::class)->create(['price' => 10, 'sku' => 'SAMPLE']);
        $product2 = factory(Product::class)->create(['price' => 10, 'sku' => 'PCK-STARTER-3']);

        Cart::add($product1);
        $this->assertFalse(Cart::hasFreeShippingProduct());

        Cart::add($product2);
        $this->assertTrue(Cart::hasFreeShippingProduct());

        $this->assertEquals(0, Cart::getTotalShipping());
    }

    public function test_bonus_on_7th_month()
    {
        $this->signInAsCustomer();

        config(['app.disable_charge' => true]);

        $bonus = factory(Product::class)->create(['price' => 10, 'sku' => config('subscription.bonus_box')]);
        
        //First month
        Cart::add($this->subscription_monthly)->processBonus()->setData(['status' => ORDER::ORDER_COMPLETED])->build();
        $this->assertCount(2, OrderItem::all());

        //second month
        processBox(customer()->refresh()->account->nextBox());
        $this->assertCount(1, Order::latest()->first()->order_items);

        //third month
        processBox(customer()->refresh()->account->nextBox());
        $this->assertCount(1, Order::latest()->first()->order_items);

        //fourth month
        processBox(customer()->refresh()->account->nextBox());
        $this->assertCount(1, Order::latest()->first()->order_items);

        //fifth month
        processBox(customer()->refresh()->account->nextBox());
        $this->assertCount(1, Order::latest()->first()->order_items);

        //sixth month
        processBox(customer()->refresh()->account->nextBox());
        $this->assertCount(1, Order::latest()->first()->order_items);

        //seventh month
        processBox(customer()->refresh()->account->nextBox());
        $this->assertCount(2, Order::latest()->first()->order_items);
    }

    function test_free_oil()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product3 = factory(Product::class)->create(['price' => 300]);

        $product3->setMeta(['free_selectable' => 1]);
        $product3->save();

        $discount = \App\Shop\Discounts\Discount::create([
            'active' => 1,
            'code' => 'wholesale10',
            'type' => 'free_product_plus_discount',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        Cart::applyDiscount($discount);

        $this->post('/cart/free-oil/'.$product1->id);

        $this->assertEquals(0, Cart::getDiscountTotal());
        $this->assertCount(0, Cart::getProducts());

        $this->post('/cart/free-oil/'.$product3->id);

        $this->assertEquals(300, Cart::getDiscountTotal());
        $this->assertCount(1, Cart::getProducts());

    }

    function test_free_oil_as_query()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product2 = factory(Product::class)->create(['price' => 300]);

        $product2->setMeta(['free_selectable' => 1]);
        $product2->save();

        $discount = \App\Shop\Discounts\Discount::create([
            'active' => 1,
            'code' => 'wholesale10',
            'type' => 'free_product_plus_discount',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        $this->get('/cart/'.$product1->id.'/add?freeoil='.$product2->id);
        Cart::applyDiscount($discount);
        $this->assertEquals(300, Cart::getDiscountTotal());
        $this->assertCount(2, Cart::getProducts());

        $this->assertEquals(500, Cart::getGrandTotal());

    }
}
