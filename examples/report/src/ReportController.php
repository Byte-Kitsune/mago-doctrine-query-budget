<?php

declare(strict_types=1);

namespace App;

final class ReportController
{
    public function __construct(private ReportServiceInterface $service) {}

    public function index(): void
    {
        $this->service->load();
        $this->service->load();
    }
}
