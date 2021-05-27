<?php

namespace Tests\Feature;

use App\Mail\WholesalerVerify;
use App\Shop\Customers\Account;
use App\Shop\Customers\Customer;
use App\Shop\Customers\WholesalerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use App\Jobs\KlaviyoIdentify;

class WholesalerTypeFormTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function typeform_sends_verify_email()
    {
        Mail::fake();

        //request without a key should redirect to sales page
        $response = $this->json('GET', '/wholesaler/create', [
            'key' => '1234',
            'first_name' => 'Joe',
            'last_name' => 'Johnson',
            'email' => 'johnsontest@test.com',
            'phone' => '3333',
            'address1' => '3-12 Kamagong RD',
            'address2' => 'Uptown Ubujan',
            'city' => 'Tagbilaran',
            'region' => 'Bohol',
            'zip' => '6300',
            'country' => 'United States',
        ]);

        $this->assertDatabaseHas('wholesaler_registrations', [
            'email' => 'johnsontest@test.com',
        ]);

        $this->assertDatabaseHas('customers', [
            'email' => 'johnsontest@test.com',
        ]);

        $this->assertDatabaseHas('addresses', [
            'address1' => '3-12 Kamagong RD',
            'address2' => 'Uptown Ubujan',
            'city' => 'Tagbilaran',
            'region' => 'Bohol',
            'zip' => '6300',
        ]);

        Mail::assertSent(WholesalerVerify::class, function ($mail) {
            return $mail->hasTo('johnsontest@test.com');
        });

        $response->assertStatus(302);
    }

    /** @test */
    public function it_verifies_wholesaler()
    {
        
        Queue::fake(KlaviyoIdentify::class);

        $registration = WholesalerRegistration::createAndSendVerification([
            'key' => '1234',
            'first_name' => 'Joe',
            'last_name' => 'Johnson',
            'email' => 'johnsontest@test.com',
            'phone' => '3333',
            'address1' => '3-12 Kamagong RD',
            'address2' => 'Uptown Ubujan',
            'city' => 'Tagbilaran',
            'region' => 'Bohol',
            'zip' => '6300',
            'country' => 'United States',
        ]);

        $response = $this->getJson(route('wholesaler.verify', ['email' => $registration->email, 'token' => $registration->token]));

        $customer_password_reset = DB::table('customer_password_resets')->where('email', $registration->email)->first();

        $response->assertStatus(302);

        $this->assertDatabaseHas('customers', [
            'first_name' => 'Joe',
            'last_name' => 'Johnson',
            'email' => 'johnsontest@test.com',
        ]);

        Queue::assertPushed(KlaviyoIdentify::class);

        $customer = Customer::first();
        $this->assertTrue($customer->isWholesaler());

        //Wholesaler registration must be removed
        $this->assertDatabaseMissing('wholesaler_registrations', ['email' => $registration->email, 'token' => $registration->token]);
        
    }

    /** @test */
    public function it_process_wholesaler_for_existing_customer()
    {
        $customer = Customer::create([
            'first_name' => 'Joe',
            'last_name' => 'Johnson',
            'email' => 'johnsontest@test.com',
        ]);

        Mail::fake();

        $response = $this->json('GET', '/wholesaler/create', [
            'key' => '1234',
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => '3333',
            'address1' => '3-12 Kamagong RD',
            'address2' => 'Uptown Ubujan',
            'city' => 'Tagbilaran',
            'region' => 'Bohol',
            'zip' => '6300',
            'country' => 'United States',
        ]);

        Mail::assertSent(WholesalerVerify::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });

        $registration = WholesalerRegistration::first();

        $this->getJson(route('wholesaler.verify', ['email' => $registration->email, 'token' => $registration->token]))
            ->assertStatus(302);

        $this->assertDatabaseHas('accounts', [
            'email' => $customer->email,
        ]);

        $this->assertTrue($customer->refresh()->isWholesaler());

        $account = factory(Account::class)->create();

        // Test it process existing account;
        $response = $this->json('GET', '/wholesaler/create', [
            'key' => '1234',
            'first_name' => $account->customer->first_name,
            'last_name' => $account->customer->last_name,
            'email' => $account->customer->email,
            'phone' => '3333',
            'address1' => '3-12 Kamagong RD',
            'address2' => 'Uptown Ubujan',
            'city' => 'Tagbilaran',
            'region' => 'Bohol',
            'zip' => '6300',
            'country' => 'United States',
        ]);

        Mail::assertSent(WholesalerVerify::class, function ($mail) use ($account) {
            return $mail->hasTo($account->customer->email);
        });

        $registration = WholesalerRegistration::where('email', $account->email)->first();

        $this->getJson(route('wholesaler.verify', ['email' => $registration->email, 'token' => $registration->token]))
            ->assertRedirect(route('wholesaler.sales'));

        $this->assertTrue($account->refresh()->customer->isWholesaler());
    }
}
