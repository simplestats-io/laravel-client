<?php

use SimpleStatsIo\LaravelClient\Services\PagePath;

it('normalizes page paths like the backend', function (mixed $path, ?string $expected) {
    expect(PagePath::normalize($path))->toBe($expected);
})->with([
    'root' => ['/', '/'],
    'plain path' => ['/pricing', '/pricing'],
    'trailing slash' => ['/pricing/', '/pricing'],
    'query and fragment' => ['/blog/post?page=2#comments', '/blog/post'],
    'fragment only' => ['/docs#install', '/docs'],
    'case is kept' => ['/Blog/Hello', '/Blog/Hello'],
    'file extension' => ['/report.pdf', null],
    'upper case file extension' => ['/IMAGE.PNG', null],
    'no leading slash' => ['pricing', null],
    'pagination value' => ['2', null],
    'not a string' => [['/pricing'], null],
    'null' => [null, null],
]);

it('caps very long paths', function () {
    expect(PagePath::normalize('/'.str_repeat('a', 400)))->toHaveLength(PagePath::MAX_LENGTH);
});
