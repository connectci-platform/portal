#!/usr/bin/env bash
# Lists the runnable PHPUnit test files under one or more paths, one per line,
# sorted and de-duplicated. Shared by the PHPUnit action (to resolve a shard's
# targets) and the PHPUnit gate (to prove every test file is in some shard), so
# both sides use the same definition of "a test file".
#
# Usage: phpunit-list-tests.sh <path>... [--exclude <dir>]...
#
# A runnable test file is a *Test.php under a tests/ directory that does not
# declare an abstract class. *TestBase.php files are skipped by name, and
# anything under node_modules/ or vendor/ is ignored. A path that is a file is
# kept as given. Paths under an --exclude directory are dropped.
#
# A path that does not exist is an error, so a typo fails loud instead of
# quietly shrinking coverage.
set -euo pipefail

roots=()
excludes=()
while [ $# -gt 0 ]; do
  case "$1" in
    --exclude)
      excludes+=("${2%/}/")
      shift 2
      ;;
    *)
      roots+=("${1%/}")
      shift
      ;;
  esac
done

if [ ${#roots[@]} -eq 0 ]; then
  echo "phpunit-list-tests.sh: no paths given." >&2
  exit 1
fi

for root in "${roots[@]}"; do
  if [ -f "$root" ]; then
    echo "$root"
  elif [ -d "$root" ]; then
    find "$root" -type f -name '*Test.php' ! -name '*TestBase.php' \
      -path '*/tests/*' ! -path '*/node_modules/*' ! -path '*/vendor/*'
  else
    echo "phpunit-list-tests.sh: '$root' does not exist." >&2
    exit 1
  fi
done | while IFS= read -r file; do
  for exclude in ${excludes[@]+"${excludes[@]}"}; do
    case "$file" in
      "$exclude"*) continue 2 ;;
    esac
  done
  if grep -qE '^[[:space:]]*abstract[[:space:]]+class[[:space:]]' "$file"; then
    continue
  fi
  echo "$file"
done | LC_ALL=C sort -u
