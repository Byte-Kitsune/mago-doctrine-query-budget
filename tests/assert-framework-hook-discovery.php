<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/NativeEngine.php'; require_once dirname(__DIR__) . '/src/NativeParser.php'; require_once dirname(__DIR__) . '/src/FrameworkHooks.php';
use ByteKitsune\MagoNative\FrameworkHooks;
$sources = [
 'Child.php' => '<?php namespace Neutral; final class Child extends Middle { public function handle():void {} }',
 'Middle.php' => '<?php namespace Neutral; abstract class Middle extends Base {}',
 'Base.php' => '<?php namespace Neutral; abstract class Base implements \\Symfony\\Component\\Messenger\\Middleware\\MiddlewareInterface {}',
 'Constants.php' => '<?php namespace Neutral; #[\\Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener(event: \\Symfony\\Component\\HttpKernel\\KernelEvents::REQUEST)] final class EventListener { public function onKernelRequest():void{} }',
 'Entity.php' => '<?php namespace Neutral; #[\\Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsEntityListener("postLoad", "loaded")] final class EntityListener { public function loaded():void{} }',
 'Dynamic.php' => '<?php namespace Neutral; #[\\Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler(method: Config::METHOD)] final class Dynamic { public function __invoke():void {} }',
];
$report = FrameworkHooks::discover($sources); $targets = array_column($report['hooks'], 'target'); sort($targets);
$expected = ['Neutral\\Child::handle','Neutral\\Dynamic::__invoke','Neutral\\EntityListener::loaded','Neutral\\EventListener::onKernelRequest']; sort($expected);
if ($targets !== $expected || count($report['unknown']) !== 1 || !str_contains($report['unknown'][0], 'Dynamic hook method')) throw new RuntimeException('Hook inheritance/constants/dynamic default guard failed: ' . json_encode($report));
foreach ($report['hooks'] as $hook) if ($hook['activation'] !== 'declaration-only') throw new RuntimeException('Unproven hook activation.');
foreach (['Invalid::', 'A::m::x', '../Class::run'] as $bad) {
 try { \ByteKitsune\MagoDoctrineQueryBudget\QueryBudgetExtension::create(hookEntrypoints:[$bad]); throw new LogicException('Invalid hook selector accepted'); } catch (InvalidArgumentException) {}
}
echo "Hook inheritance, constants, explicit dynamic methods and selector validation passed\n";
