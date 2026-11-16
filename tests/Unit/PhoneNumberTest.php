<?php

use App\Booking\PhoneNumber;

// A real unit test (#115, in place of the kit's ExampleTest): PhoneNumber has no framework
// dependency, so it runs without booting Laravel. Fake numbers only.

test('normalize: the international format, with the country code never guessed for the others', function (string $typed, ?string $saved) {
    expect(PhoneNumber::normalize($typed))->toBe($saved);
})->with([
    'already international' => ['+393331234567', '+393331234567'],
    'with 00 and separators' => ['0039 (333) 123-4567', '+393331234567'],
    'Italian mobile without prefix' => ['333 123 4567', '+393331234567'],
    'Cameroonian mobile without prefix' => ['671 23 45 67', '+237671234567'],
    'Cameroonian fixed without prefix' => ['222 12 34 56', '+237222123456'],
    'Italian fixed without prefix: not guessed' => ['06 1234 5678', null],
    'too short' => ['+3912345', null],
    'starting with 0 after the prefix' => ['+0123456789', null],
    'letters' => ['chiamami', null],
    'two plus signs' => ['++393331234567', null],
]);

test('display: groups separated by U+00A0 for Italy, Cameroon and France; the rest as received', function (string $number, string $shown) {
    expect(str_replace("\u{00A0}", '_', PhoneNumber::display($number)))->toBe($shown);
})->with([
    'Italy, mobile' => ['+393331234567', '+39_333_123_4567'],
    'Cameroon' => ['+237671234567', '+237_6_71_23_45_67'],
    'France' => ['+33612345678', '+33_6_12_34_56_78'],
    'Italy, fixed' => ['+390612345678', '+390612345678'],
    'another country' => ['+4915112345678', '+4915112345678'],
    'not international' => ['3331234567', '3331234567'],
]);

test('display never changes the number a link needs: normalize of the shown number gives it back', function (string $number) {
    expect(PhoneNumber::normalize(str_replace("\u{00A0}", ' ', PhoneNumber::display($number))))->toBe(PhoneNumber::normalize($number));
})->with(['+393331234567', '+237671234567', '+33612345678', '+4915112345678']);
