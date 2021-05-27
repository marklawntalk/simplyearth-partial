<?php

namespace App\Traits;

use App\Shop\Products\Product;

trait CanAddBonus
{
    protected $bonuses = [];

    /**
     * Retrieves bonuses
     *
     * @return void
     */

    public function addBonus($sku)
    {

        if (is_string($sku)) {
            $product = Product::where('sku', $sku)->first();
        } else {
            $product = $sku;
        }

        if ($product) {

            $this->bonuses[] = $this->fields ? (object) $product->only($this->fields) : $product;

        }

        return $this;
    }

    public function getBonus()
    {
        return $this->bonuses;
    }

    public function clearBonus()
    {
        $this->bonuses = [];

        return $this;
    }

    public function processBonus()
    {
        $this->clearBonus();

        if ($this->hasSubscriptionProduct() && (!$this->customer || $this->customer->canGetBonusBox())) {
            $this->addBonus(config('subscription.bonus_box'));
        }

        return $this;
    }
}
