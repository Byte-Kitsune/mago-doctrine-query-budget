<?php

declare(strict_types=1);

namespace App;

final class CycleController
{
    public function recurse(): void { $this->recurse(); }

    public function guarded(int $remaining): void
    {
        if ($remaining <= 0) return;
        $this->guarded($remaining - 1);
    }
}
