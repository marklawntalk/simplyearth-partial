<?php

namespace App\Traits;

trait HasPlans
{
    public function parseCycle($cycle)
    {
        $available_plans = collect($this->available_plans)->map(function ($plan) {

            return $this->convertPlantoArray($plan);
        });

        $match_options = $available_plans->filter(function ($option) use ($cycle) {
            return $option['cycles'] == $cycle;
        });

        if (count($match_options)) {
            return $match_options->first();
        }

        return $available_plans->first();
    }

    public function hasPlans()
    {
        return $this->available_plans && !empty(array_filter($this->available_plans));
    }

    /**
     * Returns first plan
     *
     * @return float
     */
    public function getFirstPlanAttribute()
    {
        return $this->convertPlantoArray($this->available_plans[0]);
    }

    private function convertPlantoArray($plan)
    {
        $array = explode('|', $plan);
        
        if (count($array) < 3) {
            return null;
        }

        return [
            'deposit' => $array[0],
            'cycles' => $array[1],
            'amount' => $array[2],
        ];
    }
}
