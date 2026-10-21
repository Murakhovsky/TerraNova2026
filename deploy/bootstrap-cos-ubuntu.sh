#!/usr/bin/env bash
set -Eeuo pipefail

# Fresh Ubuntu host preparation; this script intentionally does not deploy COS.
APP_ROOT="${1:?Pass DEV_PATH as the first argument}"
if [[ ! "$APP_ROOT" =~ ^/[A-Za-z0-9_./-]+$ || "$APP_ROOT" == "/" ]]; then
  echo "Refusing unsafe APP_ROOT." >&2; exit 2
fi
if [[ ! -r /etc/os-release ]]; then
  echo "Cannot identify host OS." >&2; exit 2
fi
source /etc/os-release
if [[ "${ID:-}" != "ubuntu" ]]; then
  echo "Expected Ubuntu, found ${ID:-unknown}." >&2; exit 2
fi
if ! sudo -n true >/dev/null 2>&1; then
  echo "Passwordless sudo unavailable; aborting without changes." >&2; exit 3
fi
if [[ -e "$APP_ROOT/docker-compose.yml" ]]; then
  echo "Refusing bootstrap on existing COS deployment; target already has compose." >&2; exit 4
fi

echo "PRECHECK os=$PRETTY_NAME"
echo "PRECHECK memory:"
free -m | sed -n '1,3p'
echo "PRECHECK root disk:"
df -h /
AVAILABLE_BYTES="$(df -B1 --output=avail / | tail -n 1 | tr -d ' ')"
if (( AVAILABLE_BYTES < 6 * 1024 * 1024 * 1024 )); then
  echo "At least 6 GiB free root disk required for swap + images." >&2
  exit 5
fi

echo "STEP 1: Configure a persistent 4 GiB swapfile."
if [[ -e /swapfile && ! -f /swapfile ]]; then
  echo "/swapfile exists and is not a regular file. Refusing overwrite." >&2; exit 6
fi
if [[ -f /swapfile ]]; then
  SWAP_SIZE="$(sudo -n stat -c '%s' /swapfile)"
  if (( SWAP_SIZE != 4294967296 )); then
    echo "/swapfile exists with unexpected size ($SWAP_SIZE). Refusing overwrite." >&2; exit 6
  fi
else
  sudo -n fallocate -l 4G /swapfile
fi
sudo -n chmod 600 /swapfile
if ! sudo -n swapon --noheadings --show=NAME | grep -Fxq /swapfile; then
  sudo -n mkswap /swapfile >/dev/null
  sudo -n swapon /swapfile
fi
if ! sudo -n grep -Eq '^[[:space:]]*/swapfile[[:space:]]+none[[:space:]]+swap[[:space:]]' /etc/fstab; then
  printf '%s\n' '/swapfile none swap sw 0 0' | sudo -n tee -a /etc/fstab >/dev/null
fi
printf 'vm.swappiness=10\n' | sudo -n tee /etc/sysctl.d/90-cos-swap.conf >/dev/null
sudo -n sysctl -q -p /etc/sysctl.d/90-cos-swap.conf

echo "STEP 2: Refresh Ubuntu apt indexes (no dist-upgrade)."
sudo -n apt-get update -q

echo "STEP 3: Install COS host prerequisites."
sudo -n env DEBIAN_FRONTEND=noninteractive apt-get install -y -q ca-certificates curl gnupg git rsync nginx certbot openssl

echo "STEP 4: Install Docker Engine + Compose via Docker official apt repository."
sudo -n install -m 0755 -d /etc/apt/keyrings
sudo -n curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo -n chmod a+r /etc/apt/keyrings/docker.asc
ARCH="$(dpkg --print-architecture)"
printf 'Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' \
  "$VERSION_CODENAME" "$ARCH" | sudo -n tee /etc/apt/sources.list.d/docker.sources >/dev/null
sudo -n apt-get update -q
sudo -n env DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo -n systemctl enable --now docker
sudo -n systemctl enable --now nginx

echo "STEP 5: Create clean application root (without creating secrets or data)."
if [[ ! -e "$APP_ROOT" ]]; then
  sudo -n install -d -m 0750 -o "$(id -un)" -g "$(id -gn)" "$APP_ROOT"
fi
test -d "$APP_ROOT" && test -w "$APP_ROOT"

echo "VERIFY swap:"
free -h | sed -n '1,3p'
sudo -n swapon --noheadings --show=NAME,SIZE
echo "VERIFY apt indexes: updated"
echo "VERIFY Docker:"
sudo -n docker version --format 'client={{.Client.Version}} server={{.Server.Version}}'
sudo -n docker compose version
sudo -n docker info --format 'engine={{.ServerVersion}} storage={{.Driver}}'
echo "VERIFY host:"
systemctl is-active docker nginx
nginx -v 2>&1
certbot --version
echo "VERIFY COS path:"
test -d "$APP_ROOT" && test -w "$APP_ROOT" && echo 'APP_ROOT:ready'
echo "BOOTSTRAP_SUCCESS: Ubuntu host ready; COS not deployed."
