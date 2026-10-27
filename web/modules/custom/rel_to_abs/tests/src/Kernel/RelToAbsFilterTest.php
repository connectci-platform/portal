<?php

namespace Drupal\Tests\rel_to_abs\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the rel_to_abs text format filter.
 *
 * @group rel_to_abs
 */
class RelToAbsFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'filter', 'rel_to_abs'];

  /**
   * The filter plugin under test.
   *
   * @var \Drupal\filter\Plugin\FilterInterface
   */
  protected $filter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $request = Request::create('https://example.org/some/page');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
    $this->filter = $this->container->get('plugin.manager.filter')
      ->createInstance('rel_to_abs', ['status' => TRUE]);
  }

  /**
   * Root-relative href, src and srcset values gain the scheme and host.
   */
  public function testRootRelativeUrlsBecomeAbsolute(): void {
    $html = (string) $this->filter->process(
      '<a href="/node/1?x=1#top">a</a><img src="/files/a%20b.png" srcset="/files/s.png 1x, /files/l.png 2x">',
      'en'
    );
    $this->assertStringContainsString('href="https://example.org/node/1?x=1#top"', $html);
    // Already-encoded paths must not be encoded a second time.
    $this->assertStringContainsString('src="https://example.org/files/a%20b.png"', $html);
    $this->assertStringContainsString('srcset="https://example.org/files/s.png 1x, https://example.org/files/l.png 2x"', $html);
  }

  /**
   * Protocol-relative, fragment, absolute and mailto URLs are untouched.
   */
  public function testOtherUrlsAreLeftAlone(): void {
    $html = (string) $this->filter->process(
      '<a href="//cdn.example.com/x.js">a</a><a href="#anchor">b</a><a href="https://other.org/y">c</a><a href="mailto:a@example.org">d</a>',
      'en'
    );
    $this->assertStringContainsString('href="//cdn.example.com/x.js"', $html);
    $this->assertStringContainsString('href="#anchor"', $html);
    $this->assertStringContainsString('href="https://other.org/y"', $html);
    $this->assertStringContainsString('href="mailto:a@example.org"', $html);
  }

  /**
   * The output varies by domain, so it must carry the url.site context.
   */
  public function testResultVariesBySite(): void {
    $result = $this->filter->process('<a href="/x">x</a>', 'en');
    $this->assertContains('url.site', $result->getCacheContexts());

    // Text with nothing to rewrite still varies, so the context is constant.
    $result = $this->filter->process('plain text', 'en');
    $this->assertSame('plain text', $result->getProcessedText());
    $this->assertContains('url.site', $result->getCacheContexts());
  }

}
