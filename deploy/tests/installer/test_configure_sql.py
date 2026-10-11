#!/usr/bin/env python3
"""Tests for deploy/freeradius/configure-sql.py. Run: python3 deploy/tests/installer/test_configure_sql.py"""
import os
import subprocess
import sys
import tempfile
import unittest

SCRIPT = os.path.join(os.path.dirname(__file__), '..', '..', 'freeradius', 'configure-sql.py')

#  The lines of the stock sql module that the script changes, laid out as in InkBridge FreeRADIUS 3.2.10.
STOCK = '\n'.join([
    'sql {',
    '\tdialect = "sqlite"',
    '\tdriver = "rlm_sql_null"',
    '#\tdriver = "rlm_sql_${dialect}"',
    '\tmysql {',
    '\t\ttls {',
    '\t\t\tca_file = "/etc/ssl/certs/my_ca.crt"',
    '\t\t\tcipher = "DHE-RSA-AES256-SHA:AES128-SHA"',
    '',
    '\t\t\ttls_required = yes',
    '\t\t}',
    '',
    '\t\twarnings = auto',
    '\t}',
    '\tmongo {',
    '\t\ttls {',
    '\t\t\tca_file = /path/to/file',
    '\t\t}',
    '\t}',
    '#\tserver = "localhost"',
    '#\tport = 3306',
    '#\tlogin = "radius"',
    '#\tpassword = "radpass"',
    '\tradius_db = "radius"',
    '#\tradius_db = "dbname=radius host=localhost user=radius password=raddpass"',
    '#\tread_clients = yes',
    '\tclient_table = "nas"',
    '}',
    '',
])


def run(path, password='S3cretPassw0rd'):
    env = dict(os.environ, ISPGRID_RADIUS_DB_PASSWORD=password) if password else {
        k: v for k, v in os.environ.items() if k != 'ISPGRID_RADIUS_DB_PASSWORD'}
    return subprocess.run([sys.executable, SCRIPT, path], env=env, capture_output=True, text=True)


class ConfigureSqlTest(unittest.TestCase):
    def setUp(self):
        handle, self.path = tempfile.mkstemp()
        os.close(handle)
        with open(self.path, 'w') as f:
            f.write(STOCK)

    def tearDown(self):
        os.remove(self.path)

    def text(self):
        with open(self.path) as f:
            return f.read()

    def test_configures_the_stock_file(self):
        result = run(self.path)
        self.assertEqual(result.returncode, 0, result.stderr)
        text = self.text()
        for line in ['\tdialect = "mysql"', '\tdriver = "rlm_sql_${dialect}"', '\tserver = "localhost"',
                     '\tport = 3306', '\tlogin = "freeradius"', '\tpassword = "S3cretPassw0rd"',
                     '\tread_clients = yes',
                     '\tsafe_characters = "@abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_: /,!()"',
                     '#\t\t\tca_file = "/etc/ssl/certs/my_ca.crt"', '#\t\t\ttls_required = yes',
                     '\t\twarnings = auto', '\t\t\tca_file = /path/to/file']:
            self.assertIn(line + '\n', text)

    def test_never_prints_the_password(self):
        result = run(self.path)
        self.assertNotIn('S3cretPassw0rd', result.stdout + result.stderr)

    def test_running_twice_changes_only_the_password(self):
        run(self.path)
        first = self.text()
        run(self.path, 'An0therPassword')
        self.assertEqual(self.text(), first.replace('S3cretPassw0rd', 'An0therPassword'))

    def test_refuses_without_a_password(self):
        result = run(self.path, password=None)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.text(), STOCK)

    def test_refuses_a_file_it_does_not_know(self):
        with open(self.path, 'w') as f:
            f.write('sql {\n}\n')
        result = run(self.path)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('dialect', result.stderr)


if __name__ == '__main__':
    unittest.main()
