<?php
namespace Example;
final class TreeMapper {
    public function mapNode(array $node): array { return array_map($this->mapNode(...), $node); }
    public function reference(): \Closure { return $this->mapNode(...); }
    public function arrowTree(array $node): array { return array_map(fn(array $child) => $this->arrowTree($child), $node); }
    public function direct(array $node): array { return $this->direct($node); }
    public static function mapStatic(array $node): array { return array_map(self::mapStatic(...), $node); }
}
function mapFunction(array $node): array { return array_map(mapFunction(...), $node); }
final class CallableController {
    public function __construct(private TreeMapper $mapper) {}
    public function tree(array $node): array { return $this->mapper->mapNode($node); }
    public function reference(): \Closure { return $this->mapper->reference(); }
    public function arrowTree(array $node): array { return $this->mapper->arrowTree($node); }
    public function direct(array $node): array { return $this->mapper->direct($node); }
    public function staticTree(array $node): array { return TreeMapper::mapStatic($node); }
    public function functionTree(array $node): array { return mapFunction($node); }
}
