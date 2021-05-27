<?php

namespace App\Traits;

use App\Exceptions\PaymentException;
use Braintree\CreditCard as BraintreeCreditCard;
use Braintree\Customer as BraintreeCustomer;
use Braintree\PaymentMethod;
use Braintree\PayPalAccount;
use Braintree\Transaction as BraintreeTransaction;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

trait Billable
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
    public function charge($amount, array $options = [])
    {
        if ($this->hasSquareId()) {
            return $this->chargeSquare($amount, $options);
        }

        $customer = $this->asBraintreeCustomer();

        $response = $this->chargeBraintreeCustomer($customer, $amount, $options);

        if (!$response->success) {

            $error_message = '';

            foreach ($response->errors->deepAll() as $error) {
                $error_message += $error->message . '|';
            }

            throw new PaymentException($error_message);
        }

        return $response;
    }

    public function chargeAndSave($token, $amount, array $options = [])
    {
        //Has Existing braintree ID
        if ($this->hasBraintreeId()) {
            $customer = $this->getBraintreeCustomer($token);

            return $this->chargeBraintreeCustomer($customer, $amount, $options);
        }

        $skip_advanced_fraud_checking = !config('app.enable_advanced_fraud_tools');
        
        if (!empty(config('app.fraud_whitelist_account_ids'))) {
            if (in_array($this->id, explode(',', config('app.fraud_whitelist_account_ids')))) {
                $skip_advanced_fraud_checking = true;
            }
        }

        $data = array_replace_recursive(
            [
                'amount' => $amount,
                'paymentMethodNonce' => $token,
                'customer' => [
                    'firstName' => Arr::get(explode(' ', $this->name), 0),
                    'lastName' => Arr::get(explode(' ', $this->name), 1),
                    'email' => $this->email,
                ],
                'options' => [
                    'submitForSettlement' => true,
                    'storeInVaultOnSuccess' => true,
                    'skipAdvancedFraudChecking' => $skip_advanced_fraud_checking,
                ],
            ],
            $options
        );

        $response = BraintreeTransaction::sale($data);

        if (!$response->success) {
            Log::info((string) $response);
            throw new Exception('Braintree was unable to perform a charge: ' . $response->message);
        }

        $this->updateBraintreeDetailByCustomer($response->transaction->vaultCustomer());

        return $response;
    }

    /**
     * Charge braintree Customer.
     *
     * @param BraintreeCustomer $customer
     * @param float             $amount
     * @param array             $options
     *
     * @return void
     */
    public function chargeBraintreeCustomer(BraintreeCustomer $customer, $amount, array $options = [])
    {
        $response = BraintreeTransaction::sale(array_replace_recursive([
            'amount' => $amount,
            'paymentMethodToken' => $this->paymentMethod($customer)->token,
            'options' => [
                'submitForSettlement' => true,
                'skipAdvancedFraudChecking' => true,
            ],
            'recurring' => true,
        ], $options));

        if (!$response->success) {
            Log::info((string) $response);
            throw new Exception('Braintree was unable to perform a charge: ' . $response->message, $response->transaction->processorResponseCode);
        }

        return $response;
    }

    /**
     * Expiring Cards.
     *
     * @return void
     */
    public static function scopeExpiringCards($query, $start_date = null, $end_date = null)
    {
        if (is_string($start_date)) {
            $start_date = Carbon::parse($start_date . ' 00:00:00');
        }

        if (!isset($end_date)) {
            $end_date = $start_date;
        }

        if (is_string($end_date)) {
            $end_date = Carbon::parse($end_date . ' 23:59:59');
        }

        $creditCards = BraintreeCreditCard::expiringBetween($start_date->timestamp, $end_date->timestamp);

        $expiring = [];
        foreach ($creditCards as $card) {
            if ($card->isDefault()) {
                $expiring[] = $card->customerId;
            }
        }

        return $query->whereIn('braintree_id', $expiring);
    }

    /**
     * Update customer's credit card.
     *
     * @param string $token
     * @param array  $options
     *
     * @return void
     *
     * @throws \Exception
     */
    public function updateCard($token, array $options = [], $customer = null)
    {
        $customer = $customer ?: $this->asBraintreeCustomer();

        $verify_card = true;

        if (!empty(config('app.fraud_whitelist_account_ids'))) {
            $fraud_whitelist = explode(',', config('app.fraud_whitelist_account_ids'));
            $verify_card = !in_array($this->id, $fraud_whitelist);
        }

        $response = PaymentMethod::create(
            array_replace_recursive([
                'customerId' => $customer->id,
                'paymentMethodNonce' => $token,
                'options' => [
                    'makeDefault' => true,
                    'verifyCard' => $verify_card,
                ],
            ], $options)
        );

        if (!$response->success) {
            $error_message = $this->parseErrorMessage($response);
            Log::error('Account ' . $this->id . ' - ' . (string) $response);
            throw new Exception('Unable to create a payment method');
        }

        $paypalAccount = $response->paymentMethod instanceof PaypalAccount;

        $this->forceFill([
            'paypal_email' => $paypalAccount ? $response->paymentMethod->email : null,
            'card_brand' => $paypalAccount ? null : $response->paymentMethod->cardType,
            'card_last_four' => $paypalAccount ? null : $response->paymentMethod->last4,
            'square_id' => null,
            'expiring_notified_at' => null,
        ])->save();
    }

    private function parseErrorMessage(\Braintree\Result\Error $response)
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
     * Get the default payment method for the customer.
     *
     * @return array
     */
    public function paymentMethod($customer = null)
    {
        $customer = $customer ?: $this->asBraintreeCustomer();

        foreach ($customer->paymentMethods as $paymentMethod) {
            if ($paymentMethod->isDefault()) {
                return $paymentMethod;
            }
        }
    }

    /**
     * Create a Braintree customer for the given model.
     *
     * @param string $token
     * @param array  $options
     *
     * @return \Braintree\Customer
     *
     * @throws \Exception
     */
    public function createAsBraintreeCustomer($token, array $options = [])
    {
        $response = BraintreeCustomer::create(
            array_replace_recursive([
                'firstName' => Arr::get(explode(' ', $this->name), 0),
                'lastName' => Arr::get(explode(' ', $this->name), 1),
                'email' => $this->email,
                'paymentMethodNonce' => $token,
                'creditCard' => [
                    'options' => [
                        'makeDefault' => true,
                        'verifyCard' => true,
                    ],
                ],
            ], $options)
        );

        if (!$response->success) {

            Log::info((string) $response);

            throw new Exception('Unable to process payment method: ' . $response->message);
        }

        $this->updateBraintreeDetailByCustomer($response->customer);

        return $response->customer;
    }

    public function updateBraintreeDetailByCustomer(BraintreeCustomer $customer)
    {
        $this->braintree_id = $customer->id;

        $paymentMethod = $this->paymentMethod($customer);

        $paypalAccount = $paymentMethod instanceof PayPalAccount;

        $this->forceFill([
            'braintree_id' => $customer->id,
            'paypal_email' => $paypalAccount ? $paymentMethod->email : null,
            'card_brand' => !$paypalAccount ? $paymentMethod->cardType : null,
            'card_last_four' => !$paypalAccount ? $paymentMethod->last4 : null,
            'square_id' => null,
            'expiring_notified_at' => null,
        ])->save();

        return $this;
    }

    /**
     * Get the Braintree customer for the model.
     *
     * @return \Braintree\Customer
     */
    public function asBraintreeCustomer()
    {
        return BraintreeCustomer::find($this->braintree_id);
    }

    /**
     * Determine if the entity has a Braintree customer ID.
     *
     * @return bool
     */
    public function hasBraintreeId()
    {
        return !is_null($this->braintree_id);
    }

    public function getBraintreeCustomer($token = null, array $options = [])
    {
        if (!$this->braintree_id) {
            $customer = $this->createAsBraintreeCustomer($token, $options);
        } else {
            $customer = $this->asBraintreeCustomer();

            if ($token) {
                $this->updateCard($token, [], $customer);
                $customer = $this->asBraintreeCustomer(); //refresh customer data
            }
        }

        return $customer;
    }

    public function updateOrCreateCard($token)
    {
        $this->getBraintreeCustomer($token);

        return $this;
    }
}
