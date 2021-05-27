<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\User;

class AdministratorsTest extends TestCase
{

    use RefreshDatabase;

    public function setUp()
    {
        // first include all the normal setUp operations
        parent::setUp();

        // now re-register all the roles and permissions
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->registerPermissions();
    }

    
    function test_make_user_as_admin()
    {
        $admin = factory(User::class)->create();
        $super_admin = factory(User::class)->create();

        $admin->syncRoles('admin');
        $super_admin->syncRoles(['super-admin']);
        
        $user = factory(User::class)->create();

        $this->actingAs($admin);
        $response = $this->json('post','/admin/administrators/adduser',[
            'id' => $user->id
        ]);

        $response->assertStatus(403);

        
        $this->actingAs($super_admin);

        $response = $this->json('post', '/admin/administrators/adduser', [
            'id' => $user->id
        ]);
        

        $response->assertStatus(200);

        $this->assertEquals(['admin'],$user->getRoleNames()->toArray());
    }


    function test_administrator_store()
    {
        $response = $this->postJson('/admin/administrators/store');

        $response->assertStatus(401);

        $this->signInAsAdmin();

        $response = $this->postJson('/admin/administrators/store');

        $response->assertStatus(403);

        $this->signInAsSuperAdmin();

        $response = $this->postJson('/admin/administrators/store');

        $response->assertStatus(422);

        $response = $this->postJson('/admin/administrators/store',[
            'name' => 'Joe',
            'email' => 'mm@mgail.com',
            'password' => 'password',
            'password_confirmation' => 'password'
        ]);

        $response->assertStatus(200);
    }

    
}
