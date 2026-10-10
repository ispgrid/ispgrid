#!/usr/bin/env python3
"""Apply the isp server's changes to FreeRADIUS's stock MySQL queries.conf. Safe to run again.

Usage: patch-queries.py [path]   (default /etc/freeradius/mods-config/sql/main/mysql/queries.conf)
"""
import re
import sys

PATH = sys.argv[1] if len(sys.argv) > 1 else "/etc/freeradius/mods-config/sql/main/mysql/queries.conf"

#  Routers come from the nas table; soft-deleted ones are not loaded.
CLIENT_QUERY = """client_query = "\\
\tSELECT id, nasname, shortname, type, secret, server \\
\tFROM ${client_table} \\
\tWHERE deleted_at IS NULL\""""

#  Login log: no password (the pass column keeps its default ''), the real reject reason, the MAC.
POSTAUTH_QUERY = """query =\t"\\
\t\tINSERT INTO ${..postauth_table} \\
\t\t\t(username, reply, reply_msg, authdate, nasipaddress, mac) \\
\t\tVALUES ( \\
\t\t\t'%{SQL-User-Name}', \\
\t\t\t'%{reply:Packet-Type}', \\
\t\t\t'%{%{control:Isp-Reject-Reason}:-%{reply:Reply-Message}}', \\
\t\t\t'%S.%M', \\
\t\t\t'%{NAS-IP-Address}', \\
\t\t\t'%{Calling-Station-Id}')\""""


def replace(text, name, pattern, new, group=0):
    """Replace the first match (or one group of it) with new; stop if the stock text is not there."""
    match = re.search(pattern, text, flags=re.S | re.M)
    if not match:
        sys.exit(f"cannot apply ({name}): expected text not found in {PATH}")
    if match.group(group) == new:
        print(f"already applied: {name}")
        return text
    print(f"applied: {name}")
    return text[:match.start(group)] + new + text[match.end(group):]


def main():
    with open(PATH) as f:
        text = f.read()
    text = replace(text, "client_query skips deleted routers", r'^client_query = ".*?[^\\]"$', CLIENT_QUERY)
    text = replace(text, "post-auth query without the password",
                   r'^post-auth \{.*?^\t(query =\s*".*?[^\\]")$', POSTAUTH_QUERY, group=1)
    #  safe_characters is set in mods-available/sql (with ",!()"); a second copy here would win.
    if re.search(r'^safe_characters = ', text, flags=re.M):
        text = re.sub(r'^safe_characters = ', '#  Set in mods-available/sql instead.\n#safe_characters = ', text,
                      count=1, flags=re.M)
        print("applied: safe_characters commented out")
    else:
        print("already applied: safe_characters commented out")
    with open(PATH, "w") as f:
        f.write(text)


main()
