<?php

namespace Omaresmael\Thread\Tests\Fixtures\Exceptions;

use RuntimeException;

class OrderFailed extends RuntimeException
{
    public function __construct(public string $orderId, public int $attempt)
    {
        parent::__construct("Order {$orderId} failed on attempt {$attempt}");
    }
}
