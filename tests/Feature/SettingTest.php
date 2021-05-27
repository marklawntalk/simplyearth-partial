<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    function test_setting_provider()
    {
        set_option('site_name','simply earth');

        $this->assertDatabaseHas('settings',['option' => 'site_name', 'value' => serialize('simply earth')]);

        $this->assertEquals(get_option('site_name'),'simply earth');
    }

    function test_store_settings_store_address()
    {
        $address = [
            'address' => 'Address',
            'city' => 'New York',
            'zip' => 1000
        ];

        $this->signInAsAdmin();

        $this->postJson('/admin/store/settings/store_address',$address)->assertStatus(200);

        $this->assertDatabaseHas('settings', ['option' => 'store_address', 'value' => serialize($address)]);
    }
    
}
