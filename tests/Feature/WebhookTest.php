<?php

namespace Tests\Feature;

use App\Jobs\WebhookPushAll;
use App\Shop\Orders\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Shop\Products\Product;
use App\Shop\ShoppingBoxes\ShoppingBox;
use Carbon\Carbon as CarbonCarbon;
use Illuminate\Support\Facades\Queue;
use Facades\App\Shop\Cart\Cart;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_monthly;

    public function setUp()
    {
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY_SKU', 'subscription-monthly'), 'price' => 5]
        );

        $shop_date = Carbon::parse('first day of last month');

        for ($i = 0; $i < 13; ++$i) {
            factory(ShoppingBox::class)->create([
                'name' => $shop_date->format('F Y'),
                'stock' => 999,
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }
    }

    /**
     * A basic test example.
     */
    public function test_webhook_subscribe()
    {
        $this->postJson('/api/subscribe', ['event' => 'customer.subscribed', 'target_url' => 'http://zapier/something'])->assertStatus(401);

        //webhook subscribe
        $this->signInAsSuperAdmin();

        $this->postJson('/api/subscribe', ['event' => 'customer.subscribed', 'target_url' => 'http://zapier/something'])->assertStatus(200);

        $this->assertDatabaseHas('webhooks', ['event' => 'customer.subscribed', 'url' => 'http://zapier/something']);

        //webhook unsubscribe
        $this->deleteJson('/api/unsubscribe/1')->assertStatus(200);
        $this->assertDatabaseMissing('webhooks', ['event' => 'customer.subscribed', 'url' => 'http://zapier/something']);

        //subscribe
        $this->signInAsCustomer();

        Queue::fake([WebhookPushAll::class]);

        Cart::add($this->subscription_monthly)->setData(['status' => Order::ORDER_COMPLETED])->build();

        Queue::assertPushed(WebhookPushAll::class, function ($job) {
            return $job->event == 'customer.subscribed' && $job->data['email'] == customer()->email;
        });

        customer()->account->subscription->skipMonth(Carbon::now()->addMonth(2)->format('F Y'));
        
        Queue::assertPushed(WebhookPushAll::class, function ($job) {
            return $job->event == 'customer.skips_month' && $job->data['email'] == customer()->email;
        });

        //cancel
        customer()->account->subscription->stop();
        Queue::assertPushed(WebhookPushAll::class, function ($job) {
            return $job->event == 'customer.canceled' && $job->data['email'] == customer()->email;
        });

        customer()->account->subscription->resume();
        Queue::assertPushed(WebhookPushAll::class, function ($job) {
            return $job->event == 'customer.restarts_subscription' && $job->data['email'] == customer()->email;
        });
    }
}
