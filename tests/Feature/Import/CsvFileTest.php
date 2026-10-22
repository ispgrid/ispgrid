<?php

use App\Support\CsvFile;

/**
 * Writes the content to a temporary file and reads it with the plan columns of this test.
 */
function readCsv(string $content): CsvFile
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $content);

    return CsvFile::read($path, ['username', 'fullname', 'city'], ['username', 'fullname']);
}

it('reads rows keyed by column, in any order and case, numbered like the spreadsheet', function () {
    $csv = readCsv(" FullName ,USERNAME\nAlice Rao,alice\n\n,\nBob Rao,bob\n");

    expect($csv->errors)->toBe([])
        ->and($csv->rows)->toBe([
            ['line' => 2, 'values' => ['username' => 'alice', 'fullname' => 'Alice Rao', 'city' => '']],
            ['line' => 5, 'values' => ['username' => 'bob', 'fullname' => 'Bob Rao', 'city' => '']],
        ]);
});

it('trims cells and keeps quoted commas and line breaks in one cell', function () {
    $csv = readCsv("username,fullname,city\n  alice ,\"Rao, Alice\",\"Salt Lake\nKolkata\"\nbob,Bob,Delhi\n");

    expect($csv->rows[0]['values'])->toBe(['username' => 'alice', 'fullname' => 'Rao, Alice', 'city' => "Salt Lake\nKolkata"])
        ->and($csv->rows[1]['line'])->toBe(3);
});

it('ignores the byte-order mark spreadsheets put at the start', function () {
    expect(readCsv("\xEF\xBB\xBFusername,fullname\nalice,Alice\n")->rows[0]['values']['username'])->toBe('alice');
});

it('refuses unknown, missing and repeated columns for the whole file', function () {
    expect(errorLines(readCsv("username,speed,username\nalice,1,alice\n")->errors))->toBe([
        '- -: Unknown column: speed.',
        '- -: The column username is there twice.',
        '- -: Missing column: fullname.',
    ])->and(readCsv("username,speed\nalice,1\n")->rows)->toBe([]);
});

it('refuses a file that is not UTF-8, as Excel saves a plain CSV', function () {
    expect(errorLines(readCsv("username,fullname\nalice,Ren\xE9\n")->errors))
        ->toBe(['- -: The file is not UTF-8 text. In your spreadsheet, save it as "CSV UTF-8".']);
});

it('reports a row with more cells than the header instead of shifting them', function () {
    $csv = readCsv("username,fullname\nalice,Alice,Rao\nbob,Bob\n");

    expect(errorLines($csv->errors))->toBe(['2 -: This row has 3 cells but the header has 2. Check for a stray comma.'])
        ->and(array_column($csv->rows, 'line'))->toBe([3]);
});

it('reports a row with fewer cells than the header instead of shifting or blanking them', function () {
    $csv = readCsv("username,fullname,city\nalice,Alice\nbob,Bob,Delhi\n");

    expect(errorLines($csv->errors))->toBe(['2 -: This row has 2 cells but the header has 3. Check for a missing comma.'])
        ->and(array_column($csv->rows, 'line'))->toBe([3]);
});

it('ignores the empty unnamed columns Excel adds after the last one', function () {
    $csv = readCsv("username,fullname,,\nalice,Alice,,\nbob,Bob\n");

    expect($csv->errors)->toBe([])
        ->and(array_column($csv->rows, 'values'))->toBe([
            ['username' => 'alice', 'fullname' => 'Alice', 'city' => ''],
            ['username' => 'bob', 'fullname' => 'Bob', 'city' => ''],
        ]);
});

it('still refuses an unnamed column with values, or one between named columns', function () {
    expect(errorLines(readCsv("username,fullname,\nalice,Alice,Rao\n")->errors))
        ->toBe(['2 -: This row has 3 cells but the header has 2. Check for a stray comma.'])
        ->and(errorLines(readCsv("username,,fullname\nalice,,Alice\n")->errors))->toBe(['- -: Column 2 has no name.']);
});

it('takes English text only, and finds letters Excel turned into question marks', function (array $values, array $errors) {
    expect(CsvFile::textErrors($values, ['fullname']))->toBe($errors);
})->with([
    'English' => [['fullname' => 'Alice Rao', 'password' => 'a?b'], []],
    'one question mark' => [['fullname' => 'Is this right?'], []],
    'Hindi' => [['fullname' => 'राम कुमार'], ['fullname' => 'Use English letters, digits and symbols only.']],
    'accented letter in any column' => [['password' => 'café'], ['password' => 'Use English letters, digits and symbols only.']],
    'line break' => [['fullname' => 'Alice'.PHP_EOL.'Rao'], ['fullname' => 'Use English letters, digits and symbols only.']],
    'Bengali saved as plain CSV' => [['fullname' => '??? ???'], ['fullname' => 'Excel turned letters it could not save into ?. Type this in English letters.']],
    'question marks outside the text columns' => [['password' => 'ab??cd'], []],
]);

it('refuses an empty file and a file with only the header', function (string $content) {
    expect(errorLines(readCsv($content)->errors))->toBe(['- -: The file has no rows.']);
})->with(['empty' => '', 'header only' => "username,fullname\n"]);

it('refuses more than 5,000 rows', function () {
    $content = "username,fullname\n".str_repeat("alice,Alice\n", CsvFile::MAX_ROWS + 1);

    expect(errorLines(readCsv($content)->errors))->toBe(['- -: The file has more than 5,000 rows. Split it into smaller files.']);
});
