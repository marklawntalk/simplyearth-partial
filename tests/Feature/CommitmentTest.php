<?php

namespace Tests\Feature;

use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use App\Shop\ShoppingBoxes\ShoppingBox;
use App\Shop\ShoppingBoxes\ShoppingBoxBuilder;
use App\Shop\Subscriptions\Commitment;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use App\Mail\MailCommitMentStopAutoRenew;
use App\Shop\Subscriptions\SubscriptionBuilder;

class CommitmentTest extends TestCase
{
    use RefreshDatabase;

    public function setUp()
    {
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => env('SUBSCRIPTION_MONTHLY_SKU', 'subscription-monthly'), 'price' => 45]
        );
        $this->subscription_6months = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => config('subscription.commitment_box'), 'price' => 39]
        );

        $this->subscription_3months = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => config('subscription.commitment_box_3'), 'price' => 39]
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

    public function test_it_can_checkout_commitment_subscription()
    {

        $this->signInAsCustomer();

        Cart::add($this->subscription_6months);

        $this->assertTrue(Cart::hasCommitmentSubscription());

        //Creating the order creates the commitment
        $order = Cart::build();

        $this->assertTrue(customer()->account->subscription->isCommitment());

        $this->assertDatabaseHas('commitments', [
            'subscription_id' => customer()->account->subscription->id,
            'cycles' => 6,
            'completed_cycles' => 1,
            'status' => 'current']);

        $this->assertEquals(6, customer()->account->subscription->commitment_months);

        $current_commitment = customer()->account->subscription->commitments()->where('status', 'current')->first();

        $this->assertEquals(17, customer()->account->subscription->future_box_count);

        //Test the first order is considered part of the first commitment
        $this->assertCount(1, $current_commitment->orders);

        //TEst the pending commitments are created as well
        $this->assertCount(2, customer()->account->subscription->commitments()->where('status', '!=', 'current')->get());

        //TEst future boxes commitments
        $this->assertEquals($current_commitment->id, customer()->account->nextBox()->getCommitment()->id);

        $future_boxes = account()->futureOrders()->getMonths()->getIterator();
        $first_month = $future_boxes->current();
        $future_boxes->next();
        $second_month = $future_boxes->current();
        $future_boxes->next();
        $third_month = $future_boxes->current();

        $this->postJson('/subscription/' . $first_month->month_key . '/skip')->assertStatus(200);
        $this->assertTrue(account()->subscription->futureOrders()->refresh()->getMonth($first_month->monthKey)->skipped());

        $this->postJson('/subscription/' . $second_month->month_key . '/skip')->assertStatus(200);
        $this->assertTrue(account()->subscription->futureOrders()->refresh()->getMonth($second_month->monthKey)->skipped());

        $this->postJson('/subscription/' . $third_month->month_key . '/skip')->assertStatus(422);
        $this->assertFalse(account()->subscription->futureOrders()->refresh()->getMonth($third_month->monthKey)->skipped());

        //Subscription build will increment the completed cycles

        (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertDatabaseHas('commitments', [
            'subscription_id' => customer()->account->subscription->id,
            'cycles' => 6,
            'completed_cycles' => 2,
            'status' => 'current']);

        //Test completed commitment

        $current = Commitment::where('status', 'current')->first();
        $current->completed_cycles = 5;
        $current->save();

        customer()->account->subscription->futureOrders()->refresh();

        (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertCount(1, customer()->account->subscription->commitments()->where('status', 'completed')->get());
        $this->assertCount(1, customer()->account->subscription->commitments()->where('status', 'current')->get());
        $this->assertCount(2, customer()->account->subscription->commitments()->where('status', 'pending')->get());

        //Test autorenew should not be processed after building

        $current = Commitment::where('status', 'current')->first();
        $current->completed_cycles = 5;
        $current->save();

        customer()->account->subscription->forceFill([
            'auto_renew' => false,
            'resumed_at' => null,
        ])->save();

        customer()->account->futureOrders()->refresh();

        (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertCount(2, customer()->account->subscription->commitments()->where('status', 'completed')->get());
        $this->assertCount(0, customer()->account->subscription->commitments()->where('status', 'current')->get());
        $this->assertCount(3, customer()->account->subscription->commitments()->where('status', 'pending')->get());

        $this->assertNull(account()->nextBox());
        $this->assertTrue(customer()->account->subscription->refresh()->stopped());   
    }

    public function test_commitment_resume_date()
    {

        $this->signInAsCustomer();

        Cart::add($this->subscription_6months);

        $this->assertTrue(Cart::hasCommitmentSubscription());

        //Creating the order creates the commitment
        $order = Cart::build();

        $this->assertTrue(customer()->account->subscription->isCommitment());
        

        $future_date = Carbon::parse('first day of this month')->copy()->addMonths(3);

        customer()->account->subscription->forceFill([
            'ends_at' => null,
            'auto_renew' => false,
            'resumed_at' => $future_date,
        ])->save();

        $current = Commitment::where('status', 'current')->first();
        $current->completed_cycles = 5;
        $current->save();

        (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();


        customer()->account->futureOrders()->refresh();

        $this->assertEquals($future_date->format('F Y'), customer()->account->nextBox()->month_key);

        Carbon::setTestNow($future_date);

        (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        $this->assertNull(customer()->account->subscription->refresh()->resumed_at);
        $this->assertTrue(customer()->account->subscription->refresh()->isAutoRenew());

        customer()->account->subscription->forceFill([
            'ends_at' => null,
            'auto_renew' => false,
            'resumed_at' => $future_date,
        ])->save();

        $this->postJson('/subscription/unpause')->assertRedirect(route('future-orders'));
        $this->assertTrue(account()->subscription->refresh()->isAutoRenew());
        $this->assertNull(account()->subscription->resumed_at);
    }

    public function test_it_can_pause_cancel_commitment()
    {
        $this->signInAsCustomer();

        Cart::add($this->subscription_6months);

        $this->assertTrue(Cart::hasCommitmentSubscription());

        //Creating the order creates the commitment
        $order = Cart::build();

        $this->assertTrue(customer()->account->subscription->isCommitment());
        $this->assertTrue(customer()->account->subscription->refresh()->isAutoRenew());

        // Pausing
        //Invalid pausing date should return 422
        $this->postJson('/subscription/nextbox-continue', [
            'key' => customer()->account->subscription->commitment_last_box->getDate()->copy()->addMonths(10)->format('F Y'),
        ])->assertStatus(422);

        $after_three_month = customer()->account->subscription->commitment_last_box->getDate()->copy()->addMonths(4);

        $this->postJson('/subscription/nextbox-continue', [
            'key' => $after_three_month->format('F Y'),
        ])->assertStatus(200);

        $this->assertFalse(customer()->account->subscription->refresh()->isAutoRenew());
        $this->assertEquals($after_three_month->format('F Y'), customer()->account->subscription->resumed_at->format('F Y'));

        Mail::fake();

        //Stopping
        $this->postJson('/subscription/stop')->assertStatus(200);

        $this->assertFalse(customer()->account->subscription->refresh()->isAutoRenew());
        $this->assertNull(customer()->account->subscription->resumed_at);

        Mail::assertSent(MailCommitMentStopAutoRenew::class, function($mail) {
            return $mail->subscription->id == customer()->account->subscription->id;
        });
    }

    public function test_3_month_commitment()
    {
        $this->signInAsCustomer();

        Cart::add($this->subscription_3months);

        $this->assertTrue(Cart::hasCommitmentSubscription());

        //Creating the order creates the commitment
        $order = Cart::build();

        $this->assertTrue(customer()->account->subscription->isCommitment());
        $this->assertTrue(customer()->account->subscription->refresh()->isAutoRenew());

        $this->assertDatabaseHas('commitments', [
            'subscription_id' => customer()->account->subscription->id,
            'cycles' => 3,
            'completed_cycles' => 1,
            'status' => 'current']);

            $this->assertEquals(3, customer()->account->subscription->commitment_months);

            $current_commitment = customer()->account->subscription->commitments()->where('status', 'current')->first();
    
            $this->assertEquals(8, customer()->account->subscription->future_box_count);
    
            //Test the first order is considered part of the first commitment
            $this->assertCount(1, $current_commitment->orders);
    
            //TEst the pending commitments are created as well
            $this->assertCount(2, customer()->account->subscription->commitments()->where('status', '!=', 'current')->get());
    
            //TEst future boxes commitments
            $this->assertEquals($current_commitment->id, customer()->account->nextBox()->getCommitment()->id);
    
            $future_boxes = account()->futureOrders()->getMonths()->getIterator();
            $first_month = $future_boxes->current();
            $future_boxes->next();
            $second_month = $future_boxes->current();
            $future_boxes->next();
            $third_month = $future_boxes->current();
    
            $this->postJson('/subscription/' . $first_month->month_key . '/skip')->assertStatus(200);
            $this->assertTrue(account()->subscription->futureOrders()->refresh()->getMonth($first_month->monthKey)->skipped());
    
            $this->postJson('/subscription/' . $second_month->month_key . '/skip')->assertStatus(200);
            $this->assertTrue(account()->subscription->futureOrders()->refresh()->getMonth($second_month->monthKey)->skipped());
    
            $this->postJson('/subscription/' . $third_month->month_key . '/skip')->assertStatus(422);
            $this->assertFalse(account()->subscription->futureOrders()->refresh()->getMonth($third_month->monthKey)->skipped());
    
            //Subscription build will increment the completed cycles
    
            (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();
    
            $this->assertDatabaseHas('commitments', [
                'subscription_id' => customer()->account->subscription->id,
                'cycles' => 3,
                'completed_cycles' => 2,
                'status' => 'current']);
    
            //Test completed commitment
    
            $current = Commitment::where('status', 'current')->first();
            $current->completed_cycles = 2;
            $current->save();
    
            customer()->account->subscription->futureOrders()->refresh();
    
            (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();
    
            $this->assertCount(1, customer()->account->subscription->commitments()->where('status', 'completed')->get());
            $this->assertCount(1, customer()->account->subscription->commitments()->where('status', 'current')->get());
            $this->assertCount(2, customer()->account->subscription->commitments()
            ->where('cycles', 3)
            ->where('status', 'pending')->get());
    
            //Test autorenew should not be processed after building
    
            $current = Commitment::where('status', 'current')->first();
            $current->completed_cycles = 2;
            $current->save();
    
            customer()->account->subscription->forceFill([
                'auto_renew' => false,
                'resumed_at' => null,
            ])->save();
    
            customer()->account->futureOrders()->refresh();
    
            (new ShoppingBoxBuilder(account()->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();
    
            $this->assertCount(2, customer()->account->subscription->commitments()->where('status', 'completed')->get());
            $this->assertCount(0, customer()->account->subscription->commitments()->where('status', 'current')->get());
            $this->assertCount(3, customer()->account->subscription->commitments()->where('status', 'pending')->get());
    
            $this->assertNull(account()->nextBox());
            $this->assertTrue(customer()->account->subscription->refresh()->stopped()); 
    }
}
