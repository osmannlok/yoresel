<?php

/**
 * Aşıroğlu Yöresel Market - Yönetim paneli sipariş gönderimi (kargo takibi) controller'ı
 *
 * POST /admin/siparisler/{orderId}/gonderimler                         -> gönderim aç
 * POST /admin/siparisler/{orderId}/gonderimler/{id}/kargoya-ver        -> kargoya ver (firma + takip no)
 * POST /admin/siparisler/{orderId}/gonderimler/{id}/takip              -> takip bilgisini düzelt
 * POST /admin/siparisler/{orderId}/gonderimler/{id}/durum              -> gönderim durumunu değiştir
 *
 * (Kargo YÖNTEMİ tanımları ayrıdır: /admin/kargo, Admin\ShippingMethodController.)
 *
 * Kararlar:
 * - Erişim denetimi route grubundaki AdminMiddleware ile yapılır; burada ikinci
 *   savunma katmanı olarak yetki yeniden kontrol edilir.
 * - POST isteklerinin CSRF doğrulaması App::handle() içinde yapılır (form
 *   alanı "_token" veya Fetch başlığı "X-CSRF-TOKEN"); burada tekrarlanmaz.
 * - Sipariş kimliği yoldan gelir; gönderim, ShipmentService/Shipment modeli
 *   tarafından o siparişe ait olup olmadığı doğrulanarak işlenir. Formdan
 *   sipariş kimliği alınmaz.
 * - İş kuralları ShipmentService'tedir (gönderim + sipariş durumu + geçmiş tek
 *   transaction). Kural ihlalleri kullanıcıya gösterilebilir Türkçe
 *   InvalidArgumentException'dır; beklenmeyen hatalar loglanır, kullanıcıya
 *   genel mesaj gösterilir.
 * - Girdiler Validator'dan geçer. Kargo tarihi YYYY-AA-GG biçimindedir
 *   (Shipment modeli yalnızca bu biçimi ve YYYY-AA-GG SS:DD:ss'i kabul eder).
 * - Yanıt: istek JSON bekliyorsa (Fetch) JSON, değilse flash mesaj + yönlendirme.
 * - Müşteriye kargo e-postası (FR-068) yoktur; e-posta altyapısı gelince
 *   başarılı işlemden sonra buradan tetiklenecektir.
 *
 * Gereksinimler: FR-066, FR-067, FR-090, NFR-003, NFR-004.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Middleware\AdminMiddleware;
use App\Models\Shipment;
use App\Services\ShipmentService;
use InvalidArgumentException;
use Throwable;

final class ShipmentController extends Controller
{
    /** Sipariş detayının yolu: /admin/siparisler/{id} */
    private const ORDERS_PATH = '/admin/siparisler';

    /** Kullanıcıya gösterilen genel hata (teknik ayrıntı loga yazılır) */
    private const GENERIC_ERROR = 'İşlem şu anda tamamlanamadı. Lütfen tekrar deneyin.';

    private ShipmentService $service;

    public function __construct(?ShipmentService $service = null)
    {
        $this->service = $service ?? new ShipmentService();
    }

    // -------------------------------------------------------------------------
    // Eylemler
    // -------------------------------------------------------------------------

    /**
     * Siparişe gönderim açar: POST /admin/siparisler/{orderId}/gonderimler
     * Takip numarası verilirse kargoya verilmiş açılır ve sipariş 'shipped' olur.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params = []): Response
    {
        $orderId = (int) ($params['orderId'] ?? 0);

        return $this->handle(
            $request,
            $orderId,
            [
                'shipping_method_id' => 'integer',
                'carrier_name'       => 'string|max:100',
                'tracking_number'    => 'string|max:100',
                'tracking_url'       => 'url|max:500',
                'shipped_at'         => 'date',
            ],
            fn (array $data): string => $this->createShipment($orderId, $data),
        );
    }

    /**
     * Hazırlanan gönderimi kargoya verir:
     * POST /admin/siparisler/{orderId}/gonderimler/{id}/kargoya-ver
     *
     * @param array<string, string> $params
     */
    public function markHandedToCargo(Request $request, array $params = []): Response
    {
        $orderId    = (int) ($params['orderId'] ?? 0);
        $shipmentId = (int) ($params['id'] ?? 0);

        return $this->handle(
            $request,
            $orderId,
            $this->trackingRules() + ['shipped_at' => 'date'],
            function (array $data) use ($shipmentId, $orderId): string {
                $this->service->markHandedToCargo($shipmentId, $orderId, $data, Auth::id());

                return 'Gönderim kargoya verildi olarak kaydedildi.';
            },
        );
    }

    /**
     * Takip bilgisini düzeltir:
     * POST /admin/siparisler/{orderId}/gonderimler/{id}/takip
     *
     * @param array<string, string> $params
     */
    public function updateTracking(Request $request, array $params = []): Response
    {
        $orderId    = (int) ($params['orderId'] ?? 0);
        $shipmentId = (int) ($params['id'] ?? 0);

        return $this->handle(
            $request,
            $orderId,
            $this->trackingRules(),
            function (array $data) use ($shipmentId, $orderId): string {
                $this->service->updateTracking($shipmentId, $orderId, $data);

                return 'Kargo bilgisi güncellendi.';
            },
        );
    }

    /**
     * Gönderim durumunu değiştirir:
     * POST /admin/siparisler/{orderId}/gonderimler/{id}/durum
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params = []): Response
    {
        $orderId    = (int) ($params['orderId'] ?? 0);
        $shipmentId = (int) ($params['id'] ?? 0);

        return $this->handle(
            $request,
            $orderId,
            ['status' => 'required|in:' . implode(',', Shipment::STATUSES)],
            function (array $data) use ($shipmentId, $orderId): string {
                $this->service->changeStatus($shipmentId, $orderId, (string) $data['status'], Auth::id());

                return 'Gönderim durumu güncellendi.';
            },
        );
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Ortak akış: yetki, sipariş kimliği, doğrulama, eylem, yanıt.
     *
     * @param array<string, string>               $rules  Validator kuralları
     * @param callable(array<string, mixed>): string $action Başarı mesajı döndürür
     */
    private function handle(Request $request, int $orderId, array $rules, callable $action): Response
    {
        // İkinci savunma katmanı (AdminMiddleware zaten uygular)
        if (!Auth::canAccessAdmin()) {
            return $request->expectsJson()
                ? $this->json(['success' => false, 'message' => 'Bu işlem için yetkiniz yok.'], 403)
                : $this->redirect(AdminMiddleware::LOGIN_PATH);
        }

        if ($orderId <= 0) {
            return $this->respond($request, 0, false, 'Sipariş bulunamadı.', [], 404);
        }

        $validator = Validator::make($request->only(array_keys($rules)), $rules, self::labels());

        if ($validator->fails()) {
            return $this->respond(
                $request,
                $orderId,
                false,
                (string) $validator->firstError(),
                $validator->errors(),
                422
            );
        }

        try {
            $message = $action($validator->validated());
        } catch (InvalidArgumentException $e) {
            // Kural ihlali: model/servis mesajı kullanıcıya gösterilebilir
            return $this->respond($request, $orderId, false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[%s] Admin\ShipmentController: sipariş %d: %s',
                date('Y-m-d H:i:s'),
                $orderId,
                $e->getMessage()
            ));

            return $this->respond($request, $orderId, false, self::GENERIC_ERROR, [], 500);
        }

        return $this->respond($request, $orderId, true, $message, [], 200);
    }

    /**
     * Gönderim açar; mesajı döndürür.
     *
     * @param array<string, mixed> $data
     */
    private function createShipment(int $orderId, array $data): string
    {
        $this->service->create($orderId, $data, Auth::id());

        $dispatched = trim((string) ($data['tracking_number'] ?? '')) !== '';

        return $dispatched
            ? 'Gönderim kargoya verildi olarak kaydedildi.'
            : 'Gönderim hazırlanıyor olarak açıldı.';
    }

    /**
     * Takip bilgisi alanlarının kuralları (firma ve takip numarası zorunlu).
     *
     * @return array<string, string>
     */
    private function trackingRules(): array
    {
        return [
            'carrier_name'    => 'required|string|max:100',
            'tracking_number' => 'required|string|max:100',
            'tracking_url'    => 'url|max:500',
        ];
    }

    /**
     * JSON bekleyen istekte JSON, aksi halde flash mesaj + sipariş detayına yönlendirme.
     *
     * @param array<string, string> $errors
     */
    private function respond(
        Request $request,
        int $orderId,
        bool $success,
        string $message,
        array $errors,
        int $status
    ): Response {
        if ($request->expectsJson()) {
            return $this->json(['success' => $success, 'message' => $message, 'errors' => $errors], $status);
        }

        Session::flash($success ? 'success' : 'error', $message);

        return $this->redirect($orderId > 0 ? self::ORDERS_PATH . '/' . $orderId . '#kargo' : self::ORDERS_PATH);
    }

    /**
     * Doğrulama mesajlarında görünen alan adları.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'shipping_method_id' => 'Kargo yöntemi',
            'carrier_name'       => 'Kargo firması',
            'tracking_number'    => 'Takip numarası',
            'tracking_url'       => 'Takip adresi',
            'shipped_at'         => 'Kargo tarihi',
            'status'             => 'Gönderim durumu',
        ];
    }
}
