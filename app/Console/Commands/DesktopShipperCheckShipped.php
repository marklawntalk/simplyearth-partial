<?php

namespace App\Console\Commands;

use App\Events\OrderStatusUpdated;
use App\Shop\Orders\DesktopShipper\DesktopShipperAPI;
use App\Shop\Orders\DesktopShipper\DesktopShipperException;
use App\Shop\Orders\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DesktopShipperCheckShipped extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'desktopshipper:shipped {--page=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Desktop Shipper - Order Shipped Checker';

    protected $new_min_create_date;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {        
        $page = $this->option('page') ?: 1;
        $query_token = null;
        $filters = [];
        $now = Carbon::now();

        //get last order number
        $filters['minCreateDate'] = get_option('last_shipped_create_min_date');

        do {

            $search_query = (new DesktopShipperAPI)->getShippedOrders($filters, $query_token, $page);

            if (!$search_query || empty($search_query->packages)) {
                break;
            }

            $this->processOrders($search_query->packages);

            $query_token = $search_query->queryToken;
            $page = $search_query->nextPage;

            sleep(1);

        } while ($search_query->nextPage);

        //Save new latest order number
        set_option('last_shipped_create_min_date', $now->toISOString());
    }

    protected function processOrders($orders)
    {
        if (!$orders) {
            return;
        }

        foreach ($orders as $order) {

            if (in_array($order->status, ['ClosedAndVoid', 'Void'])) {
                continue;
            }

            $order_number = $order->marketOrderId ?? $order->customRef2;

            if ($order->marketReference != "Public Api") {
                continue;
            }

            $found_order = Order::getOrderByNumber($order_number);

            if (!$found_order) {
                continue;
            }

            // we will skip if the tracking number is already set
            if (!empty($found_order->tracking_number)) {
                continue;
            }

            $date_shipped = Carbon::parse($order->createDate) ?: now();

            $found_order->tracking_number = $order->trackingNumber;
            $found_order->shipping_carrier = $order->carrier;
            $found_order->shipping_date = $date_shipped;
            $found_order->status = Order::ORDER_COMPLETED;
            $found_order->completed_at = $date_shipped;
            $found_order->save();
            event(new OrderStatusUpdated($found_order));
        }
    }
}
