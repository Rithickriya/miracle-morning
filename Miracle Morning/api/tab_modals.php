<!-- Edit Modal -->
<div class="edit-modal-overlay" id="editOverlay" style="display:none" onclick="if(event.target===this)closeEdit()">
  <div class="edit-modal">
    <h3 id="editTitle">Edit Payment</h3>
    <div class="edit-field"><label>Amount (₹)</label><input type="number" id="editAmt" min="1" placeholder="Amount"></div>
    <div class="edit-field">
      <label>Payment Mode</label>
      <select id="editMode">
        <option value="FinCloud">FinCloud</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Cash">Cash</option>
        <option value="Card">Card</option>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button onclick="closeEdit()" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="saveEdit()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<!-- Visitor Paid Modal -->
<div class="edit-modal-overlay" id="visitorPayOverlay" style="display:none" onclick="if(event.target===this)closeVisitorPay()">
  <div class="edit-modal">
    <h3 style="color:#1565c0">✓ Visitor Payment</h3>
    <div style="font-size:.82rem;color:var(--gry);margin-bottom:14px" id="vpay_name"></div>
    <div class="edit-field">
      <label>Payment Method *</label>
      <select id="vpay_mode">
        <option value="Cash">Cash</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Card">Card</option>
        <option value="FinCloud">FinCloud</option>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button onclick="closeVisitorPay()" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="confirmVisitorPay()" style="background:#1565c0;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer">Confirm Paid</button>
    </div>
  </div>
</div>

<!-- Paid by Member Modal -->
<div class="edit-modal-overlay" id="memberPayOverlay" style="display:none" onclick="if(event.target===this)closeMemberPay()">
  <div class="edit-modal" style="width:400px">
    <h3 style="color:#6a1b9a">Paid by Member</h3>
    <div style="font-size:.82rem;color:var(--gry);margin-bottom:14px" id="mpay_name"></div>
    <div class="edit-field">
      <label>Member paying *</label>
      <input type="text" id="mpay_search" placeholder="Type member name..." oninput="filterMpayList(this.value)" autocomplete="off">
      <div id="mpay_list" style="max-height:160px;overflow-y:auto;border:1px solid var(--bdr);border-radius:8px;margin-top:4px;display:none;background:#fff;position:relative;z-index:10"></div>
      <input type="hidden" id="mpay_member_id">
      <div id="mpay_selected" style="display:none;margin-top:6px;padding:6px 10px;background:var(--rlt);border-radius:8px;font-size:.8rem;font-weight:600;color:var(--red)"></div>
    </div>
    <div class="edit-field" style="margin-top:12px">
      <label>When will they pay?</label>
      <div style="display:flex;gap:8px;margin-top:6px">
        <button type="button" id="mpay_now_btn" onclick="setPayWhen('now')"
                style="flex:1;padding:8px;border-radius:8px;border:2px solid var(--red);background:var(--rlt);color:var(--red);font-weight:700;font-size:.8rem;cursor:pointer">
          Pay Now
        </button>
        <button type="button" id="mpay_later_btn" onclick="setPayWhen('later')"
                style="flex:1;padding:8px;border-radius:8px;border:2px solid var(--bdr);background:#fff;color:var(--gry);font-weight:700;font-size:.8rem;cursor:pointer">
          Collect Later
        </button>
      </div>
    </div>
    <div class="edit-field" id="mpay_mode_field">
      <label>Payment Method</label>
      <select id="mpay_mode">
        <option value="Cash">Cash</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Card">Card</option>
        <option value="FinCloud">FinCloud</option>
      </select>
    </div>
    <div id="mpay_later_note" style="display:none;padding:8px 12px;background:#fff8e1;border-radius:8px;font-size:.78rem;color:#c47800;border:1px solid #ffe082">
      Visitor will be marked as entered. Member due will appear in queue for later collection.
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button onclick="closeMemberPay()" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="confirmMemberPay()" style="background:#6a1b9a;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer">Confirm</button>
    </div>
  </div>
</div>

<!-- Settle Due Modal -->
<div class="edit-modal-overlay" id="settleOverlay" style="display:none" onclick="if(event.target===this)closeSettle()">
  <div class="edit-modal">
    <h3 style="color:#6a1b9a">Collect Visitor Due</h3>
    <div class="edit-field">
      <label>Payment Method</label>
      <select id="settle_mode">
        <option value="Cash">Cash</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Card">Card</option>
        <option value="FinCloud">FinCloud</option>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button onclick="closeSettle()" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="confirmSettle()" style="background:#6a1b9a;color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer">Mark Collected</button>
    </div>
  </div>
</div>

<!-- NEW: Edit Member Session Modal (for Live Desk) -->
<div class="edit-modal-overlay" id="editMemberSessionModal" style="display:none" onclick="if(event.target===this)closeModal('editMemberSessionModal')">
  <div class="edit-modal" style="width:380px">
    <h3>Edit Member Payment Session</h3>
    <input type="hidden" id="mems_member_id">
    <input type="hidden" id="mems_session_id">
    <div class="edit-field"><label>Total Amount (₹)</label><input type="number" id="mems_total" min="1" step="1"></div>
    <div class="edit-field"><label>Paid Date (YYYY-MM-DD)</label><input type="date" id="mems_paid_date"></div>
    <div class="edit-field">
      <label>Payment Mode</label>
      <select id="mems_mode">
        <option value="Cash">Cash</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Card">Card</option>
        <option value="FinCloud">FinCloud</option>
      </select>
    </div>
    <div class="edit-field">
      <label>Status</label>
      <select id="mems_status">
        <option value="Paid">Paid</option>
        <option value="Pending">Pending</option>
        <option value="Rejected">Rejected</option>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button onclick="closeModal('editMemberSessionModal')" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="saveMemberSessionEdit()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<!-- NEW: Edit Kitty Modal (for Live Desk) -->
<div class="edit-modal-overlay" id="editKittyModal" style="display:none" onclick="if(event.target===this)closeModal('editKittyModal')">
  <div class="edit-modal" style="width:340px">
    <h3>Edit Kitty Payment</h3>
    <input type="hidden" id="kitty_id">
    <div class="edit-field"><label>Amount (₹)</label><input type="number" id="kitty_amount" min="1" step="1"></div>
    <div class="edit-field">
      <label>Payment Mode</label>
      <select id="kitty_mode">
        <option value="Cash">Cash</option>
        <option value="UPI">QR Code (UPI)</option>
        <option value="Card">Card</option>
        <option value="FinCloud">FinCloud</option>
      </select>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px">
      <button onclick="closeModal('editKittyModal')" style="background:none;border:1px solid var(--bdr);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer">Cancel</button>
      <button onclick="saveKittyEdit()" style="background:var(--red);color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:.82rem;font-weight:600;cursor:pointer">Save</button>
    </div>
  </div>
</div>

<script>
// ── Existing modal functions (closeEdit, saveEdit, openVisitorPay, closeVisitorPay, confirmVisitorPay, openMemberPay, closeMemberPay, setPayWhen, filterMpayList, selectMpayMember, confirmMemberPay, closeSettle, confirmSettle) are already defined elsewhere.
// We keep them untouched; only add new functions for the new modals.

// Generic close function for any modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Member Session Edit functions
function openEditMemberSessionModal(memberId, sessionId, total, paidDate, mode, status) {
    document.getElementById('mems_member_id').value = memberId;
    document.getElementById('mems_session_id').value = sessionId;
    document.getElementById('mems_total').value = total;
    document.getElementById('mems_paid_date').value = paidDate;
    document.getElementById('mems_mode').value = mode;
    document.getElementById('mems_status').value = status;
    document.getElementById('editMemberSessionModal').style.display = 'flex';
}

function saveMemberSessionEdit() {
    var memberId = document.getElementById('mems_member_id').value;
    var sessionId = document.getElementById('mems_session_id').value;
    var total = parseInt(document.getElementById('mems_total').value);
    var paidDate = document.getElementById('mems_paid_date').value;
    var mode = document.getElementById('mems_mode').value;
    var status = document.getElementById('mems_status').value;

    if (isNaN(total) || total <= 0) {
        alert('Please enter a valid total amount.');
        return;
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(paidDate)) {
        alert('Please enter a valid paid date (YYYY-MM-DD).');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'edit_member_session');
    fd.append('member_id', memberId);
    fd.append('session_id', sessionId);
    fd.append('paid_date', paidDate);
    fd.append('total_amount', total);
    fd.append('mode', mode);
    fd.append('status', status);

    fetch('member_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                location.reload();
            } else {
                alert('Error: ' + (d.msg || 'Failed'));
            }
        })
        .catch(e => alert('Request failed: ' + e.message));
}

// Kitty Edit functions
function openEditKittyModal(id, amount, mode) {
    document.getElementById('kitty_id').value = id;
    document.getElementById('kitty_amount').value = amount;
    document.getElementById('kitty_mode').value = mode;
    document.getElementById('editKittyModal').style.display = 'flex';
}

function saveKittyEdit() {
    var id = document.getElementById('kitty_id').value;
    var amount = parseInt(document.getElementById('kitty_amount').value);
    var mode = document.getElementById('kitty_mode').value;

    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount.');
        return;
    }

    var fd = new FormData();
    fd.append('action', 'edit_kitty');
    fd.append('id', id);
    fd.append('amount', amount);
    fd.append('mode', mode);
    fd.append('status', 'Paid'); // Kitty payments are always paid

    fetch('member_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                location.reload();
            } else {
                alert('Error: ' + (d.msg || 'Failed'));
            }
        })
        .catch(e => alert('Request failed: ' + e.message));
}
</script>