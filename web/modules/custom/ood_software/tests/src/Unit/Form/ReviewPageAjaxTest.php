<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Unit\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\ood_software\Form\ReviewPageForm;
use Drupal\Tests\UnitTestCase;

/**
 * What the page's AJAX buttons answer with.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Form\ReviewPageForm
 */
class ReviewPageAjaxTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Marks what it renders as rendered, as the real one does, so the
    // commands can collect the attachments.
    $renderer = new class() implements RendererInterface {

      /**
       * Marks the array rendered.
       *
       * @param array<mixed> $elements
       *   The render array.
       *
       * @return \Drupal\Component\Render\MarkupInterface
       *   The form's wrapper, empty.
       */
      public function renderRoot(&$elements): MarkupInterface {
        $elements['#printed'] = TRUE;
        $elements['#attached'] = $elements['#attached'] ?? [];
        return Markup::create('<div id="arv-review-form"></div>');
      }

      /**
       * As renderRoot().
       *
       * @param array<mixed> $elements
       *   The render array.
       *
       * @return \Drupal\Component\Render\MarkupInterface
       *   The form's wrapper, empty.
       */
      public function renderInIsolation(&$elements): MarkupInterface {
        return $this->renderRoot($elements);
      }

      /**
       * As renderRoot().
       *
       * @param array<mixed> $elements
       *   The render array.
       *
       * @return \Drupal\Component\Render\MarkupInterface
       *   The form's wrapper, empty.
       */
      public function renderPlain(&$elements): MarkupInterface {
        return $this->renderRoot($elements);
      }

      /**
       * Not used.
       *
       * @param string $placeholder
       *   The placeholder.
       * @param array<mixed> $elements
       *   The render array.
       *
       * @return array<mixed>
       *   The elements.
       */
      public function renderPlaceholder($placeholder, array $elements): array {
        return $elements;
      }

      /**
       * As renderRoot().
       *
       * @param array<mixed> $elements
       *   The render array.
       * @param bool $is_root_call
       *   Not used.
       *
       * @return \Drupal\Component\Render\MarkupInterface
       *   The form's wrapper, empty.
       */
      public function render(&$elements, $is_root_call = FALSE): MarkupInterface {
        return $this->renderRoot($elements);
      }

      /**
       * No render context.
       */
      public function hasRenderContext(): bool {
        return FALSE;
      }

      /**
       * Runs the callable.
       */
      public function executeInRenderContext(RenderContext $context, callable $callable): mixed {
        return $callable();
      }

      /**
       * Not used.
       *
       * @param array<mixed> $a
       *   One array.
       * @param array<mixed> $b
       *   Another.
       *
       * @return array<mixed>
       *   The first.
       */
      public function mergeBubbleableMetadata(array $a, array $b): array {
        return $a;
      }

      /**
       * Not used.
       *
       * @param array<mixed> $elements
       *   The render array.
       * @param mixed $dependency
       *   The dependency.
       */
      public function addCacheableDependency(array &$elements, $dependency): void {
      }

    };
    $container = new ContainerBuilder();
    $container->set('renderer', $renderer);
    \Drupal::setContainer($container);
  }

  /**
   * The form, without the services ajaxRefresh() does not use.
   */
  private function pageForm(): ReviewPageForm {
    $form = (new \ReflectionClass(ReviewPageForm::class))->newInstanceWithoutConstructor();
    $form->setStringTranslation($this->getStringTranslationStub());
    return $form;
  }

  /**
   * The rendered commands' names and their main argument.
   *
   * @param array<int, array<string, mixed>> $commands
   *   AjaxResponse::getCommands().
   *
   * @return array<int, string>
   *   Name, then method, text or selector.
   */
  private function summary(array $commands): array {
    return array_map(static fn (array $c): string => $c['command'] . ':' . ($c['method'] ?? $c['priority'] ?? '') . ':' . ($c['text'] ?? $c['selector'] ?? ''), $commands);
  }

  /**
   * A save replaces the form, says so politely and focuses the finding.
   *
   * @covers ::ajaxRefresh
   */
  public function testAjaxRefreshCommands(): void {
    $form = ['#type' => 'form'];
    $form_state = (new FormState())->set('arv_announce', 'Saved.')->set('arv_focus', 'finding-5');
    $response = $this->pageForm()->ajaxRefresh($form, $form_state);
    $this->assertSame([
      'insert:replaceWith:#arv-review-form',
      'announce:polite:Saved.',
      'focusFirst::#finding-5',
    ], $this->summary($response->getCommands()));
  }

  /**
   * A refused save is announced assertively, with its first error.
   *
   * @covers ::ajaxRefresh
   */
  public function testAjaxRefreshOnErrorIsAssertive(): void {
    $form = [
      '#type' => 'form',
      'prose' => [5 => ['#id' => 'edit-prose-5--x', '#parents' => ['prose', 5]]],
    ];
    $form_state = (new FormState())
      ->set('arv_focus', 'finding-5')
      ->setTriggeringElement(['#finding_pid' => 5]);
    $form_state->setErrorByName('prose][5', 'Add a note saying why.');
    $response = $this->pageForm()->ajaxRefresh($form, $form_state);
    $commands = $response->getCommands();
    $this->assertSame('announce', $commands[1]['command']);
    $this->assertSame('Not saved: Add a note saying why.', $commands[1]['text']);
    $this->assertSame('assertive', $commands[1]['priority']);
    // Into the field that needs fixing, inside the finding's box.
    $this->assertSame('focusFirst', $commands[2]['command']);
    $this->assertSame('#edit-prose-5--x', $commands[2]['selector']);
  }

  /**
   * An error's entities are not encoded twice in the announcement.
   *
   * @covers ::ajaxRefresh
   */
  public function testAjaxRefreshDecodesTheError(): void {
    $form = ['#type' => 'form'];
    $form_state = (new FormState())->set('arv_focus', 'finding-5');
    $form_state->setErrorByName('prose][5', 'It&#039;s <em>needed</em>.');
    $commands = $this->pageForm()->ajaxRefresh($form, $form_state)->getCommands();
    $this->assertSame('Not saved: It&#039;s needed.', $commands[1]['text']);
  }

  /**
   * The stale-window refusal focuses the page, with nothing else to fix.
   *
   * @covers ::ajaxRefresh
   */
  public function testAjaxRefreshWhenStale(): void {
    $form = ['#type' => 'form'];
    $form_state = (new FormState())->set('arv_stale', TRUE)->set('arv_focus', 'arv-review-form');
    $commands = $this->pageForm()->ajaxRefresh($form, $form_state)->getCommands();
    $this->assertSame('Not saved: This review changed in another window. Reload the page to see the latest version.', $commands[1]['text']);
    $this->assertSame('assertive', $commands[1]['priority']);
    $this->assertSame('#arv-review-form', $commands[2]['selector']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // FormState keeps whether any form had errors in a static.
    (new \ReflectionProperty(FormState::class, 'anyErrors'))->setValue(NULL, FALSE);
    parent::tearDown();
  }

}
