<?php

namespace App\Shop\Orders\DesktopShipper;

use GuzzleHttp\Client;

class DesktopShipperAPI
{
    protected $client;
    protected $token;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => config('app.env') === 'production' ? "https://io.desktopshipper.com" : "https://test_io.desktopshipper.com",
        ]);

        $this->token = env("DESKTOP_SHIPPER_TOKEN", "");

        if (empty($this->token)) {
            throw new DesktopShipperException("Missing Desktop Shipper Token.");
        }
    }

    public function getLatestShippedOrders($page = 1)
    {
        try {

            $parameters = "?page={$page}";

            $response = $this->client->get("/api/v1/Ship{$parameters}", [
                "headers" => $this->getHeaders(),
            ]);

        } catch (\Throwable $e) {
            throw $e;
        }

        return $this->handleResponse($response);
    }

    /**
     * Get Orders with status 'Shipped'
     * 
     * @param string Update min time
     * @param int page
     * @param int limit
     * 
     * return array|DesktopShipperException
     */
    public function getShippedOrders($filters = [], $query_token, $page = 1)
    {        
        if (!empty($query_token)) {
            return $this->handleResponse($this->client->get("/api/v1/Ship/Query?page={$page}&queryToken={$query_token}", [
                "headers" => $this->getHeaders(),
            ]));
        }

        return $this->handleResponse($this->client->post("/api/v1/Ship/Query?page={$page}", [
            "headers" => $this->getHeaders(),
            "json" => $filters,
        ]));
    }

    public function getOrder($id)
    {
        try {
            $response = $this->client->get("/api/v1/Order/{$id}", [
                "headers" => $this->getHeaders(),
            ]);
        } catch (\Throwable $e) {

            //IF no records found, desktop shipper returns 500 code.
            if ($e->getCode() == 500) {
                return [];
            } else {
                throw $e;
            }
        }

        return $this->handleResponse($response);
    }

    public function getOrders($status = null, $update_min = null, $update_max = null, $page = 1, $limit = 100, $created_min = null, $created_max = null)
    {
        try {

            $parameters = "?page={$page}&limit={$limit}";

            if ($update_min) {
                $parameters .= "&updatedAtMin=" . urlencode($update_min);
            }

            if ($update_max) {
                $parameters .= "&updatedAtMax=" . urlencode($update_max);
            }

            if ($created_min) {
                $parameters .= "&createdAtMin=" . urlencode($created_min);
            }

            if ($created_max) {
                $parameters .= "&createdAtMax=" . urlencode($created_max);
            }

            if ($status) {
                $parameters .= "&status={$status}";
            }

            $response = $this->client->get("/api/v1/Order{$parameters}", [
                "headers" => $this->getHeaders(),
            ]);
        } catch (\Throwable $e) {

            //IF no records found, desktop shipper returns 500 code.
            if ($e->getCode() == 500) {
                return [];
            } else {
                throw $e;
            }
        }

        return $this->handleResponse($response);
    }

    public function getOrderByOrderNumber($order_number)
    {
        try {

            $response = $this->client->post("/api/v1/Order/QuerySingle", [
                "headers" => $this->getHeaders(),
                'json' => ['marketOrderId' => $order_number]
            ]);
        } catch (\Throwable $e) {
            throw new DesktopShipperException($e->getMessage(), $e->getCode());
        }

        $json = $this->handleResponse($response);
        

        return $json;
    }

    public function createOrder($order_data)
    {
        try {

            $response = $this->client->post("/api/v1/Order/Create", [
                "headers" => $this->getHeaders(),
                'json' => $order_data
            ]);
        } catch (\Throwable $e) {
            throw new DesktopShipperException($e->getMessage(), $e->getCode());
        }

        $json = $this->handleResponse($response);

        if (!$json->wasSuccessful) {
            throw new DesktopShipperException("Order creation failed: {$json->responseMsg}", 400);
        }

        return $json;
    }

    protected function handleResponse($response)
    {
        if ($response->getStatusCode() != 200) {
            throw new DesktopShipperException($response->getReasonPhrase(), $response->getStatusCode());
        }

        return json_decode($response->getBody());
    }

    protected function getHeaders()
    {
        return [
            'Authorization' => "Bearer {$this->token}"
        ];
    }

    public function updateShipped($order_number)
    {
        try {
            $response = $this->client->post("/api/v1/Order/{$order_number}/Shipped", [
                "headers" => $this->getHeaders(),
            ]);
        } catch (\Throwable $e) {
            throw new DesktopShipperException($e->getMessage(), $e->getCode());
        }

        return $response;
    }

    public function updateCancelled($order_number)
    {
        try {
            $response = $this->client->post("/api/v1/Order/{$order_number}/Cancelled", [
                "headers" => $this->getHeaders(),
            ]);
        } catch (\Throwable $e) {
            throw new DesktopShipperException($e->getMessage(), $e->getCode());
        }

        return $response;
    }

    public function updateHold($order_number)
    {
        try {
            $response = $this->client->post("/api/v1/Order/{$order_number}/Hold", [
                "headers" => $this->getHeaders(),
            ]);
        } catch (\Throwable $e) {
            throw new DesktopShipperException($e->getMessage(), $e->getCode());
        }

        return $response;
    }

    public function updateNew($order_number)
    {
        try {
            $response = $this->client->post("/api/v1/Order/{$order_number}/New", [
                "headers" => $this->getHeaders(),
            ]);
        } catch (\Throwable $e) {
            throw new DesktopShipperException($e->getMessage(), $e->getCode());
        }

        return $response;
    }
}
