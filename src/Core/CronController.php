<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTPS-Cron: zuerst das Token, danach HTTP Basic. Falsches Token sieht aus wie eine unbekannte Adresse.
 */
final class CronController
{
    public function __construct(
        private readonly CronCredentialStore $credentials,
        private readonly CronRunner $runner,
    ) {
    }

    /** @param array<string, string> $params */
    public function run(Request $request, array $params): Response
    {
        $stored = $this->credentials->read();
        $token = $params['token'] ?? '';
        if (
            $stored === null
            || $stored['token_hash'] === ''
            || !hash_equals($stored['token_hash'], hash('sha256', $token))
        ) {
            return Response::notFound();
        }

        if (!$this->basicAccepted($request, $stored['basic_user'], $stored['basic_password_hash'])) {
            return new Response(401, "Anmeldung erforderlich\n", [
                'Content-Type' => 'text/plain; charset=utf-8',
                'WWW-Authenticate' => 'Basic realm="cron"',
            ]);
        }

        return new Response(200, $this->runner->notice($this->runner->run()), [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function basicAccepted(Request $request, string $expectedUser, string $passwordHash): bool
    {
        $header = $this->authorization($request);
        if (!preg_match('/^Basic\s+(\S+)\z/i', $header, $match)) {
            return false;
        }
        $decoded = base64_decode($match[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }
        [$user, $password] = explode(':', $decoded, 2);
        if ($expectedUser === '' || $passwordHash === '') {
            return false;
        }

        return hash_equals($expectedUser, $user) && password_verify($password, $passwordHash);
    }

    private function authorization(Request $request): string
    {
        $header = $request->header('authorization');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = $request->server[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
