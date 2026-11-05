<?php

declare(strict_types=1);

namespace Tests\Notify;

use App\Core\Notification;
use App\Notify\PushResult;
use App\Notify\PushSubscriptionRepository;
use App\Notify\WebPushChannel;
use Tests\DbTestCase;

final class WebPushChannelTest extends DbTestCase
{
    private PushSubscriptionRepository $repo;
    private FakePushSender $sender;
    private WebPushChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PushSubscriptionRepository($this->db);
        $this->sender = new FakePushSender();
        $this->channel = new WebPushChannel($this->repo, $this->sender, fn (): int => (int) strtotime('2026-11-05 08:00:00 UTC'));
        foreach (['a', 'b', 'c'] as $name) {
            $this->repo->upsert("https://fcm.googleapis.com/fcm/send/$name", 'k', 'a', "Gerät $name", '2026-11-01 00:00:00');
        }
    }

    public function testDeliversToAllDevicesWithJsonPayload(): void
    {
        $report = $this->channel->send(new Notification('Titel', 'Text', '/aufgaben', 'tag-1'));

        self::assertSame([3, 0, 0], [$report->delivered, $report->failed, $report->removed]);
        self::assertCount(3, $this->sender->sent);
        self::assertSame(['title' => 'Titel', 'body' => 'Text', 'url' => '/aufgaben', 'tag' => 'tag-1'], $this->sender->payloads()[0]);
        self::assertSame('2026-11-05 08:00:00', $this->repo->all()[0]['last_ok_at']);
    }

    public function testExpiredSubscriptionsAreRemoved(): void
    {
        $this->sender->results['https://fcm.googleapis.com/fcm/send/b'] = PushResult::Gone;

        $report = $this->channel->send(new Notification('T', 'B'));

        self::assertSame([2, 0, 1], [$report->delivered, $report->failed, $report->removed]);
        self::assertSame(['Gerät a', 'Gerät c'], array_column($this->repo->all(), 'label'));
    }

    public function testFailuresAreCountedAndEntireFailureIsDetected(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->sender->results["https://fcm.googleapis.com/fcm/send/$name"] = PushResult::Failed;
        }
        $report = $this->channel->send(new Notification('T', 'B'));

        self::assertTrue($report->failedEntirely());
        self::assertCount(3, $this->repo->all(), 'vorübergehende Fehler entfernen das Abo nicht');
        self::assertSame(3, (int) $this->db->fetchValue('SELECT SUM(failures) FROM push_subscriptions'));
    }

    public function testNoSubscriptionsIsNotAFailure(): void
    {
        foreach ($this->repo->all() as $sub) {
            $this->repo->delete($sub['id']);
        }
        $report = $this->channel->send(new Notification('T', 'B'));

        self::assertFalse($report->failedEntirely());
        self::assertSame(0, $report->delivered);
    }

    public function testSubscriptionIsDroppedAfterManyConsecutiveFailures(): void
    {
        $id = $this->repo->all()[0]['id'];
        for ($i = 0; $i < 20; ++$i) {
            $this->repo->markFailure($id);
        }

        self::assertCount(2, $this->repo->all());
    }
}
