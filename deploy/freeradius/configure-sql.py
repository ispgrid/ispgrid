#!/usr/bin/env python3
"""Configure FreeRADIUS's sql module (mods-available/sql) for ISPGrid: MySQL over the local socket with the
freeradius login, routers read from the nas table, and the characters the isp server needs kept unescaped.
Works on the stock file and on one it already changed (then only the password can change). The password comes
from the environment variable ISPGRID_RADIUS_DB_PASSWORD, never the command line. Prints no values.

Usage: ISPGRID_RADIUS_DB_PASSWORD=... configure-sql.py /etc/freeradius/mods-available/sql
"""
import os
import re
import sys

TAB = '\t'
SAFE_CHARACTERS = '@abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_: /,!()'


def one(lines, pattern, name):
    """The index of the single line matching pattern."""
    hits = [i for i, line in enumerate(lines) if re.match(pattern, line)]
    if len(hits) != 1:
        sys.exit(f'sql module: expected one "{name}" line, found {len(hits)}')
    return hits[0]


def set_value(lines, key, value, commented_ok=True):
    """'<tab>key = ...' (or the stock '#<tab>key = ...') becomes '<tab>key = value'."""
    prefix = r'^#?\t' if commented_ok else r'^\t'
    lines[one(lines, prefix + re.escape(key) + r'\s*=', key)] = f'{TAB}{key} = {value}'


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    password = os.environ.get('ISPGRID_RADIUS_DB_PASSWORD', '')
    if not re.fullmatch(r'[A-Za-z0-9]{12,}', password):
        sys.exit('ISPGRID_RADIUS_DB_PASSWORD must hold the database password (letters and digits, 12 or more)')
    path = sys.argv[1]
    with open(path) as f:
        lines = f.read().split('\n')

    set_value(lines, 'dialect', '"mysql"', commented_ok=False)
    set_value(lines, 'driver', '"rlm_sql_${dialect}"', commented_ok=False)
    set_value(lines, 'server', '"localhost"')
    set_value(lines, 'port', '3306')
    set_value(lines, 'login', '"freeradius"')
    set_value(lines, 'password', f'"{password}"')
    set_value(lines, 'read_clients', 'yes')
    safe = [i for i, line in enumerate(lines) if re.match(r'^#?\tsafe_characters\s*=', line)]
    if len(safe) > 1:
        sys.exit('sql module: more than one "safe_characters" line')
    safe_line = f'{TAB}safe_characters = "{SAFE_CHARACTERS}"'
    if safe:
        lines[safe[0]] = safe_line
    else:
        lines.insert(one(lines, r'^\tread_clients = yes$', 'read_clients') + 1, safe_line)

    #  The stock mysql { tls { ... } } settings point at certificates that do not exist: comment them out.
    in_mysql = in_tls = False
    for i, line in enumerate(lines):
        if line.startswith(TAB + 'mysql {'):
            in_mysql = True
        elif in_mysql and line.startswith(TAB * 2 + 'tls {'):
            in_tls = True
        elif in_tls and line.startswith(TAB * 2 + '}'):
            in_tls = in_mysql = False
        elif in_tls and line.strip() and not line.lstrip().startswith('#'):
            lines[i] = '#' + line

    with open(path, 'w', newline='\n') as f:
        f.write('\n'.join(lines))
    print('sql module: mysql, login freeradius, read_clients, safe_characters, tls off')


if __name__ == '__main__':
    main()
