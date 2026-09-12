<?php

namespace Drupal\domain_menu_system_block\Plugin\Block;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Menu\MenuActiveTrailInterface;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\system\Plugin\Block\SystemMenuBlock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core's menu block, with domain_menu_access filtering applied.
 *
 * domain_menu_access ships block plugins for its own block type and for
 * menu_block, and each adds `checkDomain` to the manipulator list. Core's
 * SystemMenuBlock hardcodes its manipulators (SystemMenuBlock::build()), so a
 * site using core menu blocks never gets the domain filtering and the links
 * leak onto every domain.
 *
 * See D8-2795.
 */
class DomainSystemMenuBlock extends SystemMenuBlock {

  /**
   * Whether domain_menu_access's manipulator is available.
   *
   * Resolved at construction rather than call time: the module is installed by
   * this module's deploy hooks rather than declared as a dependency (see
   * info.yml), so between enabling this module and running the hooks the
   * service does not exist.
   */
  protected bool $hasDomainManipulator;

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param array<string, mixed> $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_tree
   *   The menu tree service.
   * @param \Drupal\Core\Menu\MenuActiveTrailInterface $menu_active_trail
   *   The active trail service.
   * @param bool $has_domain_manipulator
   *   Whether domain_menu_access's manipulator service is registered.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MenuLinkTreeInterface $menu_tree,
    MenuActiveTrailInterface $menu_active_trail,
    bool $has_domain_manipulator = FALSE,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $menu_tree, $menu_active_trail);
    $this->hasDomainManipulator = $has_domain_manipulator;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   *
   * @return self
   *   The configured block plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu.link_tree'),
      $container->get('menu.active_trail'),
      $container->has('domain_menu_access.default_tree_manipulators'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The block render array.
   *
   * Core builds the tree and renders it in one method, so there is no seam to
   * insert a manipulator into and the parameter handling has to be mirrored
   * here — see treeParameters(). An earlier version called parent::build()
   * and discarded the result, which doubled the most expensive manipulator on
   * the site (menu_item_role_access loads an entity per link) for no benefit.
   */
  public function build() {
    // domain_menu_access is installed by this module's deploy hooks rather
    // than declared as a dependency (see info.yml), so between enabling this
    // module and running the hooks its manipulator does not exist. Defer to
    // core entirely rather than fataling.
    if (!$this->hasDomainManipulator) {
      return parent::build();
    }

    $parameters = $this->treeParameters();
    if ($parameters === NULL) {
      // Core returns an empty build when a level>1 block has no active trail
      // deep enough to re-root against.
      return [];
    }

    $tree = $this->menuTree->load($this->getDerivativeId(), $parameters);
    $tree = $this->menuTree->transform($tree, [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'domain_menu_access.default_tree_manipulators:checkDomain'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);

    return $this->menuTree->build($tree);
  }

  /**
   * Build the same tree parameters core would for this block's configuration.
   *
   * Mirrors the first half of SystemMenuBlock::build(). Keep in step with core
   * on major upgrades — the behaviours that matter are expand_all_items (which
   * uses a bare MenuTreeParameters plus the active trail, so nothing is
   * depth-limited) and the level/depth handling below it.
   *
   * Four account-menu blocks on this site set expand_all_items, so getting
   * this wrong silently truncates their menus.
   *
   * @return \Drupal\Core\Menu\MenuTreeParameters|null
   *   The parameters, or NULL when core would render nothing at all.
   */
  protected function treeParameters() {
    $menu_name = $this->getDerivativeId();

    if ($this->configuration['expand_all_items']) {
      $parameters = new MenuTreeParameters();
      $parameters->setActiveTrail($this->menuActiveTrail->getActiveTrailIds($menu_name));
    }
    else {
      $parameters = $this->menuTree->getCurrentRouteMenuTreeParameters($menu_name);
    }

    $level = $this->configuration['level'];
    $depth = $this->configuration['depth'];
    $parameters->setMinDepth($level);
    if ($depth > 0) {
      $parameters->setMaxDepth(min($level + $depth - 1, $this->menuTree->maxDepth()));
    }

    // For blocks starting below the top level, only show the active trail's
    // subtree, re-rooted at the configured level.
    if ($level > 1) {
      if (count($parameters->activeTrail) >= $level) {
        $menu_trail_ids = array_reverse(array_values($parameters->activeTrail));
        $parameters->setRoot($menu_trail_ids[$level - 1])->setMinDepth(1);
        if ($depth > 0) {
          $parameters->setMaxDepth(min($level - 1 + $depth - 1, $this->menuTree->maxDepth()));
        }
      }
      else {
        // Core returns [] here rather than rendering anything.
        return NULL;
      }
    }

    return $parameters;
  }

  /**
   * {@inheritdoc}
   *
   * The rendered menu varies by active domain, so declare the 'domain'
   * context, which keys on the negotiated domain id rather than the hostname.
   *
   * Note this does NOT currently collapse the per-hostname cache entries you
   * might expect. domain_menu_access's checkDomain() adds 'url.site' to the
   * access results it returns (DomainMenuLinkTreeManipulators.php), and that
   * bubbles into this block's render array — verified: a built block carries
   * user.permissions, url.site, user.roles. Since 'url.site' is finer-grained
   * than 'domain', the cache still fragments per hostname and every domain
   * here has several aliases.
   *
   * Declaring 'domain' is still correct and safe — it states the real
   * dependency rather than relying on a contrib module's incidental
   * cacheability. Collapsing the fragmentation would mean getting checkDomain
   * to use 'domain' instead, which is an upstream change.
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['domain']);
  }

}
