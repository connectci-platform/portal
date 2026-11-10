<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Unit\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
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
   * The stand-in renderer.
   */
  private RendererInterface $renderer;

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
    $this->renderer = $renderer;
    $container = new ContainerBuilder();
    $container->set('renderer', $renderer);
    $messenger = $this->createStub(MessengerInterface::class);
    $messenger->method('deleteByType')->willReturn([]);
    $container->set('messenger', $messenger);
    \Drupal::setContainer($container);
  }

  /**
   * The form, without the services ajaxRefresh() does not use.
   */
  private function pageForm(): ReviewPageForm {
    $form = (new \ReflectionClass(ReviewPageForm::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(ReviewPageForm::class, 'renderer'))->setValue($form, $this->renderer);
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
   * A finding's own Save puts focus on its Edit control.
   *
   * @covers ::ajaxRefresh
   */
  public function testSavedFindingFocusesItsEditControl(): void {
    $form = ['#type' => 'form'];
    $form_state = (new FormState())
      ->set('arv_focus', 'finding-5')
      ->setTriggeringElement(['#finding_pid' => 5]);
    $commands = $this->pageForm()->ajaxRefresh($form, $form_state)->getCommands();
    $this->assertSame('#finding-5 .expand-hint', $commands[2]['selector']);
  }

  /**
   * With no error inside what was pressed, focus goes to the first one.
   *
   * @covers ::ajaxRefresh
   * @covers ::errorField
   */
  public function testErrorElsewhereFocusesTheFirstErroredField(): void {
    $form = [
      '#type' => 'form',
      'prose' => [
        5 => ['#id' => 'edit-prose-5--x', '#parents' => ['prose', 5]],
        7 => ['#id' => 'edit-prose-7--x', '#parents' => ['prose', 7]],
      ],
    ];
    $form_state = (new FormState())
      ->set('arv_focus', 'finding-5')
      ->setTriggeringElement(['#finding_pid' => 5]);
    $form_state->setErrorByName('prose][7', 'Add a note saying why.');
    $commands = $this->pageForm()->ajaxRefresh($form, $form_state)->getCommands();
    $this->assertSame('focusFirst', $commands[2]['command']);
    $this->assertSame('#edit-prose-7--x', $commands[2]['selector']);
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

  /**
   * A refused save's error is put beside the field it names.
   *
   * @covers ::showFieldErrors
   */
  public function testRefusedSaveErrorIsShownBesideTheField(): void {
    $form = [
      'prose' => [
        '41' => [
          '#type' => 'textarea',
          '#id' => 'edit-prose-41',
          '#errors' => 'Add a note saying why: it is required.',
          '#attributes' => ['aria-describedby' => 'edit-prose-41--description'],
        ],
        '42' => ['#type' => 'textarea', '#id' => 'edit-prose-42'],
      ],
      'override' => [
        '41' => [
          '#type' => 'container',
          'summary' => [
            '#type' => 'textfield',
            '#id' => 'edit-override-41-summary',
            '#errors' => 'Summary is required.',
          ],
        ],
      ],
    ];
    $out = ReviewPageForm::showFieldErrors($form);
    $this->assertSame('<p class="arv-field-error" id="edit-prose-41--error">Add a note saying why: it is required.</p>', $out['prose']['41']['#suffix']);
    $this->assertSame('edit-prose-41--description edit-prose-41--error', $out['prose']['41']['#attributes']['aria-describedby']);
    $this->assertArrayNotHasKey('#suffix', $out['prose']['42']);
    $this->assertStringContainsString('Summary is required.', $out['override']['41']['summary']['#suffix']);
    $this->assertArrayNotHasKey('#suffix', $out['override']['41']);
    $this->assertSame(['showFieldErrors'], ReviewPageForm::trustedCallbacks());
  }

}
