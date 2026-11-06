<?php

/**
 * Aşıroğlu Yöresel Market - Sipariş modeli
 *
 * orders, order_items ve order_status_history tabloları (migration 012) için
 * veri erişimi sağlar.
 *
 * Kurallar (DECISIONS.md, Sipariş Kuralları ve Stok Kuralları):
 * - Sipariş numarası ASY-YYYYMMDD-NNNNNN biçimindedir; NNNNNN sipariş ID'sidir
 *   (benzersizliği ID sağlar).
 * - Sipariş başlığı, kalemler, stok düşümü ve ilk durum geçmişi kaydı AYNI
 *   transaction içinde yazılır. Stok yetersizse hiçbir şey kaydedilmez.
 * - Fiyat, KDV, ad ve SKU sepetten değil, sipariş anında veritabanından
 *   yeniden okunur (sepet fiyatı bilgilendirme amaçlıdır). Kalemler anlık
 *   kopya olarak saklanır.
 * - Fiyatlar TRY ve KDV dahildir. subtotal KDV dahil kalem toplamıdır;
 *   vat_total bu tutarın içindeki KDV'dir (genel toplama ayrıca eklenmez).
 *   grand_total = subtotal - discount_total + shipping_total.
 * - Adres anlık kopya (JSON) olarak saklanır.
 * - Her durum değişikliği order_status_history tablosuna yazılır.
 * - İptalde stok geri eklenir (stock_movements: 'incoming', kaynak 'order_cancel').
 * - Kupon uygulaması (coupon_usages, used_count) Coupon modeli yazıldığında
 *   eklenecektir; bu sınıf şimdilik indirim tutarını 0 yazar.
 * - create(), update() ve delete() kapalıdır: durum geçmişi ve stok tutarlılığı
 *   yalnızca place() ve changeStatus() ile korunur.
 *
 * Tüm sorgular PDO prepared statement ile çalışır; $_GET / $_POST kullanılmaz.
 *
 * Gereksinimler: FR-060, FR-062, FR-063, FR-064, FR-066, FR-076, FR-091, FR-092, NFR-001.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use BadMethodCallException;
use DateTimeImmutable;
use InvalidArgumentException;

final class Order extends Model
{
    /** Sayfalamada tek sayfada izin verilen en yüksek kayıt sayısı */
    private const MAX_PER_PAGE = 100;

    /** Siparişte bulunabilecek en fazla farklı kalem sayısı (sepetle aynı) */
    private const MAX_ITEMS = 50;

    /** Bir kalemde izin verilen en fazla adet (sepetle aynı) */
    private const MAX_QUANTITY = 1000;

    /** Sipariş oluşturulurken verilebilen başlangıç durumları */
    private const INITIAL_STATUSES = ['pending', 'awaiting_payment'];

    /** Admin listesinde filtrelenebilen ödeme durumları (orders.payment_status ENUM) */
    private const PAYMENT_STATUSES = ['unpaid', 'paid', 'failed', 'refunded', 'partially_refunded'];

    /** Admin sipariş notunun en fazla uzunluğu (orders.admin_note VARCHAR(1000)) */
    private const MAX_ADMIN_NOTE_LENGTH = 1000;

    /**
     * İzin verilen durum geçişleri: mevcut durum => geçilebilecek durumlar.
     * 'cancelled' ve 'returned' son durumlardır.
     */
    public const TRANSITIONS = [
        'pending'          => ['awaiting_payment', 'paid', 'preparing', 'cancelled'],
        'awaiting_payment' => ['paid', 'payment_failed', 'cancelled'],
        'payment_failed'   => ['awaiting_payment', 'cancelled'],
        'paid'             => ['preparing', 'cancelled'],
        'preparing'        => ['shipped', 'cancelled'],
        'shipped'          => ['delivered', 'returned'],
        'delivered'        => ['returned'],
        'cancelled'        => [],
        'returned'         => [],
    ];

    protected string $table = 'orders';

    /** Siparişler fiziksel silinmez; deleted_at doldurulur */
    protected bool $softDelete = true;

    /**
     * Yalnızca place() içinden parent::create() ile yazılır.
     *
     * @var list<string>
     */
    protected array $fillable = [
        'order_number',
        'user_id',
        'coupon_id',
        'customer_email',
        'customer_phone',
        'status',
        'payment_status',
        'subtotal',
        'discount_total',
        'vat_total',
        'shipping_total',
        'grand_total',
        'currency',
        'shipping_address_snapshot',
        'billing_address_snapshot',
        'customer_note',
    ];

    // -------------------------------------------------------------------------
    // Sipariş oluşturma
    // -------------------------------------------------------------------------

    /**
     * Siparişi, kalemlerini, stok düşümünü ve ilk durum kaydını tek transaction
     * içinde oluşturur. Yeni siparişin ID'sini döndürür.
     *
     * @param array{email: string, phone?: string|null} $customer Müşteri iletişim bilgisi
     * @param array<string, mixed>      $shippingAddress Teslimat adresi (anlık kopya olarak saklanır)
     * @param array<string, mixed>|null $billingAddress  Fatura adresi; null ise teslimatla aynı kabul edilir
     * @param list<array{product_id: int, variant_id: int|null, quantity: int}> $items Sipariş kalemleri
     * @param float                     $shippingTotal   Hesaplanmış kargo tutarı (TRY, KDV dahil)
     * @param int|null                  $userId          Üye siparişinde kullanıcı; misafirde null
     * @param string|null               $customerNote    Müşteri notu
     * @param string                    $initialStatus   'pending' (kapıda ödeme) veya 'awaiting_payment'
     *
     * @throws InvalidArgumentException Girdi, stok veya satılabilirlik kuralı ihlal edilirse
     */
    public function place(
        array $customer,
        array $shippingAddress,
        ?array $billingAddress,
        array $items,
        float $shippingTotal,
        ?int $userId = null,
        ?string $customerNote = null,
        string $initialStatus = 'pending'
    ): int {
        $email = mb_strtolower(trim((string) ($customer['email'] ?? '')));

        if ($email === '' || mb_strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Geçerli bir e-posta adresi zorunludur.');
        }

        $phone = isset($customer['phone']) ? trim((string) $customer['phone']) : '';

        if (mb_strlen($phone) > 20) {
            throw new InvalidArgumentException('Telefon en fazla 20 karakter olabilir.');
        }

        if ($shippingAddress === []) {
            throw new InvalidArgumentException('Teslimat adresi zorunludur.');
        }

        if (!in_array($initialStatus, self::INITIAL_STATUSES, true)) {
            throw new InvalidArgumentException('Geçersiz başlangıç durumu.');
        }

        if ($shippingTotal < 0) {
            throw new InvalidArgumentException('Kargo tutarı negatif olamaz.');
        }

        $merged = $this->mergeItems($items);

        $note = $customerNote === null ? '' : mb_substr(trim($customerNote), 0, 1000);

        return (int) $this->transaction(
            function () use (
                $email,
                $phone,
                $shippingAddress,
                $billingAddress,
                $merged,
                $shippingTotal,
                $userId,
                $note,
                $initialStatus
            ): int {
                // 1) Kalemleri güncel fiyat ve bilgilerle çöz (stok son kez record() içinde kilitlenir)
                $lines    = [];
                $subtotal = 0.0;
                $vatTotal = 0.0;

                foreach ($merged as $item) {
                    $line = $this->buildLine($item['product_id'], $item['variant_id'], $item['quantity']);

                    $subtotal += $line['line_total'];
                    $vatTotal += $line['vat_amount'];
                    $lines[]   = $line;
                }

                $subtotal   = round($subtotal, 2);
                $vatTotal   = round($vatTotal, 2);
                $grandTotal = round($subtotal + $shippingTotal, 2);

                // 2) Sipariş başlığı: numara geçici, ID alınınca kesinleşir
                $orderId = parent::create([
                    'order_number'              => 'TMP-' . bin2hex(random_bytes(8)),
                    'user_id'                   => $userId,
                    'coupon_id'                 => null,
                    'customer_email'            => $email,
                    'customer_phone'            => $phone === '' ? null : $phone,
                    'status'                    => $initialStatus,
                    'payment_status'            => 'unpaid',
                    'subtotal'                  => number_format($subtotal, 2, '.', ''),
                    'discount_total'            => '0.00',
                    'vat_total'                 => number_format($vatTotal, 2, '.', ''),
                    'shipping_total'            => number_format(round($shippingTotal, 2), 2, '.', ''),
                    'grand_total'               => number_format($grandTotal, 2, '.', ''),
                    'currency'                  => 'TRY',
                    'shipping_address_snapshot' => $this->encodeSnapshot($shippingAddress),
                    'billing_address_snapshot'  => $billingAddress === null ? null : $this->encodeSnapshot($billingAddress),
                    'customer_note'             => $note === '' ? null : $note,
                ]);

                $orderNumber = sprintf('ASY-%s-%06d', date('Ymd'), $orderId);

                $this->execute(
                    'UPDATE orders SET order_number = ? WHERE id = ?',
                    [$orderNumber, $orderId]
                );

                // 3) Kalemler (anlık kopya) ve stok düşümü
                $stock = new StockMovement();

                foreach ($lines as $line) {
                    $this->execute(
                        'INSERT INTO order_items (order_id, product_id, product_variant_id, product_name,'
                        . ' product_sku, unit_price, quantity, vat_rate, vat_amount, discount_amount, line_total)'
                        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $orderId,
                            $line['product_id'],
                            $line['variant_id'],
                            $line['name'],
                            $line['sku'],
                            number_format($line['unit_price'], 2, '.', ''),
                            $line['quantity'],
                            number_format($line['vat_rate'], 2, '.', ''),
                            number_format($line['vat_amount'], 2, '.', ''),
                            '0.00',
                            number_format($line['line_total'], 2, '.', ''),
                        ]
                    );

                    // Yetersiz stokta InvalidArgumentException fırlatır; transaction geri alınır
                    $stock->record(
                        $line['product_id'],
                        $line['variant_id'],
                        'outgoing',
                        $line['quantity'],
                        null,
                        $userId,
                        'order',
                        $orderId
                    );
                }

                // 4) İlk durum kaydı
                $this->addHistory($orderId, null, $initialStatus, $userId, 'Sipariş oluşturuldu.');

                return $orderId;
            }
        );
    }

    // -------------------------------------------------------------------------
    // Durum değişikliği
    // -------------------------------------------------------------------------

    /**
     * Sipariş durumunu değiştirir, geçmişe yazar ve gerekirse ödeme durumunu
     * günceller. İptalde stok geri eklenir. Hepsi tek transaction içindedir.
     *
     * @param int|null    $changedBy Değişikliği yapan kullanıcı; sistem işleminde (callback) null
     * @param string|null $note      Geçmişe yazılacak not
     *
     * @return bool Sipariş bulunduysa ve durum değiştiyse true; sipariş yoksa false
     *
     * @throws InvalidArgumentException Durum veya geçiş geçersizse
     */
    public function changeStatus(int $orderId, string $newStatus, ?int $changedBy = null, ?string $note = null): bool
    {
        if (!array_key_exists($newStatus, self::TRANSITIONS)) {
            throw new InvalidArgumentException('Geçersiz sipariş durumu.');
        }

        $cleanNote = $note === null ? '' : mb_substr(trim($note), 0, 500);

        return (bool) $this->transaction(
            function () use ($orderId, $newStatus, $changedBy, $cleanNote): bool {
                $order = $this->fetchOne(
                    'SELECT id, order_number, status FROM orders'
                    . ' WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
                    [$orderId]
                );

                if ($order === null) {
                    return false;
                }

                $oldStatus = (string) $order['status'];

                if (!in_array($newStatus, self::TRANSITIONS[$oldStatus] ?? [], true)) {
                    throw new InvalidArgumentException(
                        'Sipariş durumu "' . $oldStatus . '" iken "' . $newStatus . '" durumuna geçirilemez.'
                    );
                }

                $paymentStatus = match ($newStatus) {
                    'paid'           => 'paid',
                    'payment_failed' => 'failed',
                    default          => null,
                };

                if ($paymentStatus === null) {
                    $this->execute('UPDATE orders SET status = ? WHERE id = ?', [$newStatus, $orderId]);
                } else {
                    $this->execute(
                        'UPDATE orders SET status = ?, payment_status = ? WHERE id = ?',
                        [$newStatus, $paymentStatus, $orderId]
                    );
                }

                if ($newStatus === 'cancelled') {
                    $this->restock($orderId, (string) $order['order_number'], $changedBy);
                }

                $this->addHistory($orderId, $oldStatus, $newStatus, $changedBy, $cleanNote === '' ? null : $cleanNote);

                return true;
            }
        );
    }

    // -------------------------------------------------------------------------
    // Sorgular
    // -------------------------------------------------------------------------

    /**
     * Sipariş numarasına göre siparişi getirir.
     *
     * @return array<string, mixed>|null
     */
    public function findByNumber(string $orderNumber): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM orders WHERE order_number = ? AND deleted_at IS NULL',
            [trim($orderNumber)]
        );
    }

    /**
     * Misafir sipariş takibi: sipariş numarası ve e-posta birlikte eşleşmelidir.
     *
     * @return array<string, mixed>|null
     */
    public function findByNumberAndEmail(string $orderNumber, string $email): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM orders WHERE order_number = ? AND customer_email = ? AND deleted_at IS NULL',
            [trim($orderNumber), mb_strtolower(trim($email))]
        );
    }

    /**
     * Siparişi yalnızca sahibi olan kullanıcı için getirir (başka kullanıcının
     * siparişine erişimi engeller, G-05).
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $orderId, int $userId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM orders WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$orderId, $userId]
        );
    }

    /**
     * Sipariş kalemlerini döndürür.
     *
     * @return list<array<string, mixed>>
     */
    public function getItems(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC',
            [$orderId]
        );
    }

    /**
     * Durum geçmişini eskiden yeniye döndürür (değiştiren kullanıcı adıyla).
     *
     * @return list<array<string, mixed>>
     */
    public function getHistory(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT h.*, CONCAT(u.first_name, \' \', u.last_name) AS user_name'
            . ' FROM order_status_history h'
            . ' LEFT JOIN users u ON u.id = h.changed_by'
            . ' WHERE h.order_id = ?'
            . ' ORDER BY h.id ASC',
            [$orderId]
        );
    }

    /**
     * Kullanıcının siparişleri (en yeni önce), sayfalı.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     perPage: int,
     *     totalPages: int
     * }
     */
    public function paginateByUser(int $userId, int $page = 1, int $perPage = 10): array
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $total = (int) $this->fetchValue(
            'SELECT COUNT(*) FROM orders WHERE user_id = ? AND deleted_at IS NULL',
            [$userId]
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, $page), $totalPages);
        $offset     = ($page - 1) * $perPage;

        // LIMIT ve OFFSET tamsayıya çevrilmiş değerlerdir, güvenle eklenir
        $items = $this->fetchAll(
            'SELECT id, order_number, status, payment_status, grand_total, currency, created_at,'
            . ' (SELECT COALESCE(SUM(quantity), 0) FROM order_items i WHERE i.order_id = orders.id) AS item_count'
            . ' FROM orders'
            . ' WHERE user_id = ? AND deleted_at IS NULL'
            . ' ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            [$userId]
        );

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    // -------------------------------------------------------------------------
    // Yönetim paneli (FR-091, FR-092)
    // -------------------------------------------------------------------------

    /**
     * Yönetim paneli sipariş listesi (en yeni önce), filtreli ve sayfalı.
     *
     * $filters anahtarları (hepsi isteğe bağlı; geçersiz değer sessizce yok sayılır):
     *   status          sipariş durumu (TRANSITIONS anahtarlarından biri)
     *   payment_status  ödeme durumu
     *   q               sipariş numarası, e-posta veya telefonda arama (en fazla 100 karakter)
     *   date_from       YYYY-AA-GG (bu gün dahil)
     *   date_to         YYYY-AA-GG (bu gün dahil)
     *
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     perPage: int,
     *     totalPages: int
     * }
     */
    public function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $where  = ['deleted_at IS NULL'];
        $params = [];

        $status = (string) ($filters['status'] ?? '');

        if ($status !== '' && array_key_exists($status, self::TRANSITIONS)) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }

        $paymentStatus = (string) ($filters['payment_status'] ?? '');

        if ($paymentStatus !== '' && in_array($paymentStatus, self::PAYMENT_STATUSES, true)) {
            $where[]  = 'payment_status = ?';
            $params[] = $paymentStatus;
        }

        $query = mb_substr(trim((string) ($filters['q'] ?? '')), 0, 100);

        if ($query !== '') {
            // LIKE joker karakterleri (% _ \) arama metninde düz karakter sayılır
            $like     = '%' . addcslashes($query, '%_\\') . '%';
            $where[]  = '(order_number LIKE ? OR customer_email LIKE ? OR customer_phone LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null);

        if ($dateFrom !== null) {
            $where[]  = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = $this->normalizeDate($filters['date_to'] ?? null);

        if ($dateTo !== null) {
            // Bitiş günü dahil: ertesi günün başlangıcından küçük
            $where[]  = 'created_at < ?';
            $params[] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        $condition = implode(' AND ', $where);

        $total = (int) $this->fetchValue('SELECT COUNT(*) FROM orders WHERE ' . $condition, $params);

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, $page), $totalPages);
        $offset     = ($page - 1) * $perPage;

        // LIMIT ve OFFSET tamsayıya çevrilmiş değerlerdir, güvenle eklenir
        $items = $this->fetchAll(
            'SELECT id, order_number, customer_email, status, payment_status, grand_total, currency, created_at,'
            . ' (SELECT COALESCE(SUM(quantity), 0) FROM order_items i WHERE i.order_id = orders.id) AS item_count'
            . ' FROM orders'
            . ' WHERE ' . $condition
            . ' ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Duruma göre sipariş sayıları (silinmemiş siparişler). Listedeki durum
     * sekmeleri içindir; hiç siparişi olmayan durum 0 döner.
     *
     * @return array<string, int> durum => adet (tüm geçerli durumlar bulunur)
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_keys(self::TRANSITIONS), 0);

        $rows = $this->fetchAll(
            'SELECT status, COUNT(*) AS total FROM orders WHERE deleted_at IS NULL GROUP BY status'
        );

        foreach ($rows as $row) {
            $status = (string) $row['status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Yönetici notunu yazar (orders.admin_note). Müşteriye gösterilmez; bu yüzden
     * durum geçmişine değil, ayrı alana yazılır. Boş metin notu temizler.
     *
     * @return bool Sipariş bulunduysa true; bulunamazsa false
     *
     * @throws InvalidArgumentException Not çok uzunsa
     */
    public function saveAdminNote(int $orderId, ?string $note): bool
    {
        $clean = $note === null ? '' : trim($note);

        if (mb_strlen($clean) > self::MAX_ADMIN_NOTE_LENGTH) {
            throw new InvalidArgumentException(
                'Sipariş notu en fazla ' . self::MAX_ADMIN_NOTE_LENGTH . ' karakter olabilir.'
            );
        }

        $this->execute(
            'UPDATE orders SET admin_note = ? WHERE id = ? AND deleted_at IS NULL',
            [$clean === '' ? null : $clean, $orderId]
        );

        // MySQL değer değişmediyse 0 satır bildirir; varlık ayrıca doğrulanır
        return $this->fetchValue(
            'SELECT 1 FROM orders WHERE id = ? AND deleted_at IS NULL',
            [$orderId]
        ) !== null;
    }

    /** "YYYY-AA-GG" geçerliyse aynısını, değilse null döndürür. */
    private function normalizeDate(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
    }

    // -------------------------------------------------------------------------
    // Yazma yasakları
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     *
     * @throws BadMethodCallException Her zaman
     */
    public function create(array $data): int
    {
        throw new BadMethodCallException('Order::create() kapalıdır. place() kullanın.');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws BadMethodCallException Her zaman
     */
    public function update(int $id, array $data): bool
    {
        throw new BadMethodCallException('Order::update() kapalıdır. changeStatus() kullanın.');
    }

    /**
     * @throws BadMethodCallException Her zaman
     */
    public function delete(int $id): bool
    {
        throw new BadMethodCallException('Siparişler silinemez; iptal için changeStatus() kullanın.');
    }

    // -------------------------------------------------------------------------
    // Dahili yardımcılar
    // -------------------------------------------------------------------------

    /**
     * Kalemleri doğrular ve aynı ürün + varyantı tek kalemde birleştirir.
     *
     * @param list<array{product_id: int, variant_id: int|null, quantity: int}> $items
     *
     * @return list<array{product_id: int, variant_id: int|null, quantity: int}>
     *
     * @throws InvalidArgumentException Kalem geçersizse
     */
    private function mergeItems(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('Sipariş en az bir kalem içermelidir.');
        }

        $merged = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variantId = isset($item['variant_id']) && (int) $item['variant_id'] > 0
                ? (int) $item['variant_id']
                : null;
            $quantity  = (int) ($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                throw new InvalidArgumentException('Geçersiz sipariş kalemi.');
            }

            $key = $productId . ':' . ($variantId ?? 0);

            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += $quantity;
            } else {
                $merged[$key] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity'   => $quantity,
                ];
            }
        }

        if (count($merged) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Siparişte en fazla ' . self::MAX_ITEMS . ' farklı ürün olabilir.');
        }

        foreach ($merged as $item) {
            if ($item['quantity'] > self::MAX_QUANTITY) {
                throw new InvalidArgumentException(
                    'Bir üründen en fazla ' . self::MAX_QUANTITY . ' adet sipariş edilebilir.'
                );
            }
        }

        return array_values($merged);
    }

    /**
     * Tek kalemin sipariş anındaki bilgilerini hazırlar: ad, SKU, güncel fiyat,
     * KDV oranı ve tutarlar. Satılabilirlik ve stok Cart::resolveSellable()
     * ile denetlenir (aynı kurallar sepette de geçerlidir).
     *
     * @return array{
     *     product_id: int, variant_id: int|null, name: string, sku: string,
     *     quantity: int, unit_price: float, vat_rate: float,
     *     vat_amount: float, line_total: float
     * }
     *
     * @throws InvalidArgumentException Ürün satışta değilse veya stok yetmiyorsa
     */
    private function buildLine(int $productId, ?int $variantId, int $quantity): array
    {
        $cart = new Cart();
        $info = $cart->resolveSellable($productId, $variantId);

        $product = (new Product())->find($productId);

        if ($product === null) {
            throw new InvalidArgumentException('Ürün satışta değil.');
        }

        $name = (string) $product['name'];
        $sku  = (string) $product['sku'];

        if ($variantId !== null) {
            $variant = (new ProductVariant())->findForProduct($variantId, $productId);

            if ($variant === null) {
                throw new InvalidArgumentException('Seçilen seçenek satışta değil.');
            }

            $name .= ' - ' . (string) $variant['variant_name'];
            $sku   = (string) $variant['sku'];
        }

        // Stok yetersizse anlaşılır mesajla reddet (kesin kontrol record() içinde kilitle yapılır)
        $cart->assertQuantity($quantity, $info['stock']);

        $unitPrice = round($info['price'], 2);
        $lineTotal = round($unitPrice * $quantity, 2);
        $vatRate   = (float) $product['vat_rate'];

        // Fiyat KDV dahildir: KDV tutarı = toplam - toplam / (1 + oran/100)
        $vatAmount = round($lineTotal - $lineTotal / (1 + $vatRate / 100), 2);

        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'name'       => mb_substr($name, 0, 255),
            'sku'        => mb_substr($sku, 0, 64),
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
            'vat_rate'   => $vatRate,
            'vat_amount' => $vatAmount,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * İptal edilen siparişin stoğunu geri ekler. Ürünü/varyantı artık
     * stok yazmaya uygun olmayan kalemler atlanır ve loglanır; iptal engellenmez.
     */
    private function restock(int $orderId, string $orderNumber, ?int $changedBy): void
    {
        $stock    = new StockMovement();
        $products = new Product();

        foreach ($this->getItems($orderId) as $item) {
            if ($item['product_id'] === null) {
                continue; // Ürün fiziksel silinmiş
            }

            $productId = (int) $item['product_id'];
            $variantId = $item['product_variant_id'] === null ? null : (int) $item['product_variant_id'];

            // Varyantlı ürünün stoğu varyantsız yazılamaz; varyant silinmişse atla
            if ($variantId === null && $products->hasVariants($productId)) {
                $this->logSkippedRestock($orderNumber, $productId, 'varyant bulunamadı');

                continue;
            }

            try {
                $stock->record(
                    $productId,
                    $variantId,
                    'incoming',
                    (int) $item['quantity'],
                    'Sipariş iptali: ' . $orderNumber,
                    $changedBy,
                    'order_cancel',
                    $orderId
                );
            } catch (InvalidArgumentException $e) {
                // Örn. ürün soft delete ile silinmiş: iptal sürer, kalem loglanır
                $this->logSkippedRestock($orderNumber, $productId, $e->getMessage());
            }
        }
    }

    private function logSkippedRestock(string $orderNumber, int $productId, string $reason): void
    {
        error_log(sprintf(
            '[%s] Order: iptalde stok geri eklenemedi (sipariş %s, ürün %d): %s',
            date('Y-m-d H:i:s'),
            $orderNumber,
            $productId,
            $reason
        ));
    }

    /**
     * Durum geçmişine kayıt ekler (yalnızca eklenir).
     */
    private function addHistory(int $orderId, ?string $oldStatus, string $newStatus, ?int $changedBy, ?string $note): void
    {
        $this->execute(
            'INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, note)'
            . ' VALUES (?, ?, ?, ?, ?)',
            [$orderId, $oldStatus, $newStatus, $changedBy, $note]
        );
    }

    /**
     * Adresi anlık kopya olarak JSON metnine çevirir.
     *
     * @param array<string, mixed> $address
     */
    private function encodeSnapshot(array $address): string
    {
        return json_encode($address, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}