<?php

/**
 * Aşıroğlu Yöresel Market - Yönetim paneli sipariş listesi view'ı
 *
 * Admin\OrderController::index() tarafından render edilir ve
 * layouts/admin.php içine yerleştirilir.
 *
 * Değişkenler:
 *   $result              (zorunlu) ['items' => list, 'total' => int, 'page' => int,
 *                                   'perPage' => int, 'totalPages' => int]
 *   $counts              (isteğe bağlı) durum => adet (durum sekmeleri için)
 *   $filters             (isteğe bağlı) status, payment_status, q, date_from, date_to
 *   $statusLabels        sipariş durumu => görünen ad
 *   $paymentStatusLabels ödeme durumu => görünen ad
 *   $basePath            (isteğe bağlı) /admin/siparisler
 *
 * Kararlar:
 * - Durum sekmeleri bağlantıdır (GET); arama, ödeme ve tarih filtreleri sekme
 *   değişince korunur, sayfa numarası 1'e döner. Filtre formu geçerli sekmeyi
 *   gizli alanla korur.
 * - Liste yalnızca okur; durum değişikliği sipariş detay sayfasındadır.
 * - Tüm çıktılar $this->e() ile kaçırılır.
 *
 * Kullanılabilir yardımcılar: $this->e(), $this->url()
 *
 * Gereksinimler: FR-066, FR-091, FR-092, NFR-003, NFR-005.
 */

declare(strict_types=1);

$basePath = isset($basePath) ? (string) $basePath : '/admin/siparisler';

$items      = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
$total      = (int) ($result['total'] ?? 0);
$page       = max(1, (int) ($result['page'] ?? 1));
$totalPages = max(1, (int) ($result['totalPages'] ?? 1));

$counts              = isset($counts) && is_array($counts) ? $counts : [];
$filters             = isset($filters) && is_array($filters) ? $filters : [];
$statusLabels        = isset($statusLabels) && is_array($statusLabels) ? $statusLabels : [];
$paymentStatusLabels = isset($paymentStatusLabels) && is_array($paymentStatusLabels) ? $paymentStatusLabels : [];

$fStatus  = (string) ($filters['status'] ?? '');
$fPayment = (string) ($filters['payment_status'] ?? '');
$fQuery   = (string) ($filters['q'] ?? '');
$fFrom    = (string) ($filters['date_from'] ?? '');
$fTo      = (string) ($filters['date_to'] ?? '');

// Sekme dışındaki filtrelerden en az biri dolu mu (Temizle bağlantısı için)
$hasExtraFilter = $fPayment !== '' || $fQuery !== '' || $fFrom !== '' || $fTo !== '';
$hasFilter      = $hasExtraFilter || $fStatus !== '';

/** Sipariş durumu => Bootstrap rozet rengi */
$statusColors = [
    'pending'          => 'secondary',
    'awaiting_payment' => 'warning',
    'paid'             => 'info',
    'preparing'        => 'info',
    'shipped'          => 'primary',
    'delivered'        => 'success',
    'cancelled'        => 'dark',
    'payment_failed'   => 'danger',
    'returned'         => 'secondary',
];

/** Tutarı Türkçe biçimde yazar: 1.250,50 ₺ */
$money = static fn (mixed $amount): string => number_format((float) $amount, 2, ',', '.') . ' ₺';

/** "2026-11-01 14:30:00" => "01.11.2026 14:30"; çözülemezse ham metin */
$dateText = static function (mixed $value): string {
    $time = strtotime((string) $value);

    return $time === false ? (string) $value : date('d.m.Y H:i', $time);
};

/**
 * Filtreleri koruyan liste bağlantısı üretir. $override anahtarları filtrelerin
 * üzerine yazılır; boş değerler adrese eklenmez.
 *
 * @param array<string, string|int> $override
 */
$listUrl = function (array $override = []) use ($basePath, $fStatus, $fPayment, $fQuery, $fFrom, $fTo): string {
    $query = array_merge([
        'status'         => $fStatus,
        'payment_status' => $fPayment,
        'q'              => $fQuery,
        'date_from'      => $fFrom,
        'date_to'        => $fTo,
    ], $override);

    $query = array_filter($query, static fn (mixed $value): bool => $value !== '' && $value !== 0);

    return $this->url($basePath . ($query === [] ? '' : '?' . http_build_query($query)));
};

// Sayfalama penceresi: geçerli sayfa ± 2
$windowStart = max(1, $page - 2);
$windowEnd   = min($totalPages, $page + 2);

$allCount = array_sum(array_map('intval', $counts));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 fw-bold mb-0">Siparişler</h1>
        <p class="text-muted small mb-0">
            <?= $hasFilter ? 'Filtreyle eşleşen' : 'Toplam' ?> <?= $this->e((string) $total) ?> sipariş
        </p>
    </div>
</div>

<?php if ($counts !== []): ?>
    <ul class="nav nav-pills flex-nowrap overflow-auto gap-1 mb-3 pb-1" aria-label="Sipariş durumu">
        <li class="nav-item">
            <a class="nav-link<?= $fStatus === '' ? ' active' : '' ?> text-nowrap"
               href="<?= $this->e($listUrl(['status' => '', 'page' => 0])) ?>"
               <?= $fStatus === '' ? 'aria-current="page"' : '' ?>>
                Tümü <span class="badge text-bg-light border ms-1"><?= $this->e((string) $allCount) ?></span>
            </a>
        </li>
        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
            <?php $isCurrent = $fStatus === (string) $statusKey; ?>
            <li class="nav-item">
                <a class="nav-link<?= $isCurrent ? ' active' : '' ?> text-nowrap"
                   href="<?= $this->e($listUrl(['status' => (string) $statusKey, 'page' => 0])) ?>"
                   <?= $isCurrent ? 'aria-current="page"' : '' ?>>
                    <?= $this->e($statusLabel) ?>
                    <span class="badge text-bg-light border ms-1"><?= $this->e((string) (int) ($counts[$statusKey] ?? 0)) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="get" action="<?= $this->e($this->url($basePath)) ?>" class="row g-2 mb-3" role="search">
    <?php if ($fStatus !== ''): ?>
        <input type="hidden" name="status" value="<?= $this->e($fStatus) ?>">
    <?php endif; ?>

    <div class="col-12 col-md-6 col-lg-4">
        <label for="orderSearch" class="visually-hidden">Sipariş ara</label>
        <input type="search" class="form-control" id="orderSearch" name="q"
               value="<?= $this->e($fQuery) ?>" maxlength="100"
               placeholder="Sipariş no, e-posta veya telefon">
    </div>
    <div class="col-12 col-md-6 col-lg-2">
        <label for="orderPayment" class="visually-hidden">Ödeme durumu</label>
        <select class="form-select" id="orderPayment" name="payment_status">
            <option value="">Tüm ödemeler</option>
            <?php foreach ($paymentStatusLabels as $paymentKey => $paymentLabel): ?>
                <option value="<?= $this->e((string) $paymentKey) ?>"<?= $fPayment === (string) $paymentKey ? ' selected' : '' ?>>
                    <?= $this->e($paymentLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-lg-2">
        <label for="orderFrom" class="visually-hidden">Başlangıç tarihi</label>
        <input type="date" class="form-control" id="orderFrom" name="date_from"
               value="<?= $this->e($fFrom) ?>" title="Başlangıç tarihi">
    </div>
    <div class="col-6 col-lg-2">
        <label for="orderTo" class="visually-hidden">Bitiş tarihi</label>
        <input type="date" class="form-control" id="orderTo" name="date_to"
               value="<?= $this->e($fTo) ?>" title="Bitiş tarihi">
    </div>
    <div class="col-12 col-lg-auto d-flex gap-2">
        <button type="submit" class="btn btn-outline-success">Filtrele</button>
        <?php if ($hasFilter): ?>
            <a href="<?= $this->e($this->url($basePath)) ?>" class="btn btn-outline-secondary">Temizle</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($items === []): ?>
    <div class="alert alert-info mb-0" role="note">
        <?= $hasFilter
            ? 'Filtrelerinizle eşleşen sipariş bulunamadı.'
            : 'Henüz sipariş yok.' ?>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Sipariş No</th>
                        <th scope="col">Tarih</th>
                        <th scope="col">Müşteri</th>
                        <th scope="col" class="text-end">Ürün</th>
                        <th scope="col" class="text-end">Tutar</th>
                        <th scope="col">Ödeme</th>
                        <th scope="col">Durum</th>
                        <th scope="col" class="text-end">İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $row): ?>
                        <?php
                        $orderId       = (int) $row['id'];
                        $status        = (string) ($row['status'] ?? '');
                        $paymentStatus = (string) ($row['payment_status'] ?? '');
                        $detailUrl     = $this->url($basePath . '/' . $orderId);
                        ?>
                        <tr>
                            <td class="fw-semibold text-nowrap">
                                <a href="<?= $this->e($detailUrl) ?>" class="text-decoration-none">
                                    <?= $this->e($row['order_number'] ?? '') ?>
                                </a>
                            </td>
                            <td class="text-nowrap small"><?= $this->e($dateText($row['created_at'] ?? '')) ?></td>
                            <td class="small"><?= $this->e($row['customer_email'] ?? '') ?></td>
                            <td class="text-end"><?= $this->e((string) (int) ($row['item_count'] ?? 0)) ?></td>
                            <td class="text-end text-nowrap fw-semibold"><?= $this->e($money($row['grand_total'] ?? 0)) ?></td>
                            <td>
                                <span class="badge text-bg-light border">
                                    <?= $this->e($paymentStatusLabels[$paymentStatus] ?? $paymentStatus) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= $this->e($statusColors[$status] ?? 'secondary') ?>">
                                    <?= $this->e($statusLabels[$status] ?? $status) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a href="<?= $this->e($detailUrl) ?>" class="btn btn-sm btn-outline-secondary">Detay</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Sayfalama">
            <ul class="pagination justify-content-center flex-wrap mb-0">
                <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= $this->e($listUrl(['page' => max(1, $page - 1)])) ?>">Önceki</a>
                </li>

                <?php if ($windowStart > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= $this->e($listUrl(['page' => 1])) ?>">1</a></li>
                    <?php if ($windowStart > 2): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $windowStart; $i <= $windowEnd; $i++): ?>
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= $this->e($listUrl(['page' => $i])) ?>"
                           <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $this->e((string) $i) ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($windowEnd < $totalPages): ?>
                    <?php if ($windowEnd < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= $this->e($listUrl(['page' => $totalPages])) ?>"><?= $this->e((string) $totalPages) ?></a>
                    </li>
                <?php endif; ?>

                <li class="page-item<?= $page >= $totalPages ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= $this->e($listUrl(['page' => min($totalPages, $page + 1)])) ?>">Sonraki</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
