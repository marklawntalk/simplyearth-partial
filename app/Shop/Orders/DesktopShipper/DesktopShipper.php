<?php

namespace App\Shop\Orders\DesktopShipper;

use App\Shop\Orders\Order;
use Exception;

class DesktopShipper
{
    protected $order;

    public function setOrder(Order $order)
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Parse Order $object and prepare the order details for Desktop Shipper
     * 
     * @return static
     */
    public static function parseOrder(Order $order)
    {
        $self = new self;

        return $self->setOrder($order);
    }

    /**
     * Sends the order to DesktopShipper
     * 
     * @return mixed
     */
    public function createOrder()
    {
        $order_data = $this->prepareCreateOrderDetails();

        $response = (new DesktopShipperAPI)->createOrder($order_data);

        return $response;
    }


    /**
     * Prepares the create order details
     * 
     * @return static
     */
    public function prepareCreateOrderDetails()
    {

        $order_details = [
            'marketOrderId' => $this->order->order_number,
            'marketPrimaryKey' => $this->order->order_items->first()->sku,
            'shipMethod' => $this->order->requested_shipping_service ?? 'Not specified',
            //'fromAddress' => $this->fromAddress(),
            'weight' => $this->order->total_weight,
            'marketPublicNotes' => $this->order->note,
            'marketPrivateNotes' => $this->order->note,
            'orderSpecialInstructions' => $this->order->note,
            'orderShippingAmount' => $this->order->total_shipping,
            'orderTotalAmount' => $this->order->total_price,
            'orderDate' => $this->order->processed_at->format('m/d/Y g:i A'),
            'items' => $this->orderItems(),
        ];

        if ($this->order->shipping_address) {
            $order_details['shipAddress'] = $this->orderShippingAddress();
        }

        if ($this->order->billing_address) {
            $order_details['billingAddress'] = $this->orderBillingAddress();
        }


        $order_data = ['orders' => [$order_details]];

        return $order_data;
    }

    /**
     * Returns Order Items details
     * 
     * @return array
     * 
     */
    public function orderItems()
    {
        $order_items = [];

        foreach ($this->order->order_items as $order_item) {

            $image_url = config('app.url') . $order_item->image_url;

            $order_items[] = [
                "quantityOrdered" => $order_item->quantity,
                "marketSKU" => $order_item->sku,
                "marketProductKey" => $order_item->sku,
                "marketTitle" => $order_item->name,
                "unitPrice" => $order_item->sale_price ?: $order_item->price,
                "brand" => "Simply Earth",
                "manufacturer" => "Simply Earth",
                "weight" => $order_item->weight,
                "productImageURL" => $image_url,
            ];
        }

        return $order_items;
    }

    /**
     * Returns the store address
     * 
     * @return array
     */
    public function fromAddress()
    {

        $store_address = app('settings')['store_address'] ?? [];

        return [
            "name" =>  $store_address['name'] ?? "Simply Earth",
            "address1" => $store_address['address'],
            "city" => $store_address['city'],
            "postalCode" => $store_address['zip'],
            "state" => $store_address['region'],
            "countryCode" => $store_address['country'],
            "phone" => $store_address['phone'],
        ];
    }

    /**
     * Returns the shipping address details
     * 
     * @return array
     */
    public function orderShippingAddress()
    {
        $shipping_address = $this->order->shipping_address;

        return [
            "name" => $shipping_address->name,
            "company" => $shipping_address->company,
            "address1" => $shipping_address->address1,
            "address2" => $shipping_address->address2,
            "city" => $shipping_address->city,
            "postalCode" => $shipping_address->zip,
            "state" => $shipping_address->region,
            "countryCode" => $shipping_address->country,
            "phone" => $shipping_address->phone,
            "email" => $shipping_address->email,
        ];
    }

    /**
     * Returns the billing address details
     * 
     * @return array
     */
    public function orderBillingAddress()
    {
        $billing_address = $this->order->billing_address;

        return [
            "name" => $billing_address->name,
            "company" => $billing_address->company,
            "address1" => $billing_address->address1,
            "address2" => $billing_address->address2,
            "city" => $billing_address->city,
            "postalCode" => $billing_address->zip,
            "state" => $billing_address->region,
            "countryCode" => $billing_address->country,
            "phone" => $billing_address->phone,
            "email" => $billing_address->email,
        ];
    }

    public function updateStatus($status)
    {
        if (!isset($this->order->desktopshipper_id)) {
            $ds_order_number = $this->getDSOrderNumber();
        } else {
            $ds_order_number = $this->order->desktopshipper_id;
        }

        if (!$ds_order_number) {
            return;
        }

        switch (strtolower($status))
        {
            case 'shipped';

                $response = (new DesktopShipperAPI)->updateShipped($ds_order_number);

            break;

            case 'on-hold':
            case 'hold':
            case 'on_hold':    

                $response = (new DesktopShipperAPI)->updateHold($ds_order_number);
            break;

            case 'cancelled':
            case 'canceled':

                $response = (new DesktopShipperAPI)->updateCancelled($ds_order_number);
            break;

            case 'processing':
            case 'new':

                $response = (new DesktopShipperAPI)->updateNew($ds_order_number);
            break;
        }
    }

    public function getDSOrderNumber()
    {
        $response = (new DesktopShipperAPI)->getOrderByOrderNumber($this->order->order_number);

        $order_number = @$response[0]->id;

        if (!empty($order_number) > 0) {
            $this->order->desktopshipper_id = $order_number;
            $this->order->save();
        }
        
        return $order_number;
    }
}
