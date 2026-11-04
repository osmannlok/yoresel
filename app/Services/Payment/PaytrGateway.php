<?php

/**
 * Aşıroğlu Yöresel Market - PayTR iFrame API adapter'ı
 *
 * PaymentGatewayInterface'in PayTR karşılığı. Müşteri kartını PayTR'nin
 * barındırdığı iFrame sayfasında girer (3D Secure dahil); kart verisi bu
 * uygulamaya hiç ulaşmaz (FR-071, DECISIONS.md Ödeme).
 *
 * Akış:
 * 1. initiate(): sunucudan PayTR get-token çağrılır, iFrame adresi döner.
 * 2. PayTR sonucu Bildirim URL'ine POST eder; verifyCallback() imzayı
 *    doğrular, parseCallback() ortak biçime çevirir. Geçerli imzalı her
 *    bildirime (tekrarlar dahil) callbackAcknowledgement() ile "OK" dönülür.
 *
 * PayTR'ye özgü kurallar (dev.paytr.com iFrame API 1. ve 2. adım):
 * - merchant_oid yalnızca alfanümerik olabilir: 'ASY-YYYYMMDD-NNNNNN' →
 *   'ASYYYYYMMDDNNNNNN' biçiminde tireler atılarak gönderilir. Aynı sipariş
 *   için yeniden denemede (request['attempt'] > 1) sona 'R<deneme>' eklenir;
 *   parseCallback() bunu tekrar sipariş numarasına çevirir ve tam değeri
 *   provider_reference olarak döndürür.
 * - Tutarlar kuruş (tam sayı) gönderilir: "34.56" → 3456. float kullanılmaz.
 * - Para birimi PayTR'de 'TL'dir; arayüzdeki 'TRY' ile dönüştürülür.
 * - Bildirim hash'i yalnızca merchant_oid, status ve total_amount alanlarını
 *   kapsar; payment_amount imzasızdır, bu yüzden tutar olarak total_amount
 *   kullanılır. Taksit vade farkı olursa total_amount siparişten büyük
 *   olabilir; tek çekimde (no_installment=1) eşittir. Karşılaştırma ödeme
 *   servisinde yapılır.
 * - Hash karşılaştırması hash_equals() ile yapılır.
 *
 * Yapılandırma: constructor'a dizi olarak verilir (merchant_id, merchant_key,
 * merchant_salt, test_mode, timeout_minutes, debug). Değerler config/payment.php
 * (.env) kaynağından çağıran tarafından okunur; bu sınıf dosya veya ortam
 * değişkeni okumaz, secret'ları log_data'ya yazmaz.
 *
 * Test için HTTP katmanı $httpPost ile değiştirilebilir:
 * fn(string $url, array $fields): string (ham yanıt gövdesi; hatada RuntimeException).
 *
 * Gereksinimler: FR-071, FR-075.
 */

declare(strict_types=1);

namespace App\Services\Payment;

use RuntimeException;
use UnexpectedValueException;

final class PaytrGateway implements PaymentGatewayInterface
{
    private const TOKEN_URL  = 'https://www.paytr.com/odeme/api/get-token';
    private const IFRAME_URL = 'https://www.paytr.com/odeme/guvenli/';

    /** PayTR alan sınırları */
    private const MAX_IP      = 39;
    private const MAX_EMAIL   = 100;
    private const MAX_NAME    = 60;
    private const MAX_ADDRESS = 400;
    private const MAX_PHONE   = 20;

    /** Sipariş numarası: ASY-YYYYMMDD-NNNNNN */
    private const ORDER_NUMBER_PATTERN = '/^ASY-(\d{8})-(\d{6})$/';

    /** merchant_oid: ASY + tarih + no (+ R<deneme>) */
    private const MERCHANT_OID_PATTERN = '/^ASY(\d{8})(\d{6})(?:R(\d{1,3}))?$/';

    private string $merchantId;
    private string $merchantKey;
    private string $merchantSalt;
    private bool $testMode;
    private int $timeoutMinutes;
    private bool $debug;

    /** @var callable(string, array<string, scalar>): string */
    private $httpPost;

    /**
     * @param array<string, mixed> $config
     * @param callable(string, array<string, scalar>): string|null $httpPost
     *
     * @throws RuntimeException Zorunlu yapılandırma eksikse
     */
    public function __construct(array $config, ?callable $httpPost = null)
    {
        $this->merchantId   = trim((string) ($config['merchant_id'] ?? ''));
        $this->merchantKey  = (string) ($config['merchant_key'] ?? '');
        $this->merchantSalt = (string) ($config['merchant_salt'] ?? '');

        if ($this->merchantId === '' || $this->merchantKey === '' || $this->merchantSalt === '') {
            throw new RuntimeException('PayTR yapılandırması eksik.');
        }

        $this->testMode       = (bool) ($config['test_mode'] ?? false);
        $this->timeoutMinutes = max(1, min(1440, (int) ($config['timeout_minutes'] ?? 30)));
        $this->debug          = (bool) ($config['debug'] ?? false);
        $this->httpPost       = $httpPost ?? [$this, 'curlPost'];
    }

    public function providerName(): string
    {
        return 'paytr';
    }

    /**
     * {@inheritdoc}
     *
     * Ek opsiyonel anahtar: attempt (int, varsayılan 1) — aynı sipariş için
     * yeniden ödeme denemesi; merchant_oid'i benzersiz tutar.
     */
    public function initiate(array $request): array
    {
        $orderNumber = (string) ($request['order_number'] ?? '');
        $currency    = (string) ($request['currency'] ?? '');
        $customer    = $request['customer'] ?? null;
        $items       = $request['items'] ?? null;
        $urls        = $request['urls'] ?? null;

        if ($currency !== 'TRY') {
            throw new RuntimeException('PayTR yalnızca TRY destekleniyor.');
        }
        if (!is_array($customer) || !is_array($items) || $items === [] || !is_array($urls)) {
            throw new RuntimeException('PayTR isteği eksik: customer, items veya urls.');
        }

        $attempt     = max(1, (int) ($request['attempt'] ?? 1));
        $merchantOid = $this->toMerchantOid($orderNumber, $attempt);
        $amountKurus = $this->toKurus((string) ($request['amount'] ?? ''));
        if ($amountKurus <= 0) {
            throw new RuntimeException('PayTR tutarı sıfırdan büyük olmalı.');
        }

        $email = trim((string) ($customer['email'] ?? ''));
        if ($email === '' || strlen($email) > self::MAX_EMAIL || preg_match('/^[\x20-\x7E]+$/', $email) !== 1) {
            throw new RuntimeException('PayTR için e-posta geçersiz (boş, uzun veya ASCII dışı).');
        }

        $name    = trim((string) ($customer['name'] ?? ''));
        $address = trim((string) ($customer['address'] ?? ''));
        $phone   = trim((string) ($customer['phone'] ?? ''));
        $ip      = trim((string) ($customer['ip'] ?? ''));
        if ($name === '' || $address === '' || $phone === '' || $ip === '') {
            throw new RuntimeException('PayTR için ad, adres, telefon ve IP zorunlu.');
        }

        $okUrl   = (string) ($urls['ok_url'] ?? '');
        $failUrl = (string) ($urls['fail_url'] ?? '');
        if ($okUrl === '' || $failUrl === '') {
            throw new RuntimeException('PayTR için ok_url ve fail_url zorunlu.');
        }

        $basket = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('PayTR kalem biçimi geçersiz.');
            }
            $basket[] = [
                mb_substr((string) ($item['name'] ?? ''), 0, 200),
                number_format($this->toKurus((string) ($item['unit_price'] ?? '')) / 100, 2, '.', ''),
                max(1, (int) ($item['quantity'] ?? 1)),
            ];
        }
        $userBasket = base64_encode((string) json_encode($basket));

        // Taksit: tek çekimde taksit seçenekleri gizlenir.
        $installments  = max(1, (int) ($request['installment_count'] ?? 1));
        $noInstallment = $installments === 1 ? 1 : 0;
        $maxInstallment = $installments === 1 ? 0 : min(12, $installments);

        $userIp   = mb_substr($ip, 0, self::MAX_IP);
        $testMode = $this->testMode ? 1 : 0;

        $hashStr = $this->merchantId . $userIp . $merchantOid . $email . $amountKurus
            . $userBasket . $noInstallment . $maxInstallment . 'TL' . $testMode;
        $token = base64_encode(hash_hmac('sha256', $hashStr . $this->merchantSalt, $this->merchantKey, true));

        $fields = [
            'merchant_id'       => $this->merchantId,
            'user_ip'           => $userIp,
            'merchant_oid'      => $merchantOid,
            'email'             => $email,
            'payment_amount'    => $amountKurus,
            'paytr_token'       => $token,
            'user_basket'       => $userBasket,
            'debug_on'          => $this->debug ? 1 : 0,
            'no_installment'    => $noInstallment,
            'max_installment'   => $maxInstallment,
            'user_name'         => mb_substr($name, 0, self::MAX_NAME),
            'user_address'      => mb_substr($address, 0, self::MAX_ADDRESS),
            'user_phone'        => mb_substr($phone, 0, self::MAX_PHONE),
            'merchant_ok_url'   => $okUrl,
            'merchant_fail_url' => $failUrl,
            'timeout_limit'     => $this->timeoutMinutes,
            'currency'          => 'TL',
            'test_mode'         => $testMode,
            'lang'              => 'tr',
        ];

        $raw     = ($this->httpPost)(self::TOKEN_URL, $fields);
        $decoded = json_decode($raw, true);

        $logRequest = [
            'merchant_oid'    => $merchantOid,
            'payment_amount'  => $amountKurus,
            'currency'        => 'TL',
            'no_installment'  => $noInstallment,
            'max_installment' => $maxInstallment,
            'test_mode'       => $testMode,
            'timeout_limit'   => $this->timeoutMinutes,
        ];

        if (!is_array($decoded) || !isset($decoded['status'])) {
            throw new RuntimeException('PayTR yanıtı geçersiz.');
        }
        if ($decoded['status'] !== 'success' || empty($decoded['token']) || !is_string($decoded['token'])) {
            $reason = mb_substr((string) ($decoded['reason'] ?? 'bilinmiyor'), 0, 200);
            throw new RuntimeException('PayTR token alınamadı: ' . $reason);
        }

        return [
            'type'                    => self::TYPE_IFRAME,
            'url'                     => self::IFRAME_URL . $decoded['token'],
            'token'                   => $decoded['token'],
            'html'                    => null,
            'provider_transaction_id' => null,
            'provider_reference'      => $merchantOid,
            // Token ve kişisel veri (e-posta, telefon, adres) loga yazılmaz.
            'log_data'                => [
                'request'  => $logRequest,
                'response' => ['status' => 'success'],
            ],
        ];
    }

    public function verifyCallback(array $payload, array $headers = []): bool
    {
        foreach (['merchant_oid', 'status', 'total_amount', 'hash'] as $key) {
            if (!isset($payload[$key]) || !is_string($payload[$key]) || $payload[$key] === '') {
                return false;
            }
        }

        $expected = base64_encode(hash_hmac(
            'sha256',
            $payload['merchant_oid'] . $this->merchantSalt . $payload['status'] . $payload['total_amount'],
            $this->merchantKey,
            true
        ));

        return hash_equals($expected, $payload['hash']);
    }

    public function parseCallback(array $payload, array $headers = []): array
    {
        if (!$this->verifyCallback($payload, $headers)) {
            throw new UnexpectedValueException('PayTR bildirim imzası geçersiz.');
        }

        $merchantOid = (string) $payload['merchant_oid'];
        if (preg_match(self::MERCHANT_OID_PATTERN, $merchantOid, $m) !== 1) {
            throw new UnexpectedValueException('PayTR merchant_oid biçimi tanınmadı.');
        }
        $orderNumber = 'ASY-' . $m[1] . '-' . $m[2];

        $status = (string) $payload['status'];
        if ($status !== 'success' && $status !== 'failed') {
            throw new UnexpectedValueException('PayTR status değeri tanınmadı.');
        }

        $totalAmount = (string) $payload['total_amount'];
        if (preg_match('/^\d{1,12}$/', $totalAmount) !== 1) {
            throw new UnexpectedValueException('PayTR total_amount biçimi geçersiz.');
        }
        $kurus  = (int) $totalAmount;
        $amount = sprintf('%d.%02d', intdiv($kurus, 100), $kurus % 100);

        $completed = $status === 'success';

        return [
            'order_number'            => $orderNumber,
            'status'                  => $completed ? self::STATUS_COMPLETED : self::STATUS_FAILED,
            'amount'                  => $amount,
            'currency'                => $this->normalizeCurrency((string) ($payload['currency'] ?? 'TL')),
            'provider_transaction_id' => null,
            'provider_reference'      => $merchantOid,
            'card_mask'               => null,
            // Kullanıcıya genel mesaj; PayTR'nin ham hata metni yalnızca log_data'da.
            'error_message'           => $completed ? null : 'Ödeme tamamlanamadı. Lütfen tekrar deneyin.',
            // hash alanı loga yazılmaz.
            'log_data'                => [
                'merchant_oid'       => $merchantOid,
                'status'             => $status,
                'total_amount'       => $totalAmount,
                'payment_amount'     => isset($payload['payment_amount']) ? (string) $payload['payment_amount'] : null,
                'currency'           => isset($payload['currency']) ? (string) $payload['currency'] : null,
                'payment_type'       => isset($payload['payment_type']) ? (string) $payload['payment_type'] : null,
                'test_mode'          => isset($payload['test_mode']) ? (string) $payload['test_mode'] : null,
                'failed_reason_code' => isset($payload['failed_reason_code']) ? (string) $payload['failed_reason_code'] : null,
                'failed_reason_msg'  => isset($payload['failed_reason_msg'])
                    ? mb_substr((string) $payload['failed_reason_msg'], 0, 300)
                    : null,
            ],
        ];
    }

    public function callbackAcknowledgement(): string
    {
        return 'OK';
    }

    /**
     * 'ASY-20261103-000012' (+ deneme) → 'ASY20261103000012' / 'ASY20261103000012R2'.
     *
     * @throws RuntimeException Sipariş numarası biçimi geçersizse
     */
    private function toMerchantOid(string $orderNumber, int $attempt): string
    {
        if (preg_match(self::ORDER_NUMBER_PATTERN, $orderNumber, $m) !== 1) {
            throw new RuntimeException('PayTR için sipariş numarası biçimi geçersiz.');
        }

        $oid = 'ASY' . $m[1] . $m[2];

        return $attempt > 1 ? $oid . 'R' . min($attempt, 999) : $oid;
    }

    /**
     * "1250.00" / "34.5" / "12" → kuruş (tam sayı). float kullanılmaz.
     *
     * @throws RuntimeException Biçim geçersizse
     */
    private function toKurus(string $amount): int
    {
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $amount, $m) !== 1) {
            throw new RuntimeException('PayTR için tutar biçimi geçersiz.');
        }

        $fraction = str_pad($m[2] ?? '', 2, '0');

        return ((int) $m[1]) * 100 + (int) $fraction;
    }

    private function normalizeCurrency(string $currency): string
    {
        return $currency === 'TL' ? 'TRY' : $currency;
    }

    /**
     * Varsayılan HTTP katmanı: PayTR get-token çağrısı (SSL doğrulaması açık).
     *
     * @param array<string, scalar> $fields
     *
     * @throws RuntimeException Bağlantı veya HTTP hatasında
     */
    private function curlPost(string $url, array $fields): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('PayTR bağlantısı başlatılamadı.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('PayTR bağlantı hatası (curl ' . $errno . ').');
        }
        if ($code !== 200) {
            throw new RuntimeException('PayTR HTTP ' . $code . ' döndürdü.');
        }

        return (string) $body;
    }
}