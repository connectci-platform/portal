<?php

namespace Mih\Robo\Plugin\Commands;

use Mih\ComposerPins\PinChecker;
use Robo\Exception\TaskException;
use Robo\Result;
use Robo\Robo;
use Robo\Tasks;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Defines commands in the "gh" namespace.
 */
class GhCommands extends Tasks {

  /**
   * Iterate tag number.
   *
   * @command gh:tag
   * @description take current tag number and iterate.
   */
  public function iterateTag(array $args) {
    if ($args) {
      $current_tag = $args[0];
      $tag = explode('.', $current_tag);
      $tag[2] = $tag[2]+1;
      $new_tag = implode('.', $tag);
      return $new_tag;
    }

  }

  /**
   * Command to run to keep codespaces alive.
   *
   * @command gh:keepalive
   * @description Continuous loop that says bing.
   */
  public function keepAlive() {
    while (1) {
      $this->say("🔁 Bing");
      $this->_exec("sleep 120");
    }
  }

  /**
   * Pull latest Database from artifacts.
   *
   * @command gh:pulldb
   * @description Pulls latest database artifact from Github.
   */
  public function pulldb() {
    $this->say("Downloading latest database backup from GitHub artifacts...");

    // Create a temporary directory for download to avoid path traversal issues
    // (GitHub CLI v2.63.1+ has stricter path validation that can cause false positives)
    $downloadDir = 'artifact-download-tmp';
    if (!file_exists($downloadDir)) {
      mkdir($downloadDir, 0755, true);
    }

    // Download the artifact to explicit directory to work around gh CLI path traversal check
    $result = $this->_exec("gh run download -R github.com/connectci-platform/portal -n amp-daily-backup -D $downloadDir");

    // Ensure backups directory exists
    if (!file_exists('backups')) {
      $this->_exec("mkdir -p backups");
    }

    // Check what files were downloaded
    $this->say("Checking downloaded files...");
    $this->_exec("ls -la $downloadDir");

    $found_file = false;
    $target_file = 'backups/site.sql.gz';

    // Check for various possible file names in download directory
    if (file_exists("$downloadDir/site.sql.gz")) {
      $this->say("Found site.sql.gz");
      $this->_exec("mv $downloadDir/site.sql.gz $target_file");
      $found_file = true;
    }
    elseif (file_exists("$downloadDir/site.sql")) {
      $this->say("Found site.sql, compressing...");
      $this->_exec("gzip $downloadDir/site.sql");
      $this->_exec("mv $downloadDir/site.sql.gz $target_file");
      $found_file = true;
    }

    if (!$found_file) {
      $this->say("❗️ No database file found. Available files:");
      $this->_exec("find $downloadDir -name '*.sql*' -o -name 'site.*'");
      // Clean up temp directory
      $this->_exec("rm -fR $downloadDir");
      throw new \Exception('Failed to find database backup file');
    }

    // Clean up temp directory
    $this->_exec("rm -fR $downloadDir");

    // Remove old backup if it exists
    $prev_backup = 'backups/site.sql.gz.old';
    if (file_exists($target_file)) {
      $this->_exec("cp $target_file $prev_backup");
    }

    $this->say("✅ Database backup downloaded successfully to $target_file!");
  }

  /**
   * Pull latest files backup from artifacts.
   *
   * @command gh:pullfiles
   * @description Pulls latest files artifact from Github.
   */
  public function pullfiles() {
    $prev_files = 'web/sites/default/files';

    // Never overwrite a symlinked files directory; it may point elsewhere.
    if (is_link($prev_files)) {
      $this->say("$prev_files is a symlink; refusing to overwrite it. Remove the link first to pull fresh files.");
      return;
    }

    $this->say("Downloading latest file backup from GitHub artifacts...");

    // List available workflows/runs to debug
    $this->_exec("gh run list -R github.com/connectci-platform/portal -L 5");

    $date = date('ymd-H.i');

    // Create a temporary directory for download to avoid path traversal issues
    // (GitHub CLI v2.63.1+ has stricter path validation that can cause false positives)
    $downloadDir = 'artifact-download-tmp';
    if (!file_exists($downloadDir)) {
      mkdir($downloadDir, 0755, true);
    }

    // Download the artifact to explicit directory to work around gh CLI path traversal check
    $result = $this->_exec("gh run download -R github.com/connectci-platform/portal -n amp-file-backup -D $downloadDir");
    if (!$result->wasSuccessful()) {
      $this->_exec("rm -fR $downloadDir");
      throw new \Exception('Failed to download files artifact');
    }

    // Check what files were downloaded
    $this->say("Checking downloaded files...");
    $this->_exec("ls -la $downloadDir");

    // Check if files.tar.gz exists in the download directory
    $tarFile = "$downloadDir/files.tar.gz";
    if (!file_exists($tarFile)) {
      $this->say("❗️ No files.tar.gz found. Available files:");
      $this->_exec("find $downloadDir -name '*.tar.gz' -o -name 'files.*'");
      // Clean up temp directory
      $this->_exec("rm -fR $downloadDir");
      throw new \Exception('Failed to find files backup file');
    }

    // Extract inside the temp directory, not the project root.
    $extract = $this->_exec("tar -xzf $tarFile -C $downloadDir");
    if (!$extract->wasSuccessful()) {
      $this->_exec("rm -fR $downloadDir");
      throw new \Exception('Failed to extract files backup');
    }
    if (!is_dir("$downloadDir/files")) {
      $this->_exec("rm -fR $downloadDir");
      throw new \Exception('Extracted files backup does not contain a files directory');
    }

    // Move the old files directory aside. Inside DDEV the files directory is
    // a bind mount (upload_dirs) and cannot be moved, so fall back to
    // replacing its contents in place.
    $moved_aside = !file_exists($prev_files)
      || $this->_exec("mv $prev_files /tmp/files-$date 2>/dev/null")->wasSuccessful();

    if ($moved_aside) {
      if (file_exists("/tmp/files-$date")) {
        $this->say("Old files moved to /tmp/files-$date");
      }
      $move = $this->_exec("mv $downloadDir/files $prev_files");
      if (!$move->wasSuccessful()) {
        $this->_exec("rm -fR $downloadDir");
        throw new \Exception("Failed to move extracted files into $prev_files");
      }
    }
    else {
      $this->say("$prev_files cannot be moved (mount point); replacing its contents in place.");
      $clear = $this->_exec("find $prev_files -mindepth 1 -delete 2>/dev/null || sudo find $prev_files -mindepth 1 -delete");
      if (!$clear->wasSuccessful()) {
        $this->_exec("rm -fR $downloadDir");
        throw new \Exception("Could not clear $prev_files");
      }
      // Trailing "/." copies dotfiles such as .htaccess too.
      $copy = $this->_exec("cp -a $downloadDir/files/. $prev_files/");
      if (!$copy->wasSuccessful()) {
        $this->_exec("rm -fR $downloadDir");
        throw new \Exception("Failed to copy extracted files into $prev_files");
      }
    }

    // Clean up
    $this->_exec("rm -fR $downloadDir");

    $this->say("✅ File backup downloaded successfully!");
  }

  /**
   * Create github pull request.
   *
   * @command gh:pr
   * @description Create a pull request.
   */
  public function ghpr() {
    $full_branch = shell_exec("git rev-parse --abbrev-ref HEAD");
    $full_branch = preg_replace('/\r\n|\r|\n/', '', $full_branch);

    $target_branch = $full_branch == 'md-dev' ? 'main' : 'md-dev';

    $branch_parts = explode("-", $full_branch);
    $issue_number = $branch_parts[1];
    $issue_number = preg_replace('/\r\n|\r|\n/', '', $issue_number);

    $summary = $this->getJiraSummary($issue_number);
    $description = $summary !== '' ? $summary : $this->ask("Describe context / purpose for this PR");
    $title = $this->buildPrTitle($issue_number, $summary);
    $custom_repos = $this->findCustomModuleRepos($issue_number);

    $body = $this->buildPrBody($description, $issue_number, '-');

    $this->say("Creating PR for D8-$issue_number");

    $pr_urls = [];

    // Create main repo PR first.
    $main_pr_url = $this->createPr(NULL, NULL, $target_branch, $title, $body);
    if ($main_pr_url) {
      $pr_urls[] = $main_pr_url;
    }

    // Create PRs for pinned custom module repos.
    foreach ($custom_repos as $repo_info) {
      $this->say("Creating PR for {$repo_info['package']} ({$repo_info['ghRepo']})...");

      $default_branch = trim(shell_exec(
        "gh repo view " . escapeshellarg($repo_info['ghRepo']) .
        " --json defaultBranchRef --jq '.defaultBranchRef.name' 2>/dev/null"
      ) ?? '');

      if ($default_branch === '') {
        $this->say("⚠️  Could not detect default branch for {$repo_info['ghRepo']}, skipping.");
        continue;
      }

      $pr_url = $this->createPr($repo_info['ghRepo'], $repo_info['branch'], $default_branch, $title, $body);
      if ($pr_url) {
        $pr_urls[] = $pr_url;
      }
    }

    // Cross-reference all created PRs.
    if (!empty($pr_urls)) {
      $this->crossReferencePrs($pr_urls, $description, $issue_number);
    }

    // Print summary.
    $this->say("\n✅ Created PRs:");
    foreach ($pr_urls as $url) {
      $this->say("  - $url");
    }

    // Write all PR links + MultiDev link into the Jira ticket description.
    if (!empty($pr_urls)) {
      $this->updateJiraDescription($pr_urls, $issue_number);
    }
  }

  /**
   * Reset pinned custom module versions to match a base branch.
   *
   * @command gh:unpin
   * @description Resets custom module versions in composer.json/composer.lock to the versions on origin/<base>. Never commits.
   * @option base Base branch to compare against (md-dev or main).
   */
  public function ghUnpin(array $opts = ['base' => 'main']) {
    $base = (string) ($opts['base'] ?? 'main');
    if (!preg_match('/^[A-Za-z0-9._\/-]+$/', $base)) {
      $this->say("❗️ Invalid base branch name: $base");
      return 1;
    }

    $composer_path = getcwd() . '/composer.json';
    $working = file_exists($composer_path) ? json_decode(file_get_contents($composer_path), TRUE) : NULL;
    if (!is_array($working)) {
      $this->say("❗️ Could not read composer.json in " . getcwd());
      return 1;
    }

    exec("git fetch --no-tags --quiet origin " . escapeshellarg($base) . " 2>&1", $fetch_out, $fetch_code);
    if ($fetch_code !== 0) {
      $this->say("⚠️  Could not fetch origin/$base; continuing with the existing local ref.");
    }

    $base_json = shell_exec("git show " . escapeshellarg("origin/$base:composer.json") . " 2>/dev/null");
    $base_composer = is_string($base_json) ? json_decode($base_json, TRUE) : NULL;
    if (!is_array($base_composer)) {
      $this->say("❗️ Could not read composer.json from origin/$base.");
      return 1;
    }

    $mismatches = PinChecker::findMismatches($working, $base_composer);
    if (!$mismatches) {
      $this->say("✅ All custom packages match origin/$base.");
      return 0;
    }

    $has_gh = trim(shell_exec("which gh 2>/dev/null") ?? '') !== '';
    $risky = FALSE;
    foreach ($mismatches as $mismatch) {
      $package = $mismatch['package'];
      $this->say(PinChecker::formatMismatch($mismatch, $base));

      $current_branch = PinChecker::branchFromVersion($mismatch['current']);
      $expected_branch = PinChecker::branchFromVersion($mismatch['expected']);
      $repo = PinChecker::githubRepo($working, $package);
      if ($current_branch === '' || $expected_branch === '' || $repo === NULL) {
        // Not a branch pin we can compare (e.g. a tagged release).
        continue;
      }

      $ahead = NULL;
      if ($has_gh) {
        $path = "repos/$repo/compare/" . rawurlencode($expected_branch) . '...' . rawurlencode($current_branch);
        $out = shell_exec("gh api " . escapeshellarg($path) . " --jq .ahead_by 2>/dev/null");
        $out = is_string($out) ? trim($out) : '';
        if ($out !== '' && ctype_digit($out)) {
          $ahead = (int) $out;
        }
      }

      if ($ahead === NULL) {
        $this->say("⚠️  $package: could not verify whether branch $current_branch has commits not in $expected_branch (unverified).");
        $risky = TRUE;
      }
      elseif ($ahead > 0) {
        $this->say("⚠️  $package: branch $current_branch has $ahead commit(s) not in $expected_branch — the module PR may not be merged; resetting would drop that code from the portal.");
        $risky = TRUE;
      }
    }

    if ($risky && !$this->confirm("Some modules may have unmerged work. Reset the pins anyway?", FALSE)) {
      $this->say("Aborted; composer.json and composer.lock were not changed.");
      return 1;
    }

    $dev_packages = $working['require-dev'] ?? [];
    $names = [];
    foreach ($mismatches as $mismatch) {
      $package = $mismatch['package'];
      $dev = isset($dev_packages[$package]) ? ' --dev' : '';
      $result = $this->_exec("composer require --no-update$dev " . escapeshellarg($package . ':' . $mismatch['expected']));
      if (!$result->wasSuccessful()) {
        $this->say("❗️ Failed to update $package in composer.json. Stopping.");
        return 1;
      }
      $names[] = escapeshellarg($package);
    }

    $result = $this->_exec("composer update " . implode(' ', $names) . " --no-install --no-scripts");
    if (!$result->wasSuccessful()) {
      $this->say("❗️ composer update failed; composer.lock may be out of date. Review with git diff.");
      return 1;
    }

    $this->_exec("git diff --stat composer.json composer.lock");
    $this->say("Review the diff, then commit composer.json and composer.lock.");
    return 0;
  }

  /**
   * Fetch the Jira ticket summary (title) via acli.
   *
   * @return string
   *   The summary, or an empty string if acli is missing or returns nothing.
   */
  private function getJiraSummary(string $issueNumber): string {
    $acli_path = trim(shell_exec("which acli 2>/dev/null") ?? '');
    if ($acli_path === '') {
      return '';
    }
    $output = shell_exec("acli jira workitem view " . escapeshellarg("D8-$issueNumber") . " --fields summary --json 2>/dev/null") ?? '';
    $decoded = json_decode(trim($output), TRUE);
    return trim($decoded['fields']['summary'] ?? '');
  }

  /**
   * Build the PR title: "#NNNN - Ticket title", truncated to GitHub's limit.
   *
   * Falls back to "#NNNN" when no Jira summary is available.
   */
  private function buildPrTitle(string $issueNumber, string $summary): string {
    $max_length = 256;
    $title = "#$issueNumber";
    if ($summary !== '') {
      $title .= " - $summary";
    }
    if (mb_strlen($title) > $max_length) {
      $title = rtrim(mb_substr($title, 0, $max_length - 1)) . '…';
    }
    return $title;
  }

  /**
   * Find custom module repos pinned to this issue number in composer.json.
   *
   * @return array<array{package: string, ghRepo: string, branch: string}>
   */
  private function findCustomModuleRepos(string $issueNumber): array {
    $composer_path = getcwd() . '/composer.json';
    if (!file_exists($composer_path)) {
      return [];
    }

    $composer = json_decode(file_get_contents($composer_path), TRUE);
    if (!$composer) {
      return [];
    }

    $results = [];
    foreach (PinChecker::customPackages($composer) as $package => $version) {
      if (strpos($version, $issueNumber) === FALSE) {
        continue;
      }

      $gh_repo = PinChecker::githubRepo($composer, $package);
      if ($gh_repo === NULL) {
        continue;
      }

      $results[] = [
        'package' => $package,
        'ghRepo'  => $gh_repo,
        'branch'  => PinChecker::branchFromVersion($version),
      ];
    }

    return $results;
  }

  /**
   * Build the shared PR body template.
   */
  private function buildPrBody(string $description, string $issueNumber, string $prList): string {
    return "## Describe context / purpose for this PR
$description
## Issue link
https://cyberteamportal.atlassian.net/browse/D8-$issueNumber
## Any other related PRs?
$prList
## Link to MultiDev instance
http://md-$issueNumber-accessmatch.pantheonsite.io

## Checklist for PR author
- [ ] I have checked that the PR is ready to be merged
- [ ] I have reviewed the DIFF and checked that the changes are as expected
- [ ] I have assigned myself or someone else to review the PR";
  }

  /**
   * Create a PR and return its URL, or null on failure.
   *
   * @param string|null $repo GitHub owner/repo for --repo flag; null for current repo.
   * @param string|null $head Head branch; only used when $repo is provided.
   */
  private function createPr(?string $repo, ?string $head, string $base, string $title, string $body): ?string {
    $cmd = "gh pr create"
      . " --title " . escapeshellarg($title)
      . " --body " . escapeshellarg($body)
      . " --base " . escapeshellarg($base);

    if ($repo !== NULL) {
      $cmd .= " --repo " . escapeshellarg($repo);
      $cmd .= " --head " . escapeshellarg($head);
    }

    // Capture stdout (the PR URL); stderr goes directly to terminal.
    $output_lines = [];
    $return_code = 0;
    exec($cmd, $output_lines, $return_code);

    foreach (array_reverse($output_lines) as $line) {
      $line = trim($line);
      if (str_starts_with($line, 'https://')) {
        return $line;
      }
    }

    if ($return_code !== 0 || empty($output_lines)) {
      $this->say("⚠️  Could not create PR or parse URL from output.");
    }

    return NULL;
  }

  /**
   * Write PR links and MultiDev URL into the Jira description.
   *
   * Replaces the content under the "Related PRs / Multidev" heading (up to the
   * next heading), or appends that section if the ticket doesn't have one.
   * The description is sent as ADF so links and formatting render.
   */
  private function updateJiraDescription(array $prUrls, string $issueNumber): void {
    $acli_path = trim(shell_exec("which acli 2>/dev/null") ?? '');
    if ($acli_path === '') {
      $this->say("⚠️  acli not found — skipping Jira description update.");
      return;
    }

    $key = "D8-$issueNumber";
    $output = shell_exec("acli jira workitem view " . escapeshellarg($key) . " --fields description --json 2>/dev/null") ?? '';
    $decoded = json_decode(trim($output), TRUE);
    if (!is_array($decoded) || !array_key_exists('fields', $decoded)) {
      $this->say("⚠️  Could not read description for $key — skipping Jira description update.");
      return;
    }

    $doc = $decoded['fields']['description'] ?? NULL;
    if (!is_array($doc) || ($doc['type'] ?? '') !== 'doc') {
      $doc = ['type' => 'doc', 'version' => 1, 'content' => []];
    }
    $doc['content'] = $this->mergeRelatedSection($doc['content'] ?? [], $prUrls, $issueNumber);

    $tmp = tempnam(sys_get_temp_dir(), 'ghpr-adf-');
    file_put_contents($tmp, json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $result = $this->_exec(
      "acli jira workitem edit --key " . escapeshellarg($key) .
      " --description-file " . escapeshellarg($tmp) . " --yes"
    );
    unlink($tmp);

    if ($result->wasSuccessful()) {
      $this->say("✅ Jira description updated on $key");
    }
    else {
      $this->say("⚠️  Failed to update Jira description on $key");
    }
  }

  /**
   * Put the PR/MultiDev links under the "Related PRs / Multidev" heading.
   *
   * @param array $content
   *   Top-level ADF nodes of the description.
   * @param array $prUrls
   *   PR URLs to list.
   * @param string $issueNumber
   *   Jira issue number (without the D8- prefix).
   *
   * @return array
   *   The updated top-level ADF nodes.
   */
  private function mergeRelatedSection(array $content, array $prUrls, string $issueNumber): array {
    $heading_text = 'Related PRs / Multidev';
    $multidev_url = "http://md-$issueNumber-accessmatch.pantheonsite.io";
    $link = fn(string $url) => [
      'type' => 'text',
      'text' => $url,
      'marks' => [['type' => 'link', 'attrs' => ['href' => $url]]],
    ];

    $section = [
      [
        'type' => 'paragraph',
        'content' => [
          ['type' => 'text', 'text' => 'Pull Requests:', 'marks' => [['type' => 'em']]],
        ],
      ],
      [
        'type' => 'bulletList',
        'content' => array_map(fn(string $url) => [
          'type' => 'listItem',
          'content' => [['type' => 'paragraph', 'content' => [$link($url)]]],
        ], $prUrls),
      ],
      [
        'type' => 'paragraph',
        'content' => [
          ['type' => 'text', 'text' => 'MultiDev:', 'marks' => [['type' => 'em']]],
          ['type' => 'text', 'text' => ' '],
          $link($multidev_url),
        ],
      ],
    ];

    $heading_index = NULL;
    foreach ($content as $i => $node) {
      if (($node['type'] ?? '') !== 'heading') {
        continue;
      }
      $text = implode('', array_map(fn($child) => $child['text'] ?? '', $node['content'] ?? []));
      if (strcasecmp(trim($text), $heading_text) === 0) {
        $heading_index = $i;
        break;
      }
    }

    if ($heading_index === NULL) {
      $content[] = [
        'type' => 'heading',
        'attrs' => ['level' => 2],
        'content' => [['type' => 'text', 'text' => $heading_text]],
      ];
      return array_merge($content, $section);
    }

    // Section ends at the next heading, or the end of the document.
    $end = count($content);
    for ($i = $heading_index + 1; $i < count($content); $i++) {
      if (($content[$i]['type'] ?? '') === 'heading') {
        $end = $i;
        break;
      }
    }

    // Only replace the nodes this command manages ("Pull Requests:" and
    // "MultiDev:" lines, plus the PR bullet list). Anything else in the
    // section, such as a "Depends on:" line, is kept where it is.
    $kept = [];
    $insert_at = NULL;
    $prev_removed_label = NULL;
    for ($i = $heading_index + 1; $i < $end; $i++) {
      $node = $content[$i];
      $label = $this->managedLabel($node);
      $next_label = $i + 1 < $end ? $this->managedLabel($content[$i + 1]) : NULL;
      $is_pr_list = ($node['type'] ?? '') === 'bulletList'
        && ($prev_removed_label === 'pull requests:' || $next_label === 'multidev:');

      if ($label !== NULL || $is_pr_list) {
        $insert_at ??= count($kept);
        $prev_removed_label = $label;
        continue;
      }
      $prev_removed_label = NULL;
      $kept[] = $node;
    }

    $insert_at ??= count($kept);
    array_splice($kept, $insert_at, 0, $section);
    array_splice($content, $heading_index + 1, $end - $heading_index - 1, $kept);
    return $content;
  }

  /**
   * Return the lowercased label if a node is a managed paragraph.
   *
   * @param array $node
   *   An ADF node.
   *
   * @return string|null
   *   "pull requests:" or "multidev:" if the paragraph starts with that label,
   *   otherwise NULL.
   */
  private function managedLabel(array $node): ?string {
    if (($node['type'] ?? '') !== 'paragraph') {
      return NULL;
    }
    $text = strtolower(trim(implode('', array_map(fn($child) => $child['text'] ?? '', $node['content'] ?? []))));
    foreach (['pull requests:', 'multidev:'] as $label) {
      if (str_starts_with($text, $label)) {
        return $label;
      }
    }
    return NULL;
  }

  /**
   * Update all PRs to cross-reference each other in the related PRs section.
   */
  private function crossReferencePrs(array $prUrls, string $description, string $issueNumber): void {
    $pr_list = implode("\n", array_map(fn(string $url) => "- $url", $prUrls));
    $body = $this->buildPrBody($description, $issueNumber, $pr_list);
    $escaped_body = escapeshellarg($body);

    foreach ($prUrls as $pr_url) {
      $this->_exec("gh pr edit " . escapeshellarg($pr_url) . " --body $escaped_body");
    }
  }

}
