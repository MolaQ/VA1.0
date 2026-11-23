<?php

namespace App\Support;

final class YouTube
{
    /**
     * Extract the 11-character video id from any common YouTube URL form
     * (watch?v=, youtu.be/, /embed/, /live/, /shorts/) or a bare id.
     */
    public static function extractId(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
            return $url;
        }

        $pattern = '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i';

        return preg_match($pattern, $url, $m) ? $m[1] : null;
    }

    public static function thumbnail(?string $id, string $quality = 'hqdefault'): ?string
    {
        return $id ? "https://i.ytimg.com/vi/{$id}/{$quality}.jpg" : null;
    }

    public static function watchUrl(string $id, float|int|null $seconds = null): string
    {
        $t = $seconds !== null ? '&t='.max(0, (int) floor($seconds)).'s' : '';

        return "https://www.youtube.com/watch?v={$id}{$t}";
    }

    public static function formatTime(float|int|null $seconds): string
    {
        if ($seconds === null) {
            return '–';
        }

        $s = max(0, (int) floor($seconds));
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }
}
