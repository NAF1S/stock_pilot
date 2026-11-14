<?php

namespace App\Support;

/**
 * The laravel-vite-plugin font helper emits its @font-face rules as a separate
 * stylesheet (`assets/fonts-<hash>.css`) plus a manifest describing it. Vite's
 * @vite directive only injects entry CSS, so that stylesheet has to be linked
 * explicitly or the browser never sees a single @font-face and every heading
 * silently falls back to a system face.
 */
class ViteFonts
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    private static function manifest(): ?array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $candidates = [
            public_path('build/fonts-manifest.json'),
            public_path('build/fonts-manifest.dev.json'),
        ];

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded) && isset($decoded['style']['file'])) {
                return self::$manifest = $decoded;
            }
        }

        return self::$manifest = [];
    }

    /** The stylesheet URL, or null when no font asset was emitted. */
    public static function stylesheet(): ?string
    {
        $file = self::manifest()['style']['file'] ?? null;

        return is_string($file) ? asset('build/'.$file) : null;
    }

    /**
     * Stylesheet plus the per-family preload links, so the first paint uses the
     * real faces instead of swapping them in.
     */
    public static function tags(): string
    {
        $manifest = self::manifest();

        if ($manifest === []) {
            return '';
        }

        $tags = [];

        foreach ((array) ($manifest['preloads'] ?? []) as $preload) {
            $href = $preload['file'] ?? null;

            if (! is_string($href)) {
                continue;
            }

            $as = $preload['as'] ?? 'font';
            $type = $preload['type'] ?? null;
            $attrs = sprintf(
                'rel="preload" href="%s" as="%s" crossorigin',
                e(asset('build/'.$href)),
                e($as),
            );

            if (is_string($type) && $type !== '') {
                $attrs .= sprintf(' type="%s"', e($type));
            }

            $tags[] = '<link '.$attrs.'>';
        }

        $stylesheet = self::stylesheet();

        if ($stylesheet !== null) {
            $tags[] = sprintf('<link rel="stylesheet" href="%s">', e($stylesheet));
        }

        return implode("\n    ", $tags);
    }
}
