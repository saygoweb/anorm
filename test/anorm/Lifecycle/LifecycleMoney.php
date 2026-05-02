<?php

namespace Anorm\Test\Lifecycle;

class LifecycleMoney
{
    public $amount;
    public $currency;

    public function __construct($amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function equals(LifecycleMoney $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
