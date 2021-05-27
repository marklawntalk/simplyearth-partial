<?php

namespace App\Shop\Orders;

use App\Events\OrderStatusUpdated;
use App\Mail\GiftCard;
use App\Shop\Customers\Customer;
use App\Shop\Discounts\GiftCardGenerator;
use App\Shop\Orders\BillingAddress;
use App\Shop\Orders\OrderItem;
use App\Shop\Orders\ShippingAddress;
use App\Shop\Tags\Tag;
use App\Traits\Filterable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Shop\Customers\Invitation;
use App\Jobs\ProcessReferral;
use Illuminate\Contracts\Bus\Dispatcher;

class Order extends Model
{

    /**
     * Pending payment – Order received(unpaid)
     */
    const ORDER_PENDING = 'pending';

    /**
     * Failed – Payment failed or was declined(unpaid) . Note that this status may not show immediately and instead show as Pending until verified(i . e ., PayPal)
     */
    const ORDER_FAILED = 'failed';

    /**
     * Processing – Payment received and stock has been reduced – the order is awaiting fulfillment .
     * All product orders require processing, except those that are Digital and Downloadable .
     */
    const ORDER_PROCESSING = 'processing';

    /**
     * Completed – Order fulfilled and complete – requires no further action
     */
    const ORDER_COMPLETED = 'completed';

    /**
     * On - Hold – Awaiting payment – stock is reduced, but you need to confirm payment
     */
    const ORDER_ON_HOLD = 'on_hold';

    /**
     * Cancelled – Cancelled by an admin or the customer – no further action required(Cancelling an order does not affect stock quantity by default)
     */
    const ORDER_CANCELLED = 'cancelled';

    /**
     * Refunded – Refunded by an admin – no further action required
     */
    const ORDER_REFUNDED = 'refunded';

    use Filterable;

    protected $prefix = 'SE2-';

    protected $metaTable = 'orders_meta';

    protected $fillable = [
        'email',
        'phone',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'processed_at',
        'completed_at',
        'closed_at',
        'cancelled_at',
        'shipping_date',
    ];

    protected $casts = [
        'discount_details' => 'array',
        'payment_details' => 'array',
        'client_details' => 'array',
        'gift_details' => 'array',
        'customer_id' => 'string',
        'summary' => 'array',
    ];

    protected $appends = [
        'order_number',
        'payment_method',
        'processed_at_zone',
    ];

    protected $hidden = [
        'payment_details',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            $order->token = str_random(30);
        });
    }

    public function billing_address()
    {
        return $this->hasOne(BillingAddress::class);
    }

    public function shipping_address()
    {
        return $this->hasOne(ShippingAddress::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function getProcessedAtZoneAttribute()
    {
        return $this->processed_at->toW3cString();
    }

    public function getPaymentMethodAttribute()
    {
        return [
            'maskedNumber' => (new \App\Shop\Orders\MaskedNumber($this->payment_details))->getMaskedNumber()
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Order Items
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function order_items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function setIpAddressAttribute($value)
    {
        $this->attributes['ip_address'] = $value ?? $_SERVER["HTTP_CF_CONNECTING_IP"] ?? $_SERVER['REMOTE_ADDR'] ?? request()->ip();
    }

    public function getOrderNumberAttribute($value)
    {
        if (!is_null($this->order_name)) {
            return $this->order_name;
        }

        return $this->getKey();
    }

    public static function getOrderByNumber($orderNumberRaw)
    {
        $order_number_array = explode('-', $orderNumberRaw);
        $order_number = !empty($order_number_array[1])
            ? $order_number_array[1] : $order_number_array[0];

        return self::where('id', (int) $order_number)->orWhere('order_name', $order_number)->first();
    }

    public function scopeRecent($query)
    {
        return $query->whereIn('status', ['processing', 'completed']);
    }

    public function isSubscriptionPurchase()
    {
        return !is_null($this->subscription) ? true : false;
    }

    public function markAsPaid()
    {
        $this->status = self::ORDER_PROCESSING;
        $this->save();

        event(new OrderStatusUpdated($this));
    }

    public function cancelOrder()
    {
        $this->status = self::ORDER_CANCELLED;
        $this->save();

        event(new OrderStatusUpdated($this));
        \App\Jobs\OrderTax::dispatch($this, 'delete')->onQueue('normal');
    }

    public function markAsCompleted()
    {
        $this->status = self::ORDER_COMPLETED;
        $this->completed_at = Carbon::now();
        $this->save();

        event(new OrderStatusUpdated($this));
    }

    public function processGiftCards()
    {
        $this->order_items->each(function ($order_item) {
            if ($order_item->product->isGiftCard() && !$order_item->load('giftCards')->giftCards->count()) {

                foreach (range(1, $order_item->quantity) as $g) {

                    $attempts = 0;

                    do {
                        if ($attempts > 10) {
                            throw new \Exception('failed to generate a gift card');
                        }

                        Log::info('Creating gift card attempt:' . $attempts);

                        $gift_card = $order_item->giftCards()->create([
                            'token' => str_random(30),
                            'code' => GiftCardGenerator::generateCode(),
                            'initial_value' => $order_item->product->price,
                            'remaining' => $order_item->product->price,
                            'customer_id' => $this->customer_id,
                        ]);
                    } while (!$gift_card && ++$attempts < 10);

                    if ($gift_card) {
                        Mail::to($this->customer)->send(new GiftCard($gift_card));
                    }
                }
            }
        });
    }

    public function reCalculate()
    {
        return (new OrderRecalculator($this->refresh()))->build();
    }

    public function needsShipping()
    {
        return $this->order_items->where('product.shipping', true)->count();
    }

    public function scopeNeedsShippingOnly($query)
    {
        return $query->whereHas('order_items', function ($query) {
            $query->where('product.shipping', true);
        });
    }

    public function installment_plans()
    {
        return $this->hasMany(InstallmentPlan::class, 'order_id', 'id');
    }

    public function isReferred()
    {
        return isset($this->invitation_id);
    }

    public function invitation()
    {
        return $this->belongsTo(Invitation::class);
    }

    public function getReferrerAttribute()
    {
        return $this->invitation ? $this->invitation->referrer : null;
    }

    public function complete()
    {
        $this->markAsCompleted();
    }

    public function approve()
    {
        $this->needs_approval = 0;

        $this->save();

        return $this;
    }

    public function updateReferrer($customer_id)
    {
        $customer = Customer::findOrFail($customer_id);

        if (!$customer) {
            return;
        }

        //check if the order has an invitation already. Check if the same customer.

        if ($this->invitation) {

            //Do nothing if the same customer
            if ($this->invitation->customer_id == $customer_id) {
                return;
            }

            //Lets remove current referrer
            $this->removeReferrer();
        }

        app(Dispatcher::class)->dispatchNow(new ProcessReferral($this, $customer->prepareShareCode()->referral_discount));
    }

    public function removeReferrer()
    {
        if ($this->invitation) {
            $this->invitation->delete();
        }

        return $this;
    }
}