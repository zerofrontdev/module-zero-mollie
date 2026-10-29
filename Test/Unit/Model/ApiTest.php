<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Core\Model\Http\JsonClient;
use Zero\Mollie\Model\Api;

class ApiTest extends TestCase
{
    private JsonClient&MockObject $client;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private LoggerInterface&MockObject $logger;
    private Api $api;

    protected function setUp(): void
    {
        $this->client = $this->createMock(JsonClient::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->api = new Api($this->client, $this->scopeConfig, $this->createMock(EncryptorInterface::class), $this->logger, 9);
    }

    public function testAPaymentIsCreatedWithTheBearerKeyAndTheConfiguredTimeout(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('test_abc');
        $this->client->expects(self::once())->method('request')
            ->with('POST', 'https://api.mollie.com/v2/payments', ['amount' => ['value' => '10.00']], ['Authorization' => 'Bearer test_abc'], 9)
            ->willReturn(['status' => 201, 'body' => ['id' => 'tr_1', '_links' => ['checkout' => ['href' => 'https://pay']]]]);

        $payment = $this->api->createPayment(['amount' => ['value' => '10.00']], 1);

        self::assertSame('tr_1', $payment['id']);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');
        $this->client->expects(self::never())->method('request');

        $this->expectException(LocalizedException::class);
        $this->api->getPayment('tr_1', 1);
    }

    public function testAnIdThatIsNotAMolliePaymentIsRefusedBeforeAnyRequest(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('test_abc');
        $this->client->expects(self::never())->method('request');

        $this->expectException(LocalizedException::class);
        $this->api->getPayment('../customers', 1);
    }

    public function testARefusalCarriesMollieSOwnDetailAndIsLogged(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('test_abc');
        $this->client->method('request')->willReturn(['status' => 422, 'body' => ['detail' => 'The amount is too low']]);
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('HTTP 422 The amount is too low'));

        try {
            $this->api->refund('tr_1', ['value' => '0.01'], 1);
            self::fail('A refusal must throw');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('The amount is too low', (string) $e->getMessage());
        }
    }

    public function testATransportFailureBecomesAFriendlyErrorAndIsLogged(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('test_abc');
        $this->client->method('request')->willThrowException(new \RuntimeException('timed out'));
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('timed out'));

        $this->expectException(LocalizedException::class);
        $this->api->getPayment('tr_1', 1);
    }

    public function testCancellingAnOpenPaymentAcceptsTheEmptyAnswer(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('test_abc');
        $this->client->expects(self::once())->method('request')
            ->with('DELETE', 'https://api.mollie.com/v2/payments/tr_1', null, self::anything(), 9)
            ->willReturn(['status' => 204, 'body' => null]);
        $this->logger->expects(self::never())->method('warning');

        $this->api->cancel('tr_1', 1);
    }

    public function testAnHtmlAnswerToAGetIsAnError(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('test_abc');
        $this->client->method('request')->willReturn(['status' => 200, 'body' => null]);

        $this->expectException(LocalizedException::class);
        $this->api->getPayment('tr_1', 1);
    }
}
