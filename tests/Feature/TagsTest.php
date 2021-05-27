<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Customers\Customer;
use App\Shop\Tags\Tag;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    function customer_can_assign_tags()
    {
        $customer = factory(Customer::class)->create();

        $this->assertFalse($customer->hasTags('hello'));
        $this->assertFalse($customer->hasTags(['hello', 'hi', 'there']));

        $customer->assignTags('hello');
        $customer->assignTags(['hi', 'there']);

        $customer->refresh();

        $this->assertCount(3, Tag::all());
        $this->assertTrue($customer->hasTags('hello'));
        $this->assertTrue($customer->hasTags(['hello', 'hi', 'there']));

        //assigning existing tags should not create another tag
        $customer->assignTags('hello');
        $this->assertCount(3, Tag::all());

        $customer->removeTags('hello');
        $customer->refresh();
        
        $this->assertFalse($customer->hasTags(['hello', 'hi', 'there']));
        $this->assertCount(2, Tag::all());
    }
}
