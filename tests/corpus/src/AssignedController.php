<?php

declare(strict_types=1);

namespace App;

final class AssignedController
{
    private ServiceInterface $service;

    public function __construct(ServiceInterface $service)
    {
        $this->service = $service;
    }

    public function index(): void { $this->service->run(); }
}
