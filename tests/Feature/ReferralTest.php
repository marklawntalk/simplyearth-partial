<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Customers\Customer;
use App\Shop\Customers\CustomerReferral;
use App\Shop\Discounts\Discount;
use Illuminate\Support\Facades\Queue;
use App\Jobs\SendReferralInvite;
use Illuminate\Support\Facades\Auth;
use Facades\App\Shop\Cart\Cart;
use App\Shop\Products\Product;
use App\Shop\ShoppingBoxes\ShoppingBox;
use Illuminate\Support\Carbon;
use App\Shop\Orders\Order;
use App\Jobs\KlaviyoTrack;
use App\Shop\Customers\Invitation;
use App\Shop\ShoppingBoxes\ShoppingBoxBuilder;
use Illuminate\Support\Facades\Mail;
use App\Mail\ReferralInvitationInvited;
use App\Mail\ReferralInvitationReminder;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    protected $subscription_monthly;
    protected $subscription_commitment;
    protected $gift_card;


    public function setUp()
    {
        parent::setUp();

        $this->subscription_monthly = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => config('subscription.monthly'), 'price' => 5]
        );

        $this->subscription_commitment = factory(Product::class)->create(
            ['type' => 'subscription', 'sku' => config('subscription.commitment_box'), 'price' => 5]
        );

        $this->gift_card = factory(Product::class)->create(
            ['type' => 'gift_card', 'sku' => env('REFERRAL_GIFT_PRODUCT', 'sGIFT-CARD-20'), 'price' => 20]
        );

        $shop_date = Carbon::parse('first day of this month');

        for ($i = 0; $i < 12; $i++) {
            factory(ShoppingBox::class)->create([
                'name' => $shop_date->format('F Y'),
                'key' => str_slug($shop_date->format('F Y')),
            ]);

            $shop_date->addMonth(1);
        }
    }

    /** @test */
    
    
    public function it_can_generate_share_code()
    {

        config(['app.fraud_check_duration' => 0]);

        $customer1 = factory(Customer::class)->create(['first_name' => 'John Doe', 'last_name' => 'Thurman']);

        $customer1->prepareShareCode();

        $this->assertEquals('JOHNDOET50', $customer1->share_code);

        //it should auto create referral discount;
        $this->assertNotNull($customer1->referral_discount);

        //remaining referral should be equal to referral factor]
        $this->assertEquals(config('app.referral_factor'), $customer1->remaining_referrals);

        //It should append numerica value if same names
        $customer2 = factory(Customer::class)->create(['first_name' => 'John Doe',  'last_name' => 'Thurman']);

        $customer2->prepareShareCode();

        $this->assertEquals('JOHNDOET501', $customer2->share_code);

        //It should also append a numeric value if theres an existing discount code
        $customer3 = factory(Customer::class)->create(['first_name' => 'Jacinth Faith',  'last_name' => 'William']);

        $customer3->findOrCreateAccount();

        $customer3->account->subscribe($this->subscription_monthly->sku)
            ->create();

        $discount = Discount::create(['code' => 'JACINTHFAITHW50', 'type' => 'referral']);

        $customer3->prepareShareCode();

        $this->assertEquals('JACINTHFAITHW501', $customer3->share_code);

        //Test if giftcard applies
        $this->signInAsCustomer();

        Cart::add($this->subscription_monthly);

        Cart::applyDiscount('JACINTHFAITHW501');

        $this->assertEquals('referral50', Cart::getDiscount()->type);

        $order = Cart::setData(['status' => 'processed'])->build();

        $this->assertEquals('JACINTHFAITHW501', $order->refresh()->invitation->code);
        $this->assertEquals('waiting', $order->invitation->status);

        //It should not reward immediately but wait for the order's status to be completed before giving points to the referer
        $this->assertEquals(0, $customer3->refresh()->referral_count);

        //Canceled order should not be counted 
        $order->status = Order::ORDER_CANCELLED;
        $order->save();

        $this->assertEquals(0, $customer3->refresh()->referral_count);

        //Here, the current referral count should be 1 and the nextbox should be 50% off
        $order->complete();

        $this->artisan('reward:check');

        $customer3->refresh();

        $this->assertEquals(1, $customer3->referral_count);
        $this->assertEquals(2.5, $customer3->account->nextBox()->getBuilder()->getGrandTotal());
        //NO Rewards yet
        $this->assertDatabaseMissing('rewards', [
            'customer_id' => $customer3->id,
            'type' => 'freebox',
        ]);

        
        //LETS assign the a referral code to the referrer. Both old and new referral should work
        $old = Discount::create([
            'active' => 1,
            'code' => 'OLDDISCOUNT',
            'type' => 'referral',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        $customer3->forceFill(['old_share_code' => $old->code])->save();

        Auth::logout();

        $this->signInAsCustomer();

        Cart::setCustomer(customer());

        Cart::add($this->subscription_monthly);

        Cart::applyDiscount('OLDDISCOUNT');

        $order = Cart::setData(['status' => 'processed'])->build();

        $this->assertEquals('waiting', $order->refresh()->invitation->status);

        

        $order->complete();

        $this->artisan('reward:check');

        $customer3->refresh();

        // The customer has 1 succesfful referral, and one waiting for 3rd month
        $this->assertEquals(1, $customer3->referral_count); 
        $this->assertDatabaseMissing('rewards', [
            'customer_id' => $customer3->id,
            'type' => 'freebox',
        ]);

        $this->assertEquals(2.5, $customer3->account->nextBox()->getBuilder()->getGrandTotal());

        //Now lets set the referred is on the 2nd month, it should still wait for the 3rd month
        $order = (new ShoppingBoxBuilder(customer()->refresh()->account->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();
        $order->complete();
        $this->artisan('reward:check'); //Check

        $customer3 = $customer3->fresh();//Refresh  
        $this->assertEquals(1, $customer3->referral_count); 
        $this->assertDatabaseMissing('rewards', [
            'customer_id' => $customer3->id,
            'type' => 'freebox',
        ]);
        $this->assertEquals(2.5, $customer3->account->nextBox()->getBuilder()->getGrandTotal());

        //Now lets set the referred is on the 3rd month, it should now Give his reward
        $order = (new ShoppingBoxBuilder(customer()->refresh()->account->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();
        $order->complete(); 
        $this->artisan('reward:check'); //Check

        $customer3 = $customer3->fresh();
        $this->assertEquals(0, $customer3->referral_count); //zero since we convert 2 referral counts to a freebox
        $this->assertDatabaseHas('rewards', [
            'customer_id' => $customer3->id,
            'type' => 'freebox',
        ]);
        $this->assertEquals(0, $customer3->account->nextBox()->getBuilder()->getGrandTotal());  
        $customer3 = $customer3->fresh();

        $order = (new ShoppingBoxBuilder($customer3->account->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();

        //Test referral counts will not expire if the user skips a month

        $customer3->forceFill(['referral_count' => 1])->save();

        $customer3 = $customer3->fresh();
        $this->assertEquals(1, $customer3->referral_count);
        $this->assertEquals(2.5, $customer3->account->nextBox()->getBuilder()->getGrandTotal());
        $customer3->account->subscription->skipMonth($customer3->account->nextBox()->getDate()->format('F Y'));
        $customer3 = $customer3->fresh();
        $order = (new ShoppingBoxBuilder($customer3->account->nextBox()))->setData(['status' => Order::ORDER_PROCESSING])->build();   
        $this->assertEquals(2.5, $order->total_price);
    }

    /** @test */
    public function it_can_send_invitation()
    {

        $this->signInAsCustomer();

        Mail::fake();

        $emails = ['test1@gmail.com','test2@gmail.com'];

        $this->postJson('pages/referral/send-invitation', [
            'email' => $emails
        ])->assertStatus(200);

        Mail::assertQueued(ReferralInvitationInvited::class, 2);

        $this->assertDatabaseHas('invitations', [
            'email' => 'test1@gmail.com',
            'status' => 'pending'
        ]);

        $this->assertDatabaseHas('invitations', [
            'email' => 'test2@gmail.com',
            'status' => 'pending'
        ]);

        //it should work space separated as well
        $emails = ['test3@gmail.com', 'test4@gmail.com', 'test3@gmail.com'];

        $this->postJson('pages/referral/send-invitation', [
            'email' => $emails
        ])->assertStatus(200);

        $this->assertDatabaseHas('invitations', [
            'email' => 'test3@gmail.com',
            'status' => 'pending'
        ]);

        $this->assertDatabaseHas('invitations', [
            'email' => 'test4@gmail.com',
            'status' => 'pending'
        ]);
    }

    /** @test */
    public function it_can_send_reminders()
    {

        $this->signInAsCustomer();

        Mail::fake();

        $emails = ['test1@gmail.com'];

        $this->postJson('pages/referral/send-invitation', [
            'email' => $emails,
            'remind' => 1
        ])->assertStatus(200);

        $invitation = Invitation::first();

        $this->assertEquals('test1@gmail.com', $invitation->email);    

        $this->assertEquals(Carbon::today()->addDays(5)->format('Y-m-d'), $invitation->tobe_reminded_at->format('Y-m-d'));


        Invitation::insert([
            [
                'email' => 'ruewiroew@gmail.com',
                'customer_id' => customer()->id,
                'code' => customer()->share_code,
                'tobe_reminded_at' => Carbon::today(),
                'reminded_at' => null
            ],
            [
                'email' => 'ruewiroe2w@gmail.com',
                'customer_id' => customer()->id,
                'code' => customer()->share_code,
                'tobe_reminded_at' => Carbon::today()->addDays(10),
                'reminded_at' => null
            ],
            [
                'email' => 'ruewiroew3@gmail.com',
                'customer_id' => customer()->id,
                'code' => customer()->share_code,
                'tobe_reminded_at' => Carbon::today(),
                'reminded_at' => Carbon::today(),
            ]
        ]);

        //Check we have 3 unreminded invitations
        $this->assertCount(3, Invitation::unreminded()->get());

        //Here we check that theres only 1 remindable invitation for today
        $this->artisan('invitations:remind');
        Mail::assertQueued(ReferralInvitationReminder::class, 1);
        $this->assertNotNull(Invitation::where('email', 'ruewiroew@gmail.com')->first()->reminded_at);

        //Lets test reminder after 10 days
        Mail::fake();
        Carbon::setTestNow(Carbon::today()->addDays(10));
        $this->artisan('invitations:remind');
        Mail::assertQueued(ReferralInvitationReminder::class, 2);
        $this->assertNotNull(Invitation::where('email', 'test1@gmail.com')->first()->reminded_at);
        $this->assertNotNull(Invitation::where('email', 'ruewiroe2w@gmail.com')->first()->reminded_at);
    }

    function test_old_discount_can_track_referrer()
    {
        $this->signInAsCustomer();

        //if old is available
        $old = Discount::create([
            'active' => 1,
            'code' => 'OLDDISCOUNT',
            'type' => 'referral',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        customer()->forceFill(['old_share_code' => $old->code])->save();

        $old->refresh();

        $this->assertEquals(customer()->id, $old->referrer->id);

        //if new is available
        $new = Discount::create([
            'active' => 1,
            'code' => 'NEWDISCOUNT',
            'type' => 'referral50',
            'options' => [
                'discount_value' => 10,
            ],
        ]);

        customer()->forceFill(['share_code' => $new->code])->save();

        $new->refresh();

        $this->assertEquals(customer()->id, $new->referrer->id);

    }
}
