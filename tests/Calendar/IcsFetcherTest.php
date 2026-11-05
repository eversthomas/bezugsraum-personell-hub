<?php

declare(strict_types=1);

namespace Tests\Calendar;

use App\Calendar\FetchException;
use App\Calendar\HttpResponse;
use App\Calendar\IcsFetcher;
use App\Calendar\UrlGuard;
use PHPUnit\Framework\TestCase;

final class IcsFetcherTest extends TestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";

    /**
     * @param list<HttpResponse> $responses
     * @param-out FakeTransport $transport
     */
    private function fetcher(array $responses, ?FakeTransport &$transport = null): IcsFetcher
    {
        $transport = new FakeTransport($responses);
        $resolver = new FakeResolver([
            'kalender.example' => ['93.184.216.34'],
            'cdn.example' => ['93.184.216.35'],
            'intern.example' => ['10.0.0.5'],
        ]);

        return new IcsFetcher(new UrlGuard($resolver), $transport);
    }

    public function testFetchesCalendarAndReturnsEtag(): void
    {
        $fetcher = $this->fetcher([new HttpResponse(200, ['etag' => '"v1"'], self::ICS)]);
        $result = $fetcher->fetch('https://kalender.example/a.ics');

        self::assertFalse($result->notModified);
        self::assertSame(self::ICS, $result->body);
        self::assertSame('"v1"', $result->etag);
    }

    public function testSendsEtagAndHandlesNotModified(): void
    {
        $transport = null;
        $fetcher = $this->fetcher([new HttpResponse(304, [], '')], $transport);
        $result = $fetcher->fetch('https://kalender.example/a.ics', '"v1"');

        self::assertTrue($result->notModified);
        self::assertSame('"v1"', $transport->requestHeaders[0]['If-None-Match']);
    }

    public function testFollowsRedirectsAndChecksEachHop(): void
    {
        $transport = null;
        $fetcher = $this->fetcher([
            new HttpResponse(302, ['location' => 'https://cdn.example/b.ics'], ''),
            new HttpResponse(301, ['location' => '/c.ics'], ''),
            new HttpResponse(200, [], self::ICS),
        ], $transport);
        $fetcher->fetch('https://kalender.example/a.ics');

        self::assertSame(['kalender.example', 'cdn.example', 'cdn.example'], array_map(static fn ($t) => $t->host, $transport->targets));
        self::assertSame('https://cdn.example/c.ics', $transport->targets[2]->url);
    }

    public function testRedirectToInternalHostIsBlocked(): void
    {
        $transport = null;
        $fetcher = $this->fetcher([new HttpResponse(302, ['location' => 'https://intern.example/'], '')], $transport);

        try {
            $fetcher->fetch('https://kalender.example/a.ics');
            self::fail('Erwartet FetchException');
        } catch (FetchException) {
            self::assertCount(1, $transport->targets, 'Der interne Host wurde nie kontaktiert');
        }
    }

    public function testRedirectToHttpAndToMetadataAddressIsBlocked(): void
    {
        foreach (['http://kalender.example/a.ics', 'https://169.254.169.254/latest/meta-data/', 'file:///etc/passwd'] as $location) {
            $fetcher = $this->fetcher([new HttpResponse(302, ['location' => $location], '')]);
            try {
                $fetcher->fetch('https://kalender.example/a.ics');
                self::fail('Erwartet FetchException für ' . $location);
            } catch (FetchException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMoreThanThreeRedirectsFail(): void
    {
        $redirect = new HttpResponse(302, ['location' => 'https://kalender.example/n.ics'], '');
        $fetcher = $this->fetcher([$redirect, $redirect, $redirect, $redirect, new HttpResponse(200, [], self::ICS)]);

        $this->expectException(FetchException::class);
        $this->expectExceptionMessage('Zu viele Weiterleitungen');
        $fetcher->fetch('https://kalender.example/a.ics');
    }

    public function testExactlyThreeRedirectsAreAllowed(): void
    {
        $redirect = new HttpResponse(302, ['location' => 'https://kalender.example/n.ics'], '');
        $fetcher = $this->fetcher([$redirect, $redirect, $redirect, new HttpResponse(200, [], self::ICS)]);

        self::assertSame(self::ICS, $fetcher->fetch('https://kalender.example/a.ics')->body);
    }

    public function testRejectsNonCalendarAndErrorStatuses(): void
    {
        foreach ([new HttpResponse(200, [], '<html>Login</html>'), new HttpResponse(404, [], ''), new HttpResponse(403, [], ''), new HttpResponse(500, [], '')] as $response) {
            try {
                $this->fetcher([$response])->fetch('https://kalender.example/a.ics');
                self::fail('Erwartet FetchException');
            } catch (FetchException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testErrorMessagesContainNoUrl(): void
    {
        try {
            $this->fetcher([new HttpResponse(404, [], '')])->fetch('https://kalender.example/geheim?token=SECRET');
            self::fail('Erwartet FetchException');
        } catch (FetchException $e) {
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }
}
