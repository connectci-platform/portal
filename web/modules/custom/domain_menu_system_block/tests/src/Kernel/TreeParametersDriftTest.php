<?php

namespace Drupal\Tests\domain_menu_system_block\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\domain_menu_system_block\Plugin\Block\DomainSystemMenuBlock;
use Drupal\system\Plugin\Block\SystemMenuBlock;

/**
 * Guards DomainSystemMenuBlock::treeParameters() against core drift.
 *
 * Core builds its menu tree parameters inline in SystemMenuBlock::build(),
 * with no seam to reuse, so treeParameters() is a hand-copy of that logic.
 * It is correct against the core version present when it was written, but
 * nothing otherwise fails when a core upgrade changes the original — the
 * block would keep returning a valid render array built from the wrong
 * parameters, and the only symptom would be a menu quietly looking wrong.
 *
 * Four account-menu blocks on this site set expand_all_items, so that branch
 * in particular has live consequences.
 *
 * This test compares our copy against core's source. It is deliberately
 * coarse: it does not assert core's exact wording, only that the handful of
 * calls the copy depends on are still the ones core makes. If it fails after
 * a core update, re-read SystemMenuBlock::build() and reconcile
 * treeParameters() with it — the failure means "go and look", not
 * necessarily "core is wrong".
 *
 * @group domain_menu_system_block
 */
class TreeParametersDriftTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'block', 'menu_link_content', 'link', 'user'];

  /**
   * Core's build() still drives parameters the way our copy assumes.
   *
   * @dataProvider coreBehaviours
   */
  public function testCoreStillBuildsParametersTheSameWay(string $needle, string $why): void {
    $source = $this->coreBuildSource();

    $this->assertStringContainsString(
      $needle,
      $source,
      sprintf(
        "SystemMenuBlock::build() no longer contains '%s'.\n%s\n"
        . 'DomainSystemMenuBlock::treeParameters() copies core here, so reconcile the two. '
        . 'See D8-2795.',
        $needle,
        $why
      )
    );
  }

  /**
   * The pieces of core's parameter handling our copy reproduces.
   *
   * @return array<string, array{string, string}>
   *   Each case is a needle to find in core's build(), and why it matters.
   */
  public static function coreBehaviours(): array {
    return [
      'expand_all_items branch' => [
        "\$this->configuration['expand_all_items']",
        'Core decides between a bare MenuTreeParameters and the route-based ones on this flag.',
      ],
      'bare parameters when expanding' => [
        'new MenuTreeParameters()',
        'When expanding, core builds parameters from scratch so nothing is depth-limited.',
      ],
      'active trail when expanding' => [
        'setActiveTrail',
        'The expanded branch seeds the active trail rather than expanded parents.',
      ],
      'route-based parameters otherwise' => [
        'getCurrentRouteMenuTreeParameters',
        'The non-expanded branch defers to the menu tree service.',
      ],
      'minimum depth from level' => [
        'setMinDepth',
        'Core applies the block level as the minimum depth.',
      ],
      'maximum depth from depth' => [
        'setMaxDepth',
        'Core caps depth when the block configures one.',
      ],
      're-rooting below the top level' => [
        'setRoot',
        'For level > 1 core re-roots onto the active trail.',
      ],
    ];
  }

  /**
   * Our subclass overrides build() but keeps core's public shape.
   */
  public function testSubclassStillMatchesCoreSignature(): void {
    $this->assertTrue(
      is_subclass_of(DomainSystemMenuBlock::class, SystemMenuBlock::class),
      'DomainSystemMenuBlock must extend core SystemMenuBlock.'
    );

    $ours = new \ReflectionMethod(DomainSystemMenuBlock::class, 'build');
    $this->assertSame(
      DomainSystemMenuBlock::class,
      $ours->getDeclaringClass()->getName(),
      'DomainSystemMenuBlock must declare its own build(); if this fails the '
      . 'domain manipulator is no longer being applied at all.'
    );
  }

  /**
   * The configuration keys treeParameters() reads still exist with defaults.
   *
   * treeParameters() reads level, depth and expand_all_items unguarded. If core
   * ever drops or renames one, that is a notice and a wrong tree rather than a
   * hard failure, so pin them here.
   */
  public function testCoreStillDefinesTheConfigurationKeysWeRead(): void {
    // Instantiate core's class directly: the block manager may hand back our
    // subclass (hook_block_alter), and defaultConfiguration() is what core
    // itself defines.
    $block = new SystemMenuBlock(
      [],
      'system_menu_block:admin',
      ['provider' => 'system'],
      $this->container->get('menu.link_tree'),
      $this->container->get('menu.active_trail')
    );
    $defaults = $block->defaultConfiguration();

    foreach (['level', 'depth', 'expand_all_items'] as $key) {
      $this->assertArrayHasKey(
        $key,
        $defaults,
        sprintf(
          "SystemMenuBlock no longer defines '%s' in defaultConfiguration(). "
          . 'DomainSystemMenuBlock::treeParameters() reads it directly.',
          $key
        )
      );
    }
  }

  /**
   * Read the source of core's build() method.
   */
  protected function coreBuildSource(): string {
    $method = new \ReflectionMethod(SystemMenuBlock::class, 'build');
    $lines = file($method->getFileName());
    return implode('', array_slice(
      $lines,
      $method->getStartLine() - 1,
      $method->getEndLine() - $method->getStartLine() + 1
    ));
  }

}
