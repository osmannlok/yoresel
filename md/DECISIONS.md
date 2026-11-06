# Alınmış Mimari Kararlar

> Mimari kararların tek kaynağı. `CLAUDE.md` ile çelişirse bu dosya önceliklidir.

## Belge Hiyerarşisi

1. **DECISIONS.md** — mimari, DB, güvenlik, uygulama kararları (tek kaynak)
2. **GEREKSINIMLER.MD** — FR / kabul kriterleri referansı; mimari kaynak değil
3. **CLAUDE.md** — çalışma kuralları; DECISIONS ile çelişemez
4. **database/migrations** — güncel şema kaynağı
5. **database-schema.sql** — yalnızca başlangıç referansı, çalıştırılmaz

## Veritabanı & Migration

- MySQL 8 / MariaDB · utf8mb4_unicode_ci · InnoDB · PDO prepared
- Kaynak: `database/migrations`. Sıra: `DATABASE-DEPENDENCIES.md`
- Dosya adı: `NNN_create_<tablo_veya_grup>.sql`
- Çalışmış migration değiştirilmez → yeni dosya, son numaradan sonra
- `migrate.php`: dosya adı sırası, checksum → `schema_migrations`; değişirse durur
- DDL geri alınamaz; hata → dosya kaydedilmez, tablolar elle temizlenir

## Stok

- `stocks` tablosu yok → hareketler `stock_movements`
- Varyantsız: `products.stock_quantity` · Varyantlı: `product_variants.stock_quantity`
- Sipariş + stok düşümü aynı transaction
- `stock_movements` yalnızca eklenir; FOR UPDATE + transaction; stok negatife düşemez
- Manuel: neden zorunlu (FR-026). Form: `incoming|outgoing|return|correction`; `reservation` sistem
- `correction`: girilen = hedef stok; kaydedilen = farkın mutlak değeri
- Varyantlı ürüne varyantsız hareket yazılamaz
- Başlangıç stoğu: kayıt 0 ile açılır; >0 ise `incoming` aynı transaction’da

## Sepet

- Misafir: **sadece session** `$_SESSION['cart']['items']` — DB’ye yazılmaz
- Üye: `carts` + `cart_items`; `carts.user_id NOT NULL`; `session_id` rezerve, kullanılmaz
- Girişte `CartService::mergeGuestCart()` (transaction, stok sınırı; sığmayan → uyarı)
- `variant_key` VIRTUAL: `IFNULL(product_variant_id, 0)`; UNIQUE `(cart_id, product_id, variant_key)`
- Kalem anahtarı: misafir `p{id}` / `p{id}v{vid}`, üye `cart_items.id`
- Max 50 kalem, kalem başına max 1000 adet
- Sepete ekle/güncelle: stok + varyant + vitrin görünürlüğü; varyantlıda varyant zorunlu
- Fiyat bilgilendirme; siparişte güncel fiyat/stok yeniden kontrol
- Sipariş sonrası sepet `status = converted`

## Sipariş

- No: `ASY-YYYYMMDD-NNNNNN` (NNNNNN = orders.id, 6 hane). Önce `TMP-<rand>`, ID sonrası güncelle
- Durumlar: `pending, awaiting_payment, paid, preparing, shipped, delivered, cancelled, payment_failed, returned`
- `payment_status`: `unpaid, paid, failed, refunded, partially_refunded`
- `customer_email` NOT NULL · `customer_phone` NULL · idx_orders_customer_email
- `order_items.product_id` / `product_variant_id`: ON DELETE SET NULL
- Geçmiş: `order_status_history`
- `payments.order_id` / `shipments.order_id`: UNIQUE değil (çoklu deneme / parçalı gönderim)

### Checkout

- Servis: `CheckoutService`; controller ince. Misafir + üye (FR-062). Fatura adresi ayrı yok (null = teslimatla aynı)
- Tutarlar güncel fiyatla yeniden hesaplanır; kargo = `ShippingMethod::calculateFee(yöntem, kupon sonrası tutar)`; eşik: tutar ≥ `free_shipping_limit` → ücretsiz
- Başlangıç durumu: `cash_on_delivery` → `pending`; `credit_card`, `bank_transfer` → `awaiting_payment`
- Sonuç sayfası: oturumda `_last_order`; numara URL'de taşınmaz; sahiplik `user_id` ile kontrol (G-05)
- Sipariş sonrası sepet kalemleri silinir (`converted` ileride); hata siparişi bozmaz
- `ShippingMethod`: deleted_at yok; `delete()` kapalı → `setActive()`

### Order modeli

- Yazma: yalnızca `place()` ve `changeStatus()`; `create/update/delete` kapalı
- Fiyat: sepet bilgilendirme; siparişte DB’den yeniden oku (ad, SKU, fiyat, KDV) → anlık kopya
- Tutar (KDV dahil): `subtotal` = kalem toplamı · `vat_total` = subtotal içi KDV · `grand_total` = subtotal − discount + shipping
- Stok sipariş oluşurken düşer (FR-076 sapması, bilinçli). `payment_failed` stoğu tutar; iptalde `incoming` (`reference_type=order_cancel`) geri verir
- Geçişler (`TRANSITIONS`):
  - pending → awaiting_payment, paid, preparing, cancelled
  - awaiting_payment → paid, payment_failed, cancelled
  - payment_failed → awaiting_payment, cancelled
  - paid → preparing, cancelled
  - preparing → shipped, cancelled
  - shipped → delivered, returned
  - delivered → returned
  - cancelled, returned = son
- Başlangıç: `pending` (kapıda) veya `awaiting_payment` (kart/havale)
- `payment_status` eşleme: paid→paid, payment_failed→failed
- Kupon henüz yok → discount_total=0, coupon_id=NULL
- Misafir takip: numara + e-posta; üye: `findForUser()` yalnızca sahip

### Sipariş durumları ve FR-066 farkı (KALICI KARAR)

- FR-066 listesi: Beklemede, Ödeme Bekliyor, Ödendi, Hazırlanıyor, Kargoya Verildi, Teslim Edildi, İptal Edildi, İade Sürecinde, İade Edildi
- Kod, FR-066'dan iki noktada ayrılır:
  - `payment_failed` ("Ödeme Başarısız"), FR-066 listesine EK durumdur ve kalır. Sebep: ödeme başarısız olursa sipariş silinmez, stok tutulur, yeniden ödeme denenebilir
  - `return_requested` ("İade Sürecinde") şu aşamada EKLENMEZ. İade modülü (`return_requests`, Geliştirme Sırası 11) yazılırken eklenecek
- İade modülü geldiğinde akış: `delivered → return_requested → returned / delivered` (talep reddedilirse sipariş `delivered`'a döner). Eklerken: yeni migration (ENUM değişikliği, çalışmış migration değiştirilmez), `Order::TRANSITIONS`, etiket sabitleri ve rozet renkleri birlikte güncellenir
- Bu aşamada `delivered → returned` doğrudan geçişi geçerlidir; iade modülü ile `return_requested` üzerinden akacak şekilde değişecektir

## Kupon

- Soft delete: `deleted_at` · sorguda `IS NULL` zorunlu · fiziksel silme yok
- `used_count` korunur; limit kontrolünde COUNT(*) yerine kullanılır
- Kullanım yalnızca transaction: eklerken artır, iptalde azalt
- `coupon_usages.order_id` FK: orders sonrası ALTER; ON DELETE SET NULL

## Ödeme / Kargo

- Kart no, CVV, SKT → DB/log’a yazılmaz
- `.env` ve `config/payment.php` Claude tarafından okunmaz

## Ürün

- Soft delete; sipariş geçmişi anlık kopya sayesinde etkilenmez
- `stock_quantity` yalnızca oluşturmada başlangıç; sonra stok modülünden
- Varyantlı üründe `products.stock_quantity` yazılamaz
- SKU ürün+varyant arasında unique (`Product::skuExists`)
- Vitrin: pasif/silinmiş kategori veya marka ürünü listelenmez
- Fiyat: ondalık ayırıcı nokta (virgül yok)

## Görsel (Uploader)

- jpg/jpeg/png/webp · max 2 MB · 6000×6000 · MIME finfo + getimagesize · uzantı=MIME
- Rastgele ad; DB’ye göreli yol · klasör: products|categories|brands|banners
- Tek ana görsel; ilk otomatik ana · max 10 görsel/ürün
- Kayıt fail → yüklenen dosyalar silinir; silmede önce DB, sonra dosya

## Yönetim Paneli

- Admin route’lar `/admin` + AdminMiddleware grubu; giriş/çıkış grup dışı
- Erişim: `admin` + `staff`; tam yetki yalnızca `admin`
- Slug: boşsa addan üret + benzersizleştir; elle çakışırsa hata; UNIQUE soft-delete’li de sayar
- Soft delete: alt kategorisi / silinmemiş ürünü olan kategori veya marka silinemez → önce pasifleştir
- CRUD: POST + CSRF, silmede ortak onay modalı, hata → old flash

## Müşteri Auth

- Route: `GET/POST /giris`, `GET/POST /kayit`, `POST /cikis` (AdminMiddleware dışı)
- Kayıt: `user_type` formdan alınmaz → `createUser()` default `customer`
- Şifre: ≥8 karakter, harf+rakam, ≤72 bayt; giriş şifresi Validator’dan geçmez; flash/log’a yazılmaz
- Başarısız giriş: tek mesaj “E-posta veya şifre hatalı.”
- Login/register sonrası: session ID + CSRF yenile (fixation); `mergeGuestCart()` (hata girişi engellemez)
- `next`: sadece güvenli site-içi yol; `//`, `..`, `/giris|/kayit|/cikis` yok
- Çıkış: yalnızca POST + CSRF
- Ortak oturum: admin/staff vitrinden de girebilir; müşteri panele giremez
- FR-051 (e-posta doğrulama), FR-052 (şifre sıfırlama), rate limiting yok

## Vitrin Menü

- Ana Sayfa, Ürünler, arama (`GET /urunler?q=`), Sepet, Giriş/Kayıt veya ad/Çıkış; staff → Yönetim Paneli
- Sepet rozeti: sunucuda `CartService::count()`; `/sepet/` POST yanıtındaki `count` ile güncellenir

## Teknik Borç

- `Request::ip()` proxy düzeltmesi (rate limiting’den önce)
- Güvenlik başlıkları, SRI, 405 JSON → ayrı görev
- Müşteri rate limiting yok
- Layout `$_GET['q']` / `REQUEST_URI` doğrudan okur → ileride controller’dan geçirilecek
- Ödeme yöntemi kalıcılığı payments modelinde; admin kargo CRUD yok; `Cart::markConverted()` yok

## Diğer

- Blog kapsam dışı
- Belgeler `md/` altında esastır; kökte kopya tutulmaz
- Git dışı: `.env`, `storage/sessions/*`, `storage/logs/*` (+ `.gitkeep`)