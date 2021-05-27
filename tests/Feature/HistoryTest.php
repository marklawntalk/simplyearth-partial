<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\History\History;
use App\Shop\Products\Product;
use App\Shop\Customers\Account;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Auth;

class HistoryTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_monthly;

    public function setUp()
    {
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY_SKU', 'subscription-monthly'), 'price' => 5]
        );
    }

    public function test_subscription_can_be_created()
    {

        Mail::fake();
        Queue::fake();
        
        $this->signInAsCustomer();

        $subscription = customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $history = History::take(1)->latest()->first();
        $this->assertEquals('subscription_created', $history->type);
        $this->assertEquals('Customer subscribed to plan '.$this->subscription_monthly->sku, $history->summary);

        $subscription->stop();

        $history = History::orderBy('id', 'DESC')->first();

        $this->assertContains('Subscription canceled by customer', $history->summary);

        //RESUME

        $subscription->resume();

        $history = History::orderBy('id', 'DESC')->first();

        $this->assertEquals('Subscription resumed by customer', $history->summary);
    }
}
