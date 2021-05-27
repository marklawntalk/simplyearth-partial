<?php

namespace App\Shop\Orders;

use App\Jobs\ProcessReferral;
use App\Shop\Customers\Customer;
use App\Shop\Discounts\Discount;
use App\Shop\Discounts\GiftCard;
use App\Shop\Products\Product;
use App\Shop\Products\ProductInterface;
use App\Shop\Shipping\ShippingZone;
use App\Shop\ShoppingBoxes\ShoppingBox;
use App\Shop\Tax\TaxCalculator;
use App\Traits\CanAddBonus;
use App\Traits\OrderTotals;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OrderBuilder
{
    use OrderTotals, CanAddBonus;

    protected $is_checkout = true;
    protected $keep = 0;
    protected $products;
    protected $customer;
    protected $data = [];
    protected $shipping_method;
    protected $discount_applied;
    protected $gift_card_applied;
    protected $discount_override;
    protected $fields = [
        'id', 'sku', 'name', 'price', 'priceString', 'cover', 'compare_price', 'compare_price_discount', 'compare_price_discount_percentage',
        'shipping', 'weight', 'type', 'extra_attributes', 'wholesale_pricing', 'wholesale_price', 'wholesalePriceString', 'categories','variant_attributes', 'default_category'
    ];
    protected $free_addons;
    protected $free_oils;
    protected $notes = [];
    protected $discounted_products = [];
    protected $cached_values = [];
    protected $summary = [];
    protected $order_date;
    protected $order_type = "normal";

    public function __construct($products = [], Customer $customer = null, array $data = [])
    {
        $this->products = collect($products);
        $this->customer = $customer;
        $this->data = $data;
        $this->free_addons = new Collection();
        $this->free_oils = new Collection();
        $this->order_date = Carbon::now();
    }

    public function getOrderType()
    {
        return $this->order_type;
    }

    /**
     * Load Cart Data
     */
    public function loadFromCart($cart)
    {
        $objValues = get_object_vars($cart);

        foreach ($objValues as $key => $value) {
            $this->$key = $value;
        }

        return $this;
    }

    public function setKeep($value = 0)
    {
        $this->keep = $value;

        return $this;
    }

    public function wholesaleDiscountTotal()
    {
        $customer = $this->customer;

        if (! $customer) {
            return 0;
        }

        if (! $customer->isWholesaler()) {
            return 0;
        }

        $discount_total = $this->products->reduce(function ($carry, $item) {
            return $carry + ($item->wholesale_pricing ? max(0, $item->price - $item->wholesale_price) * $item->qty : 0);
        });

        return $discount_total;
    }

    public function metWholesaleMinimum()
    {
        $minimum_order = collect(get_option('settings_wholesale'))->get('wholesale_minimum_order', 0);

        return $minimum_order <= $this->getSubTotal();
    }

    public function addNote($note)
    {
        array_push($this->notes, $note);

        return $this;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setSummary($summary)
    {
        $this->summary = ! is_array($summary) ? unserialize($summary) : $summary;

        return $this;
    }

    public function getSummary()
    {
        return is_array($this->summary) ? $this->summary : [];
    }

    /**
     * Order subtotal.
     *
     * @param mixed $only Products
     *
     * @return void
     */
    public function getSubTotal($only = null)
    {
        $sub_total = 0.00;

        $is_wholesaler = $this->customer && $this->customer->isWholesaler();

        foreach ($this->products as $product) {
            if (isset($only) && ! in_array($product->id, $only)) {
                continue;
            }

            if ($this->isFreeFirstMonthDiscount() && $product->type == 'subscription') {
                continue;
            }

            $price = ($is_wholesaler && $product->wholesale_pricing) ? $product->wholesale_price : $product->price;

            $sub_total += $price * max(1, abs($product->qty));
        }

        return $sub_total;
    }

    /**
     * Order subtotal.
     *
     * @return void
     */
    public function oilProductsSubTotal()
    {
        $sub_total = 0.00;

        $is_wholesaler = $this->customer && $this->customer->isWholesaler();

        foreach ($this->getOilProductsExcludingFreeOils() as $product) {
            $price = ($is_wholesaler && $product->wholesale_pricing) ? $product->wholesale_price : $product->price;

            $sub_total += $price * max(1, abs($product->qty));
        }

        return $sub_total;
    }

    public function getOilProductsExcludingFreeOils()
    {
        $free_oils_id = $this->free_oils->pluck('id')->toArray();

        $oils = collect([]);

        foreach ($this->products as $product) {
            if (! str_contains($product->categories->pluck('name'), 'Oils') || in_array($product->id, $free_oils_id)) {
                continue;
            }
            $oils->push($product);
        }

        return $oils;
    }

    public function getFreeOilSubTotal()
    {
        $sub_total = 0.00;

        $is_wholesaler = $this->customer && $this->customer->isWholesaler();

        foreach ($this->free_oils as $free_oil) {
            $price = ($is_wholesaler && $free_oil->wholesale_pricing) ? $free_oil->wholesale_price : $free_oil->price;

            $sub_total += $price * max(1, abs($free_oil->qty));
        }

        return $sub_total;
    }

    /**
     * Get Discountable Sub Total.
     *
     * @param array $only
     *
     * @return float
     */
    public function getDiscountableSubTotal($only = null, bool $subscription_only = null)
    {
        return $this->getSubTotal($only);
    }

    public function getBonusTotal()
    {
        return collect($this->getBonus())->sum('price');
    }

    public function getFreeAddonsTotal()
    {
        return $this->free_addons->reduce(function ($carry, $item) {
            return $carry + $item['price'];
        });
    }

    public function getShippablePriceTotal()
    {
        $shippable_price_total = 0.00;

        foreach ($this->products as $product) {
            if ($product->shipping != 1) {
                continue;
            }

            $shippable_price_total += $product->price * max(1, abs($product->qty));
        }

        return $shippable_price_total;
    }

    public function getShippableWeightTotal()
    {
        $shippable_weight_total = 0.00;

        foreach ($this->products as $product) {
            if ($product->shipping != 1) {
                continue;
            }

            $shippable_weight_total += (float) $product->weight * max(1, abs($product->qty));
        }

        return $shippable_weight_total;
    }

    public function getTaxPercentage()
    {
        if ($this->customer && $this->customer->tax_exempt) {
            return 0;
        }

        return app(TaxCalculator::class)->getTaxByLocation($this->getShippingAddress()['country'] ?? 'US', $this->getShippingAddress()['zip'] ?? null, $this->getShippingAddress()['region'] ?? null);
    }

    public function isValid()
    {
        if (empty($this->products)) {
            return false;
        }

        if (is_null($this->customer)) {
            return false;
        }

        return true;
    }

    public function getDiscount()
    {
        return $this->discount_applied;
    }

    public function getGiftCard()
    {
        return $this->gift_card_applied;
    }

    public function getProductById($product_id)
    {
        return $this->products->firstWhere('id', $product_id);
    }

    public function update($product, $quantity, $checkdiscount = true)
    {
        $this->clearDiscountedProducts();

        $quantity = is_numeric($quantity) ? (int) $quantity : 1;

        if ($quantity <= 0) {
            return $this->remove($product);
        }

        if (! $this->getProductById($product->id)) {
            return $this->add($product, $quantity);
        }

        $this->products = $this->products->map(
            function ($item) use ($product, $quantity) {
                if ($item->id === $product->id) {
                    $item->qty = $quantity;
                }

                return $item;
            }
        );

        if ($checkdiscount) {
            $this->checkDiscount();
        }

        return $this;
    }

    public function add(ProductInterface $product, $quantity = 1, $checkdiscount = true)
    {
        $this->clearDiscountedProducts();

        if ($quantity <= 0) {
            return $this->remove($product);
        }

        if (! $product->canAccessByTags()) {
            return $this;
        }

        if ($product->type == 'subscription') {
            //remove existing subscription
            $this->products = $this->products->reject(function ($item) {
                return $item->type === 'subscription';
            });

            //Quantity is always 1

            $quantity = 1;
        }

        $product->qty = $quantity;

        if ($found = $this->getProductById($product->id)) {
            return $this->update($found, $found->qty + $quantity);
        } else {
            $this->products->push((object) array_merge($product->only(
                $this->fields
            ), ['qty' => $quantity]));
        }

        if ($checkdiscount) {
            $this->checkDiscount();
        }

        return $this;
    }

    /**
     * Add the plan product to the cart.
     *
     * @param ProductInterface $product
     *
     * @return static
     */
    public function addPlan(ProductInterface $product, $cycle = null, $checkdiscount = true)
    {
        if (! $product->hasPlans()) {
            return $this;
        }

        //Remove the cart item that is similar to the added plan
        $this->products = $this->products->reject(function ($item) use ($product) {
            return $item->id == $product->id;
        });

        $plan = (object) $product->parseCycle($cycle);

        $this->products->push((object) array_merge($product->only(
            $this->fields
        ), [
            'qty' => 1,
            'price' => (float) $plan->deposit,
            'priceString' => sprintf('%s%s', config('cart.currency')['symbol'], (float) $plan->deposit),
            'wholesale_pricing' => false,
            'plan' => $plan,
        ]));

        if ($checkdiscount) {
            $this->checkDiscount();
        }

        return $this;
    }

    public function remove(ProductInterface $product)
    {
        $this->clearDiscountedProducts();

        $this->products = $this->products->reject(function ($item) use ($product) {
            return $item->id === $product->id;
        });

        $this->checkDiscount();

        return $this;
    }

    public function applyDiscount($discount, $checkDiscountEligibility = true)
    {
        $this->discount_applied = null;
        $this->discounted_products = [];
        $this->cached_values = [];

        if (is_string($discount)) {
            $discount = Discount::where(['code' => $discount, 'active' => 1])->first();
        }

        if (! $discount) {
            return $this;
        }

        $discount->setOrder($this);

        if (! $discount || ($checkDiscountEligibility && ! $discount->orderPassed())) {
            return $this;
        }

        //$discount->compute();

        $this->discount_applied = $discount;

        $this->maybeDiscountFreeAddon();

        return $this;
    }

    public function addFreeOil(ProductInterface $product)
    {
        $this->free_oils->push($product);

        return $this;
    }

    public function getFreeOils()
    {
        return $this->free_oils;
    }

    public function clearFreeOils()
    {
        $this->free_oils = new Collection();

        return $this;
    }

    private function maybeDiscountFreeAddon()
    {
        $this->free_addons = new Collection();

        if (! $this->discount_applied) {
            return $this;
        }

        $this->maybeTieredFreeAddon();
        //$this->maybeReferralGift();

        if (! in_array($this->discount_applied->type, ['free_addon', 'fixed_amount', 'percentage', 'subscription_box'])) {
            return $this;
        }

        $addon_products = $this->discount_applied->getAddonProducts();

        if ($addon_products) {
            foreach ($addon_products as $key => $product) {
                $this->free_addons->push(array_merge(['qty' => 1], $product->only($this->fields)));
            }
        }

        return $this;
    }

    public function maybeTieredFreeAddon()
    {
        if (
            $this->discount_applied->type != 'tiered_discount'
            || ! in_array($this->discount_applied->options['tiered_discount']['type'], ['addon', 'percentage_addon'])
        ) {
            return $this;
        }

        $sub_total = (float) $this->discount_applied->applicableOrderSubTotal();
        $addons = [];
        $tiered_min = 0;

        foreach ($this->discount_applied->options['tiered_discount']['tiers'] as $index => $tier) {
            if ((float) $tier['min'] > $sub_total || $tiered_min > $tier['min']) {
                continue;
            }

            $tiered_min = (float) $tier['min'];

            $addons = $tier['free_addons'] ?? $this->discount_applied->options['tiered_discount']['free_addons'][$index] ?? [];
        }

        $found_products = Product::whereIn('id', $addons)->with(['metas'])->get();

        if ($found_products) {
            foreach ($found_products as $key => $product) {
                $this->free_addons->push($product->only($this->fields));
            }
        }
    }

    public function maybeReferralGift()
    {
        if ($this->discount_applied->type != 'referral') {
            return $this;
        }

        $gift = Product::where('sku', config('app.referral_gift_product'))->with(['metas'])->first();

        if ($gift) {
            $this->free_addons->push($gift->only($this->fields));
        }
    }

    public function getFreeAddons()
    {
        return $this->free_addons;
    }

    public function applyGiftCard($gift_card)
    {
        if (is_string($gift_card)) {
            $gift_card = GiftCard::with('orderItem.product')->where(['code' => $gift_card])->first();
        } else {
            $gift_card->load(['orderItem.product']);
        }

        $this->gift_card_applied = $gift_card;

        return $this;
    }

    public function deleteDiscount()
    {
        $this->discount_applied = null;
        $this->discounted_products = [];
        $this->cached_values = [];

        $this->maybeDiscountFreeAddon();

        return $this;
    }

    public function deleteGiftCard()
    {
        $this->gift_card_applied = null;

        return $this;
    }

    public function overrideDiscount(array $discount_override)
    {
        $this->discount_override = $discount_override;
    }

    public function getDiscountOverride()
    {
        return $this->discount_override;
    }

    /**
     * Get the discount total.
     *
     * @return float $discount_total
     */
    public function getDiscountTotal()
    {
        //if (!isset($this->cached_values['discount_total'])) {

        $discount = 0;

        if ($this->discount_override) {
            $discount += (float) $this->discount_override['amount'];
        }

        if ($this->discount_applied) {
            $discount += $this->discount_applied->setOrder($this)->compute();
        }

        $this->cached_values['discount_total'] = $discount;
        //}

        return $this->cached_values['discount_total'];
    }

    /**
     * Get the gift_card_total total.
     *
     * @return float $gift_card_total
     */
    public function getGiftCardTotal()
    {
        if (! $this->gift_card_applied) {
            return 0;
        }

        $before = (float) $this->getTotalBeforeGiftCard();

        return $this->gift_card_applied->remaining > $before ? $before : $this->gift_card_applied->remaining;
    }

    public function setCustomer(Customer $customer)
    {
        $this->customer = $customer;

        return $this;
    }

    public function getCustomer()
    {
        return $this->customer;
    }

    public function setData(array $data)
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public function setShippingMethod($shipping_method)
    {
        $this->shipping_method = $shipping_method;

        return $this;
    }

    public function getOrderDate()
    {
        if (! isset($this->order_date)) {
            $this->order_date = Carbon::now();
        }

        return $this->order_date;
    }

    public function build()
    {
        if (! $this->isValid()) {
            return false;
        }

        //Check if the discount still applies, remove if not.
        $this->checkDiscount();

        $order = new Order($this->data['checkout'] ?? []);
        $order->order_name = isset($this->data['order_name']) ? $this->data['order_name'] : $this->customer->id . time();

        $order->currency = currency()['id'];
        $order->subtotal_price = $this->getSubTotal();
        $order->total_tax = $this->getTaxTotal();
        $order->tax_percentage = $this->getTaxPercentage();
        $order->total_price = $this->getGrandTotal();
        $order->total_shipping = $this->getTotalShipping();
        $order->total_discounts = $this->getOverallDiscounts();
        $order->total_weight = $this->getShippableWeightTotal();
        $order->requested_shipping_service = $this->maybeExpidited();
        $order->processed_at = Carbon::now();
        $order->buyer_accepts_marketing = $this->data['buyer_accepts_marketing'] ?? true;
        $order->ip_address = $this->data['ip_address'] ?? null;
        $order->payment_details = $this->data['payment_details'] ?? null;
        $order->discount_details = $this->getDiscountDetails();
        $order->client_details = $this->data['client_details'] ?? $this->getClientDetails();
        $order->customer()->associate($this->customer);
        $order->status = $this->getStatus();
        $order->note = implode(' | ', $this->notes);
        $order = $this->beforeSave($order);

        //Here we make the customer's email as default email if it is not provided in the checkout;
        if (! $order->email) {
            $order->email = $this->customer->email;
        }

        if ($this->hasSubscriptionProduct()) {
            $order->subscription = $this->getSubscriptionProduct()->name;
            $order->box_key = $this->getSubscriptionBoxKey();
        }

        $order->summary = $this->summary;

        $order->save();

        //Discounts

        //We will increment the "used" column
        if ($this->discount_applied) {
            $this->discount_applied->used = (int) $this->discount_applied->used + 1;
            $this->discount_applied->save();
            $discount_data = ['ip_address' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? request()->ip()];

            if (! empty(@$this->discount_applied->options['campaign'])) {
                $discount_data['campaign'] = $this->discount_applied->options['campaign'];
            }

            $this->discount_applied->users()->save($this->customer, $discount_data);
        }

        //We will subsctract the gift card remaining value

        if ($this->gift_card_applied) {
            $this->gift_card_applied->remaining = max(0, $this->gift_card_applied->remaining - $this->getGiftCardTotal());
            $this->gift_card_applied->save();
        }

        //Address

        $order->shipping_address()->create($this->getShippingAddress());

        if (! $this->isBillingSameAsShipping()) {
            $order->billing_address()->create($this->getBillingAddress());
        }

        $this->createOrderItems($order);

        //FREE ADDONS
        if (count($this->getFreeAddons())) {
            $order->order_items()->createMany(
                $this->getFreeAddons()->map(function ($product) {
                    $product = (object) $product;

                    return [
                        'quantity' => 1,
                        'price' => $product->price,
                        'sale_price' => 0,
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'weight' => $product->weight,
                        'image_url' => $product->cover,
                    ];
                })->toArray()
            );
        }

        //Check if the order has a subscription product
        if ($this->is_checkout && $this->hasSubscriptionProduct()) {
            $subscription_sku = $this->getSubscriptionProduct()->sku;

            $subscriptionBuilder = $this->customer->account
                ->subscribe($subscription_sku == config('subscription.starter_box') ? config('subscription.monthly') : $subscription_sku);

            if ($this->hasCommitmentSubscription()) {
                $commitment_months = $subscription_sku == config('subscription.commitment_box_3') ? 3 : 6;
                $subscriptionBuilder->setCommitment($commitment_months);
            }

            //Check if we are subscribing this month box
            $now = $this->getOrderDate()->copy();
            $this_month_box = ShoppingBox::getByKey($now->format('F-Y'));

            if ($subscription_sku != config('subscription.starter_box') && ! $this_month_box->available()) {
                $subscriptionBuilder->setSchedule(1);
            }

            $subscription = $subscriptionBuilder->create();

            if ($subscription) {
                $subscription->maybeIncrementCommitment($order);

                if ($this->discount_applied && $this->discount_applied->type == 'subscription_box') {
                    $subscription->subscriptionDiscounts()->create([
                        'code' => $this->discount_applied->code,
                        'amount' => $this->discount_applied->options['discount_value_first_box'] ?? 0,
                        'type' => 'fixed',
                        'limit' => $this->discount_applied->options['number_of_future_boxes'] ?? 1,
                    ]);
                }

                if ($this->discount_applied && $this->discount_applied->isReferral50()) {
                    for ($i = 1; $i <= 4; ++$i) {
                        $subscription->SubscriptionDiscounts()->create(
                            [
                                'subscription_id' => $subscription->id,
                                'amount' => 10.00,
                                'code' => 'REFERRAL50OFF',
                                'type' => 'fixed',
                                'unlimited' => 0,
                                'limit' => 1,
                                'schedule' => $now->addMonth()->format('F Y'),
                            ]
                        );
                    }
                    ProcessReferral::dispatch($order, $this->discount_applied);
                }

                event(new \App\Events\Subscribed($subscription));
            }
        }

        //Check if the order has a installment plan
        if ($this->hasPlanProduct()) {
            $this->getPlanProducts()->each(function ($product) use ($order) {
                $found_product = Product::find($product->id);

                if ($found_product) {
                    (new InstallmentBuilder($order, $this->customer, $found_product, $product->plan->cycles, Carbon::now()->format('d')))->build();
                }
            });
        }

        if ($this->discount_applied && $this->discount_applied->isReferral()) {
            ProcessReferral::dispatch($order, $this->discount_applied);
        }

        //Maybe decrease subscription stock

        if (isset($order->box_key)) {
            try {
                ShoppingBox::getByKey($order->box_key)->decrementStock();
            } catch (\Exception $e) {
            }
        }

        return $order;
    }

    public function count()
    {
        return $this->products->sum('qty');
    }



    public function availableShippingMethods()
    {
        if (! isset($this->available_shipping_methods[$this->country])) {
            $this->available_shipping_methods[$this->country] = ShippingZone::where('countries', 'LIKE', '%"' . $this->country . '"%')
                ->orWHere('countries', '["*"]')
                ->orderBy('countries', 'DESC')->first();
        }

        if (! $this->available_shipping_methods[$this->country]) {
            return collect([]);
        }

        return $this->available_shipping_methods[$this->country]->getAvailableShipping($this);
    }

    public function getAvailbleShippingMethods()
    {
        return $this->available_shipping_methods;
    }

    protected function prepareOrderItems()
    {
        $wholesale_pricing = ($this->wholesaleDiscountTotal() > 0);

        $order_items = collect($this->orderProducts())->sortBy('name')->map(function ($product) use ($wholesale_pricing) {
            if ($wholesale_pricing && $product->wholesale_pricing) {
                $product->sale_price = $product->wholesale_price;
            }

            return $product;
        });

        return $order_items->merge(collect($this->getBonus())->map(function ($bonus) {
            $bonus->sale_price = 0;

            return $bonus;
        }))->map(function ($product) {
            return [
                'quantity' => max(1, isset($product->qty) ? (int) $product->qty : 1),
                'price' => $product->price,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $this->getOrderItemName($product),
                'weight' => $product->weight,
                'image_url' => $product->cover,
                'sale_price' => $product->sale_price ?? null,
            ];
        })->toArray();
    }

    protected function getOrderItemName($product)
    {
        if ($product->type == 'subscription' && ! in_array($product->sku, [config('subscription.starter_box')])) {
            return sprintf('%s:%s', $product->name, $this->getSubscriptionBoxKey());
        }

        return $product->name;
    }

    protected function createOrderItems(Order $order)
    {
        //Order Items

        $order_items = $this->prepareOrderItems();

        if (count($order_items)) {
            $order->order_items()->createMany($order_items);

            //Increase total sales for each Product

            collect($order_items)->each(function ($order_item) {
                if ($product = Product::where('sku', $order_item['sku'])) {
                    $product->increment('total_sales', $order_item['quantity']);
                }
            });
        }

        return $this;
    }

    public function getSubscriptionBoxKey()
    {
        $this_month_box = ShoppingBox::getByKey($this->getOrderDate()->format('F-Y'));

        return ($this->keep || $this_month_box->available() || in_array($this->getSubscriptionProduct()->sku, [config('subscription.starter_box')])) ? $this->getOrderDate()->format('F Y') : Carbon::parse('first day of next month')->format('F Y');
    }

    public function checkDiscount()
    {
        if ($this->getDiscount() && ! $this->getDiscount()->setOrder($this)->orderPassed()) {
            $this->deleteDiscount();
            $this->maybeDiscountFreeAddon();
        }
    }

    public function addDiscountedProducts($code = null, $type = 'percent', $value, $products = [])
    {
        if (empty($products)) {
            return;
        }

        foreach ($products as $product_id) {
            if (isset($this->discounted_products[$product_id]) && $this->discounted_products[$product_id]['code'] == $code && $this->discounted_products[$product_id]['type'] == $type) {
                $this->discounted_products[$product_id]['total'] += (float) $value;
            } else {
                $this->discounted_products[$product_id] = ['id' => $product_id, 'code' => $code, 'type' => $type, 'total' => $value];
            }
        }
    }

    public function clearDiscountedProducts()
    {
        $this->discounted_products = [];
        $this->cached_values = [];
    }

    public function getDiscountedProducts()
    {
        return $this->discounted_products;
    }

    public function orderProducts()
    {
        return $this->products;
    }

    public function getBillingAddress()
    {
        if (isset($this->data['checkout']) && isset($this->data['checkout']['billing_address'])) {
            return $this->data['checkout']['billing_address'];
        } elseif ($this->customer && $this->customer->default_address) {
            return $this->customer->default_address->toArray();
        } else {
            return ['region' => '', 'country' => 'US', 'zip' => null];
        }
    }

    public function getShippingAddress()
    {
        if (isset($this->data['checkout']) && isset($this->data['checkout']['shipping_address'])) {
            return $this->data['checkout']['shipping_address'];
        } elseif ($this->customer && $this->customer->default_address) {
            return $this->customer->default_address->toArray();
        } else {
            return ['region' => '', 'country' => 'US', 'zip' => null];
        }
    }

    public function setShippingAddress($shipping_address)
    {
        $this->data['checkout']['shipping_address'] = array_merge($this->data['checkout']['shipping_address'] ?? [], $shipping_address);

        return $this;
    }

    public function isBillingSameAsShipping()
    {
        return $this->data['checkout']['same_as_shipping_address'] ?? true;
    }

    public function getStatus()
    {
        return isset($this->data['status']) ? $this->data['status'] : Order::ORDER_PENDING;
    }

    public function defaultApplicableProducts()
    {
        return $this->getProducts();
    }

    public function getProducts()
    {
        return $this->products;
    }

    public function getShipping()
    {
        return $this->shipping_method;
    }

    public function getShippingMethodKey()
    {
        if (! $this->shipping_method) {
            return;
        }

        return $this->shipping_method->shipping_method_key;
    }

    public function getTotalShipping()
    {
        $customer = $this->customer;

        if ($this->wholesaleDiscountTotal() > 0) {
            return collect(get_option('settings_wholesale'))->get('wholesale_shipping_total', 0);
        }

        if ($this->hasFreeShippingProduct()) {
            return 0;
        }

        if (! $this->isFreeFirstMonthDiscount() && (! $this->shipping_method || $this->isFreeShipping())) {
            return 0;
        }

        $total = $this->shipping_method ? $this->shipping_method->rate : 0;

        //Free First month shipping
        if ($this->isFreeFirstMonthDiscount()) {
            $total += 9.99;
        }

        return $total;
    }

    public function hasFreeShippingDiscount()
    {
        return $this->discount_applied && ($this->discount_applied->type == 'free_shipping' || @$this->discount_applied->options['free_shipping']);
    }

    /**
     * Check if the order has a cbd product.
     *
     * @return boolean
     */
    public function hasCBDProduct()
    {
        foreach ($this->getProducts() as $product) {
            if (str_contains($product->categories->pluck('slug'), 'cbd')) {
                return true;
            }
        }

        return false;
    }

    public function hasFreeShippingProduct()
    {
        return
            (bool) $this->getProducts()->whereIn('sku', config('cart.free_shipping_products'))->count()
            || ($this->hasSubscriptionProduct()
                && ! $this->isFreeFirstMonthDiscount()
                && $this->getShippingAddress()['country'] == 'US');
    }

    public function isFreeFirstMonthDiscount()
    {
        return $this->discount_applied && $this->discount_applied->isFreeFirstMonth();
    }

    public function isFreeShipping()
    {
        return $this->hasFreeShippingDiscount()
            || ($this->shipping_method && $this->shipping_method->is_free)
            || $this->hasFreeShippingProduct();
    }

    public function isFreeShippingDiscount()
    {
        return $this->hasFreeShippingDiscount();
    }

    public function hasSubscriptionProduct()
    {
        return ! is_null($this->getSubscriptionProduct()) ? true : false;
    }

    public function hasCommitmentSubscription()
    {
        return $this->hasSubscriptionProduct()
            && in_array($this->getSubscriptionProduct()->sku, [config('subscription.commitment_box'), config('subscription.commitment_box_3')]);
    }

    public function hasNewSubscriptionProduct()
    {
        return $this->hasSubscriptionProduct() && $this->getSubscriptionProduct()->sku == config('subscription.monthly2019');
    }

    /**
     * Check if the order has a plan product.
     *
     * @return boolean
     */
    public function hasPlanProduct()
    {
        return count($this->getPlanProducts()) > 0;
    }

    /**
     * Get plan products.
     *
     * @return \Collection
     */
    public function getPlanProducts()
    {
        return $this->getProducts()->filter(function ($product) {
            return isset($product->plan);
        });
    }

    public function getSubscriptionProduct()
    {
        return $this->products->firstWhere('type', 'subscription');
    }

    public function getDetails($type = 'items')
    {
        if ($type == 'items') {
            $products = $this->getProducts()->map(function ($product) {
                if ($product->type == 'subscription' && ! in_array($product->sku, [config('subscription.starter_box')])) {
                    $key = explode(' ', $this->getSubscriptionBoxKey());
                    $product->alias = sprintf("%s's Essential Oil Recipe Box", $key[0]);
                }
                return $product;
            });


            return ($this->customer && $this->customer->isWholesaler()) ? $products : $products->map(function ($product) {
                return collect($product)->except(['wholesale_pricing', 'wholesale_price', 'wholesalePriceString']);
            });
        }

        return [];
    }

    public function getDiscountDetails()
    {
        return [
            'discount_override' => $this->discount_override,
            'discount' => $this->discount_applied ? $this->discount_applied->only(['type', 'code']) : null,
            'discount_total' => $this->getDiscountTotal(),
            'gift_card' => $this->gift_card_applied ? $this->gift_card_applied->only(['id', 'masked_code']) : null,
            'bonus' => collect($this->getBonus())->only(['id', 'sku', 'price']),
            'bonus_total' => $this->getBonusTotal(),
            'freeadons_total' => $this->getFreeAddonsTotal(),
            'wholesale_total' => $this->wholesaleDiscountTotal(),
        ];
    }

    public function getClientDetails()
    {
        return [
            'user_agent' => $this->data['user_agent'] ?? request()->server('HTTP_USER_AGENT'),
            'ip_address' => $this->data['ip_address'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? request()->ip(),
        ];
    }

    protected function beforeSave($order)
    {
        return $order;
    }

    public function requiresShipping()
    {
        if ($this->customer && $this->customer->isWholesaler()) {
            return false;
        }

        return $this->products->firstWhere('shipping', 1) ? true : false;
    }

    public function getOverallDiscounts()
    {
        return $this->getDiscountTotal() + $this->getGiftCardTotal();
    }

    public function getInstance()
    {
        return $this;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function getRegion()
    {
        return $this->region;
    }

    public function getDetail()
    {
        return [
            'data' => $this->getData(),
            'free_oils' => $this->getFreeOils(),
            'products' => $this->getProducts(),
            'country' => $this->getCountry(),
            'region' => $this->getRegion(),
            'shipping_method' => $this->getShipping(),
            'discount_applied' => $this->getDiscount(),
            'gift_card_applied' => $this->getGiftCard(),
            'discount_override' => $this->getDiscountOverride(),
            'free_addons' => $this->getFreeAddons(),
            'notes' => $this->getNotes(),
        ];
    }

    public function getCart()
    {
        $subscription = $this->getSubscriptionProduct();
        $discount =  $this->getDiscount();
        $expected_next_charge = null;

        if (! is_null($subscription)) {
            $this_month_box = \App\Shop\ShoppingBoxes\ShoppingBox::getByKey($this->getOrderDate()->format('F-Y'));

            $schedule = \App\Shop\Subscriptions\SubscriptionBuilder::parseConsolidateSchedule($this->getOrderDate()->format('d'));

            if ($subscription->sku != config('subscription.starter_box') && ! $this_month_box->available()) {
                $schedule = 1;
            }

            $expected_next_charge = Carbon::createFromFormat('F Y d H', sprintf('%s %d %d', $this->getSubscriptionBoxKey(), $schedule, 12));

            $expected_next_charge->addMonth(1);
        }

        $array = [
            'order_date' => $this->getOrderDate(),
            'customer' => $this->getCustomer(),
            'requires_shipping' => $this->requiresShipping(),
            'is_free_shipping' => $this->isFreeShipping(),
            'is_free_shipping_discount' => $this->isFreeShippingDiscount(),
            'count' =>  $this->count(),
            'items' =>  $this->getDetails('items'),
            'subtotal' => sprintf('%.2f', $this->getSubTotal()),
            'discount' => $discount ? $discount->only([
                'id',
                'type',
                'code',
                'options',
            ]) : null,
            'discount_subscription_box' => $discount && $discount->type == 'subscription_box' ? [
                'discount_value_first_box' => $discount->options['discount_value_first_box'],
                'discount_value_future_boxes' => $discount->options['discount_value_future_boxes'],
                'number_of_future_boxes' => $discount->options['number_of_future_boxes']
            ] : null,
            'gift_card' =>  $this->getGiftCard(),
            'available_shipping' =>  $this->availableShippingMethods(),
            'compare_price_total' =>  $this->getProducts()->reduce(function ($total, $product) {
                return $total + $product->compare_price_discount;
            }),
            'discount_total' => number_format($this->getDiscountTotal(), 2),
            'gift_card_total' => number_format($this->getGiftCardTotal(), 2),
            'overall_discount' => number_format($this->getOverallDiscounts(), 2),
            'grand_total' => sprintf('%.2f', $this->getGrandTotal()),
            'tax' => sprintf('%.2f', $this->getTaxTotal()),
            'currency' => currency(),
            'shipping' => $this->getShipping(),
            'shipping_total' => sprintf('%.2f', $this->getTotalShipping()),
            'addons' => $this->getFreeAddons(),
            'bonus' => $this->getBonus(),
            'subscription_product' => $this->getSubscriptionProduct(),
            'expected_next_charge' => $expected_next_charge ? $expected_next_charge->format('F jS') : null,
            'expected_next_box' => $expected_next_charge ? sprintf("%s's Essential Oil Recipe Box", $expected_next_charge->format('F')) : null,
            'bonus_discount' => sprintf('%.2f', $this->getBonusTotal()),
            'has_subscription' => ! is_null($subscription),
            'has_cbd_product' => $this->hasCBDProduct(),
            'is_commitment' => $this->hasCommitmentSubscription(),
            'is_free_box_option' => $this->isFreeFirstMonthDiscount(),
            'discounted_products' => $this->getDiscountedProducts(),
            'checkout' => [
                'shipping_method_key' => $this->getShippingMethodKey(),
                'shipping_address' => $this->getShippingAddress(),
                'billing_address' => $this->getBillingAddress(),
            ],
        ];

        if ($this->getCustomer() && $this->getCustomer()->isWholesaler()) {
            $array['wholesale_discount_total'] = sprintf('%.2f', $this->wholesaleDiscountTotal());

            if (! $this->metWholesaleMinimum() && ! $this->hasPlanProduct()) {
                $array['wholesale_message'] = wholesaleErrorMessage();
            }
        }

        return collect($array);
    }

    protected function maybeExpidited()
    {
        if ($this->products->firstWhere('sku', 'ACC-RUSH-SHIPPING')) {
            return 'EXPIDITED';
        }

        return $this->shipping_method ? $this->shipping_method->name : null;
    }

    public function skipExpired()
    {
        return false;
    }

    // add discounted_price to product object
    public function applyDiscountToProducts()
    {
        $discounted_products = $this->getDiscountedProducts();
        $this->products->map(function ($product) use ($discounted_products) {
            if (isset($discounted_products[$product->id])) {
                $discounted_product = $discounted_products[$product->id];

                // default to 'fixed' discount
                // $discount =  $discounted_product['total'] / $product->qty;
                // if discount type is 'percentage' calculate the total discount
                $discount = 0;

                if ($discounted_product['type']==='percentage') {
                    $discount = round((float) $product->price *  ($discounted_product['total']  / 100), 2);
                }
                $product->discounted_price = $product->price - $discount;
                return $product;
            }
        });
    }
}
