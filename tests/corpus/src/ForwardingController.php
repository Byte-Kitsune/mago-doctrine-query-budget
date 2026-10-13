<?php

declare(strict_types=1);

namespace App;

final class DetailsService
{
    public function getDetails(): int { return 1; }
}

final class ForwardingController
{
    public function __construct(private readonly DetailsService $details) {}
    public function get(): int { return $this->details->getDetails(); }
}

final class AssignedForwardingController
{
    private DetailsService $details;
    public function __construct(DetailsService $details) { $this->details = $details; }
    public function get(): int { return $this->details->getDetails(); }
}
