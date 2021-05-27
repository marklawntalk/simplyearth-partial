<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Shipping\ShippingZone;

class ShippingZoneTest extends TestCase
{
    use RefreshDatabase;

    function test_zone_update_with_shipping_rate_prices()
    {
        $this->postJson('/admin/shipping-zones/')->assertStatus(401);
        
        $this->signInAsAdmin();

        $zone = ShippingZone::create([
            'name' => 'Test',
            'countries' => 'US'
        ]);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'max' => 10,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(422);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 0
                ]
            ]
        ])->assertStatus(422);

        $this->patchJson('/admin/shipping-zones/'.$zone->id,[
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                'name' => 'Free shipping',
                'min' => 20,
                'is_free' => 1                
                ]
            ]
        ])->assertStatus(200);

        $this->assertDatabaseHas('shipping_rate_prices', [
            'name' => 'Free shipping',
            'min' => 20,
            'is_free' => 1,
            'shipping_zone_id' => $zone->id
        ]);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
                [
                    'name' => '19 Below',
                    'min' => 19,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(200);

        $this->assertCount(2,ShippingZone::find($zone->id)->shipping_rate_prices()->get());
    }

    function test_creation_with_shipping_rate_prices()
    {
        $this->signInAsAdmin();
        
        $this->postJson('/admin/shipping-zones', [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
                [
                    'name' => '19 Below',
                    'min' => 19,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(200);

        $zone = ShippingZone::first();

        $this->assertDatabaseHas('shipping_rate_prices', [
            'name' => 'Free shipping',
            'min' => 20,
            'is_free' => 1,
            'shipping_zone_id' => $zone->id
        ]);

        $this->assertCount(2, ShippingZone::find($zone->id)->shipping_rate_prices()->get());

        //Deletion

        $this->patchJson('/admin/shipping-zones/'. $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_prices' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
            ]
        ])->assertStatus(200);

        $this->assertCount(1, ShippingZone::find($zone->id)->shipping_rate_prices()->get());

    }

    function test_zone_update_with_shipping_rate_weights()
    {
        $this->postJson('/admin/shipping-zones/')->assertStatus(401);

        $this->signInAsAdmin();

        $zone = ShippingZone::create([
            'name' => 'Test',
            'countries' => 'US'
        ]);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_weights' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'max' => 10,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(422);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_weights' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 0
                ]
            ]
        ])->assertStatus(422);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_weights' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(200);

        $this->assertDatabaseHas('shipping_rate_weights', [
            'name' => 'Free shipping',
            'min' => 20,
            'is_free' => 1,
            'shipping_zone_id' => $zone->id
        ]);

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_weights' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
                [
                    'name' => '19 Below',
                    'min' => 19,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(200);

        $this->assertCount(2, ShippingZone::find($zone->id)->shipping_rate_weights()->get());
    }

    function test_creation_with_shipping_rate_weights()
    {
        $this->signInAsAdmin();

        $this->postJson('/admin/shipping-zones', [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_weights' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
                [
                    'name' => '19 Below',
                    'min' => 19,
                    'is_free' => 1
                ]
            ]
        ])->assertStatus(200);

        $zone = ShippingZone::first();

        $this->assertDatabaseHas('shipping_rate_weights', [
            'name' => 'Free shipping',
            'min' => 20,
            'is_free' => 1,
            'shipping_zone_id' => $zone->id
        ]);

        $this->assertCount(2, ShippingZone::find($zone->id)->shipping_rate_weights()->get());

        //Deletion

        $this->patchJson('/admin/shipping-zones/' . $zone->id, [
            'name' => 'Test 2',
            'countries' => 'US',
            'shipping_rate_weights' => [
                [
                    'name' => 'Free shipping',
                    'min' => 20,
                    'is_free' => 1
                ],
            ]
        ])->assertStatus(200);

        $this->assertCount(1, ShippingZone::find($zone->id)->shipping_rate_weights()->get());

    }
}
