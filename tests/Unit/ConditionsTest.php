<?php

use App\Services\Alerting\Conditions;
use App\Support\RuleLabel;

/*
| Tes unit (tanpa Laravel dan database): fungsi murni pembanding kondisi
| dan format angka label aturan.
*/

it('membandingkan nilai dengan operator aturan', function (float $value, string $op, float $threshold, bool $expected) {
    expect(Conditions::compare($value, $op, $threshold))->toBe($expected);
})->with([
    'CPU 85,1 > 85' => [85.1, '>', 85, true],
    'CPU 85 > 85 (batas tidak termasuk)' => [85.0, '>', 85, false],
    'disk 9,9 < 10' => [9.9, '<', 10, true],
    'disk 10 < 10 (batas tidak termasuk)' => [10.0, '<', 10, false],
    'layanan state 1 != 4' => [1.0, '!=', 4, true],
    'layanan state 4 != 4' => [4.0, '!=', 4, false],
    'layanan state 4 == 4' => [4.0, '==', 4, true],
    'galat pembulatan float tetap dianggap sama' => [4.0000000001, '==', 4, true],
]);

it('menolak operator yang tidak dikenal', function () {
    Conditions::compare(1, '>=', 1);
})->throws(InvalidArgumentException::class);

it('memformat angka ambang untuk label (desimal koma, tanpa nol berlebih)', function ($value, string $expected) {
    expect(RuleLabel::number($value))->toBe($expected);
})->with([
    [85, '85'],
    [85.0, '85'],
    [27.5, '27,5'],
    [0.25, '0,25'],
    [null, ''],
]);
