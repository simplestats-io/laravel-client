<?php

use SimpleStatsIo\LaravelClient\Services\SkipRules;

beforeEach(function () {
    $this->rules = new SkipRules('array');
});

it('remembers a referer skip hint case-insensitively and for subdomains', function () {
    $this->rules->remember([['type' => 'referer', 'value' => 'Spam.example', 'ttl' => 60]]);

    expect($this->rules->shouldSkip('referer', 'spam.example'))->toBeTrue()
        ->and($this->rules->shouldSkip('referer', 'Shop.Spam.example'))->toBeTrue()
        ->and($this->rules->shouldSkip('referer', 'notspam.example'))->toBeFalse()
        ->and($this->rules->shouldSkip('referer', 'other.example'))->toBeFalse()
        ->and($this->rules->shouldSkip('referer', null))->toBeFalse();
});

it('ignores unknown types and malformed hints', function (mixed $rules) {
    $this->rules->remember($rules);

    expect($this->rules->shouldSkip('referer', 'spam.example'))->toBeFalse()
        ->and($this->rules->shouldSkip('ip', '1.2.3.4'))->toBeFalse();
})->with([
    'no list' => [null],
    'unknown type' => [[['type' => 'ip', 'value' => '1.2.3.4', 'ttl' => 60]]],
    'missing ttl' => [[['type' => 'referer', 'value' => 'spam.example']]],
    'zero ttl' => [[['type' => 'referer', 'value' => 'spam.example', 'ttl' => 0]]],
    'empty value' => [[['type' => 'referer', 'value' => '', 'ttl' => 60]]],
    'not a rule' => [['referer']],
]);
