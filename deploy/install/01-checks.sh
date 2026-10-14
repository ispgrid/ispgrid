# shellcheck shell=bash
#  Step 1: the system is supported and has room (bootstrap ran the same checks before fetching the code).
step "Checks"
preflight
say "Ubuntu $OS_VERSION, $(dpkg --print-architecture), $(df -Ph / | awk 'NR == 2 { print $4 }') free"
