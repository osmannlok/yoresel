<?php

/**
 * Aşıroğlu Yöresel Market - Ödeme controller'ı (kredi / banka kartı)
 *
 * GET  /odeme/kart                   -> sağlayıcı ödeme sayfası (iFrame)
 * GET  /odeme/donus/basarili         -> sağlayıcı "başarılı" dönüş adresi (ok_url)
 * GET  /odeme/donus/basarisiz        -> sağlayıcı "başarısız" dönüş adresi (fail_url)
 * GET  /odeme/durum                  -> ödeme durumu (JSON; Fetch ile sorgulama)
 * POST /odeme/callback/{provider}    -> sağlayıcı bildirimi (sunucudan sunucuya)
 *
 * Kararlar:
 * - Controller ince kalır; ödeme başlatma ve callback işleme PaymentService'tedir.
 *   Servis kural hataları (InvalidArgumentException) kullanıcıya gösterilebilir
 *   Türkçe mesajlardır; diğer hatalar (RuntimeException vb.) loglanır ve
 *   kullanıcıya yalnızca genel mesaj gösterilir.
 * - Ödeme sayfası sipariş numarasını URL'de taşımaz; CheckoutController'ın
 *   oturumdaki `_last_order` kaydını kullanır ve yalnızca sipariş sahibine
 *   açılır (üyede user_id eşleşmeli, misafirde sipariş misafir siparişi
 *   olmalı). Başkasının siparişi ile olmayan sipariş aynı yanıtı verir (G-05).
 * - Sağlayıcının tarayıcıyı yönlendirdiği ok/fail sayfaları ödemenin kanıtı
 *   DEĞİLDİR; sipariş durumunu yalnızca imzası doğrulanmış callback değiştirir
 *   (FR-075). Dönüş sayfaları yalnızca müşteriyi doğru sayfaya taşır; iFrame
 *   içinde açılabileceği için üst pencerede yönlendirme yapan view kullanılır.
 * - Callback uç noktası CSRF dışındadır (config: csrf.except içinde
 *   "/odeme/callback/*"); bunun yerine sağlayıcı imzası doğrulanır. Yanıt:
 *   geçerli imza -> 200 + sağlayıcının beklediği gövde (tekrarlar dahil);
 *   geçersiz imza -> 400; bilinmeyen sağlayıcı -> 404; işleme hatası -> 500
 *   (sağlayıcı bildirimi yeniden dener). Callback gövdesi loglanmaz; kart ve
 *   imza verisi bu controller'da hiçbir yere yazılmaz.
 * - Ödeme sayfası ve durum yanıtları önbelleğe alınmaz (no-store).
 * - Havale/EFT bilgi sayfası (FR-072) ve kapıda ödeme bu controller'ın
 *   kapsamında değildir.
 *
 * Gereksinimler: FR-071, FR-075, FR-076, NFR-003, NFR-004.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Order;
use App\Services\PaymentService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PaymentController extends Controller
{
    /** Kart ödeme sayfası */
    private const CARD_PATH = '/odeme/kart';

    /** Sağlayıcı dönüş adresleri (ok_url / fail_url) */
    private const RETURN_OK_PATH   = '/odeme/donus/basarili';
    private const RETURN_FAIL_PATH = '/odeme/donus/basarisiz';

    /** Ödeme durumu (JSON) yolu */
    private const STATUS_PATH = '/odeme/durum';

    /** Sağlayıcı callback yolu öneki; sonuna sağlayıcı kodu eklenir */
    private const CALLBACK_PREFIX = '/odeme/callback/';

    /** Sipariş sonuç sayfası (CheckoutController ile aynı) */
    private const COMPLETE_PATH = '/siparis/tamamlandi';

    /** CheckoutController'ın oturum anahtarı */
    private const SESSION_LAST_ORDER = '_last_order';

    /** Kartla ödeme yöntemi kodu (payments.payment_method ile uyumlu) */
    private const METHOD_CARD = 'credit_card';

    /** Kartla ödenebilir sipariş durumları (DECISIONS.md geçişleri) */
    private const PAYABLE_STATUSES = ['awaiting_payment', 'payment_failed'];

    /** Callback gövdesinden okunacak en fazla alan sayısı ve değer uzunluğu */
    private const CALLBACK_MAX_FIELDS = 50;
    private const CALLBACK_MAX_VALUE  = 2000;

    // -------------------------------------------------------------------------
    // Ödeme sayfası
    // -------------------------------------------------------------------------

    /**
     * Sağlayıcı ödeme sayfasını (iFrame) gösterir: GET /odeme/kart
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $context = $this->resolveLastOrder();

        if ($context === null) {
            return $this->redirect('/');
        }

        $order = $context['order'];

        // Yalnızca kartla ödeme seçilen siparişler bu sayfayı kullanır
        if ($context['payment_method'] !== self::METHOD_CARD) {
            return $this->redirect(self::COMPLETE_PATH);
        }

        // Ödenmiş, iptal edilmiş vb. sipariş için yeni ödeme başlatılmaz
        if (!in_array((string) $order['status'], self::PAYABLE_STATUSES, true)) {
            Session::flash('info', 'Bu sipariş için ödeme beklenmiyor.');

            return $this->redirect(self::COMPLETE_PATH);
        }

        try {
            $payment = (new PaymentService())->startCardPayment((int) $order['id'], [
                'ok_url'       => $this->absoluteUrl(self::RETURN_OK_PATH),
                'fail_url'     => $this->absoluteUrl(self::RETURN_FAIL_PATH),
                'callback_url' => $this->absoluteUrl(self::CALLBACK_PREFIX . 'paytr'),
            ], $request->ip());

            $iframeUrl = (string) ($payment['iframe_url'] ?? '');

            // Sağlayıcı adresi yalnızca HTTPS olabilir
            if (!str_starts_with($iframeUrl, 'https://')) {
                throw new RuntimeException('Ödeme sağlayıcısı geçersiz iFrame adresi döndürdü.');
            }
        } catch (InvalidArgumentException $e) {
            // Servis kuralları kullanıcıya gösterilebilir Türkçe mesajlardır
            Session::flash('error', $e->getMessage());

            return $this->redirect(self::COMPLETE_PATH);
        } catch (Throwable $e) {
            $this->logError('ödeme başlatılamadı (sipariş ' . (int) $order['id'] . ')', $e);
            Session::flash(
                'error',
                'Ödeme sayfası şu an açılamadı. Siparişiniz kaydedildi; lütfen biraz sonra tekrar deneyin.'
            );

            return $this->redirect(self::COMPLETE_PATH);
        }

        return $this->view('payment/card', [
            'title'        => 'Kart ile Ödeme',
            'order'        => $order,
            'iframeUrl'    => $iframeUrl,
            'statusPath'   => self::STATUS_PATH,
            'completePath' => self::COMPLETE_PATH,
        ])->header('Cache-Control', 'no-store');
    }

    // -------------------------------------------------------------------------
    // Sağlayıcıdan dönüş
    // -------------------------------------------------------------------------

    /**
     * Başarılı dönüş: GET /odeme/donus/basarili
     *
     * Ödemenin gerçekten alındığı callback ile belirlenir; burada yalnızca
     * müşteri sonuç sayfasına taşınır.
     *
     * @param array<string, string> $params
     */
    public function returnSuccess(Request $request, array $params = []): Response
    {
        $context = $this->resolveLastOrder();

        if ($context !== null && (string) $context['order']['payment_status'] !== 'paid') {
            Session::flash(
                'info',
                'Ödemeniz doğrulanıyor. Onaylandığında sipariş durumunuz otomatik güncellenir.'
            );
        }

        return $this->returnPage(
            $context === null ? '/' : self::COMPLETE_PATH,
            'Ödeme sonucunuz kontrol ediliyor…'
        );
    }

    /**
     * Başarısız dönüş: GET /odeme/donus/basarisiz
     *
     * @param array<string, string> $params
     */
    public function returnFailure(Request $request, array $params = []): Response
    {
        $context = $this->resolveLastOrder();

        if ($context !== null) {
            Session::flash('error', 'Ödeme tamamlanamadı. İsterseniz tekrar deneyebilirsiniz.');
        }

        return $this->returnPage(
            $context === null ? '/' : self::CARD_PATH,
            'Ödeme tamamlanamadı, yönlendiriliyorsunuz…'
        );
    }

    // -------------------------------------------------------------------------
    // Durum sorgusu (Fetch)
    // -------------------------------------------------------------------------

    /**
     * Siparişin ödeme durumunu döndürür (JSON): GET /odeme/durum
     *
     * Ödeme sayfası, callback işlenene kadar bu uç noktayı aralıklı sorgular
     * ve sonuç belli olunca müşteriyi yönlendirir.
     *
     * @param array<string, string> $params
     */
    public function status(Request $request, array $params = []): Response
    {
        $context = $this->resolveLastOrder();

        // Salt okunur uç nokta: eşzamanlı Fetch isteklerini bekletmemek için kilit bırakılır
        Session::close();

        if ($context === null) {
            return $this->json(['success' => false, 'message' => 'Sipariş bulunamadı.'], 404)
                ->header('Cache-Control', 'no-store');
        }

        $paymentStatus = (string) $context['order']['payment_status'];
        $nextPath      = null;

        if ($paymentStatus === 'paid') {
            $nextPath = self::COMPLETE_PATH;
        } elseif ($paymentStatus === 'failed') {
            $nextPath = self::CARD_PATH;
        }

        return $this->json([
            'success'        => true,
            'order_status'   => (string) $context['order']['status'],
            'payment_status' => $paymentStatus,
            'done'           => $nextPath !== null,
            'next_url'       => $nextPath === null ? null : $this->url($nextPath),
        ])->header('Cache-Control', 'no-store');
    }

    // -------------------------------------------------------------------------
    // Sağlayıcı bildirimi (callback / webhook)
    // -------------------------------------------------------------------------

    /**
     * Sağlayıcı bildirimini işler: POST /odeme/callback/{provider}
     *
     * CSRF yerine sağlayıcı imzası doğrulanır (FR-075). İmza doğrulaması,
     * tutar karşılaştırması, tekrar bildirimlerin yok sayılması ve sipariş
     * durumu güncellemesi PaymentService'tedir.
     *
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params = []): Response
    {
        $provider = (string) ($params['provider'] ?? '');

        try {
            $result = (new PaymentService())->handleCallback(
                $provider,
                $this->readCallbackPayload($request),
                [],
                $request->ip()
            );
        } catch (InvalidArgumentException $e) {
            // Bilinmeyen sağlayıcı
            return $this->plain('NOT FOUND', 404);
        } catch (Throwable $e) {
            // Sağlayıcı "OK" almazsa bildirimi yeniden dener; ayrıntı yalnızca logda
            $this->logError('callback işlenemedi (sağlayıcı ' . $this->safeLabel($provider) . ')', $e);

            return $this->plain('ERROR', 500);
        }

        if (empty($result['valid'])) {
            return $this->plain('INVALID', 400);
        }

        return $this->plain((string) ($result['body'] ?? 'OK'), 200);
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Oturumdaki son siparişi bulur ve sahipliğini doğrular.
     *
     * Kayıt yoksa, sipariş bulunamazsa veya sahibi bu oturum değilse null
     * döner; çağıran tarafın yanıtı bu durumların hepsinde aynıdır (G-05).
     *
     * @return array{order: array<string, mixed>, payment_method: string}|null
     */
    private function resolveLastOrder(): ?array
    {
        $last = Session::get(self::SESSION_LAST_ORDER);

        if (!is_array($last) || !isset($last['order_id']) || !is_int($last['order_id'])) {
            return null;
        }

        $order = (new Order())->find($last['order_id']);

        if ($order === null) {
            return null;
        }

        // Sahiplik: üye siparişi yalnızca sahibine, misafir siparişi misafir oturumuna açıktır
        $orderUserId = $order['user_id'] === null ? null : (int) $order['user_id'];

        if ($orderUserId !== Auth::id()) {
            return null;
        }

        return [
            'order'          => $order,
            'payment_method' => (string) ($last['payment_method'] ?? ''),
        ];
    }

    /**
     * Callback gövdesini skaler değerli diziye çevirir. Dizi/nesne değerleri
     * atılır; alan sayısı ve değer uzunluğu sınırlıdır. İçerik loglanmaz.
     *
     * @return array<string, string>
     */
    private function readCallbackPayload(Request $request): array
    {
        $payload = [];

        foreach ($request->all() as $key => $value) {
            if (count($payload) >= self::CALLBACK_MAX_FIELDS) {
                break;
            }

            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $payload[$key] = mb_substr((string) $value, 0, self::CALLBACK_MAX_VALUE);
        }

        return $payload;
    }

    /**
     * Sağlayıcıya verilecek mutlak adres. Sağlayıcı sunucuları göreli adresi
     * kullanamaz; app.url ayarı yoksa veya HTTPS değilse hata verilir.
     *
     * @throws RuntimeException app.url eksik veya HTTPS değilse
     */
    private function absoluteUrl(string $path): string
    {
        $url = $this->url($path);

        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Mutlak HTTPS adresi üretilemedi; app.url ayarını kontrol edin.');
        }

        return $url;
    }

    /**
     * Üst pencerede yönlendirme yapan küçük dönüş sayfası. Sağlayıcının
     * dönüş adresi iFrame içinde açılabileceği için yönlendirme `window.top`
     * üzerinden yapılır; JavaScript kapalıysa bağlantı gösterilir.
     */
    private function returnPage(string $path, string $message): Response
    {
        return $this->view('payment/return', [
            'title'   => 'Yönlendiriliyor',
            'target'  => $this->url($path),
            'message' => $message,
        ], null)->header('Cache-Control', 'no-store');
    }

    /** Düz metin yanıtı (sağlayıcıya dönen gövde HTML olmamalıdır). */
    private function plain(string $body, int $status): Response
    {
        return Response::html($body, $status)
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Log satırında güvenle yazılabilecek kısa etiket (yalnızca a-z, 0-9, -, _). */
    private function safeLabel(string $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/i', '', mb_substr($value, 0, 30)) ?? '';
    }

    /**
     * Teknik hatayı log dosyasına yazar (kullanıcıya gösterilmez).
     * İstek gövdesi, imza ve kart verisi loga yazılmaz.
     */
    private function logError(string $context, Throwable $e): void
    {
        error_log(sprintf(
            '[%s] PaymentController: %s: %s',
            date('Y-m-d H:i:s'),
            $context,
            $e->getMessage()
        ));
    }
}