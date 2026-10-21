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
    'street with Kompleks' => ['Kompleks Griya Asri Blok C2 No. 5, Bandung 40135', '[ADDRESS]'],
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
    expect((new PiiRedactor)->redact('KTP 6201234567890123'))->toBe('KTP [NIK]');
});

test('a labelled NIK is replaced as an identifier', function () {
    expect((new PiiRedactor)->redact('NIK: 6201234567890123'))->toBe('NIK: [ID]');
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
    'gender as a word in a sentence' => 'Gender equality in tech',
    'status as a word in a sentence' => 'Status pekerjaan aktif',
    'religion label without a colon' => 'Agama',
    'date with dots' => 'Joined on 12.03.2021 today',
    'age of empires' => 'Age of Empires',
    'passport as a word in a sentence' => 'Passport to success',
    'address as a word in a sentence' => 'Address the root cause',
    'telefonica as a company name' => 'Telefonica was a client',
    'born as a word in a sentence' => 'Born to code',
    'race as a word in a sentence' => 'The race to market',
    'version date with dashes' => 'Version 2021-06-15',
    'revenue with a percentage and a year' => 'Revenue grew 12.5% in 2021',
    'sprint date with dots' => 'Sprint 2021.06.15',
    'metric with a label-like word' => 'Increased NPS from 32 to 58',
    'tel as part of a place name' => '5 years at Tel Aviv University',
    'mobile as a skills heading with versions' => 'Mobile: iOS 17, Android 14, React Native 0.74',
    'cell as a heading of a sentence with counts' => 'Cell: 5 engineers across 3 time zones',
    'race as a competition result' => 'Race: 2nd place, ICPC regional 2019',
    'health as a domain heading' => 'Health: led the HealthTech platform rebuild in 2022',
    'phone label without a digit' => 'Phone: on request',
    'phone placeholder from an earlier step' => 'Phone: [PHONE]',
    'address placeholder from an earlier step' => 'Address: [ADDRESS]',
]);

test('replaces a labelled value in each language and keeps its label', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'german birth date' => ['Geburtsdatum: 01.02.1990', 'Geburtsdatum: [PERSONAL]'],
    'german marital status' => ['Familienstand: verheiratet', 'Familienstand: [PERSONAL]'],
    'french birth date with a space before the colon' => ['Date de naissance : 01/02/1990', 'Date de naissance : [PERSONAL]'],
    'french nationality' => ['Nationalité : française', 'Nationalité : [PERSONAL]'],
    'spanish birth date' => ['Fecha de nacimiento: 1 de febrero de 1990', 'Fecha de nacimiento: [PERSONAL]'],
    'spanish marital status' => ['Estado civil: casado', 'Estado civil: [PERSONAL]'],
    'portuguese birth date' => ['Data de nascimento: 01/02/1990', 'Data de nascimento: [PERSONAL]'],
    'portuguese religion' => ['Religião: Católica', 'Religião: [PERSONAL]'],
    'italian birth date' => ['Data di nascita: 01/02/1990', 'Data di nascita: [PERSONAL]'],
    'italian sex' => ['Sesso: maschile', 'Sesso: [PERSONAL]'],
    'dutch birth date' => ['Geboortedatum: 01-02-1990', 'Geboortedatum: [PERSONAL]'],
    'dutch civil status' => ['Burgerlijke staat: gehuwd', 'Burgerlijke staat: [PERSONAL]'],
    'japanese birth date without a colon' => ['生年月日 1990年1月1日', '生年月日 [PERSONAL]'],
    'japanese sex with a full-width colon' => ['性別：男', '性別：[PERSONAL]'],
    'chinese birth date with a full-width colon' => ['出生日期：1990年1月1日', '出生日期：[PERSONAL]'],
    'chinese ethnicity with a full-width colon' => ['民族：汉', '民族：[PERSONAL]'],
    'korean birth date' => ['생년월일: 1990.01.01', '생년월일: [PERSONAL]'],
    'korean sex' => ['성별: 남', '성별: [PERSONAL]'],
    'english nationality' => ['Nationality: Indonesian', 'Nationality: [PERSONAL]'],
    'english ethnicity' => ['Ethnicity: Javanese', 'Ethnicity: [PERSONAL]'],
    'english age' => ['Age: 34', 'Age: [PERSONAL]'],
]);

test('replaces a national ID, tax or insurance number in any country', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'labelled passport number' => ['Passport No: C1234567', 'Passport No: [ID]'],
    'labelled us social security number' => ['SSN: 123-45-6789', 'SSN: [ID]'],
    'unlabelled us social security number' => ['123-45-6789', '[ID]'],
    'uk national insurance number' => ['AB 12 34 56 C', '[ID]'],
    'singapore nric' => ['S1234567D', '[ID]'],
    'malaysian mykad' => ['900101-14-5678', '[ID]'],
    'brazilian cpf' => ['123.456.789-09', '[ID]'],
    'indian aadhaar' => ['1234 5678 9012', '[ID]'],
    'labelled japanese my number' => ['マイナンバー：1234 5678 9012', 'マイナンバー：[ID]'],
]);

test('replaces a phone number in a format of another country and keeps its label', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'labelled german phone' => ['Telefon: 030 1234567', 'Telefon: [PHONE]'],
    'labelled french phone with a space before the colon' => ['Téléphone : 01 23 45 67 89', 'Téléphone : [PHONE]'],
    'labelled spanish mobile' => ['Móvil: 612 34 56 78', 'Móvil: [PHONE]'],
    'labelled japanese phone with a full-width colon' => ['電話：03-1234-5678', '電話：[PHONE]'],
    'north american phone with parentheses' => ['(415) 555-0132', '[PHONE]'],
    'north american phone with dots' => ['415.555.0132', '[PHONE]'],
]);

test('replaces a labelled address in any language and keeps its label', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'german address' => ['Adresse: Hauptstraße 5, 10115 Berlin', 'Adresse: [ADDRESS]'],
    'spanish address' => ['Dirección: Calle Mayor 10, Madrid', 'Dirección: [ADDRESS]'],
    'japanese address with a full-width colon' => ['住所：東京都新宿区1-2-3', '住所：[ADDRESS]'],
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

test('replaces an NPWP tax number and keeps its label', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'old format npwp' => ['NPWP 12.345.678.9-012.345', 'NPWP [NPWP]'],
    'labelled 15 digit npwp' => ['NPWP: 123456789012345', 'NPWP: [NPWP]'],
    'labelled 16 digit npwp is not a nik' => ['NPWP: 1234567890123456', 'NPWP: [NPWP]'],
    'labelled npwp with dots and a space' => ['NPWP 12.345.678.9.012.345 today', 'NPWP [NPWP] today'],
]);

test('replaces the value of a personal field and keeps its label', function (string $input, string $expected) {
    expect((new PiiRedactor)->redact($input))->toBe($expected);
})->with([
    'birth date' => ['Tanggal Lahir: 12 Januari 1995', 'Tanggal Lahir: [PERSONAL]'],
    'birth place and date' => ['Tempat, Tanggal Lahir: Bandung, 12 Januari 1995', 'Tempat, Tanggal Lahir: [PERSONAL]'],
    'birth place and date with a slash' => ['Tempat/Tanggal Lahir: Bandung, 12 Januari 1995', 'Tempat/Tanggal Lahir: [PERSONAL]'],
    'birth place and date with dan' => ['Tempat dan Tanggal Lahir: Bandung', 'Tempat dan Tanggal Lahir: [PERSONAL]'],
    'abbreviated birth date' => ['Tgl. Lahir: 12-05-1995', 'Tgl. Lahir: [PERSONAL]'],
    'abbreviated birth date without a dot' => ['Tgl Lahir: 12-05-1995', 'Tgl Lahir: [PERSONAL]'],
    'birth place and abbreviated date' => ['Tempat/Tgl Lahir: Bandung, 12-05-1995', 'Tempat/Tgl Lahir: [PERSONAL]'],
    'birth place and abbreviated date with a dot' => ['Tempat/Tgl. Lahir: Bandung, 12-05-1995', 'Tempat/Tgl. Lahir: [PERSONAL]'],
    'abbreviated birth place and date' => ['Tmp/Tgl Lahir: Bandung, 12-05-1995', 'Tmp/Tgl Lahir: [PERSONAL]'],
    'abbreviated birth place' => ['Tmp. Lahir: Bandung', 'Tmp. Lahir: [PERSONAL]'],
    'ttl' => ['TTL: Bandung, 12-01-1995', 'TTL: [PERSONAL]'],
    'date of birth' => ['Date of Birth: 12 January 1995', 'Date of Birth: [PERSONAL]'],
    'birth date in english' => ['Birth Date: 12 January 1995', 'Birth Date: [PERSONAL]'],
    'birthdate' => ['Birthdate: 12 January 1995', 'Birthdate: [PERSONAL]'],
    'dob' => ['DOB: 12 January 1995', 'DOB: [PERSONAL]'],
    'religion' => ['Religion: Islam', 'Religion: [PERSONAL]'],
    'agama' => ['Agama: Islam', 'Agama: [PERSONAL]'],
    'marital status' => ['Marital Status: Married', 'Marital Status: [PERSONAL]'],
    'status perkawinan' => ['Status Perkawinan: Menikah', 'Status Perkawinan: [PERSONAL]'],
    'status' => ['Status: Menikah', 'Status: [PERSONAL]'],
    'gender' => ['Gender: Male', 'Gender: [PERSONAL]'],
    'sex' => ['Sex: Male', 'Sex: [PERSONAL]'],
    'jenis kelamin' => ['Jenis Kelamin: Laki-laki', 'Jenis Kelamin: [PERSONAL]'],
    'tinggi badan' => ['Tinggi Badan: 170 cm', 'Tinggi Badan: [PERSONAL]'],
    'berat badan' => ['Berat Badan: 65 kg', 'Berat Badan: [PERSONAL]'],
    'golongan darah' => ['Golongan Darah: O', 'Golongan Darah: [PERSONAL]'],
    'value on the line after the label' => ["Agama\n: Islam", "Agama\n: [PERSONAL]"],
    'value on the line after the colon' => ["Agama:\nIslam", "Agama:\n[PERSONAL]"],
    'two fields on one line with a pipe' => ['Agama: Islam | Status: Menikah', 'Agama: [PERSONAL] | Status: [PERSONAL]'],
    'full-width colon' => ['Agama： Islam', 'Agama： [PERSONAL]'],
    'value ends before two spaces' => ['Agama: Islam  Backend engineer', 'Agama: [PERSONAL]  Backend engineer'],
]);

test('replaces the name of the user with a placeholder', function (string $input, ?string $name, string $expected) {
    expect((new PiiRedactor)->redact($input, $name))->toBe($expected);
})->with([
    'full name in upper case' => ['RINA WIJAYA is a backend engineer', 'Rina Wijaya', '[NAME] is a backend engineer'],
    'full name split over two lines' => ["Rina\nWijaya\nBackend engineer", 'Rina Wijaya', "[NAME]\nBackend engineer"],
    'single part of the name' => ['Rina leads the team', 'Rina Wijaya', '[NAME] leads the team'],
    'part of two characters stays' => ['Li and Rina', 'Rina Li', 'Li and [NAME]'],
    'single name of two characters stays' => ['Li led the Li project', 'Li', 'Li led the Li project'],
    'longer word that contains a part stays' => ['Wijayakusuma and Rina', 'Rina Wijaya', 'Wijayakusuma and [NAME]'],
    'name equal to a placeholder word keeps the placeholder' => ['[PHONE] and Phone', 'Phone', '[PHONE] and [NAME]'],
    'null name changes nothing' => ['Rina Wijaya', null, 'Rina Wijaya'],
    'name with no letters changes nothing' => ['Rina Wijaya 123', '123', 'Rina Wijaya 123'],
]);

test('keeps a part of the name that is in a company name', function (string $input, string $name, string $expected) {
    expect((new PiiRedactor)->redact($input, $name))->toBe($expected);
})->with([
    'part after the prefix' => ['Engineer at PT Nugraha Digital', 'Ahmad Nugraha', 'Engineer at PT Nugraha Digital'],
    'part after the prefix and a dot' => ['PT. Sari Roti, Jakarta', 'Dewi Sari', 'PT. Sari Roti, Jakarta'],
    'part later in the company name' => ['CV Mulia Sari Jaya', 'Sari Putri', 'CV Mulia Sari Jaya'],
    'company name in upper case' => ['PT NUGRAHA DIGITAL', 'Ahmad Nugraha', 'PT NUGRAHA DIGITAL'],
    'full name after the prefix is replaced' => ['PT Ahmad Nugraha Digital', 'Ahmad Nugraha', 'PT [NAME] Digital'],
    'part outside the company name is replaced' => ['Nugraha joined PT Nugraha Digital', 'Ahmad Nugraha', '[NAME] joined PT Nugraha Digital'],
    'part after the fourth word is replaced' => ['PT Satu Dua Tiga Empat Nugraha', 'Ahmad Nugraha', 'PT Satu Dua Tiga Empat [NAME]'],
    'part after a lower-case word is replaced' => ['PT Mulia and Nugraha', 'Ahmad Nugraha', 'PT Mulia and [NAME]'],
    'part on the line after the prefix is replaced' => ["PT\nNugraha Digital", 'Ahmad Nugraha', "PT\n[NAME] Digital"],
    'prefix inside a longer word is not a prefix' => ['PTPN Nugraha', 'Ahmad Nugraha', 'PTPN [NAME]'],
    'prefix in lower case is not a prefix' => ['pt Nugraha', 'Ahmad Nugraha', 'pt [NAME]'],
]);

test('an empty string gives an empty string', function () {
    expect((new PiiRedactor)->redact(''))->toBe('');
});
