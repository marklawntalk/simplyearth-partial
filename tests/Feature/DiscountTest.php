<?php

namespace Tests\Feature;

use App\Shop\Discounts\Discount;
use App\Shop\Products\Product;
use App\Shop\Shipping\ShippingZone;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use App\Shop\Categories\Category;
use App\Shop\Customers\Account;
use App\Shop\Orders\Order;
use App\Shop\Tags\Tag;
use App\Shop\ShoppingBoxes\ShoppingBox;
use App\Shop\Subscriptions\Subscription;
use App\Shop\Subscriptions\SubscriptionDiscount;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_monthly;

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

    public function test_admin_create_discount()
    {
        $this->signInAsAdmin();
        $this->postJson('/admin/discounts', [
            'code' => 'dsfysdi49327',
            'type' => 'percentage',
        ])->assertStatus(200);

        $this->assertDatabaseHas('discounts', ['type' => 'percentage']);

        $this->getJson('/admin/discounts/search')->assertStatus(200);
    }

    public function test_discount_percentage()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 100]);
        $product2 = factory(Product::class)->create(['price' => 40]);
        $product3 = factory(Product::class)->create(['price' => 40]);

        Discount::create([
            'code' => 'aaa',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
            ],
        ]);

        Discount::create([
            'code' => 'bbb',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 50,
            ],
        ]);

        Cart::add($product1);
        Cart::add($product2);

        $this->assertEquals(140, Cart::getTotal());

        //Percentage Discount
        Cart::applyDiscount('aaa');

        $this->assertEquals(105, Cart::getTotal());

        //Fixed Amount

        Cart::applyDiscount('bbb');

        $this->assertEquals(90, Cart::getTotal());

        //Minimum Items

        Discount::create([
            'code' => 'ccc',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 30,
                'minimum_requirement' => 'minimum_quantity_items',
                'minimum_quantity_items' => 3,
            ],
        ]);

        Cart::applyDiscount('ccc');

        $this->assertEquals(140, Cart::getTotal());

        Cart::add($product3);

        Cart::applyDiscount('ccc');

        $this->assertEquals(150, Cart::getTotal());

        //Minimum quantity

        Discount::create([
            'code' => 'ddd',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 30,
                'minimum_requirement' => 'minimum_purchase_amount',
                'minimum_purchase_amount' => 200,
            ],
        ]);

        Cart::applyDiscount('ddd');

        $this->assertEquals(180, Cart::getTotal());

        Cart::add($product3);

        Cart::applyDiscount('ddd');

        $this->assertEquals(190, Cart::getTotal());

        //Usage limits

        $usage_limit = Discount::create([
            'code' => 'eee',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 30,
                'usage_limits' => 1,
            ],
        ]);

        Cart::applyDiscount('eee');

        $this->assertEquals(190, Cart::getTotal());

        $usage_limit->used = 1;
        $usage_limit->save();

        Cart::applyDiscount('eee');

        $this->assertEquals(220, Cart::getTotal());

        //Active Date;

        //future
        Discount::create([
            'code' => 'fff',
            'type' => 'fixed_amount',
            'start_date' => Carbon::now()->addDays(2),
            'options' => [
                'discount_value' => 30,
            ],
        ]);

        Cart::applyDiscount('fff');

        $this->assertEquals(220, Cart::getTotal());

        //past

        Discount::create([
            'code' => 'ggg',
            'type' => 'fixed_amount',
            'end_date' => Carbon::now()->subDays(2),
            'options' => [
                'discount_value' => 30,
            ],
        ]);

        Cart::applyDiscount('ggg');

        $this->assertEquals(220, Cart::getTotal());

        //present

        Discount::create([
            'code' => 'hhh',
            'type' => 'fixed_amount',
            'start_date' => Carbon::now()->subDays(2),
            'options' => [
                'discount_value' => 30,
            ],
        ]);

        Cart::applyDiscount('hhh');

        $this->assertEquals(190, Cart::getTotal());

        Discount::create([
            'code' => 'iii',
            'type' => 'fixed_amount',
            'end_date' => Carbon::now()->addDays(2),
            'options' => [
                'discount_value' => 30,
            ],
        ]);

        Cart::applyDiscount('iii');

        $this->assertEquals(190, Cart::getTotal());
    }

    public function test_discount_applied()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 100]);

        $discont = Discount::create([
            'code' => 'aaa',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
                'per_ip_address' => true
            ],
        ]);

        Cart::add($product1);

        $this->post('/cart/promocode', [
            'code' => 'aaa',
        ])->assertStatus(200);

        $this->assertNotNull(Cart::getDiscount());
        $this->assertNotNull(session('cart.promocode'));
        $this->assertEquals(25, Cart::getDiscountTotal());

        $order = Cart::setCustomer(customer())->build();

        $this->assertNotFalse($order);
        $this->assertNotNull($order->discount_details);
        $this->assertEquals(75, $order->total_price);
        $this->assertEquals(1, $discont->refresh()->used);
    }

    public function test_discount_free_shipping()
    {
        $this->signInAsCustomer();

        $zone = ShippingZone::create([
            'name' => 'Test 2',
            'countries' => 'US',
        ]);

        $shipping_method = $zone->shipping_rate_prices()->create([
            'name' => 'Regular',
            'min' => 0,
            'is_free' => 0,
            'rate' => 15,
        ]);

        $discount = Discount::create([
            'code' => 'free-shipping',
            'type' => 'free_shipping',
            'options' => [],
        ]);

        $discount_free_shipping = Discount::create([
            'code' => 'another-free-shipping',
            'type' => 'percentage',
            'active' => true,
            'options' => [
                'discount_value' => 25,
                'free_shipping' => 1
            ],
        ]);

        $product1 = factory(Product::class)->create(['price' => 100, 'shipping' => 1]);

        Cart::add($product1)->setShippingMethod($shipping_method);

        $this->assertEquals(15, Cart::getTotalShipping());

        Cart::applyDiscount('free-shipping');

        $this->assertTrue(Cart::isFreeShipping());
        $this->assertEquals(0, Cart::getTotalShipping());

        $order = Cart::setCustomer(customer())->build();

        $this->assertEquals(0, $order->total_shipping);

        Cart::add($product1)->applyDiscount($discount_free_shipping);
        $this->assertEquals(0, Cart::getTotalShipping());

    }

    function test_dicount_free_addon()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 20, 'shipping' => 1, 'type' => 'subscription']);

        $addon = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $addon2 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        $discount = Discount::create([
            'code' => 'free-addon',
            'type' => 'free_addon',
            'options' => [
                'discount_value' => 25,
                'addons' => [$addon->id, $addon2->id]
            ],
        ]);

        Cart::add($product1, 2);

        Cart::add($product2);

        Cart::applyDiscount('free-addon');

        $this->assertNotNull(Cart::getDiscount());

        $this->assertEquals(0, Cart::getDiscountTotal());

        $this->assertCount(2, Cart::getFreeAddons());

        $order = Cart::setCustomer(customer())->build();

        $this->assertEquals(0, $order->total_discounts);

        $this->assertCount(4, $order->order_items);

        $this->assertDatabaseHas('order_items', ['product_id' => $addon->id, 'price' => $addon->price]);
    }

    function test_dicount_free_addon_on_subscription_box()
    {
        $product = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        $category = Category::create(['name' => 'Cat 1', 'slug' => 'catsanddogs']);
        $category->products()->save($product);

        $addon = factory(Product::class)->create(['price' => 30, 'shipping' => 1]);
        $addon2 = factory(Product::class)->create(['price' => 30, 'shipping' => 1]);

        $discount = Discount::create([
            'code' => 'free-addon',
            'type' => 'free_addon',
            'options' => [
                'discount_value' => 25,
                'addons' => [$addon->id, $addon2->id],
                'applies_to' => 'categories',
                'categories' => [$category->id]
            ],
        ]);

        //subscribe
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->patchJson('/cart/' . $product->id . '/edit', [
            'ship_now' => false,
            'qty' => 1,
        ])->assertStatus(200);

        $nextBox = customer()->refresh()->account->nextBox();

        $this->assertCount(1, $nextBox->getAddons());

        $this->postJson('/subscription/discount', [
            'code' => $discount->code,
        ])->assertStatus(200);

        $nextBox = customer()->refresh()->account->nextBox();
        
        $this->assertNotNull(89, $nextBox->getBuilder()->getGrandTotal());

        $this->assertCount(2, $nextBox->getBuilder()->getFreeAddons());


        

        $order = $nextBox->getBuilder()->build();

        $this->assertEquals(89, $order->total_price);

        $this->assertCount(4, $order->order_items);
    }

    function test_discount_applies_to()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);
        $product3 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);



        //Specific products

        Discount::create([
            'code' => 'specific',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
                'applies_to' => 'products',
                'products' => [$product2->id]
            ],
        ]);

        Discount::create([
            'code' => 'else',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
                'applies_to' => 'products',
                'products' => [$product3->id]
            ],
        ]);

        Cart::add($product1);
        Cart::add($product2);
        Cart::applyDiscount('else');

        $this->assertEquals(0, Cart::getDiscountTotal());

        Cart::applyDiscount('specific');

        $this->assertEquals(5, Cart::getDiscountTotal());

        Cart::add($product2);

        $this->assertEquals(10, Cart::getDiscountTotal());

        

        //Subscription products should be applicable to other subscription product

        $subscription_product = factory(Product::class)->create(['price' => 20, 'shipping' => 1, 'type' => 'subscription']);
        $commitment_product = factory(Product::class)->create(['price' => 20, 'shipping' => 1, 'type' => 'subscription', 'sku' => config('subscription.commitment_box')]);

        Cart::clear();

        Discount::create([
            'code' => 'subscription-code',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
                'applies_to' => 'products',
                'products' => [$subscription_product->id]
            ],
        ]);

        Cart::add($commitment_product);
        Cart::applyDiscount('subscription-code');

        $this->assertNotNull(Cart::getDiscount());

        Cart::clear();

        //Categories

        $category = Category::create(['name' => 'Cat 1', 'slug' => 'catsanddogs']);

        $category->products()->save($product1);

        Discount::create([
            'code' => 'catty',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
                'applies_to' => 'categories',
                'products' => [$product2->id],
                'categories' => [$category->id]
            ],
        ]);

        Cart::applyDiscount('catty')->remove($product1);

        $this->assertEquals(0, Cart::getDiscountTotal());

        Cart::add($product1);
        

        Cart::applyDiscount('catty');

        $this->assertEquals(12.5, Cart::getDiscountTotal());

        $this->assertFalse(Cart::isFreeShipping());

        //Fixed amount

        Discount::create([
            'code' => 'fixed-catty',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 100,
                'applies_to' => 'categories',
                'products' => [$product2->id],
                'categories' => [$category->id]
            ],
        ]);

        Cart::applyDiscount('fixed-catty');

        $this->assertEquals(50, Cart::getDiscountTotal());
    }

    function test_discount_limit_one_use_per_customer()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        $discount = Discount::create([
            'code' => 'per-customer',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 10,
                'per_customer' => true,
            ],
        ]);

        Cart::add($product1);

        Cart::applyDiscount('per-customer');

        $this->assertNotNull(Cart::getDiscount());
        $this->assertEquals(10, Cart::getDiscountTotal());

        $order = Cart::setCustomer(customer())->build();

        $this->assertNotFalse($order);
        
        $this->assertCount(1, $discount->users);

        Cart::add($product1);

        Cart::applyDiscount('per-customer');

        $this->assertNull(Cart::getDiscount());
        $this->assertEquals(0, Cart::getDiscountTotal());
    }


    function test_discount_customer_eligibility()
    {
        $this->signInAsCustomer();

        $tag = Tag::create(['name' => 'wholesale']);

        $product1 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        $discount = Discount::create([
            'code' => 'tags',
            'type' => 'fixed_amount',
            'options' => [
                'discount_value' => 10,
                'customer_eligibility' => 'specific_groups',
                'customer_tags' => ['wholesale']
            ],
        ]);

        Cart::add($product1);

        Cart::applyDiscount('tags');

        $this->assertNull(Cart::getDiscount());
        $this->assertEquals(0, Cart::getDiscountTotal());

        $tag->customers()->save(customer());

        Cart::applyDiscount('tags');

        $this->assertNotNull(Cart::getDiscount());
        $this->assertEquals(10, Cart::getDiscountTotal());
    }

    public function test_discount_with_addons()
    {

        $addon = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $addon2 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        $discount = Discount::create([
            'code' => 'withaddons',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 10,
                'addons' => [$addon->id, $addon2->id]
            ],
        ]);

        $product1 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        Cart::add($product1);

        Cart::applyDiscount('withaddons');

        $this->assertNotNull(Cart::getDiscount());

        $this->assertEquals(5, Cart::getDiscountTotal());

        $this->assertCount(2, Cart::getFreeAddons());


    }

    function test_free_first_box()
    {

        $this->signInAsCustomer();

        $zone = ShippingZone::create([
            'name' => 'Test 2',
            'countries' => '["US"]',
        ]);
        
        $shipping_method = $zone->shipping_rate_prices()->create([
            'name' => 'FREE',
            'min' => 0,
            'is_free' => 1,
            'rate' => 0,
        ]);

        $discount = Discount::create([
            'code' => 'free-shipping',
            'type' => 'free_shipping',
            'options' => [],
        ]);

        $product1 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 20, 'shipping' => 1, 'type' => 'subscription']);
        $product3 = factory(Product::class)->create(['price' => 20, 'shipping' => 1, 'type' => 'subscription', 'sku' => config('subscription.commitment_box')]);

        $discount = Discount::create([
            'code' => 'commitmentfreefirstmonth',
            'type' => 'freefirstmonth',
            'options' => [],
        ]);

        //Discount should be applicable to commitments only

        Cart::add($product1); //regular product

        Cart::applyDiscount('commitmentfreefirstmonth');

        $this->assertNull(Cart::getDiscount());

        Cart::add($product2); //subscription

        Cart::applyDiscount('commitmentfreefirstmonth');

        $this->assertNull(Cart::getDiscount());

        Cart::add($product3); //subscirption with commitments

        Cart::applyDiscount('commitmentfreefirstmonth');

        $this->assertNotNull(Cart::getDiscount());

        Cart::remove($product1)->remove($product2);

        //Subscription Box is FREE
        $this->assertEquals(0, Cart::getSubTotal());

        //Shipping is not FREE
        Cart::autoSetShipping();

        $this->assertEquals(9.99, Cart::getTotalShipping());


        //Only applicable to new subscribers

        customer()->account->subscribe($product3->sku)
            ->create();

        $account = customer()->account->refresh();

        $this->assertTrue($account->subscribed());

        Cart::clear();

        Cart::add($product3); 
        Cart::applyDiscount('commitmentfreefirstmonth');

        customer()->refresh();

        $this->assertNull(Cart::getDiscount());
    }

    function test_it_processes_wholesaler_discounts()
    {
        $this->signInAsWholesaler();

        $product = factory(Product::class)->create(['price' => 7.99, 'shipping' => 1, 'wholesale_price' => 4.40, 'wholesale_pricing' => true]);
        $product2 = factory(Product::class)->create(['price' => 11.99, 'shipping' => 1, 'wholesale_price' => 6.59, 'wholesale_pricing' => true]);

        $product->assignTags('wholesale');

        $discount = Discount::create([
            'code' => 'wholesale10',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 10,
                'applies_to' => 'products',
                'products' => [$product->id],
                'customer_eligibility' => 'specific_groups',
                'customer_tags' => ['wholesale']
            ],
        ]);

        Cart::add($product, 25);
        Cart::applyDiscount('wholesale10');

        $this->assertEquals(11, Cart::getDiscountTotal());

        Cart::add($product2, 50);

        $this->assertEquals(11, Cart::getDiscountTotal());

    }

    function test_subscription_box_discount()
    {
        $this->signInAsCustomer();

        $product = factory(Product::class)->create(['price' => 20]);
        $subscription_product = factory(Product::class)->create(['price' => 39, 'shipping' => 1, 'type' => 'subscription']);

        $discount = Discount::create([
            'code' => 'subscription_box',
            'active' => 1,
            'type' => 'subscription_box',
            'options' => [
                'discount_value_first_box' => 10,
                'discount_value_future_boxes' => 10,
                'number_of_future_boxes' => 4
            ],
        ]);

        $discount->forceFill(['active' => 1])->save();

        //It should not be applicable to non subscription product
        Cart::add($product)->applyDiscount($discount);

        $this->assertNull(Cart::getDiscount());

        //Applicable for subscription product
        Cart::clear()->add($subscription_product)->applyDiscount($discount);

        $this->assertNotNull(Cart::getDiscount());

        $this->assertEquals(10, Cart::getDiscountTotal());

        $this->assertEquals(29, Cart::getGrandTotal());

        Cart::build();

        $this->assertDatabaseHas('subscription_discounts', [
            'code' => $discount->code,
            'type' => 'fixed',
            'amount' => 10,
            'limit' => 4
        ]);
    }

    function test_subscription_discount_and_normal_discount_should_work_together()
    {
        $this->signInAsCustomer();

        $addon = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        customer()->account->subscription->subscriptionDiscounts()->create(['amount' => 20, 'type' => 'fixed']);

        $discount = Discount::create([
            'code' => 'withaddons',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        $this->patchJson('/cart/' . $addon->id . '/edit', [
            'ship_now' => false,
            'qty' => 1,
        ])->assertStatus(200);

        $this->postJson('/subscription/discount', [
            'code' => $discount->code,
        ])->assertStatus(200);

        $nextBox = customer()->refresh()->account->nextBox();

        $this->assertNotNull(25, $nextBox->getBuilder()->getDiscountTotal());

        $this->assertCount(1, $nextBox->getAddons());

    }

    function test_freeaddon_with_percentage_discount()
    {
        $this->signInAsCustomer();

        $product = factory(Product::class)->create(['price' => 7.99, 'shipping' => 1, 'wholesale_price' => 4.40, 'wholesale_pricing' => true]);
        $product2 = factory(Product::class)->create(['price' => 11.99, 'shipping' => 1, 'wholesale_price' => 6.59, 'wholesale_pricing' => true]);
        $category = Category::create(['name' => 'Oils', 'slug' => 'oils']);

        $category->products()->save($product);

        $discount = Discount::create([
            'active' => 1,
            'code' => 'wholesale10',
            'type' => 'free_product_plus_discount',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        Cart::applyDiscount($discount);
        Cart::addFreeOil($product);

        $this->assertEquals($product->price, Cart::getDiscountTotal());
        $this->assertEquals(0, Cart::getGrandTotal());    
    }

    function test_discount_campaigns()
    {
        $this->signInAsCustomer();

        $product = factory(Product::class)->create(['price' => 7.99, 'shipping' => 1, 'wholesale_price' => 4.40, 'wholesale_pricing' => true]);

        $discount = Discount::create([
            'active' => 1,
            'code' => 'wholesale10',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 10,
                'campaign' => 'freeoilcampaign',
            ],
        ]);

        Cart::applyDiscount($discount);

        Cart::build();

        $this->assertDatabaseHas('customer_discount', [
            'campaign' => 'freeoilcampaign',
            'ip_address' => '127.0.0.1',
        ]);
    }

    function test_referral_50()
    {

        Carbon::setTestNow(Carbon::parse('2020-01-02'));

        $shop_date = Carbon::parse('first day of last month');

        for ($i = 0; $i < 13; $i++) {
            ShoppingBox::updateOrCreate([
                'name' => $shop_date->format('F Y'),
                'stock' => 999,
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }

        $account = factory(Account::class)->create();
        $account->customer->prepareShareCode();


        $subscription_monthly = $this->subscription_monthly;

        $this->signInAsCustomer();

        Cart::add($subscription_monthly)->applyDiscount($account->customer->share_code)->setData(['status' => Order::ORDER_COMPLETED]);

        $this->assertEquals(10, Cart::getDiscountTotal());
        $this->assertEquals(29, Cart::getGrandTotal());

        Cart::build();
        
        $this->assertCount(4, SubscriptionDiscount::where([
            'subscription_id' => customer()->account->refresh()->subscription->id,
            'amount' => 10,
            'code' => 'REFERRAL50OFF',
            'type' => 'fixed',
            'unlimited' => 0,
            'limit' => 1,
        ])->get());

        $future_orders = customer()->account->futureOrders();

        $this->assertEquals(29, $future_orders->getMonth('February 2020')->getBuilder()->getGrandTotal());
        $this->assertEquals(29, $future_orders->getMonth('March 2020')->getBuilder()->getGrandTotal());
        $this->assertEquals(29, $future_orders->getMonth('April 2020')->getBuilder()->getGrandTotal());
        $this->assertEquals(29, $future_orders->getMonth('May 2020')->getBuilder()->getGrandTotal());
        $this->assertEquals(39, $future_orders->getMonth('June 2020')->getBuilder()->getGrandTotal());

        customer()->account->subscription->skipMonth('February 2020');
        $future_orders = customer()->account->futureOrders()->refresh();
        $this->assertTrue($future_orders->getMonth('February 2020')->skipped());
        $this->assertEquals(39, $future_orders->getMonth('June 2020')->getBuilder()->getGrandTotal());
    }

    function test_bogo()
    {
        $product = factory(Product::class)->create(['price' => 60, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $product3 = factory(Product::class)->create(['price' => 20, 'shipping' => 1]);

        $discount = Discount::create([
            'active' => 1,
            'code' => 'bogo',
            'type' => 'bogo',
            'options' => [
                'discount_value' => 50,
                'bogo_required' => [$product->id, $product2->id, $product3->id],
                'bogo' => [$product->id, $product2->id, $product3->id],
            ],
        ]);

        Cart::add($product, 1);
        Cart::add($product2, 2);
        Cart::add($product3, 3);

        Cart::applyDiscount('bogo');

        $this->assertEquals(45, Cart::getDiscountTotal());

        Cart::clear();

        Cart::add($product3, 2);
        Cart::add($product, 4);

        Cart::applyDiscount('bogo');    

        $this->assertEquals(70, Cart::getDiscountTotal());

        Cart::clear();

        Cart::add($product3, 10);

        Cart::applyDiscount('bogo');    

        $this->assertEquals(50, Cart::getDiscountTotal());

        Cart::clear();

        Cart::add($product, 2);

        Cart::applyDiscount('bogo');    

        $this->assertEquals(30, Cart::getDiscountTotal());

        Cart::clear();

        Cart::add($product, 3);

        Cart::applyDiscount('bogo');    

        $this->assertEquals(30, Cart::getDiscountTotal());
    }
}
