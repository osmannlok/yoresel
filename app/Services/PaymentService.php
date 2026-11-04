<?php

/**
 * Aşıroğlu Yöresel Market - Ödeme servisi
 *
 * Ödeme başlatma ve sağlayıcı callback'ini işleme akışını tek yerde toplar.
 * Yalnızca PaymentGatewayInterface'e bağımlıdır (PayTR, iyzico, sandbox).
 *
 * Kurallar (DECISIONS.md, Ödeme / Kargo; migration 013):
 * - Kart verisi bu uygulamaya ulaşmaz; kart sağlayıcının sayfasında girilir.
 * - Ödeme tutarı siparişin grand_total değerinden alınır (Payment::createPending),
 *   çağıranın verdiği değere güvenilmez.
 * - Callback'te imza doğrulaması zorunludur. İmzası geçersiz callback ile
 *   ödeme veya sipariş durumu ASLA değişmez; olay 'callback_invalid_signature'
 *   olarak loglanır.
 * - Tekrarlanan callback idempotenttir: sonuçlanmış ödeme değişmez.
 * - Başarılı ödemede payment completed + order paid, başarısızda payment failed
 *   + order payment_failed (Payment modeli, tek transaction).
 * - payment_logs ödeme transaction'ının DIŞINDA yazılır; log hatası akışı bozmaz.
 * - Kural ihlalleri (tutar uyuşmazlığı, iptal edilmiş siparişe ödeme) callback'i
 *   düşürmez: loglanır ve sağlayıcıya onay yanıtı verilir; manuel inceleme gerekir.
 *   Beklenmeyen (DB vb.) hatalar yeniden fırlatılır; sağlayıcı callback'i tekrar dener.
 * - Sipariş sahipliği: üye siparişi yalnızca sahibine, misafir siparişi
 *   (user_id NULL) yalnızca misafir oturumuna açıktır (CheckoutController::complete ile aynı kural).
 *
 * Gereksinimler: FR-071, FR-075, FR-076, FR-077, NFR-009, G-06.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\PaytrGateway;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class PaymentService
{
    /** Kart ödemesi başlatılabilen sipariş durumları */
    private const PAYABLE_STATUSES = ['awaiting_payment', 'payment_failed'];

    /** Aynı sipariş için en fazla ödeme denemesi */
    private const MAX_ATTEMPTS = 5;

    /** Kullanıcıya gösterilen genel hata (teknik ayrıntı loga yazılır) */
    private const GENERIC_ERROR = 'Ödeme başlatılamadı. Lütfen daha sonra tekrar deneyin.';

    private PaymentGatewayInterface $gateway;

    private Payment $payments;

    private PaymentLog $logs;

    private Order $orders;

    public function __construct(
        ?PaymentGatewayInterface $gateway = null,
        ?Payment $payments = null,
        ?PaymentLog $logs = null,
        ?Order $orders = null
    ) {
        $this->gateway  = $gateway ?? self::fromConfig();
        $this->payments = $payments ?? new Payment();
        $this->logs     = $logs ?? new PaymentLog();
        $this->orders   = $orders ?? new Order();
    }

    // -------------------------------------------------------------------------
    // Başlatma
    // -------------------------------------------------------------------------

    /**
     * Kart ödemesini başlatır ve müşterinin göreceği ödeme sayfası bilgisini döndürür.
     *
     * @param int         $orderId     Sipariş kimliği
     * @param int|null    $ownerUserId Oturumdaki kullanıcı (Auth::id()); misafirde null
     * @param string      $ip          İstemci IP (Request::ip())
     *
     * @return array{payment_id: int, type: string, url: string|null, html: string|null}
     *
     * @throws InvalidArgumentException Sipariş bulunamaz, sahibi değil veya ödemeye uygun değilse
     *                                  (mesaj kullanıcıya gösterilebilir)
     * @throws RuntimeException         Sağlayıcı hatasında (mesaj kullanıcıya gösterilebilir genel metin)
     */
    public function startCardPayment(int $orderId, ?int $ownerUserId, string $ip): array
    {
        $order = $this->orders->find($orderId);

        // Başkasının siparişi ile olmayan sipariş aynı yanıtı verir (G-05)
        if ($order === null || ($order['user_id'] === null ? null : (int) $order['user_id']) !== $ownerUserId) {
            throw new InvalidArgumentException('Sipariş bulunamadı.');
        }

        $status = (string) $order['status'];

        if (!in_array($status, self::PAYABLE_STATUSES, true)) {
            throw new InvalidArgumentException('Bu sipariş için ödeme yapılamaz.');
        }

        $attempt = count($this->payments->getByOrder($orderId)) + 1;

        if ($attempt > self::MAX_ATTEMPTS) {
            throw new InvalidArgumentException('Ödeme deneme sınırına ulaşıldı. Lütfen bizimle iletişime geçin.');
        }

        // Başarısız denemeden sonra yeniden deneme: sipariş tekrar ödeme bekler
        if ($status === 'payment_failed') {
            $this->orders->changeStatus($orderId, 'awaiting_payment', null, 'Ödeme yeniden deneniyor.');
        }

        $paymentId = $this->payments->createPending(
            $orderId,
            'credit_card',
            $this->gateway->providerName()
        );

        $payment = $this->payments->findLatestByOrder($orderId);
        $amount  = number_format((float) ($payment['amount'] ?? $order['grand_total']), 2, '.', '');

        try {
            $result = $this->gateway->initiate([
                'order_number'      => (string) $order['order_number'],
                'amount'            => $amount,
                'currency'          => (string) $order['currency'],
                'installment_count' => 1,
                'attempt'           => $attempt,
                'customer'          => $this->customerData($order, $ip),
                'items'             => $this->basketItems($order),
                'urls'              => $this->returnUrls(),
            ]);
        } catch (Throwable $e) {
            // Ayrıntı yalnızca loga; kullanıcıya genel mesaj
            error_log(sprintf(
                '[%s] PaymentService: ödeme başlatılamadı (sipariş %d): %s',
                date('Y-m-d H:i:s'),
                $orderId,
                $e->getMessage()
            ));

            $this->payments->markCancelled($paymentId);
            $this->logs->record(
                'init_error',
                $paymentId,
                $orderId,
                $this->gateway->providerName(),
                null,
                ['error' => 'başlatma hatası'],
                $ip
            );

            throw new RuntimeException(self::GENERIC_ERROR);
        }

        $logData = is_array($result['log_data'] ?? null) ? $result['log_data'] : [];

        $this->logs->record(
            'init',
            $paymentId,
            $orderId,
            $this->gateway->providerName(),
            is_array($logData['request'] ?? null) ? $logData['request'] : null,
            is_array($logData['response'] ?? null) ? $logData['response'] : null,
            $ip
        );

        return [
            'payment_id' => $paymentId,
            'type'       => (string) $result['type'],
            'url'        => $result['url'] ?? null,
            'html'       => $result['html'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Callback
    // -------------------------------------------------------------------------

    /**
     * Sağlayıcı callback'ini işler.
     *
     * @param array<string, mixed>  $payload Callback gövdesi (Request::all())
     * @param array<string, string> $headers Küçük harfli başlıklar
     *
     * @return array{valid: bool, body: string} valid=false ise controller 400 döndürmeli
     *         (body boş); valid=true ise body sağlayıcının beklediği yanıttır (örn. "OK").
     *
     * @throws Throwable Beklenmeyen (DB vb.) hatalarda; sağlayıcı callback'i tekrar dener
     */
    public function handleCallback(array $payload, array $headers, string $ip): array
    {
        $provider = $this->gateway->providerName();

        if (!$this->gateway->verifyCallback($payload, $headers)) {
            // İmzası geçersiz callback hiçbir durumu değiştirmez
            $this->logs->record(
                'callback_invalid_signature',
                null,
                null,
                $provider,
                $payload,
                null,
                $ip
            );

            return ['valid' => false, 'body' => ''];
        }

        $ack = $this->gateway->callbackAcknowledgement();

        try {
            $parsed = $this->gateway->parseCallback($payload, $headers);
        } catch (UnexpectedValueException $e) {
            // İmza geçerli ama biçim tanınmadı: sağlayıcı tekrar denemesin, loglanır
            $this->logs->record('callback_unparsable', null, null, $provider, $payload, null, $ip);
            error_log(sprintf('[%s] PaymentService: callback çözümlenemedi: %s', date('Y-m-d H:i:s'), $e->getMessage()));

            return ['valid' => true, 'body' => $ack];
        }

        $order = $this->orders->findByNumber((string) $parsed['order_number']);

        if ($order === null) {
            $this->logs->record('callback_unknown_order', null, null, $provider, $parsed['log_data'], null, $ip);

            return ['valid' => true, 'body' => $ack];
        }

        $orderId = (int) $order['id'];
        $payment = $this->payments->findLatestByOrder($orderId);

        if ($payment === null) {
            $this->logs->record('callback_no_payment', null, $orderId, $provider, $parsed['log_data'], null, $ip);

            return ['valid' => true, 'body' => $ack];
        }

        $paymentId = (int) $payment['id'];
        $changed   = false;
        $event     = 'callback';

        try {
            if ($parsed['status'] === PaymentGatewayInterface::STATUS_COMPLETED) {
                $changed = $this->payments->markCompleted(
                    $paymentId,
                    $parsed['provider_transaction_id'],
                    $parsed['provider_reference'],
                    $parsed['card_mask'],
                    (float) $parsed['amount']
                );
            } else {
                $changed = $this->payments->markFailed($paymentId, $parsed['error_message']);
            }

            if (!$changed) {
                $event = 'callback_duplicate';
            }
        } catch (InvalidArgumentException $e) {
            // Tutar uyuşmazlığı veya geçersiz sipariş geçişi: işlem geri alındı, manuel inceleme gerekir
            $event = 'callback_rejected';

            error_log(sprintf(
                '[%s] PaymentService: callback reddedildi (sipariş %d, manuel inceleme): %s',
                date('Y-m-d H:i:s'),
                $orderId,
                $e->getMessage()
            ));
        }

        $this->logs->record(
            $event,
            $paymentId,
            $orderId,
            $provider,
            $parsed['log_data'],
            ['status' => $parsed['status'], 'changed' => $changed],
            $ip
        );

        return ['valid' => true, 'body' => $ack];
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Yapılandırmadan varsayılan sağlayıcıyı kurar. Anahtarlar
     * config/payment.php (.env kaynaklı) içinde tanımlıdır.
     *
     * @throws RuntimeException Sağlayıcı tanımsız veya desteklenmiyorsa
     */
    private static function fromConfig(): PaymentGatewayInterface
    {
        $name = (string) App::config('payment.default', 'paytr');

        return match ($name) {
            'paytr' => new PaytrGateway((array) App::config('payment.paytr', [])),
            default => throw new RuntimeException('Desteklenmeyen ödeme sağlayıcısı.'),
        };
    }

    /**
     * Gateway için müşteri bilgisi (kaynak: sipariş ve adres anlık kopyası).
     *
     * @param array<string, mixed> $order
     *
     * @return array{email: string, name: string, phone: string, address: string, ip: string}
     */
    private function customerData(array $order, string $ip): array
    {
        $snapshot = json_decode((string) ($order['shipping_address_snapshot'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $text = static fn (string $key): string => isset($snapshot[$key]) && is_scalar($snapshot[$key])
            ? trim((string) $snapshot[$key])
            : '';

        $name    = trim($text('first_name') . ' ' . $text('last_name'));
        $address = trim(implode(' ', array_filter(
            [$text('address_line'), $text('neighborhood'), $text('district'), $text('city')],
            static fn (string $part): bool => $part !== ''
        )));

        return [
            'email'   => (string) $order['customer_email'],
            'name'    => $name,
            'phone'   => (string) ($order['customer_phone'] ?? $text('phone')),
            'address' => $address,
            'ip'      => $ip,
        ];
    }

    /**
     * Sepet kalemleri (kaynak: order_items). Kargo ücreti ayrı kalem olarak
     * eklenir; indirim henüz yoktur (kupon bağlanınca gözden geçirilecek).
     *
     * @param array<string, mixed> $order
     *
     * @return list<array{name: string, unit_price: string, quantity: int}>
     */
    private function basketItems(array $order): array
    {
        $items = [];

        foreach ($this->orders->getItems((int) $order['id']) as $row) {
            $items[] = [
                'name'       => (string) $row['product_name'],
                'unit_price' => number_format((float) $row['unit_price'], 2, '.', ''),
                'quantity'   => max(1, (int) $row['quantity']),
            ];
        }

        $shipping = (float) ($order['shipping_total'] ?? 0);

        if ($shipping > 0) {
            $items[] = [
                'name'       => 'Kargo',
                'unit_price' => number_format($shipping, 2, '.', ''),
                'quantity'   => 1,
            ];
        }

        return $items;
    }

    /**
     * Ödeme sonrası dönüş adresleri (mutlak URL gerekir).
     *
     * @return array{ok_url: string, fail_url: string}
     */
    private function returnUrls(): array
    {
        $base = rtrim((string) App::config('app.url', ''), '/');
        $ok   = (string) App::config('payment.ok_url', $base . '/siparis/tamamlandi');
        $fail = (string) App::config('payment.fail_url', $base . '/siparis/tamamlandi');

        return ['ok_url' => $ok, 'fail_url' => $fail];
    }
}