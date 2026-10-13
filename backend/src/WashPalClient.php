<?php
declare(strict_types=1);

// Talks to the Wash Pal service and keeps its access token valid.
final class WashPalClient
{
    // Renew this many seconds before expiry so a token never expires mid-request.
    private const RENEW_MARGIN = 60;

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private string $baseUrl,
        private string $user,
        private string $pass,
    ) {
    }

    /** Sends a text/plain command to /task. Returns [httpStatus, body]. */
    public function command(string $command): array
    {
        return $this->authorized('POST', '/task', $command, "/task [$command]");
    }

    /** Downloads the CSV the server built for the last finished task. */
    public function csv(): array
    {
        return $this->authorized('GET', '/csv', null, '/csv');
    }

    private function authorized(string $method, string $path, ?string $body, string $logLabel): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $headers = ['Authorization: Bearer ' . $this->token()];
            $options = [];
            if ($body !== null) {
                $headers[] = 'Content-Type: text/plain; charset=utf-8';
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            $options[CURLOPT_HTTPHEADER] = $headers;

            [$status, $response] = $this->http($method, $path, $options);
            Logger::write("UPSTREAM $method $logLabel -> $status");

            // A rejected token is renewed and the request retried exactly once.
            if ($status !== 401) {
                break;
            }
            $this->token = null;
        }
        return [$status, $response];
    }

    private function token(): string
    {
        if ($this->token === null || time() >= $this->tokenExpiresAt - self::RENEW_MARGIN) {
            $this->renewToken();
        }
        return $this->token;
    }

    private function renewToken(): void
    {
        [$status, $body] = $this->http('GET', '/?token=renew', [
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->user . ':' . $this->pass,
        ]);
        Logger::write("UPSTREAM GET /?token=renew -> $status");

        // The service gives no reason on failure, so surface what little it says.
        if ($status !== 200) {
            throw new RuntimeException("Token request failed (HTTP $status): " . substr(trim($body), 0, 200));
        }
        $xml = @simplexml_load_string($body);
        if ($xml === false || !isset($xml->Value, $xml->Expires)) {
            throw new RuntimeException('Token response was not the expected XML');
        }
        $this->token = (string) $xml->Value;
        // Expires is UTC, so parse it as UTC rather than server-local time.
        $this->tokenExpiresAt = (new DateTimeImmutable((string) $xml->Expires, new DateTimeZone('UTC')))->getTimestamp();
    }

    private function http(string $method, string $path, array $options): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        curl_setopt_array($ch, $options + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException('Wash Pal unreachable: ' . curl_error($ch));
        }
        return [curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $response];
    }
}
