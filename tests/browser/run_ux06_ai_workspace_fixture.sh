#!/usr/bin/env bash
set -euo pipefail

# Integrator-only runner for the existing disposable Runtime CI Compose stack.
# No changes to a shared tenant, no provider profile, budget, Plan, Action or Hermes call.
if [[ "${GITHUB_ACTIONS:-}" != true || "${UX06_E2E_FIXTURE_SAFE:-}" != 1 || "${UX06_E2E_BASE_URL:-}" != http://127.0.0.1:8081 ]]; then
    echo 'UX06 fixture seeding is restricted to explicit disposable localhost GitHub CI.' >&2
    exit 2
fi
: "${MYSQL_USER:?Required disposable Compose user}" "${MYSQL_PASSWORD:?Required disposable Compose password}" "${MYSQL_DATABASE:?Required disposable Compose database}"
command -v php >/dev/null
command -v docker >/dev/null
command -v node >/dev/null
CHROME_PATH="${CHROME_PATH:-$(command -v google-chrome || command -v chromium || command -v chromium-browser || true)}"
test -n "$CHROME_PATH"

# Random, machine-generated SQL-safe strings, never production identifiers.
suffix="$(php -r 'echo bin2hex(random_bytes(6));')"
org="ux06-ci-$suffix"
foreign="ux06-other-$suffix"
export UX06_E2E_EMAIL="ux06-manager-$suffix@example.test"
export UX06_E2E_RESTRICTED_EMAIL="ux06-buyer-$suffix@example.test"
export UX06_E2E_PASSWORD="UX06-$(php -r 'echo bin2hex(random_bytes(16));')-Aa9!"
export UX06_E2E_RESTRICTED_PASSWORD="UX06-$(php -r 'echo bin2hex(random_bytes(16));')-Aa9!"
export UX06_E2E_CAMPAIGN_ID="mkt-$(php -r 'echo bin2hex(random_bytes(12));')"
export UX06_E2E_FOREIGN_CAMPAIGN_ID="mkt-$(php -r 'echo bin2hex(random_bytes(12));')"
export CHROME_PATH
owner_hash="$(php -r 'echo password_hash(getenv("UX06_E2E_PASSWORD"), PASSWORD_DEFAULT);')"
buyer_hash="$(php -r 'echo password_hash(getenv("UX06_E2E_RESTRICTED_PASSWORD"), PASSWORD_DEFAULT);')"
module_version="$(php -r '$m=require "app/Domains/Marketing/module.php"; echo $m["version"];')"
schema_version="$(php -r '$m=require "app/Domains/Marketing/module.php"; echo $m["schema_version"];')"
mysql_ci() {
    docker compose exec -T mysql mysql -N -s -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" "$@"
}

# Refuse collisions and provision new disposable tenants only.
test "$(mysql_ci -e "SELECT COUNT(*) FROM cos_organizations WHERE id IN ('$org','$foreign')")" = 0
mysql_ci <<SQL
INSERT INTO cos_organizations (id,name,status)
VALUES ('$org','UX06 isolated browser owner','ACTIVE'),('$foreign','UX06 isolated foreign tenant','ACTIVE');
INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status)
VALUES ('$org','$UX06_E2E_EMAIL','$owner_hash','UX06 fixture manager','manager','active'),
       ('$org','$UX06_E2E_RESTRICTED_EMAIL','$buyer_hash','UX06 fixture buyer','buyer','active');
INSERT INTO cos_organization_memberships (organization_id,user_id,role,status)
SELECT '$org',id,role,'ACTIVE' FROM tn_users
WHERE organization_id='$org' AND email IN ('$UX06_E2E_EMAIL','$UX06_E2E_RESTRICTED_EMAIL');
INSERT INTO cos_organization_modules (organization_id,module_id,enabled)
VALUES ('$org','marketing',1),('$foreign','marketing',1);
INSERT INTO cos_module_installations (organization_id,module_id,status,installed_version,schema_version)
VALUES ('$org','marketing','INSTALLED','$module_version','$schema_version'),
       ('$foreign','marketing','INSTALLED','$module_version','$schema_version');
INSERT INTO marketing_campaigns (organization_id,id,owner_id,name,business_objective,brief,product_refs_json,status,revision)
SELECT '$org','$UX06_E2E_CAMPAIGN_ID',CAST(id AS CHAR),'UX06 owned campaign','Browser read-only acceptance','No remote call',JSON_ARRAY(),'DRAFT',1
FROM tn_users WHERE organization_id='$org' AND email='$UX06_E2E_EMAIL';
INSERT INTO marketing_campaigns (organization_id,id,owner_id,name,business_objective,brief,product_refs_json,status,revision)
VALUES ('$foreign','$UX06_E2E_FOREIGN_CAMPAIGN_ID','fixture-owner','UX06 PRIVATE FOREIGN campaign','Foreign tenant negative','Must not disclose',JSON_ARRAY(),'DRAFT',1);
SQL

test "$(mysql_ci -e "SELECT COUNT(*) FROM marketing_campaigns WHERE (organization_id='$org' AND id='$UX06_E2E_CAMPAIGN_ID') OR (organization_id='$foreign' AND id='$UX06_E2E_FOREIGN_CAMPAIGN_ID')")" = 2
test "$(mysql_ci -e "SELECT COUNT(*) FROM cos_organization_memberships WHERE organization_id='$org' AND status='ACTIVE'")" = 2
export UX06_E2E_EXPECT_BLOCKED=1
node tests/browser/ux06_ai_workspace.mjs
