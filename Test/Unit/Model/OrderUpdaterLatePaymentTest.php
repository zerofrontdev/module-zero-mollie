<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Test\Unit\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Mollie\Model\Api;
use Zero\Mollie\Model\OrderUpdater;
use Zero\Mollie\Model\Payment;

/**
 * A cancelled order's Mollie payment is only asked about: paid or authorized
 * is reported, nothing is cancelled at Mollie. An open payment never cancels
 * the order from update() (only the return page abandons it).
 */
class OrderUpdaterLatePaymentTest extends TestCase
{
    private Api&MockObject $api;
    private OrderPayment&MockObject $orderPayment;
    private OrderUpdater $updater;

    protected function setUp(): void
    {
        $this->api = $this->createMock(Api::class);
        $this->orderPayment = $this->createMock(OrderPayment::class);
        $this->updater = new OrderUpdater($this->api, $this->orderPayment);
    }

    private function order(string $method = Payment::CODE, string $id = 'tr_1', ?OrderPaymentInfo $payment = null): Order
    {
        $payment ??= $this->createStub(OrderPaymentInfo::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::PAYMENT_ID, $id]]);
        $order = $this->createStub(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getGrandTotal')->willReturn(10.5);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        return $order;
    }

    #[DataProvider('paid')]
    public function testPaidIsReported(string $status): void
    {
        $this->api->expects($this->once())->method('getPayment')->with('tr_1', 1)
            ->willReturn(['id' => 'tr_1', 'status' => $status, 'amount' => ['currency' => 'EUR', 'value' => '10.50']]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertSame(
            ['id' => 'tr_1', 'amount' => '10.50', 'currency' => 'EUR'],
            $this->updater->latePayment($this->order())
        );
    }

    /** @return array<string, array{string}> */
    public static function paid(): array
    {
        return ['paid' => ['paid'], 'authorized' => ['authorized']];
    }

    #[DataProvider('unpaid')]
    public function testUnpaidIsNull(string $status): void
    {
        $this->api->expects($this->once())->method('getPayment')->willReturn(['id' => 'tr_1', 'status' => $status]);
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order()));
    }

    /** @return array<string, array{string}> */
    public static function unpaid(): array
    {
        return ['open' => ['open'], 'pending' => ['pending'], 'canceled' => ['canceled'], 'expired' => ['expired'], 'failed' => ['failed']];
    }

    public function testNeverChangesTheOrder(): void
    {
        $payment = $this->createMock(OrderPaymentInfo::class);
        $payment->expects($this->never())->method('setAdditionalInformation');
        $this->api->expects($this->once())->method('getPayment')
            ->willReturn(['id' => 'tr_1', 'status' => 'authorized', 'amount' => ['currency' => 'EUR', 'value' => '10.50']]);
        $this->api->expects($this->never())->method($this->logicalOr('cancel', 'refund', 'createPayment'));
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNotNull($this->updater->latePayment($this->order(Payment::CODE, 'tr_1', $payment)));
    }

    public function testAnotherMethodOrNoPaymentIsNullWithoutAsking(): void
    {
        $this->api->expects($this->never())->method('getPayment');
        $this->orderPayment->expects($this->never())->method($this->anything());

        $this->assertNull($this->updater->latePayment($this->order('zero_adyen')));
        $this->assertNull($this->updater->latePayment($this->order(Payment::CODE, '')));
    }

    public function testAnOpenPaymentNeverCancelsTheOrder(): void
    {
        $this->api->expects($this->exactly(2))->method('getPayment')
            ->willReturnOnConsecutiveCalls(['status' => 'open'], ['status' => 'pending']);
        $this->api->expects($this->never())->method('cancel');
        $this->orderPayment->method('settled')->willReturn(null);
        $this->orderPayment->expects($this->never())->method($this->logicalOr('cancel', 'capture', 'authorize'));

        $this->assertSame(ReturnResult::NOT_STARTED, $this->updater->update($this->order()));
        $this->assertSame(ReturnResult::PENDING, $this->updater->update($this->order()));
    }
}
