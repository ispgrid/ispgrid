<?php

use App\Support\RateLimit;

it('reads a speed with its unit as bits per second', function (string $speed, int $bits) {
    expect(RateLimit::bits($speed))->toBe($bits);
})->with([
    ['512k', 512_000],
    ['25M', 25_000_000],
    ['1M', 1_000_000],
]);

it('refuses a speed MikroTik would misread', function (string $speed) {
    expect(RateLimit::bits($speed))->toBeNull();
})->with(['25', '25m', '25 M', '1.5M', '0M', 'M', '25G', '', '-5M', '025M']);

it('writes upload then download', function () {
    expect((new RateLimit('10M', '50M'))->toString())->toBe('10M/50M');
});

it('writes burst, threshold and burst time after the rate', function () {
    expect((new RateLimit('25M', '25M', '30M', '30M', '20M', '20M', 10))->toString())
        ->toBe('25M/25M 30M/30M 20M/20M 10/10');
});

it('writes every part of a full rate', function () {
    expect((new RateLimit('25M', '25M', '30M', '30M', '20M', '20M', 10, 8, '5M', '5M'))->toString())
        ->toBe('25M/25M 30M/30M 20M/20M 10/10 8 5M/5M');
});

it('fills the burst places with zeros when only priority and minimum are set', function () {
    expect((new RateLimit('25M', '25M', priority: 1, minUpload: '5M', minDownload: '5M'))->toString())
        ->toBe('25M/25M 0/0 0/0 0/0 1 5M/5M');
});

it('uses the router default priority 8 when only a minimum is set', function () {
    expect((new RateLimit('25M', '25M', minUpload: '5M', minDownload: '5M'))->toString())
        ->toBe('25M/25M 0/0 0/0 0/0 8 5M/5M');
});

it('writes a priority without a minimum', function () {
    expect((new RateLimit('25M', '25M', priority: 1))->toString())->toBe('25M/25M 0/0 0/0 0/0 1');
});

it('accepts a complete rate', function () {
    expect((new RateLimit('25M', '25M', '30M', '30M', '20M', '20M', 10, 8, '5M', '5M'))->errors())->toBe([]);
});

it('names the speed field that is not a speed', function () {
    expect((new RateLimit('25', '25M'))->errors())->toHaveKey('upload')->not->toHaveKey('download');
});

it('wants every burst field or none', function () {
    expect(array_keys((new RateLimit('25M', '25M', burstUpload: '30M'))->errors()))
        ->toBe(['burst_download', 'threshold_upload', 'threshold_download', 'burst_time']);
});

it('keeps priority between 1 and 8 and burst time between 1 and 3600 seconds', function () {
    expect(array_keys((new RateLimit('25M', '25M', '30M', '30M', '20M', '20M', 0, 9))->errors()))
        ->toBe(['burst_time', 'priority']);
});

it('wants both minimum speeds or neither', function () {
    expect(array_keys((new RateLimit('25M', '25M', minUpload: '5M'))->errors()))->toBe(['min_download']);
});

it('compares burst, threshold and minimum with the normal speed', function (array $fields, string $field) {
    $rate = new RateLimit(...array_merge([
        'upload' => '20M', 'download' => '20M',
        'burstUpload' => '30M', 'burstDownload' => '30M',
        'thresholdUpload' => '10M', 'thresholdDownload' => '10M',
        'burstTime' => 10,
    ], $fields));

    expect(array_keys($rate->errors()))->toBe([$field]);
})->with([
    'burst below the rate' => [['burstUpload' => '10M'], 'burst_upload'],
    'threshold above the rate' => [['thresholdDownload' => '25M'], 'threshold_download'],
    'minimum above the rate' => [['minUpload' => '21M', 'minDownload' => '1M'], 'min_upload'],
    'speeds in different units' => [['burstDownload' => '19000k'], 'burst_download'],
]);
