<?php

namespace App\Shop\Orders;

use App\Exceptions\PaymentException;
use App\Shop\Customers\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class InstallmentPlan extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'product',
    ];

    protected $appends = ['summary'];

    public function scopeIncomplete($query)
    {
        return $query->whereRaw('paid_cycles < cycles');
    }

    public function scopeFailedChargeable($query)
    {
        return $query->incomplete()->where([
            ['status', 'active'],
            ['failed_attempts', '>', 0]
        ]);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function charge()
    {
        try {

            if (!config('app.disable_charge')) {
                $this->account->charge($this->amount);
            }

        } catch (PaymentException | \Exception $e) {

            Log::warning($e->getMessage());

            return $this->afterFailedCharge();

        }

        return $this->afterSuccessfulCharge();
    }

    /**
     * Called after successfulcharge
     *
     * @return static
     */
    private function afterSuccessfulCharge()
    {
        $this->paid_cycles++;
        $this->next_schedule_date = Carbon::now()->addMonth(1)->format('Y-m-d');
        $this->failed_attempts = 0;
        $this->save();

        $this->account->customer->removeTags(['installment-failed-charge', 'installment-incomplete']);

        history('charge', $this->id, [
            'amount' => $this->amount,
        ], 'payment_plan');

        return $this;
    }

    private function afterFailedCharge()
    {
        $this->failed_attempts++;

        switch ($this->failed_attempts) {
            case 1:
                $this->next_schedule_date = Carbon::now()->addDays(2)->format('Y-m-d');
                break;

            case 2:
                $this->next_schedule_date = Carbon::now()->modify('next saturday')->format('Y-m-d');
                break;
            case 3:
                $this->next_schedule_date = Carbon::now()->modify('next saturday')->format('Y-m-d');
                break;
            case 4:
                $this->next_schedule_date = Carbon::now()->modify('next saturday')->modify('next saturday')->format('Y-m-d');
                break;
            default:
                $this->account->customer->assignTags('installment-incomplete');
                $this->status = 'incomplete';
                break;
        }

        $this->save();

        \Mail::to($this->account)->send(new \App\Mail\FailedChargePlan($this->account, $this->failed_attempts));

        history('failed_charge', $this->id, [
            'amount' => $this->amount,
        ], 'payment_plan');

        //Assign failed tag
        $this->account->customer->assignTags('installment-failed-charge');

        return $this;
    }

    public function getSummaryAttribute()
    {
        $summary = [[
            'intro' => sprintf('Initial Payment - (%s)', $this->created_at->format('M j')),
            'status' => 'PAID',
            'amount' => '$'.(float)$this->deposit,
        ]];

        
        $charge_date = Carbon::create($this->created_at->format('Y'), $this->created_at->format('m'), $this->schedule);
        for ($i = 1; $i <= $this->cycles; $i++) {
            $charge_date->addMonth(1);

            $summary[] = [
                'intro' => sprintf('%s Installment - (%s)', addOrdinalNumberSuffix($i), $charge_date->format('M j')),
                'status' => $i <= $this->paid_cycles ? 'PAID' : 'PENDING',
                'amount' => '$'.(float)$this->amount,
            ];
        }

        return $summary;
    }
}
