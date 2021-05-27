<?php

namespace App\Jobs;

use App\Shop\Checkout\ConversionTracker;
use App\Shop\Conversion\Klaviyo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KlaviyoTrack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $event;
    protected $data;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($event, $data)
    {
        $this->event = $event;
        $this->data = $data;
        $this->tries = 1;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(Klaviyo $klaviyo)
    {
        if (env('DISABLE_KLAVIYO')) {
            return;
        }

        switch ($this->event) {

            case 'card-declined':

                if (isset($this->data['subscription'])) {
                    $subscription = $this->data['subscription'];
                    $klaviyo->track('Failed Charge', [
                        "customer_properties" => [
                            '$email' => $subscription->owner->email,
                            'Subscription' => $subscription->product->name,
                        ],
                        'properties' => [
                            'failed_since' => $subscription->failed_at,
                            'failed_attempts' => $subscription->failed_attempts,
                        ],
                    ]);
                }

                break;

            case 'subscription-cancelled':

                if (isset($this->data['subscription'])) {
                    $subscription = $this->data['subscription'];
                    $klaviyo->track('Subscription Cancelled', [
                        "customer_properties" => [
                            '$email' => $subscription->owner->email,
                            'Subscription' => $subscription->product->name,
                        ],
                    ]);
                }

                break;

            case 'subscription-restarted':

                if (isset($this->data['subscription'])) {
                    $subscription = $this->data['subscription'];
                    $klaviyo->track('Subscription Restarted', [
                        "customer_properties" => [
                            '$email' => $subscription->owner->email,
                            'Subscription' => $subscription->product->name,
                        ],
                    ]);
                }

                break;

            case 'subscription-paused':

                if (isset($this->data['subscription'])) {
                    $subscription = $this->data['subscription'];
                    $klaviyo->track('Subscription Paused', [
                        "customer_properties" => [
                            '$email' => $subscription->owner->email,
                            'Subscription' => $subscription->product->name,
                        ],
                    ]);
                }

                break;

            case 'subscription-unpaused':

                if (isset($this->data['subscription'])) {
                    $subscription = $this->data['subscription'];
                    $klaviyo->track('Subscription Unpaused', [
                        "customer_properties" => [
                            '$email' => $subscription->owner->email,
                            'Subscription' => $subscription->product->name,
                        ],
                    ]);
                }

                break;

            case 'order-fulfilled':

                if (isset($this->data['order'])) {
                    $klaviyo->track('Fulfilled Order', (new ConversionTracker($this->data['order']))->klaviyo(['time' => $this->data['order']->completed_at->timestamp]));
                }

                break;

            case 'email-invite-sent':
                
                $klaviyo->track('Email Invite Sent', [
                    "customer_properties" => [
                        '$email' => $this->data['customerEmail'],
                    ],
                    'properties' => [
                        'To' => $this->data['toEmail'],
                    ],
                ]);

                $klaviyo->updateCustomerProperties($this->data['customerEmail'], [
                    'Total Email Invites' => $this->data['customerInvitesCount'],
                    'Total Successful Referrals' => $this->data['customerSuccessfulCount'],
                ]);
                
                break;

            case 'reward':

                $klaviyo->track('Earned a Reward', [
                    "customer_properties" => [
                        '$email' => $this->data['customer']->email,
                    ],
                    "properties" => [
                        'reward' => $this->data['type'],
                        'quantity' => $this->data['quantity'],
                        'code' => $this->data['customer']->share_code,
                    ],
                ]);

                break;

            case 'referral-points-earned':

                $klaviyo->track('Referral Successful', [
                    "customer_properties" => [
                        '$email' => $this->data['customer']->email,
                    ],
                    "properties" => [
                        'remaining_referrals' => $this->data['customer']->remaining_referrals,
                        'code' => $this->data['customer']->share_code,
                        'Total Email Invites' => $this->data['customer']->invitations->count(),
                        'Total Successful Referrals' => $this->data['customer']->earned_invites->count(),
                    ],
                ]);

                break;

            case 'card-expiring':

                $klaviyo->track('Card Expiring', [
                    "customer_properties" => [
                        '$email' => $this->data['account']->email,
                    ],
                    "properties" => [                        
                        'expiration_date' => $this->data['expiration_date'],
                        'card_brand' => $this->data['account']->card_brand,
                        'card_last_four' => $this->data['account']->card_last_four,
                    ],
                ]);

                break;

            case 'card-expired':

                $klaviyo->track('Card Expired', [
                    "customer_properties" => [
                        '$email' => $this->data['account']->email,
                    ],
                    "properties" => [                        
                        'failed_attempts' => $this->data['failed_attempts'],
                        'expired_card_brand' => $this->data['account']->card_brand,
                        'expired_card_last_four' => $this->data['account']->card_last_four,
                    ],
                ]);

                break;
        }
    }

    public function getEvent()
    {
        return $this->event;
    }
}
