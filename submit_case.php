<?php
$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid"      => "sa",
  "PWD"      => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) { die(print_r(sqlsrv_errors(), true)); }

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

// Fix datetime for SQL
$date_time_sql = !empty($date_time) ? (str_replace('T',' ',$date_time).':00') : date('Y-m-d H:i:s');

// Handle attachments: save & append as links into notes
$attachmentLinks = [];
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
  $baseDir = __DIR__ . '/uploads/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $case_number);
  if (!is_dir($baseDir)) { @mkdir($baseDir, 0777, true); }
  for ($i=0; $i<count($_FILES['attachments']['name']); $i++) {
    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
      $tmp  = $_FILES['attachments']['tmp_name'][$i];
      $name = basename($_FILES['attachments']['name'][$i]);
      $stamp = date('Ymd_His');
      $safe = $stamp . '_' . preg_replace('/[^\w.\-]/', '_', $name);
      $dest = $baseDir . '/' . $safe;
      if (move_uploaded_file($tmp, $dest)) {
        $url = 'uploads/' . rawurlencode($case_number) . '/' . rawurlencode($safe);
        $attachmentLinks[] = "<a href=\"$url\" target=\"_blank\">$name</a>";
      }
    }
  }
}
if (!empty($attachmentLinks)) {
  $notes = trim($notes . (strlen($notes)?' ':'') . 'Attachments: ' . implode(', ', $attachmentLinks));
}

$sql = "INSERT INTO mwcsp_caser
(date_time, case_number, spn, first_name, middle_name, family_name, age, gender, disability, language, user_type, notes, status, phone_number, informed_consent, address)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

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
