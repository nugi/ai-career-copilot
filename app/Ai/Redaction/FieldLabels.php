<?php

namespace App\Ai\Redaction;

/**
 * The labels that come before a personal value in a resume, grouped by category.
 *
 * A label is matched in any case. To support a new language, add its labels to the right list.
 */
final class FieldLabels
{
    /**
     * Labels for a personal value. The value is replaced with [PERSONAL].
     *
     * @var list<string>
     */
    public const PERSONAL = [
        // English
        'Date of Birth', 'Birth Date', 'Birthdate', 'DOB', 'Place of Birth', 'Born', 'Age', 'Gender', 'Sex',
        // Race and Health are left out: they head ordinary resume lines, such as "Race: 2nd place" and "Health: led the HealthTech rebuild".
        'Marital Status', 'Civil Status', 'Religion', 'Ethnicity', 'Nationality', 'Citizenship',
        'Height', 'Weight', 'Blood Type', 'Disability',
        // Indonesian and Malay
        'Tempat, Tanggal Lahir', 'Tempat/Tanggal Lahir', 'Tempat dan Tanggal Lahir', 'Tanggal Lahir',
        'Tempat Lahir', 'TTL', 'Tarikh Lahir', 'Umur', 'Usia', 'Agama', 'Status', 'Status Perkawinan',
        'Status Pernikahan', 'Status Perkahwinan', 'Jenis Kelamin', 'Jantina', 'Kewarganegaraan',
        'Warganegara', 'Bangsa', 'Suku', 'Tinggi Badan', 'Berat Badan', 'Golongan Darah',
        // German
        'Geburtsdatum', 'Geburtsort', 'Geboren', 'Alter', 'Geschlecht', 'Familienstand', 'Konfession',
        'Religion', 'Staatsangehörigkeit', 'Nationalität',
        // French
        'Date de naissance', 'Lieu de naissance', 'Né le', 'Née le', 'Âge', 'Sexe', 'Situation familiale',
        'État civil', 'Etat civil', 'Nationalité',
        // Spanish
        'Fecha de nacimiento', 'Lugar de nacimiento', 'Edad', 'Sexo', 'Estado civil', 'Religión', 'Nacionalidad',
        // Portuguese
        'Data de nascimento', 'Local de nascimento', 'Idade', 'Estado civil', 'Religião', 'Nacionalidade',
        // Italian
        'Data di nascita', 'Luogo di nascita', 'Età', 'Sesso', 'Stato civile', 'Nazionalità',
        // Dutch
        'Geboortedatum', 'Geboorteplaats', 'Leeftijd', 'Geslacht', 'Burgerlijke staat', 'Nationaliteit',
        // Japanese
        '生年月日', '年齢', '性別', '国籍', '配偶者', '血液型', '身長', '体重',
        // Chinese
        '出生日期', '出生年月', '年龄', '性别', '婚姻状况', '婚姻狀況', '民族', '籍贯', '国籍', '宗教',
        // Korean
        '생년월일', '나이', '성별', '국적', '결혼여부', '종교',
    ];

    /**
     * Labels for a national ID, passport or tax number. The value is replaced with [ID].
     *
     * @var list<string>
     */
    public const IDENTIFIER = [
        // English
        'National ID', 'ID Number', 'ID No', 'Identity Card', 'Identity Number', 'Passport', 'Passport No',
        'Passport Number', 'SSN', 'Social Security Number', 'SIN', 'National Insurance Number', 'NI Number',
        'NINO', 'NRIC', 'FIN', 'MyKad', 'IC No', 'Aadhaar', 'PAN',
        // Indonesian and Malay
        'KTP', 'NIK', 'Paspor',
        // Brazilian Portuguese
        'CPF', 'RG',
        // Spanish
        'DNI', 'NIE', 'NIF',
        // German
        'Personalausweis', 'Reisepass', 'Steuer-ID',
        // French
        'Numéro de sécurité sociale',
        // Italian
        'Codice fiscale',
        // Dutch
        'BSN',
        // Australian
        'TFN',
        // Chinese
        '身份证号', '身份证', '身分證',
        // Japanese
        'マイナンバー',
        // Korean
        '주민등록번호',
    ];

    /**
     * Labels for a phone number. The value is replaced with [PHONE].
     *
     * @var list<string>
     */
    public const PHONE = [
        // English
        'Phone', 'Telephone', 'Tel', 'Mobile', 'Cell', 'HP', 'No. HP', 'No HP', 'WhatsApp', 'WA',
        // Indonesian and Malay
        'Telepon',
        // German
        'Telefon', 'Handy', 'Mobil',
        // French
        'Téléphone', 'Tél', 'Portable',
        // Spanish
        'Teléfono', 'Móvil', 'Celular',
        // Portuguese
        'Telefone', 'Telemóvel',
        // Italian
        'Telefono', 'Cellulare',
        // Japanese
        '電話', '携帯',
        // Chinese
        '电话', '手机',
        // Korean
        '전화', '휴대폰',
    ];

    /**
     * Labels for a postal address. The value is replaced with [ADDRESS].
     *
     * @var list<string>
     */
    public const ADDRESS = [
        // English
        'Address', 'Home Address',
        // Indonesian and Malay
        'Alamat',
        // German
        'Anschrift', 'Wohnort',
        // French
        'Adresse',
        // Spanish
        'Dirección', 'Domicilio',
        // Portuguese
        'Endereço', 'Morada',
        // Italian
        'Indirizzo',
        // Dutch
        'Adres',
        // Japanese
        '住所', '現住所',
        // Chinese
        '地址', '住址',
        // Korean
        '주소',
    ];
}
