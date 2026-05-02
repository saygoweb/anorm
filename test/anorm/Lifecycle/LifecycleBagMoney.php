<?php

namespace Anorm\Test\Lifecycle;

class LifecycleBagMoney
{
    public $amount;
    public $currency;

    public function __construct($amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }
}
