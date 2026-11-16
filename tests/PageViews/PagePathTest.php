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
    'source map' => ['/build/app.js.map', null],
    'dotfile' => ['/.env', null],
    'file in nested directory' => ['/assets/feed.xml', null],
    'file with trailing slash' => ['/deploy.sh/', null],
    'slug ending in json' => ['/daily/accept-json', '/daily/accept-json'],
    'slug ending in sh' => ['/daily/pin-to-a-commit-hash', '/daily/pin-to-a-commit-hash'],
    'slug ending in back' => ['/daily/holding-you-back', '/daily/holding-you-back'],
    'slug ending in conf' => ['/events/laracon-conf', '/events/laracon-conf'],
    'directory with extension like name' => ['/files.zip/overview', '/files.zip/overview'],
    'no leading slash' => ['pricing', null],
    'pagination value' => ['2', null],
    'not a string' => [['/pricing'], null],
    'null' => [null, null],
]);

it('caps very long paths', function () {
    expect(PagePath::normalize('/'.str_repeat('a', 400)))->toHaveLength(PagePath::MAX_LENGTH);
});
