<?php

declare(strict_types=1);

namespace Netresearch\NrRepurpose\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Typed, read-only accessor for the nr_repurpose extension configuration (ext_conf_template.txt).
 * The analyzer and generators read their defaults from here instead of poking ExtensionConfiguration
 * directly, so the configuration surface lives in exactly one place. (Image provider defaults to
 * OpenAI's gpt-image-1 — the only configured provider in this stack; DALL·E was retired.)
 */
final class RepurposeConfiguration
{
    private const EXTENSION_KEY = 'nr_repurpose';

    private const VALID_VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    private const VALID_TTS_MODELS = ['tts-1', 'tts-1-hd'];
    private const VALID_THEMES = ['nr', 'neutral'];

    /** @var array<string,mixed> */
    private readonly array $tree;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $tree = $extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (ExtensionConfigurationExtensionNotConfiguredException | ExtensionConfigurationPathDoesNotExistException) {
            $tree = [];
        }
        $this->tree = \is_array($tree) ? $tree : [];
    }

    public function hostAVoice(): string
    {
        return $this->enum($this->leaf('voices', 'hostA'), self::VALID_VOICES, 'nova');
    }

    public function hostBVoice(): string
    {
        return $this->enum($this->leaf('voices', 'hostB'), self::VALID_VOICES, 'onyx');
    }

    public function ttsModel(): string
    {
        return $this->enum($this->leaf('tts', 'model'), self::VALID_TTS_MODELS, 'tts-1-hd');
    }

    public function imageProvider(): string
    {
        return $this->enum($this->leaf('image', 'provider'), ['fal', 'dalle'], 'dalle');
    }

    public function imageModel(): string
    {
        $value = trim((string) ($this->leaf('image', 'model') ?? ''));

        return $value !== '' ? $value : 'gpt-image-1';
    }

    public function diagramViewportWidth(): int
    {
        return $this->positiveInt($this->leaf('diagram', 'viewportWidth'), 1200);
    }

    public function storyWidth(): int
    {
        return $this->positiveInt($this->leaf('story', 'width'), 1080);
    }

    public function storyHeight(): int
    {
        return $this->positiveInt($this->leaf('story', 'height'), 1920);
    }

    public function defaultTheme(): string
    {
        return $this->enum($this->tree['defaultTheme'] ?? null, self::VALID_THEMES, 'nr');
    }

    public function mapReduceCharThreshold(): int
    {
        return $this->positiveInt($this->leaf('mapReduce', 'charThreshold'), 12000);
    }

    private function leaf(string $group, string $key): mixed
    {
        $section = $this->tree[$group] ?? null;

        return \is_array($section) ? ($section[$key] ?? null) : null;
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $default): string
    {
        $value = \is_string($value) ? trim($value) : '';

        return \in_array($value, $allowed, true) ? $value : $default;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (is_numeric($value)) {
            $int = (int) $value;
            if ($int > 0) {
                return $int;
            }
        }

        return $default;
    }
}
