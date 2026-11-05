#!/bin/sh

# Fail on error
set -e

storeKey () {
  mkdir -p ~/.ssh/
  eval $(ssh-agent -s)
  echo "$SSH_PRIVATE_KEY" > ~/.ssh/private.key
  chmod 0600 ~/.ssh/private.key
  ssh-add ~/.ssh/private.key
  touch /root/.ssh/known_hosts
  chmod 600 /root/.ssh/known_hosts
  echo "Host *.drush.in" > /root/.ssh/config
  echo "    StrictHostKeyChecking no" >> /root/.ssh/config
  chmod 600 /root/.ssh/config
  cat /root/.ssh/config

  ssh-add -l
  git config --global --add safe.directory "*"
  git config --global --add safe.directory /github/workspace
}

terminusApi () {
  terminus auth:login --machine-token=$terminus_api
}

# Poll `terminus workflow:list` for running workflows on a single environment.
# terminus workflow:list ignores the .env suffix and returns every environment
# on the site, so we filter the csv on the env column ourselves.
# On timeout: exit 1 if <fatal> is non-empty, otherwise log and return 0.
waitForWorkflows () {
  site=$1
  env_filter=$2
  attempts=$3
  fatal=$4

  echo "Waiting for Pantheon workflows on $site.$env_filter to complete..."
  i=1
  while [ "$i" -le "$attempts" ]; do
    running=$(terminus workflow:list "$site" --format=csv --fields=env,status 2>/dev/null | tail -n +2 | tr -d '"' | awk -F, -v e="$env_filter" '$1 == e && $2 == "running" {c++} END{print c+0}')
    if [ "$running" = "0" ]; then
      echo "Workflows complete."
      return 0
    fi
    echo "Workflow still running on $env_filter... attempt $i/$attempts"
    sleep 15
    i=$((i + 1))
  done

  if [ -n "$fatal" ]; then
    echo "ERROR: Timed out waiting for Pantheon workflows on $site.$env_filter to complete." >&2
    exit 1
  else
    echo "Timed out waiting for Pantheon workflows on $site.$env_filter; continuing."
    return 0
  fi
}

# Run drush deploy on an environment and fail the step if it fails.
# Never pass --retry: a half-applied deploy must not be re-run automatically.
# config:status is informational only: live has harmless false/0 drift
# on 11 views, so it must not fail the job yet.
drushDeploy () {
  site=$1
  target_env=$2
  echo "Running drush deploy on $site.$target_env..."
  terminus remote:drush "$site.$target_env" -- deploy -y
  echo "Config status on $site.$target_env:"
  terminus remote:drush "$site.$target_env" -- config:status || true
}

# Print the IDs of the workflows on one environment, one per line.
# Workflow names contain quotes and commas, so parse JSON, not CSV.
workflowIds () {
  terminus workflow:list "$1" --format=json --fields=id,env 2>/dev/null | php -r '
    $rows = json_decode(stream_get_contents(STDIN), true) ?: [];
    foreach ($rows as $row) {
      if ($row["env"] === $argv[1]) {
        echo $row["id"], "\n";
      }
    }
  ' "$2"
}

# Wait for a workflow on <env> whose name contains <pattern> and whose ID is
# not in <known_ids> (newline-separated, from workflowIds) to appear and
# finish. Exits 1 if none appears within 2 minutes, if it fails, or if it's
# still running after 12 minutes.
waitForNewWorkflow () {
  site=$1
  target_env=$2
  pattern=$3
  known_ids=$4

  echo "Waiting for a new '$pattern' workflow on $site.$target_env..."
  i=1
  while [ "$i" -le 48 ]; do
    # workflow:list is newest first, so the first match is the newest.
    status=$(terminus workflow:list "$site" --format=json --fields=id,env,workflow,status 2>/dev/null | php -r '
      [, $env, $pattern, $known] = $argv;
      $known = array_filter(explode("\n", $known));
      $rows = json_decode(stream_get_contents(STDIN), true) ?: [];
      foreach ($rows as $row) {
        if ($row["env"] === $env
          && str_contains((string) $row["workflow"], $pattern)
          && !in_array($row["id"], $known, TRUE)) {
          echo $row["status"];
          exit(0);
        }
      }
      echo "none";
    ' "$target_env" "$pattern" "$known_ids")

    case "$status" in
      succeeded)
        echo "Workflow '$pattern' on $target_env finished."
        return 0
        ;;
      failed|aborted)
        echo "ERROR: Workflow '$pattern' on $site.$target_env ended with status '$status'." >&2
        exit 1
        ;;
      none)
        if [ "$i" -ge 8 ]; then
          echo "ERROR: No new '$pattern' workflow appeared on $site.$target_env within 2 minutes." >&2
          exit 1
        fi
        ;;
    esac
    echo "Workflow '$pattern' on $target_env: $status... attempt $i/48"
    sleep 15
    i=$((i + 1))
  done

  echo "ERROR: Timed out waiting for '$pattern' on $site.$target_env to finish." >&2
  exit 1
}

if [ "$runner" = cron ];
then
  #curl $dev_cron_url
  storeKey
  terminusApi
  terminus remote:drush $site_name.$env -- core-cron -v
fi

if [ "$runner" = cr ];
then
  storeKey
  terminusApi
  terminus remote:drush $site_name.$env -- cr
fi

if [ "$runner" = deploy ];
then
  storeKey
  terminusApi

  # Commits can't be compared directly between GitHub and the Pantheon build
  # repo, so we wait on and verify the environment we are deploying from.
  if [ "$env" = "test" ]; then
    source_env=dev
  elif [ "$env" = "live" ]; then
    source_env=test
  else
    echo "ERROR: Unknown deploy target '$env'; cannot determine source environment." >&2
    exit 1
  fi

  waitForWorkflows "$site_name" "$source_env" 40 fatal

  echo "Code log for $site_name.$source_env:"
  terminus env:code-log $site_name.$source_env

  if [ -n "$expected_sha" ]; then
    expected_sha8=$(printf '%.8s' "$expected_sha")
    # First entry whose labels include the source env (not simply the first
    # entry; dev commits sit above test's in the combined log).
    result=$(terminus env:code-log $site_name.$source_env --format=json | php -r '
      $rows = json_decode(stream_get_contents(STDIN), true);
      $env = $argv[1];
      foreach ($rows as $row) {
        $labels = $row["labels"];
        if (is_string($labels)) {
          $labels = array_map("trim", explode(",", $labels));
        }
        if (is_array($labels) && in_array($env, $labels, true)) {
          echo $row["hash"] . "\t" . $row["message"];
          exit(0);
        }
      }
    ' "$source_env")
    actual_hash=$(printf '%s' "$result" | cut -f1)
    actual_message=$(printf '%s' "$result" | cut -f2-)
    expected_prefix="[gh $expected_sha8]"
    case "$actual_message" in
      "$expected_prefix"*) : ;;
      *)
        echo "ERROR: Expected commit prefix '$expected_prefix' (from $expected_sha) on $site_name.$source_env, but found message '$actual_message' (hash: $actual_hash)." >&2
        if [ "$source_env" = "test" ]; then
          echo "Hint: If test was deployed out of band, re-run (M) Create Release." >&2
        else
          echo "Hint: Dispatch main.yml on main to build a fresh dev deploy." >&2
        fi
        exit 1
        ;;
    esac
  fi

  current_version=$(git describe --tags --abbrev=0)
  terminus env:deploy --note "Version: $current_version" $flags -- $site_name.$env

  # env:deploy waits for its own workflow. On test, --sync-content may also
  # start follow-on workflows (clone_database -> no_prod.php) that should finish
  # before updb/cim. Give them time to be queued, then wait for them.
  sleep 30
  waitForWorkflows "$site_name" "$env" 40 fatal
  drushDeploy "$site_name" "$env"
fi

if [ "$runner" = bkup ];
then
  storeKey
  terminusApi
  mkdir backups
  echo "Creating fresh backup on $site_name.$env..."
  terminus backup:create $site_name.$env --element=db
  terminus backup:get $site_name.$env --element=db --to=backups/site.sql.gz
fi

if [ "$runner" = bkup-files ];
then
  storeKey
  terminusApi
  mkdir backups
  file_url=$(terminus backup:get $site_name.$env --element=files)
  wget -O files.tar.gz $file_url
  tar -xzvf files.tar.gz
  rm -fR files_live/php/twig/*
  rm -fR files_live/private/20*
  rm -fR files_live/private/.keys
  rm -fR files_live/private/styles/*
  rm -fR files_live/private/webform/*
  mv files_live files
  tar -czvf files.tar.gz files
  rm -fR files
fi

if [ "$runner" = update ];
then
  storeKey
  git config --global --add safe.directory /github/workspace

  COMPOSER_IGNORE_PLATFORM_REQS=1

  git config --global user.email "$git_email"
  git config --global user.name "$git_name"

  original_directory=$(pwd)

  sh -c "composer config -g github-oauth.github.com $gh_token"

  composer install --no-dev --ignore-platform-reqs

  branch="${GITHUB_REF#refs/heads/}"
  if [ "$branch" = "main" ]; then
    branch="master"
  fi

  mkdir backups
  cd backups
  echo 'Cloning Pantheon repo'
  git clone $PANTHEON_GIT_REPO -b master accessmatch

  cd accessmatch
  # Check if the branch exists (locally or remotely)
  if git show-ref --verify --quiet "refs/heads/$branch" || git show-ref --verify --quiet "refs/remotes/origin/$branch"; then
    echo "Branch '$branch' exists. Checking it out..."
    git checkout "$branch"
  else
    echo "Branch '$branch' does not exist. Creating and checking it out..."
    git checkout -b "$branch"
  fi
  echo 'Pantheon repo'
  git config --get remote.origin.url

  cd $original_directory
  echo 'Github repo'
  git config --get remote.origin.url
  rm -fR .git
  mv backups/accessmatch/.git .
  echo 'Switched Pantheon repo'
  git branch
  git config --get remote.origin.url

  echo 'Remove some dev files/folders'
  # Remove all .txt files EXCEPT robots.txt — deleting robots.txt left prod
  # with a 404 robots.txt and unrestricted crawling of faceted pages.
  find . -type f -name "*.txt" -not -name "robots.txt" -delete
  find . -mindepth 1 -type f -name "*.txt" -not -path "*/.github/*" -not -name "robots.txt" -delete
  rm -fR .devcontainer
  rm -fR .editorconfig
  rm -fR .github
  rm -fR README.md
  rm -fR backups
  rm -fR robo
  rm -fR docroot/.editorconfig
  rm -fR docroot/sites/example.settings.local.php
  rm -fR docroot/sites/example.sites.php
  rm -fR tests
  rm -fR web/themes/contrib/bootstrap5/dist/bootstrap/5.2.3/.github/
  find web/modules/custom/ \( -name ".git" -o -name ".gitignore" -o -name ".gitmodules" -o -name ".gitattributes" \) -exec rm -rf -- {} +

  git checkout .gitignore

  echo 'Git status check'
  git status
  echo 'Add new files'
  git add .
  echo 'Commit changes'
  # Prefix the commit subject with the GitHub SHA so a Pantheon commit can be
  # traced back to the GitHub commit it was built from.
  sha8=$(printf '%.8s' "$GITHUB_SHA")
  commit_message="[gh $sha8] $message"
  if [ "$branch" = "master" ]; then
    # main always gets a commit, even with no build changes, so the marker
    # commit is always present for the deploy runner to verify against.
    git commit --allow-empty -m "$commit_message"
  else
    # A config/code-only branch can produce no build-artifact changes, in which
    # case there is nothing to commit. Skip the commit rather than let git's
    # non-zero exit abort the deploy (which would leave the multidev uncreated).
    if git diff --cached --quiet; then
      echo 'No build changes to commit; pushing existing branch state.'
    else
      git commit -m "$commit_message"
    fi
  fi
  echo 'status'
  git status

  terminusApi
  if [ "$branch" = "master" ]; then
    target_env=dev
  else
    target_env=$branch
  fi

  # A brand-new md-* branch has no environment yet; md_create
  # (PmdCommands::create) runs drush deploy for it.
  has_env=""
  if terminus env:info "$site_name.$target_env" >/dev/null 2>&1; then
    has_env=1
    known_ids=$(workflowIds "$site_name" "$target_env")
  fi

  # An md-* branch with no build changes pushes nothing, so no sync_code
  # workflow will appear. master always gets a commit (--allow-empty).
  needs_push=1
  if git rev-parse --verify --quiet "origin/$branch" >/dev/null \
    && [ "$(git rev-parse HEAD)" = "$(git rev-parse "origin/$branch")" ]; then
    needs_push=""
  fi

  echo 'push'
  git push origin $branch

  if [ -n "$has_env" ]; then
    if [ -n "$needs_push" ]; then
      waitForNewWorkflow "$site_name" "$target_env" "Sync code" "$known_ids"
    fi
    drushDeploy "$site_name" "$target_env"
  fi
fi

if [ "$runner" = composer_update ];
then
  storeKey
  git branch $drupal_update
  git checkout $drupal_update
  git remote set-url origin "https://$username:$gh_token@github.com/connectci-platform/portal.git"
  git config --global user.email \"$email\"
  git config --global user.name \"$username\"
  composer config -g github-oauth.github.com $gh_token
  composer install --no-dev --ignore-platform-reqs
  echo '-=-=-=-=-=-=-=- Composer update -=-=-=-=-=-=-=-'
  $robo ciupdate $drupal_update $version --no-interaction --verbose
  git push origin $drupal_update
fi

if [ "$runner" = deletepr ];
then
  branch=$PR_BRANCH
  echo $branch
  storeKey
  terminusApi
  terminus multidev:delete --delete-branch --yes -- accessmatch.$branch
fi

if [ "$runner" = sync ];
then
  storeKey
  terminusApi
  terminus env:clone-content "$site_name".live dev --yes --no-interaction
fi

if [ "$runner" = md_check ];
then
  storeKey
  terminusApi
  branch="${GITHUB_REF#refs/heads/}"
  touch md_check.txt
  vendor/bin/robo pmd:check $branch --no-interaction | grep -o "FALSE\|TRUE" > md_check.txt
fi

if [ "$runner" = md_create ];
then
  storeKey
  terminusApi
  branch="${GITHUB_REF#refs/heads/}"
  vendor/bin/robo pmd:create $branch --no-interaction
fi

if [ "$runner" = md_file_commands ];
then
  storeKey
  terminusApi
  branch="${GITHUB_REF#refs/heads/}"
  commands=$(cat robo/assets/md/$branch | tr -d '[:space:]')
  echo $commands
  terminus env:wake accessmatch.$branch
  # Wait for any active Pantheon workflows (sync_code, multidev create) on
  # this multidev only, not site-wide, to finish.
  waitForWorkflows accessmatch "$branch" 24 ""
  terminus remote:drush accessmatch.$branch -- domain:default $commands
  echo "Set domain to: $commands"
fi
