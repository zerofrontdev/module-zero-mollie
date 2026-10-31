<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\LatePaymentInterface;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\OrderUpdaterInterface;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Model\RedirectPayment\WebhookHandlerInterface;

/**
 * Brings an order in line with its Mollie payment, asked from Mollie itself
 * (the webhook only says which payment changed):
 * - paid, for the order's amount and currency: captured;
 * - authorized (Klarna and other pay-later methods): reserved, taken when
 *   captured in the Mollie dashboard;
 * - canceled, expired, failed: cancelled;
 * - open: nobody paid yet (NOT_STARTED);
 * - pending: nothing yet.
 * It cancels only on canceled, expired or failed, never on "open", so the
 * recheck cron may ask it (RedirectPayment\Updaters). A cancelled order
 * paid anyway is reported by latePayment(), read-only.
 */
class OrderUpdater implements WebhookHandlerInterface, OrderUpdaterInterface, LatePaymentInterface
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment
    ) {
    }

    /**
     * @inheritDoc
     */
    public function update(Order $order, array $params = []): string
    {
        $id = (string) $order->getPayment()?->getAdditionalInformation(Payment::PAYMENT_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $id === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $payment = $this->api->getPayment($id, (int) $order->getStoreId());

        return match ((string) ($payment['status'] ?? '')) {
            'paid' => $this->paid($order, $payment, false),
            'authorized' => $this->paid($order, $payment, true),
            'canceled', 'expired', 'failed' => $this->failed($order, (string) $payment['status']),
            'open' => ReturnResult::NOT_STARTED,
            default => ReturnResult::PENDING,
        };
    }

    /**
     * The verified webhook (now or from the queue): the order brought in line
     * with the provider's answer. $data is the call itself, kept for the
     * record; the state is asked, not taken from it.
     *
     * @inheritDoc
     */
    public function webhook(Order $order, array $data): void
    {
        $this->update($order);
    }

    /**
     * A cancelled order's Mollie payment, asked from Mollie: paid or
     * authorized, or not. Nothing on the order is changed, nothing is
     * captured or cancelled at Mollie.
     *
     * @inheritDoc
     */
    public function latePayment(Order $order): ?array
    {
        $id = (string) $order->getPayment()?->getAdditionalInformation(Payment::PAYMENT_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $id === '') {
            return null;
        }
        $payment = $this->api->getPayment($id, (int) $order->getStoreId());
        if (!in_array((string) ($payment['status'] ?? ''), ['paid', 'authorized'], true)) {
            return null;
        }

        return [
            'id' => (string) ($payment['id'] ?? $id),
            'amount' => (string) ($payment['amount']['value'] ?? ''),
            'currency' => (string) ($payment['amount']['currency'] ?? ''),
        ];
    }

    /**
     * Nobody paid: the payment is cancelled where the method allows it, and
     * the order too.
     */
    public function abandon(Order $order): void
    {
        try {
            $this->api->cancel((string) $order->getPayment()->getAdditionalInformation(Payment::PAYMENT_ID), (int) $order->getStoreId());
        } catch (LocalizedException) {
            // Not cancelable: it expires on its own.
        }
        $this->failed($order, (string) __('the customer came back without paying'));
    }

    private function paid(Order $order, array $payment, bool $authorizedOnly): string
    {
        $expected = Payment::amount($order, (float) $order->getGrandTotal());
        if (($payment['amount'] ?? null) != $expected) {
            $this->orderPayment->flag(
                $order,
                (string) __('Mollie reports a payment of a different amount; check it in the Mollie dashboard.'),
                sprintf(
                    'Mollie: %s %s, expected %s %s',
                    $payment['amount']['value'] ?? '?',
                    $payment['amount']['currency'] ?? '?',
                    $expected['value'],
                    $expected['currency']
                )
            );

            return ReturnResult::PENDING;
        }
        if ($authorizedOnly) {
            $this->orderPayment->authorize($order, (string) $payment['id']);
        } else {
            $this->orderPayment->capture($order, (string) $payment['id']);
        }

        return ReturnResult::PAID;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('Mollie payment did not go through: %1', $reason));

        return ReturnResult::FAILED;
    }
}
