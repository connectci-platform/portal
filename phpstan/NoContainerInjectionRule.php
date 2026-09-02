<?php

namespace Cyberteam\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags ContainerInterface injected directly into __construct().
 *
 * The service container should not be passed as a constructor argument because
 * it hides real dependencies. Use ContainerInjectionInterface::create() to
 * resolve services, then pass them individually to the constructor.
 *
 * @implements \PHPStan\Rules\Rule<\PhpParser\Node\Stmt\ClassMethod>
 */
class NoContainerInjectionRule implements Rule {

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    assert($node instanceof ClassMethod);

    if ($node->name->name !== '__construct') {
      return [];
    }

    $errors = [];
    foreach ($node->params as $param) {
      $type = $param->type;

      // Resolve the fully-qualified name from a Name node.
      $fqn = NULL;
      if ($type instanceof Node\Name\FullyQualified) {
        $fqn = implode('\\', $type->parts);
      }
      elseif ($type instanceof Node\Name) {
        // Resolve relative name against the current namespace.
        $fqn = $scope->resolveName($type);
      }

      if ($fqn === 'Symfony\\Component\\DependencyInjection\\ContainerInterface') {
        $paramName = $param->var instanceof Node\Expr\Variable
          ? '$' . $param->var->name
          : '(unknown)';
        $errors[] = RuleErrorBuilder::message(
          sprintf(
            'Constructor parameter %s in %s uses ContainerInterface directly. Inject specific services instead.',
            $paramName,
            $scope->getClassReflection()?->getName() ?? '(unknown class)',
          )
        )->identifier('cyberteam.noContainerInjection')->build();
      }
    }

    return $errors;
  }

}
