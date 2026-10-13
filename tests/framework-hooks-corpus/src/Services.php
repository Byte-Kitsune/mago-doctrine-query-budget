<?php
namespace HookExamples;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener as Listen;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\Connection;
#[Listen(event: 'application.ready', method: 'receive')]
final class ArrivalService {
    public function __construct(private Connection $db) {}
    public function receive(): void { $this->db->executeQuery('SELECT 1'); }
    public function helper(): void {}
}
#[Listen('kernel.request')]
final class DefaultListener {
    public function onKernelRequest(): void {}
    public function __invoke(): void { throw new \LogicException(); }
}
#[AsMessageHandler(null, null, null, 'process')]
final class MessageService {
    public function __construct(private Connection $db) {}
    public function process(): void { $this->db->executeStatement('UPDATE records SET state = 1'); }
}
final class Subscriber implements EventSubscriberInterface {
    public static function getSubscribedEvents(): array { return ['application.ready' => 'receive', 'application.done' => ['finish', 5], 'application.multiple' => [['first', 10], ['last', -1]]]; }
    public function receive(): void {}
    public function finish(): void {}
    public function first(): void {}
    public function last(): void {}
    public function unrelated(): void {}
}
abstract class BaseMiddleware implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {}
final class WorkerMiddleware extends BaseMiddleware {
    public function handle(\Symfony\Component\Messenger\Envelope $envelope, \Symfony\Component\Messenger\Middleware\StackInterface $stack): \Symfony\Component\Messenger\Envelope { return $envelope; }
}
final class Validation extends \Symfony\Component\Validator\ConstraintValidator {
    public function __construct(private Connection $db) {}
    public function validate(mixed $value, \Symfony\Component\Validator\Constraint $constraint): void { $this->db->executeQuery('SELECT 1'); }
}
#[AsDoctrineListener(event: 'postLoad')]
final class DatabaseListener {
    public function __construct(private Connection $db) {}
    public function postLoad(): void { $this->db->executeQuery('SELECT 1'); }
}
#[AsEntityListener(event: 'postPersist', method: 'afterWrite', entity: Entity::class)]
final class EntityListener {
    public function afterWrite(): void {}
}
#[\Doctrine\ORM\Mapping\Entity]
#[\Doctrine\ORM\Mapping\HasLifecycleCallbacks]
final class Entity {
    #[\Doctrine\ORM\Mapping\PostLoad]
    public function loaded(): void {}
}
final class LanguageHooks {
    public function __construct(private Connection $db) {}
    public function __serialize(): array { $this->db->executeQuery('SELECT 1'); return []; }
    public function __toString(): string { return 'value'; }
}
final class ConfiguredService {
    public function __construct(private Connection $db) {}
    protected function hiddenHandler(): void { $this->db->executeQuery('SELECT 1'); }
}
namespace HookExamples\Fake;
#[\HookExamples\Fake\AsEventListener]
final class NamedListener {
    public function ignored(): void {}
}
