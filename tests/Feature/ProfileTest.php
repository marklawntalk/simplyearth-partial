<?php

namespace Tests\Feature;

use App\Http\Resources\AddressResource;
use App\Shop\Customers\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_address()
    {
        $account = factory(Account::class)->create(['email' => 'existingemail@gmail.com']);
        $this->signInAsCustomer();
        $address = [
            'first_name' => 'Mark',
            'last_name' => 'Lontoc',
            'phone' => '12345',
            'company' => 'Hey',
            'address1' => 'address1',
            'address2' => 'dfadfdasf',
            'city' => 'Tagb',
            'zip' => '1234',
            'region' => 'NY',
            'country' => 'US',
        ];

        $this->patchJson('/profile/address', $address)->assertStatus(200);

        $this->assertEquals($address, (new AddressResource(customer()->default_address))->toArray([]));

    }

    public function test_it_updates_new_email()
    {
        $account = factory(Account::class)->create(['email' => 'existingemail@gmail.com']);
        $this->signInAsCustomer();

        //Checks existing email
        $this->patchJson('/profile/email', ['email' => 'existingemail@gmail.com'])->assertStatus(422);

        $this->patchJson('/profile/email', ['email' => 'somethinganother@gmail.com'])->assertStatus(200);

        //Should update both customer and account emails
        $this->assertEquals('somethinganother@gmail.com', customer()->email);
        $this->assertEquals('somethinganother@gmail.com', customer()->account->email);
    }

    public function test_it_updates_password()
    {
        $account = factory(Account::class)->create(['email' => 'existingemail@gmail.com', 'password' => bcrypt('haha')]);
        $this->signInAsCustomer($account);

        //Checks existing email
        $this->patchJson('/profile/password', [
            'current_password' => 'idontknow',
            'password' => 'newpass',
            'password_confirmation' => 'newpass'])->assertStatus(422);

        $this->patchJson('/profile/password', [
            'current_password' => 'haha',
            'password' => 'newpass',
            'password_confirmation' => 'newpass'])->assertStatus(200);

        $account->refresh();
        //Should update both customer and account emails
        $this->assertTrue(Hash::check('newpass', $account->password));
    }

    public function test_it_updates_payment_method()
    {
        $this->signInAsCustomer();

        //Checks existing email
        $this->patchJson('/profile/payment', [
            'nonce' => 'fake-valid-nonce',
        ])->assertStatus(200);

        $braintree = customer()->account->braintree_id;

        $this->assertNotNull($braintree);
    }
}
