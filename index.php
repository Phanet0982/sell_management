<?php
// ════════════════════════════════════════════════
//  Taste of Asia — MySQL Edition
//  Requires: PHP 7.4+, PDO + pdo_mysql extension
// ════════════════════════════════════════════════

session_start();

// ── Database config — edit these ──────────────
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sell_management');
define('DB_USER', 'root');       // change to your MySQL user
define('DB_PASS', '');           // change to your MySQL password
define('DB_CHARSET', 'utf8mb4');
// ──────────────────────────────────────────────

// ── Telegram config — edit these ──────────────
define('TG_BOT_TOKEN', '8701404263:AAGto0Z4UQ3HyGPWpfLY8_mUQftZobN6WzY');
define('TG_CHAT_ID',   '1407858984');
// ──────────────────────────────────────────────

// ── Send Telegram notification ────────────────
function sendTelegram(string $message): void {
    if (TG_BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE' || TG_CHAT_ID === 'YOUR_CHAT_ID_HERE') return;
    $url  = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $data = http_build_query([
        'chat_id'    => TG_CHAT_ID,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $data,
        'timeout' => 5,
    ]]);
    @file_get_contents($url, false, $ctx);
}
// ──────────────────────────────────────────────

// ── PDO connection (singleton) ────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── Cart helpers ──────────────────────────────
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

function cartTotal(array $cart, array $menu): float {
    $total = 0;
    foreach ($cart as $id => $qty) {
        foreach ($menu as $item) {
            if ($item['id'] == $id) { $total += $item['price'] * $qty; break; }
        }
    }
    return $total;
}

// ── AJAX / POST handler ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    try {
        // Load menu once for price lookups
        $menu = db()->query('SELECT * FROM menu_items WHERE active = 1 ORDER BY category, name')->fetchAll();

        if ($action === 'add') {
            $id = (int)$_POST['id'];
            $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + 1;

        } elseif ($action === 'remove') {
            $id = (int)$_POST['id'];
            if (isset($_SESSION['cart'][$id])) {
                $_SESSION['cart'][$id]--;
                if ($_SESSION['cart'][$id] <= 0) unset($_SESSION['cart'][$id]);
            }

        } elseif ($action === 'clear') {
            $_SESSION['cart'] = [];

        } elseif ($action === 'checkout') {
            // ── Save order to database ──
            if (empty($_SESSION['cart'])) {
                echo json_encode(['success' => false, 'message' => 'Cart is empty']);
                exit;
            }

            $customerName = trim($_POST['customer_name'] ?? 'Guest');
            $tableNumber  = trim($_POST['table_number']  ?? '');
            $note         = trim($_POST['note'] ?? '');
            $total        = cartTotal($_SESSION['cart'], $menu);

            $pdo = db();
            $pdo->beginTransaction();

            // Insert order header
            $stmt = $pdo->prepare(
                'INSERT INTO orders (customer_name, total_amount, status, note) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$customerName ?: 'Guest', $total, 'pending', $note]);
            $orderId = (int)$pdo->lastInsertId();

            // Insert order lines
            $lineStmt = $pdo->prepare(
                'INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($_SESSION['cart'] as $itemId => $qty) {
                foreach ($menu as $m) {
                    if ($m['id'] == $itemId) {
                        $lineStmt->execute([
                            $orderId,
                            $itemId,
                            $m['name'],
                            $m['price'],
                            $qty,
                            $m['price'] * $qty
                        ]);
                        break;
                    }
                }
            }

            $pdo->commit();
            $_SESSION['cart'] = [];   // clear cart after order

            // ── Build Telegram message ──
            $now       = date('d/m/Y H:i');
            $tgLines   = [];
            foreach ($_SESSION['cart'] as $itemId => $qty) { /* already cleared — use saved lines */ }
            // Re-fetch order lines for the message
            $savedLines = db()->prepare('SELECT item_name, quantity, unit_price FROM order_items WHERE order_id = ?');
            $savedLines->execute([$orderId]);
            foreach ($savedLines->fetchAll() as $row) {
                $tgLines[] = "  • {$row['item_name']} x{$row['quantity']} — $" . number_format($row['unit_price'] * $row['quantity'], 2);
            }
            $tableText = $tableNumber ? "\n🪑 <b>Table:</b> {$tableNumber}" : '';
            $noteText  = $note ? "\n📝 <b>Note:</b> " . htmlspecialchars($note) : '';
            $tgMessage = "🔔 <b>New Order #{$orderId}</b>\n"
                       . "👤 <b>Customer:</b> " . htmlspecialchars($customerName ?: 'Guest')
                       . $tableText . "\n"
                       . "🕐 <b>Time:</b> {$now}\n"
                       . "🍽 <b>Items:</b>\n" . implode("\n", $tgLines) . "\n"
                       . "💰 <b>Total: $" . number_format($total, 2) . "</b>"
                       . $noteText;
            sendTelegram($tgMessage);
            // ────────────────────────────

            echo json_encode([
                'success'  => true,
                'order_id' => $orderId,
                'message'  => "Order #$orderId placed successfully!"
            ]);
            exit;
        }

        // Return updated cart state
        $count = array_sum($_SESSION['cart']);
        $total = cartTotal($_SESSION['cart'], $menu);
        $lines = [];
        foreach ($_SESSION['cart'] as $id => $qty) {
            foreach ($menu as $m) {
                if ($m['id'] == $id) {
                    $lines[] = ['id' => $id, 'name' => $m['name'], 'qty' => $qty, 'price' => (float)$m['price']];
                    break;
                }
            }
        }
        echo json_encode(['cart' => $_SESSION['cart'], 'total' => $total, 'count' => $count, 'lines' => $lines]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Load menu from DB for page render ─────────
try {
    $menu = db()->query('SELECT * FROM menu_items WHERE active = 1 ORDER BY category, name')->fetchAll();
    $dbError = null;
} catch (PDOException $e) {
    $menu    = [];
    $dbError = $e->getMessage();
}

$categories  = array_unique(array_column($menu, 'category'));
$cartCount   = array_sum($_SESSION['cart']);
$cartTotalVal = cartTotal($_SESSION['cart'], $menu);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Taste of Asia</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f0eb; min-height: 100vh; }

    /* ── Header ── */
    .header { background: #D85A30; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; }
    .header-title { color: #fff; font-size: 22px; font-weight: 700; }
    .header-sub   { color: rgba(255,255,255,.75); font-size: 11px; margin-top: 2px; letter-spacing: .5px; }
    .cart-btn { background: #fff; border: none; border-radius: 50%; width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; font-size: 20px; }
    .cart-badge { position: absolute; top: -4px; right: -4px; background: #993C1D; color: #fff; border-radius: 50%; width: 20px; height: 20px; font-size: 11px; display: flex; align-items: center; justify-content: center; font-weight: 700; }

    /* ── DB error banner ── */
    .db-error { background: #ffeaea; border-left: 4px solid #e24b4a; padding: 12px 16px; font-size: 13px; color: #a32d2d; }

    /* ── Search ── */
    .search-bar { padding: 10px 16px; background: #fff; border-bottom: 1px solid #e8e0d8; }
    .search-bar input { width: 100%; padding: 9px 14px; border: 1px solid #ddd; border-radius: 24px; font-size: 14px; outline: none; background: #faf8f5; }
    .search-bar input:focus { border-color: #D85A30; }

    /* ── Tabs ── */
    .tabs { display: flex; gap: 8px; padding: 10px 16px; background: #fff; border-bottom: 1px solid #e8e0d8; overflow-x: auto; scrollbar-width: none; }
    .tabs::-webkit-scrollbar { display: none; }
    .tab { flex-shrink: 0; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid #ddd; background: #f5f0eb; color: #666; transition: all .15s; }
    .tab:hover  { border-color: #D85A30; color: #D85A30; }
    .tab.active { background: #D85A30; color: #fff; border-color: #D85A30; }

    /* ── Menu ── */
    .menu { max-width: 680px; margin: 0 auto; padding: 14px 16px 110px; display: flex; flex-direction: column; gap: 10px; }
    .menu-item { background: #fff; border-radius: 14px; border: 1px solid #ede8e1; padding: 12px; display: flex; gap: 12px; align-items: center; transition: box-shadow .15s; }
    .menu-item:hover { box-shadow: 0 2px 12px rgba(216,90,48,.08); }
    .item-emoji { width: 68px; height: 68px; border-radius: 10px; background: #fef3ee; display: flex; align-items: center; justify-content: center; font-size: 32px; flex-shrink: 0; }
    .item-info  { flex: 1; min-width: 0; }
    .item-name  { font-size: 15px; font-weight: 600; color: #222; }
    .item-cat   { font-size: 11px; color: #D85A30; font-weight: 500; margin-top: 2px; }
    .item-desc  { font-size: 12px; color: #888; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; }
    .price { font-size: 15px; font-weight: 700; color: #D85A30; }
    .qty-ctrl { display: flex; align-items: center; gap: 8px; }
    .qty-btn  { width: 28px; height: 28px; border-radius: 50%; border: none; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; font-weight: 600; transition: transform .1s; }
    .qty-btn:active { transform: scale(.9); }
    .minus { background: #f0ece8; color: #666; }
    .plus  { background: #D85A30; color: #fff; }
    .qty-num { font-size: 14px; font-weight: 600; min-width: 16px; text-align: center; color: #222; }

    /* ── Cart Bar ── */
    .cart-bar { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); width: calc(100% - 32px); max-width: 640px; background: #D85A30; color: #fff; border-radius: 14px; padding: 14px 20px; display: none; justify-content: space-between; align-items: center; cursor: pointer; box-shadow: 0 4px 24px rgba(216,90,48,.35); z-index: 200; }
    .cart-bar.visible { display: flex; }
    .cart-bar-label { font-size: 14px; font-weight: 600; }
    .cart-bar-total { font-size: 16px; font-weight: 700; }

    /* ── Modal ── */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; z-index: 300; }
    .modal-overlay.open { display: flex; align-items: flex-end; justify-content: center; }
    .modal { background: #fff; width: 100%; max-width: 680px; border-radius: 20px 20px 0 0; padding: 20px 20px 40px; max-height: 85vh; overflow-y: auto; }
    .modal-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
    .modal-close { background: #f0ece8; border: none; border-radius: 50%; width: 32px; height: 32px; font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #555; }
    .cart-line { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0ece8; font-size: 14px; }
    .cart-line-name  { font-weight: 500; }
    .cart-line-right { display: flex; align-items: center; gap: 12px; color: #555; }
    .cart-summary { margin-top: 16px; font-size: 16px; font-weight: 700; display: flex; justify-content: space-between; }

    /* ── Checkout form ── */
    .checkout-form { margin-top: 16px; display: flex; flex-direction: column; gap: 10px; }
    .checkout-form label { font-size: 13px; color: #666; font-weight: 500; }
    .checkout-form input,
    .checkout-form textarea { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 10px; font-size: 14px; font-family: inherit; outline: none; }
    .checkout-form input:focus,
    .checkout-form textarea:focus { border-color: #D85A30; }
    .checkout-form textarea { resize: vertical; min-height: 70px; }

    .checkout-btn { margin-top: 4px; width: 100%; padding: 14px; background: #D85A30; color: #fff; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; }
    .checkout-btn:hover    { background: #c04f28; }
    .checkout-btn:disabled { background: #ccc; cursor: not-allowed; }
    .clear-btn { margin-top: 8px; width: 100%; padding: 10px; background: #f0ece8; color: #888; border: none; border-radius: 12px; font-size: 14px; cursor: pointer; }
    .empty-cart { text-align: center; padding: 30px 0; color: #aaa; font-size: 14px; }

    /* ── Success screen ── */
    .order-success { text-align: center; padding: 30px 10px; }
    .order-success .icon { font-size: 52px; }
    .order-success h2 { font-size: 20px; font-weight: 700; margin: 12px 0 8px; color: #222; }
    .order-success p  { font-size: 14px; color: #888; }
    .order-id-badge { display: inline-block; margin-top: 12px; background: #fef3ee; color: #D85A30; font-weight: 700; padding: 6px 18px; border-radius: 20px; font-size: 14px; }

    /* ── Toast ── */
    .toast { position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%); background: #222; color: #fff; padding: 10px 20px; border-radius: 24px; font-size: 13px; opacity: 0; pointer-events: none; transition: opacity .3s; z-index: 400; white-space: nowrap; }
    .toast.show { opacity: 1; }
    .hidden { display: none !important; }
  </style>
</head>
<body>

<!-- Header -->
<div class="header">
  <div>
    <div class="header-title">Taste of Asia</div>
    <div class="header-sub">Scan · Browse · Order</div>
  </div>
  <button class="cart-btn" onclick="openCart()">
    🛒
    <span class="cart-badge" id="cart-badge" <?= $cartCount === 0 ? 'style="display:none"' : '' ?>>
      <?= $cartCount ?>
    </span>
  </button>
</div>

<?php if ($dbError): ?>
<div class="db-error">
  ⚠️ <strong>Database connection failed:</strong> <?= htmlspecialchars($dbError) ?><br>
  Check your DB_HOST, DB_USER, DB_PASS and DB_NAME settings at the top of this file,
  and make sure you have run <code>schema.sql</code>.
</div>
<?php endif; ?>

<!-- Search -->
<div class="search-bar">
  <input type="text" placeholder="Search dishes..." oninput="filterMenu(this.value)" />
</div>

<!-- Tabs -->
<div class="tabs">
  <div class="tab active" data-cat="All" onclick="setTab(this)">All</div>
  <?php foreach ($categories as $cat): ?>
    <div class="tab" data-cat="<?= htmlspecialchars($cat) ?>" onclick="setTab(this)">
      <?= htmlspecialchars($cat) ?>
    </div>
  <?php endforeach; ?>
</div>

<!-- Menu -->
<div class="menu" id="menu-list">
  <?php if (empty($menu)): ?>
    <p style="text-align:center;padding:40px;color:#aaa;font-size:14px;">
      No menu items found. Make sure you ran <code>schema.sql</code> first.
    </p>
  <?php endif; ?>
  <?php foreach ($menu as $item): ?>
    <?php $qty = $_SESSION['cart'][$item['id']] ?? 0; ?>
    <div class="menu-item"
         data-cat="<?= htmlspecialchars($item['category']) ?>"
         data-name="<?= strtolower(htmlspecialchars($item['name'])) ?>"
         data-desc="<?= strtolower(htmlspecialchars($item['description'])) ?>">
      <div class="item-emoji"><?= $item['emoji'] ?></div>
      <div class="item-info">
        <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
        <div class="item-cat"><?= htmlspecialchars($item['category']) ?></div>
        <div class="item-desc"><?= htmlspecialchars($item['description']) ?></div>
        <div class="item-footer">
          <span class="price">$<?= number_format($item['price'], 2) ?></span>
          <div class="qty-ctrl">
            <button class="qty-btn minus" id="minus-<?= $item['id'] ?>"
              style="<?= $qty === 0 ? 'display:none' : '' ?>"
              onclick="changeQty(<?= $item['id'] ?>, -1)">−</button>
            <span class="qty-num" id="qty-<?= $item['id'] ?>"
              style="<?= $qty === 0 ? 'display:none' : '' ?>"><?= $qty ?></span>
            <button class="qty-btn plus" onclick="changeQty(<?= $item['id'] ?>, 1)">+</button>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Cart Bar -->
<div class="cart-bar <?= $cartCount > 0 ? 'visible' : '' ?>" id="cart-bar" onclick="openCart()">
  <span class="cart-bar-label" id="cart-bar-label">
    <?= $cartCount ?> item<?= $cartCount !== 1 ? 's' : '' ?> in cart
  </span>
  <span class="cart-bar-total" id="cart-bar-total">
    $<?= number_format($cartTotalVal, 2) ?>
  </span>
</div>

<!-- Cart / Checkout Modal -->
<div class="modal-overlay" id="modal-overlay" onclick="closeCartIfOutside(event)">
  <div class="modal">
    <div class="modal-title">
      <span id="modal-heading">Your Cart</span>
      <button class="modal-close" onclick="closeCart()">✕</button>
    </div>

    <!-- Cart lines -->
    <div id="cart-lines"></div>

    <!-- Cart summary + checkout form -->
    <div id="cart-checkout-section" style="display:none">
      <div class="cart-summary">
        <span>Total</span>
        <span id="modal-total">$0.00</span>
      </div>
      <div class="checkout-form">
        <label for="cust-table">Table number</label>
        <input type="text" id="cust-table" placeholder="e.g. 3" maxlength="10" />
        <label for="cust-name">Your name (optional)</label>
        <input type="text" id="cust-name" placeholder="e.g. John" maxlength="100" />
        <label for="cust-note">Special instructions (optional)</label>
        <textarea id="cust-note" placeholder="Allergies, spice level…"></textarea>
      </div>
      <button class="checkout-btn" id="checkout-btn" onclick="submitOrder()">Place Order</button>
      <button class="clear-btn" onclick="clearCart()">Clear Cart</button>
    </div>

    <!-- Success screen (hidden until order placed) -->
    <div id="order-success" style="display:none" class="order-success">
      <div class="icon">🎉</div>
      <h2>Order Placed!</h2>
      <p>Your order has been saved to the database.<br>We'll start preparing it right away.</p>
      <div class="order-id-badge" id="order-id-badge">Order #—</div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
let cart  = <?= json_encode($_SESSION['cart'], JSON_FORCE_OBJECT) ?>;
const prices = {};
<?php foreach ($menu as $item): ?>
prices[<?= $item['id'] ?>] = <?= (float)$item['price'] ?>;
<?php endforeach; ?>

let activeTab = 'All';
let searchQ   = '';

// ── AJAX ──
async function post(body) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(body)) fd.append(k, v);
  const res  = await fetch('', { method: 'POST', body: fd });
  return res.json();
}

// ── Qty change ──
async function changeQty(id, delta) {
  const data = await post({ action: delta > 0 ? 'add' : 'remove', id });
  cart = data.cart;
  const qty   = cart[id] || 0;
  document.getElementById('qty-'   + id).textContent    = qty;
  document.getElementById('qty-'   + id).style.display  = qty > 0 ? '' : 'none';
  document.getElementById('minus-' + id).style.display  = qty > 0 ? '' : 'none';
  updateCartBar(data.count, data.total);
  showToast(delta > 0 ? 'Added to cart' : 'Removed from cart');
}

// ── Cart bar ──
function updateCartBar(count, total) {
  const badge = document.getElementById('cart-badge');
  badge.textContent   = count;
  badge.style.display = count > 0 ? 'flex' : 'none';
  const bar = document.getElementById('cart-bar');
  if (count > 0) {
    bar.classList.add('visible');
    document.getElementById('cart-bar-label').textContent = count + ' item' + (count !== 1 ? 's' : '') + ' in cart';
    document.getElementById('cart-bar-total').textContent = '$' + total.toFixed(2);
  } else {
    bar.classList.remove('visible');
  }
}

// ── Open / Close cart modal ──
function openCart() {
  const lines = [];
  let total = 0;
  for (const [id, qty] of Object.entries(cart)) {
    const p = prices[id] || 0;
    total += p * qty;
    const nameEl = document.querySelector(`#menu-list .menu-item:has(#qty-${id}) .item-name`);
    lines.push({ name: nameEl ? nameEl.textContent : 'Item', qty, price: p });
  }
  renderCartModal(lines, total);
  document.getElementById('order-success').style.display      = 'none';
  document.getElementById('cart-lines').style.display         = '';
  document.getElementById('cart-checkout-section').style.display = lines.length ? '' : 'none';
  document.getElementById('modal-heading').textContent        = 'Your Cart';
  document.getElementById('modal-overlay').classList.add('open');
}

function closeCart() {
  document.getElementById('modal-overlay').classList.remove('open');
}
function closeCartIfOutside(e) {
  if (e.target === document.getElementById('modal-overlay')) closeCart();
}

function renderCartModal(lines, total) {
  const el = document.getElementById('cart-lines');
  if (!lines.length) {
    el.innerHTML = '<div class="empty-cart">Your cart is empty</div>';
    document.getElementById('cart-checkout-section').style.display = 'none';
  } else {
    el.innerHTML = lines.map(l =>
      `<div class="cart-line">
        <span class="cart-line-name">${l.name}</span>
        <span class="cart-line-right">x${l.qty}&nbsp;&nbsp;$${(l.price * l.qty).toFixed(2)}</span>
      </div>`
    ).join('');
    document.getElementById('modal-total').textContent = '$' + total.toFixed(2);
    document.getElementById('cart-checkout-section').style.display = '';
  }
}

// ── Submit order ──
async function submitOrder() {
  const btn  = document.getElementById('checkout-btn');
  btn.disabled = true;
  btn.textContent = 'Saving order…';
  const data = await post({
    action:        'checkout',
    customer_name: document.getElementById('cust-name').value,
    table_number:  document.getElementById('cust-table').value,
    note:          document.getElementById('cust-note').value,
  });
  if (data.success) {
    cart = {};
    updateCartBar(0, 0);
    document.querySelectorAll('.qty-num, [id^="minus-"]').forEach(el => el.style.display = 'none');
    document.getElementById('cart-lines').style.display             = 'none';
    document.getElementById('cart-checkout-section').style.display  = 'none';
    document.getElementById('order-id-badge').textContent           = 'Order #' + data.order_id;
    document.getElementById('order-success').style.display          = '';
    document.getElementById('modal-heading').textContent            = 'Order Confirmed';
  } else {
    showToast('Error: ' + data.message);
    btn.disabled    = false;
    btn.textContent = 'Place Order';
  }
}

// ── Clear cart ──
async function clearCart() {
  await post({ action: 'clear' });
  cart = {};
  updateCartBar(0, 0);
  document.querySelectorAll('.qty-num, [id^="minus-"]').forEach(el => el.style.display = 'none');
  closeCart();
  showToast('Cart cleared');
}

// ── Filter / Search ──
function setTab(el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  activeTab = el.dataset.cat;
  applyFilter();
}
function filterMenu(q) { searchQ = q.toLowerCase(); applyFilter(); }
function applyFilter() {
  document.querySelectorAll('.menu-item').forEach(item => {
    const matchTab    = activeTab === 'All' || item.dataset.cat === activeTab;
    const matchSearch = !searchQ || item.dataset.name.includes(searchQ) || item.dataset.desc.includes(searchQ);
    item.classList.toggle('hidden', !(matchTab && matchSearch));
  });
}

// ── Toast ──
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(t._t);
  t._t = setTimeout(() => t.classList.remove('show'), 1800);
}
</script>
</body>
</html>