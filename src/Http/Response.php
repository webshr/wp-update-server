<?php

declare(strict_types=1);

namespace Webshr\WpUpdateServer\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body = '',
        public readonly ?string $filePath = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, private',
            ],
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    public static function file(string $path, string $downloadName, string $contentType = 'application/zip'): self
    {
        return new self(200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $downloadName) . '"',
            'Content-Length' => (string) filesize($path),
            'Content-Transfer-Encoding' => 'binary',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], filePath: $path);
    }

    /**
     * @return never
     */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->defaultHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->filePath !== null) {
            readfile($this->filePath);
            exit;
        }

        echo $this->body;
        exit;
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ];
    }
}
