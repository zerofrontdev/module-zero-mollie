<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Test\Unit\Model;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\Mollie\Model\Api;
use Zero\Mollie\Model\Payment;

class PaymentTest extends TestCase
{
    public function testAmountValueHasTwoDecimals(): void
    {
        $this->assertSame('10.00', Api::value(10));
        $this->assertSame('1234.57', Api::value(1234.567));
    }

    /**
     * Mollie refuses a webhook it can't reach: none is sent from a development site.
     */
    public function testWebhookOnlyForPublicSites(): void
    {
        $this->assertTrue(Payment::isPublic('https://zero.fixstacks.com/zero_mollie/payment/webhook/'));
        $this->assertFalse(Payment::isPublic('http://magento2.test:8080/zero_mollie/payment/webhook/'));
        $this->assertFalse(Payment::isPublic('http://localhost/zero_mollie/payment/webhook/'));
        $this->assertFalse(Payment::isPublic('http://192.168.1.10/zero_mollie/payment/webhook/'));
    }

    /**
     * Mollie's amount through MinorUnits: two decimals as before, none for
     * JPY and ISK (Mollie's multicurrency table; orchestrator decision).
     */
    #[DataProvider('amounts')]
    public function testAmountUnchanged(string $currency, float $amount, string $value): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn($currency);

        $this->assertSame(['currency' => $currency, 'value' => $value], Payment::amount($order, $amount));
    }

    /** @return array<string, array{string, float, string}> */
    public static function amounts(): array
    {
        return [
            'EUR' => ['EUR', 10.0, '10.00'],
            'EUR rounded' => ['EUR', 1234.567, '1234.57'],
            'HUF keeps two' => ['HUF', 1000.0, '1000.00'],
            'TWD keeps two' => ['TWD', 99.5, '99.50'],
            'JPY whole' => ['JPY', 1000.0, '1000'],
            'ISK whole' => ['ISK', 500.4, '500'],
        ];
    }
}
