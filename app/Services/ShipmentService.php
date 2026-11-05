<?php

/**
 * Aşıroğlu Yöresel Market - Kargo / gönderim servisi
 *
 * Shipment (gönderim) ve Order (sipariş) modellerini tek transaction'da
 * koordine eder. Shipment modeli sipariş durumunu DEĞİŞTİRMEZ (model notu);
 * bu servis gönderim değişikliğinden sonra sipariş durumunu ve geçmişini
 * günceller. Kargo takibi manueldir: firma, takip numarası ve kargo tarihi
 * yönetici tarafından girilir (FR-067).
 *
 * Kurallar (DECISIONS.md, Ödeme / Kargo; migration 014):
 * - Gönderim değişikliği + sipariş durumu + order_status_history tek
 *   transaction'dadır; biri başarısız olursa hiçbiri yazılmaz. Model
 *   metotlarının kendi transaction'ları bu transaction'a katılır.
 * - Gönderim açılabilen sipariş durumları: pending (kapıda ödeme), paid,
 *   preparing; parçalı gönderim için shipped. Ödemesi alınmamış
 *   (awaiting_payment, payment_failed), iptal veya iade edilmiş siparişe
 *   kargo girilemez.
 * - Bir gönderim 'handed_to_cargo' olunca sipariş 'shipped' olur
 *   (pending/paid ise önce 'preparing', Order::TRANSITIONS'a uygun).
 * - Sipariş 'delivered' olur ancak TÜM gönderimleri 'delivered' ise.
 * - 'failed' ve 'returned' gönderimler sipariş durumunu değiştirmez;
 *   iade akışı iade modülünün işidir.
 * - Sipariş sahipliği bu serviste doğrulanmaz: müşteri okuması için çağıran
 *   önce Order::findForUser() kullanmalıdır (G-05).
 * - Kural ihlalleri kullanıcıya gösterilebilir Türkçe InvalidArgumentException
 *   olarak yükselir (modellerle aynı sözleşme); çağıran yakalar. Beklenmeyen
 *   (DB vb.) hatalar yeniden fırlatılır, çağıran loglar.
 * - Müşteriye kargo e-postası (FR-068) bu serviste YOKTUR; e-posta altyapısı
 *   gelince başarılı create()/markHandedToCargo()/changeStatus() sonrasında
 *   çağıran katmandan tetiklenecektir.
 *
 * Gereksinimler: FR-066, FR-067, FR-069, G-05.
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use InvalidArgumentException;

final class ShipmentService
{
    /** Gönderim açılabilen sipariş durumları ('shipped': parçalı gönderim) */
    private const DISPATCHABLE_ORDER_STATUSES = ['pending', 'paid', 'preparing', 'shipped'];

    private Shipment $shipments;

    private Order $orders;

    public function __construct(?Shipment $shipments = null, ?Order $orders = null)
    {
        $this->shipments = $shipments ?? new Shipment();
        $this->orders    = $orders ?? new Order();
    }

    // -------------------------------------------------------------------------
    // Okuma
    // -------------------------------------------------------------------------

    /**
     * Yönetim paneli: siparişin tüm gönderimleri (eskiden yeniye).
     *
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(int $orderId): array
    {
        return $this->shipments->getByOrder($orderId);
    }

    /**
     * Müşteri: yalnızca gösterilebilir alanlar (FR-069). Sipariş sahipliği
     * çağıran tarafından Order::findForUser() ile doğrulanmış olmalıdır (G-05).
     *
     * @return list<array<string, mixed>>
     */
    public function listForCustomer(int $orderId): array
    {
        return $this->shipments->getForCustomer($orderId);
    }

    // -------------------------------------------------------------------------
    // Yazma
    // -------------------------------------------------------------------------

    /**
     * Siparişe gönderim açar ve ID'sini döndürür.
     *
     * $data: shipping_method_id, carrier_name, tracking_number, tracking_url,
     * shipped_at (hepsi isteğe bağlı). Takip numarası verilirse gönderim
     * doğrudan 'handed_to_cargo' olur ve sipariş 'shipped' yapılır; verilmezse
     * gönderim 'preparing' açılır ve sipariş durumu değişmez.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException Sipariş/girdi kuralı ihlal edilirse
     */
    public function create(int $orderId, array $data, ?int $adminId = null): int
    {
        return (int) $this->orders->transaction(function () use ($orderId, $data, $adminId): int {
            $this->assertDispatchable($orderId);

            $shipmentId = $this->shipments->createForOrder($orderId, $data);
            $shipment   = $this->shipments->findForOrder($shipmentId, $orderId);

            if ($shipment !== null && (string) $shipment['status'] === 'handed_to_cargo') {
                $this->markOrderShipped($orderId, $shipment, $adminId);
            }

            return $shipmentId;
        });
    }

    /**
     * Hazırlanan (veya başarısız) gönderimi kargoya verir ve siparişi 'shipped' yapar.
     * carrier_name ve tracking_number zorunludur.
     *
     * @param array<string, mixed> $data carrier_name, tracking_number, tracking_url, shipped_at
     *
     * @throws InvalidArgumentException Gönderim/sipariş/girdi kuralı ihlal edilirse
     */
    public function markHandedToCargo(int $shipmentId, int $orderId, array $data, ?int $adminId = null): void
    {
        $this->orders->transaction(function () use ($shipmentId, $orderId, $data, $adminId): void {
            $this->assertDispatchable($orderId);

            $this->shipments->markHandedToCargo(
                $shipmentId,
                $orderId,
                (string) ($data['carrier_name'] ?? ''),
                (string) ($data['tracking_number'] ?? ''),
                $this->nullableText($data['tracking_url'] ?? null),
                $this->nullableText($data['shipped_at'] ?? null)
            );

            $shipment = $this->shipments->findForOrder($shipmentId, $orderId);

            if ($shipment !== null) {
                $this->markOrderShipped($orderId, $shipment, $adminId);
            }
        });
    }

    /**
     * Firma / takip numarası / takip adresini düzeltir. Durum ve sipariş
     * durumu değişmez; teslim edilmiş veya iade edilmiş gönderimde reddedilir.
     *
     * @param array<string, mixed> $data carrier_name, tracking_number, tracking_url
     *
     * @throws InvalidArgumentException Gönderim bulunamazsa, kilitliyse veya girdi geçersizse
     */
    public function updateTracking(int $shipmentId, int $orderId, array $data): void
    {
        $this->shipments->updateTracking(
            $shipmentId,
            $orderId,
            (string) ($data['carrier_name'] ?? ''),
            (string) ($data['tracking_number'] ?? ''),
            $this->nullableText($data['tracking_url'] ?? null)
        );
    }

    /**
     * Gönderim durumunu değiştirir (Shipment::TRANSITIONS kuralları) ve siparişi
     * senkronlar: 'handed_to_cargo' => sipariş 'shipped'; 'delivered' => tüm
     * gönderimler teslim edildiyse sipariş 'delivered'.
     *
     * @throws InvalidArgumentException Geçiş/sipariş kuralı ihlal edilirse
     */
    public function changeStatus(int $shipmentId, int $orderId, string $newStatus, ?int $adminId = null): void
    {
        $this->orders->transaction(function () use ($shipmentId, $orderId, $newStatus, $adminId): void {
            if ($newStatus === 'handed_to_cargo') {
                $this->assertDispatchable($orderId);
            }

            $this->shipments->changeStatus($shipmentId, $orderId, $newStatus);

            if ($newStatus === 'handed_to_cargo') {
                $shipment = $this->shipments->findForOrder($shipmentId, $orderId);

                if ($shipment !== null) {
                    $this->markOrderShipped($orderId, $shipment, $adminId);
                }

                return;
            }

            if ($newStatus === 'delivered') {
                $this->markOrderDeliveredIfComplete($orderId, $adminId);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Sipariş var ve gönderim açılabilir durumda olmalı.
     *
     * @throws InvalidArgumentException Aksi halde
     */
    private function assertDispatchable(int $orderId): void
    {
        $order = $this->orders->find($orderId);

        if ($order === null) {
            throw new InvalidArgumentException('Sipariş bulunamadı.');
        }

        if (!in_array((string) $order['status'], self::DISPATCHABLE_ORDER_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Bu sipariş durumunda kargo bilgisi girilemez. '
                . 'Siparişin ödemesi alınmış ve iptal/iade edilmemiş olmalıdır.'
            );
        }
    }

    /**
     * Siparişi 'shipped' yapar. pending/paid ise önce 'preparing' (Order::TRANSITIONS).
     * Zaten 'shipped' ise (parçalı gönderim) değiştirmez; geçmişe yalnızca
     * ilk kargoya verilişte kayıt düşülür.
     *
     * @param array<string, mixed> $shipment Kargoya verilmiş gönderim satırı
     */
    private function markOrderShipped(int $orderId, array $shipment, ?int $adminId): void
    {
        $order  = $this->orders->find($orderId);
        $status = (string) ($order['status'] ?? '');

        if ($status === 'shipped') {
            return;
        }

        if (in_array($status, ['pending', 'paid'], true)) {
            $this->orders->changeStatus($orderId, 'preparing', $adminId);
        }

        $carrier  = trim((string) ($shipment['carrier_name'] ?? ''));
        $tracking = trim((string) ($shipment['tracking_number'] ?? ''));
        $note     = 'Kargoya verildi: ' . $carrier . ' / ' . $tracking;

        $this->orders->changeStatus($orderId, 'shipped', $adminId, $note);
    }

    /**
     * Tüm gönderimler 'delivered' ise ve sipariş 'shipped' ise siparişi 'delivered' yapar.
     */
    private function markOrderDeliveredIfComplete(int $orderId, ?int $adminId): void
    {
        $order = $this->orders->find($orderId);

        if ($order === null || (string) $order['status'] !== 'shipped') {
            return;
        }

        $shipments = $this->shipments->getByOrder($orderId);

        if ($shipments === []) {
            return;
        }

        foreach ($shipments as $shipment) {
            if ((string) $shipment['status'] !== 'delivered') {
                return;
            }
        }

        $this->orders->changeStatus($orderId, 'delivered', $adminId, 'Siparişiniz teslim edildi.');
    }

    /** Boş metni null yapar; skalar olmayan değer null döner. */
    private function nullableText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
