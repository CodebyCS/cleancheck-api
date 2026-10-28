<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Ical;

use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class IcalFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_REDIRECTS = 3;
    public const CONNECT_TIMEOUT_SECONDS = 5;
    public const TIMEOUT_SECONDS = 15;

    // Rejects private (10.x, 192.168.x), local (127.x, ::1) and other non-public ranges.
    private const PUBLIC_IP_FLAGS = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE;

    public function __construct(private readonly HostResolver $resolver) {}

    public function fetch(string $url): string
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            [$host, $ip] = $this->checkUrl($url);

            $response = $this->request($url, $host, $ip);

            if (! $response->redirect()) {
                return $this->body($response, $host);
            }

            // Each redirect goes through the same checks before it is followed.
            $url = $this->nextUrl($response->header('Location'), $host);
        }

        throw new IcalFetchException(
            IcalErrorCode::UrlNotAllowed,
            'O calendário tem demasiados redirecionamentos.',
        );
    }

    /** @return array{0: string, 1: string} host and the checked IP to connect to */
    private function checkUrl(string $url): array
    {
        $parts = parse_url($url);

        $allowed = $parts !== false
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ($parts['port'] ?? 443) === 443;

        if (! $allowed) {
            throw new IcalFetchException(
                IcalErrorCode::UrlNotAllowed,
                'O calendário tem de estar num endereço https:// público.',
            );
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new IcalFetchException(
                IcalErrorCode::FetchFailed,
                "Não foi possível encontrar o servidor {$host}.",
            );
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, self::PUBLIC_IP_FLAGS) === false) {
                throw new IcalFetchException(
                    IcalErrorCode::UrlNotAllowed,
                    "O servidor {$host} está numa rede privada ou local.",
                );
            }
        }

        return [$host, $ips[0]];
    }

    private function request(string $url, string $host, string $ip): Response
    {
        $tooLarge = false;
        $pinnedIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        try {
            return Http::withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions([
                    // Connect to the IP we checked, so DNS cannot change it in between.
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinnedIp}"]],
                    // Returning true aborts the download as soon as it passes the limit.
                    'progress' => function (int $total, int $downloaded) use (&$tooLarge): bool {
                        $tooLarge = $total > self::MAX_BYTES || $downloaded > self::MAX_BYTES;

                        return $tooLarge;
                    },
                ])
                ->get($url);
        } catch (ConnectionException|TransferException) {
            throw $tooLarge
                ? $this->tooLarge()
                : new IcalFetchException(
                    IcalErrorCode::FetchFailed,
                    "Não foi possível ligar ao servidor {$host} (tempo esgotado ou ligação recusada).",
                );
        }
    }

    private function body(Response $response, string $host): string
    {
        if (! $response->successful()) {
            throw new IcalFetchException(
                IcalErrorCode::FetchFailed,
                "O servidor {$host} respondeu com o estado {$response->status()}.",
            );
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw $this->tooLarge();
        }

        return $body;
    }

    private function nextUrl(string $location, string $host): string
    {
        // A relative redirect ("/path") stays on the same server.
        return str_starts_with($location, '/') ? "https://{$host}{$location}" : $location;
    }

    private function tooLarge(): IcalFetchException
    {
        return new IcalFetchException(
            IcalErrorCode::FeedTooLarge,
            'O calendário tem mais de 2 MB.',
        );
    }
}