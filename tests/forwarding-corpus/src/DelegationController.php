<?php

declare(strict_types=1);
namespace Example;
use Doctrine\DBAL\Connection;

abstract class AbstractEndpoint
{
    protected function json(array $data): array { return $data; }
}
interface BypassAccountMarker {}
final class TimingEvent {}
final class SharedTimer
{
    public function start(string $name): void {}
    public function stop(string $name): TimingEvent { return new TimingEvent(); }
}
final class LeafFacade
{
    public function __construct(private Connection $connection) {}
    public function getDetails(): int { $this->connection->executeQuery('SELECT 1'); return 1; }
}
final class SideAggregator
{
    public function __construct(private LeafFacade $facade) {}
    public function getDetails(): int { return $this->facade->getDetails(); }
}
final class DetailsOrchestrator
{
    public function __construct(private LeafFacade $facade, private SideAggregator $side, private SharedTimer $timer) {}
    public function getDetails(): int
    {
        $this->timer->start('details');
        $value = $this->facade->getDetails() + $this->side->getDetails() + $this->facade->getDetails();
        $this->timer->stop('details');
        return $value;
    }
}
final class DetailsUseCase
{
    public function __construct(private DetailsOrchestrator $orchestrator) {}
    public function getDetails(): int { return $this->orchestrator->getDetails(); }
}
final class ResponseMapper
{
    public function __construct(private LeafFacade $facade) {}
    public function getDetails(): int { return $this->facade->getDetails(); }
}

final class Flow01Controller extends AbstractEndpoint implements BypassAccountMarker
{
    public function __construct(private DetailsUseCase $useCase, private ResponseMapper $mapper, private SharedTimer $timer) {}
    public function get(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow02Controller extends AbstractEndpoint
{
    private DetailsUseCase $useCase; private ResponseMapper $mapper; private SharedTimer $timer;
    public function __construct(DetailsUseCase $useCase, ResponseMapper $mapper, SharedTimer $timer) { $this->useCase = $useCase; $this->mapper = $mapper; $this->timer = $timer; }
    public function handle(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow03Controller extends AbstractEndpoint
{
    public function __construct(private DetailsUseCase $useCase, private ResponseMapper $mapper, private SharedTimer $timer) {}
    public function getFooter(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow04Controller extends AbstractEndpoint implements BypassAccountMarker
{
    private DetailsUseCase $useCase; private ResponseMapper $mapper; private SharedTimer $timer;
    public function __construct(DetailsUseCase $useCase, ResponseMapper $mapper, SharedTimer $timer) { $this->useCase = $useCase; $this->mapper = $mapper; $this->timer = $timer; }
    public function getDetails(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow05Controller extends AbstractEndpoint
{
    public function __construct(private DetailsUseCase $useCase, private ResponseMapper $mapper, private SharedTimer $timer) {}
    public function submit(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow06Controller extends AbstractEndpoint
{
    private DetailsUseCase $useCase; private ResponseMapper $mapper; private SharedTimer $timer;
    public function __construct(DetailsUseCase $useCase, ResponseMapper $mapper, SharedTimer $timer) { $this->useCase = $useCase; $this->mapper = $mapper; $this->timer = $timer; }
    public function download(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow07Controller extends AbstractEndpoint implements BypassAccountMarker
{
    public function __construct(private DetailsUseCase $useCase, private ResponseMapper $mapper, private SharedTimer $timer) {}
    public function get(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow08Controller extends AbstractEndpoint
{
    private DetailsUseCase $useCase; private ResponseMapper $mapper; private SharedTimer $timer;
    public function __construct(DetailsUseCase $useCase, ResponseMapper $mapper, SharedTimer $timer) { $this->useCase = $useCase; $this->mapper = $mapper; $this->timer = $timer; }
    public function handle(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow09Controller extends AbstractEndpoint
{
    public function __construct(private DetailsUseCase $useCase, private ResponseMapper $mapper, private SharedTimer $timer) {}
    public function getFooter(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow10Controller extends AbstractEndpoint implements BypassAccountMarker
{
    private DetailsUseCase $useCase; private ResponseMapper $mapper; private SharedTimer $timer;
    public function __construct(DetailsUseCase $useCase, ResponseMapper $mapper, SharedTimer $timer) { $this->useCase = $useCase; $this->mapper = $mapper; $this->timer = $timer; }
    public function getDetails(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow11Controller extends AbstractEndpoint
{
    public function __construct(private DetailsUseCase $useCase, private ResponseMapper $mapper, private SharedTimer $timer) {}
    public function submit(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class Flow12Controller extends AbstractEndpoint
{
    private DetailsUseCase $useCase; private ResponseMapper $mapper; private SharedTimer $timer;
    public function __construct(DetailsUseCase $useCase, ResponseMapper $mapper, SharedTimer $timer) { $this->useCase = $useCase; $this->mapper = $mapper; $this->timer = $timer; }
    public function download(): array
    {
        $this->timer->start('endpoint');
        $value = $this->useCase->getDetails();
        $mapped = $this->mapper->getDetails();
        $this->timer->stop('endpoint');
        return $this->json([$value, $mapped]);
    }
}

final class RealCycleA
{
    public function __construct(private RealCycleB $next) {}
    public function getDetails(): int { return $this->next->getDetails(); }
}
final class RealCycleB
{
    public function __construct(private RealCycleA $next) {}
    public function getDetails(): int { return $this->next->getDetails(); }
}
final class RealCycleController
{
    public function __construct(private RealCycleA $useCase) {}
    public function getDetails(): int { return $this->useCase->getDetails(); }
}
