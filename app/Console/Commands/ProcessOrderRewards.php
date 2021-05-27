<?php

namespace App\Console\Commands;

use App\Jobs\KlaviyoTrack;
use App\Shop\Conversion\Klaviyo;
use App\Shop\Customers\Invitation;
use App\Shop\Orders\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessOrderRewards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reward:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for fraud duration completion to process rewards';

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
    public function handle(Klaviyo $klaviyo)
    {

        try {

            // Get Orders
            Order::where('status', Order::ORDER_COMPLETED)
                ->where('reward_processed', 0)
                ->whereNotNull('subscription')
                ->whereNotNull('customer_id')
                ->where('completed_at', '<=', Carbon::now()->subDays((int) config('app.fraud_check_duration'))->toDateTimeString())
                ->chunk(100, function ($orders) {
                    foreach ($orders as $key => $order) {

                        try {

                            //We perform invitation lookup here because some orders would not have the invitation_id field
                            $invitation = Invitation::whereIn('status', ['processing', 'waiting'])
                                ->where(function($query) use ($order) {
                                    $query
                                    ->orWhere('id', $order->invitation_id)
                                    ->orWhere('invitee_customer_id', $order->customer_id)
                                    ->orWhere('email', $order->customer->email);
                                })->first();

                            if ($invitation) {

                                $discountType = isset($order->discount_details['discount']['type']) ? $order->discount_details['discount']['type'] : null;

                                if (in_array($discountType, ['referral50']) || $order->customer->subscriptionOrders()->count() >= 3) {
                                    $invitation->referrer->addReferralCount(1);

                                    KlaviyoTrack::dispatch('referral-points-earned', [
                                        'customer' => $invitation->referrer,
                                    ]);

                                    $invitation->update(['status' => 'earned']);
                                }
                            }

                            $order->reward_processed = 1;
                            $order->save();

                        } catch (\Throwable $e) {

                            throw new \Exception('Order ID - '. $order->id.' '.$e->getMessage());

                        }

                    }
                });

        } catch (\Throwable $th) {
            Log::error('ProcessOrderRewards: '.$th->getMessage());
        }
    }
}
