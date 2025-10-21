<?php
// ---- Prefills from query string ----
$case_number_prefill = isset($_GET['case_number']) ? htmlspecialchars($_GET['case_number']) : '';
$phone_number_prefill = isset($_GET['phone_number']) ? htmlspecialchars($_GET['phone_number']) : '';
$currentPage = basename($_SERVER['PHP_SELF']);

// Server time (display only)
$serverTime = new DateTime('now', new DateTimeZone('Australia/Sydney'));
$now = $serverTime->format('Y-m-d\TH:i'); // for datetime-local input

// DB for related cases (if phone_number provided)
$related_rows = [];
$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid" => "sa",
  "PWD" => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

// Optional related list only if phone provided and DB available
if ($conn && $phone_number_prefill !== '') {
    // Fetch rows for same phone, excluding current case_number if provided
    $sql = "SELECT CONVERT(VARCHAR(19), date_time, 120) AS date_time_str, * 
            FROM mwcsp_caser
            WHERE phone_number = ?
              AND (? = '' OR case_number <> ?)
            ORDER BY 
              CASE 
                WHEN LOWER(status)='open' THEN 1
                WHEN LOWER(status)='escalated' THEN 2
                ELSE 3
              END, date_time ASC";
    $params = [ $phone_number_prefill, $case_number_prefill, $case_number_prefill ];
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt) {
        while($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $related_rows[] = $r;
        }
    }
}

// Audio discovery (HTTP directory listing) used by related list
function scanRemoteDir($url, $depth=0, $maxDepth=2, &$visited=[]) {
    $mp3_files = [];
    $url = rtrim($url,'/');

    if($depth > $maxDepth || in_array($url, $visited)) return [];
    $visited[] = $url;

    $context = stream_context_create(['http'=>['timeout'=>5]]);
    $html = @file_get_contents($url, false, $context);
    if($html === false) return [];

    preg_match_all('/href="([^"]+)"/i', $html, $matches);
    if(!empty($matches[1])){
        foreach($matches[1] as $link){
            if($link == '../' || $link == './') continue;

            if(preg_match('#^https?://#i', $link)) {
                $full_link = $link;
            } elseif(substr($link,0,1) === '/') {
                $full_link = 'http://192.168.1.154' . $link;
            } else {
                $full_link = $url . '/' . ltrim($link, '/');
            }

            if(preg_match('/\.mp3$/i', $link)){
                $mp3_files[] = $full_link;
            } elseif(substr($link,-1) == '/'){
                $mp3_files = array_merge($mp3_files, scanRemoteDir($full_link, $depth+1, $maxDepth, $visited));
            }
        }
    }
    return $mp3_files;
}
$audio_dir_url = "http://192.168.1.154/secrecord";
$mp3_files = scanRemoteDir($audio_dir_url);

// For colouring rows / age calc in related list
$serverTimezone = new DateTimeZone(date_default_timezone_get());
$nowDT = new DateTime("now", $serverTimezone);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MWCSP Case Form</title>
<link rel="stylesheet" href="css/style.css">
<style>
/* Header / Navbar */
.header {
    background: #0073e6;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header a {
    color: #fff;
    text-decoration: none;
    padding: 8px 16px;
    background: #005bb5;
    border-radius: 5px;
    font-size: 16px;
    font-weight: 500;
}
.header a.active { background: #003f7f; }
.header a:hover  { background: #003f7f; }

body { font-family: Arial, sans-serif; background: #f7f9fc; padding: 0; margin:0; }
.container { max-width: 700px; margin: 20px auto; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
h2 { text-align: center; margin-bottom: 15px; color: #0073e6; }
label { font-weight: bold; margin-bottom: 3px; display: block; }
input, select, textarea { width: 100%; box-sizing: border-box; padding: 6px; margin-bottom: 10px; border-radius: 5px; border: 1px solid #ccc; font-size: 14px; }
input[type="datetime-local"] { height: 34px; }
textarea { resize: vertical; height: 60px; }
button { background: #0073e6; color: #fff; border: none; padding: 8px 18px; border-radius: 5px; cursor: pointer; margin-top: 10px; font-size: 14px; }
button:hover { background: #005bb5; }
.required { color: red; }

/* Two-column layout */
.row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.col-half { flex: 1 1 calc(50% - 5px); }
@media(max-width: 600px){ .col-half { flex: 1 1 100%; } }
.col-half input, .col-half select, .col-half textarea { width: 100%; }

/* Informed Consent row adjustments */
.consent-row { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }

/* Related cases table styles (match cases.php) */
.related-card { max-width: 1100px; margin: 26px auto; padding: 14px 16px 20px; background:#fff; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.1); }
.related-title { text-align:center; color:#0073e6; margin:8px 0 12px; }

table { width: 100%; border-collapse: collapse; margin-top: 12px; }
th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
thead tr { background:#0073e6; color:#fff; }
tbody tr:nth-child(even) { background:#f2f6fb; }

/* Row highlights */
.highlight-orange { background-color: #fff4df !important; }  /* Open >2h */
.highlight-red    { background-color: #ffd6d6 !important; }  /* Open ≥24h */
.highlight-green  { background-color: #dff5df !important; }  /* Closed */
.highlight-blue   { background-color: #d9ecff !important; }  /* Escalated */

/* Modals shared */
.modal { display: none; position: fixed; padding-top: 100px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);}
#detailsModal { z-index: 2000; }
#notesModal   { z-index: 3000; }

.modal-content { background-color: #fff; margin: auto; padding: 20px; border-radius: 10px; width: 80%; max-width: 640px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; }
.modal-content h3 { margin: 0 0 10px 0; color:#0073e6; }
.close { color: #aaa; position: absolute; top: 10px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; }
.close:hover { color: #000; }

.notes-box {
  border: 1px solid #d3def5;
  background: #f3f7ff;
  padding: 12px;
  border-radius: 8px;
  max-height: 360px;
  overflow: auto;
  line-height: 1.4;
  font-size: 14px;
  white-space: pre-wrap;
}
.notes-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }
.btn {
  display:inline-block;
  background:#0073e6;
  color:#fff !important;
  padding:8px 14px;
  border-radius:6px;
  text-decoration:none;
  border:1px solid #005bb5;
}
.btn:hover { background:#005bb5; }
#attachmentModal   { z-index: 3050; }
.attachment-modal .modal-content {
  max-width: 900px;
  width: 90%;
  height: 80vh;
  display: flex;
  flex-direction: column;
}
.attachment-modal .modal-content h3 { margin-bottom: 12px; }
.attachment-body {
  flex: 1;
  border: 1px solid #dde4f2;
  border-radius: 8px;
  background: #f9fbff;
  overflow: hidden;
}
.attachment-body iframe {
  width: 100%;
  height: 100%;
  border: none;
  background: #fff;
}
.attachment-actions {
  margin-top: 15px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

/* Details modal look (striped) */
.details-wrap {
  border: 1px solid #d3def5;
  background: #f3f7ff;
  border-radius: 8px;
  padding: 0;
  overflow: hidden;
}
.details-header {
  background: #e6f0ff;
  color: #005bb5;
  padding: 10px 12px;
  font-weight: 600;
  border-bottom: 1px solid #c9d9ff;
}
.details-table { width: 100%; border-collapse: collapse; }
.details-table th, .details-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #dbe6ff;
  text-align: left;
  font-size: 14px;
}
.details-table tbody tr:nth-child(even) { background: #eef4ff; }
.details-actions { display:flex; justify-content:flex-end; gap:10px; padding: 10px 12px; background:#f3f7ff; }
</style>

<script>
// --- Google Maps popup helper (same as cases.php) ---
function openMapPopup(addr){
  if(!addr) return;
  const url = "https://www.google.com/maps?q=" + encodeURIComponent(addr);
  window.open(url, "mapPopup", "width=800,height=600,menubar=0,toolbar=0,location=0,status=0");
}
</script>
</head>
<body>

<!-- Header / Navbar -->
<div class="header">
  <a href="form.php" class="<?php echo ($currentPage=='form.php')?'active':''; ?>">➕ New Case</a>
  <a href="cases.php" class="<?php echo ($currentPage=='cases.php')?'active':''; ?>">📋 Case List</a>
  <a href="search.php" class="<?php echo ($currentPage=='search.php')?'active':''; ?>">🔍 Search Cases</a>
  <a href="dashboard.php" class="<?php echo ($currentPage=='dashboard.php')?'active':''; ?>">📊 Dashboard</a>
</div>

<div class="container">
<h2>MWCSP Case Form</h2>
<form action="submit_case.php" method="post">

  <!-- Top row: Date/Time & Case Number -->
  <div class="row">
      <div class="col-half">
          <label for="date_time">Date and Time:</label>
          <input type="datetime-local" id="date_time" name="date_time" value="<?php echo $now; ?>" readonly>
      </div>
      <div class="col-half">
          <label for="case_number">Case Number: <span class="required">*</span></label>
          <input type="text" id="case_number" name="case_number" maxlength="15" value="<?php echo $case_number_prefill; ?>" required>
      </div>
  </div>

  <!-- Informed Consent & Phone Number -->
  <div class="row">
      <div class="col-half consent-row">
          <label for="informed_consent">Informed Consent <span class="required">*</span></label>
          <input type="checkbox" id="informed_consent" name="informed_consent" value="1" required>
      </div>
      <div class="col-half">
          <label for="phone_number">Phone Number:</label>
          <input type="text" id="phone_number" name="phone_number" maxlength="20" value="<?php echo $phone_number_prefill; ?>">
      </div>
  </div>

  <!-- SPN & Date of Birth -->
  <div class="row">
      <div class="col-half">
          <label for="spn">Social Protection Number (SPN):</label>
          <input type="text" id="spn" name="spn">
      </div>
      <div class="col-half">
          <label for="age">Date of Birth:</label>
          <input type="date" id="age" name="age">
      </div>
  </div>

  <!-- First & Middle Name -->
  <div class="row">
      <div class="col-half">
          <label for="first_name">First Name:</label>
          <input type="text" id="first_name" name="first_name">
      </div>
      <div class="col-half">
          <label for="middle_name">Middle Name:</label>
          <input type="text" id="middle_name" name="middle_name">
      </div>
  </div>

  <!-- Family Name & Status -->
  <div class="row">
      <div class="col-half">
          <label for="family_name">Family Name:</label>
          <input type="text" id="family_name" name="family_name">
      </div>
      <div class="col-half">
          <label for="status">Status:</label>
          <select id="status" name="status">
              <option value="Open" selected>Open</option>
              <option value="Closed">Closed</option>
          </select>
      </div>
  </div>

  <!-- Gender & Disability -->
  <div class="row">
      <div class="col-half">
          <label for="gender">Gender:</label>
          <select id="gender" name="gender">
              <option value="" selected>-- Select Gender --</option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
              <option value="Other">Other</option>
              <option value="Prefer not to say">Prefer not to say</option>
          </select>
      </div>
      <div class="col-half">
          <label for="disability">Disability:</label>
          <select id="disability" name="disability">
              <option value="" selected>-- Select Disability --</option>
              <option value="None">None</option>
              <option value="Physical">Physical</option>
              <option value="Sensory">Sensory</option>
              <option value="Intellectual">Intellectual</option>
              <option value="Mental">Mental</option>
              <option value="Other">Other (specify)</option>
              <option value="Language">Language</option>
          </select>
      </div>
  </div>

  <!-- Language & User Type -->
  <div class="row">
      <div class="col-half">
          <label for="language">Language:</label>
          <select id="language" name="language">
              <option value="" selected>-- Select Language --</option>
              <option value="English">English</option>
              <option value="Fijian">Fijian</option>
              <option value="Hindi">Hindi</option>
          </select>
      </div>
      <div class="col-half">
          <label for="user_type">User Type:</label>
          <select id="user_type" name="user_type">
              <option value="" selected>-- Select User Type --</option>
              <option value="SP recipient">SP recipient</option>
              <option value="Community member">Community member</option>
              <option value="Non-government">Non-government</option>
              <option value="Government">Government</option>
              <option value="Other">Other (please specify)</option>
          </select>
      </div>
  </div>

  <!-- Address -->
  <label for="address">Address:</label>
  <input type="text" id="address" name="address" placeholder="Optional - used for map link in details">

  <!-- Notes -->
  <label for="notes">Notes:</label>
  <textarea id="notes" name="notes"></textarea>

  <button type="submit">Submit Case</button>
</form>
</div>

<?php if ($conn && $phone_number_prefill !== ''): ?>
  <div class="related-card">
    <h2 class="related-title">Related Cases for Phone: <?php echo htmlspecialchars($phone_number_prefill); ?></h2>
    <table>
      <thead>
        <tr>
          <th>Date/Time</th>
          <th>Case #</th>
          <th>Full Name</th>
          <th>Status</th>
          <th>Phone</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach($related_rows as $row) {
          $rowDate = !empty($row['date_time_str']) ? new DateTime($row['date_time_str'], $serverTimezone) : null;
          $ageHours = 0;
          if ($rowDate) {
              $diff = $nowDT->diff($rowDate);
              $ageHours = $diff->days*24 + $diff->h;
          }

          $statusLower = strtolower((string)$row['status']);
          $highlight = '';
          if ($statusLower == 'closed') {
              $highlight = 'highlight-green';
          } elseif ($statusLower == 'escalated') {
              $highlight = 'highlight-blue';
          } elseif ($ageHours >= 24) {
              $highlight = 'highlight-red';
          } elseif ($ageHours > 2) {
              $highlight = 'highlight-orange';
          }

          // audio link by case number match
          $case_number = $row['case_number'];
          $audioLink = '';
          foreach ($mp3_files as $file) {
              if (stripos(basename($file), (string)$case_number) !== false) {
                  $audioLink = $file;
                  break;
              }
          }

          $fullName = trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['family_name'] ?? ''));

          echo "<tr class='$highlight'>";
          echo "<td>". ($rowDate ? $rowDate->format('Y-m-d H:i') : '') ."</td>";
          echo "<td>". htmlspecialchars((string)$case_number) ."</td>";
          echo "<td>". htmlspecialchars($fullName) ."</td>";
          echo "<td>". htmlspecialchars((string)$row['status']) ."</td>";

          echo "<td>";
          if (!empty($row['phone_number'])) {
              $safePhone = htmlspecialchars((string)$row['phone_number']);
              echo "<a href='tel:$safePhone'>$safePhone</a>";
          } else { echo "—"; }
          echo "</td>";

          // Actions with View Details/Notes, Edit, Close/Escalate logic, Address + Escalation ID in modal
          echo "<td>
                  <a href='javascript:void(0);' class='view-details-btn' 
                     data-case='".htmlspecialchars(json_encode($row), ENT_QUOTES)."' 
                     data-audio='".htmlspecialchars($audioLink, ENT_QUOTES)."'>View Details</a> | 
                  <a class='edit-link' href='edit_case.php?id=".urlencode((string)$case_number)."'>Edit</a>";
          if ($statusLower == 'open') {
              echo " | <a class='edit-link' href='cases.php?close_case=".urlencode((string)$case_number)."' onclick=\"return confirm('Close this case?');\">Close</a> | 
                     <a class='edit-link' href='escalate.php?id=".urlencode((string)$case_number)."'>Escalate</a>";
          } elseif ($statusLower == 'escalated') {
              echo " | <a class='edit-link' href='cases.php?close_case=".urlencode((string)$case_number)."' onclick=\"return confirm('Close this escalated case?');\">Close</a>";
          }
          echo "</td>";

          echo "</tr>";
      }
      ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Notes Modal -->
<div id="notesModal" class="modal">
  <div class="modal-content">
    <span class="close" aria-label="Close notes">&times;</span>
    <h3>Case Notes</h3>
    <div id="modalNotes" class="notes-box"></div>
    <div class="notes-actions">
      <a href="javascript:void(0);" class="btn" id="closeNotesBtn">Close</a>
    </div>
  </div>
</div>

<!-- Case Details Modal -->
<div id="detailsModal" class="modal">
  <div class="modal-content">
    <span class="close" aria-label="Close details">&times;</span>
    <h3>Case Details</h3>
    <div class="details-wrap">
      <div class="details-header">Overview</div>
      <table class="details-table" id="detailsTable">
        <tbody></tbody>
      </table>
      <div class="details-actions">
        <a href="javascript:void(0);" class="btn" id="closeDetailsBtn">Close</a>
      </div>
    </div>
  </div>
</div>

<!-- Attachment Modal -->
<div id="attachmentModal" class="modal attachment-modal">
  <div class="modal-content">
    <span class="close" aria-label="Close attachment">&times;</span>
    <h3 id="attachmentTitle">Attachment Preview</h3>
    <div class="attachment-body">
      <iframe id="attachmentFrame" title="Attachment preview"></iframe>
    </div>
    <div class="attachment-actions">
      <a href="javascript:void(0);" class="btn" id="downloadAttachmentLink" target="_blank" rel="noopener">Open in New Tab</a>
      <a href="javascript:void(0);" class="btn" id="closeAttachmentBtn">Close</a>
    </div>
  </div>
</div>

<script>
// Notes Modal
const notesModal = document.getElementById("notesModal");
const modalNotes = document.getElementById("modalNotes");
const closeNotesIcon = notesModal.querySelector(".close");
const closeNotesBtn  = document.getElementById("closeNotesBtn");
const attachmentModal = document.getElementById("attachmentModal");
const attachmentFrame = document.getElementById("attachmentFrame");
const attachmentTitle = document.getElementById("attachmentTitle");
const downloadAttachmentLink = document.getElementById("downloadAttachmentLink");
const closeAttachmentBtn = document.getElementById("closeAttachmentBtn");
const closeAttachmentIcon = attachmentModal.querySelector(".close");

// Close handlers
closeNotesIcon.onclick = () => { notesModal.style.display = "none"; };
closeNotesBtn.onclick  = () => { notesModal.style.display = "none"; };
closeAttachmentIcon.onclick = () => { closeAttachmentModal(); };
closeAttachmentBtn.onclick  = () => { closeAttachmentModal(); };

function openAttachmentModal(url, filename) {
  if (!url) return;
  const safeName = filename && filename.trim() ? filename.trim() : 'Attachment';
  attachmentTitle.textContent = safeName;
  attachmentFrame.src = url;
  downloadAttachmentLink.href = url;
  attachmentModal.style.display = "block";
}

function closeAttachmentModal() {
  attachmentModal.style.display = "none";
  attachmentFrame.src = "";
}

if (modalNotes) {
  modalNotes.addEventListener('click', (event) => {
    const link = event.target.closest('a');
    if (!link) return;
    const hrefAttr = link.getAttribute('href') || '';
    const absoluteHref = link.href || hrefAttr;
    if (!hrefAttr && !absoluteHref) return;
    const isAttachmentLink = link.classList.contains('attachment-link') || (absoluteHref && absoluteHref.includes('/uploads/'));
    if (!isAttachmentLink) return;
    event.preventDefault();
    const filename = link.getAttribute('data-filename') || link.textContent || '';
    const targetUrl = hrefAttr || absoluteHref;
    openAttachmentModal(targetUrl, filename);
  });
}

// Details Modal
const detailsModal = document.getElementById("detailsModal");
const detailsCloseIcon = detailsModal.querySelector(".close");
const closeDetailsBtn  = document.getElementById("closeDetailsBtn");
const detailsTableBody = document.querySelector("#detailsTable tbody");

function addRow(label, valueHtml) {
  const tr = document.createElement('tr');
  const th = document.createElement('th');
  const td = document.createElement('td');
  th.textContent = label;
  td.innerHTML   = valueHtml || '—';
  tr.appendChild(th);
  tr.appendChild(td);
  detailsTableBody.appendChild(tr);
}

document.querySelectorAll(".view-details-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    const data  = JSON.parse(btn.getAttribute("data-case"));
    const audio = btn.getAttribute("data-audio") || '';
    detailsTableBody.innerHTML = "";

    addRow("Date/Time", data.date_time_str || '');
    addRow("Case #", data.case_number || '');
    addRow("Status", data.status || '');
    addRow("SPN", data.spn || '');
    const fullName = `${data.first_name||''} ${data.middle_name||''} ${data.family_name||''}`.trim();
    addRow("Name", fullName || '');
    addRow("Phone", data.phone_number ? `<a href="tel:${data.phone_number}">${data.phone_number}</a>` : '—');
    addRow("Gender", data.gender || '');
    addRow("Disability", data.disability || '');
    addRow("Language", data.language || '');
    addRow("User Type", data.user_type || '');

    // Address (clickable map) + Escalation Session ID (NEW)
    addRow("Address", data.address 
      ? `<a href="javascript:void(0)" onclick="openMapPopup('${(String(data.address)).replace(/'/g,"\\'")}')">${String(data.address)}</a>`
      : '—'
    );
    addRow("Escalation Session ID", data.escalation_session_id || '—');

    // Notes link
    addRow("Notes", data.notes ? `<a href="javascript:void(0);" class="view-notes-btn" data-notes="${String(data.notes).replace(/"/g,'&quot;')}">View Notes</a>` : 'No Notes');

    // Audio
    if (audio) addRow("Audio", `<a href="${audio}" target="_blank">Play Audio</a>`);

    // Informed Consent
    addRow("Informed Consent", data.informed_consent ? 'Yes' : 'No');

    // re-bind nested View Notes inside details
    detailsTableBody.querySelectorAll(".view-notes-btn").forEach(nbtn => {
      nbtn.addEventListener("click", () => {
        modalNotes.innerHTML = nbtn.getAttribute("data-notes") || '';
        notesModal.style.display = "block";
      });
    });

    detailsModal.style.display = "block";
  });
});

detailsCloseIcon.onclick = () => { detailsModal.style.display = "none"; };
closeDetailsBtn.onclick  = () => { detailsModal.style.display = "none"; };

// Close modals when clicking outside
window.onclick = e => {
  if(e.target == notesModal) notesModal.style.display = "none";
  if(e.target == detailsModal) detailsModal.style.display = "none";
  if(e.target == attachmentModal) closeAttachmentModal();
};

// ESC to close
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    notesModal.style.display = "none";
    detailsModal.style.display = "none";
    closeAttachmentModal();
  }
});
</script>

</body>
</html>
