<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\Event;
use App\Events\Registered as RegisteredCustomer;
use Illuminate\Support\Facades\Mail;
use App\Mail\Registered as RegisteredMail;
use App\Shop\Customers\Customer;
use App\Shop\Customers\CustomerRepository;
use App\Mail\Activation as ActivationMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use App\Jobs\KlaviyoIdentify;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;
    use WithoutMiddleware;

    function test_user_can_register()
    {

        Mail::fake();
        Queue::fake(KlaviyoIdentify::class);

        $this->postJson('/register', [])->assertStatus(422);

        $response = $this->postJson('/register', [
            'first_name' => 'John',
            'last_name' => 'Fabian',
            'email' => 'jf@example.com',
            'password' => 'testing',
            'password_confirmation' => 'testing'
        ]);

        $this->assertDatabaseHas('customers', [
            'first_name' => 'John',
            'last_name' => 'Fabian',
            'email' => 'jf@example.com',
        ]);

        $this->assertDatabaseHas('accounts', [
            'email' => 'jf@example.com',
        ]);

        Mail::assertQueued(RegisteredMail::class, function ($mail) {

            return $mail->customer->email === 'jf@example.com';

        });
        
        Queue::assertPushed(KlaviyoIdentify::class);

    }


    function test_send_invite_and_user_can_activate()
    {

        Mail::fake();
        Queue::fake();

        $customer = factory(Customer::class)->create();

        $this->postJson('/admin/customers/sendinvite/' . $customer->id)->assertStatus(200);

        $this->assertDatabaseHas('customer_activations', ['email' => $customer->email]);

        Mail::assertSent(ActivationMail::class, function ($mail) use ($customer) {

            return $mail->customer->email === $customer->email;

        });

        $token = $customer->fresh()->activation->token;
        $this->getJson('activation/' . $token);

        $this->assertDatabaseHas('accounts', ['email' => $customer->email]);
        $this->assertDatabaseMissing('customer_activations', ['email' => $customer->email]);

        Mail::assertQueued(RegisteredMail::class, function ($mail) use ($customer) {

            return $mail->customer->email === $customer->email;

        });

    }

    function test_existing_customer_requires_email_verification()
    {
        Mail::fake();

        $customer = factory(Customer::class)->create();

        $response = $this->postJson('register', [
            'email' => $customer->email,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'password' => 'something',
            'password_confirmation' => 'something'
        ])
            ->assertRedirect(route('customer.login'));

        $this->assertEquals(
            session()->get('flash_notification')->first()->message,
            sprintf('We have sent an email to %s, please click the link included to verify your email address.', $customer->email)
        );

        Mail::assertSent(ActivationMail::class, function ($mail) use ($customer) {

            return $mail->customer->email === $customer->email;

        });

    }

    function test_existing_customer_can_strill()
    {
        Mail::fake();

        $customer = factory(Customer::class)->create();

        $response = $this->postJson('register', [
            'email' => $customer->email,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'password' => 'something',
            'password_confirmation' => 'something'
        ])
            ->assertRedirect(route('customer.login'));

        $this->assertEquals(
            session()->get('flash_notification')->first()->message,
            sprintf('We have sent an email to %s, please click the link included to verify your email address.', $customer->email)
        );

        Mail::assertSent(ActivationMail::class, function ($mail) use ($customer) {

            return $mail->customer->email === $customer->email;

        });

    }

}