<?php

namespace App\Listeners;

use App\Jobs\CheckCustomerPassword;
use App\Jobs\KlaviyoBoxInfo;
use App\Jobs\KlaviyoIdentify;
use App\Jobs\KlaviyoSubscriptionStarted;
use App\Jobs\KlaviyoTrack;
use App\Jobs\WebhookPushAll;
use App\Mail\MailCommitMentStopAutoRenew;
use App\Mail\MailSubscriptionCancelled;
use App\Mail\MailSubscriptionContinue;
use App\Mail\MailSubscriptionGifted;
use App\Mail\MailSubscriptionPaused;
use App\Mail\MailSubscriptionRestarted;
use App\Mail\MailSubscriptionSkipped;
use App\Mail\MailSubscriptionUnskipped;
use App\Mail\Registered as RegisteredMail;
use Illuminate\Support\Facades\Mail;

class CustomerEventSubscriber
{
    public function onRegistered($event)
    {
        KlaviyoIdentify::dispatch($event->account->customer);

        Mail::to($event->account)->send(new RegisteredMail($event->account->customer));

        WebhookPushAll::dispatch('customer.registered', array_merge(
            $event->account->customer->only(['email', 'first_name', 'last_name']),
            []
        ));
    }

    public function onSubscribed($event)
    {
        //Mail::to($event->subscription->owner->email)->send(new SubscribedMail($event->subscription));
        KlaviyoSubscriptionStarted::dispatch($event->subscription);
        CheckCustomerPassword::dispatch($event->subscription->owner)->delay(now()->addHours(12));
        WebhookPushAll::dispatch('customer.subscribed', array_merge(
            $event->subscription->owner->customer->only(['email', 'first_name', 'last_name']),
            $event->subscription->owner->customer->default_address ? [
                'shipping_first_name' => $event->subscription->owner->customer->default_address->first_name,
                'shipping_last_name' => $event->subscription->owner->customer->default_address->last_name,
                'shipping_address1' => $event->subscription->owner->customer->default_address->address1,
                'shipping_address2' => $event->subscription->owner->customer->default_address->address2,
                'shipping_city' => $event->subscription->owner->customer->default_address->city,
                'shipping_zip' => $event->subscription->owner->customer->default_address->zip,
                'shipping_region' => $event->subscription->owner->customer->default_address->region,
                'shipping_country' => $event->subscription->owner->customer->default_address->country,
            ] : [],
            $event->subscription->only(['plan'])
        ));
    }

    public function onSubscriptionCancelled($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner->email)->send(new MailSubscriptionCancelled($event->subscription));

        KlaviyoTrack::dispatch('subscription-cancelled', [
            'subscription' => $event->subscription,
        ]);

        WebhookPushAll::dispatch('customer.canceled', array_merge(
            $event->subscription->owner->customer->only(['email', 'first_name', 'last_name']),
            $event->subscription->only(['plan', 'cancel_reason'])
        ));
    }

    public function onSubscriptionRestarted($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner->email)->send(new MailSubscriptionRestarted($event->subscription));

        KlaviyoTrack::dispatch('subscription-restarted', [
            'subscription' => $event->subscription,
        ]);
    }

    public function onSubscriptionSkipped($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner)->send(new MailSubscriptionSkipped($event->subscription, $event->old_box, $event->new_box));
    }

    public function onSubscriptionContinue($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner)->send(new MailSubscriptionContinue($event->subscription, $event->stopped_date, $event->continue_date));
    }

    public function onSubscriptionUnskipped($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner)->send(new MailSubscriptionUnskipped($event->subscription));
    }

    public function onSubscriptionGifted($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner)->send(new MailSubscriptionGifted($event->subscription, $event->gift));
    }

    public function onCommitmentStopAutoRenew($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner)->send(new MailCommitMentStopAutoRenew($event->subscription));
    }

    public function onSubscriptionPaused($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);
        Mail::to($event->subscription->owner->email)->send(new MailSubscriptionPaused($event->subscription));

        KlaviyoTrack::dispatch('subscription-paused', [
            'subscription' => $event->subscription,
        ]);
    }

    public function onSubscriptionUnpaused($event)
    {
        KlaviyoBoxInfo::dispatch($event->subscription->owner->customer);

        KlaviyoTrack::dispatch('subscription-unpaused', [
            'subscription' => $event->subscription,
        ]);
    }

    public function onPasswordReset($event)
    {
        history('created_password', $event->user->customer->id);
    }

    public function subscribe($events)
    {
        $events->listen(
            'App\Events\Registered',
            'App\Listeners\CustomerEventSubscriber@onRegistered'
        );

        $events->listen(
            'App\Events\Subscribed',
            'App\Listeners\CustomerEventSubscriber@onSubscribed'
        );

        $events->listen(
            'App\Events\SubscriptionCancelled',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionCancelled'
        );

        $events->listen(
            'App\Events\SubscriptionRestarted',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionRestarted'
        );

        $events->listen(
            'App\Events\SubscriptionSkipped',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionSkipped'
        );

        $events->listen(
            'App\Events\SubscriptionContinue',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionContinue'
        );

        $events->listen(
            'App\Events\SubscriptionUnskipped',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionUnskipped'
        );

        $events->listen(
            'App\Events\SubscriptionGifted',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionGifted'
        );

        $events->listen(
            'App\Events\CommitmentStopAutoRenew',
            'App\Listeners\CustomerEventSubscriber@onCommitmentStopAutoRenew'
        );

        $events->listen(
            'Illuminate\Auth\Events\PasswordReset',
            'App\Listeners\CustomerEventSubscriber@onPasswordReset'
        );

        $events->listen(
            'App\Events\SubscriptionPaused',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionPaused'
        );

        $events->listen(
            'App\Events\SubscriptionUnpaused',
            'App\Listeners\CustomerEventSubscriber@onSubscriptionUnpaused'
        );
    }
}
