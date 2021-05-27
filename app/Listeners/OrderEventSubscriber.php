<?php

namespace App\Listeners;

use App\Jobs\DesktopShipperNewOrder;
use App\Jobs\KlaviyoOrder;
use App\Jobs\KlaviyoTrack;
use App\Jobs\OrderTax;
use App\Jobs\StampedOrder;
use App\Mail\OrderProcessed;
use App\Mail\OrderShipped;
use App\Shop\Orders\InstallmentPlan;
use App\Shop\Orders\Order;
use Illuminate\Support\Facades\Mail;
use App\Jobs\KlaviyoBoxInfo;
use App\Mail\OutForDelivery;

class OrderEventSubscriber
{
    public function onOrderCreated($event)
    {
        if ($event->order->customer->isWholesaler() && $event->order->customer->orders->count() == 1) {
            //Do not send the orderProcessed immediately for wholesaler's first order
            $event->order->needs_approval = 1;
            $event->order->save();
        } else {
            Mail::to($event->order)->send(new OrderProcessed($event->order));
        }

        if ($event->order->status == Order::ORDER_PROCESSING) {
            //Process cards if any
            $event->order->processGiftCards();
            
            if ($event->order->order_items->where('product.shipping', true)->count()) {            
                DesktopShipperNewOrder::dispatch($event->order);
            }
        }

        //Send order data to klaviyo
        KlaviyoOrder::dispatch($event->order);

        //Send order data to Stamped
        StampedOrder::dispatch($event->order);

        //Send tax to taxjar
        OrderTax::dispatch($event->order)->onQueue('normal');

        \App\Jobs\WebhookPushAll::dispatch('order.created', array_merge(
            $event->order->customer->only(['email', 'first_name', 'last_name']),
            [
                'order_number' => $event->order->order_number,
                'sub_total' => $event->order->subtotal_price,
                'total_price' => $event->order->total_price,
                'total_tax' => $event->order->total_tax,
                'total_discounts' => $event->order->total_discounts,
                'total_shipping' => $event->order->total_shipping,
            ]
        ));
    }

    public function onOrderStatusUpdated($event)
    {
        switch ($event->order->status) {
            case Order::ORDER_PROCESSING:
                
                //Process cards if any
                $event->order->processGiftCards();
                
                if ($event->order->order_items->where('product.shipping', true)->count()) {
                    DesktopShipperNewOrder::dispatch($event->order);
                } else {
                    //Mark order as complete if theres no product that requires shipping
                    $event->order->markAsCompleted();
                }

                \App\Jobs\WebhookPushAll::dispatch('order.paid', array_merge(
                    $event->order->customer->only(['email', 'first_name', 'last_name']),
                    [
                        'order_number' => $event->order->order_number,
                        'total_price' => $event->order->total_price,
                    ]
                ));

                break;

            case Order::ORDER_COMPLETED:

                KlaviyoTrack::dispatch('order-fulfilled', ['order' => $event->order]);

                if ($event->order->order_items->where('product.shipping', true)->count()) {   
                    $shipped_date = \App\Shop\Misc\DateTimeUtility::getNextOrderShippedDate();                 
                    //Email shipped date
                    Mail::to($event->order)->later($shipped_date, new OrderShipped($event->order));
                    
                    /*
                    //For subscription orders
                    if ($event->order->isSubscriptionPurchase()) {
                        //5 days after shipped
                        Mail::to($event->order)->later($shipped_date->copy()->addDays(5), new OutForDelivery($event->order));
                    }*/
                }

                //For subscription orders
                if ($event->order->isSubscriptionPurchase()) {
                    KlaviyoBoxInfo::dispatch($event->order->customer);
                }

                \App\Jobs\WebhookPushAll::dispatch('order.completed', array_merge(
                    $event->order->customer->only(['email', 'first_name', 'last_name']),
                    [
                        'order_number' => $event->order->order_number,
                        'total_price' => $event->order->total_price,
                    ]
                ));

                break;

            case Order::ORDER_CANCELLED:

                if ($installment_plan = InstallmentPlan::where('order_id', $event->order->id)->first()) {
                    $installment_plan->update(['status' => 'cancelled']);
                }

                \App\Jobs\DesktopShipperUpdateStatus::dispatch($event->order, 'cancelled');

                \App\Jobs\WebhookPushAll::dispatch('order.canceled', array_merge(
                    $event->order->customer->only(['email', 'first_name', 'last_name']),
                    [
                        'order_number' => $event->order->order_number,
                        'total_price' => $event->order->total_price,
                    ]
                ));

                break;
        }
    }

    public function subscribe($events)
    {
        $events->listen(
            'App\Events\OrderCreated',
            'App\Listeners\OrderEventSubscriber@onOrderCreated'
        );

        $events->listen(
            'App\Events\OrderStatusUpdated',
            'App\Listeners\OrderEventSubscriber@onOrderStatusUpdated'
        );
    }
}
