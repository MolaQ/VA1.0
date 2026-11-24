<?php

namespace App\Support;

final class Locales
{
    /**
     * @return array<string, string> code => native name
     */
    public static function supported(): array
    {
        return config('app.supported_locales', ['pl' => 'Polski']);
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::supported());
    }
}
