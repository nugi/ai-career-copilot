<?php

use App\Ai\PiiRedactor;

test('replaces each kind of personal data with its placeholder', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'email address' => ['Email budi.santoso+jobs@mail.co.id today', 'Email [EMAIL] today'],
    'https link' => ['Portfolio https://budi.dev/projects today', 'Portfolio [URL] today'],
    'www link' => ['See www.budi.dev today', 'See [URL] today'],
    'linkedin profile without scheme' => ['Profile linkedin.com/in/budi-santoso today', 'Profile [URL] today'],
    'link followed by a comma' => ['Profile linkedin.com/in/budi-santoso, Bandung', 'Profile [URL], Bandung'],
    'link inside parentheses' => ['See (https://budi.dev).', 'See ([URL]).'],
    'github profile without scheme' => ['Code github.com/budi-s today', 'Code [URL] today'],
    'gitlab profile without scheme' => ['Code gitlab.com/budi-s today', 'Code [URL] today'],
    'nik of 16 digits' => ['NIK 3201234567890001 today', 'NIK [NIK] today'],
    'nik grouped with spaces' => ['NIK 3201 2345 6789 0001 today', 'NIK [NIK] today'],
    'nik grouped with dashes' => ['NIK 3201-2345-6789-0001 today', 'NIK [NIK] today'],
    'mobile with +62 and dashes' => ['Call +62 812-3456-7890 today', 'Call [PHONE] today'],
    'mobile with +62 and spaces' => ['Call +62812 3456 7890 today', 'Call [PHONE] today'],
    'mobile with 62 and dashes' => ['Call 62812-3456-7890 today', 'Call [PHONE] today'],
    'mobile with (+62) and dashes' => ['(+62) 812-3456-7890', '[PHONE]'],
    'mobile with 0 and spaces' => ['Call 0812 3456 7890 today', 'Call [PHONE] today'],
    'mobile with 0 and dashes' => ['Call 0812-3456-7890 today', 'Call [PHONE] today'],
    'mobile without separators' => ['Call 081234567890 today', 'Call [PHONE] today'],
    'landline with area code in parentheses' => ['Call (021) 555-1234 today', 'Call [PHONE] today'],
    'landline with dash' => ['Call 021-5551234 today', 'Call [PHONE] today'],
    'landline with +62' => ['Call +62 21 5551234 today', 'Call [PHONE] today'],
    'landline with four digit area code' => ['Call (0274) 512345 today', 'Call [PHONE] today'],
    'international with spaces' => ['Call +65 9123 4567 today', 'Call [PHONE] today'],
    'international with parentheses' => ['Call +1 (415) 555-0132 today', 'Call [PHONE] today'],
    'street with Jl.' => ['Jl. Merdeka No. 10', '[ADDRESS]'],
    'street with Jln.' => ['Jln. Sudirman 5', '[ADDRESS]'],
    'street with Jalan' => ['Jalan Asia Afrika 8', '[ADDRESS]'],
    'street with lower case jalan and an uppercase next word' => ['jalan Asia Afrika 8', '[ADDRESS]'],
    'street with lower case abbreviation' => ['jl. merdeka no. 10', '[ADDRESS]'],
    'full word jalan with uppercase next word and comma' => ['Jalan Sudirman Kav. 52, Jakarta 12190', '[ADDRESS]'],
    'full word gang with uppercase next word and comma' => ['Gang Mawar 3, Bandung', '[ADDRESS]'],
    'full word perumahan with uppercase next word' => ['Perumahan Griya Asri Blok C2', '[ADDRESS]'],
    'street with Gg.' => ['Gg. Kenanga 2', '[ADDRESS]'],
    'street with Gang' => ['Gang Mawar 4', '[ADDRESS]'],
    'street with Komplek' => ['Komplek Griya Asri blok B', '[ADDRESS]'],
    'street with Perumahan' => ['Perumahan Taman Indah 3', '[ADDRESS]'],
    'street with Perum' => ['Perum Pondok Hijau 7', '[ADDRESS]'],
    'rt and rw with spaces' => ['RT 03/RW 05', '[ADDRESS]'],
    'rt and rw with dots' => ['RT.003 RW.005', '[ADDRESS]'],
    'rt and rw with spaces around the slash' => ['RT 3 / RW 5', '[ADDRESS]'],
    'address ends at the postal code in a one line resume' => ['Jl. Kenanga No. 7, RT 02/RW 04, Bandung 40115 NIK 3273012345670001 Backend engineer with 6 years of Laravel experience.', '[ADDRESS] NIK [NIK] Backend engineer with 6 years of Laravel experience.'],
    'address ends before a pipe separator' => ['Jl. Merdeka No. 10, Bandung | budi@example.com | Backend engineer', '[ADDRESS] | [EMAIL] | Backend engineer'],
    'address ends before two spaces in a row' => ['Jl. Sudirman 5, Jakarta    Senior Engineer at Acme', '[ADDRESS]    Senior Engineer at Acme'],
    'rt and rw start the address and the postal code ends it' => ['Kp. Sukamaju RT 03/RW 05 Desa Cibodas 43253 Backend engineer', 'Kp. Sukamaju [ADDRESS] Backend engineer'],
    'address ends before a bullet separator and keeps the postal code outside it' => ['Jl. Cempaka Putih Raya No. 5 • Jakarta Selatan 12510', '[ADDRESS] • Jakarta Selatan 12510'],
]);

test('an address without a separator or postal code is cut after 120 characters', function () {
    $input = 'Jl. Merdeka '.str_repeat('a', 200);

    $output = (new PiiRedactor)->redact($input);

    expect($output)->toStartWith('[ADDRESS]')
        ->and(strlen($output))->toBeLessThan(strlen($input))
        ->and($output)->toBe('[ADDRESS]'.str_repeat('a', 89));
});

test('an address cut after 120 characters leaves valid UTF-8 when multi-byte characters follow', function () {
    $input = 'Jl. '.str_repeat('a', 118).'é•é• more text';

    $output = (new PiiRedactor)->redact($input);

    expect(mb_check_encoding($output, 'UTF-8'))->toBeTrue()
        ->and($output)->toStartWith('[ADDRESS]')
        ->and($output)->toBe('[ADDRESS]•é• more text');
});

test('invalid UTF-8 input throws a RuntimeException', function () {
    expect(fn () => (new PiiRedactor)->redact("Email: \xC3\x28"))->toThrow(RuntimeException::class);
});

test('a NIK that starts with 62 is replaced as a NIK and not as a phone number', function () {
    expect((new PiiRedactor)->redact('NIK: 6201234567890123'))->toBe('NIK: [NIK]');
});

test('text that holds no personal data stays unchanged', function (string $text) {
    expect((new PiiRedactor)->redact($text))->toBe($text);
})->with([
    'idr amount' => 'Rp 15.000.000 per month',
    'year range with hyphen' => '2019 - 2023',
    'year range with en dash' => '2019 – 2023',
    'version numbers with commas' => 'PHP 8.5, Laravel 13',
    'date with slashes' => '01/02/2023',
    'latency in milliseconds' => 'cut p95 latency from 800 ms to 180 ms',
    'large count' => 'designed a schema for 2M orders',
    'city and country' => 'Jakarta, Indonesia',
    'city with country name' => 'Yogyakarta, Indonesia',
    'five digit number outside an address' => 'Order 12345 shipped',
    'domain without a path' => 'Built Laravel.com integrations',
    'idr amount with dot separated groups' => 'Rp 2.000.000.000 per year',
    'gang as part of a phrase' => 'Applied Gang of Four patterns',
    'berjalan as a verb' => 'Saya berjalan kaki',
    'jalan followed by a lower case word' => 'jalan kaki ke kantor',
    'four part version number' => 'Version 1.2.3.4',
    'seventeen digit number' => 'ID 12345678901234567',
    'plus with years' => '+5 years of experience',
]);

test('a resume header keeps the name and every line without personal data', function () {
    $input = <<<'TEXT'
Budi Santoso
budi.santoso@gmail.com | +62 812-3456-7890 | linkedin.com/in/budi-santoso
Jl. Merdeka No. 10, RT 03/RW 05, Bandung 40111
Backend engineer, 2019 – 2023, Rp 15.000.000
TEXT;

    $expected = <<<'TEXT'
Budi Santoso
[EMAIL] | [PHONE] | [URL]
[ADDRESS]
Backend engineer, 2019 – 2023, Rp 15.000.000
TEXT;

    expect((new PiiRedactor)->redact($input))->toBe($expected);
});

test('an empty string gives an empty string', function () {
    expect((new PiiRedactor)->redact(''))->toBe('');
});
