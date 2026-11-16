/**
 * PharmaSys Hub - Smart POS Billing & WhatsApp Cloud API Engine
 * Strict Business Logic: Recalled & Expired batch blocks
 */

let cart = [];
const GST_RATE = 0.12;
const MIN_CART_QTY = 1; // Default standard unit order quantity

document.addEventListener('DOMContentLoaded', () => {
  renderCart();
});

// Add item to POS Cart with strict safety validation
function addToPosCart(item) {
  const alertBox = document.getElementById('pos-safety-alert');

  // STRICT CHECK 1: Smart Batch Recall
  if (parseInt(item.is_recalled) === 1) {
    if (alertBox) {
      alertBox.style.display = 'flex';
      alertBox.className = 'alert alert-danger';
      alertBox.innerHTML = `
        <div>
          <strong>STRICT DISPENSING LOCKOUT &mdash; RECALLED BATCH:</strong><br>
          Batch <code>${item.batch_number}</code> (${item.brand_name}) has been blacklisted by the Manufacturer Quality Assurance team.<br>
          <em>Reason: "${item.recall_reason || 'QA purity assay threshold breached'}"</em>.<br>
          <strong>Dispensing this medication is strictly illegal under pharmacy compliance regulations.</strong>
        </div>
      `;
    }
    showToast(`LOCKOUT: Batch ${item.batch_number} is blacklisted by manufacturer!`, 'danger');
    return;
  }

  // STRICT CHECK 2: Calendar Date Expiry Matching
  if (parseInt(item.is_expired) === 1) {
    if (alertBox) {
      alertBox.style.display = 'flex';
      alertBox.className = 'alert alert-danger';
      alertBox.innerHTML = `
        <div>
          <strong>STRICT DISPENSING LOCKOUT &mdash; EXPIRED MEDICINE:</strong><br>
          Batch <code>${item.batch_number}</code> (${item.brand_name}) has crossed its expiration date (<strong>${item.expiry_date}</strong>).<br>
          <strong>Dispensing expired pharmaceuticals is blocked.</strong>
        </div>
      `;
    }
    showToast(`LOCKOUT: Batch ${item.batch_number} has expired!`, 'danger');
    return;
  }

  // Hide alert box if clean
  if (alertBox) {
    alertBox.style.display = 'none';
  }

  // Stock limit verification
  const existing = cart.find(c => c.batch_id === item.batch_id);
  if (existing) {
    if (existing.qty + 1 > item.quantity) {
      showToast(`Cannot exceed physical stock (${item.quantity} available on shelf)`, 'warning');
      return;
    }
    existing.qty += 1;
    showToast(`Added +1 ${item.brand_name} to cart (Total: ${existing.qty})`, 'success');
  } else {
    if (item.quantity <= 0) {
      showToast(`No stock available on shelf for ${item.brand_name}`, 'warning');
      return;
    }
    cart.push({
      batch_id: item.batch_id,
      batch_number: item.batch_number,
      name: item.brand_name,
      price: parseFloat(item.price_mrp),
      qty: 1,
      max_stock: item.quantity
    });
    showToast(`Added ${item.brand_name} to cart`, 'success');
  }

  renderCart();
}

function updateCartQty(batchId, delta) {
  const item = cart.find(c => c.batch_id === batchId);
  if (!item) return;

  const newQty = item.qty + delta;
  if (newQty <= 0) {
    removeFromCart(batchId);
    return;
  } else if (newQty > item.max_stock) {
    showToast(`Maximum shelf stock available is ${item.max_stock}`, 'warning');
    return;
  } else {
    item.qty = newQty;
  }
  renderCart();
}

function setCartQty(batchId, val) {
  const item = cart.find(c => c.batch_id === batchId);
  if (!item) return;

  let qty = parseInt(val, 10);
  if (isNaN(qty) || qty <= 0) {
    qty = 1;
  } else if (qty > item.max_stock) {
    qty = item.max_stock;
    showToast(`Maximum shelf stock available is ${item.max_stock}`, 'warning');
  }
  item.qty = qty;
  renderCart();
}

function removeFromCart(batchId) {
  cart = cart.filter(c => c.batch_id !== batchId);
  renderCart();
}

function renderCart() {
  const container = document.getElementById('cart-items-container');
  const emptyNotice = document.getElementById('cart-empty-notice');
  const subtotalEl = document.getElementById('cart-subtotal');
  const gstEl = document.getElementById('cart-gst');
  const grandTotalEl = document.getElementById('cart-grand-total');
  const checkoutBtn = document.getElementById('btn-checkout');

  if (!container) return;

  container.innerHTML = '';

  if (cart.length === 0) {
    if (emptyNotice) emptyNotice.style.display = 'block';
    if (subtotalEl) subtotalEl.textContent = '₹0.00';
    if (gstEl) gstEl.textContent = '₹0.00';
    if (grandTotalEl) grandTotalEl.textContent = '₹0.00';
    if (checkoutBtn) checkoutBtn.disabled = true;
    return;
  }

  if (emptyNotice) emptyNotice.style.display = 'none';
  if (checkoutBtn) checkoutBtn.disabled = false;

  let subtotal = 0;

  cart.forEach(item => {
    const lineTotal = item.price * item.qty;
    subtotal += lineTotal;

    const row = document.createElement('div');
    row.style.display = 'flex';
    row.style.justifyContent = 'space-between';
    row.style.alignItems = 'center';
    row.style.padding = '0.65rem 0';
    row.style.borderBottom = '1px solid var(--border-light)';

    row.innerHTML = `
      <div>
        <div style="font-weight: 600; font-size: 0.85rem;">${item.name}</div>
        <div style="font-size: 0.725rem; color: var(--text-subtle);">Batch: ${item.batch_number} &bull; ₹${item.price.toFixed(2)}/unit</div>
      </div>
      <div style="display: flex; align-items: center; gap: 0.4rem;">
        <button type="button" class="btn btn-outline btn-sm" style="padding: 0.15rem 0.5rem;" onclick="updateCartQty(${item.batch_id}, -1)" title="Decrease quantity">-</button>
        <input type="number" min="1" max="${item.max_stock}" value="${item.qty}"
               style="width: 52px; text-align: center; font-weight: 700; font-size: 0.85rem; padding: 0.2rem; border: 1px solid var(--border-light); border-radius: 4px;"
               onchange="setCartQty(${item.batch_id}, this.value)" title="Quantity">
        <button type="button" class="btn btn-outline btn-sm" style="padding: 0.15rem 0.5rem;" onclick="updateCartQty(${item.batch_id}, 1)" title="Increase quantity">+</button>
        <span style="font-weight: 700; min-width: 75px; text-align: right; font-size: 0.85rem;">₹${lineTotal.toFixed(2)}</span>
        <button type="button" class="btn btn-outline btn-sm" style="color: var(--color-danger); border: none; padding: 0.15rem 0.4rem; font-size: 1.1rem; line-height: 1;" onclick="removeFromCart(${item.batch_id})" title="Remove item">&times;</button>
      </div>
    `;
    container.appendChild(row);
  });

  const gst = subtotal * GST_RATE;
  const grandTotal = subtotal + gst;

  if (subtotalEl) subtotalEl.textContent = `₹${subtotal.toFixed(2)}`;
  if (gstEl) gstEl.textContent = `₹${gst.toFixed(2)}`;
  if (grandTotalEl) grandTotalEl.textContent = `₹${grandTotal.toFixed(2)}`;
}

// POS Checkout
async function processCheckout() {
  const nameInput = document.getElementById('customer-name');
  const phoneInput = document.getElementById('customer-phone');
  const payMode = document.getElementById('payment-mode');

  const name = nameInput ? nameInput.value.trim() : 'Walk-in Patient';
  const phone = phoneInput ? phoneInput.value.trim() : '';

  if (!phone || phone.length < 10) {
    showToast('Please enter a valid 10-digit mobile number for WhatsApp E-Billing', 'warning');
    if (phoneInput) phoneInput.focus();
    return;
  }

  const checkoutBtn = document.getElementById('btn-checkout');
  if (checkoutBtn) {
    checkoutBtn.disabled = true;
    checkoutBtn.textContent = 'Processing & Sending WhatsApp E-Bill...';
  }

  try {
    const payload = {
      consumer_name: name,
      consumer_phone: phone,
      payment_mode: payMode ? payMode.value : 'UPI',
      cart_items: cart.map(c => ({
        batch_id: c.batch_id,
        quantity: c.qty,
        unit_price: c.price
      }))
    };

    const res = await fetch('api/pos_checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await res.json();

    if (data.success) {
      showToast(`Invoice ${data.invoice_number} created! WhatsApp e-bill dispatched.`, 'success');
      renderWhatsAppPreview(data, phone);
      cart = [];
      renderCart();
    } else {
      showToast(`DISPENSE BLOCKED: ${data.error}`, 'danger');
      const alertBox = document.getElementById('pos-safety-alert');
      if (alertBox) {
        alertBox.style.display = 'flex';
        alertBox.className = 'alert alert-danger';
        alertBox.innerHTML = `<div><strong>SAFETY LOCKOUT:</strong> ${data.error}</div>`;
      }
    }
  } catch (err) {
    showToast('Network error during checkout', 'danger');
  } finally {
    if (checkoutBtn) {
      checkoutBtn.disabled = false;
      checkoutBtn.textContent = 'Complete Dispense & Send WhatsApp E-Bill';
    }
  }
}

function renderWhatsAppPreview(data, phone) {
  const modal = document.getElementById('whatsapp-preview-modal');
  const body = document.getElementById('whatsapp-preview-content');
  if (!modal || !body) return;

  const wa = data.whatsapp || {};
  const previewText = wa.rendered_body || `Tax Invoice: ${data.invoice_number} for ₹${data.grand_total}`;

  body.innerHTML = `
    <div style="background: #EFEAE2; border-radius: 8px; padding: 1rem; border: 1px solid #D1D7DB; font-family: var(--font-mono); white-space: pre-wrap; font-size: 0.8rem; color: #111B21; line-height: 1.5; max-height: 380px; overflow-y: auto;">
${previewText}
    </div>
    <div style="margin-top: 1rem; display: flex; align-items: center; justify-content: space-between;">
      <span class="badge badge-success">Delivered via Meta WhatsApp Cloud API to +91 ${phone}</span>
      <button class="btn btn-outline btn-sm" onclick="closeWhatsAppModal()">Close</button>
    </div>
  `;

  modal.style.display = 'flex';
}

function closeWhatsAppModal() {
  const modal = document.getElementById('whatsapp-preview-modal');
  if (modal) modal.style.display = 'none';
}

function autoAddPrescribedMedicines(medsString) {
  if (!medsString) return;
  const items = medsString.split(/[\n,]+/);
  let addedCount = 0;

  items.forEach(rawItem => {
    const medName = rawItem.trim().toLowerCase();
    if (!medName) return;

    const rows = document.querySelectorAll('.pos-item-row');
    let matchedRow = null;

    for (const r of rows) {
      if (r.getAttribute('data-unsafe') === '1') continue;
      const brand = (r.getAttribute('data-brand') || '').toLowerCase();
      const search = (r.getAttribute('data-search') || '').toLowerCase();
      
      if (brand && (medName.includes(brand) || brand.includes(medName))) {
        matchedRow = r;
        break;
      }
    }

    if (matchedRow) {
      const addBtn = matchedRow.querySelector('button[onclick*="addToPosCart"]');
      if (addBtn) {
        addBtn.click();
        addedCount++;
      }
    }
  });

  if (addedCount > 0) {
    showToast(`Added ${addedCount} prescribed medication(s) to billing cart!`, 'success');
  } else {
    showToast('Prescribed medicines located on shelf. Please review lots to add.', 'info');
  }
}

window.addToPosCart = addToPosCart;
window.updateCartQty = updateCartQty;
window.setCartQty = setCartQty;
window.removeFromCart = removeFromCart;
window.processCheckout = processCheckout;
window.closeWhatsAppModal = closeWhatsAppModal;
window.autoAddPrescribedMedicines = autoAddPrescribedMedicines;
