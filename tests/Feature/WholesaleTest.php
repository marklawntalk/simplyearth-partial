<?php

namespace Tests\Feature;

use App\Mail\OrderProcessed;
use App\Shop\Products\Product;
use App\Shop\Settings\Setting;
use Facades\App\Shop\Cart\Cart;
use Facades\App\Shop\Checkout\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WholesaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_is_wholesaler()
    {
        Mail::fake();

        $this->signInAsCustomer();

        $this->assertFalse(customer()->isWholesaler());

        customer()->tags()->create(['name' => 'wholesale']);

        $this->assertTrue(customer()->refresh()->isWholesaler());

        //Check discount

        $product1 = factory(Product::class)->create(['price' => 50, 'wholesale_price' => 20, 'wholesale_pricing' => true, 'shipping' => 1]);

        Cart::add($product1, 3);

        $this->assertEquals(90, Cart::wholesaleDiscountTotal());

        $this->assertEquals(60, Cart::getSubTotal());

        $this->assertEquals(60, Cart::getGrandTotal());

        //minimum order
        set_option('settings_wholesale', ['wholesale_minimum_order' => 200, 'wholesale_shipping_total' => 20]);
        $this->app->instance('settings', Setting::pluck('value', 'option')->toArray()); //refresh singleton settings

        $this->assertEquals(90, Cart::wholesaleDiscountTotal());
        $this->assertEquals(80, Cart::getGrandTotal());
        $this->assertEquals(20, Cart::getTotalShipping());

        //First order of the wholesaler must wait for admin's approval
        Checkout::getOrCreateCustomer();
        $order = Checkout::processCheckout();

        $this->assertEquals(1, $order->needs_approval);
        Mail::assertNotQueued(OrderProcessed::class);

        $this->signInAsAdmin();

        $this->patchJson("/admin/orders/" . $order->id . "/approve")->assertStatus(200);

        Mail::assertQueued(OrderProcessed::class);

        $this->assertEquals(0, $order->refresh()->needs_approval);

        //Check the shipping is normal if no product with wholesale pricing is added

        $this->signInAsWholesaler();

        Cart::clear();

        $normal_product = factory(Product::class)->create(['price' => 50, 'shipping' => 1]);

        Cart::add($normal_product);
        $this->assertEquals(0, Cart::getTotalShipping());
    }
}
