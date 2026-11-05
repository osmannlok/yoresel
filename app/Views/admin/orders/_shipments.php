<?php

/**
 * Aşıroğlu Yöresel Market - Yönetim paneli sipariş detayı: kargo / gönderim bölümü (parça)
 *
 * Sipariş detay view'ının (admin/orders/show) içinde, kart alanında dahil edilir:
 *   <?php require __DIR__ . '/_shipments.php'; ?>
 *
 * Controller: App\Controllers\Admin\ShipmentController (formların hedefi)
 *
 * Gelen değişkenler:
 *   array<string,mixed>                $order                 Sipariş satırı (en az: id, status)
 *   list<array<string,mixed>>          $shipments             ShipmentService::listForAdmin() çıktısı
 *   array<string,string>               $shipmentStatusLabels  (isteğe bağlı) durum => görünen ad
 *   array<string,list<string>>         $nextStatuses          (isteğe bağlı) durum => geçilebilecek durumlar;
 *                                                             verilmezse mevcut durum dışındaki tüm durumlar
 *                                                             listelenir ve model reddeder
 *   string                             $ordersPath            (isteğe bağlı) /admin/siparisler
 *
 * Kararlar:
 * - Formlar JavaScript kapalıyken de çalışır (normal POST + flash mesaj +
 *   yönlendirme). JavaScript açıkken Fetch ile gönderilir, hata satır içinde
 *   gösterilir, başarıda sayfa yenilenir. CSRF alanı her formdadır ($this->csrfField()).
 * - Kargo tarihi YYYY-AA-GG biçimindedir (type="date"); Shipment modeli yalnızca
 *   bu biçimi kabul eder. Boş bırakılırsa bugünün tarihi kullanılır.
 * - "Kargoya ver" formu yalnızca hazırlanan/başarısız gönderimde, "Takip bilgisi"
 *   formu kargoya verilmiş ve henüz kilitlenmemiş gönderimde görünür; teslim
 *   edilmiş veya iade edilmiş gönderimin takip bilgisi değiştirilemez.
 * - Yeni gönderim yalnızca ödemesi alınmış/hazırlanan/kargolanmış siparişlerde
 *   (pending, paid, preparing, shipped) açılabilir (ShipmentService ile aynı kural).
 * - Tüm çıktılar $this->e() ile kaçırılır; hata metni textContent ile yazılır.
 *
 * Kullanılabilir yardımcılar: $this->e(), $this->url(), $this->csrfField()
 *
 * Gereksinimler: FR-066, FR-067, NFR-003, NFR-004, NFR-005.
 */

declare(strict_types=1);

$order     = isset($order) && is_array($order) ? $order : [];
$shipments = isset($shipments) && is_array($shipments) ? $shipments : [];

$shipmentStatusLabels = isset($shipmentStatusLabels) && is_array($shipmentStatusLabels)
    ? $shipmentStatusLabels
    : [
        'preparing'        => 'Hazırlanıyor',
        'handed_to_cargo'  => 'Kargoya Verildi',
        'in_transit'       => 'Yolda',
        'out_for_delivery' => 'Dağıtımda',
        'delivered'        => 'Teslim Edildi',
        'failed'           => 'Teslim Edilemedi',
        'returned'         => 'İade Döndü',
    ];

$nextStatuses = isset($nextStatuses) && is_array($nextStatuses) ? $nextStatuses : [];
$ordersPath   = isset($ordersPath) ? (string) $ordersPath : '/admin/siparisler';

$orderId     = (int) ($order['id'] ?? 0);
$orderStatus = (string) ($order['status'] ?? '');
$canAdd      = in_array($orderStatus, ['pending', 'paid', 'preparing', 'shipped'], true);
$baseAction  = rtrim($ordersPath, '/') . '/' . $orderId . '/gonderimler';

/** Gönderim durumu => Bootstrap rozet rengi */
$statusColors = [
    'preparing'        => 'secondary',
    'handed_to_cargo'  => 'primary',
    'in_transit'       => 'primary',
    'out_for_delivery' => 'info',
    'delivered'        => 'success',
    'failed'           => 'danger',
    'returned'         => 'secondary',
];

/** "2026-11-05 14:30:00" => "05.11.2026"; çözülemezse boş */
$dateText = static function (mixed $value): string {
    $time = strtotime((string) $value);

    return $time === false ? '' : date('d.m.Y', $time);
};

/** "2026-11-05 14:30:00" => type="date" değeri "2026-11-05" */
$dateValue = static function (mixed $value): string {
    $text = (string) $value;

    return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : '';
};
?>
<div class="card border-0 shadow-sm mb-4" id="kargo">
    <div class="card-body p-4">
        <h2 class="h5 fw-semibold mb-3">Kargo Bilgisi</h2>

        <?php if ($shipments === []): ?>
            <p class="small text-muted">Bu sipariş için henüz gönderim kaydı yok.</p>
        <?php endif; ?>

        <?php foreach ($shipments as $index => $shipment): ?>
            <?php
            $shipmentId = (int) ($shipment['id'] ?? 0);
            $status     = (string) ($shipment['status'] ?? '');
            $color      = $statusColors[$status] ?? 'secondary';
            $locked     = in_array($status, ['delivered', 'returned'], true);
            $canHandOff = in_array($status, ['preparing', 'failed'], true);
            $next       = array_key_exists($status, $nextStatuses)
                ? $nextStatuses[$status]
                : array_values(array_diff(array_keys($shipmentStatusLabels), [$status]));
            $actionBase = $baseAction . '/' . $shipmentId;
            $idSuffix   = (string) $shipmentId;
            $shippedAt  = $dateText($shipment['shipped_at'] ?? '');
            $deliveredAt = $dateText($shipment['delivered_at'] ?? '');
            ?>
            <div class="<?= $index > 0 ? 'border-top pt-3 mt-3' : '' ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <span class="fw-semibold">Gönderim #<?= $this->e((string) ($index + 1)) ?></span>
                    <span class="badge text-bg-<?= $this->e($color) ?>"><?= $this->e($shipmentStatusLabels[$status] ?? $status) ?></span>
                </div>

                <?php if ($shippedAt !== '' || $deliveredAt !== ''): ?>
                    <p class="small text-muted mb-2">
                        <?php if ($shippedAt !== ''): ?>Kargo tarihi: <?= $this->e($shippedAt) ?><?php endif; ?>
                        <?php if ($shippedAt !== '' && $deliveredAt !== ''): ?> · <?php endif; ?>
                        <?php if ($deliveredAt !== ''): ?>Teslim tarihi: <?= $this->e($deliveredAt) ?><?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if ($canHandOff || !$locked): ?>
                    <form method="post"
                          action="<?= $this->e($this->url($actionBase . ($canHandOff ? '/kargoya-ver' : '/takip'))) ?>"
                          class="row g-2 mb-2 js-shipment-form">
                        <?= $this->csrfField() ?>

                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="carrier-<?= $this->e($idSuffix) ?>">Kargo firması</label>
                            <input type="text" class="form-control form-control-sm" id="carrier-<?= $this->e($idSuffix) ?>"
                                   name="carrier_name" maxlength="100" required
                                   value="<?= $this->e($shipment['carrier_name'] ?? '') ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1" for="tracking-<?= $this->e($idSuffix) ?>">Takip numarası</label>
                            <input type="text" class="form-control form-control-sm" id="tracking-<?= $this->e($idSuffix) ?>"
                                   name="tracking_number" maxlength="100" required
                                   value="<?= $this->e($shipment['tracking_number'] ?? '') ?>">
                        </div>
                        <div class="col-12 <?= $canHandOff ? 'col-md-8' : '' ?>">
                            <label class="form-label small mb-1" for="url-<?= $this->e($idSuffix) ?>">Takip adresi (isteğe bağlı)</label>
                            <input type="url" class="form-control form-control-sm" id="url-<?= $this->e($idSuffix) ?>"
                                   name="tracking_url" maxlength="500" placeholder="https://"
                                   value="<?= $this->e($shipment['tracking_url'] ?? '') ?>">
                        </div>
                        <?php if ($canHandOff): ?>
                            <div class="col-12 col-md-4">
                                <label class="form-label small mb-1" for="shipped-<?= $this->e($idSuffix) ?>">Kargo tarihi</label>
                                <input type="date" class="form-control form-control-sm" id="shipped-<?= $this->e($idSuffix) ?>"
                                       name="shipped_at" value="<?= $this->e($dateValue($shipment['shipped_at'] ?? '')) ?>">
                            </div>
                        <?php endif; ?>

                        <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                            <button type="submit" class="btn btn-sm <?= $canHandOff ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <?= $canHandOff ? 'Kargoya Verildi Olarak Kaydet' : 'Bilgileri Kaydet' ?>
                            </button>
                            <span class="small text-danger d-none js-form-error" role="alert"></span>
                        </div>
                    </form>
                <?php elseif (trim((string) ($shipment['tracking_number'] ?? '')) !== ''): ?>
                    <p class="small mb-2">
                        <?= $this->e($shipment['carrier_name'] ?? '') ?> · <?= $this->e($shipment['tracking_number'] ?? '') ?>
                    </p>
                <?php endif; ?>

                <?php if ($next !== []): ?>
                    <form method="post" action="<?= $this->e($this->url($actionBase . '/durum')) ?>"
                          class="d-flex flex-wrap align-items-center gap-2 js-shipment-form">
                        <?= $this->csrfField() ?>

                        <label class="small text-muted" for="status-<?= $this->e($idSuffix) ?>">Durumu değiştir:</label>
                        <select class="form-select form-select-sm w-auto" id="status-<?= $this->e($idSuffix) ?>" name="status" required>
                            <?php foreach ($next as $option): ?>
                                <option value="<?= $this->e($option) ?>"><?= $this->e($shipmentStatusLabels[$option] ?? $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Güncelle</button>
                        <span class="small text-danger d-none js-form-error" role="alert"></span>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($canAdd): ?>
            <form method="post" action="<?= $this->e($this->url($baseAction)) ?>"
                  class="row g-2 <?= $shipments !== [] ? 'border-top pt-3 mt-3' : '' ?> js-shipment-form">
                <?= $this->csrfField() ?>

                <div class="col-12">
                    <h3 class="h6 fw-semibold mb-0"><?= $shipments === [] ? 'Kargo bilgisi gir' : 'Parçalı gönderim ekle' ?></h3>
                    <div class="form-text">Takip numarası girilirse gönderim kargoya verildi olarak kaydedilir ve sipariş "Kargoya Verildi" olur. Boş bırakılırsa gönderim "Hazırlanıyor" olarak açılır.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" for="new-carrier">Kargo firması</label>
                    <input type="text" class="form-control form-control-sm" id="new-carrier" name="carrier_name" maxlength="100">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" for="new-tracking">Takip numarası</label>
                    <input type="text" class="form-control form-control-sm" id="new-tracking" name="tracking_number" maxlength="100">
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1" for="new-url">Takip adresi (isteğe bağlı)</label>
                    <input type="url" class="form-control form-control-sm" id="new-url" name="tracking_url"
                           maxlength="500" placeholder="https://">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1" for="new-shipped">Kargo tarihi</label>
                    <input type="date" class="form-control form-control-sm" id="new-shipped" name="shipped_at">
                </div>

                <div class="col-12 d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">Gönderim Ekle</button>
                    <span class="small text-danger d-none js-form-error" role="alert"></span>
                </div>
            </form>
        <?php elseif ($shipments === []): ?>
            <p class="small text-muted mb-0">Kargo bilgisi yalnızca ödemesi alınmış, iptal veya iade edilmemiş siparişlere girilebilir.</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    'use strict';

    document.querySelectorAll('.js-shipment-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var message = form.querySelector('.js-form-error');
            var button = form.querySelector('button[type="submit"]');

            if (message) {
                message.classList.add('d-none');
                message.textContent = '';
            }
            if (button) {
                button.disabled = true;
            }

            function fail(text) {
                if (message) {
                    message.textContent = text;
                    message.classList.remove('d-none');
                }
                if (button) {
                    button.disabled = false;
                }
            }

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (result.ok && result.data && result.data.success === true) {
                        window.location.reload();
                        return;
                    }

                    var text = result.data && result.data.message
                        ? result.data.message
                        : 'İşlem tamamlanamadı. Lütfen tekrar deneyin.';
                    fail(text);
                })
                .catch(function () {
                    fail('Bağlantı hatası. Lütfen tekrar deneyin.');
                });
        });
    });
})();
</script>
