<?php

namespace App\Traits;

use SquareConnect\ApiClient;
use SquareConnect\Model\Card;
use SquareConnect\Model\Customer as SquareCustomer;

trait BillableSquare
{
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param float $amount
     * @param array $options
     *
     * @return array
     *
     * @throws \Exception
     */
    public function chargeSquare($amount, array $options = [])
    {
        $customer = $this->asSquareCustomer();

        $response = $this->chargeSquareCustomer($customer, $amount, $options);

        return $response;
    }

    /**
     * Charge and save card.
     *
     * @param string $token
     * @param float  $amount
     * @param array  $options
     *
     * @return \SquareConnect\Model\CreatePaymentResponse
     */
    public function chargeAndSaveSquare($token, $amount, array $options = [])
    {
        //Has Existing square ID
        if ($this->hasSquareId()) {

            $customer = $this->asSquareCustomer();

            //IF token is available, update the card
            if (!is_null($token)) {
                $responseCard = $this->updateCardSquare($token, [], $customer, false);
                $customer->setCards([$responseCard->getCard()]);
            }
        }

        return $this->chargeSquareCustomer($customer ?? $this->createasSquareCustomer($token, []), $amount, $options);
    }

    /**
     * Charge braintree Customer.
     *
     * @param SquareCustomer $customer
     * @param float          $amount
     * @param array          $options
     *
     * @return \SquareConnect\Model\CreatePaymentResponse
     */
    public function chargeSquareCustomer(SquareCustomer $customer, $amount, array $options = [])
    {
        try {
            $body = new \SquareConnect\Model\CreatePaymentRequest(array_merge(
                [
                    'amount_money' => $this->parseMoney($amount),
                    'source_id' => collect($customer->getCards())->last()->getId(),
                    'idempotency_key' => uniqid(),
                    'customer_id' => $customer->getId(),
                    'buyer_email_address' => $this->email,
                ]
            ), $options);

            $payments_api = new \SquareConnect\Api\PaymentsApi(app()->make(ApiClient::class));
            $response = $payments_api->createPayment($body);
        } catch (\SquareConnect\ApiException $e) {
            $response = $e->getResponseBody();

            throw new \Exception($response->errors[0]->detail);
        }

        if (!empty($response->getErrors())) {
            $error = end($response->getErrors());
            throw new \Exception($error->getDetail());
        }

        return $response;
    }

    /**
     * Convert amount into square money.
     *
     * @param float  $amount
     * @param string $currency
     *
     * @return \SquareConnect\Model\Money
     */
    public function parseMoney($amount = 0, $currency = 'USD')
    {
        $money = new \SquareConnect\Model\Money();
        $money->setAmount(intval((float) sprintf('%.2f', $amount) * 100))->setCurrency($currency);

        return $money;
    }

    /**
     * Update customer's credit card.
     *
     * @param string $token
     * @param array  $options
     *
     * @return \SquareConnect\Model\CreateCustomerCardResponse $response
     *
     * @throws \Exception
     */
    public function updateCardSquare($token, array $options = [], SquareCustomer $customer = null)
    {
        $customer = $customer ?? $this->asSquareCustomer();

        $apiInstance = new \SquareConnect\Api\CustomersApi(app()->make(ApiClient::class));

        //SET CARD
        $body = new \SquareConnect\Model\CreateCustomerCardRequest([
            'card_nonce' => $token,
        ]);

        try {
            $response = $apiInstance->createCustomerCard($customer->getId(), $body);
        } catch (\SquareConnect\ApiException $e) {
            $response = $e->getResponseObject();

            throw new \Exception($response->errors[0]->detail);
        }

        $this->updateSquareDetailByCustomer($customer, $response->getCard());

        return $response;
    }

    private function parseErrorMessageSquare(\Braintree\Result\Error $response)
    {
        $verification = $response->verification;

        if ($verification) {
            if ($verification->status == 'gateway_rejected') {
                return $verification->gatewayRejectionReason;
            }

            return $verification->processorResponseText;
        }

        return $response->message;
    }

    /**
     * Create a Braintree customer for the given model.
     *
     * @param string $token
     * @param array  $options
     *
     * @return SquareCustomer
     *
     * @throws \Exception
     */
    public function createasSquareCustomer($token, array $options = [])
    {
        $apiInstance = new \SquareConnect\Api\CustomersApi(app()->make(ApiClient::class));
        $body = new \SquareConnect\Model\CreateCustomerRequest([
            'given_name' => $this->customer->first_name,
            'family_name' => $this->customer->last_name,
            'email_address' => $this->customer->email_address,
            'reference_id' => $this->customer->id,
        ]);

        try {
            $response = $apiInstance->createCustomer($body);
        } catch (\SquareConnect\ApiException $e) {
            $response = $e->getResponseBody();

            throw new \Exception($response->errors[0]->detail);
        }

        $customer = $response->getCustomer();

        $response = $this->updateCardSquare($token, [], $customer);

        if (is_a($response->getCard(), \SquareConnect\Model\Card::class)) {
            $customer->setCards([$response->getCard()]);
        }

        return $customer;
    }

    public function updateSquareDetailByCustomer(SquareCustomer $customer, Card $card = null)
    {
        $this->forceFill([
            'square_id' => $customer->getId(),
            'braintree_id' => null,
            'paypal_email' => null,
            'card_brand' => $card->getCardBrand(),
            'card_last_four' => $card->getLast4(),
            'expiring_notified_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Get the Braintree customer for the model.
     *
     * @return SquareCustomer
     */
    public function asSquareCustomer()
    {
        $apiInstance = new \SquareConnect\Api\CustomersApi(app()->make(ApiClient::class));

        try {
            $response = $apiInstance->retrieveCustomer($this->square_id);
        } catch (\SquareConnect\ApiException $e) {
            $response = $e->getResponseBody();

            throw new \Exception($response->errors[0]->detail);
        }

        return $response->getCustomer();
    }

    /**
     * Get Square customer ID.
     *
     * @return bool
     */
    public function getSquareId()
    {
        return $this->square_id;
    }

    /**
     * Determine if the entity has a Square customer ID.
     *
     * @return bool
     */
    public function hasSquareId()
    {
        return !is_null($this->square_id);
    }

    public function getSquareCustomer($token = null, array $options = [])
    {
        if (!$this->square_id) {
            return $this->createasSquareCustomer($token, $options);
        }

        $customer = $this->asSquareCustomer();

        if ($token) {
            $this->updateCardSquare($token, [], $customer);
        }

        return $customer;
    }

    public function chargeSquareNonce($token, $amount, array $options = [])
    {
        try {

            $money = new \SquareConnect\Model\Money();
            $money->setAmount(sprintf('%.2f', $amount) * 100)->setCurrency('USD');

            $body = new \SquareConnect\Model\CreatePaymentRequest(array_merge(
                [
                    'amount_money' => $money,
                    'source_id' => $token,
                    'idempotency_key' => uniqid(),
                    'buyer_email_address' => $this->email,
                ]
            ), $options);

            $payments_api = new \SquareConnect\Api\PaymentsApi(app()->make(\SquareConnect\ApiClient::class));
            $response = $payments_api->createPayment($body);
        } catch (\SquareConnect\ApiException $e) {
            $response = $e->getResponseBody();

            throw new \Exception($response->errors[0]->detail);
        }

        if (!empty($response->getErrors())) {
            $error = end($response->getErrors());
            throw new \Exception($error->getDetail());
        }

        return $response;
    }

    public function updateOrCreateCardSquare($token)
    {
        $this->getSquareCustomer($token);

        return $this;
    }
}
