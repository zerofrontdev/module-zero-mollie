<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mollie\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zero\Core\Model\Http\JsonClient;

/**
 * Mollie's Payments API v2: the API key as a bearer token (test_… or
 * live_…), JSON both ways, amounts as {"currency": "EUR", "value": "10.00"}.
 */
class Api
{
    private const URL = 'https://api.mollie.com/v2/';

    public function __construct(
        private readonly JsonClient $client,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function createPayment(array $payment, ?int $storeId): array
    {
        $answer = $this->send('POST', 'payments', $payment, $storeId);
        if (empty($answer['id']) || empty($answer['_links']['checkout']['href'])) {
            throw new LocalizedException(__('Mollie didn\'t create the payment. Please try again or choose another payment method.'));
        }

        return $answer;
    }

    /**
     * @throws LocalizedException
     */
    public function getPayment(string $id, ?int $storeId): array
    {
        if (!preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
            throw new LocalizedException(__('Not a Mollie payment.'));
        }

        return $this->send('GET', 'payments/' . $id, null, $storeId);
    }

    /**
     * @throws LocalizedException
     */
    public function refund(string $id, array $amount, ?int $storeId): array
    {
        return $this->send('POST', 'payments/' . $id . '/refunds', ['amount' => $amount], $storeId);
    }

    /**
     * Cancels an open payment, so it can't be paid any more (only some methods
     * allow it; the others expire).
     *
     * @throws LocalizedException
     */
    public function cancel(string $id, ?int $storeId): void
    {
        $this->send('DELETE', 'payments/' . $id, null, $storeId);
    }

    public function isConfigured(?int $storeId): bool
    {
        return $this->apiKey($storeId) !== '';
    }

    /**
     * Mollie's amount value: two decimals, a string.
     */
    public static function value(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @throws LocalizedException
     */
    private function send(string $method, string $path, ?array $body, ?int $storeId): array
    {
        $key = $this->apiKey($storeId);
        if ($key === '') {
            throw new LocalizedException(__('Mollie payments are not set up: the API key is missing.'));
        }
        try {
            ['status' => $status, 'body' => $answer] = $this->client->request(
                $method,
                self::URL . $path,
                $body,
                ['Authorization' => 'Bearer ' . $key],
                $this->timeout
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Zero Mollie: ' . $path . ': ' . $e->getMessage());
            throw new LocalizedException(__('Mollie is not answering. Please try again in a minute.'), $e);
        }
        // A cancelled payment (DELETE) answers 204 with nothing in the body.
        if ($status < 200 || $status >= 300 || ($answer === null && $method !== 'DELETE')) {
            $detail = is_array($answer) ? (string) ($answer['detail'] ?? '') : '';
            $this->logger->warning(sprintf('Zero Mollie: %s: HTTP %d %s', $path, $status, $detail));
            throw new LocalizedException(__('Mollie refused the request: %1', $detail !== '' ? $detail : 'HTTP ' . $status));
        }

        return $answer ?? [];
    }

    private function apiKey(?int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue('payment/zero_mollie/api_key', ScopeInterface::SCOPE_STORE, $storeId));

        // Read through the Encrypted backend model its config.xml default names, the
        // value comes decrypted; one stored some other way may still be encrypted.
        return preg_match('/^\d+:\d+:/', $value) ? trim((string) $this->encryptor->decrypt($value)) : $value;
    }
}
