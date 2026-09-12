<?php

namespace Drupal\connectci_llms;

/**
 * Builds the llms.txt body for a domain.
 *
 * Deliberately factual boilerplate only — community identity comes from the
 * domain label, and no marketing prose ships without an owner signing it
 * off. See D8-2780.
 */
final class LlmsText {

  /**
   * Events endpoint path.
   *
   * v2.3 is the current API and correct per domain: domains without events
   * return an empty array, which is the cheap answer crawlers should get.
   */
  public const EVENTS_PATH = '/api/2.3/events';

  /**
   * Build the llms.txt markdown for one domain.
   *
   * The tagline is the domain's system.site slogan — community-curated copy
   * (D8-2780). When absent, the platform sentence stands alone.
   */
  public static function build(string $site_name, string $host, string $tagline = '', bool $access_apis = FALSE): string {
    $base = 'https://' . $host;
    // Heredocs cannot interpolate class constants; assign first.
    $events = self::EVENTS_PATH;
    // The resources API serves ACCESS data on the support domain only. The
    // content API is domain-aware (renders the serving domain's nodes).
    $access_lines = $access_apis
      ? "\n- Resources: {$base}/api/1.0/resources returns the ACCESS resource catalog as JSON\n- Content index: {$base}/.well-known/content-index.json lists the support documentation corpus with content hashes"
      : '';
    $intro = $tagline !== ''
      ? "> {$tagline}\n>\n> {$site_name} is part of the ConnectCI platform."
      : "> {$site_name} is part of the ConnectCI platform.";
    return <<<MD
# {$site_name}

{$intro}

## Content

- Sitemap: {$base}/sitemap.xml

## Machine-readable APIs

- API documentation: {$base}/api-docs describes every API with OpenAPI specs
- Events: {$base}{$events} returns upcoming events as JSON
- Announcements: {$base}/api/2.1/announcements returns announcements as JSON
- Page text: {$base}/api/1.0/content?path=PAGE_PATH renders content pages as plain text with a content hash (events and other listing types are served by their own APIs above){$access_lines}

MD;
  }

}
