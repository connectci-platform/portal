<?php

namespace Mih\Robo\Plugin\Commands;

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
   * Pull latest Database from artifacts.
   *
   * @command gh:pullfiles
   * @description Pulls latest database artifact from Github.
   */
  public function pullfiles() {
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

    // Remove existing files directory if it exists
    $prev_files = 'web/sites/default/files';
    if (file_exists($prev_files)) {
      $result = $this->_exec("mv $prev_files /tmp/files-$date 2>/dev/null || sudo mv $prev_files /tmp/files-$date");
      if ($result->getExitCode() !== 0) {
        $this->_exec("sudo rm -rf $prev_files");
        $this->say("Old files removed (mv to /tmp failed).");
      }
      else {
        $this->say("Old files moved to /tmp/files-$date");
      }
    }

    // Extract and move files
    $this->_exec("tar -xzvf $tarFile");
    $this->_exec("mv files web/sites/default/files");

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

    $description = $this->getDescription($issue_number);
    $custom_repos = $this->findCustomModuleRepos($issue_number);

    $body = $this->buildPrBody($description, $issue_number, '-');

    // Copy template to clipboard (macOS only).
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'DAR') {
      $escaped = escapeshellarg($body);
      shell_exec("echo $escaped | pbcopy");
      $this->say("PR description template copied to clipboard!");
    }

    $this->say("Creating PR for D8-$issue_number");

    $pr_urls = [];

    // Create main repo PR first.
    $main_pr_url = $this->createPr(NULL, NULL, $target_branch, "#$issue_number", $body);
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

      $pr_url = $this->createPr($repo_info['ghRepo'], $repo_info['branch'], $default_branch, "#$issue_number", $body);
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

    // Post all PR links + MultiDev link to Jira ticket.
    if (!empty($pr_urls)) {
      $this->postPrJiraComment($pr_urls, $issue_number);
    }
  }

  /**
   * Fetch PR description from Jira via acli, or fall back to interactive prompt.
   */
  private function getDescription(string $issueNumber): string {
    $acli_path = trim(shell_exec("which acli 2>/dev/null") ?? '');
    if ($acli_path !== '') {
      $output = shell_exec("acli jira workitem view d8-$issueNumber --fields summary 2>/dev/null") ?? '';
      if ($output !== '') {
        // Try JSON parse first.
        $decoded = json_decode(trim($output), TRUE);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['summary'])) {
          return trim($decoded['summary']);
        }
        // Fall back to regex extraction.
        if (preg_match('/summary[:\s]+(.+)/i', $output, $matches)) {
          $summary = trim($matches[1]);
          if ($summary !== '') {
            return $summary;
          }
        }
      }
    }
    return $this->ask("Describe context / purpose for this PR");
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

    $repositories = $composer['repositories'] ?? [];
    $packages = array_merge(
      $composer['require'] ?? [],
      $composer['require-dev'] ?? []
    );

    $results = [];
    foreach ($packages as $package => $version) {
      if (strpos($version, $issueNumber) === FALSE) {
        continue;
      }

      // Extract the suffix (part after /).
      $suffix = str_contains($package, '/') ? substr(strstr($package, '/'), 1) : $package;

      // Find matching repository entry by key.
      $repo_url = NULL;
      foreach ($repositories as $key => $repo) {
        if ($key === $suffix && isset($repo['url'])) {
          $repo_url = $repo['url'];
          break;
        }
      }

      if ($repo_url === NULL) {
        continue;
      }

      // Extract owner/repo from GitHub URL.
      if (!preg_match('#github\.com[/:](.+?)(?:\.git)?$#', $repo_url, $m)) {
        continue;
      }
      $gh_repo = $m[1];

      // Strip -dev suffix to get the feature branch name.
      $branch = preg_replace('/-dev$/', '', $version);

      $results[] = [
        'package' => $package,
        'ghRepo'  => $gh_repo,
        'branch'  => $branch,
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
   * Post PR links and MultiDev URL as a Jira comment.
   */
  private function postPrJiraComment(array $prUrls, string $issueNumber): void {
    $acli_path = trim(shell_exec("which acli 2>/dev/null") ?? '');
    if ($acli_path === '') {
      $this->say("⚠️  acli not found — skipping Jira comment.");
      return;
    }

    $multidev_url = "http://md-$issueNumber-accessmatch.pantheonsite.io";

    $lines = ["*Pull Requests:*"];
    foreach ($prUrls as $url) {
      $lines[] = "- $url";
    }
    $lines[] = "";
    $lines[] = "*MultiDev:* $multidev_url";

    $comment = implode("\n", $lines);

    $result = $this->_exec(
      "acli jira workitem comment create --key " . escapeshellarg("D8-$issueNumber") .
      " --body " . escapeshellarg($comment)
    );

    if ($result->wasSuccessful()) {
      $this->say("✅ Jira comment added to D8-$issueNumber");
    }
    else {
      $this->say("⚠️  Failed to add Jira comment to D8-$issueNumber");
    }
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
