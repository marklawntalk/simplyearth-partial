<?php

namespace App\Jobs;

use App\Shop\Orders\DesktopShipper\DesktopShipper;
use App\Shop\Orders\DesktopShipper\DesktopShipperException;
use App\Shop\Orders\Order;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

class DesktopShipperNewOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;

        $this->tries = 3;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        //Skip if Desktop Shipper Token isnt available
        if (empty(env('DESKTOP_SHIPPER_TOKEN'))) {
            return;
        }

        try {

            $response = DesktopShipper::parseOrder($this->order)->createOrder();
            
        } catch (DesktopShipperException $e) {            
            $this->notifySlack($e);            
        } catch (\Throwable $e) {            
            $this->notifySlack($e);            
        }

        $ds_order_number = @$response->orderResults[0]->clientUniqueSequence;

        if (!empty($ds_order_number)) {
            $this->order->desktopshipper_id = $ds_order_number;
            $this->order->save();

            return;
        }

        $this->notifySlack(new Exception("DS did not return an order_number"));
    }

    protected function notifySlack($e)
    {
        if (config('slack.order_related')) {

            $message = "*Send to DS Failed Order :" . get_class($e) . ':' . $e->getCode() . ' ' . $e->getMessage();
            Notification::route('slack', config('slack.order_related'))
    ->notifyNow(new \App\Notifications\OrderRelatedNotification($this->order, $message));
        }
    }
}
