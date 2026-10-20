#!/usr/bin/env bash
#
#  Tests for deploy/bin/ispgrid (the server command). Run: bash deploy/tests/installer/test_ispgrid.sh
#  Needs bash 4+, git and PHP (Linux, or Git Bash on Windows).
#
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
export ISPGRID_INSTALLER="$here/../../install.sh"
# shellcheck source=../../bin/ispgrid
source "$here/../../bin/ispgrid"
export ADDONS_PHP="$here/../../install/addons.php"

count=0
failed=0

#  check <name> <got> <want>
check() {
  count=$((count + 1))
  if [[ "$2" != "$3" ]]; then
    failed=$((failed + 1))
    printf 'FAIL %s: got [%s], want [%s]\n' "$1" "$2" "$3"
  fi
}

#  args <command...>: the installer arguments, space-separated, then the exit code
args() {
  local out code
  out=$(ispgrid_installer_args v0.1.3 "$@" 2>/dev/null)
  code=$?
  printf '%s rc=%s\n' "$(tr '\n' ' ' <<<"$out" | sed 's/ $//')" "$code"
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

#  What each command runs
check "add" "$(args addon add ispgrid/documents)" "--version=v0.1.3 --addons-only --yes --addon=ispgrid/documents rc=0"
check "add pinned unlisted" "$(args addon add acme/x@dev-main --allow-unlisted)" \
  "--version=v0.1.3 --addons-only --yes --addon=acme/x@dev-main --allow-unlisted rc=0"
check "remove with data" "$(args addon remove ispgrid/ipdr --delete-data)" \
  "--version=v0.1.3 --addons-only --yes --remove-addon=ispgrid/ipdr --delete-data rc=0"
check "update all" "$(args addon update)" "--version=v0.1.3 --addons-only --yes rc=0"
check "update one" "$(args addon update ispgrid/ipdr@v0.1.3)" "--version=v0.1.3 --addons-only --yes --addon=ispgrid/ipdr@v0.1.3 rc=0"
check "verbose passes" "$(args addon update --verbose)" "--version=v0.1.3 --addons-only --yes --verbose rc=0"
check "upgrade passes through" "$(args upgrade --version=v0.2.0 --verbose)" "--version=v0.2.0 --verbose rc=0"
check "upgrade alone" "$(args upgrade)" " rc=0"
check "refuses bad name" "$(args addon add 'x/y;id')" " rc=1"
check "add needs a name" "$(args addon add)" " rc=2"
check "add takes one name" "$(args addon add ispgrid/ipdr ispgrid/documents)" " rc=2"
check "remove takes no version" "$(args addon remove ispgrid/ipdr@v0.1.0)" " rc=1"
check "delete-data only with remove" "$(args addon add ispgrid/ipdr --delete-data)" " rc=2"
check "unknown command" "$(args addon frob)" " rc=2"
check "no empty argument" "$(ispgrid_installer_args v0.1.3 addon remove ispgrid/ipdr | grep -c '^$')" 0
check "no empty argument for upgrade" "$(ispgrid_installer_args v0.1.3 upgrade | grep -c '^$')" 0
check "unknown top command" "$(args frob)" " rc=2"

#  main: root only, and only on a server where ISPGrid is installed
ispgrid_is_root() { return 0; }
ISPGRID_CONF="$tmp/none.conf"
check "not installed" "$( (ispgrid_main addon add ispgrid/ipdr) 3>&1 2>&1 | grep -c 'ISPGrid is not installed here')" 1
ispgrid_is_root() { return 1; }
check "root only" "$( (ispgrid_main addon list) 3>&1 2>&1 | grep -c 'sudo ispgrid')" 1
ispgrid_is_root() { return 0; }
check "help" "$(ispgrid_main help | grep -c 'sudo ispgrid addon add NAME')" 1
check "no command" "$(ispgrid_main >/dev/null 2>&1; echo "rc=$?")" "rc=2"

#  addon list: installed add-ons with their state, then what else the list has for this series
reg="$here/fixtures/registry.json"
regrepo="$tmp/regrepo"
git init -q "$regrepo" && cp "$reg" "$regrepo/registry.json"
git -C "$regrepo" -c core.autocrlf=false add registry.json && git -C "$regrepo" -c user.name=t -c user.email=t@t commit -q -m list
home="$tmp/home"
mkdir -p "$home/vendor/composer"
cat > "$home/addons.json" <<'EOF'
{
  "ispgrid/ipdr": {"version": "v0.1.1", "commit": "2222222222222222222222222222222222222222", "pinned": true},
  "acme/thing": {"constraint": "dev-main", "unlisted": true}
}
EOF
printf '{"packages": [{"name": "ispgrid/ipdr", "version": "v0.1.1"}, {"name": "acme/thing", "version": "dev-main"}]}\n' \
  > "$home/vendor/composer/installed.json"
listing=$(ISPGRID_HOME=$home OPT_REGISTRY=$regrepo ispgrid_list v0.1.3 2>&1)
check "list: yanked" "$(grep -c 'ispgrid/ipdr .*v0.1.1 .*ISPGrid .*YANKED: Broke the export' <<<"$listing")" 1
check "list: not reviewed" "$(grep -c 'acme/thing .*dev-main .*not reviewed' <<<"$listing")" 1
check "list: available" "$(grep -c 'ispgrid/documents .*v0.1.0 .*Customer documents' <<<"$listing")" 1
check "list: installed is not available" "$(sed -n '/^Available/,$p' <<<"$listing" | grep -c 'ispgrid/ipdr')" 0
printf '{"ispgrid/ipdr": {"version": "v0.1.0", "commit": "1111111111111111111111111111111111111111", "pinned": false}}\n' > "$home/addons.json"
printf '{"packages": [{"name": "ispgrid/ipdr", "version": "v0.1.0"}]}\n' > "$home/vendor/composer/installed.json"
check "list: up to date" "$(ISPGRID_HOME=$home OPT_REGISTRY=$regrepo ispgrid_list v0.1.3 | grep -c 'ispgrid/ipdr .*up to date')" 1
check "list: update available" "$(ISPGRID_HOME=$home OPT_REGISTRY=$regrepo ispgrid_list v0.2.0 | grep -c 'ispgrid/ipdr .*update v0.2.0 available')" 1
printf '{"ispgrid/ipdr": {"version": "v0.1.0", "commit": "1111111111111111111111111111111111111111", "pinned": true}}\n' > "$home/addons.json"
check "list: pinned" "$(ISPGRID_HOME=$home OPT_REGISTRY=$regrepo ispgrid_list v0.2.0 | grep -c 'ispgrid/ipdr .*pinned (newest: v0.2.0)')" 1
offline=$(ISPGRID_HOME=$home OPT_REGISTRY=$tmp/no-repo ispgrid_list v0.1.3 2>&1)
check "list without the registry" "$(grep -c 'could not be read' <<<"$offline")" 1
check "list without the registry still shows installed" "$(grep -c 'ispgrid/ipdr' <<<"$offline")" 1
rm "$home/addons.json"
check "list with nothing installed" "$(ISPGRID_HOME=$home OPT_REGISTRY=$regrepo ispgrid_list v0.1.3 | grep -c 'No add-ons installed')" 1

echo "$((count - failed))/$count passed"
[[ $failed -eq 0 ]]
