<?php

namespace App\Shop\Orders;

use App\Shop\Discounts\GiftCard;
use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{

    protected $with = ['product'];
    protected $appends = ['cover', 'priceString'];
    protected $keyType = 'double';

    protected $attributes = [
        'taxable' => true,
    ];

    protected $fillable = [
        'quantity',
        'price',
        'sale_price',
        'product_id',
        'sku',
        'name',
        'extra_attributes',
        'image_url',
        'requires_shipping',
        'taxable',        
    ];

    protected $casts = ['extra_attributes' => 'array'];

    public function getIdAttribute($value)
    {
        return sprintf('%.0f', $value);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }

    public function getPriceStringAttribute()
    {
        return sprintf('%s%s', config('cart.currency')['symbol'], $this->price);
    }

    public function getCoverAttribute()
    {
        return $this->image_url ?? $this->product ? @$this->product->cover : null;
    }

    public function giftCards()
    {
        return $this->hasMany(GiftCard::class, 'order_item_id');
    }

    public function updateQuantity($quantity)
    {
        if ($this->quantity == $quantity) {
            return $this;
        }

        $this->quantity = (int) $quantity;
        $this->save();
        return $this;
    }
}
