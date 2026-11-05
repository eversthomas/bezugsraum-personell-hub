<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthService;
use App\Auth\DeviceCookie;
use App\Auth\LoginAttemptRepository;
use App\Auth\LoginStatus;
use App\Auth\TotpVerifier;
use App\Auth\TrustedDeviceRepository;
use App\Auth\UserRepository;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use Tests\DbTestCase;

final class AuthFlowTest extends DbTestCase
{
    private const PASSWORD = 'correct-horse-battery';
    private const START = 1_800_000_000;

    private int $clock = self::START;
    private Crypto $crypto;
    private TotpVerifier $totp;
    private string $secret;
    private UserRepository $users;
    private TrustedDeviceRepository $devices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypto = new Crypto(sodium_hex2bin(Crypto::generateKeyHex()));
        $this->totp = new TotpVerifier();
        $this->users = new UserRepository($this->db);
        $this->devices = new TrustedDeviceRepository($this->db);
        $this->secret = $this->totp->generateSecret();
        $this->users->create(
            'thomas',
            password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            $this->crypto->encrypt($this->secret),
            gmdate('Y-m-d H:i:s', self::START),
        );
    }

    private function service(?Session $session = null): AuthService
    {
        $session ??= new Session(null, false);

        return new AuthService(
            $this->users,
            new LoginAttemptRepository($this->db),
            $this->devices,
            $this->totp,
            $this->crypto,
            $session,
            new Csrf($session),
            fn (): int => $this->clock,
        );
    }

    /** @param array<string, string> $cookies */
    private function request(array $cookies = []): Request
    {
        return new Request('POST', '/login', cookies: $cookies, server: ['REMOTE_ADDR' => '203.0.113.7']);
    }

    private function code(): string
    {
        return $this->totp->codeAt($this->secret, $this->clock);
    }

    public function testFullLoginRequiresPasswordAndTotp(): void
    {
        $auth = $this->service();

        self::assertSame(LoginStatus::NeedsTotp, $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD)->status);
        self::assertFalse($auth->isAuthenticated($this->request()), 'Nach dem Passwort allein noch nicht angemeldet');

        self::assertSame(LoginStatus::LoggedIn, $auth->attemptTotp($this->request(), $this->code(), false)->status);
        self::assertTrue($auth->isAuthenticated($this->request()));
    }

    public function testWrongPasswordAndUnknownUserLookAlike(): void
    {
        $auth = $this->service();

        self::assertSame(LoginStatus::Invalid, $auth->attemptPassword($this->request(), 'thomas', 'falsch')->status);
        self::assertSame(LoginStatus::Invalid, $auth->attemptPassword($this->request(), 'niemand', self::PASSWORD)->status);
        self::assertSame(LoginStatus::Invalid, $auth->attemptPassword($this->request(), '', '')->status);
    }

    public function testWrongTotpIsRejected(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);

        self::assertSame(LoginStatus::Invalid, $auth->attemptTotp($this->request(), '000000', false)->status);
        self::assertSame(LoginStatus::Invalid, $auth->attemptTotp($this->request(), 'abc', false)->status);
        self::assertFalse($auth->isAuthenticated($this->request()));
    }

    public function testTotpCodeCannotBeReused(): void
    {
        $code = $this->code();
        $first = $this->service();
        $first->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        self::assertSame(LoginStatus::LoggedIn, $first->attemptTotp($this->request(), $code, false)->status);

        $second = $this->service();
        $second->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        self::assertSame(LoginStatus::Invalid, $second->attemptTotp($this->request(), $code, false)->status);
    }

    public function testTotpWithoutPasswordStepIsRejected(): void
    {
        $auth = $this->service();

        self::assertSame(LoginStatus::Invalid, $auth->attemptTotp($this->request(), $this->code(), false)->status);
    }

    public function testPendingTotpExpires(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        $this->clock += AuthService::PENDING_SECONDS + 1;

        self::assertFalse($auth->hasPendingTotp());
        self::assertSame(LoginStatus::Invalid, $auth->attemptTotp($this->request(), $this->code(), false)->status);
    }

    public function testLockoutAfterFiveFailuresAndReleaseAfterFifteenMinutes(): void
    {
        $auth = $this->service();
        for ($i = 0; $i < AuthService::MAX_FAILURES; ++$i) {
            self::assertSame(LoginStatus::Invalid, $auth->attemptPassword($this->request(), 'thomas', 'falsch')->status);
        }

        // Auch das richtige Passwort wird jetzt abgewiesen.
        self::assertSame(LoginStatus::Locked, $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD)->status);

        $this->clock += AuthService::LOCK_SECONDS + 1;
        self::assertSame(LoginStatus::NeedsTotp, $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD)->status);
    }

    public function testFailedTotpCountsTowardsLockout(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        for ($i = 0; $i < AuthService::MAX_FAILURES; ++$i) {
            $auth->attemptTotp($this->request(), '000000', false);
        }

        self::assertSame(LoginStatus::Locked, $auth->attemptTotp($this->request(), $this->code(), false)->status);
    }

    public function testRememberDeviceSkipsTotpAndStoresOnlyHash(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        $result = $auth->attemptTotp($this->request(), $this->code(), true);

        self::assertSame(LoginStatus::LoggedIn, $result->status);
        self::assertNotNull($result->deviceToken);
        self::assertSame(64, strlen($result->deviceToken));
        $stored = $this->db->fetchValue('SELECT token_hash FROM trusted_devices');
        self::assertSame(hash('sha256', $result->deviceToken), $stored);
        self::assertNotSame($result->deviceToken, $stored);

        $other = $this->service();
        $login = $other->attemptPassword($this->request([DeviceCookie::NAME => $result->deviceToken]), 'thomas', self::PASSWORD);
        self::assertSame(LoginStatus::LoggedIn, $login->status);
        self::assertTrue($other->isAuthenticated($this->request()));
    }

    public function testRememberedDeviceStillNeedsPassword(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        $token = (string) $auth->attemptTotp($this->request(), $this->code(), true)->deviceToken;

        $other = $this->service();
        $result = $other->attemptPassword($this->request([DeviceCookie::NAME => $token]), 'thomas', 'falsch');
        self::assertSame(LoginStatus::Invalid, $result->status);
    }

    public function testDeviceExpiresAfterThirtyDays(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        $token = (string) $auth->attemptTotp($this->request(), $this->code(), true)->deviceToken;

        $this->clock += (AuthService::DEVICE_DAYS * 86400) + 1;
        $result = $this->service()->attemptPassword($this->request([DeviceCookie::NAME => $token]), 'thomas', self::PASSWORD);
        self::assertSame(LoginStatus::NeedsTotp, $result->status);
    }

    public function testDeviceCanBeRevoked(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        $token = (string) $auth->attemptTotp($this->request(), $this->code(), true)->deviceToken;
        $user = $this->users->findByUsername('thomas');
        self::assertNotNull($user);
        $list = $this->devices->listForUser($user->id);
        self::assertCount(1, $list);

        self::assertTrue($this->devices->revoke($user->id, (int) $list[0]['id']));

        $result = $this->service()->attemptPassword($this->request([DeviceCookie::NAME => $token]), 'thomas', self::PASSWORD);
        self::assertSame(LoginStatus::NeedsTotp, $result->status);
    }

    public function testLogoutEndsSession(): void
    {
        $auth = $this->service();
        $auth->attemptPassword($this->request(), 'thomas', self::PASSWORD);
        $auth->attemptTotp($this->request(), $this->code(), false);
        $auth->logout();

        self::assertFalse($auth->isAuthenticated($this->request()));
    }
}
