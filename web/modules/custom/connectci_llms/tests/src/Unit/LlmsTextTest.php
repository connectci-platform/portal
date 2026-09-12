<?php

namespace Drupal\Tests\connectci_llms\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\connectci_llms\LlmsText;

/**
 * Tests the llms.txt body builder.
 *
 * @group connectci_llms
 */
class LlmsTextTest extends UnitTestCase {

  /**
   * The body carries the domain identity and the curated links.
   */
  public function testBuildContainsIdentityAndLinks(): void {
    $text = LlmsText::build('CCMNet', 'ccmnet.org');
    $this->assertStringStartsWith('# CCMNet', $text);
    $this->assertStringContainsString('https://ccmnet.org/sitemap.xml', $text);
    $this->assertStringContainsString('https://ccmnet.org/api-docs', $text);
    $this->assertStringContainsString('https://ccmnet.org/api/2.3/events', $text);
    $this->assertStringContainsString('/api/2.1/announcements', $text);
    // The content API is domain-aware, lists everywhere, and teaches its
    // call pattern inline since the bare endpoint 404s.
    $this->assertStringContainsString('https://ccmnet.org/api/1.0/content?path=PAGE_PATH', $text);
    // The resources API is ACCESS-only and absent unless requested.
    $this->assertStringNotContainsString('/api/1.0/resources', $text);
    $access = LlmsText::build('ACCESS Support', 'support.access-ci.org', '', TRUE);
    $this->assertStringContainsString('https://support.access-ci.org/api/1.0/resources', $access);
    $this->assertStringContainsString('/.well-known/content-index.json', $access);
    $this->assertStringNotContainsString('/.well-known/', $text);
  }

  /**
   * A tagline renders above the platform line; absence degrades cleanly.
   */
  public function testTagline(): void {
    $with = LlmsText::build('CCMNet', 'ccmnet.org', 'Participate in mentorships to make connections and exchange knowledge');
    $this->assertStringContainsString('> Participate in mentorships to make connections and exchange knowledge', $with);
    $this->assertStringContainsString('> CCMNet is part of the ConnectCI platform.', $with);
    $without = LlmsText::build('CCMNet', 'ccmnet.org');
    $this->assertStringNotContainsString('>\n>', $without);
    $this->assertStringContainsString('> CCMNet is part of the ConnectCI platform.', $without);
  }

  /**
   * The body varies by domain and ends cleanly.
   */
  public function testBuildIsPerDomain(): void {
    $ccmnet = LlmsText::build('CCMNet', 'ccmnet.org');
    $gp = LlmsText::build('Great Plains', 'greatplains.cyberinfrastructure.org');
    $this->assertNotSame($ccmnet, $gp);
    $this->assertStringStartsWith('# Great Plains', $gp);
    $this->assertStringContainsString('https://greatplains.cyberinfrastructure.org/sitemap.xml', $gp);
    $this->assertStringEndsWith("\n", $gp);
  }

}
