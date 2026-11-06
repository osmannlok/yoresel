<?php

/**
 * Aşıroğlu Yöresel Market - Yönetim paneli sipariş detayı view'ı
 *
 * Admin\OrderController::show() tarafından render edilir ve layouts/admin.php
 * içine yerleştirilir.
 *
 * Değişkenler:
 *   $order                (zorunlu) sipariş satırı (orders)
 *   $items                (zorunlu) order_items satırları
 *   $address              teslimat adresi anlık kopyası (çözülmüş JSON)
 *   $history              order_status_history satırları + user_name (eskiden yeniye)
 *   $payments             Payment::getByOrder() güvenli alanları (kart verisi yok)
 *   $shipments            ShipmentService::listForAdmin() çıktısı
 *   $nextStatuses         elle seçilebilecek sipariş durumları (liste)
 *   $statusLabels, $paymentStatusLabels, $shipmentStatusLabels
 *   $paymentMethodLabels, $paymentRecordLabels
 *   $listPath             /admin/siparisler
 *
 * Kararlar (DECISIONS.md):
 * - Durum formundaki "not" müşteriye görünen durum geçmişi notudur; "iç not"
 *   (orders.admin_note) yalnızca yönetimde görünür. İki form ayrıdır.
 * - Kargoya verildi / teslim edildi durumları elle seçilemez; gönderim bölümünden
 *   türer ($nextStatuses bunları zaten içermez).
 * - Sipariş iptali kart ödemesini İADE ETMEZ; iptal edilebilen ve ödemesi
 *   alınmış siparişte uyarı gösterilir. İade, ödeme sağlayıcı panelinden yapılır.
 * - Formlar JavaScript kapalıyken de çalışır (POST + flash + yönlendirme).
 *   JavaScript açıkken Fetch ile gönderilir, hata satır içinde gösterilir,
 *   başarıda sayfa yenilenir. CSRF alanı her formdadır.
 * - Kargo bölümü _shipments.php parçasıdır. Parça $nextStatuses'ı GÖNDERİM
 *   durumu haritası olarak okuduğundan, sipariş listesi dahil etmeden önce
 *   $orderNextStatuses'a taşınır ve parçaya boş harita verilir.
 * - Kart bilgisi yalnızca maskeli (card_mask) gösterilir.
 * - Tüm çıktılar $this->e() ile kaçırılır; hata metni textContent ile yazılır.
 *
 * Kullanılabilir yardımcılar: $this->e(), $this->url(), $this->csrfField()
 *
 * Gereksinimler: FR-066, FR-067, FR-069, FR-091, FR-092, NFR-003, NFR-004, NFR-005.
 */

declare(strict_types=1);

$order    = isset($order) && is_array($order) ? $order : [];
$items    = isset($items) && is_array($items) ? $items : [];
$address  = isset($address) && is_array($address) ? $address : [];
$history  = isset($history) && is_array($history) ? $history : [];
$payments = isset($payments) && is_array($payments) ? $payments : [];

$shipments           = isset($shipments) && is_array($shipments) ? $shipments : [];
$statusLabels        = isset($statusLabels) && is_array($statusLabels) ? $statusLabels : [];
$paymentStatusLabels = isset($paymentStatusLabels) && is_array($paymentStatusLabels) ? $paymentStatusLabels : [];
$shipmentStatusLabels = isset($shipmentStatusLabels) && is_array($shipmentStatusLabels) ? $shipmentStatusLabels : [];
$paymentMethodLabels = isset($paymentMethodLabels) && is_array($paymentMethodLabels) ? $paymentMethodLabels : [];
$paymentRecordLabels = isset($paymentRecordLabels) && is_array($paymentRecordLabels) ? $paymentRecordLabels : [];
$listPath            = isset($listPath) ? (string) $listPath : '/admin/siparisler';

// Sipariş durum listesi, kargo parçasının gönderim haritasıyla karışmasın
$orderNextStatuses = isset($nextStatuses) && is_array($nextStatuses) ? array_values($nextStatuses) : [];
$nextStatuses      = [];
$ordersPath        = $listPath;

$orderId       = (int) ($order['id'] ?? 0);
$status        = (string) ($order['status'] ?? '');
$paymentStatus = (string) ($order['payment_status'] ?? '');
$orderBase     = rtrim($listPath, '/') . '/' . $orderId;

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

/** "2026-11-01 14:30:00" => "01.11.2026 14:30"; boşsa "-"; çözülemezse ham metin */
$dateText = static function (mixed $value): string {
    $text = trim((string) $value);

    if ($text === '') {
        return '-';
    }

    $time = strtotime($text);

    return $time === false ? $text : date('d.m.Y H:i', $time);
};

/** Adres alanını metin olarak döndürür; yoksa boş. */
$addr = static fn (string $key): string => isset($address[$key]) && is_scalar($address[$key])
    ? trim((string) $address[$key])
    : '';

$statusLabel  = $statusLabels[$status] ?? $status;
$paymentLabel = $paymentStatusLabels[$paymentStatus] ?? $paymentStatus;

$fullName      = trim($addr('first_name') . ' ' . $addr('last_name'));
$location      = implode(', ', array_filter(
    [$addr('neighborhood'), $addr('district')],
    static fn (string $part): bool => $part !== ''
));
if ($addr('city') !== '') {
    $location .= ($location !== '' ? ' / ' : '') . $addr('city');
}

$discountTotal = (float) ($order['discount_total'] ?? 0);
$shippingTotal = (float) ($order['shipping_total'] ?? 0);
$customerNote  = trim((string) ($order['customer_note'] ?? ''));
$adminNote     = trim((string) ($order['admin_note'] ?? ''));

// İptal edilebilir ve ödemesi alınmış sipariş: kart iadesi otomatik yapılmaz uyarısı
$canCancel  = in_array('cancelled', $orderNextStatuses, true);
$showRefund = $canCancel && $paymentStatus === 'paid';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><a href="<?= $this->e($this->url($listPath)) ?>">Siparişler</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= $this->e($order['order_number'] ?? '') ?></li>
            </ol>
        </nav>
        <h1 class="h3 fw-bold mb-1">Sipariş <?= $this->e($order['order_number'] ?? '') ?></h1>
        <p class="text-muted small mb-0"><?= $this->e($dateText($order['created_at'] ?? '')) ?> tarihinde verildi</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <span class="badge fs-6 text-bg-<?= $this->e($statusColors[$status] ?? 'secondary') ?>"><?= $this->e($statusLabel) ?></span>
        <span class="badge fs-6 text-bg-light border">Ödeme: <?= $this->e($paymentLabel) ?></span>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-8">

        <!-- Sipariş kalemleri -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Sipariş kalemleri</div>
            <div class="card-body">
                <div class="table-responsive mb-3">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Ürün</th>
                                <th scope="col" class="text-end">Birim fiyat</th>
                                <th scope="col" class="text-end">Adet</th>
                                <th scope="col" class="text-end">KDV</th>
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
                                    <td class="text-end text-nowrap small text-muted">%<?= $this->e(rtrim(rtrim(number_format((float) ($item['vat_rate'] ?? 0), 2, ',', '.'), '0'), ',')) ?></td>
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

        <!-- Kargo / gönderim (FR-069) -->
        <div class="card border-0 shadow-sm mb-4" id="kargo">
            <div class="card-header bg-white fw-semibold">Kargo</div>
            <div class="card-body">
                <?php require __DIR__ . '/_shipments.php'; ?>
            </div>
        </div>

        <!-- Ödemeler -->
        <div class="card border-0 shadow-sm mb-4" id="odeme">
            <div class="card-header bg-white fw-semibold">Ödeme kayıtları</div>
            <?php if ($payments === []): ?>
                <div class="card-body text-muted small">Bu sipariş için ödeme kaydı yok.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Tarih</th>
                                <th scope="col">Yöntem</th>
                                <th scope="col">Durum</th>
                                <th scope="col" class="text-end">Tutar</th>
                                <th scope="col">Ayrıntı</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <?php
                                $paymentRecordStatus = (string) ($payment['status'] ?? '');
                                $method              = (string) ($payment['payment_method'] ?? '');
                                $installments        = (int) ($payment['installment_count'] ?? 1);
                                $cardMask            = trim((string) ($payment['card_mask'] ?? ''));
                                $errorMessage        = trim((string) ($payment['error_message'] ?? ''));
                                $transactionId       = trim((string) ($payment['provider_transaction_id'] ?? ''));
                                ?>
                                <tr>
                                    <td class="text-nowrap small"><?= $this->e($dateText($payment['paid_at'] ?? ($payment['created_at'] ?? ''))) ?></td>
                                    <td>
                                        <?= $this->e($paymentMethodLabels[$method] ?? $method) ?>
                                        <?php if (trim((string) ($payment['payment_provider'] ?? '')) !== ''): ?>
                                            <div class="small text-muted"><?= $this->e($payment['payment_provider']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-<?= $paymentRecordStatus === 'completed' ? 'success' : ($paymentRecordStatus === 'failed' ? 'danger' : 'secondary') ?>">
                                            <?= $this->e($paymentRecordLabels[$paymentRecordStatus] ?? $paymentRecordStatus) ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap"><?= $this->e($money($payment['amount'] ?? 0)) ?></td>
                                    <td class="small text-muted">
                                        <?php if ($cardMask !== ''): ?>
                                            <div>Kart: <?= $this->e($cardMask) ?><?= $installments > 1 ? ' · ' . $this->e((string) $installments) . ' taksit' : '' ?></div>
                                        <?php endif; ?>
                                        <?php if ($transactionId !== ''): ?>
                                            <div>İşlem no: <?= $this->e($transactionId) ?></div>
                                        <?php endif; ?>
                                        <?php if ($errorMessage !== ''): ?>
                                            <div class="text-danger"><?= $this->e($errorMessage) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Durum geçmişi -->
        <div class="card border-0 shadow-sm mb-4" id="gecmis">
            <div class="card-header bg-white fw-semibold">Sipariş geçmişi</div>
            <?php if ($history === []): ?>
                <div class="card-body text-muted small">Henüz kayıt yok.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach (array_reverse($history) as $row): ?>
                        <?php
                        $newStatus = (string) ($row['new_status'] ?? '');
                        $oldStatus = trim((string) ($row['old_status'] ?? ''));
                        $rowNote   = trim((string) ($row['note'] ?? ''));
                        $by        = trim((string) ($row['user_name'] ?? ''));
                        ?>
                        <li class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <?php if ($oldStatus !== ''): ?>
                                        <span class="text-muted small"><?= $this->e($statusLabels[$oldStatus] ?? $oldStatus) ?> &rarr;</span>
                                    <?php endif; ?>
                                    <span class="badge text-bg-<?= $this->e($statusColors[$newStatus] ?? 'secondary') ?>"><?= $this->e($statusLabels[$newStatus] ?? $newStatus) ?></span>
                                </div>
                                <div class="small text-muted">
                                    <?= $this->e($dateText($row['created_at'] ?? '')) ?>
                                    · <?= $by !== '' ? $this->e($by) : 'Sistem' ?>
                                </div>
                            </div>
                            <?php if ($rowNote !== ''): ?>
                                <div class="small mt-1"><?= nl2br($this->e($rowNote)) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-4">

        <!-- Durum güncelle (FR-092) -->
        <div class="card border-0 shadow-sm mb-4" id="durum">
            <div class="card-header bg-white fw-semibold">Durumu güncelle</div>
            <div class="card-body">
                <?php if ($orderNextStatuses === []): ?>
                    <p class="text-muted small mb-0">
                        <?= in_array($status, ['shipped', 'delivered'], true)
                            ? 'Bu aşamadaki durum değişiklikleri kargo bölümünden yönetilir.'
                            : 'Bu sipariş için başka bir duruma geçilemez.' ?>
                    </p>
                <?php else: ?>
                    <form method="post" action="<?= $this->e($this->url($orderBase . '/durum')) ?>" class="js-order-form" novalidate>
                        <?= $this->csrfField() ?>
                        <div class="mb-3">
                            <label for="orderStatus" class="form-label">Yeni durum</label>
                            <select class="form-select" id="orderStatus" name="status" required>
                                <?php foreach ($orderNextStatuses as $option): ?>
                                    <option value="<?= $this->e($option) ?>"><?= $this->e($statusLabels[$option] ?? $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="orderStatusNote" class="form-label">Müşteriye görünen not <span class="text-muted">(isteğe bağlı)</span></label>
                            <textarea class="form-control" id="orderStatusNote" name="note" rows="2" maxlength="500"></textarea>
                            <div class="form-text">Bu not müşterinin sipariş geçmişinde görünür.</div>
                        </div>

                        <?php if ($showRefund): ?>
                            <div class="alert alert-warning small" role="note">
                                <strong>İptal ederseniz</strong> stok geri verilir ancak ödeme otomatik İADE EDİLMEZ.
                                Kart ödemesinin iadesini ödeme sağlayıcı panelinden ayrıca yapın.
                            </div>
                        <?php endif; ?>

                        <div class="text-danger small mb-2 d-none js-form-error" role="alert"></div>
                        <button type="submit" class="btn btn-success w-100">Durumu kaydet</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Müşteri -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Müşteri</div>
            <div class="card-body small">
                <div class="mb-1"><?= $this->e($order['customer_email'] ?? '') ?></div>
                <?php if (trim((string) ($order['customer_phone'] ?? '')) !== ''): ?>
                    <div class="mb-1"><?= $this->e($order['customer_phone']) ?></div>
                <?php endif; ?>
                <div class="text-muted">
                    <?= (int) ($order['user_id'] ?? 0) > 0 ? 'Üye siparişi' : 'Misafir siparişi' ?>
                </div>
                <?php if ($customerNote !== ''): ?>
                    <hr>
                    <div class="fw-semibold mb-1">Müşteri notu</div>
                    <div><?= nl2br($this->e($customerNote)) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Teslimat adresi -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Teslimat adresi</div>
            <div class="card-body small">
                <?php if ($address === []): ?>
                    <span class="text-muted">Adres bilgisi okunamadı.</span>
                <?php else: ?>
                    <?php if ($fullName !== ''): ?><div class="fw-semibold"><?= $this->e($fullName) ?></div><?php endif; ?>
                    <?php if ($addr('phone') !== ''): ?><div><?= $this->e($addr('phone')) ?></div><?php endif; ?>
                    <?php if ($addr('address_line') !== ''): ?><div><?= $this->e($addr('address_line')) ?></div><?php endif; ?>
                    <?php if ($location !== ''): ?><div><?= $this->e($location) ?></div><?php endif; ?>
                    <?php if ($addr('postal_code') !== ''): ?><div><?= $this->e($addr('postal_code')) ?></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- İç not (yalnızca yönetim) -->
        <div class="card border-0 shadow-sm mb-4" id="not">
            <div class="card-header bg-white fw-semibold">İç not <span class="text-muted fw-normal small">(müşteri görmez)</span></div>
            <div class="card-body">
                <form method="post" action="<?= $this->e($this->url($orderBase . '/not')) ?>" class="js-order-form" novalidate>
                    <?= $this->csrfField() ?>
                    <label for="orderAdminNote" class="visually-hidden">İç not</label>
                    <textarea class="form-control mb-2" id="orderAdminNote" name="admin_note" rows="4" maxlength="1000"><?= $this->e($adminNote) ?></textarea>
                    <div class="text-danger small mb-2 d-none js-form-error" role="alert"></div>
                    <button type="submit" class="btn btn-outline-success w-100">Notu kaydet</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    document.querySelectorAll('.js-order-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.fetch) {
                return; // Eski tarayıcı: normal POST
            }

            event.preventDefault();

            var errorBox = form.querySelector('.js-form-error');
            var button = form.querySelector('button[type="submit"]');

            function showError(message) {
                if (errorBox) {
                    errorBox.textContent = message;
                    errorBox.classList.remove('d-none');
                }
            }

            if (errorBox) {
                errorBox.classList.add('d-none');
            }
            if (button) {
                button.disabled = true;
            }

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (result.ok) {
                        window.location.reload();
                        return;
                    }
                    showError(result.data && result.data.message
                        ? result.data.message
                        : 'İşlem tamamlanamadı. Lütfen tekrar deneyin.');
                    if (button) {
                        button.disabled = false;
                    }
                })
                .catch(function () {
                    showError('Bağlantı hatası. Lütfen tekrar deneyin.');
                    if (button) {
                        button.disabled = false;
                    }
                });
        });
    });
}());
</script>
