<?php

declare(strict_types=1);

use Core\Support\Privacy\PersonalDataMask;

it('masks an email but keeps it recognisable', function (): void {
    expect(PersonalDataMask::email('alice@domaine.cm'))->toBe('a***@d***.cm')
        ->and(PersonalDataMask::email('alice@domaine.cm'))->not->toContain('alice');
});

it('masks a phone number but keeps its country and last digits', function (): void {
    expect(PersonalDataMask::phone('+237 6 90 00 11 12'))->toBe('+2376******12');
});

it('masks a name but keeps its initials', function (): void {
    expect(PersonalDataMask::name('Ali Kamga'))->toBe('A*** K***');
});

it('never lets the whole value through', function (string $value, string $masked): void {
    expect(PersonalDataMask::email($value))->not->toContain($value)
        ->and(PersonalDataMask::email($value))->not->toBe($value);
})->with([
    ['alice@domaine.cm', 'a***@d***.cm'],
    ['a@b.cd', 'a***@b***.cd'],
]);

it('copes with values that are not what they claim to be', function (): void {
    expect(PersonalDataMask::email('not-an-email'))->toBe('***')
        ->and(PersonalDataMask::email('@domaine.cm'))->toBe('***')
        ->and(PersonalDataMask::phone('123'))->toBe('***')
        ->and(PersonalDataMask::name('   '))->toBe('***');
});
