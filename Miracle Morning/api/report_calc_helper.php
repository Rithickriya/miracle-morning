<?php
// ═══════════════════════════════════════════════════════════════════
// report_calc_helper.php — Recalculates member weekly payments by
// replaying payment sessions chronologically with carry-over.
//
// This is a DISPLAY-ONLY helper. It does NOT modify the database.
// Used by: member_report.php, member_report_pdf.php,
//          member_report_batch.php, tab_members.php
// ═══════════════════════════════════════════════════════════════════

/**
 * Recalculate a member's weekly payment data by replaying sessions.
 *
 * Instead of reading raw per-Sunday partial_paid / partial_balance
 * (which don't reflect cross-session carry-over), this function:
 *   1. Fetches payment sessions (grouped by submitted_at)
 *   2. Sorts them chronologically
 *   3. Distributes each session's total across Sundays (earliest unpaid first)
 *
 * @param PDO $pdo       Database connection
 * @param int $member_id Member ID
 * @return array {
 *   'Sundays'       => [ 'Y-m-d' => [amount_paid, balance, status, payment_method, paid_date], ... ],
 *   'sessions'      => [ session rows from DB ],
 *   'totalPaid'     => int,
 *   'totalCollected'=> int (same as totalPaid, for compatibility),
 *   'fullWeeks'     => int,
 *   'partialWeeks'  => int,
 *   'byMonth'       => [ 'F Y' => [ Sunday rows ], ... ]  (for report rendering)
 * }
 */
function recalc_member_payments(PDO $pdo, int $member_id): array {
    $week_fee = 1450;

    // ── 1. Get payment sessions ordered chronologically ──────────────
    $sq = $pdo->prepare("
        SELECT 
            DATE_FORMAT(t.submitted_at, '%Y-%m-%d %H:%i:%s') AS session_id,
            DATE(t.submitted_at) AS paid_date,
            GROUP_CONCAT(t.friday_date ORDER BY t.friday_date ASC SEPARATOR ',') AS Sundays,
            COUNT(*) AS week_count,
            MIN(t.payment_method) AS payment_method,
            MAX(t.status) AS status,
            COALESCE(MAX(t.original_total), SUM(t.amount)) AS total_amount
        FROM transactions t
        WHERE t.member_id = ? AND t.type = 'Member'
        GROUP BY session_id
        ORDER BY t.submitted_at ASC
    ");
    $sq->execute([$member_id]);
    $sessions = $sq->fetchAll(PDO::FETCH_ASSOC);

    if (!$sessions) {
        return [
            'Sundays'        => [],
            'sessions'       => [],
            'totalPaid'      => 0,
            'totalCollected' => 0,
            'fullWeeks'      => 0,
            'partialWeeks'   => 0,
            'byMonth'        => [],
        ];
    }

    // ── 2. Collect all unique Sundays across all sessions ─────────────
    $Sundayset = [];
    foreach ($sessions as $s) {
        foreach (explode(',', $s['Sundays']) as $fd) {
            $fd = trim($fd);
            if ($fd) $Sundayset[$fd] = true;
        }
    }
    ksort($Sundayset);
    $rawSundayList = array_keys($Sundayset);
    $SundayList = [];
    if ($rawSundayList) {
        $cur = new DateTime(reset($rawSundayList));
        $end = new DateTime(end($rawSundayList));
        while ($cur <= $end) {
            $SundayList[] = $cur->format('Y-m-d');
            $cur->modify('+7 days');
        }
    }

    // ── 3. Initialize each Sunday ────────────────────────────────────
    $SundayData = [];
    foreach ($SundayList as $fd) {
        $SundayData[$fd] = [
            'friday_date'    => $fd,
            'amount_paid'    => 0,
            'balance'        => $week_fee,
            'is_partial'     => 0,
            'status'         => 'Unpaid',
            'payment_method' => '—',
            'paid_date'      => '',
        ];
    }

    // ── 4. Replay each session chronologically ───────────────────────
    foreach ($sessions as &$s) {
        $s['applied_Sundays'] = [];
        $s['applied_week_count'] = 0;
        $s['applied_Sundays_label'] = '';

        // Only process Paid sessions (skip Pending/Rejected)
        if ($s['status'] !== 'Paid') {
            // Still mark the Sundays as Pending if they exist
            foreach (explode(',', $s['Sundays']) as $fd) {
                $fd = trim($fd);
                if ($fd && isset($SundayData[$fd]) && $SundayData[$fd]['status'] === 'Unpaid') {
                    $SundayData[$fd]['status'] = $s['status'];
                    $SundayData[$fd]['payment_method'] = $s['payment_method'];
                    $SundayData[$fd]['paid_date'] = $s['paid_date'];
                }
            }
            continue;
        }

        $remaining = (int)$s['total_amount'];
        $method    = $s['payment_method'];
        $paidDate  = $s['paid_date'];

        // Distribute across ALL Sundays, earliest first
        // This naturally fills previous partial balances before moving on
        foreach ($SundayList as $fd) {
            if ($remaining <= 0) break;
            if ($SundayData[$fd]['balance'] <= 0) continue; // already fully paid

            $apply = min($remaining, $SundayData[$fd]['balance']);
            if ($apply > 0) {
                $s['applied_Sundays'][] = [
                    'friday_date' => $fd,
                    'amount' => $apply,
                    'is_partial_apply' => ($apply < $week_fee),
                ];
            }
            $SundayData[$fd]['amount_paid'] += $apply;
            $SundayData[$fd]['balance']     -= $apply;
            $SundayData[$fd]['payment_method'] = $method;
            $SundayData[$fd]['paid_date']      = $paidDate;

            if ($SundayData[$fd]['balance'] <= 0) {
                $SundayData[$fd]['is_partial'] = 0;
                $SundayData[$fd]['status']     = 'Paid';
            } else {
                $SundayData[$fd]['is_partial'] = 1;
                $SundayData[$fd]['status']     = 'Paid';
            }

            $remaining -= $apply;
        }

        // If money remains after filling all known Sundays, create future ones
        if ($remaining > 0) {
            $lastFd = end($SundayList) ?: date('Y-m-d');
            $nextFd = new DateTime($lastFd);
            $nextFd->modify('+7 days');
            while ($remaining > 0) {
                $newFd = $nextFd->format('Y-m-d');
                $apply = min($remaining, $week_fee);
                $SundayData[$newFd] = [
                    'friday_date'    => $newFd,
                    'amount_paid'    => $apply,
                    'balance'        => $week_fee - $apply,
                    'is_partial'     => ($apply < $week_fee) ? 1 : 0,
                    'status'         => 'Paid',
                    'payment_method' => $method,
                    'paid_date'      => $paidDate,
                ];
                $s['applied_Sundays'][] = [
                    'friday_date' => $newFd,
                    'amount' => $apply,
                    'is_partial_apply' => ($apply < $week_fee),
                ];
                $SundayList[] = $newFd;
                $remaining -= $apply;
                $nextFd->modify('+7 days');
            }
        }

        $s['applied_week_count'] = count($s['applied_Sundays']);
        $labels = [];
        foreach ($s['applied_Sundays'] as $af) {
            $label = date('d M', strtotime($af['friday_date']));
            if ((int)$af['amount'] !== $week_fee) {
                $label .= ' (₹' . number_format((int)$af['amount']) . ')';
            }
            $labels[] = $label;
        }
        $s['applied_Sundays_label'] = implode(', ', $labels);
    }
    unset($s);

    // ── 5. Calculate totals ──────────────────────────────────────────
    $totalPaid     = 0;
    $fullWeeks     = 0;
    $partialWeeks  = 0;
    foreach ($SundayData as &$data) {
        $totalPaid += $data['amount_paid'];
        if ($data['amount_paid'] > 0 && $data['balance'] <= 0) {
            $fullWeeks++;
        } elseif ($data['amount_paid'] > 0 && $data['balance'] > 0) {
            $partialWeeks++;
        }
    }
    unset($data);

    // ── 6. Group by month for report display ─────────────────────────
    $byMonth = [];
    foreach ($SundayData as $fd => $data) {
        if ($data['status'] === 'Unpaid') continue; // skip Sundays with no activity
        $mk = date('F Y', strtotime($fd));
        $byMonth[$mk][] = $data;
    }

    return [
        'Sundays'        => $SundayData,
        'sessions'       => $sessions,
        'totalPaid'      => $totalPaid,
        'totalCollected' => $totalPaid,
        'fullWeeks'      => $fullWeeks,
        'partialWeeks'   => $partialWeeks,
        'byMonth'        => $byMonth,
    ];
}

/**
 * Lightweight version for tab_members.php — uses pre-loaded transaction
 * arrays instead of running a new query per member.
 *
 * @param array $txns  Array of transaction rows for this member
 *                     (must include: friday_date, amount, payment_method,
 *                      status, is_partial, partial_paid, partial_balance,
 *                      submitted_at, and optionally original_total)
 * @return array { totalPaid, fullWeeks, partialWeeks }
 */
function recalc_from_txns(array $txns): array {
    $week_fee = 1450;
    if (!$txns) return ['totalPaid' => 0, 'fullWeeks' => 0, 'partialWeeks' => 0, 'SundayBal' => []];

    // Group into sessions by submitted_at timestamp
    $sessions = [];
    foreach ($txns as $tx) {
        $sk = $tx['submitted_at'];
        if (!isset($sessions[$sk])) {
            $sessions[$sk] = [
                'Sundays'   => [],
                'method'    => $tx['payment_method'],
                'status'    => $tx['status'],
                'raw_txns'  => [],
            ];
        }
        $sessions[$sk]['Sundays'][] = $tx['friday_date'];
        $sessions[$sk]['raw_txns'][] = $tx;
        // MAX-like for status: if any is Paid, session is Paid
        if ($tx['status'] === 'Paid') $sessions[$sk]['status'] = 'Paid';
    }

    // Calculate session totals using original_total if available
    foreach ($sessions as $sk => &$s) {
        $origTotal = 0;
        foreach ($s['raw_txns'] as $rtx) {
            if (!empty($rtx['original_total']) && (int)$rtx['original_total'] > $origTotal) {
                $origTotal = (int)$rtx['original_total'];
            }
        }
        if ($origTotal > 0) {
            $s['total_amount'] = $origTotal;
        } else {
            $s['total_amount'] = array_sum(array_map(function($tx) {
                return (int)$tx['amount'];
            }, $s['raw_txns']));
        }
    }
    unset($s);

    ksort($sessions); // chronological

    // Collect all Sundays
    $Sundayset = [];
    foreach ($sessions as $s) {
        foreach ($s['Sundays'] as $fd) $Sundayset[$fd] = true;
    }
    ksort($Sundayset);
    $rawSundayList = array_keys($Sundayset);
    $SundayList = [];
    if ($rawSundayList) {
        $cur = new DateTime(reset($rawSundayList));
        $end = new DateTime(end($rawSundayList));
        while ($cur <= $end) {
            $SundayList[] = $cur->format('Y-m-d');
            $cur->modify('+7 days');
        }
    }

    // Initialize
    $SundayBal = [];
    foreach ($SundayList as $fd) {
        $SundayBal[$fd] = ['paid' => 0, 'balance' => $week_fee];
    }

    // Replay
    foreach ($sessions as $s) {
        if ($s['status'] !== 'Paid') continue;
        $remaining = $s['total_amount'];
        foreach ($SundayList as $fd) {
            if ($remaining <= 0) break;
            if ($SundayBal[$fd]['balance'] <= 0) continue;
            $apply = min($remaining, $SundayBal[$fd]['balance']);
            $SundayBal[$fd]['paid']    += $apply;
            $SundayBal[$fd]['balance'] -= $apply;
            $remaining -= $apply;
        }
        // Create future Sundays for remaining money
        if ($remaining > 0) {
            $lastFd = end($SundayList) ?: date('Y-m-d');
            $nextFd = new DateTime($lastFd);
            $nextFd->modify('+7 days');
            while ($remaining > 0) {
                $newFd = $nextFd->format('Y-m-d');
                $apply = min($remaining, $week_fee);
                $SundayBal[$newFd] = ['paid' => $apply, 'balance' => $week_fee - $apply];
                $SundayList[] = $newFd;
                $remaining -= $apply;
                $nextFd->modify('+7 days');
            }
        }
    }

    // Totals
    $totalPaid = 0; $fullWeeks = 0; $partialWeeks = 0;
    foreach ($SundayBal as $fb) {
        $totalPaid += $fb['paid'];
        if ($fb['paid'] > 0 && $fb['balance'] <= 0) $fullWeeks++;
        elseif ($fb['paid'] > 0 && $fb['balance'] > 0) $partialWeeks++;
    }

    return ['totalPaid' => $totalPaid, 'fullWeeks' => $fullWeeks, 'partialWeeks' => $partialWeeks, 'SundayBal' => $SundayBal];
}
