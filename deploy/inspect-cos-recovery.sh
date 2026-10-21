#!/usr/bin/env bash
# Read-only, do not expose .env, SQL contents or authentication credentials.
set -Eeuo pipefail
ROOT="$1"
echo '=== COS RECOVERY INVENTORY ==='
printf 'OS: '; . /etc/os-release; echo "$PRETTY_NAME"
echo 'block_devices:'; lsblk -o NAME,SIZE,FSTYPE,MOUNTPOINTS || true
echo 'disk:'; df -h / | tail -n 1
echo 'runtime_state:'
for suffix in / .env .env.docker docker-compose.yml; do
  path="$ROOT/$suffix"
  if test -d "$path"; then echo "$suffix: directory"
  elif test -s "$path"; then echo "$suffix: file_present"
  else echo "$suffix: absent"; fi
done
echo 'docker_containers:'
sudo -n docker ps -a --format '{{.Names}} | {{.Image}} | {{.Status}}' || true
echo 'docker_volumes:'
sudo -n docker volume ls --format '{{.Name}}' || true
echo 'candidate_mysql_volumes:'
while IFS= read -r volume; do
  case "$volume" in
    cos_*|*mysql*|*mariadb*|*backup*)
      mp="$(sudo -n docker volume inspect --format '{{.Mountpoint}}' "$volume" 2>/dev/null || true)"
      echo "volume: $volume"
      if test -n "$mp"; then
        sudo -n du -sh "$mp" 2>/dev/null || true
        if sudo -n test -e "$mp/auto.cnf"; then echo "mysql_datadir_marker: present"; fi
      fi ;;
  esac
done < <(sudo -n docker volume ls --format '{{.Name}}')
echo 'potential_backup_files (names and sizes only):'
for d in /opt /home /var/backups /mnt /srv; do
  test -d "$d" || continue
  sudo -n find "$d" -xdev -maxdepth 6 -type f \
    \( -iname '*.sql' -o -iname '*.sql.gz' -o -iname '*.sql.zst' -o -iname '*.dump' -o -iname '*.bak' -o -iname '*mysql*.tar*' -o -iname '*cos*backup*' \) \
    -printf '%p | %s bytes\n' 2>/dev/null | head -n 30 || true
done
echo '=== COS RECOVERY INVENTORY COMPLETE ==='
