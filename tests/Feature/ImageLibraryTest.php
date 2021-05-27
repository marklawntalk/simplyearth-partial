<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Misc\ImageLibrary;

class ImageLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_has_a_default_image_library()
    {
        $this->assertDatabaseHas('image_libraries', ['name' => 'Default', 'slug' => 'default']);
    }
    
    public function test_it_generates_unique_slug_for_image_library()
    {
        $library1 = ImageLibrary::create(['name' => 'Default']);
        $library2 = ImageLibrary::create(['name' => 'Default']);
        $library3 = ImageLibrary::create(['name' => 'Default']);
        $library4 = ImageLibrary::create(['name' => 'Default 2']);

        $this->assertEquals('default-2', $library2->slug);
        $this->assertEquals('default-3', $library3->slug);
        $this->assertEquals('default-2-1', $library4->slug);
    }
}
