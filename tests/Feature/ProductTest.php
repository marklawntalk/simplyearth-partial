<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Shop\Products\Product;
use App\Shop\Tags\Tag;
use Facades\App\Shop\Cart\Cart;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_fillable_filters_the_array()
    {
        $product = new Product();

        $array = [
            'quality_certification' => 1,
            'some' => 2,
            'thing' => 'some_value',
            'else' => 3,
        ];

        $this->assertEquals(['quality_certification' => 1], self::callMethod($product, 'metaFillableFromArray', [$array]));
    }

    public function test_product_meta_filter_and_save_on_submission()
    {
        $product = new Product();

        $product->name = 'Sample';
        $product->sku = 'dfdsff';

        $product->setMeta(['quality_certification' => 1, 'other' => 2]);

        $product->save();

        $this->assertArrayHasKey('quality_certification', $product->getMeta()->toArray());
    }

    public function test_product_image_sort()
    {
        $product = new Product();

        $image1 = $product->addMedia(UploadedFile::fake()->image('product1.jpg'))->toMediaCollection();
        $image2 = $product->addMedia(UploadedFile::fake()->image('product2.jpg'))->toMediaCollection();
        $image3 = $product->addMedia(UploadedFile::fake()->image('product3.jpg'))->toMediaCollection();

        $this->assertCount(3, $product->getMedia());
        $this->assertEquals([
            'product1.jpg',
            'product2.jpg',
            'product3.jpg',
        ], [
            $image1->file_name,
            $image2->file_name,
            $image3->file_name,
        ]);
    }

    public function test_if_product_is_auto_generating_slug()
    {
        $product = factory(Product::class)->create([
            'name' => 'My Product Hey',
            'slug' => '', ]);

        $this->assertEquals('my-product-hey', $product->slug);

        $product2 = factory(Product::class)->create([
            'name' => 'My Product Hey',
            'slug' => '', ]);

        $this->assertEquals('my-product-hey-1', $product2->slug);
    }

    public function test_it_product_saves()
    {
        $this->signInAsAdmin();

        $this->postJson('/admin/products', [
            'name' => 'Product 1',
            'sku' => 'HEHE',
            'price' => 1,
            'extra_attributes' => [
                'label' => 'Volume',
                'value' => '12oz',
            ],
        ]);

        $product = Product::first();

        $this->assertNotNull($product);

        $this->assertEquals([
            'label' => 'Volume',
            'value' => '12oz',
        ], $product->extra_attributes);

        $this->patchJson('/admin/products/'.$product->id, [
            'name' => 'Product 1',
            'sku' => 'HEHE',
            'price' => 1,
            'extra_attributes' => [
                'label' => 'Volume',
                'value' => '20oz',
            ],
        ])->assertStatus(302);

        $this->assertEquals([
            'label' => 'Volume',
            'value' => '20oz',
        ], $product->refresh()->extra_attributes);
    }

    public function test_it_limits_by_tags()
    {
        $product = factory(Product::class)->create(['price' => 30]);

        //add tags to $product
        $tag = Tag::create(['name' => 'wholesale']);

        $product->tags()->save($tag);

        $this->signInAsCustomer();

        $products = Product::limitByTags()->get();

        $this->assertCount(0, $products);

        $this->assertFalse($product->canAccessByTags());

        Cart::add($product);

        $this->assertCount(0, Cart::getProducts());

        customer()->tags()->save($tag);

        customer()->refresh();

        $this->assertTrue($product->canAccessByTags());

        $products = Product::limitByTags()->get();

        $this->assertCount(1, $products);

        Cart::add($product);

        $this->assertCount(1, Cart::getProducts());
    }
}
