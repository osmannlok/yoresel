<?php

/**
 * Aşıroğlu Yöresel Market - Uygulama çekirdeği
 *
 * Yapılandırmayı tutar, session'ı başlatır, route'ları kaydeder,
 * CSRF doğrulamasını yapar ve isteği Router'a yönlendirir.
 */

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AccountOrderController; // YENİ
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\BrandController;
use App\Controllers\Admin\CategoryController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrderController;
use App\Controllers\Admin\ProductController;
use App\Controllers\Admin\ProductImageController;
use App\Controllers\Admin\ProductVariantController;
use App\Controllers\Admin\ShipmentController;
use App\Controllers\Admin\ShippingMethodController;
use App\Controllers\Admin\StockController;
use App\Controllers\AuthController as StorefrontAuthController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\ProductController as StorefrontProductController;
use App\Middleware\AdminMiddleware;
use Throwable;

final class App
{
    /** @var array<string, mixed> Yüklenen yapılandırma dizisi */
    private static array $config = [];

    private Router $router;

    /**
     * @param array<string, mixed> $config config/config.php dosyasından gelen dizi
     */
    public function __construct(array $config)
    {
        self::$config = $config;
        $this->router = new Router();
    }

    /**
     * Yapılandırma değerini nokta notasyonu ile okur.
     * Örnek: App::config('app.debug', false)
     */
    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Uygulamayı çalıştırır: istek alınır, işlenir, yanıt gönderilir.
     */
    public function run(): void
    {
        Session::start((array) self::config('session', []));

        $this->registerRoutes();

        $request = Request::capture();

        try {
            $response = $this->handle($request);
        } catch (Throwable $e) {
            // Geliştirme modunda hatayı index.php'deki global handler'a bırak
            if ((bool) self::config('app.debug', false)) {
                throw $e;
            }

            $this->logException($e);
            $response = $this->errorResponse($request, 500);
        }

        $response->send();
    }

    /**
     * Route'ları kaydeder.
     * Yeni modüller eklendikçe route'lar buraya eklenecektir.
     */
    private function registerRoutes(): void
    {
        // --- Vitrin ---------------------------------------------------------
        $this->router->get('/', [HomeController::class, 'index']);

        // Ürün listesi ve detay (FR-002, FR-003, FR-004, FR-014, FR-015, FR-033).
        // Herkese açıktır; yalnızca GET olduğu için CSRF gerekmez.
        $this->router->get('/urunler', [StorefrontProductController::class, 'index']);
        $this->router->get('/urun/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}', [StorefrontProductController::class, 'show']);

        // --- Müşteri girişi, kaydı ve çıkışı (FR-041, FR-050) ---------------
        // Herkese açıktır. POST'ların CSRF doğrulaması handle() içinde yapılır.
        // Yönetim paneli girişi ayrıdır: /admin/giris, /admin/cikis.
        $this->router->get('/giris', [StorefrontAuthController::class, 'showLogin']);
        $this->router->post('/giris', [StorefrontAuthController::class, 'login']);
        $this->router->get('/kayit', [StorefrontAuthController::class, 'showRegister']);
        $this->router->post('/kayit', [StorefrontAuthController::class, 'register']);
        $this->router->post('/cikis', [StorefrontAuthController::class, 'logout']);

        // --- Hesabım: siparişler (FR-055, FR-069, G-05) --------------------- // YENİ
        // Müşteri hesabıdır; AdminMiddleware dışındadır. Giriş denetimi ve       // YENİ
        // sipariş sahipliği (Order::findForUser) controller'da yapılır.          // YENİ
        // Yalnızca GET olduğu için CSRF gerekmez.                                // YENİ
        $this->router->get('/hesabim/siparisler', [AccountOrderController::class, 'index']); // YENİ
        $this->router->get('/hesabim/siparisler/{id:\d+}', [AccountOrderController::class, 'show']); // YENİ

        // --- Sepet (FR-040, FR-042, FR-043, FR-044, FR-045) -----------------
        // Misafir de sepet kullanır; bu route'lar AdminMiddleware dışındadır.
        // POST'ların CSRF doğrulaması handle() içinde yapılır (X-CSRF-TOKEN).
        $this->router->get('/sepet', [CartController::class, 'index']);
        $this->router->get('/sepet/ozet', [CartController::class, 'summary']);
        $this->router->post('/sepet/ekle', [CartController::class, 'add']);
        $this->router->post('/sepet/guncelle', [CartController::class, 'update']);
        $this->router->post('/sepet/sil', [CartController::class, 'remove']);


        // --- Checkout (FR-043, FR-045, FR-046, FR-060..FR-064, FR-074) ------
        // Misafir de sipariş verebilir (FR-062); bu route'lar AdminMiddleware
        // dışındadır. POST /odeme için CSRF doğrulaması handle() içinde yapılır.
        // Sonuç sayfası numarayı URL'de taşımaz; oturumdaki _last_order ile açılır.
        $this->router->get('/odeme', [CheckoutController::class, 'index']);
        $this->router->get('/odeme/ozet', [CheckoutController::class, 'summary']);
        $this->router->post('/odeme', [CheckoutController::class, 'store']);
        $this->router->get('/siparis/tamamlandi', [CheckoutController::class, 'complete']);

        // --- Yönetim paneli: giriş ve çıkış ---------------------------------
        // Bu route'lar AdminMiddleware grubunun DIŞINDA kalmalıdır; aksi halde
        // giriş sayfası giriş yapmamış kullanıcıyı kendisine yönlendirir.
        $this->router->get(AdminMiddleware::LOGIN_PATH, [AdminAuthController::class, 'showLogin']);
        $this->router->post(AdminMiddleware::LOGIN_PATH, [AdminAuthController::class, 'login']);
        $this->router->post('/admin/cikis', [AdminAuthController::class, 'logout']);

        // --- Yönetim paneli: korumalı route'lar -----------------------------
        // Yeni admin route'ları (sipariş vb.) bu grubun İÇİNE eklenir.
        // Grup içindeki yollar "/admin" önekiyle birleşir ('/' => '/admin').
        $this->router->group(
            ['prefix' => '/admin', 'middleware' => [AdminMiddleware::class]],
            function (Router $router): void {
                $router->get('/', [DashboardController::class, 'index']);

                // Kategoriler (FR-030, FR-031, FR-033)
                $router->get('/kategoriler', [CategoryController::class, 'index']);
                $router->get('/kategoriler/olustur', [CategoryController::class, 'create']);
                $router->post('/kategoriler', [CategoryController::class, 'store']);
                $router->get('/kategoriler/{id:\d+}/duzenle', [CategoryController::class, 'edit']);
                $router->post('/kategoriler/{id:\d+}/guncelle', [CategoryController::class, 'update']);
                $router->post('/kategoriler/{id:\d+}/sil', [CategoryController::class, 'destroy']);

                // Markalar (FR-032, FR-033)
                $router->get('/markalar', [BrandController::class, 'index']);
                $router->get('/markalar/olustur', [BrandController::class, 'create']);
                $router->post('/markalar', [BrandController::class, 'store']);
                $router->get('/markalar/{id:\d+}/duzenle', [BrandController::class, 'edit']);
                $router->post('/markalar/{id:\d+}/guncelle', [BrandController::class, 'update']);
                $router->post('/markalar/{id:\d+}/sil', [BrandController::class, 'destroy']);

                // Ürünler (FR-010, FR-011, FR-015)
                $router->get('/urunler', [ProductController::class, 'index']);
                $router->get('/urunler/olustur', [ProductController::class, 'create']);
                $router->post('/urunler', [ProductController::class, 'store']);
                $router->get('/urunler/{id:\d+}/duzenle', [ProductController::class, 'edit']);
                $router->post('/urunler/{id:\d+}/guncelle', [ProductController::class, 'update']);
                $router->post('/urunler/{id:\d+}/sil', [ProductController::class, 'destroy']);

                // Ürün görselleri (FR-012, FR-013, FR-014)
                // "sirala" sabit yoludur; {gid:\d+} kısıtlı yollarla çakışmaz.
                $router->get('/urunler/{id:\d+}/gorseller', [ProductImageController::class, 'index']);
                $router->post('/urunler/{id:\d+}/gorseller', [ProductImageController::class, 'store']);
                $router->post('/urunler/{id:\d+}/gorseller/sirala', [ProductImageController::class, 'reorder']);
                $router->post('/urunler/{id:\d+}/gorseller/{gid:\d+}/ana', [ProductImageController::class, 'setMain']);
                $router->post('/urunler/{id:\d+}/gorseller/{gid:\d+}/alt', [ProductImageController::class, 'updateAlt']);
                $router->post('/urunler/{id:\d+}/gorseller/{gid:\d+}/sil', [ProductImageController::class, 'destroy']);

                // Ürün varyantları (FR-020, FR-021, FR-022, FR-025)
                $router->get('/urunler/{id:\d+}/varyantlar', [ProductVariantController::class, 'index']);
                $router->get('/urunler/{id:\d+}/varyantlar/olustur', [ProductVariantController::class, 'create']);
                $router->post('/urunler/{id:\d+}/varyantlar', [ProductVariantController::class, 'store']);
                $router->get('/urunler/{id:\d+}/varyantlar/{vid:\d+}/duzenle', [ProductVariantController::class, 'edit']);
                $router->post('/urunler/{id:\d+}/varyantlar/{vid:\d+}/guncelle', [ProductVariantController::class, 'update']);
                $router->post('/urunler/{id:\d+}/varyantlar/{vid:\d+}/durum', [ProductVariantController::class, 'toggle']);

                // Stok (FR-023, FR-024, FR-026)
                $router->get('/stok', [StockController::class, 'index']);
                $router->get('/stok/hareket', [StockController::class, 'create']);
                $router->post('/stok/hareket', [StockController::class, 'store']);
                $router->get('/stok/gecmis', [StockController::class, 'history']);


                // Kargo yöntemleri (FR-084, FR-085)
                $router->get('/kargo', [ShippingMethodController::class, 'index']);
                $router->get('/kargo/olustur', [ShippingMethodController::class, 'create']);
                $router->post('/kargo', [ShippingMethodController::class, 'store']);
                $router->get('/kargo/{id:\d+}/duzenle', [ShippingMethodController::class, 'edit']);
                $router->post('/kargo/{id:\d+}/guncelle', [ShippingMethodController::class, 'update']);
                $router->post('/kargo/{id:\d+}/durum', [ShippingMethodController::class, 'toggle']);

                // Siparişler (FR-066, FR-091, FR-092)
                $router->get('/siparisler', [OrderController::class, 'index']);
                $router->get('/siparisler/{id:\d+}', [OrderController::class, 'show']);
                $router->post('/siparisler/{id:\d+}/durum', [OrderController::class, 'changeStatus']);
                $router->post('/siparisler/{id:\d+}/not', [OrderController::class, 'saveNote']);

                // Sipariş gönderimleri / manuel kargo takibi (FR-067, FR-069)
                $router->post('/siparisler/{orderId:\d+}/gonderimler', [ShipmentController::class, 'store']);
                $router->post('/siparisler/{orderId:\d+}/gonderimler/{id:\d+}/kargoya-ver', [ShipmentController::class, 'markHandedToCargo']);
                $router->post('/siparisler/{orderId:\d+}/gonderimler/{id:\d+}/takip', [ShipmentController::class, 'updateTracking']);
                $router->post('/siparisler/{orderId:\d+}/gonderimler/{id:\d+}/durum', [ShipmentController::class, 'changeStatus']);


            }
        );
    }

    /**
     * İsteği işler: CSRF kontrolü, route eşleştirme ve yanıt üretimi.
     */
    private function handle(Request $request): Response
    {
        // Durum değiştiren isteklerde CSRF token zorunlu
        if ($this->requiresCsrf($request) && !Csrf::validate($request)) {
            return $this->errorResponse($request, 419);
        }

        $response = $this->router->dispatch($request);

        // Eşleşen route yoksa 404
        return $response ?? $this->errorResponse($request, 404);
    }

    /**
     * Bu isteğin CSRF doğrulamasına tabi olup olmadığını belirler.
     */
    private function requiresCsrf(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        // İstisna listesi (örn. ödeme callback'leri; bunlar imza ile doğrulanacak)
        $exceptions = (array) self::config('csrf.except', []);
        $path = $request->path();

        foreach ($exceptions as $pattern) {
            $pattern = (string) $pattern;

            // "/odeme/callback/*" gibi sonu yıldızlı desenleri destekle
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($path, rtrim($pattern, '*'))) {
                    return false;
                }
            } elseif ($path === $pattern) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hata durumları için güvenli yanıt üretir (teknik detay içermez).
     * İstek JSON bekliyorsa (Fetch API) JSON, aksi halde basit HTML döner.
     */
    private function errorResponse(Request $request, int $status): Response
    {
        $messages = [
            404 => ['Sayfa bulunamadı', 'Aradığınız sayfa mevcut değil veya taşınmış olabilir.'],
            419 => ['Oturum süresi doldu', 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.'],
            500 => ['Bir hata oluştu', 'İşleminiz şu anda gerçekleştirilemiyor. Lütfen daha sonra tekrar deneyin.'],
        ];

        [$title, $text] = $messages[$status] ?? $messages[500];

        if ($request->expectsJson()) {
            return Response::json(['success' => false, 'message' => $text], $status);
        }

        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $text  = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
            . '</head><body class="bg-light">'
            . '<main class="container text-center py-5">'
            . '<h1 class="display-6 mb-3">' . $title . '</h1>'
            . '<p class="text-muted mb-4">' . $text . '</p>'
            . '<a href="/" class="btn btn-success">Ana Sayfaya Dön</a>'
            . '</main></body></html>';

        return Response::html($html, $status);
    }

    /**
     * Exception'ı storage/logs/php-error.log dosyasına yazar.
     */
    private function logException(Throwable $e): void
    {
        error_log(sprintf(
            "[%s] %s: %s in %s:%d\nStack trace:\n%s",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }
}