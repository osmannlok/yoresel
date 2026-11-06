<?php

/**
 * Aşıroğlu Yöresel Market - Yönetim paneli sipariş controller'ı
 *
 * GET  /admin/siparisler                 -> filtreli, sayfalı sipariş listesi
 * GET  /admin/siparisler/{id}            -> sipariş detayı (kalemler, adres, ödeme,
 *                                           kargo, durum geçmişi, yönetici notu)
 * POST /admin/siparisler/{id}/durum      -> sipariş durumunu değiştir (Fetch veya form)
 * POST /admin/siparisler/{id}/not        -> yönetici notunu kaydet (Fetch veya form)
 *
 * (Gönderim/kargo işlemleri ayrıdır: Admin\ShipmentController.)
 *
 * Kararlar:
 * - Erişim denetimi route grubundaki AdminMiddleware ile yapılır; burada ikinci
 *   savunma katmanı olarak yetki yeniden kontrol edilir.
 * - POST isteklerinin CSRF doğrulaması App::handle() içinde yapılır; burada
 *   tekrarlanmaz.
 * - Durum geçişlerinin geçerliliğini Order::changeStatus() denetler (TRANSITIONS).
 *   Burada ek olarak 'shipped' ve 'delivered' elle seçilemez: bu iki durum
 *   gönderim kaydından türer (ShipmentService) ve kargo bölümünden yönetilir;
 *   böylece "kargolandı ama gönderim kaydı yok" tutarsızlığı oluşmaz.
 * - Durumu 'paid' yapmak ödeme durumunu da 'paid' yapar (havale/EFT onayı).
 *   'cancelled' stokları geri ekler (Order modeli). İptal, kart ile alınmış
 *   ödemeyi OTOMATİK İADE ETMEZ; iade ödeme sağlayıcı panelinden yapılır.
 * - Yönetici notu (orders.admin_note) müşteriye gösterilmez; durum geçmişine
 *   not yazılırsa müşteri görür. Bu yüzden iki not alanı ayrıdır: durum
 *   değişikliğinin "note" alanı geçmişe (müşteri görür), "admin_note" iç nottur.
 * - Ödeme satırından yalnızca gösterilebilir alanlar view'a geçer (kart
 *   numarası zaten saklanmaz; yalnızca maske vardır).
 * - Girdiler Validator'dan geçer; liste filtreleri Order modelinde doğrulanır.
 * - Yanıt: Fetch isteğinde JSON, aksi halde flash mesaj + yönlendirme.
 *
 * Gereksinimler: FR-066, FR-090, FR-091, FR-092, NFR-003, NFR-004.
 */

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\AccountOrderController;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Middleware\AdminMiddleware;
use App\Models\Order;
use App\Models\Payment;
use App\Services\ShipmentService;
use InvalidArgumentException;
use Throwable;

final class OrderController extends Controller
{
    private const LIST_PATH = '/admin/siparisler';

    private const PER_PAGE = 20;

    /** Sayfa numarası üst sınırı (aşırı büyük OFFSET'i engeller) */
    private const MAX_PAGE = 100000;

    /** Elle seçilemeyen sipariş durumları: gönderim kaydından türer */
    private const SHIPMENT_DRIVEN_STATUSES = ['shipped', 'delivered'];

    /** Kullanıcıya gösterilen genel hata (teknik ayrıntı loga yazılır) */
    private const GENERIC_ERROR = 'İşlem şu anda tamamlanamadı. Lütfen tekrar deneyin.';

    private const PAYMENT_METHOD_LABELS = [
        'credit_card'      => 'Kredi/Banka Kartı',
        'bank_transfer'    => 'Havale/EFT',
        'cash_on_delivery' => 'Kapıda Ödeme',
        'wallet'           => 'Cüzdan',
    ];

    private const PAYMENT_RECORD_LABELS = [
        'pending'            => 'Bekliyor',
        'completed'          => 'Tamamlandı',
        'failed'             => 'Başarısız',
        'cancelled'          => 'İptal',
        'refunded'           => 'İade Edildi',
        'partially_refunded' => 'Kısmen İade Edildi',
    ];

    // -------------------------------------------------------------------------
    // Liste
    // -------------------------------------------------------------------------

    /**
     * Sipariş listesi: GET /admin/siparisler
     *
     * Sorgu parametreleri: status, payment_status, q, date_from, date_to, page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        if (!Auth::canAccessAdmin()) {
            return $this->redirect(AdminMiddleware::LOGIN_PATH);
        }

        $filters = [
            'status'         => $this->queryText($request, 'status', 30),
            'payment_status' => $this->queryText($request, 'payment_status', 30),
            'q'              => $this->queryText($request, 'q', 100),
            'date_from'      => $this->queryText($request, 'date_from', 10),
            'date_to'        => $this->queryText($request, 'date_to', 10),
        ];

        $page = $request->query('page', 1);
        $page = is_scalar($page) && preg_match('/^[1-9]\d{0,5}$/', (string) $page) === 1
            ? min(self::MAX_PAGE, (int) $page)
            : 1;

        $orders = new Order();

        return $this->view('admin/orders/index', [
            'title'               => 'Siparişler',
            'activeMenu'          => 'orders',
            'result'              => $orders->paginateForAdmin($filters, $page, self::PER_PAGE),
            'counts'              => $orders->countByStatus(),
            'filters'             => $filters,
            'statusLabels'        => AccountOrderController::STATUS_LABELS,
            'paymentStatusLabels' => AccountOrderController::PAYMENT_STATUS_LABELS,
            'basePath'            => self::LIST_PATH,
        ], 'layouts/admin');
    }

    // -------------------------------------------------------------------------
    // Detay
    // -------------------------------------------------------------------------

    /**
     * Sipariş detayı: GET /admin/siparisler/{id}
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        if (!Auth::canAccessAdmin()) {
            return $this->redirect(AdminMiddleware::LOGIN_PATH);
        }

        $id     = (int) ($params['id'] ?? 0);
        $orders = new Order();
        $order  = $id > 0 ? $orders->find($id) : null;

        if ($order === null) {
            Session::flash('error', 'Sipariş bulunamadı.');

            return $this->redirect(self::LIST_PATH);
        }

        $status = (string) $order['status'];

        return $this->view('admin/orders/show', [
            'title'                => 'Sipariş ' . (string) $order['order_number'],
            'activeMenu'           => 'orders',
            'order'                => $order,
            'items'                => $orders->getItems($id),
            'address'              => $this->decodeSnapshot((string) $order['shipping_address_snapshot']),
            'history'              => $orders->getHistory($id),
            'payments'             => $this->paymentRows($id),
            'shipments'            => (new ShipmentService())->listForAdmin($id),
            'nextStatuses'         => $this->selectableStatuses($status),
            'statusLabels'         => AccountOrderController::STATUS_LABELS,
            'paymentStatusLabels'  => AccountOrderController::PAYMENT_STATUS_LABELS,
            'shipmentStatusLabels' => AccountOrderController::SHIPMENT_STATUS_LABELS,
            'paymentMethodLabels'  => self::PAYMENT_METHOD_LABELS,
            'paymentRecordLabels'  => self::PAYMENT_RECORD_LABELS,
            'listPath'             => self::LIST_PATH,
        ], 'layouts/admin');
    }

    // -------------------------------------------------------------------------
    // Durum ve not
    // -------------------------------------------------------------------------

    /**
     * Sipariş durumunu değiştirir: POST /admin/siparisler/{id}/durum
     *
     * Alanlar: status (zorunlu), note (isteğe bağlı; durum geçmişine yazılır,
     * MÜŞTERİ GÖRÜR).
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return $this->handle(
            $request,
            $id,
            [
                'status' => 'required|in:' . implode(',', array_keys(Order::TRANSITIONS)),
                'note'   => 'string|max:500',
            ],
            function (array $data) use ($id): string {
                $newStatus = (string) $data['status'];

                if (in_array($newStatus, self::SHIPMENT_DRIVEN_STATUSES, true)) {
                    throw new InvalidArgumentException(
                        'Bu durum kargo bölümünden yönetilir. Gönderim kaydı ekleyin veya gönderim durumunu güncelleyin.'
                    );
                }

                $note = isset($data['note']) ? (string) $data['note'] : null;

                if (!(new Order())->changeStatus($id, $newStatus, Auth::id(), $note)) {
                    throw new InvalidArgumentException('Sipariş bulunamadı.');
                }

                return 'Sipariş durumu güncellendi.';
            },
        );
    }

    /**
     * Yönetici notunu kaydeder: POST /admin/siparisler/{id}/not
     *
     * Alan: admin_note (boş gönderilirse not silinir). Müşteriye gösterilmez.
     *
     * @param array<string, string> $params
     */
    public function saveNote(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);

        return $this->handle(
            $request,
            $id,
            ['admin_note' => 'string|max:1000'],
            function (array $data) use ($id): string {
                $note = isset($data['admin_note']) ? (string) $data['admin_note'] : null;

                if (!(new Order())->saveAdminNote($id, $note)) {
                    throw new InvalidArgumentException('Sipariş bulunamadı.');
                }

                return 'Sipariş notu kaydedildi.';
            },
        );
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Ortak POST akışı: yetki, kimlik, doğrulama, eylem, yanıt.
     *
     * @param array<string, string>                  $rules  Validator kuralları
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
            // Kural ihlali: model mesajı kullanıcıya gösterilebilir
            return $this->respond($request, $orderId, false, $e->getMessage(), [], 422);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[%s] Admin\OrderController: sipariş %d: %s',
                date('Y-m-d H:i:s'),
                $orderId,
                $e->getMessage()
            ));

            return $this->respond($request, $orderId, false, self::GENERIC_ERROR, [], 500);
        }

        return $this->respond($request, $orderId, true, $message, [], 200);
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

        return $this->redirect($orderId > 0 ? self::LIST_PATH . '/' . $orderId : self::LIST_PATH);
    }

    /**
     * Sipariş durumundan elle geçilebilecek durumlar (gönderimden türeyenler hariç).
     *
     * @return list<string>
     */
    private function selectableStatuses(string $status): array
    {
        return array_values(array_diff(
            Order::TRANSITIONS[$status] ?? [],
            self::SHIPMENT_DRIVEN_STATUSES
        ));
    }

    /**
     * Ödeme denemelerini yalnızca gösterilebilir alanlarla döndürür.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentRows(int $orderId): array
    {
        $allowed = array_flip([
            'id', 'payment_method', 'payment_provider', 'provider_transaction_id', 'status',
            'amount', 'currency', 'installment_count', 'card_mask', 'error_message',
            'paid_at', 'created_at',
        ]);

        try {
            $rows = (new Payment())->getByOrder($orderId);
        } catch (Throwable $e) {
            // Ödeme okunamazsa detay sayfası yine açılır; hata loglanır
            error_log(sprintf(
                '[%s] Admin\OrderController: ödemeler okunamadı (sipariş %d): %s',
                date('Y-m-d H:i:s'),
                $orderId,
                $e->getMessage()
            ));

            return [];
        }

        return array_map(
            static fn (array $row): array => array_intersect_key($row, $allowed),
            $rows
        );
    }

    /** Sorgu parametresini metne çevirir; dizi/boş/çok uzun değer için sınırlar. */
    private function queryText(Request $request, string $key, int $maxLength): string
    {
        $value = $request->query($key, '');

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $maxLength) : '';
    }

    /**
     * Anlık adres kopyasını (JSON) diziye çevirir; çözülemezse boş dizi.
     *
     * @return array<string, mixed>
     */
    private function decodeSnapshot(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Doğrulama mesajlarında görünen alan adları.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'status'     => 'Sipariş durumu',
            'note'       => 'Durum notu',
            'admin_note' => 'Yönetici notu',
        ];
    }
}
