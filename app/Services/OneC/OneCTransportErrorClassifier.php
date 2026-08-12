<?php

namespace App\Services\OneC;

use Throwable;

final class OneCTransportErrorClassifier
{
    /**
     * @return array{error_category: string, timeout_seconds: int, curl_errno?: int}
     */
    public function context(Throwable $exception, int $timeout): array
    {
        $curlCode = $this->curlCode($exception);

        $context = [
            'error_category' => $this->category($curlCode, $exception->getMessage()),
            'timeout_seconds' => $timeout,
        ];

        if ($curlCode !== null) {
            $context['curl_errno'] = $curlCode;
        }

        return $context;
    }

    private function curlCode(Throwable $exception): ?int
    {
        do {
            if (method_exists($exception, 'getHandlerContext')) {
                $value = $exception->getHandlerContext()['errno'] ?? null;

                if (is_numeric($value)) {
                    return (int) $value;
                }
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }

    private function category(?int $curlCode, string $message): string
    {
        return match (true) {
            $curlCode === 28 => 'timeout',
            $curlCode === 7 => 'connection_refused',
            $curlCode === 6 => 'dns',
            in_array($curlCode, [35, 51, 58, 60, 77, 80, 82, 83, 90, 91], true) => 'tls',
            in_array($curlCode, [18, 52, 55, 56], true) => 'connection_reset',
            $this->contains($message, ['timed out', 'timeout']) => 'timeout',
            $this->contains($message, ['connection refused']) => 'connection_refused',
            $this->contains($message, ['could not resolve', 'name resolution']) => 'dns',
            $this->contains($message, ['ssl', 'tls', 'certificate']) => 'tls',
            $this->contains($message, ['connection reset', 'empty reply', 'unexpected eof']) => 'connection_reset',
            default => 'network',
        };
    }

    /** @param array<string> $needles */
    private function contains(string $message, array $needles): bool
    {
        $message = strtolower($message);

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
