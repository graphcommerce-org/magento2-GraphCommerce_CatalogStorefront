<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

/** Transport-neutral publication operations; all engine and storage behavior belongs to the service. */
interface ViewPublicationInterface
{
    /** @return array<string, mixed> Committed checkpoint and whether configuration changes remain pending. */
    public function status(): array;

    /** @return array<string, mixed> Result of one finite, coalesced publication attempt. */
    public function process(): array;
}
