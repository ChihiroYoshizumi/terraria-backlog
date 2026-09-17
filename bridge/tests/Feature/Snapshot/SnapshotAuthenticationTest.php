<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「token 無し / 不正 token を reject」。
 *
 * AC-16: 未認証の通知を拒否し、ログとゲーム内表示に API Key が含まれない。
 * docs/design.md §6.1 / §16.1。
 */
final class SnapshotAuthenticationTest extends SnapshotTestCase
{
    /**
     * @return array<string, array{0: string|null}>
     */
    public static function invalidAuthorizationHeaders(): array
    {
        return [
            'header missing' => [null],
            'empty header' => [''],
            'wrong token' => ['Bearer wrong-adapter-token'],
            'right token wrong scheme' => ['Basic '.self::TOKEN],
            'bare token without scheme' => [self::TOKEN],
            'token as prefix of the real one' => ['Bearer test-adapter-toke'],
            'token with trailing junk' => ['Bearer '.self::TOKEN.'x'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAuthorizationHeaders')]
    public function it_rejects_requests_without_a_valid_adapter_token(?string $header): void
    {
        $processor = $this->recordProcessor();

        $response = $this->postSnapshot(
            SnapshotPayload::valid(),
            headers: ['Authorization' => $header],
        );

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate', 'Bearer');
        $response->assertJsonPath('error.code', 'auth.unauthorized');

        // 認証前に Snapshot 処理を開始していないこと。
        $this->assertSame([], $processor->received);

        // token の値そのものを response へ echo しないこと。
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent() ?: '');
    }

    #[Test]
    public function it_accepts_the_configured_adapter_token(): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::valid())->assertOk();

        $this->assertCount(1, $processor->received);
    }

    #[Test]
    public function it_fails_closed_when_no_adapter_token_is_configured(): void
    {
        config()->set('terraria.adapter_token', null);
        $processor = $this->recordProcessor();

        // token 未設定を「認証不要」と解釈しない。
        $this->postSnapshot(SnapshotPayload::valid(), headers: ['Authorization' => null])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'bridge.misconfigured');

        $this->assertSame([], $processor->received);
    }
}
