<?php
set_time_limit(120);

$serverName = "localhost";
$connectionOptions = array(
    "Database" => "nextccdb",
    "Uid" => "sa",
    "PWD" => '$olidus'
);
$conn = sqlsrv_connect($serverName, $connectionOptions);
if(!$conn) { die(print_r(sqlsrv_errors(), true)); }

// Close case (Open or Escalated)
if (isset($_GET['close_case'])) {
    $close_case = $_GET['close_case'];
    $update = "UPDATE mwcsp_caser SET status='Closed' WHERE case_number=?";
    sqlsrv_query($conn, $update, [$close_case]);
    header("Location: cases.php");
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);

$serverTimezone = new DateTimeZone(date_default_timezone_get());
$now = new DateTime("now", $serverTimezone);

// Fetch all rows for pie + pagination
$sqlAll = "SELECT CONVERT(VARCHAR(19), date_time, 120) AS date_time_str, * 
           FROM mwcsp_caser 
           ORDER BY 
             CASE 
               WHEN LOWER(status)='open' THEN 1
               WHEN LOWER(status)='escalated' THEN 2
               ELSE 3
             END,
             date_time ASC";
$stmtAll = sqlsrv_query($conn, $sqlAll);
$rows = [];
while($r = sqlsrv_fetch_array($stmtAll, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }

// ---------- 5-slice pie time range ----------
$pie_range = isset($_GET['pie_range']) ? $_GET['pie_range'] : '7d';
$valid_ranges = ['today','7d','1m','3m','6m','12m','all'];
if (!in_array($pie_range, $valid_ranges)) $pie_range = '7d';

$pieStart = null;
switch ($pie_range) {
  case 'today': $pieStart = (clone $now)->setTime(0,0,0); break;
  case '7d':    $pieStart = (clone $now)->modify('-7 days'); break;
  case '1m':    $pieStart = (clone $now)->modify('-1 month'); break;
  case '3m':    $pieStart = (clone $now)->modify('-3 months'); break;
  case '6m':    $pieStart = (clone $now)->modify('-6 months'); break;
  case '12m':   $pieStart = (clone $now)->modify('-12 months'); break;
  case 'all':   $pieStart = null; break;
}

// Compute 5-slice status counts
$count_open_fresh = 0;   // <2h
$count_open_2h    = 0;   // >2h & <24h
$count_open_24h   = 0;   // ≥24h
$count_escalated  = 0;
$count_closed     = 0;

foreach ($rows as $row) {
    if (empty($row['date_time_str'])) continue;
    $rowDate = new DateTime($row['date_time_str'], $serverTimezone);
    if ($pieStart !== null && $rowDate < $pieStart) continue;
    if ($rowDate > $now) continue;

    $diff = $now->diff($rowDate);
    $ageHours = $diff->days * 24 + $diff->h;

    $status = strtolower((string)$row['status']);
    if ($status === 'closed') {
        $count_closed++;
    } elseif ($status === 'escalated') {
        $count_escalated++;
    } else {
        if ($ageHours >= 24)       $count_open_24h++;
        elseif ($ageHours > 2)     $count_open_2h++;
        else                       $count_open_fresh++;
    }
}

// ---------- Audio discovery ----------
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

// ------------- Pagination: 10 per page -------------
$per_page = 10;
$total = count($rows);
$total_pages = max(1, (int)ceil($total / $per_page));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$rows_page = array_slice($rows, $offset, $per_page);

function buildPageLink($p, $pie_range) {
    return 'cases.php?page='.$p.'&pie_range='.urlencode($pie_range);
}

sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Case List</title>
<link rel="stylesheet" href="css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- A) Google Maps preview helper -->
<script>
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
.container { max-width: 1100px; margin: 0 auto; padding: 14px 16px 30px; }

/* Top header block: pie LEFT, legend RIGHT */
.header-block {
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  margin-top:10px;
  width:100%;
}

/* Pie LEFT */
.pie-side {
  flex: 0 0 220px;
  margin-right: 12px;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
}
.pie-wrap { width: 180px; max-width: 180px; }
#statusPie { width: 180px !important; height: 180px !important; }

/* Period buttons above pie */
.range-bar { display:flex; justify-content:flex-start; gap:6px; flex-wrap:wrap; margin-bottom:6px; }
.range-bar a {
  background:#e6f0ff; color:#005bb5; text-decoration:none; padding:4px 8px; border-radius:6px; border:1px solid #b3d1ff; font-size:12px;
}
.range-bar a.active { background:#0073e6; color:#fff; border-color:#005bb5; }
.range-bar a:hover  { background:#005bb5; color:#fff; }

/* Legend (right) */
.legend-wrap {
  flex: 1 1 auto;
  min-width: 0;
}
.legend-wrap span { display:inline-block; margin: 0 6px 6px 0; padding:4px 8px; border:1px solid #ccc; border-radius:4px; }

/* Table + stripes + highlights */
table { width: 100%; border-collapse: collapse; margin-top: 16px; }
th, td { padding: 10px; border: 1px solid #ccc; text-align: left; }
thead tr { background:#0073e6; color:#fff; }
tbody tr:nth-child(even) { background:#f2f6fb; }

/* Ensure highlight colours override stripes */
.highlight-orange { background-color: #fff4df !important; }  /* Open >2h */
.highlight-red    { background-color: #ffd6d6 !important; }  /* Open ≥24h */
.highlight-green  { background-color: #dff5df !important; }  /* Closed */
.highlight-blue   { background-color: #d9ecff !important; }  /* Escalated */

/* Modals */
.modal { display: none; position: fixed; padding-top: 100px; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);}
#detailsModal { z-index: 2000; }
#notesModal   { z-index: 3000; }

/* --- View Notes modal styling --- */
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
.notes-actions {
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:10px;
}
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
  background: #0a0a0a;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.attachment-body iframe,
.attachment-body audio {
  width: 100%;
  height: 100%;
  border: none;
  background: #fff;
}
.attachment-body audio {
  height: auto;
  background: #111;
  padding: 12px;
}
.attachment-actions {
  margin-top: 15px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
}

/* --- View Details modal styling to match Search --- */
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
.details-table {
  width: 100%;
  border-collapse: collapse;
}
.details-table th, .details-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #dbe6ff;
  text-align: left;
  font-size: 14px;
}
.details-table tbody tr:nth-child(even) {
  background: #eef4ff;   /* zebra striping inside modal */
}
.details-actions {
  display:flex;
  justify-content:flex-end;
  gap:10px;
  padding: 10px 12px;
  background:#f3f7ff;
}

/* Pagination */
.pagination {
  display:flex; gap:6px; justify-content:center; align-items:center; margin-top:14px;
}
.pagination a, .pagination span {
  padding:6px 10px; border:1px solid #b3d1ff; border-radius:6px; text-decoration:none;
  background:#e6f0ff; color:#005bb5; font-size:13px;
}
.pagination .active {
  background:#0073e6; color:#fff; border-color:#005bb5;
}
.pagination .disabled {
  opacity:0.5; pointer-events:none;
}
</style>
</head>
<body>

<div class="header">
  <a href="form.php">➕ New Case</a>
  <a href="cases.php" class="active">📋 Case List</a>
  <a href="search.php">🔍 Search Cases</a>
  <a href="dashboard.php">📊 Dashboard</a>
</div>

<div class="container">
  <h2 style="text-align:center; color:#0073e6; margin-top:6px;">Case List</h2>

  <div class="header-block">
    <!-- Pie (LEFT) with period buttons -->
    <div class="pie-side">
      <div class="range-bar">
        <?php
          function pieBtn($label,$key,$current){
            $cls = ($key===$current)?'active':''; 
            $href = 'cases.php?pie_range='.$key;
            echo "<a class='$cls' href='$href'>$label</a>";
          }
          pieBtn('Today','today',$pie_range);
          pieBtn('7d','7d',$pie_range);
          pieBtn('1m','1m',$pie_range);
          pieBtn('3m','3m',$pie_range);
          pieBtn('6m','6m',$pie_range);
          pieBtn('12m','12m',$pie_range);
          pieBtn('All','all',$pie_range);
        ?>
      </div>
      <div class="pie-wrap">
        <canvas id="statusPie"></canvas>
      </div>
    </div>

    <!-- Legend (RIGHT) -->
    <div class="legend-wrap">
      <div>
        <span style="background:#fff; border-color:#ddd;">⚪ Open &lt;= 2 hours</span>
        <span style="background:#fff4df;">🔶 Open &gt; 2 hours</span>
        <span style="background:#ffd6d6;">🔴 Open ≥ 24 hours</span>
        <span style="background:#d9ecff;">🔵 Escalated</span>
        <span style="background:#dff5df;">✅ Closed</span>
      </div>
    </div>
  </div>

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
    foreach($rows_page as $row) {
        $rowDate = !empty($row['date_time_str']) ? new DateTime($row['date_time_str'], $serverTimezone) : null;
        $ageHours = 0;
        if ($rowDate) {
            $diff = $now->diff($rowDate);
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

        echo "<tr class='$highlight'>";
        echo "<td>". ($rowDate ? $rowDate->format('Y-m-d H:i') : '') ."</td>";
        echo "<td>". htmlspecialchars((string)$case_number) ."</td>";
        echo "<td>". htmlspecialchars(trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['family_name'] ?? ''))) ."</td>";
        echo "<td>". htmlspecialchars((string)$row['status']) ."</td>";

        echo "<td>";
        if (!empty($row['phone_number'])) {
            $safePhone = htmlspecialchars((string)$row['phone_number']);
            echo "<a href='tel:$safePhone'>$safePhone</a>";
        } else { echo "—"; }
        echo "</td>";

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

  <!-- Pagination -->
  <div class="pagination">
    <?php
      $prev = $page - 1; $next = $page + 1;
      echo '<a class="'.($page<=1?'disabled':'').'" href="'.buildPageLink(max(1,$prev), $pie_range).'">Prev</a>';
      for ($p=1; $p <= $total_pages; $p++) {
          $cls = ($p==$page)?'active':''; 
          echo '<a class="'.$cls.'" href="'.buildPageLink($p, $pie_range).'">'.$p.'</a>';
      }
      echo '<a class="'.($page>=$total_pages?'disabled':'').'" href="'.buildPageLink(min($total_pages,$next), $pie_range).'">Next</a>';
    ?>
  </div>
</div>

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
        <tbody>
          <!-- injected rows -->
        </tbody>
      </table>
      <div class="details-actions">
        <a href="javascript:void(0);" class="btn" id="closeDetailsBtn">Close</a>
      </div>
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
const pieCtx = document.getElementById('statusPie');
new Chart(pieCtx, {
  type: 'pie',
  data: {
    labels: ['Open <2h','Open >2h','Open ≥24h','Escalated','Closed'],
    datasets: [{
      data: [
        <?= (int)$count_open_fresh ?>,
        <?= (int)$count_open_2h ?>,
        <?= (int)$count_open_24h ?>,
        <?= (int)$count_escalated ?>,
        <?= (int)$count_closed ?>
      ],
      backgroundColor: ['#ffffff','#fff4df','#ffd6d6','#d9ecff','#dff5df'],
      borderColor:     ['#dcdcdc','#e6d0a8','#d99','#99c2ff','#a6e3a6'],
      borderWidth: 1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: true,
    plugins: { legend: { position: 'bottom' }, tooltip: { enabled: true } }
  }
});

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

  const external = externalUrl && externalUrl.trim() ? externalUrl : url;
  if (external) {
    openPreviewExternal.href = external;
    openPreviewExternal.style.display = 'inline-block';
  } else {
    openPreviewExternal.removeAttribute('href');
    openPreviewExternal.style.display = 'none';
  }

  if (type === 'audio') {
    const audio = document.createElement('audio');
    audio.controls = true;
    audio.setAttribute('controls', 'controls');
    audio.preload = 'metadata';
    audio.style.width = '100%';
    audio.setAttribute('aria-label', safeTitle);

    const source = document.createElement('source');
    source.src = url;
    source.type = 'audio/mpeg';
    audio.appendChild(source);

    const fallback = document.createElement('p');
    fallback.style.color = '#fff';
    fallback.style.padding = '12px';
    fallback.textContent = 'Your browser could not load this audio preview.';
    audio.appendChild(fallback);

    previewBody.appendChild(audio);
    try { audio.load(); } catch (err) { console.warn('Audio preview load failed', err); }
  } else {
    const iframe = buildIframe(url, safeTitle);
    previewBody.appendChild(iframe);
  }

  previewModal.style.display = "block";
}

// Ensure other scripts (like the head-level Google Maps helper) can find this
// even if execution order differs between browsers.
window.openPreviewModal = openPreviewModal;

function closePreviewModal() {
  const activeAudio = previewBody.querySelector('audio');
  if (activeAudio && typeof activeAudio.pause === 'function') {
    try { activeAudio.pause(); } catch (_) {}
  }
  previewModal.style.display = "none";
  previewBody.innerHTML = '';
}

window.closePreviewModal = closePreviewModal;

// Bind any main-table notes-button if present (kept for compatibility)
document.querySelectorAll(".view-notes-btn").forEach(btn => {
  btn.addEventListener("click", () => {
    modalNotes.innerHTML = btn.getAttribute("data-notes") || '';
    notesModal.style.display = "block";
  });
});
closeNotesIcon.onclick = () => { notesModal.style.display = "none"; };
closeNotesBtn.onclick  = () => { notesModal.style.display = "none"; };
closePreviewIcon.onclick = () => { closePreviewModal(); };
closePreviewBtn.onclick  = () => { closePreviewModal(); };

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

    // B) Address (clickable map) and Escalation Session ID
    const addressText = data.address ? String(data.address) : '';
    addRow("Address", (addressText && addressText.trim() !== '')
      ? `<a href="javascript:void(0);" class="map-preview-link" data-address="${addressText.replace(/"/g,'&quot;')}">${addressText}</a>`
      : '—'
    );
    addRow("Escalation Session ID", data.escalation_session_id || '—');

    addRow("Gender", data.gender || '');
    addRow("Disability", data.disability || '');
    addRow("Language", data.language || '');
    addRow("User Type", data.user_type || '');
    addRow("Notes", data.notes ? `<a href="javascript:void(0);" class="view-notes-btn" data-notes="${String(data.notes).replace(/"/g,'&quot;')}">View Notes</a>` : 'No Notes');
    if (audio) addRow("Audio", `<a href="javascript:void(0);" class="audio-preview-link" data-audio="${audio}">Play Audio</a>`);
    addRow("Informed Consent", data.informed_consent ? 'Yes' : 'No');

    // bind nested View Notes inside details
    detailsTableBody.querySelectorAll(".view-notes-btn").forEach(nbtn => {
      nbtn.addEventListener("click", () => {
        modalNotes.innerHTML = nbtn.getAttribute("data-notes") || '';
        notesModal.style.display = "block";
      });
    });

    detailsTableBody.querySelectorAll('.audio-preview-link').forEach(link => {
      link.addEventListener('click', () => {
        const audioUrl = link.getAttribute('data-audio') || '';
        openPreviewModal({ url: audioUrl, type: 'audio', title: 'Audio Preview', externalUrl: audioUrl });
      });
    });

    detailsTableBody.querySelectorAll('.map-preview-link').forEach(link => {
      link.addEventListener('click', () => {
        const addr = link.getAttribute('data-address') || '';
        openMapPopup(addr);
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
  if(e.target == previewModal) closePreviewModal();
};

// Close modals with ESC key
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    notesModal.style.display = "none";
    detailsModal.style.display = "none";
    closePreviewModal();
  }
});
</script>

</body>
</html>
