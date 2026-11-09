<?php

namespace Omnitravel\Gtfs;

/** Names made searchable: lower case, without accents, words split on anything else. */
final class Text
{
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'ā' => 'a', 'ē' => 'e', 'ī' => 'i', 'ō' => 'o', 'ū' => 'u',
        'ą' => 'a', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'ę' => 'e', 'ě' => 'e', 'ł' => 'l', 'ń' => 'n', 'ň' => 'n', 'ř' => 'r', 'ś' => 's', 'š' => 's',
        'ť' => 't', 'ů' => 'u', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', '’' => ' ', "'" => ' ',
    ];

    /** " saint-barthélemy  Venaisière" -> "saint barthelemy venaisiere" */
    public static function searchable(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::ACCENTS);

        return trim((string) preg_replace('~[^\pL\pN]+~u', ' ', $text));
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $searchable = self::searchable($text);

        return '' === $searchable ? [] : explode(' ', $searchable);
    }
}
