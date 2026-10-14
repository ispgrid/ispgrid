<?php

use App\Notifications\Redactor;

it('replaces every secret in a text', function () {
    expect(Redactor::scrub('cURL error for https://x/api?key=abc12345: abc12345 refused', ['abc12345']))
        ->toBe('cURL error for https://x/api?key=••••: •••• refused');
});

it('leaves short or empty secrets alone so it never blanks ordinary text', function () {
    expect(Redactor::scrub('a b c', ['', 'a']))->toBe('a b c');
});
