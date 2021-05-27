<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Referrals\Reward;
use App\Shop\Products\Product;
use Illuminate\Support\Carbon;
use App\Shop\ShoppingBoxes\ShoppingBox;
use Illuminate\Support\Facades\Queue;
use App\Jobs\KlaviyoTrack;

class RewardTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_mothly;

    public function setUp()
    {
        parent::setUp();

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

    /** @test */
    function it_can_claim_free_box_reward()
    {

        Queue::fake();
        
        $this->signInAsCustomer();

        customer()->reward('freebox');

        $this->assertDatabaseHas('rewards', [
            'type' => 'freebox',
            'customer_id' => customer()->id
        ]);

        Queue::assertPushed(KlaviyoTrack::class, function($job){
            return $job->getEvent()=='reward';
        });
        
        //Subscribed user can claim the freebox    
        customer()->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $this->assertTrue(customer()->account->nextBox()->isFree());

        $this->assertEquals(0, customer()->account->nextBox()->getBuilder()->getGrandTotal());

        customer()->account->nextBox()->getBuilder()->build();

        $this->assertDatabaseHas('orders', [
            'total_price' => 0,
            'email' => customer()->email
        ]);

        $this->assertEquals(5, customer()->refresh()->account->nextBox()->getBuilder()->getGrandTotal());
    }
}
