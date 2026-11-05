<?php

declare(strict_types=1);

use App\Auth\TotpVerifier;
use App\Auth\UserRepository;
use App\Core\Config;
use App\Core\Crypto;

/** @var \App\Core\Kernel $kernel */
$kernel = require __DIR__ . '/bootstrap.php';
$c = $kernel->container();

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Aufruf: php bin/create-user.php <benutzername>\n");
    fwrite(STDERR, "Passwort wird abgefragt (oder über die Umgebungsvariable PH_PASSWORD gesetzt).\n");
    exit(1);
}

$password = getenv('PH_PASSWORD') ?: '';
if ($password === '') {
    $interactive = function_exists('posix_isatty') && posix_isatty(STDIN);
    echo 'Passwort (mindestens 12 Zeichen): ';
    if ($interactive) {
        shell_exec('stty -echo');
    }
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if ($interactive) {
        shell_exec('stty echo');
        echo "\n";
    }
}
if (mb_strlen($password) < App\Auth\AuthService::MIN_PASSWORD_LENGTH) {
    fwrite(STDERR, "Passwort zu kurz (mindestens 12 Zeichen).\n");
    exit(1);
}

$users = $c->get(UserRepository::class);
if ($users->findByUsername($username) !== null) {
    fwrite(STDERR, "Benutzer existiert bereits.\n");
    exit(1);
}

$totp = $c->get(TotpVerifier::class);
$secret = $totp->generateSecret();
$users->create(
    $username,
    password_hash($password, PASSWORD_DEFAULT),
    $c->get(Crypto::class)->encrypt($secret),
    gmdate('Y-m-d H:i:s'),
);

echo "Benutzer angelegt.\n";
echo "Authenticator-App einrichten (Schlüssel manuell eingeben oder URI als QR-Code anzeigen):\n";
echo '  Schlüssel: ' . $secret . "\n";
echo '  URI:       ' . $totp->provisioningUri($secret, $username, 'Personal Hub') . "\n";
