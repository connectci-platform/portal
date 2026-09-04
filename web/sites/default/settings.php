<?php

/**
 * @file
 * Global settings.
 */

use Drupal\Core\Installer\InstallerKernel;

global $config;

$hash = getenv('DRUPAL_HASH_SALT');

$settings['hash_salt'] = $hash;
$settings['update_free_access'] = FALSE;
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = 'sites/default/files/private';

$settings['container_yamls'][] = __DIR__ . '/services.yml';
$config['config_split.config_split.local']['status'] = FALSE;
$config['config_split.config_split.live']['status'] = FALSE;

$config['environment_indicator.indicator']['bg_color'] = '#27391C';
$config['environment_indicator.indicator']['fg_color'] = '#FFFFFF';
$config['environment_indicator.indicator']['name'] = 'MD';

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 50;
global $content_directories;
$content_directories['sync'] = '/datastorage/content/sync';

$additionalSettingsFiles = [
  (DRUPAL_ROOT . "/../vendor/acquia/blt/settings/blt.settings.php"),
  (__DIR__ . "/settings.pantheon.php"),
// For lando blt tests.
  (__DIR__ . "/settings/local.settings.php"),
// More local settings.
  (__DIR__ . "/local.settings.php"),
];

foreach ($additionalSettingsFiles as $settingsFile) {
  if (file_exists($settingsFile)) {
    require $settingsFile;
  }
}

if (defined('PANTHEON_ENVIRONMENT')) {
  $env = PANTHEON_ENVIRONMENT;
}
else {
  $env = getenv('PANTHEON_ENVIRONMENT');
}

// Detect DDEV as local environment
if (empty($env) && getenv('IS_DDEV_PROJECT') == 'true') {
  $env = 'local';
}

if (isset($env)) {
  // Per environment settings.
  switch ($env) {
    case 'dev':
      $config['system.logging']['error_level'] = 'verbose';

      $config['environment_indicator.indicator']['bg_color'] = '#7F8CAA';
      $config['environment_indicator.indicator']['fg_color'] = '#FFFFFF';
      $config['environment_indicator.indicator']['name'] = 'Dev';
      break;

    case 'test':
      $config['environment_indicator.indicator']['bg_color'] = '#FE7743';
      $config['environment_indicator.indicator']['fg_color'] = '#FFFFFF';
      $config['environment_indicator.indicator']['name'] = 'Test';
     break;

    case 'local':
      $config['config_split.config_split.local']['status'] = TRUE;
      $config['system.logging']['error_level'] = 'verbose';
      $config['system.performance']['css']['preprocess'] = FALSE;
      $config['system.performance']['js']['preprocess'] = FALSE;
      // development.services.yml turns Twig theme-debug ON (and disables render
      // caching). In CI we must NOT load it: theme-debug injects HTML comments —
      // and any stray PHP warning — into rendered output, which corrupts AJAX
      // JSON responses (ajax.js then throws a parsererror and silently drops the
      // interaction). Skipping the file leaves services.yml's committed
      // debug: FALSE in force, matching production. CI is signalled by the
      // CI=true web_environment set in .github/actions/ddev-base. NOTE: a second,
      // git-ignored append of this same file lives in
      // sites/default/settings/local.settings.php — that path is absent in CI, so
      // this is the only append that matters here; edit BOTH if you change intent.
      if (getenv('CI') !== 'true') {
        $settings['container_yamls'][] = __DIR__ . '/../development.services.yml';
      }

      $config['environment_indicator.indicator']['bg_color'] = '#005A70';
      $config['environment_indicator.indicator']['fg_color'] = '#FFFFFF';
      $config['environment_indicator.indicator']['name'] = 'Local';
      break;

    case 'live':
      $config['config_split.config_split.live']['status'] = TRUE;

      $config['environment_indicator.indicator']['bg_color'] = '#DC2525';
      $config['environment_indicator.indicator']['fg_color'] = '#FFFFFF';
      $config['environment_indicator.indicator']['name'] = 'Prod';
      break;
  }
}

$settings['config_sync_directory'] = 'sites/default/config/default';

// Load Google OAuth client_secret from private key file instead of config.
$google_secret_file = DRUPAL_ROOT . '/sites/default/files/private/.keys/google-oauth-secret.key';
if (file_exists($google_secret_file)) {
  $config['social_auth_google.settings']['client_secret'] = trim(file_get_contents($google_secret_file));
}

// Load Mailgun SMTP passwords from private key files instead of config.
$mailgun_secret_file = DRUPAL_ROOT . '/sites/default/files/private/.keys/mailgun-smtp.key';
if (file_exists($mailgun_secret_file)) {
  $config['symfony_mailer.mailer_transport.smtp']['configuration']['pass'] = trim(file_get_contents($mailgun_secret_file));
}
$mailgun_ccmnet_file = DRUPAL_ROOT . '/sites/default/files/private/.keys/mailgun-smtp-ccmnet.key';
if (file_exists($mailgun_ccmnet_file)) {
  $config['symfony_mailer.mailer_transport.smtp_ccmnet']['configuration']['pass'] = trim(file_get_contents($mailgun_ccmnet_file));
}
$mailgun_ood_file = DRUPAL_ROOT . '/sites/default/files/private/.keys/mailgun-smtp-ood.key';
if (file_exists($mailgun_ood_file)) {
  $config['symfony_mailer.mailer_transport.smtp_ood']['configuration']['pass'] = trim(file_get_contents($mailgun_ood_file));
}

// Load CILogon client_secret from private key file instead of config.
$cilogon_secret_file = DRUPAL_ROOT . '/sites/default/files/private/.keys/cilogon-client-secret.key';
if (file_exists($cilogon_secret_file)) {
  $config['openid_connect.settings.cilogon']['settings']['client_secret'] = trim(file_get_contents($cilogon_secret_file));
}

// Exclude modules that either contain secrets or break non-live environments.
// Dev-only modules (devel, webprofiler, etc.) are handled by config_split.
$settings['config_exclude_modules'] = [
  'cilogon_auth',
  'drupal_seamless_cilogon',
  'recaptcha',
  'recaptcha_v3',
];

ini_set('max_execution_time', 360);

// Configure Redis.
if (defined(
 'PANTHEON_ENVIRONMENT'
) && !InstallerKernel::installationAttempted(
) && extension_loaded('redis')) {
  // Set Redis as the default backend for any cache bin not otherwise specified.
  $settings['cache']['default'] = 'cache.backend.redis';

  // Phpredis is built into the Pantheon application container.
  $settings['redis.connection']['interface'] = 'PhpRedis';

  // These are dynamic variables handled by Pantheon.
  $settings['redis.connection']['host'] = $_ENV['CACHE_HOST'];
  $settings['redis.connection']['port'] = $_ENV['CACHE_PORT'];
  $settings['redis.connection']['password'] = $_ENV['CACHE_PASSWORD'];

  $settings['redis_compress_length'] = 100;
  $settings['redis_compress_level'] = 1;

  $settings['cache_prefix']['default'] = 'pantheon-redis';

  // Use the database for forms.
  $settings['cache']['bins']['form'] = 'cache.backend.database';

  // Apply changes to the container configuration to make better use of Redis.
  // This includes using Redis for the lock and flood control systems, as well
  // as the cache tag checksum. Alternatively, copy the contents of that file
  // to your project-specific services.yml file, modify as appropriate, and
  // remove this line.
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

  // Allow the services to work before the Redis module itself is enabled.
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

  // Manually add the classloader path, this is required for the container
  // cache bin definition below.
  // $class_loader is provided in scope by Drupal's bootstrap when it includes
  // this settings file.
  /** @var \Composer\Autoload\ClassLoader $class_loader */
  $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

  // Use redis for container cache.
  // The container cache is used to load the container definition itself, and
  // thus any configuration stored in the container itself is not available
  // yet. These lines force the container cache to use Redis rather than the
  // default SQL cache.
  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      'redis.factory' => [
        'class' => 'Drupal\redis\ClientFactory',
      ],
      'cache.backend.redis' => [
        'class' => 'Drupal\redis\Cache\CacheBackendFactory',
        'arguments' => [
          '@redis.factory',
          '@cache_tags_provider.container',
          '@serialization.phpserialize',
        ],
      ],
      'cache.container' => [
        'class' => '\Drupal\redis\Cache\PhpRedis',
        'factory' => ['@cache.backend.redis', 'get'],
        'arguments' => ['container'],
      ],
      'cache_tags_provider.container' => [
        'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
        'arguments' => ['@redis.factory'],
      ],
      'serialization.phpserialize' => [
        'class' => 'Drupal\Component\Serialization\PhpSerialize',
      ],
    ],
  ];

  // Skip Drupal's default `Vary: Cookie` on anonymous responses so Pantheon's
  // edge can share one cache entry per URL across visitors with different
  // non-session cookies (analytics, tracking, etc.). Authenticated users
  // still bypass the edge because Pantheon recognizes the SESS*/SSESS*
  // session cookie as cache-busting, so they continue to get personalized
  // content from origin. See web/core/assets/scaffold/files/default.settings.php
  // for the upstream documentation of this setting.
  $settings['omit_vary_cookie'] = TRUE;
}

$env = getenv('PANTHEON_ENVIRONMENT');

// Helper function to get Turnstile secrets.
// Reads from private secrets file (Pantheon) or env vars (local dev).
function _get_turnstile_secret(string $name): string {
  static $secrets = null;
  static $debug_logged = false;

  // Load secrets file once.
  if ($secrets === null) {
    // Try possible paths for the secrets file on Pantheon.
    // Per Pantheon docs, use relative path from Drupal root: sites/default/files/private
    // __DIR__ is web/sites/default, so we need to go up and use the Drupal root path.
    // Per Pantheon docs example, use relative path: sites/default/files/private/file.json
    $possible_paths = [
      'sites/default/files/private/.keys/secrets.json',
      __DIR__ . '/files/private/.keys/secrets.json',
      '/files/private/.keys/secrets.json',
    ];

    $secrets = [];
    foreach ($possible_paths as $path) {
      if (file_exists($path)) {
        $raw = file_get_contents($path);
        $secrets = json_decode($raw, true) ?: [];
        break;
      }
    }
  }

  if (isset($secrets[$name])) {
    return $secrets[$name];
  }

  // Fall back to environment variables (local dev).
  return getenv($name) ?: '';
}

// Resolve the real visitor IP for Turnstile cookie binding.
//
// On Pantheon, $_SERVER['REMOTE_ADDR'] is the internal load-balancer IP
// (10.1.x.x), identical for every visitor behind a given LB. Binding the
// verification cookie to it makes one solved challenge valid for everyone
// sharing that LB — which let the facet-crawl bots replay a single cookie
// past the gate. Bind to the real client instead: Pantheon puts it as the
// first token of X-Forwarded-For (format: "client, client, 10.1.x.x").
function _get_real_client_ip(): string {
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $client = trim($parts[0]);
    if (filter_var($client, FILTER_VALIDATE_IP)) {
      return $client;
    }
  }
  return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Serve the Turnstile challenge page.
 *
 * This function handles both displaying the challenge form and processing
 * the verification response. It runs before Drupal bootstraps to minimize
 * server load from bot traffic.
 */
function _serve_turnstile_challenge(string $return_url): void {
  $site_key = _get_turnstile_secret('TURNSTILE_SITE_KEY');
  $secret_key = _get_turnstile_secret('TURNSTILE_SECRET_KEY');
  // SESS prefix: Pantheon's Global CDN treats SESS* cookies as session cookies,
  // forwarding them to origin and bypassing the edge cache for that visitor.
  // This mirrors the working SESSaccess_auth cookie on this site. (An unprefixed
  // cookie is stripped at the edge and never reaches PHP, which loops the user.)
  $cookie_name = 'SESSturnstileverified';
  $cookie_duration = 86400; // 24 hours
  $error = '';

  // Sanitize return URL - must be a relative path on this domain.
  if (!preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $return_url)) {
    $return_url = '/';
  }

  // Handle form submission.
  // Check both POST and GET since Pantheon may redirect POST to GET.
  $turnstile_token = $_POST['cf-turnstile-response'] ?? $_GET['cf-turnstile-response'] ?? null;
  if ($turnstile_token) {
    $token = $turnstile_token;
    $post_return_url = $_POST['return_url'] ?? $_GET['return_url'] ?? '/';

    // Sanitize return URL again.
    if (preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $post_return_url)) {
      $return_url = $post_return_url;
    }

    // Verify with Cloudflare.
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      // Do not send 'remoteip': Cloudflare validates it against the IP that
      // solved the widget, and behind Pantheon's load balancer the client IP
      // we resolve doesn't reliably match Cloudflare's edge view, which makes
      // siteverify return success=false and loops the user back to the
      // challenge. The token alone is sufficient proof of a valid solve.
      CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $secret_key,
        'response' => $token,
      ]),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
      $result = json_decode($response, true);

      if (!empty($result['success'])) {
        // Verification successful - set cookie and redirect.
        // Match the working SESSaccess_auth cookie: always Secure (Pantheon's
        // LB terminates TLS so $_SERVER['HTTPS'] is unset at origin) and
        // SameSite=None so the cookie survives the cross-context navigation
        // back from the Turnstile challenge.
        setcookie($cookie_name, hash('sha256', $secret_key . _get_real_client_ip()), [
          'expires' => time() + $cookie_duration,
          'path' => '/',
          'secure' => true,
          'httponly' => true,
          'samesite' => 'None',
        ]);

        header('Location: ' . $return_url);
        exit();
      }
    }

    // Verification failed.
    $error = 'Verification failed. Please try again.';
  }

  // Calculate base path for "skip" link.
  $base_path = strtok($return_url, '?');
  $show_skip_link = ($base_path !== $return_url);

  // Output the challenge page.
  // Uses JavaScript to submit via GET with token in URL (POST is redirected by Pantheon edge).
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verify You\'re Human - ACCESS</title>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #f5f5f5;
      margin: 0;
      padding: 20px;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .container {
      background: white;
      padding: 40px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      max-width: 450px;
      width: 100%;
      text-align: center;
    }
    h1 { margin: 0 0 10px 0; color: #333; font-size: 24px; }
    p { color: #666; margin: 0 0 24px 0; line-height: 1.5; }
    .error { background: #fee; color: #c00; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .cf-turnstile { display: flex; justify-content: center; margin-bottom: 20px; }
    button {
      background: #0073e6;
      color: white;
      border: none;
      padding: 12px 32px;
      font-size: 16px;
      border-radius: 4px;
      cursor: pointer;
      transition: background 0.2s;
    }
    button:hover { background: #005bb5; }
    button:disabled { background: #ccc; cursor: not-allowed; }
    .skip-link { display: block; margin-top: 20px; color: #666; text-decoration: none; font-size: 14px; }
    .skip-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Quick Verification</h1>
    <p>To help protect our site from automated traffic, please complete this quick verification.</p>';

  // Show error from failed verification or other issues.
  if (!empty($error) || isset($_GET['error'])) {
    $error_msg = $error ?: 'Verification failed. Please try again.';
    echo '<div class="error">' . htmlspecialchars($error_msg) . '</div>';
  }

  // JavaScript handles form submission via GET with token in query string.
  echo '<div id="error-msg" class="error" style="display:none;"></div>
    <div class="cf-turnstile" data-sitekey="' . htmlspecialchars($site_key) . '" data-callback="onTurnstileSuccess"></div>
    <button type="button" id="submit-btn" onclick="submitVerification()" disabled>Continue</button>
    <script>
      var turnstileToken = null;
      var returnUrl = ' . json_encode($return_url) . ';

      function onTurnstileSuccess(token) {
        turnstileToken = token;
        document.getElementById("submit-btn").disabled = false;
      }

      function submitVerification() {
        if (!turnstileToken) {
          document.getElementById("error-msg").textContent = "Please complete the verification first.";
          document.getElementById("error-msg").style.display = "block";
          return;
        }
        // Redirect to verify endpoint with token in URL (avoids POST redirect issue).
        var verifyUrl = "/turnstile-verify?token=" + encodeURIComponent(turnstileToken) + "&return=" + encodeURIComponent(returnUrl);
        window.location.href = verifyUrl;
      }
    </script>';

  if ($show_skip_link) {
    echo '<a href="' . htmlspecialchars($base_path) . '" class="skip-link">Continue without filters &rarr;</a>';
  }

  echo '</div>
</body>
</html>';
  exit();
}

// Emergency netblock denylist (2026-07-01 facet-crawl outage).
// A distributed bot crawl from these /16 ranges took the site down by walking
// faceted URLs. Return a cheap 403 for faceted requests from these networks
// before any expensive query or the Turnstile flow runs. Remove once the
// attack subsides. Scoped to faceted requests (?f[]) to limit collateral to
// the abuse pattern; legitimate non-facet browsing from these ranges is
// unaffected.
if (($env === 'live') && !empty($_GET['f'])) {
  $_denylist_prefixes = ['84.75.', '145.223.', '57.141.', '82.38.'];
  $_client_ip = _get_real_client_ip();
  foreach ($_denylist_prefixes as $_prefix) {
    if (strpos($_client_ip, $_prefix) === 0) {
      header('HTTP/1.1 403 Forbidden');
      header('Retry-After: 3600');
      exit('Access denied.');
    }
  }
}

// Emergency multi-facet kill switch (2026-07-01 facet-crawl outage).
// While the attack is active, return a cheap 503 for anonymous requests with
// many facets (3+) before Drupal bootstraps — this is the expensive,
// uncacheable URL space the bots walk. Real single- and two-facet filtering
// still works: those fall through to the solvable Turnstile challenge below,
// so legitimate links (e.g. a two-facet URL shared in an email) let a human
// prove themselves once instead of hitting a dead-end 503. The threshold was
// raised from 2 to 3 on 2026-07-13 after a CCMNet affinity-group email sent a
// two-facet mentorships link that the 503 was blocking for real recipients.
// Controlled by an env var so it can be flipped off WITHOUT a deploy:
// unset FACET_KILL_SWITCH (or set to '0') once traffic subsides.
if ($env === 'live'
  && getenv('FACET_KILL_SWITCH') !== '0'
  && isset($_GET['f']) && is_array($_GET['f']) && count($_GET['f']) >= 3) {
  // Exempt genuine logged-in users only: a real Drupal session cookie is
  // SESS/SSESS followed by a 32-char hex id. Do NOT exempt on mere presence of
  // any SESS* name (that would let our own SESSturnstileverified cookie, or a
  // fabricated one, bypass this) — everyone else falls through to the real
  // Turnstile check below, which validates the cookie properly.
  $_is_authed = FALSE;
  foreach (array_keys($_COOKIE) as $_cn) {
    if (preg_match('/^S?SESS[0-9a-f]{32}$/', $_cn)) {
      $_is_authed = TRUE;
      break;
    }
  }
  if (!$_is_authed) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    exit('Filtered search is temporarily unavailable due to unusual traffic. Please try again later.');
  }
}

// Turnstile-based bot protection for faceted searches.
// Enable on live environment OR when TURNSTILE_ENABLED is explicitly 'true'.
$enable_turnstile = ($env === 'live') || (getenv('TURNSTILE_ENABLED') === 'true');

// Handle Turnstile verification endpoint (receives token via GET to avoid POST redirect issue).
if ($enable_turnstile && strpos($_SERVER['REQUEST_URI'], '/turnstile-verify') === 0) {
  $token = isset($_GET['token']) ? $_GET['token'] : '';
  $return_url = isset($_GET['return']) ? $_GET['return'] : '/';
  $secret_key = _get_turnstile_secret('TURNSTILE_SECRET_KEY');
  // SESS prefix required so Pantheon's CDN forwards the cookie to origin.
  $cookie_name = 'SESSturnstileverified';
  $cookie_duration = 86400; // 24 hours

  // Sanitize return URL.
  if (!preg_match('/^\/[a-zA-Z0-9\-\_\/\?\&\=\[\]\%\.\+\:\#\~\@\!\'\(\)\,\;\* ]*$/', $return_url)) {
    $return_url = '/';
  }

  if (!empty($token) && !empty($secret_key)) {
    // Verify with Cloudflare.
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      // Do not send 'remoteip': Cloudflare validates it against the IP that
      // solved the widget, and behind Pantheon's load balancer the client IP
      // we resolve doesn't reliably match Cloudflare's edge view, which makes
      // siteverify return success=false and loops the user back to the
      // challenge. The token alone is sufficient proof of a valid solve.
      CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $secret_key,
        'response' => $token,
      ]),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
      $result = json_decode($response, true);

      if (!empty($result['success'])) {
        // Verification successful - set cookie and redirect.
        // Always Secure (Pantheon's LB terminates TLS, leaving $_SERVER['HTTPS']
        // unset at origin) and SameSite=None so the cookie survives the
        // cross-context navigation back from the challenge.
        setcookie($cookie_name, hash('sha256', $secret_key . _get_real_client_ip()), [
          'expires' => time() + $cookie_duration,
          'path' => '/',
          'secure' => true,
          'httponly' => true,
          'samesite' => 'None',
        ]);

        header('Location: ' . $return_url);
        exit();
      }
    }
  }

  // Verification failed - redirect back to challenge with error.
  $challenge_url = '/turnstile-challenge?return=' . urlencode($return_url) . '&error=1';
  // Never let the edge cache a challenge redirect — a cached challenge would be
  // served to already-verified visitors and loop them.
  header('Cache-Control: no-store, no-cache, private, max-age=0');
  header('Location: ' . $challenge_url);
  exit();
}

// Handle Turnstile challenge page requests.
// This intercepts /turnstile-challenge before Drupal routes it.
if ($enable_turnstile && strpos($_SERVER['REQUEST_URI'], '/turnstile-challenge') === 0) {
  $return_url = isset($_GET['return']) ? $_GET['return'] : '/';
  _serve_turnstile_challenge($return_url);
}

if ($enable_turnstile && isset($_SERVER['QUERY_STRING'])) {
  // Skip Turnstile for API paths — these are consumed by MCP servers and
  // external integrations, not browsers. The page display is still protected.
  $is_api_path = strpos($_SERVER['REQUEST_URI'], '/api/') === 0;

  // Count unique facet parameters.
  // PHP parses f[0]=value as $_GET['f'][0], so check for 'f' array.
  $facet_count = 0;
  if (isset($_GET['f']) && is_array($_GET['f'])) {
    $facet_count = count($_GET['f']);
  }

  // Only check faceted pages (skip API paths).
  if ($facet_count > 0 && !$is_api_path) {
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

    // Skip verification for logged-in users.
    $is_logged_in = FALSE;
    foreach ($_COOKIE as $cookie_name => $cookie_value) {
      if (strpos($cookie_name, 'SESS') === 0 || strpos($cookie_name, 'SSESS') === 0) {
        $is_logged_in = TRUE;
        break;
      }
    }

    // Skip verification for AJAX requests (real users already on site).
    $is_ajax = isset($_GET['_drupal_ajax']) || isset($_SERVER['HTTP_X_REQUESTED_WITH']);

    if (!$is_ajax && !$is_logged_in) {
      // First line of defense: block obvious bots immediately.
      $known_bots = [
        'bot', 'Bot', 'BOT', 'crawler', 'Crawler', 'spider', 'Spider',
        'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot', 'BLEXBot',
        'YandexBot', 'Googlebot', 'bingbot', 'Baiduspider', 'Sogou', 'Exabot',
        'facebot', 'ia_archiver', 'Screaming Frog', 'python', 'Python',
        'Go-http-client', 'Java/', 'wget', 'curl', 'libwww', 'lwp-trivial',
        'httrack', 'nutch', 'msnbot', 'Discordbot', 'WhatsApp', 'Twitterbot',
        'facebookexternalhit', 'LinkedInBot', 'Slackbot', 'Telegram', 'Signal',
        'DataForSeoBot', 'SeznamBot', 'BingPreview', 'PageSpeed', 'Lighthouse',
        'Chrome-Lighthouse', 'HeadlessChrome', 'PhantomJS', 'SlimerJS',
        'CensysInspect', 'NetcraftSurveyAgent', 'masscan', 'nmap',
      ];

      foreach ($known_bots as $bot) {
        if (stripos($user_agent, $bot) !== FALSE) {
          error_log('Blocked known bot faceted request: ' . $_SERVER['REQUEST_URI'] . ' | UA: ' . $user_agent);
          header("HTTP/1.1 403 Forbidden");
          echo 'Access denied.';
          exit();
        }
      }

      // Second line of defense: Turnstile verification for everyone else.
      $turnstile_secret = _get_turnstile_secret('TURNSTILE_SECRET_KEY');
      // SESS prefix required so Pantheon's CDN forwards the cookie to origin.
      $cookie_name = 'SESSturnstileverified';

      // Verify the cookie is valid (matches expected hash).
      $cookie_valid = FALSE;
      if (isset($_COOKIE[$cookie_name]) && !empty($turnstile_secret)) {
        $expected_hash = hash('sha256', $turnstile_secret . _get_real_client_ip());
        $cookie_valid = hash_equals($expected_hash, $_COOKIE[$cookie_name]);
      }

      if (!$cookie_valid && !empty($turnstile_secret)) {
        // No valid verification cookie - redirect to Turnstile challenge.
        $challenge_url = '/turnstile-challenge?return=' . urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . $challenge_url);
        exit();
      }

      // If Turnstile is not configured, fall back to blocking multiple facets.
      if (empty($turnstile_secret) && $facet_count >= 2) {
        header("HTTP/1.1 503 Service Unavailable");
        header("Retry-After: 60");
        echo '<!DOCTYPE html>
<html>
<head>
    <title>Service Temporarily Unavailable</title>
</head>
<body>
    <h1>Service Temporarily Unavailable</h1>
    <p>Please use fewer filters or try again later.</p>
    <p><a href="/">Return to the homepage</a></p>
</body>
</html>';
        exit();
      }
    }
  }
}

// Below configuration uses a redis backend and will limit each
// crawler / bot (identified by User-Agent string) to a maximum of 300
// requests every 600 seconds.
//
// Regular traffic (human visitors and bots not openly identifying as bots)
// will be limited to a maximum of 600 requests per visitor
// (identified by IP address + User-Agent string) every 600 seconds.
//
// Regular traffic will additionally be limited at the ASN-level to a
// maximum of 600 requests per ASN every 600 seconds.
// See https://en.wikipedia.org/wiki/Autonomous_system_(Internet)
// Enable or disable rate limiting. Required.
$settings['crawler_rate_limit.settings']['enabled'] = TRUE;

// Supported backends: redis, memcached, apcu. Required.
if (defined(
 'PANTHEON_ENVIRONMENT'
) && !InstallerKernel::installationAttempted(
) && extension_loaded('redis')) {
  $settings['crawler_rate_limit.settings']['backend'] = 'redis';
}
else {
  $settings['crawler_rate_limit.settings']['backend'] = 'apcu';
}

// Limit for crawler / bot traffic (visitors that openly identify as
// crawlers / bots). Required.
$settings['crawler_rate_limit.settings']['bot_traffic'] = [
  // Time interval in seconds. Must be whole number greater than zero.
  'interval' => 600,
  // Number of requests allowed in the given time interval per crawler or
  // bot (identified by User-Agent string). Must be a whole number greater
  // than zero.
  'requests' => 100,
];

// Limits for regular website traffic (visitors that don't openly identify
// as crawlers / bots). Optional. Omit or set to 0 to disable.
// Visitor-level (IP address + User-Agent string) regular traffic rate
// limit.
$settings['crawler_rate_limit.settings']['regular_traffic'] = [
  // Time interval in seconds. Must be whole number greater than zero.
  'interval' => 600,
  // Number of requests allowed in the given time interval per regular
  // visitor (identified by combination of IP address + User-Agent string).
  'requests' => 300,
];
// Autonomous system-level (ASN) regular traffic rate limit.
// Requires geoip2/geoip2 and associated ASN Database.
// See https://github.com/maxmind/GeoIP2-php
// See https://dev.maxmind.com/geoip/docs/databases/asn#binary-database
$settings['crawler_rate_limit.settings']['regular_traffic_asn'] = [
  // Time interval in seconds. Must be whole number greater than zero.
  'interval' => 600,
  // Number of requests allowed in the given time interval per autonomous
  // system number (ASN).
  'requests' => 300,
  // Absolute path to the local ASN Database file. Must be an up-to-date,
  // GeoLite2/GeoIP2 binary ASN Database. Consider updating automatically
  // via GeoIP Update or cron.
  // See https://dev.maxmind.com/geoip/updating-databases
  'database' => '/app/web/sites/default/files/private/geoip2/GeoLite2-ASN.mmdb',
];

// Automatically generated include for settings managed by ddev.
$ddev_settings = __DIR__ . '/settings.ddev.php';
if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($ddev_settings)) {
  require $ddev_settings;
}
