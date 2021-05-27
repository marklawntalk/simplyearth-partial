<?php

namespace Tests\Feature;

use App\Mail\GiftCard as GiftCardMail;
use App\Shop\Discounts\GiftCard;
use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use Facades\App\Shop\Cart\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Mockery\Mock;

class GiftCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_gift_card_emails_gift_card()
    {

        Mail::fake();

        $this->signInAsCustomer();

        $card = factory(Product::class)->create(['price' => 100, 'type' => 'gift_card']);

        $order = Cart::add($card)->setCustomer(customer())->build();
        
        $order->markAsPaid();

        Mail::assertQueued(GiftCardMail::class);

        $this->assertDatabaseHas('gift_cards', ['remaining' => 100, 'customer_id' => customer()->id]);

        $this->assertEquals(Order::ORDER_COMPLETED, $order->status); //THe order should be completed since the item is a gift card;

        $order->markAsPaid();

        $this->assertCount(1, GiftCard::all());

        $gift_card = GiftCard::first();

        $this->get(route('gift-card', ['gift_card' => $gift_card->id, 'token' => $gift_card->token]))
            ->assertStatus(200)
            ->assertSee($gift_card->code);
    }

    public function test_gift_card_order_with_multiple_quantity()
    {

        Mail::fake();

        $this->signInAsCustomer();

        $card = factory(Product::class)->create(['price' => 100, 'type' => 'gift_card']);

        $order = Cart::add($card, 2)->setCustomer(customer())->build();

        $order->markAsPaid();

        Mail::assertQueued(GiftCardMail::class, 2);

        $this->assertDatabaseHas('gift_cards', ['remaining' => 100, 'customer_id' => customer()->id]);

        $this->assertEquals(Order::ORDER_COMPLETED, $order->status); //THe order should be completed since the item is a gift card;

        $order->markAsPaid();

        $this->assertCount(2, GiftCard::all());

        $gift_card = GiftCard::first();

        $this->get(route('gift-card', ['gift_card' => $gift_card->id, 'token' => $gift_card->token]))
            ->assertStatus(200)
            ->assertSee($gift_card->code);
    }

    public function test_gift_card_apply()
    {
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 100]);

        $gift_card = new GiftCard([
            'code' => 'LLL',
            'customer_id' => customer()->id,
            'remaining' => 25,
            'token' => str_random(30)]);

        $gift_card->order_item_id = 1;
        $gift_card->save();

        Cart::add($product1);

        $this->assertEquals(100, Cart::getTotal());

        Cart::applyGiftCard($gift_card->code);

        $this->assertEquals(75, Cart::getTotal());

        $this->assertNotNull(session('cart.gift_card'));

        $order = Cart::setCustomer(customer())->build();

        $this->assertEquals(75, $order->total_price);

        $this->assertEquals(0, $gift_card->refresh()->remaining);

        //Multiple quantity

        Cart::clear();

        Cart::add($product1, 4);

        $gift_card = new GiftCard([
            'code' => 'JKL',
            'customer_id' => customer()->id,
            'remaining' => 400,
            'token' => str_random(30)]);

        $gift_card->order_item_id = 1;
        $gift_card->save();

        $this->assertEquals(400, Cart::getTotal());

        Cart::applyGiftCard($gift_card->code);

        $this->assertEquals(0, Cart::getTotal());

        $this->assertNotNull(session('cart.gift_card'));

        $order = Cart::setCustomer(customer())->build();

        $this->assertEquals(0, $order->total_price);

        $this->assertEquals(0, $gift_card->refresh()->remaining);
    }

    public function test_gift_card_reuse()
    {

        Mail::fake();
        
        $this->signInAsCustomer();

        $product1 = factory(Product::class)->create(['price' => 50]);

        $gift_card = new GiftCard([
            'code' => 'JKL',
            'customer_id' => customer()->id,
            'remaining' => 100,
            'token' => str_random(30)]);

        $gift_card->order_item_id = 1;
        $gift_card->save();

        //First order;

        Cart::add($product1);

        $this->assertEquals(50, Cart::getTotal());

        Cart::applyGiftCard($gift_card->code);

        $order = Cart::setCustomer(customer())->build();

        $this->assertEquals(0, $order->total_price);

        $this->assertEquals(50, $gift_card->refresh()->remaining);

        //Second order

        Cart::clear();

        Cart::add($product1);

        $this->assertEquals(50, Cart::getTotal());

        Cart::applyGiftCard($gift_card->code);

        $order = Cart::setCustomer(customer())->build();

        $this->assertEquals(0, $order->total_price);

        $this->assertEquals(0, $gift_card->refresh()->remaining);
    }

    public function test_giftcard_variants()
    {
        Mail::fake();

        $giftcard = factory(Product::class)->create(['type' => 'gift_card', 'price' => 20, 'setup' => 'variable']);

        $variant = factory(Product::class)->create(['price' => 25, 'type' => 'variant']);

        $giftcard->variants()->save($variant);

        $this->assertEquals($giftcard->id, $variant->parent_id);

        

        $this->signInAsCustomer();

        $order = Cart::add($variant)->build();
        $order->processGiftCards();

        $this->assertCount(1, GiftCard::all());

    }

    public function test_generate_gift_card()
    {
        $this->signInAsCustomer();

        customer()->generateGiftCard(50, 10);

        $this->assertCount(10, GiftCard::where('initial_value', 50)->where('remaining', 50)->where('customer_id', customer()->id)->get());
    }
}
