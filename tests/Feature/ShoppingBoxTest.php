<?php

namespace Tests\Feature;

use App\Jobs\ProcessShoppingBox;
use App\Mail\OrderProcessed;
use App\Shop\Customers\Account;
use App\Shop\Customers\Customer;
use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use App\Shop\ShoppingBoxes\ShoppingBox;
use App\Shop\ShoppingBoxes\ShoppingBoxBuilder;
use App\Shop\Subscriptions\Subscription;
use App\Shop\Subscriptions\SubscriptionDiscount;
use Facades\App\Shop\Subscriptions\FutureOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use App\Shop\Discounts\Discount;
use App\Jobs\BuildShoppingBox;
use Facades\App\Shop\Cart\Cart;
use App\Shop\Reports\MonthlyBox;
use App\Shop\Subscriptions\SubscriptionPause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Events\Subscribed;
use App\Exceptions\PaymentException;
use Mockery;

class ShoppingBoxTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_monthly;

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

        set_option('settings_wholesale', ['wholesale_minimum_order' => 100, 'wholesale_shipping_total' => 20]);
    }

    public function test_if_shopping_box_can_get_by_key()
    {
        $shopping_box = factory(ShoppingBox::class)->create();

        $this->assertEquals($shopping_box->id, ShoppingBox::getByKey($shopping_box->key)->id);
    }

    public function test_future_orders()
    {

        $now = Carbon::now();
        $account = factory(Account::class)->create();
        $account->subscribe($this->subscription_monthly->sku)->setSchedule(23)->create();

        $this->assertTrue($account->subscribed());
        $future_orders = $account->futureOrders();
        $this->assertNotNull($future_orders);
        $this->assertEquals(20, $future_orders->getMonths()->first()->date->format('d'));
        $this->assertNotNull(
            $future_orders->getMonth(
                $now->modify('first day of next month')->format('F Y')
            )
        );

        //Route

        $this->get(route('profile'))->assertRedirect(route('customer.login'));

        $this->signInAsCustomer($account);
        $this->getJson(route('profile'))->assertStatus(200);

        //Skipping
        $account->subscription->skipMonth($now->format('F Y'));
        $account->subscription->refresh();
        FutureOrders::setSubscription($account->subscription);

        $this->assertTrue($account->futureOrders()->getMonth($now->format('F Y'))->skipped());

        //Check if we are getting the shoppingbox when getting the month;
        $this->assertEquals(
            ShoppingBox::getByKey($now->format('F Y'))->month_key,
            $account->futureOrders()->getMonth($now->format('F Y'))->shopping_box->month_key
        );
    }

    public function test_can_process_boxjob()
    {
        $this->signInAsCustomer();

        Mail::fake();

        customer()->saveDefaultAddress([
            'first_name' => customer()->first_name,
            'last_name' => customer()->last_name,
            'address1' => 'test address1',
            'city' => 'test city',
            'zip' => 222,
            'region' => 'test region',
        ]);

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->assertTrue(customer()->account->subscribed());

        customer()->account->updateOrCreateCard('fake-valid-nonce');

        $nextBox = customer()->account->nextBox();
        $box_key = $nextBox->month_key;    
        ProcessShoppingBox::dispatch($nextBox);

        $this->assertDatabaseHas('orders', [
            'email' => customer()->email,
            'status' => Order::ORDER_PROCESSING,
            'box_key' => $box_key
        ]);

        $this->assertDatabaseHas('shipping_addresses', [
            'first_name' => customer()->first_name,
            'last_name' => customer()->last_name,
            'address1' => 'test address1',
            'city' => 'test city',
            'zip' => 222,
            'region' => 'test region',
        ]);

        //Check if Monthly box item name has the box key
        $this->assertDatabaseHas('order_items', [
            'name' => $this->subscription_monthly->name.":".$box_key 
        ]);

        Mail::assertQueued(OrderProcessed::class);
    }

    public function test_it_sets_the_correct_schedule_on_reruns()
    {
        $this->signInAsCustomer();

        Mail::fake();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(21)
            ->create();

        $now = Carbon::now();
        $mock_date = Carbon::now()->subdays(5);

        Carbon::setTestNow($mock_date);

        $subscription = customer()->account->subscription;
        $subscription->quantity = 2;
        $subscription->save();

        $process = Mockery::mock(ProcessShoppingBox::class, [customer()->account->nextBox(), $now])
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
        $process->shouldReceive('chargeAttempt')->andThrow(new PaymentException);

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($process);

        $this->assertTrue(customer()->refresh()->account->subscription->failed_attempts > 0);
    }

    public function test_subscription_quantity()
    {
        $this->signInAsCustomer();

        Mail::fake();

        Queue::fake();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(21)
            ->create();

        $subscription = customer()->account->subscription;
        $subscription->quantity = 2;
        $subscription->save();

        $order = (new ShoppingBoxBuilder(customer()->account->nextBox()))->build();

        $this->assertEquals($order->order_items->first()->quantity, 2);
    }

    public function test_subscription_has_correct_shipping_for_wholesalers()
    {
        $this->signInAsCustomer();

        Mail::fake();

        Queue::fake();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(21)
            ->create();

        customer()->tags()->create(['name' => 'wholesale']);

        $this->assertTrue(customer()->refresh()->isWholesaler());

        $subscription = customer()->account->subscription;
        $subscription->quantity = 2;
        $subscription->save();

        $this->assertEquals(0, customer()->account->nextBox()->getBuilder()->getTotalShipping());
        
    }

    public function test_subscription_has_correct_shipping_for_wholesalers_on_checkout()
    {
        $this->signInAsCustomer();

        Mail::fake();

        Queue::fake();

        customer()->tags()->create(['name' => 'wholesale']);

        $this->assertTrue(customer()->refresh()->isWholesaler());

        Cart::add($this->subscription_monthly);

        $this->assertEquals(0, Cart::getTotalShipping());
        
    }
    
    public function test_it_can_add_discounts()
    {
        $account = factory(Account::class)->create();
        $account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(1)
            ->create();

        //Apply subscription Discount

        $account->subscription->subscriptionDiscounts()->create(['amount' => 20, 'type' => 'fixed']);
       
        $this->assertEquals(20, $account->nextBox()->getBuilder()->getDiscountTotal());

        //Apply Normal Discount

        $discount = Discount::create([
            'code' => 'aaa',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 25,
            ],
        ]);
        
        $account->nextBox()->applyDiscount($discount->code);

        $this->assertDatabaseHas('subscription_normal_discounts', ['subscription_id' => $account->subscription->id, 'code' => $discount->code]);

        //It should not work on non addons product
        $this->assertEquals(20,
            $account->nextBox()->getBuilder()->getDiscountTotal());

        

        //added addons   
        $addon = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $addon2 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        $account->nextBox()->updateAddons($addon, 1);
        $account->nextBox()->updateAddons($addon2, 1);

        $this->assertCount(2, $account->nextBox()->getAddons());

        //Discount should work on addons
        $this->assertEquals(45,
            $account->nextBox()->getBuilder()->getDiscountTotal());

        //Replace discount
        $discount2 = Discount::create([
            'code' => 'bbb',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 50,
            ],
        ]);    

        $account->nextBox()->applyDiscount($discount2->code);
        $this->assertCount(1, $account->refresh()->subscription->subscriptionNormalDiscounts);
        $this->assertEquals(70, $account->nextBox()->getBuilder()->getDiscountTotal());

        //Delete Discount

        $account->nextBox()->deleteDiscount();
        
        $this->assertDatabaseMissing('subscription_normal_discounts', ['subscription_id' => $account->subscription->id]);

        //Building the box will delete the discount
        $account->nextBox()->applyDiscount($discount2->code);

        Event::fake(Subscribed::class);

        $account->nextBox()->getBuilder()->build();

        Event::assertNotDispatched(Subscribed::class);
 
        $this->assertDatabaseMissing('subscription_normal_discounts', ['subscription_id' => $account->subscription->id]);

        //Applied discount should not expire
        $discount3= Discount::create([
            'code' => 'expiring123',
            'type' => 'percentage',
            'end_date' => Carbon::now()->addDays(1),
            'options' => [
                'discount_value' => 19,
            ],
        ]);    

        $account->nextBox()->deleteDiscount();

        $account->nextBox()->applyDiscount($discount3->code);    

        Carbon::setTestNow(Carbon::now()->addDays(5));
        

        $this->assertEquals(0, $account->nextBox()->getBuilder()->getGrandTotal());

    }

    /** @test */
    public function it_reports_monthly_data()
    {

        factory(Subscription::class, 10)->create(['plan' => $this->subscription_monthly->sku]);

        $subscription1 = factory(Subscription::class)->create(['plan' => $this->subscription_monthly->sku]);
        $subscription2 = factory(Subscription::class)->create(['plan' => $this->subscription_monthly->sku]);
        $subscription3 = factory(Subscription::class)->create(['plan' => $this->subscription_monthly->sku]);

        $now = Carbon::now();

        $subscription1->skipMonth($now->copy()->addMonths(2)->format('F Y'));
        $subscription2->skipMonth($now->copy()->addMonths(2)->format('F Y'));
        

        config(['app.disable_charge' => true]);

        Mail::fake();
        
        $order = (new ShoppingBoxBuilder($subscription3->owner->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertCount(12, (new MonthlyBox($now->copy()->addMonths(1)->format('F Y')))->remaining_boxes()->get());
        $this->assertCount(11, (new MonthlyBox($now->copy()->addMonths(2)->format('F Y')))->remaining_boxes()->get());
        $this->assertCount(13, (new MonthlyBox($now->copy()->addMonths(3)->format('F Y')))->remaining_boxes()->get());

    }

    function test_shopping_box_expiry()
    {
        $shopping_box = factory(ShoppingBox::class)->create();
        $this->assertTrue($shopping_box->available());
        $shopping_box->forceFill(['stock_expiry' => Carbon::parse('yesterday')])->save();
        $this->assertFalse($shopping_box->available());
        $shopping_box->forceFill(['stock_expiry' => Carbon::parse('tomorrow')])->save();
        $this->assertTrue($shopping_box->available());
    }
}
