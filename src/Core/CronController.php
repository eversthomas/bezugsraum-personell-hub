<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTPS-Cron: zuerst das Token, danach HTTP Basic. Falsches Token sieht aus wie eine unbekannte Adresse.
 */
final class CronController
{
    public function __construct(
        private readonly Config $config,
        private readonly CronRunner $runner,
    ) {
    }

    /** @param array<string, string> $params */
    public function run(Request $request, array $params): Response
    {
        $expected = $this->config->string('cron.token_hash');
        $token = $params['token'] ?? '';
        if ($expected === '' || !hash_equals($expected, hash('sha256', $token))) {
            return Response::notFound();
        }

        if (!$this->basicAccepted($request)) {
            return new Response(401, "Anmeldung erforderlich\n", [
                'Content-Type' => 'text/plain; charset=utf-8',
                'WWW-Authenticate' => 'Basic realm="cron"',
            ]);
        }

        $body = $this->runner->run() ? "ok\n" : "läuft bereits\n";

        return new Response(200, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function basicAccepted(Request $request): bool
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
        $expectedUser = $this->config->string('cron.basic_user');
        $passwordHash = $this->config->string('cron.basic_password_hash');
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
