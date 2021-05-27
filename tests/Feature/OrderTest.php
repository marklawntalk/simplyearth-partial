<?php

namespace Tests\Unit;

use App\Shop\Customers\Customer;
use App\Shop\Orders\Order;
use App\Shop\Orders\OrderBuilder;
use App\Shop\Products\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use App\Events\OrderStatusUpdated;
use App\Shop\Orders\OrderRecalculator;
use Illuminate\Support\Facades\Queue;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_number_generated()
    {
        $order = factory(Order::class)->create(['id' => 1]);
        $this->assertEquals(''.$order->id, $order->order_number);
    }

    public function test_meta_fillable_filters_the_array()
    {
        $product1 = factory(Product::class)->create(['price' => 500]);
        $product2 = factory(Product::class)->create(['price' => 400]);

        $order = new OrderBuilder([$product1, $product2]);

        $this->assertEquals(900, $order->getSubTotal());
        $this->assertEquals(0, $order->getTaxTotal());
        $this->assertEquals(900, $order->getTotal());

        //Wisconsin Tax

        $customer = factory(Customer::class)->create(['email' => 'mharkrollen2@gmail.com']);

        $customer->saveDefaultAddress(['country' => 'US', 'region' => 'WI']);

        $order->setCustomer($customer)->setData([
            'checkout' => [
                'email' => 'checkoutemail@test.com',
                'shipping_address' => [
                    'first_name' => 'John Doe',
                    'address1' => 'Kamagong RD Uptown',
                    'city' => 'Tagbilaran',
                    'zip' => '6300',
                    'country' => 'US',
                    'region' => 'WI',
                ],
                'same_as_shipping_address' => true,
                'shipping_method_key' => '21212',
            ]
        ]);

        $customer->order_sessions()->create([
            
            'order_name' => 'YYYY',
            'order_details' => serialize($order),
            'summary' => [],
            'token' => '1212121',
        ]);

        $this->assertEquals(45, $order->getTaxTotal());

        //tax Exempt

        $customer2 = factory(Customer::class)->create(['email' => 'mharkrollen@gmail.com']);
        $customer2->tax_exempt = true;
        $customer2->save();

        $customer2->saveDefaultAddress(['country' => 'US', 'region' => 'WI']);

        $order->setCustomer($customer2);

        $this->assertEquals(0, $order->getTaxTotal());
    }

    public function test_order_status_changes()
    {
        Event::fake();

        Queue::fake();

        $customer = factory(Customer::class)->create();

        $product1 = factory(Product::class)->create(['price' => 500]);

        $order_builder = new OrderBuilder([$product1]);

        $order = $order_builder->setCustomer($customer)->build();

        $this->assertEquals(Order::ORDER_PENDING, $order->status);

        $order->markAsPaid();

        Event::assertDispatched(OrderStatusUpdated::class, function ($e) use ($order) {
            return $e->order->id === $order->id;
        });

        $this->assertEquals(Order::ORDER_PROCESSING, $order->status);

        $order->markAsCompleted();

        $this->assertNotNull($order->completed_at);

        Event::assertDispatched(OrderStatusUpdated::class, function ($e) use ($order) {
            return $e->order->id === $order->id;
        });

        $this->assertEquals(Order::ORDER_COMPLETED, $order->status);

        config(['app.disable_order_tax' => false]);

        Queue::fake();

        $order->cancelOrder();

        $this->assertEquals(Order::ORDER_CANCELLED, $order->refresh()->status);

        Queue::assertPushed(\App\Jobs\OrderTax::class, function ($job) use ($order) {
            return $job->order->id === $order->id && $job->mode == 'delete';
        });
    }

    public function test_order_recalculate()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 500, 'weight' => 0]);
        $product2 = factory(Product::class)->create(['price' => 400, 'weight' => 0]);
        $product3 = factory(Product::class)->create(['price' => 100, 'weight' => 1]);

        $order = (new OrderBuilder([$product1, $product2]))->setCustomer(customer())->build();

        $this->assertEquals(900, $order->total_price);

        $order->order_items()->create([
            'price' => $product3->price,
            'quantity' => 2,
            'sku' => $product3->sku,
            'name' => $product3->name,
            'weight' => $product3->weight,
            'product_id' => $product3->id,
        ]);

        config(['app.disable_order_tax' => false]);

        Queue::fake();

        $new_order = (new OrderRecalculator($order->refresh()))->build();

        Queue::assertPushed(\App\Jobs\OrderTax::class, function ($job) use ($new_order) {
            return $job->order->id === $new_order->id && $job->mode == 'update';
        });

        $this->assertEquals(1100, $new_order->total_price);
    }
}
