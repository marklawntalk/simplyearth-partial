<?php

namespace App\Shop\Orders;

use App\Shop\Customers\Customer;
use App\Shop\Orders\Order;
use App\Shop\Products\Product;
use Illuminate\Support\Carbon;

class InstallmentBuilder
{
    protected $order;

    protected $customer;

    protected $product;

    protected $cycle;

    protected $schedule;

    protected $next_schedule_date;

    public function __construct($order = null, $customer, Product $product, $cycle, $schedule = null)
    {
        $this->order = $order;

        $this->customer = $customer;

        $this->product = $product;

        $this->cycle = $cycle;

        $this->schedule = $schedule ?? 1;

        $this->next_schedule_date = Carbon::create(null, null, $this->schedule)->addMonth(1)->format('Y-m-d');
    }

    public function build()
    {

        if (!$this->product->hasPlans()) {
            throw new \Exception('Product does not have available installment options');
        }

        $cycle_option = (object) $this->product->parseCycle($this->cycle);

        return $this->customer->installmentPlans()->create([
            'deposit' => $cycle_option->deposit,
            'account_id' => $this->customer->account->id,
            'plan' => $this->product->sku,
            'amount' => (float) $cycle_option->amount,
            'cycles' => (int) $cycle_option->cycles,
            'schedule' => min(28, (int) $this->schedule),
            'next_schedule_date' => $this->next_schedule_date,
            'order_id' => $this->order ? $this->order->id : null,
        ]);
    }
}
