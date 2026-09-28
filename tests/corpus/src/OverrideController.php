<?php

declare(strict_types=1);

namespace App;

final class OverrideController
{
    public function __construct(private ServiceInterface $service) {}

    public function index(): void { $this->service->run(); }
}
