<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\Subscribed;
use App\Exceptions\PaymentException;
use App\Mail\AccountManagement;
use App\Shop\Customers\Account;
use App\Shop\Customers\Customer;
use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use App\Shop\Shipping\ShippingZone;
use App\Shop\Subscriptions\Subscription;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use App\Shop\ShoppingBoxes\ShoppingBoxBuilder;
use App\Shop\ShoppingBoxes\ShoppingBox;
use Illuminate\Support\Facades\Queue;
use App\Jobs\BuildShoppingBox;
use App\Jobs\ProcessShoppingBox;
use App\Shop\Orders\InstallmentBuilder;
use App\Shop\Subscriptions\SubscriptionPause;
use App\Shop\Discounts\Discount;
use App\Shop\Subscriptions\SubscriptionAddons;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use App\BoxRunReport;
use App\BoxRunSummary;
use App\Mail\MailSubscriptionPaused;
use Illuminate\Support\Facades\Event;

class SubscriptionTest extends TestCase
{

    use RefreshDatabase;

    protected $non_subscription;

    protected $subscription_monthly;

    protected $subscription_monthly_new;

    protected $subscription_3_months;

    protected $shipping_zone;

    public function setUp()
    {
        parent::setUp();

        $this->non_subscription = factory(Product::class)->create(
            ['type' => 'default', 'sku' => 'non-subscription', 'price' => 5]
        );
        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY_SKU', 'subscription-monthly'), 'price' => 5]
        );

        $this->subscription_monthly_new = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY2019_SKU', 'REC-MONTHLY2019'), 'price' => 5]
        );

        $this->subscription_3_months = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => 'subscription-3-months', 'price' => 5]
        );

        $this->subscription_3_months->setMeta(['subscription_months' => 3]);
        $this->subscription_3_months->save();

        factory(Product::class)->create(
            ['type' => 'default', 'sku' => config('subscription.bonus_box') , 'price' => 20]
        );

        $this->shipping_zone = ShippingZone::create(
            [
                'name' => 'Test 2',
                'countries' => 'US']
        );

        $this->shipping_zone->shipping_rate_prices()->create([
            'name' => 'Regular',
            'min' => 0,
            'is_free' => 0,
            'rate' => 15,
        ]);

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

    protected function customerSubscribe()
    {        
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();
    }

    public function test_subscription_can_be_created()
    {

        Mail::fake();
        Notification::fake();

        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $account = customer()->account;

        $this->assertTrue($account->subscribed());
        $this->assertEquals($this->subscription_monthly->sku, $account->subscription->plan);
        $this->assertTrue($account->subscription->active());
        $this->assertFalse($account->subscription->stopped());
        // $this->assertEquals(min(28, Carbon::now()->format('d')), $account->subscription->schedule);

        $subscription = $account->subscription;
        // Cancel Subscription
        $subscription->stop()->save();
        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->stopped());

        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();
        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->stopped());

        // Resume Subscription
        $subscription->resume();

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->stopped());

        //Test subscribing will resume the subscription
        $account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(15)
            ->create();

        $account->refresh();

        $this->assertTrue($account->subscribed());
        $this->assertEquals($this->subscription_monthly->sku, $account->subscription->plan);
        $this->assertTrue($account->subscription->active());
        $this->assertFalse($account->subscription->stopped());
        $this->assertEquals(15, $account->subscription->schedule);
    }

    public function test_subscription_frontend()
    {

        Mail::fake();
        Notification::fake();

        $this->getJson('/subscribe')->assertStatus(422);

        $this->getJson('/subscribe', ['plan' => 'random'])->assertStatus(422);
        //$this->getJson('/subscribe?plan=' . $this->non_subscription->sku)->assertStatus(404);
        $this->getJson('/subscribe?plan=' . $this->subscription_monthly->sku)->assertRedirect(route('checkout'));

        $this->assertCount(1, Cart::getProducts());

        $this->assertEquals($this->subscription_monthly->id, Cart::getProducts()->first()->id);

        $this->expectsEvents([Subscribed::class, OrderCreated::class]);
        //Checkout

        $box_key = Cart::getSubscriptionBoxKey();

        $this->postJson('checkout', [
            'nonce' => 'fake-valid-nonce',
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
                'shipping_method' => $this->shipping_zone->shipping_rate_prices->first(),
            ],
        ])->assertStatus(200);

        $customer = Customer::where('email', 'mharkrollen@gmail.com')->first();

        $this->assertEquals([
            'John',
            'Doe',
        ], [
            $customer->first_name,
            $customer->last_name,
        ]);

        $this->assertNotNull($customer->account);
        $this->assertNotNull($customer->account->braintree_id);
        $this->assertNotNull($customer->account->subscription);

        $this->assertDatabaseHas('orders', [
            'email' => 'mharkrollen@gmail.com',
            'box_key' => $box_key,
        ]);

        //Check if Monthly box item name has the box key
        $this->assertDatabaseHas('order_items', [
            'name' => $this->subscription_monthly->name.":".$box_key 
        ]);

        $customer->refresh();

        $order = Order::first();

        $this->assertNotNull($customer->account->subscription);
        $this->assertTrue($customer->account->subscribed());
        $this->assertTrue($order->isSubscriptionPurchase());
        $this->assertCount(2, $order->order_items);

        $shopping_box = ShoppingBox::getByKey($box_key);

        //Test the shoppingbox stock is deducted
        $this->assertEquals(998, $shopping_box->stock);
        Mail::assertSent(AccountManagement::class);

        //Order again
        $order = Cart::add($this->subscription_monthly)->build();
        $this->assertEquals(Carbon::now()->format('F Y'), $order->box_key);
        $this->assertEquals(997, $shopping_box->refresh()->stock);

        //Out of stock box should move to the next month;
        $shopping_box->update(['stock' => 0]);

        $order = Cart::add($this->subscription_monthly)->build();
        $shopping_box = ShoppingBox::getByKey($order->box_key);
        $this->assertEquals(Carbon::parse('first day of next month')->format('F Y'), $order->box_key);
        $this->assertEquals(998, $shopping_box->refresh()->stock);
        $this->assertEquals(1, $customer->refresh()->account->subscription->schedule);    

        

        //CBD test with braintree account. Customer should not be allowed to add CBD as addons, they will need to checkout first
        $cbd_product = factory(Product::class)->create(['price' => 3]);
        $category = \App\Shop\Categories\Category::create(['name' => 'CBD', 'slug' => 'cbd']);

        $category->products()->save($cbd_product);

        $this->assertTrue($cbd_product->refresh()->isCBD());

        $this->patchJson("/cart/{$cbd_product->id}/edit")->assertJsonMissing(['when_to_ship' => true])->assertSuccessful(); //The 

        $this->assertNotNull(Cart::getProducts());

        //If the user has a square account, allow addon
        $customer->account->chargeAndSaveSquare('cnon:card-nonce-ok', 1); //assign square token
        $this->assertTrue(customer()->refresh()->account->hasSquareId());
        $this->patchJson("/cart/{$cbd_product->id}/edit")->assertJson(['when_to_ship' => true])->assertSuccessful(); //The    
    }

    public function test_customer_can_skip_or_resume_or_gift_a_month()
    {
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(15)
            ->create();

        //Skipping
        $nextBox = customer()->account->nextBox();

        $this->assertFalse($nextBox->skipped());

        $this->postJson('/subscription/' . $nextBox->monthKey . '/skip')->assertStatus(200);

        $box = customer()->account->subscription->refresh()->futureOrders()->getMonth($nextBox->monthKey);

        $this->assertTrue($box->skipped());
        $this->assertFalse($box->gifted());

        //Resume

        $this->postJson('/subscription/' . $nextBox->monthKey . '/resume')->assertStatus(200);
        $box = customer()->account->subscription->refresh()->futureOrders()->getMonth($nextBox->monthKey);

        $this->assertFalse($box->skipped());
        $this->assertFalse($box->gifted());

        //Gift
        $this->postJson('/subscription/' . $nextBox->monthKey . '/gift', [
            'address_address1' => "dsaf",
            'address_address2' => "dsaf",
            'address_city' => "dsfdsa",
            'address_company' => "fdsf",
            'address_country' => "US",
            'address_first_name' => "fdasf",
            'address_last_name' => "sdafds",
            'address_phone' => null,
            'address_region' => "AR",
            'address_zip' => "fdsaf",
            'contact_by_email' => false,
            'email' => "gfdsg@gmail.com",
            'email_date' => "2018-04-06T16:00:00.000Z",
            'first_name' => "gfsd",
            'gift_date' => "2018-06-01T12:00:00.000Z",
            'last_name' => "gfds",
            'message' => "rwrewrewgfdgfdgfdgfd",
            'step' => 3,
        ])->assertStatus(200);

        $box = customer()->account->subscription->futureOrders()->refresh()->getMonth($nextBox->monthKey);

        $this->assertDatabaseHas('subscription_gifts', [
            'address_address1' => "dsaf",
            'address_address2' => "dsaf",
            'address_city' => "dsfdsa",
            'address_company' => "fdsf",
            'address_country' => "US",
            'address_first_name' => "fdasf",
            'address_last_name' => "sdafds",
            'address_phone' => null,
            'address_region' => "AR",
            'address_zip' => "fdsaf",
            'contact_by_email' => false,
            'email' => "gfdsg@gmail.com",
            'first_name' => "gfsd",
            'last_name' => "gfds",
            'message' => "rwrewrewgfdgfdgfdgfd",
        ]);

        $this->assertFalse($box->skipped());
        $this->assertTrue($box->gifted());

        //Cancel Gift
        $this->deleteJson('/subscription/' . $nextBox->monthKey . '/gift')->assertStatus(200);
        $box = customer()->account->subscription->futureOrders()->refresh()->getMonth($nextBox->monthKey);
        $this->assertFalse($box->gifted());
    }

    public function test_it_manage_subscriptions()
    {
        $today = Carbon::today();
        Carbon::setTestNow(Carbon::create($today->format('Y'), 4, 26, 12));
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        //Stop

        $this->postJson('/subscription/stop')->assertStatus(200);

        $this->assertTrue(customer()->account->refresh()->cancelled());

        //resume
        $this->postJson('/subscription/resume')->assertStatus(200);

        customer()->account->subscription->refresh();

        $this->assertFalse(customer()->account->paused());
        $this->assertEquals(20, customer()->account->subscription->schedule);

        //Schedule
        $this->patchJson('/subscription/schedule', ['schedule' => 3])->assertStatus(200);
        $this->assertEquals(3, customer()->account->subscription->schedule);

        //change Plan
        //$this->patchJson('/subscription/plan', ['plan' => $this->subscription_3_months->sku])->assertStatus(200);
        //$this->assertEquals($this->subscription_3_months->sku, customer()->account->subscription->plan);
    }

    public function test_it_sets_consolidate_schedule_1()
    {
        Carbon::setTestNow(Carbon::create(2018, 4, 26, 12));
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->assertEquals(20, customer()->account->subscription->schedule);
    }

    public function test_it_sets_consolidate_schedule_other_dates()
    {
        Carbon::setTestNow(Carbon::create(2018, 4, 14, 12));
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->assertEquals(14, customer()->account->subscription->schedule);
    }

    public function test_it_sets_consolidate_schedule_16()
    {
        Carbon::setTestNow(Carbon::create(2018, 4, 27, 12));
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->assertEquals(20, customer()->account->subscription->schedule);
    }

    public function test_skip_continue()
    {
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $months = customer()->account->futureOrders()->getMonths();

        $this->postJson('/subscription/nextbox-continue', [
            'key' => $months[3]->monthKey,
        ])->assertStatus(200);

        $months = customer()->account->futureOrders()->refresh()->getMonths();

        $this->assertTrue($months[0]->skipped());
        $this->assertTrue($months[1]->skipped());
        $this->assertTrue($months[2]->skipped());
        $this->assertFalse($months[3]->skipped());
    }

    public function test_can_get_all_subscribed_users()
    {

        $account1 = factory(Account::class)->create();

        $account2 = factory(Account::class)->create();

        $account3 = factory(Account::class)->create();

        $account4 = factory(Account::class)->create();

        $account1->subscribe($this->subscription_monthly->sku)
            ->create();

        $account2->subscribe($this->subscription_monthly->sku)
            ->create();

        $account2->subscription->stop();

        $account3->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->assertCount(2, Account::subscribedUsers()->get());
    }

    function test_user_box_count_by_orders()
    {
        $this->signInAsCustomer();

        account()->subscribe($this->subscription_monthly->sku)->create();

        $this->assertNotNull(account()->nextBox());

        $order = (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertNotNull($order->order_name);

        $this->assertEquals(1, account()->customer->subscriptionOrders()->count());
    }

    function test_wholesaler_tags_will_not_be_removed_after_subscription_build()
    {
        $this->signInAsCustomer();

        customer()->makeWholesaler();

        $this->assertTrue(customer()->isWholesaler());

        account()->subscribe($this->subscription_monthly->sku)->create();

        //added addons   
        $addon = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);
        $addon2 = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        account()->nextBox()->updateAddons($addon, 1);
        account()->nextBox()->updateAddons($addon2, 1);
   

        $order = (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertTrue(customer()->refresh()->isWholesaler());
    }

    

    public function test_update_payment_method_auto_attempt_charge_customers_with_failed_subscription_charge()
    {
        $this->signInAsCustomer();

        Queue::fake();

        //With Subscription

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        //Checks existing email
        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        Queue::assertNotPushed(ProcessShoppingBox::class); // Test it will try to attempt charge if theres no fail

        customer()->account->subscription->failed_at = Carbon::now()->subDays(5);

        //Checks existing email
        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        Queue::assertPushed(BuildShoppingBox::class); //It should process failed charge
    }

    public function test_update_payment_method_auto_attempt_charge_customers_with_failed_installment_charge()
    {
        $this->signInAsCustomer();

        $now = Carbon::now();

        $installment = customer()->installmentPlans()->create([
            'deposit' => 50,
            'account_id' => customer()->account->id,
            'plan' => 'dsa',
            'amount' => 30,
            'cycles' => (int) 5,
            'schedule' => 5,
            'next_schedule_date' => $now,
            'order_id' => 1111,
        ]);

        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        //Test the installment is not charged
        $this->assertDatabaseHas('installment_plans', [
            'account_id' => customer()->account->id,
            'paid_cycles' => 0,
            'next_schedule_date' => $now->format('Y-m-d'),
        ]);

        $installment->failed_attempts = 3;
        $installment->save();

        $query = customer()->installmentPlans();

        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        //Test the installment is not charged
        $this->assertDatabaseHas('installment_plans', [
            'account_id' => customer()->account->id,
            'paid_cycles' => 1,
            'failed_attempts' => 0,
        ]);        
    }

    function test_subscription_keep_month()
    {
        Carbon::setTestNow(Carbon::parse('2020-03-01')); //March 1

        $shop_date = Carbon::parse('first day of last month'); //April 1, 2020

        for ($i = 0; $i < 13; $i++) {
            ShoppingBox::updateOrCreate([
                'name' => $shop_date->format('F Y'),
                'stock' => 999,
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }
        
        $march_box = ShoppingBox::where('key', 'march-2020')->first();
        $march_box->forceFill(['stock' => 0])->save(); //March box are sold out
        $this->get('/?keep=1')->assertStatus(200); // "?keep=1" parameter should allow the customer to purchase the march box even if it's already sold out.
        $this->get('/subscribe?plan='.$this->subscription_monthly->sku);
        $this->assertEquals('March 2020', Cart::getSubscriptionBoxKey()); //March box
    }

    function test_quarterly_pause()
    {
        Carbon::setTestNow(Carbon::parse('2020-01-01'));

        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_3_months->sku)
            ->create();

        customer()->orders()->create()->forceFill([
            'subscription' => 'QUARTERLY BOX',
            'box_key' => 'JANUARY 2020',
            'status' => 'completed',
        ])->save();        

        Carbon::setTestNow(Carbon::parse('2020-03-01'));

        $this->get('/');


        $future_orders = customer()->refresh()->account->futureOrders()->refresh();

        $this->assertEquals('April 2020', $future_orders->getMonths()->first()->month_key);
        
        //test the pause
        customer()->refresh()->account->subscription->skipMonth('April 2020');
        $this->get('/');

        $this->assertEquals('July 2020', customer()->account->nextBox()->date->format('F Y'));
        
    }

    public function test_it_can_handle_discount_with_specific_products()
    {
        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $product = factory(Product::class)->create(['price' => 100]);

        $discount = Discount::create([
            'code' => 'specific',
            'type' => 'percentage',
            'options' => [
                'discount_value' => 10,
                'applies_to' => 'products',
                'products' => [$product->id]
            ],
        ]);

        //add addons
        $this->patchJson('/cart/' . $product->id . '/edit', [
            'ship_now' => false,
            'qty' => 1,
        ])->assertStatus(200);

        /** @var App\Shop\\Subscriptions\FutureOrderMonth */
        $nextBox = customer()->refresh()->account->nextBox();

        $this->assertDatabaseHas('subscription_addons', ['subscription_id' => customer()->account->subscription->id]);

        $this->assertEquals(1, $nextBox->getAddons()->count());

        //apply discount

        $this->postJson('/subscription/discount', [
            'code' => $discount->code,
        ])->assertStatus(200);

        $builder = customer()->account->refresh()->nextBox()->getBuilder();

        $this->assertNotNull($builder);

        $this->assertEquals(10, $builder->getDiscountTotal());
    }

    function test_failed_charge_and_expired_cards_customer_tags()
    {
        Queue::fake();
        Mail::fake();

        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(15)
            ->create();


        //Failed charge tags added on charge fail
        $process = Mockery::mock(ProcessShoppingBox::class, [customer()->account->nextBox()])
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
        $process->shouldReceive('chargeAttempt')->andThrow(new PaymentException);

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($process);

        $this->assertTrue(customer()->refresh()->hasTags('failed-charge'));

        //Failed charge tags removed on successful charge

        $process2 = Mockery::mock(ProcessShoppingBox::class, [customer()->account->nextBox()])
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
        $process2->shouldReceive('chargeAttempt');

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($process2);

        $this->assertFalse(customer()->refresh()->hasTags('failed-charge'));
        
        //Expired tags added on failed charge due to expired cards
        $process = Mockery::mock(ProcessShoppingBox::class, [customer()->account->nextBox()])
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
        $process->shouldReceive('chargeAttempt')->andThrow(new PaymentException('Expired', 2004));

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($process);

        $this->assertTrue(customer()->refresh()->hasTags(['failed-charge', 'has-expired-card']));

        //Expired tags should be removed upon payment method update
        customer()->assignTags(['has-expired-card']);

        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        customer()->refresh();

        $this->assertFalse(customer()->hasTags(['has-expired-tag']));        
    }

    public function test_it_can_pause_and_unpause_subscription()
    {
        $this->customerSubscribe();

        $box = Mockery::mock(ProcessShoppingBox::class, [customer()->account->nextBox()])
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
        $box->shouldReceive('chargeAttempt')->andThrow(new PaymentException());

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);

        $this->assertEquals(1, customer()->refresh()->account->subscription->failed_attempts);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);
        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($box);

        customer()->refresh();

        $this->assertFalse(customer()->account->subscription->stopped());
        $this->assertTrue(customer()->account->subscription->paused());

        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        customer()->refresh();

        $this->assertFalse(customer()->account->subscription->paused());
    }

    public function test_it_should_not_process_paused_subscription()
    {

        $this->customerSubscribe();

        Queue::fake();
        Mail::fake();

        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        customer()->account->subscription->pause();

        Mail::assertSent(MailSubscriptionPaused::class);

        config(['subscription.run_cron_job' => true]);

        Carbon::setTestNow(Carbon::parse(Carbon::now()->format('Y-m-' . customer()->account->subscription->schedule))->addMonth(1));

        Artisan::call('box:run');

        Queue::assertNotPushed(ProcessShoppingBox::class);
    }

    public function test_it_can_run_boxes()
    {
        //Queue::fake();
        Mail::fake();

        $today = Carbon::today();
        
        $account1 = factory(Account::class)->create();
        $account2 = factory(Account::class)->create();
        $account3 = factory(Account::class)->create();
        $account4 = factory(Account::class)->create();
        $account5 = factory(Account::class)->create();
        $account6 = factory(Account::class)->create();

        $account1->subscribe($this->subscription_monthly->sku)
            ->setSchedule(1)
            ->create();

        $account1->getBraintreeCustomer('fake-valid-nonce');

        $account2->subscribe($this->subscription_monthly->sku)
        ->setSchedule(1)
        ->create();

        $account2->getBraintreeCustomer('fake-valid-nonce');

        //Should process the box if the previous attempt was cancelled
        $account2->customer->orders()->save(factory(Order::class)->create([
            'status' => Order::ORDER_CANCELLED,
            'subscription' => 'Monthly Box',
            'box_key' => $today->copy()->addMonth(1)->format('F Y')
        ]));

        $account3->subscribe($this->subscription_monthly->sku)
        ->setSchedule(1)
        ->create();
        
        $account4->subscribe($this->subscription_monthly->sku)
        ->setSchedule(1)
        ->create();
        
        $account5->subscribe($this->subscription_monthly->sku)
        ->setSchedule(1)
        ->create();

        //This should be ignored since the schedule is tomorrow
        $account6->subscribe($this->subscription_monthly->sku)
        ->setSchedule(2)
        ->create();

        //ignore skip
        $account4->subscription->skipMonth($today->copy()->addMonth(1)->format('F Y'));

        //Ignore processing with already failed charge on that day
        $account5->subscription->forceFill(['failed_at' => $today->copy()->addMonth(1)->format('Y-m-1')])->save();

        Carbon::setTestNow(Carbon::parse($today->copy()->addMonth(1)->format('Y-m-1')));

        config(['subscription.run_cron_job' => true]);
        
        Artisan::call('box:run', ['schedule' => $today->copy()->addMonth(1)->format('Y-m-1')]);

        $this->assertCount(5, BoxRunReport::where('month_key', $today->copy()->addMonth(1)->format('F Y'))
        ->get());

        $this->assertCount(2, BoxRunReport::where('month_key', $today->copy()->addMonth(1)->format('F Y'))
        ->where('skipped', true)
        ->get());

        $this->assertCount(2, BoxRunReport::where('month_key', $today->copy()->addMonth(1)->format('F Y'))
        ->whereNotNull('processed_at')
        ->whereNotNull('order_name')
        ->get());
        
        $this->assertCount(1, BoxRunReport::where('month_key', $today->copy()->addMonth(1)->format('F Y'))
        ->whereNotNull('failed_reason')
        ->get());
    }

    public function test_can_process_box_with_successful_charge()
    {
        //Queue::fake();
        //Mail::fake();

        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(1)
            ->create();

        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        $nextBox = customer()->account->nextBox();

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow(new ProcessShoppingBox($nextBox));

        $this->assertDatabaseHas('orders', ['email' => customer()->email, 'box_key' => $nextBox->monthKey]);
    }

    public function test_does_not_process_box_with_failed_charge()
    {
        Queue::fake();
        Mail::fake();

        $this->signInAsCustomer();

        customer()->account->subscribe($this->subscription_monthly->sku)
            ->setSchedule(1)
            ->create();

        $nextBox = customer()->account->nextBox();

        $process = Mockery::mock(ProcessShoppingBox::class, [$nextBox])
        ->shouldAllowMockingProtectedMethods()
        ->makePartial();
        $process->shouldReceive('chargeAttempt')->andThrow(new PaymentException('Expired', 2004));

        app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchNow($process);

        $this->assertDatabaseMissing('orders', ['email' => customer()->email, 'box_key' => $nextBox->monthKey]);
    }
}
