<?php
$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid"      => "sa",
  "PWD"      => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) { die(print_r(sqlsrv_errors(), true)); }

function grantUploadAccess($path, $isDirectory = false) {
  static $account = null;
  static $owner = null;
  if (!is_string($path) || $path === '') {
    return;
  }
  if ($account === null) {
    $account = getenv('CASE_UPLOADS_ACCOUNT');
    if ($account === false) {
      $account = '';
    }
    $account = trim($account);
    if ($account === '') {
      $account = 'Users';
    }
  }
  if ($owner === null) {
    $owner = getenv('CASE_UPLOADS_OWNER');
    $owner = $owner === false ? '' : trim($owner);
  }
  if (!function_exists('exec') || stripos(PHP_OS_FAMILY, 'Windows') === false) {
    return;
  }
  if (!file_exists($path)) {
    return;
  }
  if ($account !== '') {
    $permission = $isDirectory ? '(OI)(CI)RX' : '(R)';
    $command = 'icacls ' . escapeshellarg($path) . ' /grant ' . escapeshellarg($account) . ':' . $permission;
    $output = [];
    $status = 0;
    @exec($command . ' 2>&1', $output, $status);
    if ($status !== 0 && !empty($output)) {
      error_log('icacls failed for ' . $path . ': ' . implode('; ', $output));
    }
  }
  if ($owner !== '') {
    $setOwner = 'icacls ' . escapeshellarg($path) . ' /setowner ' . escapeshellarg($owner);
    $ownerOutput = [];
    $ownerStatus = 0;
    @exec($setOwner . ' 2>&1', $ownerOutput, $ownerStatus);
    if ($ownerStatus !== 0 && !empty($ownerOutput)) {
      error_log('icacls setowner failed for ' . $path . ': ' . implode('; ', $ownerOutput));
    }
  }
}

$case_number      = $_POST['case_number'] ?? '';
$date_time        = $_POST['date_time'] ?? '';
$spn              = $_POST['spn'] ?? '';
$first_name       = $_POST['first_name'] ?? '';
$middle_name      = $_POST['middle_name'] ?? '';
$family_name      = $_POST['family_name'] ?? '';
$age              = isset($_POST['age']) && $_POST['age'] !== '' ? $_POST['age'] : null;
$gender           = $_POST['gender'] ?? '';
$disability       = $_POST['disability'] ?? '';
$language         = $_POST['language'] ?? '';
$user_type        = $_POST['user_type'] ?? '';
$notes            = $_POST['notes'] ?? '';
$status           = $_POST['status'] ?? 'Open';
$phone_number     = $_POST['phone_number'] ?? '';
$informed_consent = isset($_POST['informed_consent']) ? 1 : 0;
$address          = $_POST['address'] ?? '';

// OPTIONAL: prevent duplicates (you used this earlier)
$chk = sqlsrv_query($conn, "SELECT 1 FROM mwcsp_caser WHERE case_number = ?", [$case_number]);
if ($chk && sqlsrv_fetch($chk)) {
  die("A case with this Case Number already exists.");
}

// Determine server-side timestamp for insert
$date_time_sql = null;
$server_now_string = null;
$server_offset_string = null;
$timeResult = sqlsrv_query(
  $conn,
  "SELECT CONVERT(VARCHAR(33), SYSDATETIMEOFFSET(), 126) AS server_now_offset, " .
  "       CONVERT(VARCHAR(19), SYSDATETIME(), 120) AS server_now"
);
if ($timeResult && ($timeRow = sqlsrv_fetch_array($timeResult, SQLSRV_FETCH_ASSOC))) {
  if (!empty($timeRow['server_now'])) {
    $server_now_string = $timeRow['server_now'];
  }
  if (!empty($timeRow['server_now_offset']) && preg_match('/^[0-9T:\-\.]+([+-]\d{2}:\d{2})$/', $timeRow['server_now_offset'], $m)) {
    $server_offset_string = $m[1];
  }
}

if (!empty($date_time)) {
  $targetTz = null;
  if ($server_offset_string && preg_match('/^[+-]\d{2}:\d{2}$/', $server_offset_string)) {
    try {
      $targetTz = new DateTimeZone($server_offset_string);
    } catch (Exception $e) {
      $targetTz = null;
    }
  }
  if ($targetTz === null) {
    $tzName = @date_default_timezone_get();
    try {
      $targetTz = new DateTimeZone($tzName ?: 'UTC');
    } catch (Exception $e) {
      $targetTz = new DateTimeZone('UTC');
    }
  }

  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $date_time, $targetTz);
  if ($dt instanceof DateTime) {
    $date_time_sql = $dt->format('Y-m-d H:i:s');
  }
}

if ($date_time_sql === null && $server_now_string === null) {
  $date_time_sql = date('Y-m-d H:i:s');
}

// Handle attachments: save & append as links into notes
$attachmentLinks = [];
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
  $baseDir = __DIR__ . '/uploads/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $case_number);
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0777, true);
  }
  grantUploadAccess($baseDir, true);
  for ($i=0; $i<count($_FILES['attachments']['name']); $i++) {
    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
      $tmp  = $_FILES['attachments']['tmp_name'][$i];
      $name = basename($_FILES['attachments']['name'][$i]);
      $stamp = date('Ymd_His');
      $safe = $stamp . '_' . preg_replace('/[^\w.\-]/', '_', $name);
      $dest = $baseDir . '/' . $safe;
      if (move_uploaded_file($tmp, $dest)) {
        grantUploadAccess($dest);
        $url = 'uploads/' . rawurlencode($case_number) . '/' . rawurlencode($safe);
        $displayName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $attachmentLinks[] = '<a href="' . $href . '" class="attachment-link" data-filename="' . $displayName . '">' . $displayName . '</a>';
      }
    }
  }
}
if (!empty($attachmentLinks)) {
  $notes = trim($notes . (strlen($notes)?' ':'') . 'Attachments: ' . implode(', ', $attachmentLinks));
}

$sql = "INSERT INTO mwcsp_caser
(date_time, case_number, spn, first_name, middle_name, family_name, age, gender, disability, language, user_type, notes, status, phone_number, informed_consent, address)
VALUES (COALESCE(?, SYSDATETIME()), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$params = [
  $date_time_sql, $case_number, $spn, $first_name, $middle_name, $family_name, $age,
  $gender, $disability, $language, $user_type, $notes, $status,
  $phone_number, $informed_consent, $address
];

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) { die(print_r(sqlsrv_errors(), true)); }
sqlsrv_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Case Submitted</title>
<link rel="stylesheet" href="css/style.css">
<style>
body { font-family: Arial, sans-serif; background:#f7f9fc; margin:0; }
.container { max-width: 600px; margin: 60px auto; background:#fff; padding:30px; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,.1); text-align:center; }
.success-msg { font-size:20px; color:#2e7d32; font-weight:600; }
</style>
<script> setTimeout(function(){ window.location.href='cases.php'; }, 3000); </script>
</head>
<body>
<div class="header">
  <a href="form.php">➕ New Case</a>
  <a href="cases.php">📋 Case List</a>
  <a href="search.php">🔍 Search Cases</a>
  <a href="dashboard.php">📊 Dashboard</a>
</div>
<div class="container"><p class="success-msg">✅ Case submitted successfully.</p></div>
</body>
</html>
