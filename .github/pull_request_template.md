## What and why

<!-- What this changes and the problem it solves. Link the issue if there is one: Fixes #123 -->

## How it was tested

<!-- Tests added, and anything you checked by hand (installer run, a real NAS). -->

## Checklist

- [ ] `php artisan test --compact` and `vendor/bin/pint --dirty` pass
- [ ] No passwords, secrets, internal IPs or customer data in the code, tests or this description
- [ ] New UI text goes through `__()` and has a Hindi entry in `lang/hi.json`
- [ ] A change to what FreeRADIUS reads or does has a case in `deploy/tests/radius_policy_tests.sh`
- [ ] A change to the installer has a test in `deploy/tests/installer/test_install_sh.sh`
