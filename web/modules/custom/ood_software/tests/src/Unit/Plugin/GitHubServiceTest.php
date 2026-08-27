<?php

namespace Drupal\Tests\ood_software\Unit\Plugin;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Plugin\GitHubService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for GitHubService::resolveSoftwareForApp().
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Plugin\GitHubService
 */
class GitHubServiceTest extends UnitTestCase {

  /**
   * The service under test.
   */
  protected GitHubService $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the five constructor dependencies. resolveSoftwareForApp only
    // touches $this->entityTypeManager, but the constructor signature
    // requires all five — and tests inject a fresh EntityTypeManager per
    // test via ReflectionProperty once a software catalog is set up.
    $httpClient = $this->createMock(ClientInterface::class);
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $messenger = $this->createMock(MessengerInterface::class);

    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    // Default EntityTypeManager — individual tests override via
    // mockSoftwareCatalog() when they need a populated catalog.
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->service = new GitHubService(
      $httpClient,
      $keyRepository,
      $messenger,
      $loggerFactory,
      $entityTypeManager,
    );
  }

  /**
   * NULL declaration returns empty result.
   *
   * @covers ::resolveSoftwareForApp
   */
  public function testResolveSoftwareNull(): void {
    $r = $this->service->resolveSoftwareForApp(NULL);
    $this->assertNull($r['declared']);
    $this->assertNull($r['resolvedTitle']);
    $this->assertNull($r['resolvedNid']);
    $this->assertNull($r['suggestion']);
  }

  /**
   * Exact match (case-sensitive AND case-insensitive) resolves successfully.
   *
   * @covers ::resolveSoftwareForApp
   */
  public function testResolveSoftwareExactMatch(): void {
    $this->mockSoftwareCatalog(['Jupyter']);

    // Case-sensitive exact match.
    $r = $this->service->resolveSoftwareForApp('Jupyter');
    $this->assertSame('Jupyter', $r['resolvedTitle']);
    $this->assertSame(1, $r['resolvedNid']);
    $this->assertNull($r['suggestion']);

    // Case-insensitive match.
    $r = $this->service->resolveSoftwareForApp('JUPYTER');
    $this->assertSame('Jupyter', $r['resolvedTitle']);
    $this->assertSame(1, $r['resolvedNid']);
  }

  /**
   * Close-but-not-exact declaration returns a Levenshtein suggestion.
   *
   * @covers ::resolveSoftwareForApp
   */
  public function testResolveSoftwareFuzzyMatch(): void {
    $this->mockSoftwareCatalog(['Jupyter']);

    // "Jupiter" → "Jupyter" is Levenshtein distance 1.
    $r = $this->service->resolveSoftwareForApp('Jupiter');
    $this->assertNull($r['resolvedTitle']);
    $this->assertNull($r['resolvedNid']);
    $this->assertSame('Jupyter', $r['suggestion']);
    $this->assertSame(1, $r['suggestionDistance']);
  }

  /**
   * Far-away declaration returns neither resolution nor suggestion.
   *
   * @covers ::resolveSoftwareForApp
   */
  public function testResolveSoftwareNoMatch(): void {
    $this->mockSoftwareCatalog(['Jupyter', 'RStudio']);

    // "Xyzzy42" is too far from anything (distance > 3).
    $r = $this->service->resolveSoftwareForApp('Xyzzy42');
    $this->assertNull($r['resolvedTitle']);
    $this->assertNull($r['resolvedNid']);
    $this->assertNull($r['suggestion']);
    $this->assertNull($r['suggestionDistance']);
  }

  /**
   * Helper: install a mock catalog of Software titles into the service.
   *
   * Builds NodeInterface mocks (one per title), wires a node-storage mock
   * whose getQuery()->execute() returns the nid set and loadMultiple()
   * returns the node map, then injects a fresh EntityTypeManager into the
   * already-constructed service via ReflectionProperty.
   *
   * @param string[] $titles
   *   Titles to mock as appverse_software node rows.
   */
  protected function mockSoftwareCatalog(array $titles): void {
    $mockNodes = [];
    foreach ($titles as $i => $title) {
      $node = $this->createMock(NodeInterface::class);
      $node->method('getTitle')->willReturn($title);
      $node->method('id')->willReturn($i + 1);
      $mockNodes[$i + 1] = $node;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($mockNodes));

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($mockNodes);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('node')->willReturn($storage);

    $reflProp = new \ReflectionProperty($this->service, 'entityTypeManager');
    $reflProp->setAccessible(TRUE);
    $reflProp->setValue($this->service, $etm);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsNull(): void {
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml('appverse_implementation_tags', NULL);
    $this->assertSame([], $r['declared']);
    $this->assertSame([], $r['resolved']);
    $this->assertSame([], $r['unresolved']);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsEmptyArray(): void {
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml('appverse_implementation_tags', []);
    $this->assertSame([], $r['declared']);
    $this->assertSame([], $r['resolved']);
    $this->assertSame([], $r['unresolved']);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsExactAndCaseInsensitive(): void {
    $this->mockTagsCatalog(['Containerized', 'GPU-enabled', 'Modules']);
    // Case-sensitive exact + case-insensitive exact both resolve.
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml(
      'appverse_implementation_tags',
      ['Containerized', 'modules']
    );
    $this->assertCount(2, $r['resolved']);
    $this->assertSame('Containerized', $r['resolved'][0]['name']);
    $this->assertSame('Modules', $r['resolved'][1]['name']);
    $this->assertSame([], $r['unresolved']);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsFuzzySuggestion(): void {
    $this->mockTagsCatalog(['Containerized', 'GPU-enabled']);
    // Typo within Levenshtein-3 distance — gets a suggestion.
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml(
      'appverse_implementation_tags',
      ['Containerised']  // British spelling — distance 1 from 'Containerized'
    );
    $this->assertSame([], $r['resolved']);
    $this->assertCount(1, $r['unresolved']);
    $this->assertSame('Containerised', $r['unresolved'][0]['declared']);
    $this->assertSame('Containerized', $r['unresolved'][0]['suggestion']);
    $this->assertLessThanOrEqual(3, $r['unresolved'][0]['suggestionDistance']);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsNoMatchNoSuggestion(): void {
    $this->mockTagsCatalog(['Containerized', 'Modules']);
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml(
      'appverse_implementation_tags',
      ['NotARealTag']  // > 3 distance from anything in the catalog
    );
    $this->assertSame([], $r['resolved']);
    $this->assertCount(1, $r['unresolved']);
    $this->assertSame('NotARealTag', $r['unresolved'][0]['declared']);
    $this->assertNull($r['unresolved'][0]['suggestion']);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsMixedResolvedAndUnresolved(): void {
    $this->mockTagsCatalog(['Containerized', 'Modules']);
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml(
      'appverse_implementation_tags',
      ['Containerized', 'Containerised', 'GarbageTag']
    );
    // Containerized → resolved exact
    // Containerised → unresolved with suggestion
    // GarbageTag → unresolved without suggestion (distance > 3 from anything)
    $this->assertCount(1, $r['resolved']);
    $this->assertSame('Containerized', $r['resolved'][0]['name']);
    $this->assertCount(2, $r['unresolved']);
    $this->assertSame('Containerised', $r['unresolved'][0]['declared']);
    $this->assertSame('Containerized', $r['unresolved'][0]['suggestion']);
    $this->assertSame('GarbageTag', $r['unresolved'][1]['declared']);
    $this->assertNull($r['unresolved'][1]['suggestion']);
  }

  /**
   * @covers ::resolveTaxonomyTermsFromAppverseYml
   */
  public function testResolveTaxonomyTermsCoercesScalarsDropsEmpties(): void {
    $this->mockTagsCatalog(['One']);
    // Mixed types: should coerce scalars and drop empty strings.
    $r = $this->service->resolveTaxonomyTermsFromAppverseYml(
      'appverse_implementation_tags',
      ['One', '', '  ', 42, ['nested']]  // 42 coerces to '42' (unresolved); '' and '   ' drop; nested array drops
    );
    $this->assertSame(['One', '42'], $r['declared']);
  }

  /**
   * Helper: install a mock catalog of taxonomy term names into the service.
   *
   * Mirrors mockSoftwareCatalog but for taxonomy_term storage. The
   * resolveTaxonomyTermsFromAppverseYml method calls
   * getStorage('taxonomy_term') (not 'node'), so this overrides the
   * EntityTypeManager mock's getStorage to dispatch by entity type.
   *
   * @param string[] $names
   *   Term names to mock in the test vocabulary.
   */
  protected function mockTagsCatalog(array $names): void {
    $mockTerms = [];
    foreach ($names as $i => $name) {
      // Term mocks use Drupal\Core\Entity\EntityInterface — the
      // resolveTaxonomy method only calls getName() and id() which both
      // live on EntityInterface, so we don't need TermInterface specifically.
      $term = $this->createMock(\Drupal\Core\Entity\EntityInterface::class);
      $term->method('label')->willReturn($name);
      // The resolver calls getName() on terms; provide both for safety.
      $term->method('id')->willReturn($i + 1);
      // Add getName() method via a partial mock pattern — easier to use
      // TermInterface here.
      $mockTerms[$i + 1] = $term;
    }
    // Use TermInterface mocks for accurate getName() support.
    $mockTerms = [];
    foreach ($names as $i => $name) {
      $term = $this->createMock(\Drupal\taxonomy\TermInterface::class);
      $term->method('getName')->willReturn($name);
      $term->method('id')->willReturn($i + 1);
      $mockTerms[$i + 1] = $term;
    }

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($mockTerms));

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($mockTerms);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')
      ->willReturnCallback(function ($entityType) use ($storage) {
        if ($entityType === 'taxonomy_term') {
          return $storage;
        }
        // Fall through for any other type (shouldn't happen in these
        // tests; throw to make a mismatch loud).
        throw new \InvalidArgumentException("Unexpected getStorage call for '$entityType' in tags test");
      });

    $reflProp = new \ReflectionProperty($this->service, 'entityTypeManager');
    $reflProp->setAccessible(TRUE);
    $reflProp->setValue($this->service, $etm);
  }

  /**
   * Rebuild $this->service with a HTTP client that returns a fixed body.
   *
   * Used by the fetchRepoData/parseUrl regression tests below. Returns the
   * mock logger so tests can assert the warning content.
   */
  protected function mockGraphQLResponse(string $body): LoggerInterface {
    // Guzzle's ClientInterface routes named methods like post() through a
    // magic __call; PHPUnit can't mock magic methods. Use a real Client
    // with a MockHandler stack so the actual ->post() path executes
    // against canned response bytes.
    $mock = new MockHandler([new Response(200, [], $body)]);
    $handler = HandlerStack::create($mock);
    $httpClient = new Client(['handler' => $handler]);

    $key = $this->createMock(\Drupal\key\KeyInterface::class);
    $key->method('getKeyValue')->willReturn('fake-token');
    $keyRepository = $this->createMock(KeyRepositoryInterface::class);
    $keyRepository->method('getKey')->willReturn($key);

    $messenger = $this->createMock(MessengerInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->service = new GitHubService(
      $httpClient,
      $keyRepository,
      $messenger,
      $loggerFactory,
      $entityTypeManager,
    );
    return $logger;
  }

  /**
   * GraphQL returns 200 OK with data.repository === null (repo not found,
   * private, or removed). parseUrl must return FALSE and log a warning
   * — NOT throw a "Trying to access array offset on null" warning, which
   * was the bug that caused AddRepoForm to crash for Lissie.
   *
   * @covers ::parseUrl
   * @covers ::fetchRepoData
   */
  public function testParseUrlReturnsFalseWhenGraphqlRepositoryIsNull(): void {
    $logger = $this->mockGraphQLResponse('{"data":{"repository":null}}');
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('GitHub GraphQL did not return repository data'),
        $this->anything(),
      );

    $result = $this->service->parseUrl('https://github.com/example/missing-repo');
    $this->assertFalse($result);
  }

  /**
   * GraphQL returns only an errors block (no data key) — typical for auth
   * failures and rate-limit responses. parseUrl must return FALSE and the
   * logged warning must include the GitHub error message for ops triage.
   *
   * @covers ::parseUrl
   * @covers ::fetchRepoData
   */
  public function testParseUrlReturnsFalseWhenGraphqlErrorsOnly(): void {
    $body = '{"errors":[{"message":"Could not resolve to a Repository with the name \"example/missing\"."}]}';
    $logger = $this->mockGraphQLResponse($body);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->anything(),
        $this->callback(function ($context) {
          return isset($context['@err']) && str_contains((string) $context['@err'], 'Could not resolve to a Repository');
        }),
      );

    $result = $this->service->parseUrl('https://github.com/example/missing');
    $this->assertFalse($result);
  }

  /**
   * GraphQL returns malformed JSON (network corruption, partial response).
   * parseUrl must return FALSE rather than letting json_decode's NULL
   * cascade into fatal-style warnings.
   *
   * @covers ::parseUrl
   * @covers ::fetchRepoData
   */
  public function testParseUrlReturnsFalseWhenResponseIsNotJson(): void {
    $this->mockGraphQLResponse('not json at all');

    $result = $this->service->parseUrl('https://github.com/example/anything');
    $this->assertFalse($result);
  }

  /**
   * Data provider: subpaths that are safe to interpolate into the GraphQL
   * expression literal.
   */
  public static function validSubpathProvider(): array {
    return [
      'simple' => ['jupyter'],
      'nested' => ['apps/jupyter'],
      'version dots' => ['nf-core-rnaseq-3.26.0'],
      'underscores' => ['bc_osc_jupyter'],
      'deep nesting' => ['a/b/c/d'],
    ];
  }

  /**
   * @dataProvider validSubpathProvider
   * @covers ::isValidSubpath
   */
  public function testValidSubpathAccepted(string $path): void {
    $this->assertTrue(GitHubService::isValidSubpath($path), "'$path' should be a valid subpath.");
  }

  /**
   * Data provider: subpaths that must be rejected. The quote-bearing cases are
   * the GraphQL-injection payloads that would break out of the "HEAD:%s/..."
   * string literal; the rest are non-path junk.
   */
  public static function invalidSubpathProvider(): array {
    return [
      'double quote (injection)' => ['x") { y: object(expression: "HEAD:secret'],
      'quote only' => ['a"b'],
      'newline' => ["a\nb"],
      'space' => ['has space'],
      'backslash' => ['a\\b'],
      'brace' => ['a{b}'],
      'empty' => [''],
    ];
  }

  /**
   * @dataProvider invalidSubpathProvider
   * @covers ::isValidSubpath
   */
  public function testInvalidSubpathRejected(string $path): void {
    $this->assertFalse(GitHubService::isValidSubpath($path), "'$path' must be rejected.");
  }

  /**
   * fetchAppSubpaths THROWS on a GraphQL error body instead of returning
   * all-NULL files. This is the guard that stops an unattended caller (cron)
   * from rewriting every previously-valid member to rejected/empty on a
   * transient upstream failure.
   *
   * @covers ::fetchAppSubpaths
   */
  public function testFetchAppSubpathsThrowsOnGraphQLError(): void {
    // A 200 with an errors-only body (data absent) — the common rate-limit /
    // 5xx-normalized GraphQL failure shape.
    $this->mockGraphQLResponse(json_encode(['errors' => [['message' => 'API rate limit exceeded']]]));

    // owner/name are set by parseUrl(); set them directly to reach the fetch.
    $ref = new \ReflectionClass($this->service);
    foreach (['owner' => 'OSC', 'name' => 'mono'] as $prop => $val) {
      $p = $ref->getProperty($prop);
      $p->setAccessible(TRUE);
      $p->setValue($this->service, $val);
    }

    $this->expectException(\RuntimeException::class);
    $this->service->fetchAppSubpaths(['jupyter', 'rstudio']);
  }

  /**
   * getAppPreviewData DEGRADES (returns records, doesn't throw) when the
   * per-subpath fetch fails. Regression guard for the WSOD where a transient
   * GitHub failure crashed the Add-Repo form preview instead of rendering a
   * degraded one.
   *
   * @covers ::getAppPreviewData
   */
  public function testPreviewDegradesWhenSubpathFetchFails(): void {
    // The preview's per-subpath fetch will hit this errors-only body and throw
    // inside fetchAppSubpaths — getAppPreviewData must catch and degrade.
    $this->mockGraphQLResponse(json_encode(['errors' => [['message' => 'rate limited']]]));

    // Put the service into the declared-repo state getAppPreviewData needs:
    // owner/name (fetch), non-empty $data (isEmptyRepo=false), and a root
    // appverse.yml declaring one app subpath (isDeclaredRepo=true).
    $ref = new \ReflectionClass($this->service);
    $set = function (string $prop, $val) use ($ref) {
      $p = $ref->getProperty($prop);
      $p->setAccessible(TRUE);
      $p->setValue($this->service, $val);
    };
    $set('owner', 'OSC');
    $set('name', 'mono');
    $set('data', ['x' => 1]);
    $set('appverseYmlText', "apps:\n  - path: jupyter\n    name: Jupyter\n");

    // Must not throw; returns an array (degraded preview).
    $records = $this->service->getAppPreviewData();
    $this->assertIsArray($records, 'Preview degrades to an array rather than throwing on fetch failure.');
  }

}
