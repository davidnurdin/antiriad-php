<?php

namespace JumPHP\Util;

/**
 * Translations. Source strings are the keys; config/lang.yaml gives the other languages:
 *   fr: { 'START': 'JOUER', 'Score {n}': 'Score {n}' }
 * A missing translation returns the key itself, with its {placeholders} filled.
 */
class Lang
{
    private static ?self $instance = null;

    /** @param array<string, array<string, string>> $tables */
    public function __construct(private string $lang = 'en', private array $tables = [])
    {
        self::$instance = $this;
    }

    /** From config/lang.yaml of the game: { fr: { 'START': 'JOUER' }, ... } */
    public static function fromConfig(\JumPHP\Kernel\GameConfig $config): self
    {
        return new self($config->lang(), $config->file('lang.yaml'));
    }

    public static function t(string $key, array $params = []): string
    {
        return (self::$instance ?? new self())->translate($key, $params);
    }

    public function translate(string $key, array $params = []): string
    {
        $text = $this->tables[$this->lang][$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }

    public function setLang(string $lang): void
    {
        $this->lang = $lang;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    /** @return string[] the languages with a table, plus the source one */
    public function available(string $source = 'en'): array
    {
        return array_values(array_unique(array_merge([$source], array_keys($this->tables))));
    }
}
