<?php

use App\Support\YouTube;

it('extracts the video id from common YouTube links', function (?string $url, ?string $expected) {
    expect(YouTube::extractId($url))->toBe($expected);
})->with([
    ['https://www.youtube.com/watch?v=XCXJATWV9O4', 'XCXJATWV9O4'],
    ['https://youtube.com/watch?feature=share&v=XCXJATWV9O4&t=90', 'XCXJATWV9O4'],
    ['https://youtu.be/XCXJATWV9O4?t=12', 'XCXJATWV9O4'],
    ['https://www.youtube.com/live/XCXJATWV9O4', 'XCXJATWV9O4'],
    ['https://www.youtube.com/embed/XCXJATWV9O4', 'XCXJATWV9O4'],
    ['XCXJATWV9O4', 'XCXJATWV9O4'],
    ['https://vimeo.com/12345', null],
    ['', null],
    [null, null],
]);

it('formats video time', function () {
    expect(YouTube::formatTime(75.9))->toBe('1:15')
        ->and(YouTube::formatTime(3725))->toBe('1:02:05')
        ->and(YouTube::formatTime(null))->toBe('–');
});
