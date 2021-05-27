<?php

namespace App\Traits;

trait OrderTotals
{
    abstract public function getSubTotal();

    abstract public function getTaxPercentage();

    abstract public function getTotalShipping();

    abstract public function getDiscountTotal();

    abstract public function getGiftCardTotal();

    abstract public function wholesaleDiscountTotal();

    public function getTotal()
    {
        return sprintf('%.2f', $this->getGrandTotal());
    }

    public function getTotalBeforeWholesale()
    {
        return $this->getSubTotal() - $this->getDiscountTotal();
    }

    public function getTotalBeforeGiftCard()
    {
        return $this->getTotalShipping() + $this->getSubTotal() + $this->getTaxTotal() - $this->getDiscountTotal();
    }

    public function getTaxTotal()
    {
        return $this->getTaxPercentage() * $this->getTotalBeforeWholesale();
    }

    public function getGrandTotal()
    {
        return sprintf('%.2f', max(0, $this->getTotalBeforeGiftCard() - $this->getGiftCardTotal()));
    }
}
