<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

/**
 * A display amount: the value in the store's display currency with the tax
 * it carries, as core's pricing amounts carry their adjustments.
 */
final class Amount
{
    public function __construct(
        public readonly float $value,
        public readonly float $tax = 0.0,
    ) {
    }

    public function times(float $quantity): self
    {
        return new self($this->value * $quantity, $this->tax * $quantity);
    }

    public static function sum(self ...$amounts): self
    {
        $value = 0.0;
        $tax = 0.0;
        foreach ($amounts as $amount) {
            $value += $amount->value;
            $tax += $amount->tax;
        }

        return new self($value, $tax);
    }
}
