<?php

namespace Anorm\Test\Lifecycle;

class LifecycleSameMoney
{
    public $amount;
    public $currency;

    public function __construct($amount, string $currency)
    {
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function isSame(LifecycleSameMoney $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
