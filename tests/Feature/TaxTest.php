<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Orders\Order;
use Illuminate\Support\Carbon;
use App\Events\OrderCreated;
use Illuminate\Support\Facades\Queue;
use App\Jobs\OrderTax;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    protected $order;

    public function setUp()
    {
        parent::setUp();

        $this->order = Order::forceCreate([
            'email' => 'email@test.com',
            'total_price' => 100,
            'total_tax' => 2,
            'processed_at' => Carbon::now()
        ]);

        $this->order->shipping_address()->create([
            'first_name' => 'John Doe',
            'address1' => 'Kamagong RD Uptown',
            'city' => 'Tagbilaran',
            'zip' => '6300',
            'country' => 'PH',
            'region' => 'Bohol',
        ]);
    }
    
    /** @test */
    function it_creates_order_tax_after_order_has_been_created()
    {
        $this->signInAsCustomer();

        $this->order->customer_id = customer()->id;
        $this->order->save();

        Queue::fake();

        config(['app.disable_order_tax' => false]);

        event(new OrderCreated($this->order));

        Queue::assertPushed(OrderTax::class, function ($job) {
            return $job->order->id === $this->order->id && $job->mode == 'new';
        });
    }
}
