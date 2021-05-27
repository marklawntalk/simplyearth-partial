<?php

namespace Tests\Feature;

use App\Jobs\DesktopShipperUpdateStatus;
use App\Shop\Customers\Account;
use App\Shop\Orders\InstallmentBuilder;
use App\Shop\Orders\Order;
use App\Shop\Processes\ProcessInstallments;
use App\Shop\Products\Product;
use Facades\App\Shop\Cart\Cart;
use Facades\App\Shop\Checkout\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use App\Shop\Orders\InstallmentPlan;
use Illuminate\Support\Facades\Queue;

class InstallmentPlansTest extends TestCase
{
    use RefreshDatabase;

    protected $product;

    public function setUp()
    {
        parent::setUp();

        $this->product = factory(Product::class)->create([
            'price' => 100,
            'available_plans' => ['20|8|10'],
        ]);
    }

    /** @test */
    public function it_can_create_plans()
    {
        $account = factory(Account::class)->create();

        $installment = (new InstallmentBuilder(null, $account->customer, $this->product, 8, 30))->build();

        $this->assertDatabaseHas('installment_plans', [
            'plan' => $this->product->sku,
            'deposit' => 20,
            'amount' => 10,
            'cycles' => 8,
            'schedule' => 28,
            'account_id' => $account->id,
        ]);
    }

    /** @test */
    public function cart_can_handle_plans()
    {
        $this->signInAsCustomer();

        Queue::fake(DesktopShipperUpdateStatus::class);

        $product = factory(Product::class)->create();

        Cart::addPlan($product);

        $this->assertCount(0, Cart::getProducts());
        $this->assertFalse(Cart::hasPlanProduct());

        Cart::addPlan($this->product);

        $this->assertCount(1, Cart::getProducts());
        $this->assertTrue(Cart::hasPlanProduct());

        //Checkout

        Checkout::getOrCreateCustomer();
        Checkout::processCheckout();

        $order = Order::latest()->first();

        $this->assertDatabaseHas('installment_plans', [
            'plan' => $this->product->sku,
            'deposit' => 20,
            'amount' => 10,
            'cycles' => 8,
            'order_id' => $order->id,
            'schedule' => min(28, Carbon::now()->format('d')),
            'next_schedule_date' => Carbon::now()->addMonth(1)->format('Y-m-d'),
            'account_id' => customer()->account->id,
        ]);

        //Cancelled order should auto cancel corresponding installment_plan
        $order->cancelOrder();

        $this->assertEquals('cancelled', InstallmentPlan::first()->status);
    }

    /** @test */
    public function it_can_process_installment_plan_cron_job()
    {
        Mail::fake();

        $account = factory(Account::class)->create();

        $now = Carbon::now();

        $installment = (new InstallmentBuilder(null, $account->customer, $this->product, 8, $now->format('d')))->build();

        $processor = new ProcessInstallments;

        $this->assertCount(0, $processor->installmentsToday());

        //It should process after a month
        Carbon::setTestNow($now->addMonth(1));

        $this->assertCount(1, $processor->installmentsToday());

        $processor->run();

        Mail::assertQueued(\App\Mail\FailedChargePlan::class, function ($mail) {
            return $mail->day == 1;
        });

        //On failed charge
        $this->assertDatabaseHas('history', [
            'model_type' => 'payment_plan',
            'model_id' => $installment->id,
            'type' => 'failed_charge',
        ]);

        $this->assertEquals($now->addDays(2)->format('Y-m-d'), $installment->refresh()->next_schedule_date);
        $this->assertEquals(1, $installment->failed_attempts);
        $this->assertTrue($account->customer->hasTags('installment-failed-charge'));

        $installment->charge(); //Second Attempt
        $this->assertEquals($now->modify('next saturday')->format('Y-m-d'), $installment->refresh()->next_schedule_date);
        $this->assertEquals(2, $installment->failed_attempts);

        Mail::assertQueued(\App\Mail\FailedChargePlan::class, function ($mail) {
            return $mail->day == 2;
        });

        //More than 4 failed attempts should mark the installment plan incomplete
        $installment->update(['failed_attempts' => 4]);

        $installment->charge();
        $this->assertEquals('incomplete', $installment->refresh()->status);

        Mail::assertQueued(\App\Mail\FailedChargePlan::class, function ($mail) {
            return $mail->day == 5;
        });

        config(['app.disable_charge' => true]);
        //Should increment paid_cycles and update next_schedule_date after process
        $installment->update(['status' => 'active']);
        $processor->run();
        $this->assertEquals(1, $installment->refresh()->paid_cycles);
        $this->assertEquals($now->addMonth(1)->format('Y-m-d'), $installment->next_schedule_date);
        $this->assertEquals(0, $installment->failed_attempts);
        $this->assertFalse($account->customer->hasTags(['installment-failed-charge', 'installment-incomplete']));

        //It saves payment plan history
        $this->assertDatabaseHas('history', [
            'model_type' => 'payment_plan',
            'model_id' => $installment->id,
            'type' => 'charge',
        ]);
    }

    /** @test */
    function failed_charge_plan_cant_buy_products()
    {
        $this->signInAsCustomer();

        customer()->assignTags('installment-failed-charge');

        //It should be able to purchase 
        $product = factory(Product::class)->create();

        $this->patchJson('/cart/'.$product->id.'/edit', ['qty' => 1, 'ship_now' => 1])
        ->assertJsonFragment([
            'has_se_message' => true,
            'link_text' => 'Update My Payment Method'
        ]);
    }
}
