#!/usr/bin/env bash
set -Eeuo pipefail
# UX-00 read-only merge dry-run: no remote refs, no credentials with write permission.
cd "$(git rev-parse --show-toplevel)"
source tests/ux00/integration-snapshot.env
: "${UX00_HEAD:?Set the exact UX-00 branch head}"
out="${UX00_REPORT_DIR:-tmp/ux00-integration-preflight}"
mkdir -p "$out/pairs"
report="$out/preflight-report.md"
names=(ux00 product marketing_stack finance)
heads=("$UX00_HEAD" "$PRODUCT_SHA" "$MARKETING_STACK_SHA" "$FINANCE_SHA")
prs=(432 "$PRODUCT_PR" "$MARKETING_STACK_PR" "$FINANCE_PR")
for sha in "$BASE_MAIN" "${heads[@]}"; do
  [[ "$sha" =~ ^[a-f0-9]{40}$ ]] || { echo "Invalid SHA $sha" >&2; exit 2; }
done

{
  echo "# UX-00 pinned cross-domain merge preflight"
  echo
  echo "- Base main: $BASE_MAIN"
  echo "- UX-00 #432: $UX00_HEAD"
  echo "- Product #$PRODUCT_PR: $PRODUCT_SHA"
  echo "- Marketing #435 → #$MARKETING_STACK_PR: $MARKETING_STACK_SHA"
  echo "- Finance #$FINANCE_PR: $FINANCE_SHA"
  echo "- CI: ${GITHUB_RUN_ID:-local}"
  echo
  echo "No pushes, deployments, runtime rollout flags or production writes."
  echo
  echo "## Ref freshness"
} > "$report"

status=0
git fetch --no-tags origin "$BASE_MAIN" "${heads[@]}" > "$out/fetch.log" 2>&1 || {
  cat "$out/fetch.log"; echo "Fetch failed" >> "$report"; exit 1;
}
remote_main="$(git ls-remote origin refs/heads/main | cut -f1)"
if [[ "$remote_main" == "$BASE_MAIN" ]]; then
  echo "- PASS: pinned main matches current main" >> "$report"
else
  echo "- STALE: pinned main $BASE_MAIN, observed $remote_main" >> "$report"
  status=1
fi
for i in "${!prs[@]}"; do
  observed="$(git ls-remote origin "refs/pull/${prs[i]}/head" | cut -f1)"
  if [[ "$observed" == "${heads[i]}" ]]; then
    echo "- PASS: #${prs[i]} exact head $observed" >> "$report"
  else
    echo "- STALE: #${prs[i]} pinned ${heads[i]}, observed ${observed:-missing}" >> "$report"
    status=1
  fi
done

echo >> "$report"
echo "## Pairwise merge-tree checks" >> "$report"
for ((i=0;i<${#names[@]};i++)); do
  for ((j=i+1;j<${#names[@]};j++)); do
    label="${names[i]}-vs-${names[j]}"
    if git merge-tree --write-tree "${heads[i]}" "${heads[j]}" > "$out/pairs/$label.log" 2>&1; then
      echo "- PASS $label" >> "$report"
    else
      status=1
      echo "- CONFLICT $label:" >> "$report"
      grep -E '^(CONFLICT|Auto-merging)' "$out/pairs/$label.log" \
        | head -40 | sed 's/^/  - /' >> "$report" || true
    fi
  done
done

echo >> "$report"
echo "## Combined local merge, dependency order" >> "$report"
git config user.name "COS UX00 preflight"
git config user.email "cos-ux00-preflight@users.noreply.github.com"
git switch --detach "$BASE_MAIN" > "$out/switch.log" 2>&1
git switch -c ux00-disposable-composite >> "$out/switch.log" 2>&1
for i in "${!names[@]}"; do
  name="${names[i]}"
  if git merge --no-ff --no-commit "${heads[i]}" > "$out/merge-$name.log" 2>&1; then
    git commit -m "local-only merge: $name" >> "$out/merge-$name.log" 2>&1
    echo "- PASS $name at $(git rev-parse --short HEAD)" >> "$report"
  else
    status=1
    echo "- BLOCKED $name, conflicting files:" >> "$report"
    git diff --name-only --diff-filter=U > "$out/unmerged-$name.txt"
    sed 's/^/  - /' "$out/unmerged-$name.txt" >> "$report"
    git merge --abort >> "$out/merge-$name.log" 2>&1 || true
  fi
done
git diff --check "$BASE_MAIN"..HEAD > "$out/diff-check.log" 2>&1 || status=1
{
  echo
  echo "## Outcome"
  if [[ "$status" == 0 ]]; then
    echo "CLEAN GIT MERGE. Local tree $(git rev-parse HEAD^{tree})."
    echo "NOT a Symfony/MySQL/Playwright integrated runtime certificate."
  else
    echo "BLOCKED by conflicts or stale SHA pins. See attached detailed logs."
    echo "No remote branch, staging or production changes were made."
  fi
} >> "$report"
cat "$report"
exit "$status"
