<?php
// ---- Prefills from query string ----
$case_number_prefill = isset($_GET['case_number']) ? htmlspecialchars($_GET['case_number']) : '';
$phone_number_prefill = isset($_GET['phone_number']) ? htmlspecialchars($_GET['phone_number']) : '';
$currentPage = basename($_SERVER['PHP_SELF']);

// DB connection + server time context
$related_rows = [];
$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid" => "sa",
  "PWD" => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

function fetchServerDateContext() {
    $tzName = @date_default_timezone_get();
    try {
        $tz = new DateTimeZone($tzName ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $now = new DateTimeImmutable('now', $tz);
    return [$now, $tz];
}

list($serverNowObj, $serverTimezone) = fetchServerDateContext();
$now = $serverNowObj->format('Y-m-d\TH:i');

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

// Case metadata cache for details + previous cases modal
$casesByNumber = [];
$casesByPhone  = [];
$audioByCase   = [];

if ($conn) {
    $allStmt = sqlsrv_query($conn, "SELECT CONVERT(VARCHAR(19), date_time, 120) AS date_time_str, * FROM mwcsp_caser");
    if ($allStmt) {
        while ($row = sqlsrv_fetch_array($allStmt, SQLSRV_FETCH_ASSOC)) {
            $caseNumRaw = isset($row['case_number']) ? (string)$row['case_number'] : '';
            $caseNum = trim($caseNumRaw);
            if ($caseNum === '') {
                continue;
            }

            if (!isset($audioByCase[$caseNum])) {
                foreach ($mp3_files as $file) {
                    if (stripos(basename($file), $caseNum) !== false) {
                        $audioByCase[$caseNum] = $file;
                        break;
                    }
                }
            }

            $caseData = [
                'case_number' => $caseNum,
                'date_time_str' => isset($row['date_time_str']) ? (string)$row['date_time_str'] : '',
                'status' => isset($row['status']) ? (string)$row['status'] : '',
                'spn' => isset($row['spn']) ? (string)$row['spn'] : '',
                'first_name' => isset($row['first_name']) ? (string)$row['first_name'] : '',
                'middle_name' => isset($row['middle_name']) ? (string)$row['middle_name'] : '',
                'family_name' => isset($row['family_name']) ? (string)$row['family_name'] : '',
                'phone_number' => isset($row['phone_number']) ? trim((string)$row['phone_number']) : '',
                'address' => isset($row['address']) ? (string)$row['address'] : '',
                'escalation_session_id' => isset($row['escalation_session_id']) ? (string)$row['escalation_session_id'] : '',
                'gender' => isset($row['gender']) ? (string)$row['gender'] : '',
                'disability' => isset($row['disability']) ? (string)$row['disability'] : '',
                'language' => isset($row['language']) ? (string)$row['language'] : '',
                'user_type' => isset($row['user_type']) ? (string)$row['user_type'] : '',
                'notes' => isset($row['notes']) ? (string)$row['notes'] : '',
                'informed_consent' => !empty($row['informed_consent']) ? 1 : 0,
            ];

            $casesByNumber[$caseNum] = $caseData;

            $phoneKey = $caseData['phone_number'];
            if ($phoneKey !== '') {
                if (!isset($casesByPhone[$phoneKey])) {
                    $casesByPhone[$phoneKey] = [];
                }
                $casesByPhone[$phoneKey][] = $caseNum;
            }
        }
        sqlsrv_free_stmt($allStmt);
    }
}

$jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
$casesByNumberJson = json_encode($casesByNumber, $jsonOptions);
if ($casesByNumberJson === false) { $casesByNumberJson = '{}'; }
$casesByPhoneJson = json_encode($casesByPhone, $jsonOptions);
if ($casesByPhoneJson === false) { $casesByPhoneJson = '{}'; }
$audioByCaseJson = json_encode($audioByCase, $jsonOptions);
if ($audioByCaseJson === false) { $audioByCaseJson = '{}'; }

// For colouring rows / age calc in related list
$nowDT = clone $serverNowObj;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MWCSP Case Form</title>
<link rel="stylesheet" href="css/style.css">
<script>
const CASES_BY_NUMBER = <?php echo $casesByNumberJson; ?> || {};
const CASES_BY_PHONE  = <?php echo $casesByPhoneJson; ?> || {};
const AUDIO_BY_CASE   = <?php echo $audioByCaseJson; ?> || {};
window.CASES_BY_NUMBER = CASES_BY_NUMBER;
window.CASES_BY_PHONE = CASES_BY_PHONE;
window.AUDIO_BY_CASE = AUDIO_BY_CASE;
</script>
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
.modal { display: none; position: fixed; padding-top: 100px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); z-index: 3000;}
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
#previewModal   { z-index: 3050; }
#previousCasesModal { z-index: 3100; }
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
  background: linear-gradient(135deg, #f7f9ff, #eef2ff);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition: background 0.3s ease;
}
.attachment-body.audio-active {
  background: #eef4ff;
  padding: 24px;
}
.attachment-body iframe {
  width: 100%;
  height: 100%;
  border: none;
  background: #fff;
}
.audio-player {
  width: 100%;
  max-width: 520px;
  background: #ffffff;
  border-radius: 12px;
  box-shadow: 0 18px 36px rgba(15, 23, 42, 0.18);
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.audio-player audio {
  width: 100%;
  display: block;
  background: #f2f6ff;
  border-radius: 8px;
  padding: 6px 0;
}
.audio-status {
  font-size: 0.95rem;
  color: #3b4a6b;
}
.audio-status.hint {
  color: #5a6d92;
  font-style: italic;
}
.audio-status.error {
  color: #c0392b;
  font-weight: 600;
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

/* Previous cases modal */
.previous-cases-content {
  max-width: 680px;
  width: 95%;
}
.previous-cases-body {
  max-height: 420px;
  overflow-y: auto;
  margin-top: 6px;
}
.previous-cases-table {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
}
.previous-cases-table th,
.previous-cases-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #e3eaf9;
  text-align: left;
  font-size: 14px;
}
.previous-cases-table tbody tr:nth-child(even) {
  background: #f7f9ff;
}
.previous-cases-table .history-view-btn {
  padding: 6px 12px;
  border-radius: 6px;
  background: #0073e6;
  color: #fff;
  text-decoration: none;
  border: 1px solid #005bb5;
  display: inline-block;
}
.previous-cases-table .history-view-btn:hover {
  background: #005bb5;
}
</style>

<script>
// --- Google Maps preview helper ---
function openMapPopup(addr){
  if(!addr) return;
  const baseUrl = "https://www.google.com/maps?q=" + encodeURIComponent(addr);
  const embedUrl = baseUrl + "&output=embed";
  openPreviewModal({
    url: embedUrl,
    externalUrl: baseUrl,
    title: addr,
    type: 'map'
  });
}
window.openMapPopup = openMapPopup;
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

<!-- Previous Cases Modal -->
<div id="previousCasesModal" class="modal">
  <div class="modal-content previous-cases-content">
    <span class="close" aria-label="Close previous cases">&times;</span>
    <h3>Previous Cases</h3>
    <div id="previousCasesBody" class="previous-cases-body"></div>
    <div class="details-actions">
      <a href="javascript:void(0);" class="btn" id="closePreviousCasesBtn">Close</a>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="modal attachment-modal">
  <div class="modal-content">
    <span class="close" aria-label="Close preview">&times;</span>
    <h3 id="previewTitle">Preview</h3>
    <div class="attachment-body" id="previewBody"></div>
    <div class="attachment-actions">
      <a href="javascript:void(0);" class="btn" id="openPreviewExternal" target="_blank" rel="noopener">Open in New Tab</a>
      <a href="javascript:void(0);" class="btn" id="closePreviewBtn">Close</a>
    </div>
  </div>
</div>

<script>
// Notes Modal
const notesModal = document.getElementById("notesModal");
const modalNotes = document.getElementById("modalNotes");
const closeNotesIcon = notesModal.querySelector(".close");
const closeNotesBtn  = document.getElementById("closeNotesBtn");
const previewModal = document.getElementById("previewModal");
const previewBody = document.getElementById("previewBody");
const previewTitle = document.getElementById("previewTitle");
const openPreviewExternal = document.getElementById("openPreviewExternal");
const closePreviewBtn = document.getElementById("closePreviewBtn");
const closePreviewIcon = previewModal.querySelector(".close");

// Close handlers
closeNotesIcon.onclick = () => { notesModal.style.display = "none"; };
closeNotesBtn.onclick  = () => { notesModal.style.display = "none"; };
closePreviewIcon.onclick = () => { closePreviewModal(); };
closePreviewBtn.onclick  = () => { closePreviewModal(); };

function buildIframe(url, title) {
  const iframe = document.createElement('iframe');
  iframe.src = url;
  iframe.title = title;
  iframe.loading = 'lazy';
  iframe.allow = 'autoplay';
  return iframe;
}

function openPreviewModal({ url, title, type = 'attachment', externalUrl = '' }) {
  if (!url) return;
  const safeTitle = title && title.trim()
    ? title.trim()
    : (type === 'audio'
        ? 'Audio Preview'
        : type === 'map'
          ? 'Map Preview'
          : 'Attachment Preview');
  previewTitle.textContent = safeTitle;
  previewBody.innerHTML = '';
  previewBody.classList.remove('audio-active');

  const external = externalUrl && externalUrl.trim() ? externalUrl : url;
  if (external) {
    openPreviewExternal.href = external;
    openPreviewExternal.style.display = 'inline-block';
  } else {
    openPreviewExternal.removeAttribute('href');
    openPreviewExternal.style.display = 'none';
  }

  if (type === 'audio') {
    previewBody.classList.add('audio-active');

    const player = document.createElement('div');
    player.className = 'audio-player';

    const status = document.createElement('div');
    status.className = 'audio-status';
    status.textContent = 'Loading audio preview…';
    player.appendChild(status);

    const audio = document.createElement('audio');
    audio.controls = true;
    audio.preload = 'auto';
    audio.src = url;
    audio.setAttribute('aria-label', safeTitle);
    player.appendChild(audio);

    audio.addEventListener('loadeddata', () => {
      status.textContent = 'Press play to listen.';
      status.classList.add('hint');
    });

    audio.addEventListener('play', () => {
      status.remove();
    }, { once: true });

    audio.addEventListener('error', () => {
      status.textContent = 'We could not load the audio preview. Use "Open in New Tab".';
      status.classList.remove('hint');
      status.classList.add('error');
      if (audio.parentNode === player) {
        player.removeChild(audio);
      }
    });

    previewBody.appendChild(player);

    requestAnimationFrame(() => {
      try { audio.load(); } catch (err) { console.warn('Audio preview load failed', err); }
    });
  } else {
    const iframe = buildIframe(url, safeTitle);
    previewBody.appendChild(iframe);
  }

  previewModal.style.display = "block";
}

window.openPreviewModal = openPreviewModal;

function closePreviewModal() {
  const activeAudio = previewBody.querySelector('audio');
  if (activeAudio && typeof activeAudio.pause === 'function') {
    try { activeAudio.pause(); } catch (_) {}
  }
  previewModal.style.display = "none";
  previewBody.innerHTML = '';
  previewBody.classList.remove('audio-active');
}

window.closePreviewModal = closePreviewModal;

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
    openPreviewModal({ url: targetUrl, title: filename, type: 'attachment' });
  });
}

// Details Modal + Previous Cases
const detailsModal = document.getElementById("detailsModal");
const detailsCloseIcon = detailsModal.querySelector(".close");
const closeDetailsBtn  = document.getElementById("closeDetailsBtn");
const detailsTableBody = document.querySelector("#detailsTable tbody");
const previousCasesModal = document.getElementById("previousCasesModal");
const previousCasesBody  = document.getElementById("previousCasesBody");
const previousCasesCloseIcon = previousCasesModal.querySelector(".close");
const closePreviousCasesBtn = document.getElementById("closePreviousCasesBtn");

let detailsHistory = [];
let pendingDetailsFromLists = [];
let activeDetailsCase = null;

const htmlEscape = (value) => {
  return value === null || value === undefined
    ? ''
    : String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

const attrEscape = (value) => {
  return value === null || value === undefined
    ? ''
    : String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;');
};

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

function closePreviousCasesModal() {
  previousCasesModal.style.display = "none";
  detailsModal.style.zIndex = '';

  if (pendingDetailsFromLists.length > 0) {
    const caseToRestore = pendingDetailsFromLists.pop();
    if (caseToRestore) {
      openCaseDetails(caseToRestore, { pushHistory: false });
    }
  }
}

function openPreviousCasesList(phone, currentCaseNumber) {
  if (!phone || !CASES_BY_PHONE || !Array.isArray(CASES_BY_PHONE[phone])) {
    return;
  }

  const relatedNumbers = CASES_BY_PHONE[phone].filter(num => num !== currentCaseNumber);
  if (!relatedNumbers.length) {
    return;
  }

  const records = relatedNumbers
    .map(num => CASES_BY_NUMBER[num])
    .filter(Boolean)
    .sort((a, b) => {
      const aDate = a.date_time_str || '';
      const bDate = b.date_time_str || '';
      return bDate.localeCompare(aDate);
    });

  if (!records.length) {
    return;
  }

  let tableHtml = '<table class="previous-cases-table">';
  tableHtml += '<thead><tr><th>Date/Time</th><th>Case #</th><th>Status</th><th></th></tr></thead><tbody>';
  records.forEach(record => {
    const caseNum = record.case_number || '';
    tableHtml += '<tr>' +
      '<td>' + htmlEscape(record.date_time_str || '') + '</td>' +
      '<td>' + htmlEscape(caseNum) + '</td>' +
      '<td>' + htmlEscape(record.status || '') + '</td>' +
      '<td><a href="javascript:void(0);" class="history-view-btn" data-case="' + attrEscape(caseNum) + '">View Case</a></td>' +
      '</tr>';
  });
  tableHtml += '</tbody></table>';

  previousCasesBody.innerHTML = tableHtml;
  previousCasesModal.style.display = "block";

  previousCasesBody.querySelectorAll('.history-view-btn').forEach(link => {
    link.addEventListener('click', () => {
      const caseNum = link.getAttribute('data-case');
      openCaseDetails(caseNum, { fromPreviousList: true });
    });
  });
}

function openCaseDetails(caseNumber, options = {}) {
  if (!caseNumber || !CASES_BY_NUMBER || !CASES_BY_NUMBER[caseNumber]) {
    return;
  }

  const pushHistory = options.pushHistory !== false;
  const fromPreviousList = !!options.fromPreviousList;

  if (pushHistory && detailsModal.style.display === 'block' && activeDetailsCase && activeDetailsCase !== caseNumber) {
    detailsHistory.push({
      caseNumber: activeDetailsCase,
      reopenPreviousList: fromPreviousList
    });
  } else if (pushHistory && detailsModal.style.display !== 'block') {
    detailsHistory = [];
  }

  activeDetailsCase = caseNumber;
  const data = CASES_BY_NUMBER[caseNumber];

  detailsTableBody.innerHTML = '';

  addRow('Date/Time', htmlEscape(data.date_time_str || ''));
  addRow('Case #', htmlEscape(data.case_number || ''));
  addRow('Status', htmlEscape(data.status || ''));
  addRow('SPN', htmlEscape(data.spn || ''));

  const fullName = `${data.first_name || ''} ${data.middle_name || ''} ${data.family_name || ''}`.trim();
  addRow('Name', htmlEscape(fullName));

  const phone = data.phone_number || '';
  addRow('Phone', phone ? `<a href="tel:${attrEscape(phone)}">${htmlEscape(phone)}</a>` : '—');

  const addressText = data.address || '';
  addRow('Address', addressText && addressText.trim() !== ''
    ? `<a href="javascript:void(0);" class="map-preview-link" data-address="${attrEscape(addressText)}">${htmlEscape(addressText)}</a>`
    : '—'
  );

  addRow('Escalation Session ID', htmlEscape(data.escalation_session_id || '—'));
  addRow('Gender', htmlEscape(data.gender || ''));
  addRow('Disability', htmlEscape(data.disability || ''));
  addRow('Language', htmlEscape(data.language || ''));
  addRow('User Type', htmlEscape(data.user_type || ''));

  const notesText = data.notes || '';
  addRow('Notes', notesText
    ? `<a href="javascript:void(0);" class="view-notes-btn" data-notes="${attrEscape(notesText)}">View Notes</a>`
    : 'No Notes');

  const audioUrl = AUDIO_BY_CASE[caseNumber] || '';
  if (audioUrl) {
    addRow('Audio', `<a href="javascript:void(0);" class="audio-preview-link" data-audio="${attrEscape(audioUrl)}">Play Audio</a>`);
  }

  const phoneGroup = (phone && CASES_BY_PHONE && Array.isArray(CASES_BY_PHONE[phone])) ? CASES_BY_PHONE[phone] : [];
  const related = phoneGroup.length
    ? phoneGroup.filter(num => num !== caseNumber)
    : [];
  const previousHtml = related.length
    ? `<a href="javascript:void(0);" class="previous-cases-link" data-phone="${attrEscape(phone)}" data-current="${attrEscape(caseNumber)}">Previous Cases (${related.length})</a>`
    : 'No previous cases';
  addRow('Previous Cases', previousHtml);

  addRow('Informed Consent', data.informed_consent ? 'Yes' : 'No');

  detailsTableBody.querySelectorAll('.view-notes-btn').forEach(nbtn => {
    nbtn.addEventListener('click', () => {
      modalNotes.innerHTML = nbtn.getAttribute('data-notes') || '';
      notesModal.style.display = 'block';
    });
  });

  detailsTableBody.querySelectorAll('.audio-preview-link').forEach(link => {
    link.addEventListener('click', () => {
      const audioLink = link.getAttribute('data-audio') || '';
      openPreviewModal({ url: audioLink, type: 'audio', title: 'Audio Preview', externalUrl: audioLink });
    });
  });

  detailsTableBody.querySelectorAll('.map-preview-link').forEach(link => {
    link.addEventListener('click', () => {
      const addr = link.getAttribute('data-address') || '';
      openMapPopup(addr);
    });
  });

  detailsTableBody.querySelectorAll('.previous-cases-link').forEach(link => {
    link.addEventListener('click', () => {
      const phoneValue = link.getAttribute('data-phone');
      const current = link.getAttribute('data-current');
      openPreviousCasesList(phoneValue, current);
    });
  });

  if (previousCasesModal.style.display === 'block') {
    const prevZ = parseInt(window.getComputedStyle(previousCasesModal).zIndex || '3100', 10);
    detailsModal.style.zIndex = prevZ + 10;
  } else {
    detailsModal.style.zIndex = '';
  }

  detailsModal.style.display = 'block';
}

document.querySelectorAll('.view-details-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    let data = {};
    try {
      data = JSON.parse(btn.getAttribute('data-case') || '{}');
    } catch (err) {
      data = {};
    }

    const caseNumber = (data.case_number || '').trim();
    const audioOverride = btn.getAttribute('data-audio') || '';

    if (caseNumber) {
      const existing = CASES_BY_NUMBER[caseNumber] || {};
      CASES_BY_NUMBER[caseNumber] = Object.assign({}, existing, data, { case_number: caseNumber });

      const phoneVal = (data.phone_number || '').trim();
      if (phoneVal) {
        if (!Array.isArray(CASES_BY_PHONE[phoneVal])) {
          CASES_BY_PHONE[phoneVal] = [];
        }
        if (!CASES_BY_PHONE[phoneVal].includes(caseNumber)) {
          CASES_BY_PHONE[phoneVal].push(caseNumber);
        }
      }

      if (audioOverride) {
        AUDIO_BY_CASE[caseNumber] = audioOverride;
      }

      detailsHistory = [];
      pendingDetailsFromLists = [];
      activeDetailsCase = null;
      openCaseDetails(caseNumber);
    }
  });
});

function hideDetailsModal() {
  if (detailsHistory.length > 0) {
    const previousContext = detailsHistory.pop();
    if (previousContext && previousContext.reopenPreviousList) {
      pendingDetailsFromLists.push(previousContext.caseNumber);
      detailsModal.style.display = 'none';
      detailsModal.style.zIndex = '';
      activeDetailsCase = null;
      if (previousCasesModal.style.display !== 'block') {
        previousCasesModal.style.display = 'block';
      }
    } else if (previousContext) {
      openCaseDetails(previousContext.caseNumber, { pushHistory: false });
    }
  } else {
    detailsModal.style.display = 'none';
    detailsModal.style.zIndex = '';
    activeDetailsCase = null;
  }
}

detailsCloseIcon.onclick = hideDetailsModal;
closeDetailsBtn.onclick  = hideDetailsModal;
previousCasesCloseIcon.onclick = closePreviousCasesModal;
closePreviousCasesBtn.onclick  = closePreviousCasesModal;

// Close modals when clicking outside
window.onclick = e => {
  if(e.target == notesModal) notesModal.style.display = "none";
  if(e.target == detailsModal) hideDetailsModal();
  if(e.target == previousCasesModal) closePreviousCasesModal();
  if(e.target == previewModal) closePreviewModal();
};

// ESC to close
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    notesModal.style.display = "none";
    hideDetailsModal();
    closePreviousCasesModal();
    closePreviewModal();
  }
});
</script>

</body>
</html>
