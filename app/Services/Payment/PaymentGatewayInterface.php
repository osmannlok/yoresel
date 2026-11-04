<?php

/**
 * Aşıroğlu Yöresel Market - Ödeme sağlayıcısı arayüzü
 *
 * Tüm ödeme adapter'ları (PayTR, iyzico, test/sandbox) bu arayüzü uygular.
 * Ödeme servisi yalnızca bu arayüze bağımlıdır; sağlayıcı değişince servis
 * koduna dokunulmaz (genişletilebilir yapı).
 *
 * Kurallar (DECISIONS.md, Ödeme / Kargo):
 * - Kart numarası, CVV ve son kullanma tarihi bu arayüzden ASLA geçmez.
 *   Kart bilgisi yalnızca sağlayıcının barındırdığı ödeme sayfasında
 *   (yönlendirme / iframe / sağlayıcı formu) girilir; sunucumuza ulaşmaz.
 * - API anahtarı ve secret değerleri adapter içinde config/payment.php
 *   üzerinden okunur (.env kaynaklı); arayüz metotlarına parametre olarak
 *   verilmez ve hiçbir dönüş değerinde yer almaz.
 * - Callback'te imza/hash doğrulaması zorunludur ve adapter içinde yapılır.
 *   Geçersiz imza istisna fırlatmaz; signature_valid=false döner ki çağıran
 *   taraf olayı 'callback_invalid_signature' olarak loglayabilsin.
 *   İmzası geçersiz callback ile sipariş/ödeme durumu ASLA değiştirilmez.
 * - Tutarlar TRY ve KDV dahildir; 2 ondalıklı, nokta ayraçlı METİN olarak
 *   taşınır (örn. "850.50"). Float kullanılmaz (yuvarlama hatası).
 * - Teknik/yapılandırma/ağ hataları RuntimeException ile bildirilir; mesajı
 *   loglanır, kullanıcıya gösterilmez. Sağlayıcının "ödeme reddedildi" gibi
 *   iş sonuçları istisna DEĞİL, dönüş değerindeki status='failed' ile bildirilir.
 * - Dönüş dizileri payment_logs'a yazılmadan önce çağıran tarafta maskelenir.
 *
 * Sipariş numarası notu: Sipariş numarası "ASY-20261030-000123" biçimindedir.
 * Bazı sağlayıcılar (örn. PayTR merchant_oid) tire kabul etmez. Dönüşümü
 * (tireleri kaldırma / geri çevirme) adapter yapar; bu arayüzde her zaman
 * orijinal order_number kullanılır ve callback sonucunda orijinal haliyle döner.
 *
 * Gereksinimler: FR-070, FR-071, FR-075, FR-076, FR-077, NFR-008, NFR-009, G-06.
 */

declare(strict_types=1);

namespace App\Services\Payment;

use RuntimeException;

interface PaymentGatewayInterface
{
    /** Ödeme durumu: işlem sürüyor (payments.status ile aynı değer) */
    public const STATUS_PENDING = 'pending';

    /** Ödeme durumu: başarılı (payments.status ile aynı değer) */
    public const STATUS_COMPLETED = 'completed';

    /** Ödeme durumu: başarısız (payments.status ile aynı değer) */
    public const STATUS_FAILED = 'failed';

    /** Müşteriyi sağlayıcının ödeme sayfasına yönlendir (HTTP 302) */
    public const ACTION_REDIRECT = 'redirect';

    /** Sağlayıcının ödeme sayfasını iframe içinde göster */
    public const ACTION_IFRAME = 'iframe';

    /** form_fields ile sağlayıcıya otomatik POST formu gönder */
    public const ACTION_FORM_POST = 'form_post';

    /** Müşteri yönlendirmesi gerekmiyor (örn. başlatma başarısız oldu) */
    public const ACTION_NONE = 'none';

    /**
     * Sağlayıcının kısa, küçük harfli kodu: 'paytr', 'iyzico', 'sandbox'.
     * payments.payment_provider ve payment_logs.provider alanlarına yazılır
     * (VARCHAR(50)).
     */
    public function name(): string;

    /**
     * Adapter test (sandbox) modunda mı çalışıyor?
     * Canlıya geçiş kontrolünde ve admin panelinde uyarı göstermek için kullanılır.
     */
    public function isSandbox(): bool;

    /**
     * Ödemeyi başlatır (3D Secure sağlayıcının sayfasında yürütülür).
     *
     * Başarı/dönüş URL'leri ve callback URL'si adapter içinde
     * config/payment.php'den okunur.
     *
     * @param array{
     *     order_number: string,
     *     amount: string,
     *     currency: string,
     *     installment_count: int,
     *     items: list<array{name: string, unit_price: string, quantity: int}>
     * } $order Sipariş özeti (güncel DB değerlerinden; sepet/form verisinden değil)
     * @param array{
     *     email: string,
     *     phone: string,
     *     first_name: string,
     *     last_name: string,
     *     address_line: string,
     *     city: string,
     *     district: string,
     *     ip: string
     * } $customer Müşteri bilgisi (ip: Request::ip())
     *
     * @return array{
     *     status: 'pending'|'failed',
     *     action: 'redirect'|'iframe'|'form_post'|'none',
     *     checkout_url: string|null,
     *     form_fields: array<string, string>,
     *     provider_transaction_id: string|null,
     *     provider_reference: string|null,
     *     error_message: string|null
     * } status='failed' ise error_message kullanıcıya gösterilebilir kısa bir özettir
     *   (teknik ayrıntı içermez); action 'none' olur.
     *
     * @throws RuntimeException Yapılandırma eksikse veya sağlayıcıya ulaşılamazsa
     */
    public function initiate(array $order, array $customer): array;

    /**
     * Sağlayıcının callback/webhook isteğini doğrular ve normalleştirir.
     *
     * İlk iş imza/hash doğrulamasıdır; imza geçersizse yalnızca
     * signature_valid=false ve response_body döner, diğer alanlar boş kalır.
     * Çağıran taraf signature_valid=false iken hiçbir durum değişikliği yapmaz.
     *
     * @param array<string, mixed>  $payload Callback gövdesi (Request::all() / JSON)
     * @param array<string, string> $headers Küçük harfli başlık adı => değer
     *
     * @return array{
     *     signature_valid: bool,
     *     status: 'pending'|'completed'|'failed'|null,
     *     order_number: string|null,
     *     provider_transaction_id: string|null,
     *     provider_reference: string|null,
     *     amount: string|null,
     *     currency: string|null,
     *     error_message: string|null,
     *     response_body: string
     * } response_body: sağlayıcının beklediği yanıt metnidir (örn. PayTR için
     *   "OK"); callback controller'ı bunu aynen yazar, böylece sağlayıcı
     *   tekrar deneme yapmaz. order_number orijinal biçimiyle (ASY-...) döner.
     *
     * @throws RuntimeException Yapılandırma eksikse (imza geçersizliği istisna değildir)
     */
    public function handleCallback(array $payload, array $headers): array;

    /**
     * Ödemenin güncel durumunu sağlayıcıdan sorgular (mutabakat).
     * Callback'in kaybolduğu veya geciktiği durumlarda kullanılır.
     *
     * @param string $orderNumber Orijinal sipariş numarası (ASY-...)
     *
     * @return array{
     *     status: 'pending'|'completed'|'failed',
     *     provider_transaction_id: string|null,
     *     provider_reference: string|null,
     *     amount: string|null,
     *     currency: string|null,
     *     error_message: string|null
     * }
     *
     * @throws RuntimeException Sağlayıcıya ulaşılamazsa veya yanıt çözümlenemezse
     */
    public function query(string $orderNumber): array;

    /**
     * Tam veya kısmi iade başlatır.
     *
     * Çağıran taraf (iade servisi) tutarın iade edilebilir sınırı aşmadığını
     * önceden doğrular; ödeme/sipariş durumunu iade başarılıysa kendisi günceller.
     *
     * @param string $providerTransactionId Sağlayıcının işlem numarası (payments.provider_transaction_id)
     * @param string $orderNumber           Orijinal sipariş numarası (ASY-...)
     * @param string $amount                İade tutarı, örn. "120.00" (TRY, KDV dahil)
     *
     * @return array{
     *     success: bool,
     *     provider_reference: string|null,
     *     error_message: string|null
     * } success=false ise error_message kısa özettir; teknik ayrıntı döndürülmez.
     *
     * @throws RuntimeException Yapılandırma eksikse veya sağlayıcıya ulaşılamazsa
     */
    public function refund(string $providerTransactionId, string $orderNumber, string $amount): array;
}