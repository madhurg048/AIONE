<?php
/**
 * Merchant transactions list — searchable, date-filtered, paginated.
 *   GET transactions.php?q=&status=&from=YYYY-MM-DD&to=YYYY-MM-DD&merchant_id=&sort=&dir=&page=&per=
 * Row-level scoped: reps see only transactions on merchants they own.
 */
require_once __DIR__ . '/_bootstrap.php';
require_login();
require_module('transactions');
$db = db();

$scope = owner_scope_inline('m.owner_id');          // '1' for mgr/admin, else own merchants
$where = ['1']; $args = [];
if (!empty($_GET['q'])) {
    $where[] = "(t.txn_ref LIKE ? OR m.legal_name LIKE ? OR m.merchant_code LIKE ? OR t.product LIKE ?)";
    $q = '%' . $_GET['q'] . '%'; array_push($args, $q, $q, $q, $q);
}
if (!empty($_GET['status']))      { $where[] = "t.status = ?";        $args[] = $_GET['status']; }
if (!empty($_GET['from']))        { $where[] = "t.txn_date >= ?";     $args[] = $_GET['from'] . ' 00:00:00'; }
if (!empty($_GET['to']))          { $where[] = "t.txn_date <= ?";     $args[] = $_GET['to'] . ' 23:59:59'; }
if (!empty($_GET['merchant_id'])) { $where[] = "t.merchant_id = ?";   $args[] = (int)$_GET['merchant_id']; }
$base = "FROM merchant_transactions t JOIN merchants m ON m.id=t.merchant_id
         WHERE " . implode(' AND ', $where) . " AND $scope";

/* summary over the FILTERED set */
$sm = $db->prepare("SELECT COUNT(*) txns,
        COALESCE(SUM(CASE WHEN t.status='success' THEN t.amount ELSE 0 END),0) gmv,
        COALESCE(SUM(t.status='success'),0) ok_cnt,
        COALESCE(SUM(t.status='failed'),0) fail_cnt,
        COALESCE(SUM(CASE WHEN DATE(t.txn_date)=CURDATE() THEN 1 ELSE 0 END),0) today $base");
$sm->execute($args); $s = $sm->fetch(PDO::FETCH_ASSOC) ?: [];

/* pagination + sort */
$per = max(1, min(200, (int)($_GET['per'] ?? 25)));
$page = max(1, (int)($_GET['page'] ?? 1));
$off = ($page - 1) * $per;
$cnt = $db->prepare("SELECT COUNT(*) $base"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
$sortMap = ['txn_date'=>'t.txn_date', 'amount'=>'t.amount', 'status'=>'t.status', 'merchant'=>'m.legal_name'];
$sort = $sortMap[$_GET['sort'] ?? ''] ?? 't.txn_date';
$dir = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$q = $db->prepare("SELECT t.*, m.legal_name AS merchant_name, m.merchant_code $base ORDER BY $sort $dir LIMIT $per OFFSET $off");
$q->execute($args);

ok($q->fetchAll(PDO::FETCH_ASSOC), [
    'total' => $total, 'page' => $page, 'pages' => (int)ceil($total / $per),
    'summary' => [
        'txns'  => (int)($s['txns'] ?? 0),  'gmv' => (float)($s['gmv'] ?? 0),
        'ok'    => (int)($s['ok_cnt'] ?? 0), 'fail' => (int)($s['fail_cnt'] ?? 0),
        'today' => (int)($s['today'] ?? 0),
    ],
]);
