<?php
/**
 * Buyer Refunds Page
 * Shows all refund payments sent by Admin for cancelled orders.
 * Buyer can view receipt and mark refund as received.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('user');

$user    = current_user($conn);
$user_id = (int)$_SESSION['user_id'];

// ─── Handle Mark Refund as Received ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_refund_received') {
    verify_csrf();
    $order_id = (int)($_POST['order_id'] ?? 0);

    $chk = $conn->prepare("
        SELECT id, order_number, payment_status, buyer_refund_amount, buyer_refund_method, buyer_refund_received
        FROM orders
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $chk->bind_param("ii", $order_id, $user_id);
    $chk->execute();
    $ord = $chk->get_result()->fetch_assoc();

    if (!$ord) {
        $_SESSION['error'] = "Order not found or unauthorized.";
    } elseif ($ord['buyer_refund_received'] == 1) {
        $_SESSION['info'] = "You have already confirmed receiving this refund.";
    } elseif (!in_array($ord['payment_status'], ['refund_sent', 'refund_received'])) {
        $_SESSION['error'] = "No refund has been sent by Admin for this order yet.";
    } else {
        $now = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE orders
                SET payment_status = 'refund_received',
                    buyer_refund_received = 1,
                    buyer_refund_received_at = ?
                WHERE id = ? AND user_id = ? AND buyer_refund_received = 0
            ");
            $upd->bind_param("sii", $now, $order_id, $user_id);
            $upd->execute();

            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Buyer confirmed refund received",
                    "Amount: " . ($site_settings['currency'] ?? 'Rs.') . number_format($ord['buyer_refund_amount'], 2)
                    . " via " . $ord['buyer_refund_method'], $user_id);
            }

            // Notify Admin
            $notif_title = "Buyer Confirmed Refund";
            $notif_msg   = "Buyer confirmed receiving refund of " . ($site_settings['currency'] ?? 'Rs.') . number_format($ord['buyer_refund_amount'], 2) . " for order #{$ord['order_number']}.";
            $notif_link  = "escrow.php";
            $n_stmt = $conn->prepare("INSERT INTO admin_notifications (type, title, message, link, order_id) VALUES ('order', ?, ?, ?, ?)");
            $n_stmt->bind_param("sssi", $notif_title, $notif_msg, $notif_link, $order_id);
            $n_stmt->execute();

            $conn->commit();
            $_SESSION['success'] = "Refund confirmed! Thank you for confirming receipt for Order #{$ord['order_number']}.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Confirmation failed: " . $e->getMessage();
        }
    }
    header("Location: refunds.php");
    exit;
}

// ─── Fetch refund stats ───────────────────────────────────────────────────────
$stats_stmt = $conn->prepare("
    SELECT
        COUNT(CASE WHEN payment_status IN ('refund_sent','refund_received') THEN 1 END)         AS total_refunds,
        COUNT(CASE WHEN payment_status = 'refund_sent' AND buyer_refund_received = 0 THEN 1 END) AS pending_confirm,
        COUNT(CASE WHEN buyer_refund_received = 1 THEN 1 END)                                   AS confirmed,
        COALESCE(SUM(CASE WHEN payment_status IN ('refund_sent','refund_received') THEN buyer_refund_amount END), 0) AS total_amount
    FROM orders
    WHERE user_id = ?
");
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// ─── Pending Refunds (sent but not confirmed by buyer) ────────────────────────
$pending_stmt = $conn->prepare("
    SELECT o.id, o.order_number, o.created_at, o.grand_total, o.order_status,
           o.buyer_refund_amount, o.buyer_refund_method, o.buyer_refund_transaction_id,
           o.buyer_refund_sent_at, o.buyer_refund_receipt, o.buyer_refund_received,
           p.id AS product_id,
           p.title_en AS product_title,
           (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image,
           s.first_name AS seller_fn, s.last_name AS seller_ln
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users s ON oi.seller_id = s.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ?
      AND o.payment_status IN ('refund_sent','refund_received')
      AND o.buyer_refund_received = 0
    GROUP BY o.id
    ORDER BY o.buyer_refund_sent_at DESC
");
$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();
$pending_refunds = $pending_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ─── Confirmed Refunds history ────────────────────────────────────────────────
$confirmed_stmt = $conn->prepare("
    SELECT o.id, o.order_number, o.created_at, o.grand_total, o.order_status,
           o.buyer_refund_amount, o.buyer_refund_method, o.buyer_refund_transaction_id,
           o.buyer_refund_sent_at, o.buyer_refund_receipt, o.buyer_refund_received,
           o.buyer_refund_received_at,
           p.id AS product_id,
           p.title_en AS product_title,
           (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image,
           s.first_name AS seller_fn, s.last_name AS seller_ln
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users s ON oi.seller_id = s.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ?
      AND o.buyer_refund_received = 1
    GROUP BY o.id
    ORDER BY o.buyer_refund_received_at DESC
");
$confirmed_stmt->bind_param("i", $user_id);
$confirmed_stmt->execute();
$confirmed_refunds = $confirmed_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ─── Sidebar unread notification count ───────────────────────────────────────
$notif_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
$notif_stmt->bind_param("i", $user_id);
$notif_stmt->execute();
$unread_notifs = $notif_stmt->get_result()->fetch_assoc()['c'];

function pm_label_r($m) {
    $map = ['jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'bank_transfer' => 'Bank Transfer'];
    return $map[$m] ?? ucfirst(str_replace('_', ' ', $m));
}

$page_title = "My Refunds - PetMarket";
include '../includes/header.php';
?>

<style>
/* ── Page styles ─────────────────────────────────── */
.refund-stat-card {
    border-radius: 16px; border: none; overflow: hidden;
    transition: transform .18s, box-shadow .18s;
}
.refund-stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px rgba(0,0,0,.12) !important; }

.stat-icon-wrap {
    width: 44px; height: 44px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

.refund-pending-card {
    border-left: 5px solid #fd7e14 !important;
    background: linear-gradient(135deg,#fff9f5 0%,#ffffff 100%);
    transition: box-shadow .2s;
}
.refund-pending-card:hover { box-shadow: 0 8px 24px rgba(253,126,20,.15) !important; }

.refund-confirmed-card {
    border-left: 5px solid #198754 !important;
    background: linear-gradient(135deg,#f0faf5 0%,#ffffff 100%);
    transition: box-shadow .2s;
}
.refund-confirmed-card:hover { box-shadow: 0 8px 24px rgba(25,135,84,.15) !important; }

.receipt-thumb {
    width: 72px; height: 72px; object-fit: cover;
    border-radius: 10px; border: 2px solid #dee2e6; cursor: pointer;
    transition: transform .15s;
}
.receipt-thumb:hover { transform: scale(1.06); }

.method-badge-jc   { background:#fde8ea; color:#dc3545; border:1px solid #f5c2c7; }
.method-badge-ep   { background:#d1f0e0; color:#146c43; border:1px solid #a3d7bc; }
.method-badge-bank { background:#dce7fb; color:#0a58ca; border:1px solid #b9d0f8; }

.confirmed-stamp {
    display: inline-flex; align-items: center; gap: 6px;
    border: 2px dashed #198754; border-radius: 8px;
    padding: 3px 10px; color: #198754; font-weight: 700;
    font-size: .75rem; letter-spacing: 1px; background: rgba(25,135,84,.06);
}
.empty-state-icon {
    width: 90px; height: 90px; border-radius: 50%;
    background: linear-gradient(135deg,#f8f9fa,#e9ecef);
    display: flex; align-items: center; justify-content: center;
}
</style>

<div class="container py-5">
  <div class="row">

    <!-- ══ SIDEBAR ═══════════════════════════════════════════════ -->
    <div class="col-lg-3 mb-4">
      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body text-center p-4">
          <img src="<?= BASE_URL ?>/assets/images/<?= htmlspecialchars($user['profile_pic'] ?? 'default-user.png') ?>"
               onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($user['first_name'].' '.$user['last_name']) ?>&background=random'"
               alt="Profile" class="rounded-circle mb-3 border border-3 border-success"
               style="width:100px;height:100px;object-fit:cover;">
          <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?></h5>
          <p class="text-muted small mb-3"><?= htmlspecialchars($user['email']) ?></p>
          <a href="profile.php" class="btn btn-sm btn-outline-success rounded-pill px-3">Edit Profile</a>
        </div>
        <div class="list-group list-group-flush border-top">
          <a href="dashboard.php"   class="list-group-item list-group-item-action py-3"><i class="bi bi-grid me-2"></i>Dashboard</a>
          <a href="profile.php"     class="list-group-item list-group-item-action py-3"><i class="bi bi-person me-2"></i>My Profile</a>
          <a href="orders.php"      class="list-group-item list-group-item-action py-3"><i class="bi bi-box-seam me-2"></i>My Orders</a>
          <a href="account.php"     class="list-group-item list-group-item-action py-3"><i class="bi bi-credit-card-2-back me-2"></i>Payment Account</a>
          <a href="refunds.php"     class="list-group-item list-group-item-action active bg-success border-success py-3 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-arrow-return-left me-2"></i>My Refunds</span>
            <?php if ($stats['pending_confirm'] > 0): ?>
              <span class="badge bg-warning text-dark rounded-pill"><?= $stats['pending_confirm'] ?></span>
            <?php endif; ?>
          </a>
          <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i>Wishlist</a>
          <a href="../notifications.php" class="list-group-item list-group-item-action py-3 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bell me-2"></i>Notifications</span>
            <?php if ($unread_notifs > 0): ?>
              <span class="badge bg-danger rounded-pill"><?= $unread_notifs ?></span>
            <?php endif; ?>
          </a>
          <a href="../logout.php"   class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
        </div>
      </div>
    </div>

    <!-- ══ MAIN CONTENT ══════════════════════════════════════════ -->
    <div class="col-lg-9">
      <?php display_messages(); ?>

      <!-- Page Heading -->
      <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
          <h3 class="fw-bold mb-1">
            <i class="bi bi-arrow-return-left text-warning me-2"></i>My Refunds
          </h3>
          <p class="text-muted mb-0 small">Refund payments sent by Admin for your cancelled orders. Confirm once money arrives.</p>
        </div>
        <a href="orders.php" class="btn btn-outline-secondary rounded-pill px-4 btn-sm">
          <i class="bi bi-box-seam me-1"></i>View Orders
        </a>
      </div>

      <!-- ── Stat Cards ─────────────────────────────────────────── -->
      <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
          <div class="card refund-stat-card shadow-sm h-100" style="background:linear-gradient(135deg,#fff3cd,#ffeaa7);">
            <div class="card-body p-3">
              <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-wrap" style="background:rgba(255,193,7,.25);">
                  <i class="bi bi-arrow-return-left text-warning"></i>
                </div>
                <div>
                  <div class="text-muted fw-semibold" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;">Total Refunds</div>
                  <div class="fw-bold fs-4 text-dark lh-1 mt-1"><?= number_format($stats['total_refunds']) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-xl-3">
          <div class="card refund-stat-card shadow-sm h-100" style="background:linear-gradient(135deg,#fff4e6,#ffe8cc);">
            <div class="card-body p-3">
              <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-wrap" style="background:rgba(253,126,20,.2);">
                  <i class="bi bi-clock-history" style="color:#fd7e14;"></i>
                </div>
                <div>
                  <div class="text-muted fw-semibold" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;">Awaiting</div>
                  <div class="fw-bold fs-4 lh-1 mt-1" style="color:#fd7e14;"><?= number_format($stats['pending_confirm']) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-xl-3">
          <div class="card refund-stat-card shadow-sm h-100" style="background:linear-gradient(135deg,#d1f0e0,#b7e4ca);">
            <div class="card-body p-3">
              <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-wrap" style="background:rgba(25,135,84,.2);">
                  <i class="bi bi-check-circle-fill text-success"></i>
                </div>
                <div>
                  <div class="text-muted fw-semibold" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;">Confirmed</div>
                  <div class="fw-bold fs-4 text-success lh-1 mt-1"><?= number_format($stats['confirmed']) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-xl-3">
          <div class="card refund-stat-card shadow-sm h-100" style="background:linear-gradient(135deg,#dce7fb,#bfd3f8);">
            <div class="card-body p-3">
              <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-wrap" style="background:rgba(13,110,253,.18);">
                  <i class="bi bi-currency-exchange text-primary"></i>
                </div>
                <div>
                  <div class="text-muted fw-semibold" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;">Total Refunded</div>
                  <div class="fw-bold fs-6 text-primary lh-1 mt-1"><?= ($site_settings['currency'] ?? 'Rs.') . number_format($stats['total_amount'], 0) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Info Banner ──────────────────────────────────────── -->
      <div class="alert border-0 rounded-4 shadow-sm mb-4 d-flex align-items-start gap-3 p-3"
           style="background:linear-gradient(135deg,#fffbeb,#fff3cd);">
        <i class="bi bi-info-circle-fill text-warning fs-4 flex-shrink-0 mt-1"></i>
        <div class="small">
          <strong class="d-block mb-1">How Refunds Work</strong>
          When your order is cancelled after payment, Admin sends your money back to your saved
          <a href="account.php" class="text-success fw-bold">Payment Account</a>.
          Once you receive the money, click <strong>"Confirm Received"</strong> below to complete the process.
        </div>
      </div>

      <!-- ════════════════════════════════════════════════════════
           SECTION 1: Pending Refunds
      ════════════════════════════════════════════════════════ -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 pb-3 px-4 d-flex align-items-center justify-content-between">
          <div>
            <h5 class="fw-bold mb-0" style="color:#fd7e14;">
              <i class="bi bi-clock-history me-2"></i>Refunds Awaiting Your Confirmation
            </h5>
            <p class="text-muted small mb-0 mt-1">Admin has sent these refunds. Click "Confirm Received" once money is in your account.</p>
          </div>
          <?php if (!empty($pending_refunds)): ?>
            <span class="badge rounded-pill px-3 py-2" style="background:#fd7e14;font-size:.85rem;"><?= count($pending_refunds) ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body px-4 pb-4">
          <?php if (empty($pending_refunds)): ?>
          <div class="text-center py-5">
            <div class="empty-state-icon mx-auto mb-3">
              <i class="bi bi-check2-circle fs-1 text-success"></i>
            </div>
            <h6 class="fw-semibold text-muted">All Caught Up!</h6>
            <p class="text-muted small mb-0">No refunds are pending your confirmation right now.</p>
          </div>
          <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($pending_refunds as $r):
              $method = $r['buyer_refund_method'] ?? '';
              $badge_cls = ($method === 'jazzcash') ? 'method-badge-jc'
                         : (($method === 'easypaisa') ? 'method-badge-ep'
                         : (($method === 'bank_transfer') ? 'method-badge-bank' : 'bg-light text-dark border'));
            ?>
            <div class="card border refund-pending-card shadow-sm rounded-4 p-3">
              <div class="d-flex flex-wrap align-items-start gap-3">

                <!-- Receipt Thumbnail -->
                <div class="flex-shrink-0">
                  <?php if (!empty($r['buyer_refund_receipt'])): ?>
                    <img src="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($r['buyer_refund_receipt']) ?>"
                         class="receipt-thumb" alt="Receipt"
                         data-bs-toggle="modal" data-bs-target="#receiptModal"
                         data-receipt="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($r['buyer_refund_receipt']) ?>"
                         data-order="<?= htmlspecialchars($r['order_number']) ?>"
                         title="Click to view receipt">
                  <?php else: ?>
                    <div class="receipt-thumb d-flex align-items-center justify-content-center bg-light text-muted">
                      <i class="bi bi-receipt fs-3"></i>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Details -->
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="fw-bold text-primary">#<?= htmlspecialchars($r['order_number']) ?></span>
                    <span class="badge rounded-pill <?= $badge_cls ?> px-2 py-1 small">
                      <?= pm_label_r($method) ?>
                    </span>
                    <span class="badge bg-warning-subtle text-warning border border-warning rounded-pill px-2 small">
                      <i class="bi bi-clock me-1"></i>Awaiting Confirmation
                    </span>
                  </div>
                  <?php if (!empty($r['product_title'])): 
                    $ref_img = !empty($r['product_image']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($r['product_image']) : (!empty($r['product_id']) ? get_product_image_url($conn, $r['product_id']) : '');
                  ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <?php if (!empty($ref_img)): ?>
                        <img src="<?= $ref_img ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 34px; height: 34px;" onerror="this.src='https://placehold.co/34x34?text=Pet';">
                      <?php endif; ?>
                      <span class="text-muted small">
                        <i class="bi bi-bag me-1"></i><strong class="text-dark"><?= htmlspecialchars($r['product_title']) ?></strong>
                        <?php if (!empty($r['seller_fn'])): ?>
                          &mdash; <span><?= htmlspecialchars($r['seller_fn'].' '.$r['seller_ln']) ?></span>
                        <?php endif; ?>
                      </span>
                    </div>
                  <?php endif; ?>
                  <div class="d-flex flex-wrap gap-3 small">
                    <span>
                      <span class="text-muted">Refund Amount:</span>
                      <strong class="text-success fs-6 ms-1"><?= ($site_settings['currency'] ?? 'Rs.') . number_format($r['buyer_refund_amount'], 2) ?></strong>
                    </span>
                    <?php if (!empty($r['buyer_refund_transaction_id'])): ?>
                    <span>
                      <span class="text-muted">TID:</span>
                      <span class="font-monospace fw-semibold ms-1"><?= htmlspecialchars($r['buyer_refund_transaction_id']) ?></span>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($r['buyer_refund_sent_at'])): ?>
                    <span>
                      <span class="text-muted">Sent:</span>
                      <span class="ms-1"><?= date('d M Y, h:i A', strtotime($r['buyer_refund_sent_at'])) ?></span>
                    </span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex flex-column align-items-end gap-2 flex-shrink-0">
                  <?php if (!empty($r['buyer_refund_receipt'])): ?>
                  <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3"
                          data-bs-toggle="modal" data-bs-target="#receiptModal"
                          data-receipt="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($r['buyer_refund_receipt']) ?>"
                          data-order="<?= htmlspecialchars($r['order_number']) ?>">
                    <i class="bi bi-eye me-1"></i>View Receipt
                  </button>
                  <?php endif; ?>
                  <form method="POST" action="">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="mark_refund_received">
                    <input type="hidden" name="order_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success fw-bold rounded-pill px-4 shadow-sm"
                            onclick="return confirm('Confirm you received refund of <?= ($site_settings['currency'] ?? 'Rs.') . number_format($r['buyer_refund_amount'], 2) ?> for Order #<?= htmlspecialchars($r['order_number']) ?>?');">
                      <i class="bi bi-check-circle me-1"></i>Confirm Received
                    </button>
                  </form>
                </div>

              </div>

              <!-- Refund Progress Bar -->
              <div class="mt-3 pt-3 border-top">
                <div class="d-flex justify-content-between small text-muted mb-1">
                  <span>Refund Progress</span><span>Step 2 of 3</span>
                </div>
                <div class="progress rounded-pill" style="height:6px;">
                  <div class="progress-bar bg-warning" style="width:66%;"></div>
                </div>
                <div class="d-flex justify-content-between mt-2" style="font-size:.72rem;">
                  <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Order Cancelled</span>
                  <span class="fw-semibold" style="color:#fd7e14;"><i class="bi bi-send-fill me-1"></i>Admin Sent Refund</span>
                  <span class="text-muted"><i class="bi bi-circle me-1"></i>You Confirm</span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ════════════════════════════════════════════════════════
           SECTION 2: Confirmed Refund History
      ════════════════════════════════════════════════════════ -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 pb-3 px-4 d-flex align-items-center justify-content-between">
          <div>
            <h5 class="fw-bold mb-0 text-success">
              <i class="bi bi-check2-all me-2"></i>Confirmed Refund History
            </h5>
            <p class="text-muted small mb-0 mt-1">Refunds you have already confirmed. Full record for your reference.</p>
          </div>
          <?php if (!empty($confirmed_refunds)): ?>
            <span class="badge bg-success rounded-pill px-3 py-2" style="font-size:.85rem;"><?= count($confirmed_refunds) ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body px-4 pb-4">
          <?php if (empty($confirmed_refunds)): ?>
          <div class="text-center py-5">
            <div class="empty-state-icon mx-auto mb-3">
              <i class="bi bi-inbox fs-1 text-muted"></i>
            </div>
            <h6 class="fw-semibold text-muted">No Confirmed Refunds Yet</h6>
            <p class="text-muted small mb-0">Your confirmed refund history will appear here.</p>
          </div>
          <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($confirmed_refunds as $r):
              $method = $r['buyer_refund_method'] ?? '';
              $badge_cls = ($method === 'jazzcash') ? 'method-badge-jc'
                         : (($method === 'easypaisa') ? 'method-badge-ep'
                         : (($method === 'bank_transfer') ? 'method-badge-bank' : 'bg-light text-dark border'));
            ?>
            <div class="card border refund-confirmed-card shadow-sm rounded-4 p-3">
              <div class="d-flex flex-wrap align-items-start gap-3">

                <!-- Receipt Thumbnail -->
                <div class="flex-shrink-0">
                  <?php if (!empty($r['buyer_refund_receipt'])): ?>
                    <img src="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($r['buyer_refund_receipt']) ?>"
                         class="receipt-thumb" alt="Receipt"
                         data-bs-toggle="modal" data-bs-target="#receiptModal"
                         data-receipt="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($r['buyer_refund_receipt']) ?>"
                         data-order="<?= htmlspecialchars($r['order_number']) ?>"
                         title="View receipt">
                  <?php else: ?>
                    <div class="receipt-thumb d-flex align-items-center justify-content-center bg-light text-muted">
                      <i class="bi bi-receipt fs-3"></i>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Details -->
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="fw-bold text-primary">#<?= htmlspecialchars($r['order_number']) ?></span>
                    <span class="badge rounded-pill <?= $badge_cls ?> px-2 py-1 small"><?= pm_label_r($method) ?></span>
                    <span class="confirmed-stamp">
                      <i class="bi bi-patch-check-fill"></i>REFUND RECEIVED
                    </span>
                  </div>
                  <?php if (!empty($r['product_title'])): 
                    $ref_img = !empty($r['product_image']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($r['product_image']) : (!empty($r['product_id']) ? get_product_image_url($conn, $r['product_id']) : '');
                  ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                      <?php if (!empty($ref_img)): ?>
                        <img src="<?= $ref_img ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 34px; height: 34px;" onerror="this.src='https://placehold.co/34x34?text=Pet';">
                      <?php endif; ?>
                      <span class="text-muted small">
                        <i class="bi bi-bag me-1"></i><strong class="text-dark"><?= htmlspecialchars($r['product_title']) ?></strong>
                        <?php if (!empty($r['seller_fn'])): ?>
                          &mdash; <span><?= htmlspecialchars($r['seller_fn'].' '.$r['seller_ln']) ?></span>
                        <?php endif; ?>
                      </span>
                    </div>
                  <?php endif; ?>
                  <div class="d-flex flex-wrap gap-3 small">
                    <span>
                      <span class="text-muted">Refund Amount:</span>
                      <strong class="text-success fs-6 ms-1"><?= ($site_settings['currency'] ?? 'Rs.') . number_format($r['buyer_refund_amount'], 2) ?></strong>
                    </span>
                    <?php if (!empty($r['buyer_refund_transaction_id'])): ?>
                    <span>
                      <span class="text-muted">TID:</span>
                      <span class="font-monospace fw-semibold ms-1"><?= htmlspecialchars($r['buyer_refund_transaction_id']) ?></span>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($r['buyer_refund_sent_at'])): ?>
                    <span>
                      <span class="text-muted">Sent:</span>
                      <span class="ms-1"><?= date('d M Y', strtotime($r['buyer_refund_sent_at'])) ?></span>
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($r['buyer_refund_received_at'])): ?>
                    <span>
                      <span class="text-muted">Confirmed:</span>
                      <strong class="text-success ms-1"><?= date('d M Y, h:i A', strtotime($r['buyer_refund_received_at'])) ?></strong>
                    </span>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- View Receipt -->
                <div class="flex-shrink-0">
                  <?php if (!empty($r['buyer_refund_receipt'])): ?>
                  <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3"
                          data-bs-toggle="modal" data-bs-target="#receiptModal"
                          data-receipt="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($r['buyer_refund_receipt']) ?>"
                          data-order="<?= htmlspecialchars($r['order_number']) ?>">
                    <i class="bi bi-eye me-1"></i>View Receipt
                  </button>
                  <?php endif; ?>
                </div>

              </div>

              <!-- Completed Progress Bar -->
              <div class="mt-3 pt-3 border-top">
                <div class="d-flex justify-content-between small text-muted mb-1">
                  <span>Refund Progress</span>
                  <span class="text-success fw-bold">&#10003; Completed</span>
                </div>
                <div class="progress rounded-pill" style="height:6px;">
                  <div class="progress-bar bg-success" style="width:100%;"></div>
                </div>
                <div class="d-flex justify-content-between mt-2" style="font-size:.72rem;">
                  <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Order Cancelled</span>
                  <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Admin Sent Refund</span>
                  <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>You Confirmed</span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /col-lg-9 -->
  </div><!-- /row -->
</div><!-- /container -->

<!-- ══ Receipt Viewer Modal ══════════════════════════════════════════════════ -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-white">
      <div class="modal-header border-0 px-4 py-3" style="background:linear-gradient(135deg,#198754,#146c43);">
        <h5 class="modal-title fw-bold text-white mb-0">
          <i class="bi bi-receipt me-2"></i>Refund Receipt
          <small class="fw-normal opacity-75 ms-2" id="receiptModalOrderNo"></small>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 text-center" style="background:#f8fafc;">
        <img id="receiptModalImg" src="" alt="Refund Receipt"
             class="img-fluid rounded-3 shadow-sm" style="max-height:70vh;">
        <div class="mt-3">
          <a id="receiptModalFullLink" href="#" target="_blank"
             class="btn btn-outline-primary rounded-pill px-4 btn-sm">
            <i class="bi bi-arrows-fullscreen me-1"></i>Open Full Size
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var receiptModal = document.getElementById('receiptModal');
    if (receiptModal) {
        receiptModal.addEventListener('show.bs.modal', function (e) {
            var trigger = e.relatedTarget;
            var receipt = trigger ? trigger.dataset.receipt : '';
            var orderNo = trigger ? trigger.dataset.order  : '';
            document.getElementById('receiptModalImg').src        = receipt;
            document.getElementById('receiptModalFullLink').href  = receipt;
            document.getElementById('receiptModalOrderNo').textContent = orderNo ? '— Order #' + orderNo : '';
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
