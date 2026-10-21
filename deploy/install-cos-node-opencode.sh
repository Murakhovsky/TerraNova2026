#!/usr/bin/env bash
set -Eeuo pipefail
# Installs CLI tooling, not COS. Does not log in to ChatGPT or start a server.
source /etc/os-release
if [[ "$ID" != "ubuntu" ]]; then echo "Unsupported OS: $ID" >&2; exit 2; fi
if ! sudo -n true >/dev/null 2>&1; then echo "Passwordless sudo unavailable" >&2; exit 3; fi
for tool in curl gpg dpkg; do command -v "$tool" >/dev/null || { echo "Missing $tool" >&2; exit 4; }; done
ARCH="$(dpkg --print-architecture)"
if [[ "$ARCH" != "amd64" && "$ARCH" != "arm64" ]]; then
  echo "Unsupported NodeSource architecture: $ARCH" >&2; exit 5
fi
echo "CHECK host:"
printf 'os=%s arch=%s\n' "$PRETTY_NAME" "$ARCH"
df -h / | tail -n 1
free -h | sed -n '1,3p'

echo "STEP 1: Configure signed NodeSource Node.js 24 LTS apt repository."
sudo -n install -d -m 0755 /usr/share/keyrings
TMP_GPG="$(mktemp)"
TMP_ASC="$(mktemp)"
trap 'rm -f "$TMP_GPG" "$TMP_ASC"' EXIT
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key -o "$TMP_ASC"
gpg --batch --yes --dearmor -o "$TMP_GPG" "$TMP_ASC"
sudo -n install -m 0644 "$TMP_GPG" /usr/share/keyrings/nodesource.gpg
printf 'Types: deb\nURIs: https://deb.nodesource.com/node_24.x\nSuites: nodistro\nComponents: main\nArchitectures: %s\nSigned-By: /usr/share/keyrings/nodesource.gpg\n' \
  "$ARCH" | sudo -n tee /etc/apt/sources.list.d/nodesource.sources >/dev/null
printf 'Package: nodejs\nPin: origin deb.nodesource.com\nPin-Priority: 600\n' \
  | sudo -n tee /etc/apt/preferences.d/cos-nodejs >/dev/null
sudo -n apt-get update -q
sudo -n env DEBIAN_FRONTEND=noninteractive apt-get install -y -q nodejs
NODE_MAJOR="$(node -p 'Number(process.versions.node.split(".")[0])')"
if [[ "$NODE_MAJOR" != "24" ]]; then
  echo "Expected Node 24 LTS, detected $(node --version)" >&2; exit 6
fi
command -v npm >/dev/null || { echo "npm missing after Node installation" >&2; exit 6; }
echo "VERIFY node=$(node --version) npm=$(npm --version)"

echo "STEP 2: Migrate the GLOBAL OpenCode executable from V1 to V2."
# V1 opencode-ai and V2 @opencode/cli both install an 'opencode' binary.
# Only npm's global installations are changed. User-owned OpenCode
# credentials, conversations and ~/.config/opencode are NEVER touched.
# npm 11 blocks postinstall scripts unless the package is explicitly allowed.
# V2 needs its own postinstall script to select the native executable.
if command -v pgrep >/dev/null 2>&1 && pgrep -u "$(id -u)" -x opencode >/dev/null 2>&1; then
  echo "OpenCode is currently running for the SSH user. Exit OpenCode TUI and rerun this workflow." >&2
  exit 17
fi
sudo -n env npm_config_update_notifier=false npm uninstall --global opencode-ai @opencode/cli
sudo -n env npm_config_update_notifier=false npm install --global --no-audit --no-fund \
  --allow-scripts=@opencode/cli @opencode/cli

command -v opencode >/dev/null || { echo "OpenCode binary missing" >&2; exit 7; }
OPENCODE_VERSION="$(opencode --version)"
if [[ "$OPENCODE_VERSION" != 2.* ]]; then
  echo "Expected OpenCode V2, detected '$OPENCODE_VERSION'" >&2; exit 8
fi
echo "VERIFY opencode=$OPENCODE_VERSION"
echo "VERIFY location=$(command -v opencode)"
echo "VERIFY version=V2, CLI only; no OpenCode server started."
echo "VERIFY ChatGPT credentials and user configuration remain untouched."
echo "NODE_OPENCODE_BOOTSTRAP_SUCCESS"
