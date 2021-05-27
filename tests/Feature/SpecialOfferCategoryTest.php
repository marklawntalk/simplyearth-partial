<?php

namespace Tests\Feature;

use App\Shop\Categories\Category;
use App\Shop\Discounts\Discount;
use App\Shop\Products\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Facades\App\Shop\Cart\Cart;

class SpecialOfferCategoryTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    function special_offer_category_percentage()
    {
        $product = factory(Product::class)->create(['price' => 10, 'shipping' => 1]);
        $product2 = factory(Product::class)->create(['price' => 10, 'shipping' => 1]);
        $product3 = factory(Product::class)->create(['price' => 10, 'shipping' => 1]);

        $category = Category::create(['name' => 'Oils', 'slug' => 'oils']);

        $category->products()->save($product);

        Discount::create([
            'code' => 'special_offer_category',
            'type' => 'special_offer_category',
            'options' => [
                'customer_eligibility' => 'everyone',
                'special_offer_categories' => [$category->id],
                'special_offer_purchase' => 3,
                'special_offer_receive' => 1,
                'special_offer_type' => 'percentage',
                'special_offer_discount_value' => '100'
            ],
        ]);

        Cart::add($product, 4);

        Cart::applyDiscount('special_offer_category');

        $this->assertEquals(30, Cart::getTotal());

        //Test it shouldnt apply for products not part of the category

        Cart::add($product2, 4);
        Cart::add($product3, 4);

        $this->assertEquals(10, Cart::getDiscountTotal());

        Cart::clear();

        //Test different products
        $category->products()->save($product2);

        Cart::add($product, 4);
        Cart::add($product2->refresh(), 4);

        Cart::applyDiscount('special_offer_category');

        $this->assertEquals(20, Cart::getDiscountTotal());
    }
}
