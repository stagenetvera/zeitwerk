<?php
// public/companies/update_and_redirect.php
require __DIR__ . '/../../src/layout/header.php';
require_login();
csrf_check();

$user = auth_user();
$account_id = (int)$user['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('/companies/index.php');
  exit;
}

$id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');

if ($id <= 0) {
  flash('Ungültige Firmen-ID.', 'danger');
  redirect('/companies/index.php');
  exit;
}

// Firma laden (Ownership)
$sel = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND account_id = ?');
$sel->execute([$id, $account_id]);
$company = $sel->fetch();
if (!$company) {
  flash('Firma nicht gefunden.', 'danger');
  redirect('/companies/index.php');
  exit;
}

if ($name === '') {
  flash('Name ist erforderlich.', 'danger');
  redirect('/companies/edit.php?id=' . $id);
  exit;
}

// Felder übernehmen
$address     = trim($_POST['address'] ?? '');
$hourly_rate = ($_POST['hourly_rate'] ?? '') !== '' ? (float)$_POST['hourly_rate'] : null;
$vat_id      = trim($_POST['vat_id'] ?? '');
$status      = $_POST['status'] ?? $company['status'];

// Update
$upd = $pdo->prepare('UPDATE companies
  SET name = ?, address = ?, hourly_rate = ?, vat_id = ?, status = ?
  WHERE id = ? AND account_id = ?');
$upd->execute([$name, $address, $hourly_rate, $vat_id, $status, $id, $account_id]);

redirect('/companies/index.php');
exit;
