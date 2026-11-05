<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_helpers.php';

function mapInvoiceOutstandingCheque($row) {
    return [
        'id' => (string) $row['id'],
        'amount' => (float) $row['amount'],
        'date' => $row['date'],
        'ref' => 'INV-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
        'source' => 'invoice'
    ];
}

function mapManualOutstandingCheque($row) {
    return [
        'id' => 'manual-' . $row['id'],
        'amount' => (float) $row['amount'],
        'date' => $row['date'],
        'ref' => 'CHQ-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
        'source' => 'manual'
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJson(['ok' => true]);
}

try {
    $pdo = getDbConnection();
    repairInvoiceTotalsFromItems($pdo);
    $input = readJsonInput();
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'getPartyBalance':
            $partyId = (int) ($input['partyId'] ?? 0);
            if ($partyId <= 0) {
                sendJson(['ok' => false, 'message' => 'Party ID is required.'], 400);
            }

            // Fetch opening balance of the party
            $partyStmt = $pdo->prepare('SELECT opening_balance FROM parties WHERE id = ?');
            $partyStmt->execute([$partyId]);
            $partyRow = $partyStmt->fetch();
            $openingBalance = $partyRow ? (float) $partyRow['opening_balance'] : 0.0;

            // Total Credit = current invoice amounts + sale returns (so originals show as sales)
            $stmt = $pdo->prepare('SELECT SUM(amount) as total_credit FROM invoices WHERE party_id = ?');
            $stmt->execute([$partyId]);
            $creditRow = $stmt->fetch();
            $totalCredit = (float) ($creditRow['total_credit'] ?? 0);

            $returnCreditStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM sale_returns WHERE party_id = ?');
            $returnCreditStmt->execute([$partyId]);
            $saleReturnsTotal = (float) $returnCreditStmt->fetchColumn();
            $totalCredit += $saleReturnsTotal;

            $manualCreditStmt = $pdo->prepare('SELECT SUM(amount) as total_credit FROM outstanding_cheques WHERE party_id = ? AND status = ?');
            $manualCreditStmt->execute([$partyId, 'Pending']);
            $manualCreditRow = $manualCreditStmt->fetch();
            $totalCredit += (float) ($manualCreditRow['total_credit'] ?? 0);

            // Total Debit (Payment Receipts + Sale Returns)
            $stmt = $pdo->prepare('SELECT SUM(amount) as total_debit FROM payment_receipts WHERE party_id = ?');
            $stmt->execute([$partyId]);
            $debitRow = $stmt->fetch();
            $totalDebit = (float) ($debitRow['total_debit'] ?? 0) + $saleReturnsTotal;

            // Outstanding cheques for this client (full or remaining amount)
            $chequeStmt = $pdo->prepare(
                'SELECT inv.id,
                        GREATEST(inv.amount - COALESCE((
                           SELECT SUM(pr.amount) FROM payment_receipts pr WHERE pr.invoice_id = inv.id
                        ), 0), 0) AS amount,
                        inv.invoice_date as date
                 FROM invoices inv
                 WHERE inv.party_id = ? AND inv.payment_type = ? AND inv.payment_status IN (?, ?)
                 HAVING amount > 0
                 ORDER BY inv.invoice_date ASC, inv.id ASC'
            );
            $chequeStmt->execute([$partyId, 'Cheque', 'Pending', 'Partial']);
            $chequeRows = $chequeStmt->fetchAll();
            $outstandingCheques = array_map('mapInvoiceOutstandingCheque', $chequeRows);

            $manualChequeStmt = $pdo->prepare('SELECT id, amount, cheque_date as date FROM outstanding_cheques WHERE party_id = ? AND status = ? ORDER BY cheque_date ASC, id ASC');
            $manualChequeStmt->execute([$partyId, 'Pending']);
            $manualChequeRows = $manualChequeStmt->fetchAll();
            $outstandingCheques = array_merge(
                $outstandingCheques,
                array_map('mapManualOutstandingCheque', $manualChequeRows)
            );
            $outstandingChequeAmount = array_sum(array_map(fn($row) => (float) $row['amount'], $outstandingCheques));

            $balance = $openingBalance + $totalCredit - $totalDebit;

            sendJson([
                'ok' => true,
                'data' => [
                    'openingBalance' => $openingBalance,
                    'totalCredit' => $totalCredit,
                    'totalDebit' => $totalDebit,
                    'balance' => $balance,
                    'outstandingChequeCount' => count($outstandingCheques),
                    'outstandingChequeAmount' => $outstandingChequeAmount,
                    'outstandingCheques' => $outstandingCheques
                ]
            ]);
            break;

        case 'getLedger':
            $partyId = (int) ($input['partyId'] ?? 0);
            $startDate = trim((string) ($input['startDate'] ?? ''));
            $endDate = trim((string) ($input['endDate'] ?? ''));
            if ($partyId <= 0) {
                sendJson(['ok' => false, 'message' => 'Party ID is required.'], 400);
            }

            // Fetch opening balance of the party
            $partyStmt = $pdo->prepare('SELECT opening_balance, created_at FROM parties WHERE id = ?');
            $partyStmt->execute([$partyId]);
            $partyRow = $partyStmt->fetch();
            $openingBalance = $partyRow ? (float) $partyRow['opening_balance'] : 0.0;
            $partyCreatedAt = $partyRow ? $partyRow['created_at'] : '-';

            // Invoices shown at original credit (amount + returns);
            // Sale Returns appear as Debit (RET-####); Receipts as Debit.
            // Net balance matches: opening + current_invoice_amounts - receipts.
            $query = "
                (SELECT 'Invoice' as type, inv.id,
                        (inv.amount + COALESCE((
                            SELECT SUM(sr.amount) FROM sale_returns sr WHERE sr.invoice_id = inv.id
                        ), 0)) as credit,
                        0 as debit,
                        DATE(inv.invoice_date) as date,
                        CONCAT('INV-', LPAD(inv.id, 4, '0')) as ref,
                        inv.payment_type, inv.payment_status
                 FROM invoices inv WHERE inv.party_id = ?)
                UNION ALL
                (SELECT 'Cheque' as type, id, amount as credit, 0 as debit, cheque_date as date, CONCAT('CHQ-', LPAD(id, 4, '0')) as ref, 'Cheque' as payment_type, status as payment_status
                 FROM outstanding_cheques WHERE party_id = ? AND status = 'Pending')
                UNION ALL
                (SELECT 'Return' as type, sr.id, 0 as credit, sr.amount as debit, sr.return_date as date,
                        CONCAT('RET-', LPAD(sr.id, 4, '0'), ' · INV-', LPAD(sr.invoice_id, 4, '0')) as ref,
                        'Return' as payment_type, 'Returned' as payment_status
                 FROM sale_returns sr WHERE sr.party_id = ?)
                UNION ALL
                (SELECT 'Receipt' as type, id, 0 as credit, amount as debit, receipt_date as date, details as ref, payment_type, 'Received' as payment_status
                 FROM payment_receipts WHERE party_id = ?)
                ORDER BY date ASC, type DESC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$partyId, $partyId, $partyId, $partyId]);
            $rows = $stmt->fetchAll();

            $ledger = [];
            
            // Add Opening Balance as first entry
            $ledger[] = [
                'type' => 'Opening',
                'id' => 0,
                'credit' => $openingBalance >= 0 ? $openingBalance : 0,
                'debit' => $openingBalance < 0 ? abs($openingBalance) : 0,
                'date' => $partyCreatedAt,
                'ref' => 'Opening Balance',
                'paymentType' => 'Cash',
                'paymentStatus' => 'Received',
                'balance' => $openingBalance
            ];

            $runningBalance = $openingBalance;
            $filteredCredit = $openingBalance >= 0 ? $openingBalance : 0;
            $filteredDebit = $openingBalance < 0 ? abs($openingBalance) : 0;
            $filteredLedger = [$ledger[0]];

            foreach ($rows as $row) {
                $credit = (float) $row['credit'];
                $debit = (float) $row['debit'];
                $runningBalance += ($credit - $debit);
                $entry = [
                    'type' => $row['type'],
                    'id' => $row['id'],
                    'credit' => $credit,
                    'debit' => $debit,
                    'date' => $row['date'],
                    'ref' => $row['ref'],
                    'paymentType' => $row['payment_type'] ?? 'Cash',
                    'paymentStatus' => $row['payment_status'] ?? 'Received',
                    'balance' => $runningBalance,
                    'billId' => null,
                    'inventoryName' => null,
                    'itemCount' => 0,
                    'items' => [],
                    'details' => $row['ref'],
                ];

                if ($row['type'] === 'Invoice') {
                    $invoiceId = (int) $row['id'];
                    $items = fetchInvoiceItems($pdo, $invoiceId);
                    $billStmt = $pdo->prepare('SELECT bill_id, sale_id FROM invoices WHERE id = ?');
                    $billStmt->execute([$invoiceId]);
                    $invMeta = $billStmt->fetch() ?: [];
                    $billId = !empty($invMeta['bill_id'])
                        ? (int) $invMeta['bill_id']
                        : (!empty($invMeta['sale_id']) ? (int) $invMeta['sale_id'] : $invoiceId);

                    if (!$items) {
                        // Legacy single-line invoices without invoice_items
                        $legacyStmt = $pdo->prepare(
                            "SELECT i.inventory_name, COALESCE(pk.name, i.packing) AS packing_name,
                                    pk.weight AS packing_weight, inv.amount, s.weight, s.price
                             FROM invoices inv
                             LEFT JOIN inventory i ON i.id = inv.inventory_id
                             LEFT JOIN packings pk ON pk.id = i.packing_id
                             LEFT JOIN sales s ON s.id = inv.sale_id
                             WHERE inv.id = ?"
                        );
                        $legacyStmt->execute([$invoiceId]);
                        $legacy = $legacyStmt->fetch();
                        if ($legacy && !empty($legacy['inventory_name'])) {
                            $items = [[
                                'id' => '0',
                                'saleId' => (int) ($invMeta['sale_id'] ?? 0),
                                'inventoryId' => 0,
                                'inventoryName' => $legacy['inventory_name'],
                                'packingId' => null,
                                'packing' => $legacy['packing_name'] ?? '',
                                'packingWeight' => isset($legacy['packing_weight']) && $legacy['packing_weight'] !== null
                                    ? (float) $legacy['packing_weight']
                                    : null,
                                'weight' => (float) ($legacy['weight'] ?? 0),
                                'price' => (float) ($legacy['price'] ?? 0),
                                'amount' => round((float) ($legacy['amount'] ?? $credit), 2),
                            ]];
                        }
                    }

                    // For ledger display, show original sold lines (remaining + returned)
                    $retLinesStmt = $pdo->prepare(
                        "SELECT sr.sale_id, sr.inventory_id, i.inventory_name,
                                COALESCE(pk.name, i.packing) AS packing_name, pk.weight AS packing_weight,
                                sr.weight, sr.price, sr.amount
                         FROM sale_returns sr
                         LEFT JOIN inventory i ON i.id = sr.inventory_id
                         LEFT JOIN packings pk ON pk.id = i.packing_id
                         WHERE sr.invoice_id = ?"
                    );
                    $retLinesStmt->execute([$invoiceId]);
                    $retLines = $retLinesStmt->fetchAll();
                    if ($retLines) {
                        $bySale = [];
                        foreach ($items as $item) {
                            $key = (int) ($item['saleId'] ?? 0);
                            $bySale[$key] = $item;
                        }
                        foreach ($retLines as $rl) {
                            $key = (int) $rl['sale_id'];
                            if (isset($bySale[$key])) {
                                $bySale[$key]['weight'] = round(
                                    (float) $bySale[$key]['weight'] + (float) $rl['weight'],
                                    2
                                );
                                $bySale[$key]['amount'] = round(
                                    (float) $bySale[$key]['amount'] + (float) $rl['amount'],
                                    2
                                );
                            } else {
                                $bySale[$key] = [
                                    'id' => '0',
                                    'saleId' => $key,
                                    'inventoryId' => (int) $rl['inventory_id'],
                                    'inventoryName' => $rl['inventory_name'] ?? 'Unknown',
                                    'packingId' => null,
                                    'packing' => $rl['packing_name'] ?? '',
                                    'packingWeight' => isset($rl['packing_weight']) && $rl['packing_weight'] !== null
                                        ? (float) $rl['packing_weight']
                                        : null,
                                    'weight' => (float) $rl['weight'],
                                    'price' => (float) $rl['price'],
                                    'amount' => round((float) $rl['amount'], 2),
                                ];
                            }
                        }
                        $items = array_values($bySale);
                    }

                    $names = array_values(array_unique(array_map(
                        fn($item) => $item['inventoryName'],
                        $items
                    )));
                    $inventoryLabel = !$names
                        ? null
                        : (count($names) <= 2
                            ? implode(' + ', $names)
                            : $names[0] . ' + ' . (count($names) - 1) . ' more');

                    $detailLines = array_map(function ($item) {
                        $name = $item['inventoryName'] ?? 'Unknown';
                        $packing = trim((string) ($item['packing'] ?? ''));
                        $label = $packing !== '' ? "{$name} ({$packing})" : $name;
                        $piece = number_format((float) ($item['weight'] ?? 0), 2, '.', '');
                        $price = number_format((float) ($item['price'] ?? 0), 2, '.', '');
                        $lineTotal = number_format((float) ($item['amount'] ?? 0), 2, '.', '');
                        return "{$label} — {$piece} × Rs.{$price} = Rs.{$lineTotal}";
                    }, $items);

                    $refParts = ['INV-' . str_pad((string) $invoiceId, 4, '0', STR_PAD_LEFT)];
                    if ($billId > 0) {
                        $refParts[] = 'BILL-' . str_pad((string) $billId, 4, '0', STR_PAD_LEFT);
                    }
                    if ($inventoryLabel) {
                        $refParts[] = $inventoryLabel;
                    }

                    $entry['billId'] = $billId > 0 ? (string) $billId : null;
                    $entry['inventoryName'] = $inventoryLabel;
                    $entry['itemCount'] = max(count($items), 1);
                    $entry['items'] = $items;
                    $entry['ref'] = implode(' · ', $refParts);
                    $entry['details'] = $detailLines
                        ? implode("\n", $detailLines)
                        : $entry['ref'];
                } elseif ($row['type'] === 'Return') {
                    $returnId = (int) $row['id'];
                    $retStmt = $pdo->prepare(
                        "SELECT sr.*, i.inventory_name, COALESCE(pk.name, i.packing) AS packing_name,
                                pk.weight AS packing_weight
                         FROM sale_returns sr
                         LEFT JOIN inventory i ON i.id = sr.inventory_id
                         LEFT JOIN packings pk ON pk.id = i.packing_id
                         WHERE sr.id = ?"
                    );
                    $retStmt->execute([$returnId]);
                    $ret = $retStmt->fetch();
                    if ($ret) {
                        $name = $ret['inventory_name'] ?? 'Unknown';
                        $packing = trim((string) ($ret['packing_name'] ?? ''));
                        $label = $packing !== '' ? "{$name} ({$packing})" : $name;
                        $piece = number_format((float) $ret['weight'], 2, '.', '');
                        $entry['inventoryName'] = $name;
                        $entry['itemCount'] = 1;
                        $entry['items'] = [[
                            'saleId' => (int) $ret['sale_id'],
                            'inventoryId' => (int) $ret['inventory_id'],
                            'inventoryName' => $name,
                            'packing' => $packing,
                            'packingWeight' => isset($ret['packing_weight']) && $ret['packing_weight'] !== null
                                ? (float) $ret['packing_weight']
                                : null,
                            'weight' => (float) $ret['weight'],
                            'price' => (float) $ret['price'],
                            'amount' => round((float) $ret['amount'], 2),
                        ]];
                        $entry['ref'] = 'RET-' . str_pad((string) $returnId, 4, '0', STR_PAD_LEFT)
                            . ' · INV-' . str_pad((string) $ret['invoice_id'], 4, '0', STR_PAD_LEFT)
                            . ' · ' . $label;
                        $entry['details'] = "Sale return — {$label} — {$piece} pcs · Rs."
                            . number_format((float) $ret['amount'], 2, '.', '');
                    }
                }

                $ledger[] = $entry;

                $entryDate = substr((string) $row['date'], 0, 10);
                $inRange = true;
                if ($startDate !== '' && $entryDate < $startDate) {
                    $inRange = false;
                }
                if ($endDate !== '' && $entryDate > $endDate) {
                    $inRange = false;
                }
                if ($inRange) {
                    $filteredLedger[] = $entry;
                    $filteredCredit += $credit;
                    $filteredDebit += $debit;
                }
            }

            $useFilter = ($startDate !== '' || $endDate !== '');
            sendJson([
                'ok' => true,
                'data' => $useFilter ? $filteredLedger : $ledger,
                'totals' => [
                    'totalCredit' => $useFilter ? $filteredCredit : array_sum(array_map(fn($e) => (float) $e['credit'], $ledger)),
                    'totalDebit' => $useFilter ? $filteredDebit : array_sum(array_map(fn($e) => (float) $e['debit'], $ledger)),
                    'balance' => $runningBalance,
                    'filtered' => $useFilter,
                    'startDate' => $startDate ?: null,
                    'endDate' => $endDate ?: null
                ]
            ]);
            break;

        case 'getBankLedger':
            $bankId = (int) ($input['bankId'] ?? 0);
            if ($bankId <= 0) {
                sendJson(['ok' => false, 'message' => 'Bank ID is required.'], 400);
            }

            // 1. Get Opening Balance
            $stmt = $pdo->prepare('SELECT bank_name, opening_balance FROM banks WHERE id = ?');
            $stmt->execute([$bankId]);
            $bank = $stmt->fetch();
            if (!$bank) throw new RuntimeException('Bank not found.');

            $openingBalance = (float) $bank['opening_balance'];

            // 2. Get Transactions
            // Inflow: Receipts + Bank Transactions (Inflow)
            // Outflow: Bank Transactions (Outflow)
            $query = "
                (SELECT 'Receipt' as type, pr.id, pr.amount as inflow, 0 as outflow, pr.receipt_date as date, p.name as ref 
                 FROM payment_receipts pr LEFT JOIN parties p ON pr.party_id = p.id WHERE pr.bank_id = ?)
                UNION ALL
                (SELECT type as type, id, (CASE WHEN type='Inflow' THEN amount ELSE 0 END) as inflow, (CASE WHEN type='Outflow' THEN amount ELSE 0 END) as outflow, transaction_date as date, details as ref 
                 FROM bank_transactions WHERE bank_id = ?)
                ORDER BY date ASC, id ASC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$bankId, $bankId]);
            $rows = $stmt->fetchAll();

            $ledger = [];
            // Add Opening Balance as first entry
            $ledger[] = [
                'type' => 'Opening',
                'id' => 0,
                'inflow' => $openingBalance,
                'outflow' => 0,
                'date' => '-',
                'ref' => 'Opening Balance',
                'balance' => $openingBalance
            ];

            $runningBalance = $openingBalance;
            foreach ($rows as $row) {
                $inflow = (float) $row['inflow'];
                $outflow = (float) $row['outflow'];
                $runningBalance += ($inflow - $outflow);
                $ledger[] = [
                    'type' => $row['type'],
                    'id' => $row['id'],
                    'inflow' => $inflow,
                    'outflow' => $outflow,
                    'date' => $row['date'],
                    'ref' => $row['ref'],
                    'balance' => $runningBalance
                ];
            }

            sendJson([
                'ok' => true, 
                'data' => [
                    'bankName' => $bank['bank_name'],
                    'ledger' => $ledger,
                    'currentBalance' => $runningBalance
                ]
            ]);
            break;

        case 'getTrialBalance':
            $startDate = trim((string) ($input['startDate'] ?? ''));
            $endDate = trim((string) ($input['endDate'] ?? ''));

            $creditSql = "SELECT inv.id, inv.amount, DATE(inv.invoice_date) as date, p.name as party_name,
                                 CONCAT('INV-', LPAD(inv.id, 4, '0')) as ref, 'Credit' as side
                          FROM invoices inv
                          LEFT JOIN parties p ON inv.party_id = p.id
                          WHERE 1=1";
            $creditParams = [];
            if ($startDate !== '') {
                $creditSql .= ' AND DATE(inv.invoice_date) >= ?';
                $creditParams[] = $startDate;
            }
            if ($endDate !== '') {
                $creditSql .= ' AND DATE(inv.invoice_date) <= ?';
                $creditParams[] = $endDate;
            }
            $creditSql .= ' ORDER BY inv.invoice_date ASC, inv.id ASC';
            $creditStmt = $pdo->prepare($creditSql);
            $creditStmt->execute($creditParams);
            $creditRows = $creditStmt->fetchAll();

            $debitSql = "SELECT pr.id, pr.amount, pr.receipt_date as date, p.name as party_name,
                                CONCAT('REC-', LPAD(pr.id, 4, '0')) as ref, 'Debit' as side, 'Receipt' as entry_type
                         FROM payment_receipts pr
                         LEFT JOIN parties p ON pr.party_id = p.id
                         WHERE 1=1";
            $debitParams = [];
            if ($startDate !== '') {
                $debitSql .= ' AND pr.receipt_date >= ?';
                $debitParams[] = $startDate;
            }
            if ($endDate !== '') {
                $debitSql .= ' AND pr.receipt_date <= ?';
                $debitParams[] = $endDate;
            }
            $debitSql .= ' ORDER BY pr.receipt_date ASC, pr.id ASC';
            $debitStmt = $pdo->prepare($debitSql);
            $debitStmt->execute($debitParams);
            $debitRows = $debitStmt->fetchAll();

            // Expenses affect trial balance as Debit
            $expenseSql = "SELECT e.id, e.amount, e.expense_date as date,
                                  COALESCE(ec.name, 'Expense') as party_name,
                                  CONCAT('EXP-', LPAD(e.id, 4, '0')) as ref, 'Debit' as side, 'Expense' as entry_type
                           FROM expenses e
                           LEFT JOIN expense_categories ec ON e.category_id = ec.id
                           WHERE 1=1";
            $expenseParams = [];
            if ($startDate !== '') {
                $expenseSql .= ' AND e.expense_date >= ?';
                $expenseParams[] = $startDate;
            }
            if ($endDate !== '') {
                $expenseSql .= ' AND e.expense_date <= ?';
                $expenseParams[] = $endDate;
            }
            $expenseSql .= ' ORDER BY e.expense_date ASC, e.id ASC';
            $expenseStmt = $pdo->prepare($expenseSql);
            $expenseStmt->execute($expenseParams);
            $expenseRows = $expenseStmt->fetchAll();

            $totalCredit = array_sum(array_map(fn($row) => (float) $row['amount'], $creditRows));
            $totalDebit = array_sum(array_map(fn($row) => (float) $row['amount'], $debitRows))
                + array_sum(array_map(fn($row) => (float) $row['amount'], $expenseRows));

            $entries = array_merge(
                array_map(function ($row) {
                    return [
                        'type' => 'Invoice',
                        'ref' => $row['ref'],
                        'partyName' => $row['party_name'] ?? 'Unknown',
                        'amount' => (float) $row['amount'],
                        'date' => $row['date'],
                        'side' => 'Credit'
                    ];
                }, $creditRows),
                array_map(function ($row) {
                    return [
                        'type' => $row['entry_type'] ?? 'Receipt',
                        'ref' => $row['ref'],
                        'partyName' => $row['party_name'] ?? 'Unknown',
                        'amount' => (float) $row['amount'],
                        'date' => $row['date'],
                        'side' => 'Debit'
                    ];
                }, $debitRows),
                array_map(function ($row) {
                    return [
                        'type' => 'Expense',
                        'ref' => $row['ref'],
                        'partyName' => $row['party_name'] ?? 'Expense',
                        'amount' => (float) $row['amount'],
                        'date' => $row['date'],
                        'side' => 'Debit'
                    ];
                }, $expenseRows)
            );

            sendJson([
                'ok' => true,
                'data' => [
                    'entries' => $entries,
                    'creditEntries' => array_values(array_filter($entries, fn($entry) => $entry['side'] === 'Credit')),
                    'debitEntries' => array_values(array_filter($entries, fn($entry) => $entry['side'] === 'Debit')),
                    'totalCredit' => $totalCredit,
                    'totalDebit' => $totalDebit,
                    'totalExpenses' => array_sum(array_map(fn($row) => (float) $row['amount'], $expenseRows)),
                    'balance' => $totalCredit - $totalDebit,
                    'startDate' => $startDate ?: null,
                    'endDate' => $endDate ?: null
                ]
            ]);
            break;

        case 'getCheques':
            // Pending cheques: invoices with Cheque payment type not yet fully received
            $pendingStmt = $pdo->query("
                SELECT inv.id, inv.invoice_date as date,
                       GREATEST(inv.amount - COALESCE((
                         SELECT SUM(pr.amount) FROM payment_receipts pr WHERE pr.invoice_id = inv.id
                       ), 0), 0) AS amount,
                       'Pending' as cheque_status,
                       p.name as party_name, p.id as party_id
                FROM invoices inv
                LEFT JOIN parties p ON inv.party_id = p.id
                WHERE inv.payment_type = 'Cheque' AND inv.payment_status IN ('Pending', 'Partial')
                HAVING amount > 0
                ORDER BY inv.invoice_date DESC
            ");
            $pendingRows = $pendingStmt->fetchAll();

            // Received cheques: payment receipts with Cheque payment type
            $receivedStmt = $pdo->query("
                SELECT pr.id, pr.receipt_date as date, pr.amount, 'Received' as cheque_status,
                       p.name as party_name, p.id as party_id, b.bank_name
                FROM payment_receipts pr
                LEFT JOIN parties p ON pr.party_id = p.id
                LEFT JOIN banks b ON pr.bank_id = b.id
                WHERE pr.payment_type = 'Cheque'
                ORDER BY pr.receipt_date DESC
            ");
            $receivedRows = $receivedStmt->fetchAll();

            $cheques = [];
            foreach ($pendingRows as $row) {
                $cheques[] = [
                    'id' => (string) $row['id'],
                    'date' => $row['date'],
                    'amount' => (float) $row['amount'],
                    'status' => 'Pending',
                    'partyName' => $row['party_name'],
                    'partyId' => (string) $row['party_id'],
                    'bankName' => '-',
                    'ref' => 'INV-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                    'source' => 'invoice'
                ];
            }

            $manualPendingStmt = $pdo->query("
                SELECT oc.id, oc.cheque_date as date, oc.amount, p.name as party_name, p.id as party_id, b.bank_name
                FROM outstanding_cheques oc
                LEFT JOIN parties p ON oc.party_id = p.id
                LEFT JOIN banks b ON oc.bank_id = b.id
                WHERE oc.status = 'Pending'
                ORDER BY oc.cheque_date DESC
            ");
            $manualPendingRows = $manualPendingStmt->fetchAll();
            foreach ($manualPendingRows as $row) {
                $cheques[] = [
                    'id' => 'manual-' . $row['id'],
                    'date' => $row['date'],
                    'amount' => (float) $row['amount'],
                    'status' => 'Pending',
                    'partyName' => $row['party_name'],
                    'partyId' => (string) $row['party_id'],
                    'bankName' => $row['bank_name'] ?? '-',
                    'ref' => 'CHQ-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                    'source' => 'manual'
                ];
            }

            foreach ($receivedRows as $row) {
                $cheques[] = [
                    'id' => (string) $row['id'],
                    'date' => $row['date'],
                    'amount' => (float) $row['amount'],
                    'status' => 'Received',
                    'partyName' => $row['party_name'],
                    'partyId' => (string) $row['party_id'],
                    'bankName' => $row['bank_name'] ?? '-',
                    'ref' => 'REC-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                    'source' => 'receipt'
                ];
            }

            sendJson(['ok' => true, 'data' => $cheques]);
            break;

        case 'addOutstandingCheque':
            $payload = $input['payload'] ?? [];
            $partyId = (int) ($payload['partyId'] ?? 0);
            $amount = (float) ($payload['amount'] ?? 0);
            $date = $payload['date'] ?? date('Y-m-d');
            $bankId = !empty($payload['bankId']) ? (int) $payload['bankId'] : null;
            $details = trim((string) ($payload['details'] ?? ''));
            if ($details === '') {
                $details = 'Manual outstanding cheque';
            }

            if ($partyId <= 0) {
                sendJson(['ok' => false, 'message' => 'Client is required.'], 400);
            }
            if ($amount <= 0) {
                sendJson(['ok' => false, 'message' => 'Cheque amount must be greater than 0.'], 400);
            }

            $partyStmt = $pdo->prepare('SELECT id FROM parties WHERE id = ?');
            $partyStmt->execute([$partyId]);
            if (!$partyStmt->fetch()) {
                sendJson(['ok' => false, 'message' => 'Client not found.'], 400);
            }

            if ($bankId) {
                $bankStmt = $pdo->prepare('SELECT id FROM banks WHERE id = ?');
                $bankStmt->execute([$bankId]);
                if (!$bankStmt->fetch()) {
                    sendJson(['ok' => false, 'message' => 'Bank not found.'], 400);
                }
            }

            $stmt = $pdo->prepare('INSERT INTO outstanding_cheques (party_id, amount, cheque_date, bank_id, details, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$partyId, $amount, $date, $bankId, $details, 'Pending']);
            $chequeId = (int) $pdo->lastInsertId();

            sendJson([
                'ok' => true,
                'data' => [
                    'id' => 'manual-' . $chequeId,
                    'ref' => 'CHQ-' . str_pad($chequeId, 4, '0', STR_PAD_LEFT)
                ]
            ]);
            break;

        case 'receiveOutstandingCheque':
            $chequeKey = (string) ($input['id'] ?? '');
            if (strpos($chequeKey, 'manual-') !== 0) {
                sendJson(['ok' => false, 'message' => 'Only manually added cheques can be received here.'], 400);
            }

            $chequeId = (int) str_replace('manual-', '', $chequeKey);
            $bankId = !empty($input['bankId']) ? (int) $input['bankId'] : null;
            $date = $input['date'] ?? date('Y-m-d');

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT party_id, amount, status, details FROM outstanding_cheques WHERE id = ? FOR UPDATE');
                $stmt->execute([$chequeId]);
                $cheque = $stmt->fetch();
                if (!$cheque) {
                    throw new RuntimeException('Outstanding cheque not found.');
                }
                if ($cheque['status'] !== 'Pending') {
                    throw new RuntimeException('This cheque is already received.');
                }

                $updateStmt = $pdo->prepare('UPDATE outstanding_cheques SET status = ?, bank_id = COALESCE(?, bank_id) WHERE id = ?');
                $updateStmt->execute(['Received', $bankId, $chequeId]);

                $receiptStmt = $pdo->prepare('INSERT INTO payment_receipts (party_id, bank_id, amount, payment_type, receipt_date, details) VALUES (?, ?, ?, ?, ?, ?)');
                $receiptStmt->execute([
                    $cheque['party_id'],
                    $bankId,
                    $cheque['amount'],
                    'Cheque',
                    $date,
                    $cheque['details'] ?: ('Received cheque ' . $chequeKey)
                ]);

                $pdo->commit();
                sendJson(['ok' => true]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            break;

        case 'deleteOutstandingCheque':
            $chequeKey = (string) ($input['id'] ?? '');
            if (strpos($chequeKey, 'manual-') !== 0) {
                sendJson(['ok' => false, 'message' => 'Only manually added cheques can be deleted here.'], 400);
            }

            $chequeId = (int) str_replace('manual-', '', $chequeKey);
            $stmt = $pdo->prepare('DELETE FROM outstanding_cheques WHERE id = ? AND status = ?');
            $stmt->execute([$chequeId, 'Pending']);
            sendJson(['ok' => true]);
            break;

        default:
            sendJson(['ok' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    sendJson(['ok' => false, 'message' => $e->getMessage()], 500);
}
