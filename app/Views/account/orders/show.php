<?php

/**
 * Aşıroğlu Yöresel Market - Hesabım: sipariş detayı view'ı
 *
 * AccountOrderController::show() tarafından render edilir ve layouts/main.php
 * içine yerleştirilir.
 *
 * Değişkenler:
 *   $order               orders satırı
 *   $items               order_items satırları
 *   $address             teslimat adresi anlık kopyası (JSON'dan çözülmüş)
 *   $history             list: status, note, created_at (personel adı yok)
 *   $shipments           (isteğe bağlı) list: carrier_name, tracking_number,
 *                        tracking_url, status, shipped_at, delivered_at.
 *                        Verilmezse veya boşsa kargo bloğu yalnızca siparişin
 *                        durumuna göre bilgi mesajı gösterir.
 *   $statusLabels        sipariş durumu => görünen ad
 *   $paymentStatusLabels ödeme durumu => görünen ad
 *   $listPath            /hesabim/siparisler
 *
 * Kargo bloğu (FR-069): kargo firması, takip numarası ve gönderim durumu
 * gösterilir. Kargo takibi manuel olduğundan (FR-067) bilgiyi yönetici girer;
 * parçalı gönderimde her gönderim ayrı kart olarak listelenir. Takip adresi
 * yalnızca http/https ise bağlantıya çevrilir; diğer değerler düz metin olur.
 *
 * Fiyatlar KDV dahil, TRY.
 *
 * Kullanılabilir yardımcılar: $this->e(), $this->url()
 *
 * Gereksinimler: FR-055, FR-063, FR-066, FR-067, FR-069, G-02, G-05, NFR-005.
 */

declare(strict_types=1);

$order               = isset($order) && is_array($order) ? $order : [];
$items               = isset($items) && is_array($items) ? $items : [];
$address             = isset($address) && is_array($address) ? $address : [];
$history             = isset($history) && is_array($history) ? $history : [];
$shipments           = isset($shipments) && is_array($shipments) ? $shipments : [];
$statusLabels        = isset($statusLabels) && is_array($statusLabels) ? $statusLabels : [];
$paymentStatusLabels = isset($paymentStatusLabels) && is_array($paymentStatusLabels) ? $paymentStatusLabels : [];
$listPath            = isset($listPath) ? (string) $listPath : '/hesabim/siparisler';

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

/** Gönderim durumu => [müşteriye görünen ad, rozet rengi] (shipments.status ENUM) */
$shipmentStatusMeta = [
    'preparing'        => ['Hazırlanıyor', 'secondary'],
    'handed_to_cargo'  => ['Kargoya verildi', 'primary'],
    'in_transit'       => ['Yolda', 'primary'],
    'out_for_delivery' => ['Dağıtımda', 'info'],
    'delivered'        => ['Teslim edildi', 'success'],
    'failed'           => ['Teslim edilemedi', 'danger'],
    'returned'         => ['İade edildi', 'secondary'],
];

/** Tutarı Türkçe biçimde yazar: 1.250,50 ₺ */
$money = static fn (mixed $amount): string => number_format((float) $amount, 2, ',', '.') . ' ₺';

/** "2026-11-01 14:30:00" => "01.11.2026 14:30"; çözülemezse ham metin */
$dateText = static function (mixed $value): string {
    $time = strtotime((string) $value);

    return $time === false ? (string) $value : date('d.m.Y H:i', $time);
};

/** Adres alanını metin olarak döndürür; yoksa boş. */
$addr = static fn (string $key): string => isset($address[$key]) && is_scalar($address[$key])
    ? trim((string) $address[$key])
    : '';

/** Takip adresi yalnızca geçerli http/https ise bağlantı olur (javascript: vb. reddedilir). */
$isSafeUrl = static function (string $url): bool {
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
};

$status        = (string) ($order['status'] ?? '');
$paymentStatus = (string) ($order['payment_status'] ?? '');
$statusLabel   = $statusLabels[$status] ?? $status;
$paymentLabel  = $paymentStatusLabels[$paymentStatus] ?? $paymentStatus;
$statusColor   = $statusColors[$status] ?? 'secondary';

$fullName = trim($addr('first_name') . ' ' . $addr('last_name'));

// "Mahalle, İlçe / İl" satırı: boş parçalar atlanır
$locationParts = array_filter(
    [$addr('neighborhood'), $addr('district')],
    static fn (string $part): bool => $part !== ''
);
$location = implode(', ', $locationParts);

if ($addr('city') !== '') {
    $location .= ($location !== '' ? ' / ' : '') . $addr('city');
}

$shippingTotal = (float) ($order['shipping_total'] ?? 0);
$discountTotal = (float) ($order['discount_total'] ?? 0);
$customerNote  = trim((string) ($order['customer_note'] ?? ''));

// Kargo bloğu: gönderim kaydı varsa veya sipariş kargo aşamasındaysa gösterilir
$showShipping = $shipments !== [] || in_array($status, ['preparing', 'shipped', 'delivered'], true);
?>
<div class="container py-4 py-lg-5">

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= $this->e($this->url($listPath)) ?>">Siparişlerim</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $this->e($order['order_number'] ?? '') ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Sipariş <?= $this->e($order['order_number'] ?? '') ?></h1>
            <p class="text-muted small mb-0"><?= $this->e($dateText($order['created_at'] ?? '')) ?> tarihinde verildi</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge fs-6 text-bg-<?= $this->e($statusColor) ?>"><?= $this->e($statusLabel) ?></span>
            <span class="badge fs-6 text-bg-light border">Ödeme: <?= $this->e($paymentLabel) ?></span>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-12 col-lg-8">

            <!-- Kalemler -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3">Ürünler</h2>

                    <div class="table-responsive">
                        <table class="table align-middle mb-3">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Ürün</th>
                                    <th scope="col" class="text-end">Birim fiyat</th>
                                    <th scope="col" class="text-end">Adet</th>
                                    <th scope="col" class="text-end">Toplam</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= $this->e($item['product_name'] ?? '') ?></div>
                                            <?php if (trim((string) ($item['product_sku'] ?? '')) !== ''): ?>
                                                <div class="small text-muted">SKU: <?= $this->e($item['product_sku']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap"><?= $this->e($money($item['unit_price'] ?? 0)) ?></td>
                                        <td class="text-end"><?= $this->e((string) (int) ($item['quantity'] ?? 0)) ?></td>
                                        <td class="text-end text-nowrap"><?= $this->e($money($item['line_total'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <dl class="row mb-0 ms-md-auto" style="max-width: 360px;">
                        <dt class="col-6 fw-normal text-muted">Ara toplam</dt>
                        <dd class="col-6 text-end mb-1"><?= $this->e($money($order['subtotal'] ?? 0)) ?></dd>

                        <?php if ($discountTotal > 0): ?>
                            <dt class="col-6 fw-normal text-muted">İndirim</dt>
                            <dd class="col-6 text-end mb-1">-<?= $this->e($money($discountTotal)) ?></dd>
                        <?php endif; ?>

                        <dt class="col-6 fw-normal text-muted">Kargo</dt>
                        <dd class="col-6 text-end mb-1"><?= $shippingTotal <= 0 ? 'Ücretsiz' : $this->e($money($shippingTotal)) ?></dd>

                        <dt class="col-6 fw-normal text-muted">İçerdiği KDV</dt>
                        <dd class="col-6 text-end mb-1 text-muted"><?= $this->e($money($order['vat_total'] ?? 0)) ?></dd>

                        <dt class="col-6 fs-5 fw-bold border-top pt-2 mt-1">Toplam</dt>
                        <dd class="col-6 fs-5 fw-bold text-success text-end border-top pt-2 mt-1"><?= $this->e($money($order['grand_total'] ?? 0)) ?></dd>
                    </dl>
                </div>
            </div>

            <!-- Kargo bilgisi (FR-069) -->
            <?php if ($showShipping): ?>
                <div class="card border-0 shadow-sm mb-4" id="kargo">
                    <div class="card-body p-4">
                        <h2 class="h5 fw-semibold mb-3">Kargo Bilgisi</h2>

                        <?php if ($shipments === []): ?>
                            <p class="small text-muted mb-0">
                                <?= $status === 'preparing'
                                    ? 'Siparişiniz hazırlanıyor. Kargoya verildiğinde takip bilgisi burada görünecek.'
                                    : 'Kargo takip bilgisi yakında eklenecek.' ?>
                            </p>
                        <?php else: ?>
                            <?php foreach ($shipments as $index => $shipment): ?>
                                <?php
                                $shipStatus   = (string) ($shipment['status'] ?? '');
                                $shipMeta     = $shipmentStatusMeta[$shipStatus] ?? [$shipStatus, 'secondary'];
                                $carrier      = trim((string) ($shipment['carrier_name'] ?? ''));
                                $tracking     = trim((string) ($shipment['tracking_number'] ?? ''));
                                $trackingUrl  = trim((string) ($shipment['tracking_url'] ?? ''));
                                $shippedAt    = trim((string) ($shipment['shipped_at'] ?? ''));
                                $deliveredAt  = trim((string) ($shipment['delivered_at'] ?? ''));
                                ?>
                                <div class="<?= $index > 0 ? 'border-top pt-3 mt-3' : '' ?>">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <span class="fw-semibold">
                                            <?= count($shipments) > 1 ? 'Gönderim ' . $this->e((string) ($index + 1)) : 'Gönderim' ?>
                                        </span>
                                        <span class="badge text-bg-<?= $this->e($shipMeta[1]) ?>"><?= $this->e($shipMeta[0]) ?></span>
                                    </div>

                                    <dl class="row small mb-0">
                                        <dt class="col-5 col-sm-4 fw-normal text-muted">Kargo firması</dt>
                                        <dd class="col-7 col-sm-8 mb-1"><?= $carrier !== '' ? $this->e($carrier) : '<span class="text-muted">—</span>' ?></dd>

                                        <dt class="col-5 col-sm-4 fw-normal text-muted">Takip numarası</dt>
                                        <dd class="col-7 col-sm-8 mb-1">
                                            <?php if ($tracking === ''): ?>
                                                <span class="text-muted">Henüz girilmedi</span>
                                            <?php elseif ($isSafeUrl($trackingUrl)): ?>
                                                <a href="<?= $this->e($trackingUrl) ?>" target="_blank"
                                                   rel="noopener noreferrer"><?= $this->e($tracking) ?></a>
                                            <?php else: ?>
                                                <span class="fw-semibold"><?= $this->e($tracking) ?></span>
                                            <?php endif; ?>
                                        </dd>

                                        <?php if ($shippedAt !== ''): ?>
                                            <dt class="col-5 col-sm-4 fw-normal text-muted">Kargo tarihi</dt>
                                            <dd class="col-7 col-sm-8 mb-1"><?= $this->e($dateText($shippedAt)) ?></dd>
                                        <?php endif; ?>

                                        <?php if ($deliveredAt !== ''): ?>
                                            <dt class="col-5 col-sm-4 fw-normal text-muted">Teslim tarihi</dt>
                                            <dd class="col-7 col-sm-8 mb-1"><?= $this->e($dateText($deliveredAt)) ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Durum geçmişi -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h5 fw-semibold mb-3">Sipariş Geçmişi</h2>

                    <?php if ($history === []): ?>
                        <p class="small text-muted mb-0">Henüz kayıt yok.</p>
                    <?php else: ?>
                        <ol class="list-unstyled mb-0">
                            <?php foreach (array_reverse($history) as $entry): ?>
                                <?php
                                $entryStatus = (string) ($entry['status'] ?? '');
                                $entryNote   = (string) ($entry['note'] ?? '');
                                ?>
                                <li class="border-start border-2 ps-3 pb-3 ms-1">
                                    <div class="fw-semibold">
                                        <?= $this->e($statusLabels[$entryStatus] ?? $entryStatus) ?>
                                    </div>
                                    <div class="small text-muted"><?= $this->e($dateText($entry['created_at'] ?? '')) ?></div>
                                    <?php if ($entryNote !== ''): ?>
                                        <div class="small mt-1"><?= nl2br($this->e($entryNote), false) ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">

            <!-- Teslimat adresi -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h6 fw-semibold mb-3">Teslimat Adresi</h2>
                    <?php if ($fullName !== '' || $addr('address_line') !== ''): ?>
                        <address class="mb-0 small">
                            <?php if ($fullName !== ''): ?>
                                <strong><?= $this->e($fullName) ?></strong><br>
                            <?php endif; ?>
                            <?php if ($addr('address_line') !== ''): ?>
                                <?= nl2br($this->e($addr('address_line')), false) ?><br>
                            <?php endif; ?>
                            <?php if ($location !== ''): ?>
                                <?= $this->e($location) ?><br>
                            <?php endif; ?>
                            <?php if ($addr('postal_code') !== ''): ?>
                                <?= $this->e($addr('postal_code')) ?><br>
                            <?php endif; ?>
                            <?php if ($addr('phone') !== ''): ?>
                                Tel: <?= $this->e($addr('phone')) ?>
                            <?php endif; ?>
                        </address>
                    <?php else: ?>
                        <p class="small text-muted mb-0">Adres bilgisi okunamadı.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- İletişim ve not -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <h2 class="h6 fw-semibold mb-3">İletişim</h2>
                    <p class="small mb-1">E-posta: <?= $this->e($order['customer_email'] ?? '') ?></p>
                    <?php if (trim((string) ($order['customer_phone'] ?? '')) !== ''): ?>
                        <p class="small mb-0">Telefon: <?= $this->e($order['customer_phone']) ?></p>
                    <?php endif; ?>

                    <?php if ($customerNote !== ''): ?>
                        <h2 class="h6 fw-semibold mt-4 mb-2">Sipariş Notunuz</h2>
                        <p class="small mb-0"><?= nl2br($this->e($customerNote), false) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4">
        <a href="<?= $this->e($this->url($listPath)) ?>" class="btn btn-outline-secondary">Siparişlerime Dön</a>
    </div>
</div>