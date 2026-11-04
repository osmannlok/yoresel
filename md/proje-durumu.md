# Proje Durumu — Aşıroğlu Yöresel Market

> Güncel durum kaydı. Kararlar: `DECISIONS.md` · Migration sırası: `DATABASE-DEPENDENCIES.md` · Test: `TEST-COMMANDS.md`

## 1. Özet

| **Proje** | Aşıroğlu Yöresel Market — yöresel gıda e-ticaret |
| **Stack** | PHP 8.2+, Custom MVC, MySQL 8/MariaDB, PDO, Bootstrap 5, Vanilla JS, Fetch |
| **Standart** | OOP, PSR-12, Türkçe yorumlar |
| **Çalışma** | Her görev tek dosya → rapor → onay → dosya. `DEVAM` olmadan sonraki dosyaya geçilmez |

## 2. Tamamlanan

**Çekirdek**
- [x] MVC: App, Router (middleware/group), Controller, Model, Database, Request, Response, Session, Csrf, Validator
- [x] `public/index.php`, `config/{config,database}.php`, `HomeController`, `layouts/main.php`
- [x] Migration 001–018 + `migrate.php` (checksum; gerçek MySQL’de henüz çalıştırılmadı)
- [x] `DECISIONS.md`, `DATABASE-DEPENDENCIES.md`, `TEST-COMMANDS.md`

**Admin**
- [x] Auth: User, Auth, AdminMiddleware, Admin/AuthController, admin login, DashboardController + view
- [x] Kategori/Marka: Model + Controller + index/form + görsel/logo (Uploader)
- [x] Ürün: Product, ProductImage, ProductVariant + Controller’lar + views + 6’şar route
- [x] Uploader (jpg/png/webp, 2 MB, 6000 px)
- [x] Stok: StockMovement, StockController, stock/{index,form,history}
- [x] Dashboard: ürün sayısı, kritik stok (FR-097)
- [x] Başlangıç stoğu > 0 → stock_movements (aynı transaction)

**Vitrin**
- [x] Cart + CartService + CartController + cart/index + 5 sepet route
- [x] Product::paginateStorefront + vitrin ProductController + products/{index,show}
- [x] layouts/main: menü (Ürünler, Sepet rozetli, arama, Giriş/Kayıt/Çıkış, Yönetim)
- [x] Müşteri Auth: AuthController, auth/{login,register}, 5 route; giriş/kayıtta mergeGuestCart

**Sipariş / Checkout**
- [x] Order modeli: place, changeStatus, findByNumber, getItems, getHistory, paginateByUser
- [x] ShippingMethod modeli (delete() kapalı → setActive; calculateFee, remainingForFreeShipping)
- [x] CheckoutService (quote, validate, placeOrder), CheckoutController, checkout/{index,complete}
- [x] App.php: /odeme, /odeme/ozet, POST /odeme, /siparis/tamamlandi; sepette "Ödemeye Geç" bağlantısı

<!-- YENİ -->
**Hesabım: siparişler (FR-055, FR-069 kısmen, G-05)**
- [x] `AccountOrderController` (index, show): giriş zorunlu, misafir `/giris?next=...` adresine yönlenir; sipariş `Order::findForUser()` ile sahibine bağlı
- [x] `account/orders/{index,show}` view'ları: liste (sayfalı, durum/ödeme rozeti), detay (kalemler, toplamlar, adres, durum geçmişi; personel adı gösterilmez)
- [x] `App.php`: `GET /hesabim/siparisler`, `GET /hesabim/siparisler/{id:\d+}` (AdminMiddleware dışı)
- [x] `layouts/main.php`: üye menüsünde "Siparişlerim" bağlantısı (aktif işaretli)

- [x] `app/Services/Payment/PaytrGateway.php' yazıldı.
- [x] `app/Services/Payment/PaymentGatewayInterface.php' yazıldı.
- [x] `app/Models/Coupon.php' yazıldı.
- [x] `app/Models/Shipment.php' yazıldı.

## 3. Migration’lar (001–018)

| No | Tablolar |
|---|---|
| 001–004 | users, addresses, categories, brands |
| 005–007 | products, product_images, product_variants |
| 008 | attributes, attribute_values, product_attributes |
| 009 | stock_movements |
| 010 | coupons (+deleted_at), coupon_usages |
| 011 | carts (user_id NOT NULL), cart_items |
| 012 | orders (+customer_email/phone), order_items, order_status_history |
| 013–014 | payments, payment_logs · shipping_methods, shipments |
| 015–016 | return_requests · reviews, wishlists |
| 017–018 | banners, pages, newsletter, contact · settings, activity_logs |

**Toplam:** 18 migration, 31 tablo (+ schema_migrations)

## 4. Modeller & Ana API

| Model | Not |
|---|---|
| Category | soft delete, tree, cycle check, slugExists |
| Brand | soft delete, getActive, slugExists |
| User | create() kapalı → createUser(); password_hash asla dönmez |
| Auth | oturumda sadece user_id; isAdmin / canAccessAdmin |
| Product | soft delete, findBySlug (FR-033), paginate + paginateStorefront, currentPrice |
| ProductImage | tek is_main, reorder, max 10 |
| ProductVariant | delete() kapalı → setActive(); stok sadece oluştururken |
| StockMovement | sadece record(); FOR UPDATE + transaction |
| Cart | üye sepeti; resolveSellable (stok/varyant/vitrin) |
| CartService | misafir (session) + üye; mergeGuestCart |
| Order | place, changeStatus, sorgular (findForUser, paginateByUser Hesabım'da kullanılıyor) |
| ShippingMethod | delete() kapalı → setActive(); calculateFee, remainingForFreeShipping |
| CheckoutService | quote/validate/placeOrder; güncel fiyatla kargo hesabı; başarıdan sonra sepet boşalır |
| Coupon | kupon doğrulama/kullanım; `used_count` transaction’da |
| Payment | ödeme kaydı ve ödeme durumu işlemleri |
|Shipment | delete/create/update kapalı; createForOrder, markHandedToCargo, updateTracking, changeStatus (FOR UPDATE); getForCustomer (FR-069) satırı.

**Uploader:** products|categories|brands|banners · finfo + getimagesize · rastgele ad

## 5. Sıradaki



3. Hesabım profil/adres sayfaları (FR-053, FR-054)
4. İade → E-posta → Raporlama

## 6. Kritik Kararlar (özet)

- Misafir sepeti **sadece session**; `carts.user_id NOT NULL`
- Stok sipariş oluşurken düşer (FR-076 sapması, DECISIONS’ta kayıtlı)
- Kupon: used_count transaction’da; soft delete; COUNT(*) yerine used_count
- Yetki: oturumda sadece ID; rol her istekte DB’den
- SKU ürün+varyant arasında unique; soft-delete’li kayıtlar da sayılır
- Fiyat: price>0, discount_price<price; ondalık ayırıcı nokta
- Vitrin görünürlüğü (FR-033): pasif/silinmiş kategori/marka ürünü listelenmez
- Varyant silinmez (`is_active=0`); stok sadece oluştururken yazılır
- Sepet: max 50 kalem, kalem başına max 1000 adet; fiyat bilgilendirme, siparişte yeniden hesap
- `next` parametresi: sadece güvenli site-içi yol
- Admin/vitrin oturumu ortak (`_auth_user_id`); müşteri `/admin/giris` yapamaz
- Checkout: kargo eşiği kupon sonrası tutara göre (kupon yokken subtotal); sonuç sayfası `_last_order` oturumuyla açılır, numara URL'de yok
- Başlangıç durumu: kapıda ödeme `pending`; kart/havale `awaiting_payment`
- Kargo yöntemi silinmez, yalnızca aktif/pasif yapılır. `code` oluşturulduktan sonra değişmez. Son aktif yöntem kapatılamaz.
- `shipping/index.php` ve `form.php` içinde `$this->csrfField()` kullanıldı. `variants/index.php` ile aynı yöntem olup olmadığı kontrol edilecek.
- `index.php` Bootstrap Icons (`bi`) sınıfı kullanıyor. Layout'ta yüklü olup olmadığı kontrol edilecek.
<!-- YENİ -->
- Hesabım siparişleri: yalnızca üye; sahiplik `Order::findForUser()` ile. Başkasının sipariş ID'si ile olmayan ID aynı yanıtı verir (G-05). Müşteriye durum geçmişinde yalnızca durum, not ve tarih gösterilir (personel adı view'a taşınmaz). Misafir siparişi (`user_id` NULL) üye listesinde görünmez.

Tam liste → `DECISIONS.md`

## 7. Riskler

**Kritik**
- Migration 010–018 gerçek DB’de çalıştırılmadı → sepet/sipariş test edilemez
- `shipped/delivered` → `returned` stoğu otomatik geri eklemez (iade modülü)
- Ödemesi alınmış sipariş iptalinde iade Order’ın işi değil
- Admin + müşteri girişinde rate limiting yok; `Request::ip()` proxy düzeltmesi öncesi sunucu açılmamalı
- `.env` sohbette paylaşıldı → DB şifresi değiştirilmeli
- İkinci spesifikasyon seti (`GEREKSİNİMLER.MD` vb.) mevcut şema ile çelişiyor; hangi belge geçerli netleşmeli
- `AuthMiddleware.php` içinde AdminMiddleware kopyası var → silinmeli
- DECISIONS.md “Sepet Kuralları” bölümü eski kuralı içeriyor (session_id)

**Orta**
- FR-021 varyant kritik stok eşiği yok; ürün eşiği kullanılıyor
- E-posta doğrulama (FR-051) ve şifremi unuttum (FR-052) yok
- `layouts/main.php` doğrudan `$_GET`/`$_SERVER` okuyor
<!-- YENİ -->
- Hesabım'da profil ve adres sayfaları henüz yok (yalnızca "Siparişlerim" var)
- Kargo takip bilgisi (FR-069) sipariş detayında gösterilmiyor; shipments modeli gelince eklenecek
- Misafir sipariş takibi (numara + e-posta, `findByNumberAndEmail`) için sayfa yok
- Ödeme yöntemi orders'ta saklanmıyor; payments modeli gelene kadar yalnızca oturumda/dönüşte
- Sipariş sonrası sepet `converted` yapılmıyor, kalemler silinir (Cart::markConverted yok)
- Onay e-postası metni var ama e-posta altyapısı yok (FR-065)
- Fatura adresi ayrı alınmıyor

**Düşük**
- CSP / güvenlik başlıkları, Bootstrap SRI, 405 JSON yok
- Admin girişleri activity_logs’a yazılmıyor

## 8. Son Güncelleme

2026-11-02 — `Coupon.php` ve `Payment.php` modelleri yazıldı. Sıradaki dosya: `PaymentGatewayInterface.php`. Yeni dosyaların `php -l` + elle testleri bekliyor.