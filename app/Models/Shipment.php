<?php

/**
 * Aşıroğlu Yöresel Market - Gönderim (kargo takibi) modeli
 *
 * shipments tablosu (migration 014). Kargo takibi MANUELDİR: kargo firması,
 * takip numarası ve kargo tarihi yönetici tarafından girilir.
 *
 * Kurallar (DECISIONS.md, migration 014):
 * - shipments.order_id UNIQUE değildir; bir siparişin parçalı gönderimi olabilir.
 * - Takip numarası girilince gönderim 'handed_to_cargo' olur ve shipped_at
 *   doldurulur. 'delivered' olunca delivered_at doldurulur.
 * - Bu model SİPARİŞ DURUMUNU DEĞİŞTİRMEZ ve e-posta göndermez. 'delivered'
 *   sonrası siparişin 'delivered' yapılması ve takip numarası e-postası
 *   (FR-068) üst katmandaki serviste yapılır.
 * - create/update/delete kapalıdır; yazma yalnızca bu sınıfın metotlarıyla
 *   yapılır. Durum geçişleri TRANSITIONS ile sınırlıdır ve satır kilitlenerek
 *   (FOR UPDATE) transaction içinde uygulanır.
 * - Teslim edilmiş veya iade edilmiş gönderimin takip bilgisi değiştirilemez.
 * - İptal edilmiş, iade edilmiş veya ödemesi başarısız siparişe gönderim açılamaz.
 * - Takip URL'si yalnızca http/https olabilir.
 * - Kural hataları kullanıcıya gösterilebilir Türkçe InvalidArgumentException'dır.
 * - Tüm sorgular PDO prepared statement iledir; bu sınıfta $_GET / $_POST kullanılmaz.
 *
 * Gereksinimler: FR-067, FR-068, FR-069, FR-093.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use BadMethodCallException;
use DateTimeImmutable;
use InvalidArgumentException;

final class Shipment extends Model
{
    /** Geçerli gönderim durumları (shipments.status ENUM) */
    public const STATUSES = [
        'preparing',
        'handed_to_cargo',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'failed',
        'returned',
    ];

    /** Kargo firması en fazla uzunluk (shipments.carrier_name VARCHAR(100)) */
    private const MAX_CARRIER_LENGTH = 100;

    /** Takip URL'si en fazla uzunluk (shipments.tracking_url VARCHAR(500)) */
    private const MAX_URL_LENGTH = 500;

    /** Takip numarası biçimi: harf, rakam, - _ . / ; 3-100 karakter */
    private const TRACKING_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\-_.\/]{2,99}$/';

    /** Gönderim açılamayan sipariş durumları */
    private const BLOCKED_ORDER_STATUSES = ['cancelled', 'returned', 'payment_failed'];

    /** Takip bilgisi değiştirilemeyen gönderim durumları */
    private const LOCKED_STATUSES = ['delivered', 'returned'];

    /**
     * İzinli durum geçişleri: mevcut durum => gidilebilecek durumlar.
     * 'returned' son durumdur.
     */
    private const TRANSITIONS = [
        'preparing'        => ['handed_to_cargo', 'failed'],
        'handed_to_cargo'  => ['in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'],
        'in_transit'       => ['out_for_delivery', 'delivered', 'failed', 'returned'],
        'out_for_delivery' => ['delivered', 'failed', 'returned'],
        'failed'           => ['handed_to_cargo', 'in_transit', 'returned'],
        'delivered'        => ['returned'],
        'returned'         => [],
    ];

    protected string $table = 'shipments';

    protected bool $softDelete = false;

    /** @var list<string> */
    protected array $fillable = [
        'order_id',
        'shipping_method_id',
        'carrier_name',
        'tracking_number',
        'tracking_url',
        'status',
        'shipped_at',
        'delivered_at',
    ];

    // -------------------------------------------------------------------------
    // Kapalı genel yazma metotları
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        throw new BadMethodCallException('Gönderim oluşturmak için createForOrder() kullanın.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        throw new BadMethodCallException(
            'Gönderim güncellemek için updateTracking(), markHandedToCargo() veya changeStatus() kullanın.'
        );
    }

    public function delete(int $id): bool
    {
        throw new BadMethodCallException('Gönderim silinemez; durumu değiştirilir.');
    }

    // -------------------------------------------------------------------------
    // Okuma
    // -------------------------------------------------------------------------

    /**
     * Gönderimi, yalnızca verilen siparişe aitse getirir.
     *
     * @return array<string, mixed>|null
     */
    public function findForOrder(int $id, int $orderId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM `shipments` WHERE `id` = ? AND `order_id` = ?',
            [$id, $orderId]
        );
    }

    /**
     * Siparişin tüm gönderimleri (eskiden yeniye). Yönetim paneli içindir.
     *
     * @return list<array<string, mixed>>
     */
    public function getByOrder(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT * FROM `shipments` WHERE `order_id` = ? ORDER BY `id` ASC',
            [$orderId]
        );
    }

    /**
     * Müşteriye gösterilecek gönderim bilgisi (FR-069): yalnızca kargo firması,
     * takip numarası, takip bağlantısı, durum ve tarihler.
     *
     * Sipariş sahipliği çağıranın sorumluluğundadır (Order::findForUser).
     *
     * @return list<array<string, mixed>>
     */
    public function getForCustomer(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT `id`, `carrier_name`, `tracking_number`, `tracking_url`, `status`,'
            . ' `shipped_at`, `delivered_at`'
            . ' FROM `shipments` WHERE `order_id` = ? ORDER BY `id` ASC',
            [$orderId]
        );
    }

    // -------------------------------------------------------------------------
    // Oluşturma
    // -------------------------------------------------------------------------

    /**
     * Siparişe gönderim açar ve ID'sini döndürür.
     *
     * $data anahtarları (hepsi isteğe bağlı):
     *   shipping_method_id, carrier_name, tracking_number, tracking_url, shipped_at
     *
     * Takip numarası verilirse kargo firması da zorunludur; gönderim
     * 'handed_to_cargo' olarak açılır ve shipped_at yazılır. Takip numarası
     * yoksa gönderim 'preparing' olarak açılır.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException Sipariş, yöntem veya girdi kuralı ihlal edilirse
     */
    public function createForOrder(int $orderId, array $data = []): int
    {
        $this->assertOrderCanShip($orderId);

        $methodId = $this->cleanMethodId($data['shipping_method_id'] ?? null);
        $tracking = $this->cleanTracking(
            $data['carrier_name'] ?? null,
            $data['tracking_number'] ?? null,
            $data['tracking_url'] ?? null
        );

        $row = [
            'order_id'           => $orderId,
            'shipping_method_id' => $methodId,
            'carrier_name'       => $tracking['carrier_name'],
            'tracking_number'    => $tracking['tracking_number'],
            'tracking_url'       => $tracking['tracking_url'],
            'status'             => 'preparing',
            'shipped_at'         => null,
        ];

        if ($tracking['tracking_number'] !== null) {
            $row['status']     = 'handed_to_cargo';
            $row['shipped_at'] = $this->parseDate($data['shipped_at'] ?? null);
        }

        return parent::create($row);
    }

    // -------------------------------------------------------------------------
    // Güncelleme
    // -------------------------------------------------------------------------

    /**
     * Hazırlanıyor (veya başarısız) gönderimi kargoya verilmiş yapar: kargo
     * firması ve takip numarası zorunludur, shipped_at yazılır (FR-067).
     *
     * @throws InvalidArgumentException Gönderim bulunamazsa, durumu uygun değilse
     *                                  veya girdi geçersizse
     */
    public function markHandedToCargo(
        int $id,
        int $orderId,
        string $carrierName,
        string $trackingNumber,
        ?string $trackingUrl = null,
        ?string $shippedAt = null
    ): bool {
        $tracking = $this->cleanTracking($carrierName, $trackingNumber, $trackingUrl);

        if ($tracking['tracking_number'] === null) {
            throw new InvalidArgumentException('Takip numarası zorunludur.');
        }

        $shipped = $this->parseDate($shippedAt);

        return (bool) $this->transaction(function () use ($id, $orderId, $tracking, $shipped): bool {
            $shipment = $this->lockForOrder($id, $orderId);

            if (!in_array((string) $shipment['status'], ['preparing', 'failed'], true)) {
                throw new InvalidArgumentException(
                    'Yalnızca hazırlanan veya başarısız gönderim kargoya verilebilir.'
                );
            }

            $this->assertOrderCanShip($orderId);

            parent::update($id, [
                'carrier_name'    => $tracking['carrier_name'],
                'tracking_number' => $tracking['tracking_number'],
                'tracking_url'    => $tracking['tracking_url'],
                'status'          => 'handed_to_cargo',
                'shipped_at'      => $shipped,
            ]);

            return true;
        });
    }

    /**
     * Kargo firması, takip numarası ve takip bağlantısını düzeltir.
     * Teslim edilmiş veya iade edilmiş gönderimde reddedilir. Durum ve
     * shipped_at değişmez.
     *
     * @throws InvalidArgumentException Gönderim bulunamazsa, kilitliyse veya girdi geçersizse
     */
    public function updateTracking(
        int $id,
        int $orderId,
        string $carrierName,
        string $trackingNumber,
        ?string $trackingUrl = null
    ): bool {
        $tracking = $this->cleanTracking($carrierName, $trackingNumber, $trackingUrl);

        if ($tracking['tracking_number'] === null) {
            throw new InvalidArgumentException('Takip numarası zorunludur.');
        }

        return (bool) $this->transaction(function () use ($id, $orderId, $tracking): bool {
            $shipment = $this->lockForOrder($id, $orderId);

            if (in_array((string) $shipment['status'], self::LOCKED_STATUSES, true)) {
                throw new InvalidArgumentException(
                    'Teslim edilmiş veya iade edilmiş gönderimin takip bilgisi değiştirilemez.'
                );
            }

            if ((string) $shipment['status'] === 'preparing') {
                throw new InvalidArgumentException(
                    'Hazırlanan gönderim için önce kargoya verme işlemini yapın.'
                );
            }

            // Değişiklik yoksa MySQL 0 satır etkilenmiş bildirir; bu hata değildir
            parent::update($id, [
                'carrier_name'    => $tracking['carrier_name'],
                'tracking_number' => $tracking['tracking_number'],
                'tracking_url'    => $tracking['tracking_url'],
            ]);

            return true;
        });
    }

    /**
     * Gönderim durumunu TRANSITIONS kurallarına göre değiştirir.
     *
     * - 'handed_to_cargo' için takip numarası dolu olmalıdır; shipped_at boşsa yazılır.
     * - 'delivered' olunca delivered_at yazılır.
     *
     * Sipariş durumunu güncellemek çağıranın (servis) işidir.
     *
     * @throws InvalidArgumentException Durum geçersizse, geçiş izinli değilse
     *                                  veya gönderim bulunamazsa
     */
    public function changeStatus(int $id, int $orderId, string $newStatus): bool
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Geçersiz gönderim durumu.');
        }

        return (bool) $this->transaction(function () use ($id, $orderId, $newStatus): bool {
            $shipment = $this->lockForOrder($id, $orderId);
            $current  = (string) $shipment['status'];

            if (!in_array($newStatus, self::TRANSITIONS[$current] ?? [], true)) {
                throw new InvalidArgumentException('Gönderim durumu bu şekilde değiştirilemez.');
            }

            $now  = date('Y-m-d H:i:s');
            $data = ['status' => $newStatus];

            if ($newStatus === 'handed_to_cargo') {
                if (trim((string) ($shipment['tracking_number'] ?? '')) === '') {
                    throw new InvalidArgumentException(
                        'Kargoya verilmeden önce takip numarası girilmelidir.'
                    );
                }

                if ($shipment['shipped_at'] === null) {
                    $data['shipped_at'] = $now;
                }
            }

            if ($newStatus === 'delivered') {
                $data['delivered_at'] = $now;

                if ($shipment['shipped_at'] === null) {
                    $data['shipped_at'] = $now;
                }
            }

            parent::update($id, $data);

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Gönderim satırını sipariş eşleşmesiyle okuyup kilitler.
     * Yalnızca transaction içinde çağrılmalıdır.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException Gönderim bu siparişe ait değilse veya yoksa
     */
    private function lockForOrder(int $id, int $orderId): array
    {
        $row = $this->fetchOne(
            'SELECT * FROM `shipments` WHERE `id` = ? AND `order_id` = ? FOR UPDATE',
            [$id, $orderId]
        );

        if ($row === null) {
            throw new InvalidArgumentException('Gönderim bulunamadı.');
        }

        return $row;
    }

    /**
     * Sipariş var, silinmemiş ve gönderim açılabilir durumda olmalı.
     *
     * @throws InvalidArgumentException Aksi halde
     */
    private function assertOrderCanShip(int $orderId): void
    {
        $status = $this->fetchValue(
            'SELECT `status` FROM `orders` WHERE `id` = ? AND `deleted_at` IS NULL',
            [$orderId]
        );

        if ($status === null) {
            throw new InvalidArgumentException('Sipariş bulunamadı.');
        }

        if (in_array((string) $status, self::BLOCKED_ORDER_STATUSES, true)) {
            throw new InvalidArgumentException(
                'İptal edilmiş, iade edilmiş veya ödemesi başarısız siparişe gönderim açılamaz.'
            );
        }
    }

    /**
     * Kargo yöntemi kimliğini doğrular; boşsa null döner.
     *
     * @throws InvalidArgumentException Yöntem yoksa
     */
    private function cleanMethodId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (!is_scalar($value) || preg_match('/^[1-9]\d{0,9}$/', (string) $value) !== 1) {
            throw new InvalidArgumentException('Kargo yöntemi geçersiz.');
        }

        $id    = (int) $value;
        $count = (int) $this->fetchValue('SELECT COUNT(*) FROM `shipping_methods` WHERE `id` = ?', [$id]);

        if ($count === 0) {
            throw new InvalidArgumentException('Seçilen kargo yöntemi bulunamadı.');
        }

        return $id;
    }

    /**
     * Kargo firması, takip numarası ve takip bağlantısını doğrular.
     * Takip numarası varsa kargo firması zorunludur; hepsi boşsa null döner.
     *
     * @return array{carrier_name: string|null, tracking_number: string|null, tracking_url: string|null}
     *
     * @throws InvalidArgumentException Girdi geçersizse
     */
    private function cleanTracking(mixed $carrier, mixed $number, mixed $url): array
    {
        $carrier = $this->cleanText($carrier);
        $number  = $this->cleanText($number);
        $url     = $this->cleanText($url);

        if ($carrier !== null && mb_strlen($carrier) > self::MAX_CARRIER_LENGTH) {
            throw new InvalidArgumentException(
                'Kargo firması en fazla ' . self::MAX_CARRIER_LENGTH . ' karakter olabilir.'
            );
        }

        if ($number !== null && preg_match(self::TRACKING_PATTERN, $number) !== 1) {
            throw new InvalidArgumentException(
                'Takip numarası 3-100 karakter olmalı; yalnızca harf, rakam, tire, alt çizgi, nokta ve eğik çizgi içerebilir.'
            );
        }

        if ($number !== null && $carrier === null) {
            throw new InvalidArgumentException('Takip numarası girildiğinde kargo firması zorunludur.');
        }

        if ($url !== null && !$this->isHttpUrl($url)) {
            throw new InvalidArgumentException(
                'Takip bağlantısı geçerli bir web adresi (http veya https) olmalıdır.'
            );
        }

        return [
            'carrier_name'    => $carrier,
            'tracking_number' => $number,
            'tracking_url'    => $url,
        ];
    }

    /** Skaler değeri kırpar; metin değilse veya boşsa null döner. */
    private function cleanText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** Yalnızca http/https adreslerini kabul eder (javascript: vb. reddedilir). */
    private function isHttpUrl(string $value): bool
    {
        if (strlen($value) > self::MAX_URL_LENGTH || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Kargo tarihini DATETIME metnine çevirir. Boşsa şimdiki zaman kullanılır.
     * "Y-m-d" gün başı (00:00:00) olur; "Y-m-d H:i:s" olduğu gibi kabul edilir.
     *
     * @throws InvalidArgumentException Tarih geçersizse
     */
    private function parseDate(mixed $value): string
    {
        $text = $this->cleanText($value);

        if ($text === null) {
            return date('Y-m-d H:i:s');
        }

        foreach (['Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $text);

            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        throw new InvalidArgumentException('Kargo tarihi geçerli bir tarih olmalıdır (YYYY-AA-GG).');
    }
}