<?php

declare(strict_types=1);

namespace Drupal\Tests\ood_software\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ood_software\Form\AddRepoForm;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\Tests\ood_software\Kernel\Traits\ProdConfigTrait;

/**
 * Covers AddRepoForm surfacing the empty-root error in the AJAX rebuild.
 *
 * When a submitted repo has no appverse.yml and no manifest.yml at its root
 * (e.g. the OOD app files live in a subdirectory like app/), submitFetch()
 * reports the reason via messenger()->addError() and setRebuild(). The #ajax
 * callback then replaces the form wrapper with the rebuilt form.
 *
 * The bug this guards against: the form had no status_messages render element
 * inside the wrapper, so messenger errors were not injected into the AJAX
 * response — they silently deferred to the next full page load, and the user
 * saw a generic "did not respond in time" failure instead of the real reason.
 *
 * Two assertions together prove the fix:
 *   1. submitFetch() sets the empty-root error into the messenger (the message
 *      always fired — this half was never broken).
 *   2. The rebuilt form contains a #type => status_messages element, so the
 *      AJAX rebuild has somewhere to render that error (the fix).
 *
 * Boundary: this asserts the status_messages element is PRESENT in the rebuilt
 * render array. That messenger output ends up visibly rendered in the browser
 * is a framework behaviour of the status_messages element and is confirmed by
 * one-time manual verification, not by this kernel test.
 *
 * @coversDefaultClass \Drupal\ood_software\Form\AddRepoForm
 * @group ood_software
 */
class AddRepoFormEmptyRootMessageTest extends KernelTestBase {

  use ProdConfigTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'datetime',
    'link',
    'taxonomy',
    'path',
    'path_alias',
    'content_moderation',
    'workflows',
    // ood_software.gh depends on the `key` module's key.repository service.
    'key',
    // ood_software_node_insert() on appverse_app nodes calls the `flag` service.
    'flag',
    'ood_software',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'user', 'node']);

    // submitFetch() runs a dup-URL entity query against appverse_repo
    // (field_repo_url) before the empty-repo check, so the node type and that
    // field must exist for the query to build.
    $this->importProdConfig([
      'node.type.appverse_repo',
      'field.storage.node.field_repo_url',
      'field.field.node.appverse_repo.field_repo_url',
    ]);
  }

  /**
   * An empty-root submit reports the reason AND has somewhere to render it.
   *
   * @covers ::submitFetch
   * @covers ::buildForm
   */
  public function testEmptyRootErrorIsRenderableInAjaxRebuild(): void {
    // Replace ood_software.gh with a double reporting the empty-root state:
    // a parseable GitHub URL, not archived, but no appverse.yml/manifest.yml
    // at the root (isEmptyRepo() === TRUE).
    $github = $this->createMock(GitHubService::class);
    $github->method('parseUrl')->willReturn(TRUE);
    $github->method('getIsArchived')->willReturn(FALSE);
    $github->method('getRepoUrl')->willReturn('https://github.com/example/empty-root');
    $github->method('isEmptyRepo')->willReturn(TRUE);
    // Defensive: if reached, report a non-declared/non-single shape too.
    $github->method('isDeclaredRepo')->willReturn(FALSE);
    $this->container->set('ood_software.gh', $github);

    $form_object = AddRepoForm::create($this->container);

    // Drive the URL-stage submit handler directly (as the other kernel form
    // tests do), supplying the submitted repo_url via form_state values.
    $form_state = new FormState();
    $form_state->setValue('repo_url', 'https://github.com/example/empty-root');
    $form_array = [];
    $form_object->submitFetch($form_array, $form_state);

    // 1. The empty-root error was reported to the user (message fired).
    $messages = $this->container->get('messenger')->messagesByType('error');
    $joined = implode("\n", array_map('strval', $messages));
    self::assertStringContainsString(
      'no',
      $joined,
      'submitFetch must report an error for an empty-root repo.'
    );
    self::assertStringContainsString(
      'root',
      $joined,
      'The empty-root error should mention the repository root.'
    );

    // The form rebuilds (stays on the url stage) rather than advancing.
    self::assertTrue($form_state->isRebuilding(), 'submitFetch must set the form to rebuild on an empty-root repo.');
    self::assertNull($form_state->get('stage'), 'submitFetch must not advance the stage on an empty-root repo.');

    // 2. The rebuilt form contains a status_messages element, so the AJAX
    // rebuild has somewhere to render that error. This is the fix: without
    // the element, messenger errors are dropped from the AJAX response.
    $rebuilt = $form_object->buildForm([], new FormState());
    self::assertArrayHasKey('messages', $rebuilt, 'buildForm must include a messages element.');
    self::assertSame(
      'status_messages',
      $rebuilt['messages']['#type'] ?? NULL,
      'The messages element must be #type status_messages so messenger output renders in the AJAX-replaced wrapper.'
    );
  }

}
