<?php
/**
 * PlotBird Analytics - internationalisation.
 *
 * Translations live in /locales/<lang>.json as nested objects, so a key such as
 * "dashboard.widgets.top_pages" walks three levels. Missing keys fall back to
 * English and finally to the key itself, which makes an untranslated string
 * obvious in the interface instead of blank.
 *
 * Plural forms are declared in the locale file as arrays, e.g.
 *   "visits": ["visit", "visits"]              (en - 2 forms)
 *   "visits": ["визит", "визита", "визитов"]   (ru - 3 forms)
 * and selected with I18n::plural('visits', $n).
 *
 * @package PlotBird
 */

declare(strict_types=1);

namespace PlotBird;

final class I18n
{
    /** Locale used when nothing else is known. */
    public const FALLBACK = 'en';

    /** @var array<string,array<string,mixed>> Loaded translation trees. */
    private static array $catalogues = [];

    /** @var array<string,array<string,string>> Loaded country name maps. */
    private static array $countries = [];

    /** @var string|null Resolved interface locale. */
    private static ?string $current = null;

    /**
     * Locales shipped with the project, in the order they appear in switchers.
     *
     * "country" is the ISO code of the flag shown next to the language. Flags
     * are rendered as SVG images (Windows has no flag emoji font).
     *
     * @var array<string,array{native:string,english:string,country:string,flag:string,decimal:string,thousands:string}>
     */
    private const KNOWN = [
        'en' => ['native' => 'English',   'english' => 'English',    'country' => 'gb', 'flag' => '🇬🇧', 'decimal' => '.', 'thousands' => ','],
        'ru' => ['native' => 'Русский',   'english' => 'Russian',    'country' => 'ru', 'flag' => '🇷🇺', 'decimal' => ',', 'thousands' => ' '],
        'de' => ['native' => 'Deutsch',   'english' => 'German',     'country' => 'de', 'flag' => '🇩🇪', 'decimal' => ',', 'thousands' => '.'],
        'es' => ['native' => 'Español',   'english' => 'Spanish',    'country' => 'es', 'flag' => '🇪🇸', 'decimal' => ',', 'thousands' => '.'],
        'fr' => ['native' => 'Français',  'english' => 'French',     'country' => 'fr', 'flag' => '🇫🇷', 'decimal' => ',', 'thousands' => ' '],
        'it' => ['native' => 'Italiano',  'english' => 'Italian',    'country' => 'it', 'flag' => '🇮🇹', 'decimal' => ',', 'thousands' => '.'],
        'pt' => ['native' => 'Português', 'english' => 'Portuguese', 'country' => 'pt', 'flag' => '🇵🇹', 'decimal' => ',', 'thousands' => '.'],
        'nl' => ['native' => 'Nederlands','english' => 'Dutch',      'country' => 'nl', 'flag' => '🇳🇱', 'decimal' => ',', 'thousands' => '.'],
        'pl' => ['native' => 'Polski',    'english' => 'Polish',     'country' => 'pl', 'flag' => '🇵🇱', 'decimal' => ',', 'thousands' => ' '],
        'tr' => ['native' => 'Türkçe',    'english' => 'Turkish',    'country' => 'tr', 'flag' => '🇹🇷', 'decimal' => ',', 'thousands' => '.'],
        'uk' => ['native' => 'Українська','english' => 'Ukrainian',  'country' => 'ua', 'flag' => '🇺🇦', 'decimal' => ',', 'thousands' => ' '],
        'zh' => ['native' => '中文',       'english' => 'Chinese',    'country' => 'cn', 'flag' => '🇨🇳', 'decimal' => '.', 'thousands' => ','],
        'ja' => ['native' => '日本語',     'english' => 'Japanese',   'country' => 'jp', 'flag' => '🇯🇵', 'decimal' => '.', 'thousands' => ','],
        'ko' => ['native' => '한국어',     'english' => 'Korean',     'country' => 'kr', 'flag' => '🇰🇷', 'decimal' => '.', 'thousands' => ','],
        'ar' => ['native' => 'العربية',    'english' => 'Arabic',     'country' => 'sa', 'flag' => '🇸🇦', 'decimal' => '.', 'thousands' => ',']
    ];

    /**
     * Locales that actually have a translation file, intersected with KNOWN.
     *
     * @return array<string,array{native:string,english:string,country:string,flag:string,decimal:string,thousands:string}>
     */
    public static function available(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $found = [];
        foreach (self::KNOWN as $code => $meta) {
            if (is_file(self::directory() . '/' . $code . '.json')) {
                $found[$code] = $meta;
            }
        }

        // A translation dropped in by hand but not listed above is still usable.
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            if (!isset($found[$code]) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $code) === 1) {
                $found[$code] = [
                    'native' => strtoupper($code), 'english' => strtoupper($code),
                    'country' => 'xx', 'flag' => '🌐',
                    'decimal' => '.', 'thousands' => ',',
                ];
            }
        }

        if (!isset($found[self::FALLBACK])) {
            $found = [self::FALLBACK => self::KNOWN[self::FALLBACK]] + $found;
        }

        /** @var array<string,array{native:string,english:string,flag:string,decimal:string,thousands:string}> $found */
        $cache = $found;
        return $cache;
    }

    /**
     * Whether a locale is installed.
     */
    public static function isAvailable(string $locale): bool
    {
        return isset(self::available()[$locale]);
    }

    /**
     * Whether a string may be used as the name of a translation file.
     *
     * The value becomes part of a filesystem path, so it is restricted to the
     * shape of a language tag: two letters, optionally a region. Nothing that
     * can escape the directory survives this check.
     */
    private static function isSafeCode(string $locale): bool
    {
        return preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $locale) === 1;
    }

    public static function directory(): string
    {
        return PB_ROOT . '/locales';
    }

    /**
     * Metadata for a locale (name, flag, separators).
     *
     * @return array{native:string,english:string,flag:string,decimal:string,thousands:string}
     */
    public static function meta(string $locale): array
    {
        $available = self::available();
        if (isset($available[$locale])) {
            return $available[$locale];
        }
        if (isset(self::KNOWN[$locale])) {
            return self::KNOWN[$locale];
        }
        return ['native' => strtoupper($locale), 'english' => strtoupper($locale), 'country' => 'xx', 'flag' => '🌐', 'decimal' => '.', 'thousands' => ','];
    }

    /**
     * The active locale, resolved once per request.
     *
     * Priority:
     *   1. an explicit choice stored in the session (language switcher, sign-in);
     *   2. the browser's Accept-Language, when it maps to a shipped locale -
     *      this is what makes a first visit feel native;
     *   3. the application default from the settings/settings page;
     *   4. English.
     */
    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }

        Security::startSession();

        $session = $_SESSION['locale'] ?? null;
        if (is_string($session) && self::isAvailable($session)) {
            return self::$current = $session;
        }

        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($header !== '') {
            $match = self::match($header);
            if ($match !== null) {
                return self::$current = $match;
            }
        }

        $configured = Config::get('app.default_locale');
        if (is_string($configured) && self::isAvailable($configured)) {
            return self::$current = $configured;
        }

        return self::$current = self::FALLBACK;
    }

    /**
     * Force a locale for this request (and remember it in the session).
     */
    public static function set(string $locale, bool $persist = true): void
    {
        if (!self::isAvailable($locale)) {
            return;
        }
        self::$current = $locale;
        if ($persist) {
            Security::startSession();
            $_SESSION['locale'] = $locale;
        }
    }

    /**
     * Pick the best available locale for an Accept-Language header, always
     * answering with something usable (English when nothing matches).
     */
    public static function negotiate(string $header): string
    {
        $match = self::match($header);
        if ($match !== null) {
            return $match;
        }

        $available = array_keys(self::available());
        return in_array(self::FALLBACK, $available, true)
            ? self::FALLBACK
            : (string) ($available[0] ?? self::FALLBACK);
    }

    /**
     * The best available locale for an Accept-Language header, or null when
     * the browser asks for languages this installation does not ship.
     *
     * Returning null (rather than the fallback) lets the caller decide whether
     * the configured default language should win.
     */
    public static function match(string $header): ?string
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        $available = array_keys(self::available());

        $candidates = [];
        foreach (explode(',', $header) as $index => $part) {
            $part  = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = explode(';', $part);
            $tag  = strtolower(trim($bits[0]));
            $q    = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                $param = trim($param);
                if (strpos($param, 'q=') === 0) {
                    $q = (float) substr($param, 2);
                }
            }
            if ($tag === '' || $tag === '*') {
                continue;
            }
            // "ru-RU" and "ru" both map to "ru"; region variants fall back to
            // the base language, which is what our files are keyed by.
            $candidates[] = ['tag' => $tag, 'base' => explode('-', $tag)[0], 'q' => $q, 'index' => $index];
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($a['q'] === $b['q']) {
                return $a['index'] <=> $b['index'];
            }
            return $b['q'] <=> $a['q'];
        });

        foreach ($candidates as $candidate) {
            foreach ([$candidate['tag'], $candidate['base']] as $tag) {
                if (in_array($tag, $available, true)) {
                    return $tag;
                }
            }
        }

        return null;
    }

    /**
     * Translate a dotted key.
     *
     * @param array<string,string|int> $replace
     */
    public static function translate(string $key, array $replace = []): string
    {
        $value = self::lookup(self::current(), $key);
        if ($value === null) {
            $value = self::lookup(self::FALLBACK, $key);
        }
        if ($value === null) {
            // Make a missing translation visible but harmless.
            return $key;
        }
        if (is_array($value)) {
            // A plural set used without a count: show the first form.
            $value = (string) reset($value);
        }

        $value = (string) $value;
        foreach ($replace as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string) $replacement, $value);
        }
        return $value;
    }

    /**
     * Count-aware translation. Falls back to a plain key lookup when the entry
     * is a string rather than an array of forms.
     *
     * @param array<string,string|int> $replace
     */
    public static function plural(string $key, int $count, array $replace = []): string
    {
        $forms = self::lookup(self::current(), $key);
        if (!is_array($forms)) {
            $forms = self::lookup(self::FALLBACK, $key);
        }

        $replace = array_merge(['count' => $count], $replace);

        if (!is_array($forms) || $forms === []) {
            return self::translate($key, $replace);
        }

        $forms = array_values(array_map('strval', $forms));
        $index = self::pluralIndex($count, count($forms));
        $form  = $forms[$index] ?? $forms[0];

        foreach ($replace as $name => $replacement) {
            $form = str_replace('{' . $name . '}', (string) $replacement, $form);
        }
        return $form;
    }

    /**
     * Plural form index for the active locale.
     */
    private static function pluralIndex(int $count, int $forms): int
    {
        $locale = substr(self::current(), 0, 2);
        $n      = abs($count);

        // Languages with a single form.
        if (in_array($locale, ['zh', 'ja', 'ko', 'tr'], true) || $forms === 1) {
            return 0;
        }

        // Slavic three-form rule (Russian, Ukrainian).
        if (in_array($locale, ['ru', 'uk'], true)) {
            $mod10  = $n % 10;
            $mod100 = $n % 100;
            if ($mod10 === 1 && $mod100 !== 11) {
                return 0;
            }
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
                return 1;
            }
            return 2;
        }

        // Polish: 1 / 2-4 (except teens) / rest.
        if ($locale === 'pl') {
            if ($n === 1) {
                return 0;
            }
            $mod10  = $n % 10;
            $mod100 = $n % 100;
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
                return 1;
            }
            return 2;
        }

        // Default two-form rule (English, German, Spanish, French, Italian...).
        return $n === 1 ? 0 : min(1, $forms - 1);
    }

    /**
     * Walk a dotted key inside a locale file.
     *
     * @return mixed|null
     */
    private static function lookup(string $locale, string $key)
    {
        $catalogue = self::catalogue($locale);
        $value     = $catalogue;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * Load (and cache) a locale file.
     *
     * @return array<string,mixed>
     */
    private static function catalogue(string $locale): array
    {
        if (isset(self::$catalogues[$locale])) {
            return self::$catalogues[$locale];
        }
        $code = basename($locale);
        if (!self::isSafeCode($code)) {
            return self::$catalogues[$locale] = [];
        }
        $file = self::directory() . '/' . $code . '.json';
        if (!is_file($file)) {
            return self::$catalogues[$locale] = [];
        }
        $raw     = (string) file_get_contents($file);
        $decoded = json_decode($raw, true);
        return self::$catalogues[$locale] = is_array($decoded) ? $decoded : [];
    }

    /**
     * Localised country name for an ISO 3166-1 alpha-2 code.
     *
     * Falls back to the English map and finally to the raw code, so a country
     * that is missing from a translation is still readable.
     */
    public static function country(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) !== 2) {
            return '';
        }

        $locale = self::current();
        foreach ([$locale, self::FALLBACK] as $candidate) {
            $map = self::countryMap($candidate);
            if (isset($map[$code])) {
                return $map[$code];
            }
        }
        return $code;
    }

    /**
     * @return array<string,string>
     */
    private static function countryMap(string $locale): array
    {
        if (isset(self::$countries[$locale])) {
            return self::$countries[$locale];
        }
        $code = basename($locale);
        if (!self::isSafeCode($code)) {
            return self::$countries[$locale] = [];
        }
        $file = self::directory() . '/countries/' . $code . '.json';
        if (!is_file($file)) {
            return self::$countries[$locale] = [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return self::$countries[$locale] = is_array($decoded) ? $decoded : [];
    }

    public static function decimalSeparator(): string
    {
        return self::meta(self::current())['decimal'];
    }

    public static function thousandsSeparator(): string
    {
        return self::meta(self::current())['thousands'];
    }
}
