<?php
/**
 * Fee engine: builds each student's fee schedule ("demands") from the fee structure,
 * applies concessions, works out late fees, and records receipts.
 *
 * A demand = one instalment of one fee head, e.g. "Tuition Fee - May 2026".
 * Balance of a demand = amount - concession - paid - waived.
 */

/** Fee frequencies are a master (Masters → Fee Frequencies): each one lists the months in which it is charged. */
function frequencies(bool $activeOnly = false): array
{
    static $all = null;
    if ($all === null) $all = rows('SELECT * FROM fee_frequencies ORDER BY sort_order, name');
    return $activeOnly ? array_values(array_filter($all, fn($f) => (int)$f['active'] === 1)) : $all;
}
function frequency(int $id): ?array { foreach (frequencies() as $f) if ((int)$f['id'] === $id) return $f; return null; }
function freq_months(array $f): array { return $f['is_one_time'] || !$f['months'] ? [] : array_map('intval', explode(',', $f['months'])); }
/** Instalments in a full year (1 for one-time). */
function freq_count(?array $f): int { return !$f ? 1 : max(1, count(freq_months($f))); }
const MONTH_NAMES = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
function freq_describe(array $f): string
{
    if ($f['is_one_time']) return 'Once, when the student joins';
    $m = freq_months($f);
    return count($m) === 12 ? 'Every month' : count($m) . ' × a year: ' . implode(', ', array_map(fn($x) => MONTH_NAMES[$x], $m));
}

const SYS_PREV_DUES = 'Previous Year Dues';
const SYS_BOUNCE = 'Cheque Bounce Charges';

function system_head_id(string $name): int
{
    $id = val('SELECT id FROM fee_heads WHERE name = ?', [$name]);
    if ($id) return (int)$id;
    return insert('fee_heads', ['name' => $name, 'frequency_id' => (int)val('SELECT id FROM fee_frequencies WHERE is_one_time = 1 ORDER BY sort_order LIMIT 1'), 'is_system' => 1, 'sort_order' => 999, 'late_fee_applies' => $name === SYS_PREV_DUES ? 1 : 0]);
}

function due_day(): int { return max(1, min(28, (int)setting('fee_due_day', '10'))); }

function month_due_date(int $y, int $m): string
{
    return sprintf('%04d-%02d-%02d', $y, $m, due_day());
}

/**
 * Instalments for a fee head in an academic year, for a student who joined on $joinDate.
 * $freq is a row of fee_frequencies. Each instalment covers the months from its own month up to the
 * next instalment's month. An instalment whose months are already over when the student joins is skipped;
 * the one running when they join is charged in full. One-time fees are charged once.
 */
function fee_periods(array $freq, string $yearStart, string $yearEnd, string $joinDate): array
{
    $start = new DateTime(date('Y-m-01', strtotime($yearStart)));
    $join = new DateTime(max($joinDate, $yearStart));
    $joinMonth = new DateTime($join->format('Y-m-01'));
    $sm = (int)$start->format('n');
    $months = freq_months($freq);
    if (!$months) {
        $due = month_due_date((int)$start->format('Y'), $sm);
        if ($join->format('Y-m-d') > $due) $due = $join->format('Y-m-d');
        return [['key' => 'ONCE', 'label' => 'One time', 'due' => $due]];
    }
    $offs = array_values(array_unique(array_map(fn($m) => ($m - $sm + 12) % 12, $months)));
    sort($offs);
    $n = count($offs);
    // keys stay compatible with earlier versions: monthly = YYYY-MM, 1 = Y, 2 = H1.., 4 = Q1.., others T1..
    $prefix = $n === 1 ? 'Y' : ($n === 2 ? 'H' : ($n === 4 ? 'Q' : 'T'));
    $out = [];
    foreach ($offs as $i => $off) {
        $pStart = (clone $start)->modify("+$off months");
        $nextOff = $offs[$i + 1] ?? 12;
        $pEnd = (clone $start)->modify("+$nextOff months")->modify('-1 day');
        if ($pEnd < $joinMonth) continue;                          // finished before the student joined
        if ($pStart->format('Y-m-d') > $yearEnd) break;
        $chargeMonth = $pStart < $joinMonth ? $joinMonth : $pStart;
        $due = month_due_date((int)$chargeMonth->format('Y'), (int)$chargeMonth->format('n'));
        if ($join->format('Y-m-d') > $due) $due = $join->format('Y-m-d');   // never due before the student joined
        if ($n === 12) { $key = $pStart->format('Y-m'); $label = $pStart->format('M Y'); }
        elseif ($n === 1) { $key = 'Y'; $label = 'Annual'; }
        else {
            $key = $prefix . ($i + 1);
            $span = $pStart->format('M') . ($nextOff - $off > 1 ? '–' . $pEnd->format('M') : '');
            $label = ($n === 4 ? 'Q' . ($i + 1) : ($n === 2 ? 'Half ' . ($i + 1) : 'Instalment ' . ($i + 1))) . ' (' . $span . ')';
        }
        $out[] = ['key' => $key, 'label' => $label, 'due' => $due];
    }
    return $out;
}

function enrollment_full(int $enrId): ?array
{
    return row('SELECT e.*, s.admission_no, s.first_name, s.last_name, s.father_name, s.father_phone, s.mother_phone, s.whatsapp,
                       s.is_rte, s.status AS student_status, c.name AS class_name, sec.name AS section_name,
                       y.name AS year_name, y.start_date, y.end_date, tr.name AS route_name, tr.amount AS route_amount
                FROM enrollments e
                JOIN students s ON s.id = e.student_id
                JOIN classes c ON c.id = e.class_id
                LEFT JOIN sections sec ON sec.id = e.section_id
                JOIN academic_years y ON y.id = e.academic_year_id
                LEFT JOIN transport_routes tr ON tr.id = e.transport_route_id
                WHERE e.id = ?', [$enrId]);
}

/** Concession amount for one instalment. */
function concession_for(array $concessions, int $headId, float $amount): float
{
    $c = 0.0;
    foreach ($concessions as $sc) {
        if ($sc['fee_head_id'] !== null && (int)$sc['fee_head_id'] !== $headId) continue;
        $c += $sc['kind'] === 'percent' ? $amount * (float)$sc['value'] / 100 : (float)$sc['value'];
    }
    return round(min($amount, max(0, $c)), 2);
}

/**
 * Create or refresh the fee schedule of one enrollment. Instalments that already have a
 * payment or waiver are never changed. Returns number of instalments created or updated.
 */
function generate_demands(int $enrId): int
{
    $e = enrollment_full($enrId);
    if (!$e || $e['status'] === 'left') return 0;
    $isRte = (int)$e['is_rte'] === 1 && setting('rte_full_waiver', '1') === '1';

    $heads = rows('SELECT fh.*, fs.amount FROM fee_structure fs JOIN fee_heads fh ON fh.id = fs.fee_head_id
                   WHERE fh.frequency_id IS NOT NULL AND fs.academic_year_id = ? AND fs.class_id = ? AND fh.active = 1 AND fh.is_system = 0
                     AND fh.is_optional = 0 AND fh.is_transport = 0 AND fs.amount > 0',
        [$e['academic_year_id'], $e['class_id']]);
    $heads = array_values(array_filter($heads, fn($h) => $h['applies_to'] === 'all'
        || ($h['applies_to'] === 'new' && (int)$e['is_new'] === 1) || ($h['applies_to'] === 'old' && (int)$e['is_new'] === 0)));
    if ($e['transport_route_id'] && (float)$e['route_amount'] > 0) {
        foreach (rows('SELECT * FROM fee_heads WHERE is_transport = 1 AND active = 1 ORDER BY sort_order LIMIT 1') as $th) {
            $th['amount'] = $e['route_amount'];
            $heads[] = $th;
        }
    }
    // optional heads the student has opted into (kept as demands with period_key starting "OPT")
    $concessions = rows('SELECT * FROM student_concessions WHERE enrollment_id = ?', [$enrId]);
    $existing = [];
    foreach (rows('SELECT * FROM fee_demands WHERE enrollment_id = ?', [$enrId]) as $d) {
        $existing[$d['fee_head_id'] . '|' . $d['period_key']] = $d;
    }
    $wanted = [];
    $n = 0;
    foreach ($heads as $hd) {
        $freq = frequency((int)$hd['frequency_id']);
        if (!$freq) continue;
        $periods = fee_periods($freq, $e['start_date'], $e['end_date'], $e['join_date']);
        // If the fee's frequency was changed after this student paid part of it, keep their old schedule
        // for this year so the paid months are not charged again. New students get the new frequency.
        $newKeys = array_column($periods, 'key');
        $clash = false;
        foreach ($existing as $d) {
            if ((int)$d['fee_head_id'] === (int)$hd['id'] && !preg_match('/^(X|OPT|PREV)/', $d['period_key']) && !in_array($d['period_key'], $newKeys, true)
                && ((float)$d['paid'] > 0 || (float)$d['waived'] > 0 || (float)$d['late_fee_paid'] > 0)) { $clash = true; break; }
        }
        if ($clash) {
            foreach ($existing as $k => $d) if ((int)$d['fee_head_id'] === (int)$hd['id']) $wanted[$k] = true;
            continue;
        }
        foreach ($periods as $p) {
            $k = $hd['id'] . '|' . $p['key'];
            $wanted[$k] = true;
            $amount = round((float)$hd['amount'], 2);
            $conc = $isRte ? $amount : concession_for($concessions, (int)$hd['id'], $amount);
            if (isset($existing[$k])) {
                $d = $existing[$k];
                if ((float)$d['paid'] == 0 && (float)$d['waived'] == 0 && (float)$d['late_fee_paid'] == 0
                    && ((float)$d['amount'] != $amount || (float)$d['concession'] != $conc || $d['due_date'] !== $p['due'])) {
                    update('fee_demands', ['amount' => $amount, 'concession' => $conc, 'due_date' => $p['due'], 'period_label' => $p['label']], 'id = ?', [$d['id']]);
                    $n++;
                }
                continue;
            }
            insert('fee_demands', ['enrollment_id' => $enrId, 'fee_head_id' => $hd['id'], 'period_key' => $p['key'],
                'period_label' => $p['label'], 'due_date' => $p['due'], 'amount' => $amount, 'concession' => $conc]);
            $n++;
        }
    }
    // remove untouched instalments that no longer apply (e.g. transport stopped, class changed)
    foreach ($existing as $k => $d) {
        if (isset($wanted[$k])) continue;
        if (preg_match('/^(X|OPT|PREV)/', $d['period_key'])) continue;  // manual, optional, carried forward
        if ((float)$d['paid'] == 0 && (float)$d['waived'] == 0 && (float)$d['late_fee_paid'] == 0) {
            q('DELETE FROM fee_demands WHERE id = ?', [$d['id']]);
            $n++;
        }
    }
    return $n;
}

/** Stop future instalments when a student leaves: unpaid instalments due after the leaving date are removed. */
function close_demands_after(int $enrId, string $leavingDate): int
{
    return q("DELETE FROM fee_demands WHERE enrollment_id = ? AND due_date > ? AND paid = 0 AND waived = 0 AND late_fee_paid = 0
              AND period_key NOT LIKE 'PREV%'", [$enrId, $leavingDate])->rowCount();
}

function demand_balance(array $d): float
{
    return round((float)$d['amount'] - (float)$d['concession'] - (float)$d['paid'] - (float)$d['waived'], 2);
}

function late_rule(): array
{
    return ['scope' => setting('late_fee_scope', 'per_date')];   // per_date = once per due date; per_item = each fee item
}

/** Late fee rules master (Masters → Late Fee Rules), with their slabs. */
function late_rules(): array
{
    static $rules = null;
    if ($rules === null) {
        $rules = [];
        foreach (rows('SELECT * FROM late_fee_rules') as $r) { $r['slabs'] = []; $rules[(int)$r['id']] = $r; }
        foreach (rows('SELECT * FROM late_fee_slabs ORDER BY from_day') as $sl) if (isset($rules[(int)$sl['rule_id']])) $rules[(int)$sl['rule_id']]['slabs'][] = $sl;
    }
    return $rules;
}

/** Which rule applies to an instalment: the fee head's own rule, else the default rule. 0 = no late fee. */
function rule_id_for(array $d): int
{
    if (isset($d['late_fee_applies']) && (int)$d['late_fee_applies'] === 0) return 0;
    $rid = (int)($d['late_fee_rule_id'] ?? 0) ?: (int)setting('late_fee_rule_id', '0');
    $r = late_rules()[$rid] ?? null;
    return $r && (int)$r['active'] === 1 ? $rid : 0;
}

/**
 * Late fee earned by something due on $dueDate, as of $asOf, under a rule.
 * Days late are counted after the grace days. The slab that contains that number of days decides the fee:
 * "once" = that amount, "per_day" = amount × days late, "per_month" = amount × months late (30 days, part month counts).
 */
function late_fee_gross(string $dueDate, string $asOf, $ruleId): float
{
    $r = is_array($ruleId) ? null : (late_rules()[(int)$ruleId] ?? null);
    if (!$r || !$r['slabs']) return 0.0;
    $lateFrom = date('Y-m-d', strtotime($dueDate . ' +' . (int)$r['grace_days'] . ' days'));
    if ($asOf <= $lateFrom) return 0.0;
    $days = (int)round((strtotime($asOf) - strtotime($lateFrom)) / 86400);
    $fee = 0.0;
    foreach ($r['slabs'] as $sl) {
        if ($days < (int)$sl['from_day'] || ($sl['to_day'] !== null && $days > (int)$sl['to_day'])) continue;
        $amt = (float)$sl['amount'];
        $fee = $sl['calc'] === 'per_day' ? $amt * $days : ($sl['calc'] === 'per_month' ? $amt * (int)ceil($days / 30) : $amt);
        break;
    }
    if ((float)$r['max_amount'] > 0) $fee = min($fee, (float)$r['max_amount']);
    return round($fee, 2);
}

function late_applies(array $d): bool
{
    return rule_id_for($d) > 0 && (int)$d['carried_forward'] === 0;
}

/**
 * Late fee still payable, per instalment id, for a list of instalments of ONE enrollment.
 * With scope "per_date" the late fee is charged once for all fees due on the same date
 * (e.g. April tuition + transport = one late fee) and is shown on the first unpaid item of that date.
 * Pass every instalment of the enrollment (paid ones too) so earlier late fee payments are counted.
 */
function late_fees_map(array $demands, string $asOf, ?array $rule = null): array
{
    $rule = $rule ?? late_rule();
    $out = [];
    if ($rule['scope'] === 'per_item') {
        foreach ($demands as $d) {
            if (!late_applies($d) || demand_balance($d) <= 0) continue;
            $f = round(max(0, late_fee_gross($d['due_date'], $asOf, rule_id_for($d)) - (float)$d['late_fee_paid'] - (float)$d['late_fee_waived']), 2);
            if ($f > 0) $out[$d['id']] = $f;
        }
        return $out;
    }
    $groups = [];
    // fees due on the same date under the same rule share one late fee
    foreach ($demands as $d) if (late_applies($d)) $groups[$d['due_date'] . '|' . rule_id_for($d)][] = $d;
    foreach ($groups as $gk => $list) {
        [$due, $rid] = explode('|', $gk);
        $settled = 0.0; $carrier = null;
        foreach ($list as $d) {
            $settled += (float)$d['late_fee_paid'] + (float)$d['late_fee_waived'];
            if ($carrier === null && demand_balance($d) > 0) $carrier = $d;
        }
        if ($carrier === null) continue;
        $f = round(max(0, late_fee_gross($due, $asOf, (int)$rid) - $settled), 2);
        if ($f > 0) $out[$carrier['id']] = $f;
    }
    return $out;
}

/** Late fee for a single instalment, looking at the other instalments of the same enrollment. */
function late_fee_for(array $d, string $asOf, ?array $rule = null): float
{
    $all = rows('SELECT d.*, fh.late_fee_applies, fh.late_fee_rule_id FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id WHERE d.enrollment_id = ?', [$d['enrollment_id']]);
    return late_fees_map($all, $asOf, $rule)[$d['id']] ?? 0.0;
}

/** All open instalments of an enrollment with balance and late fee worked out. */
function open_demands(int $enrId, ?string $asOf = null): array
{
    $asOf = $asOf ?? today();
    $rule = late_rule();
    $list = rows('SELECT d.*, fh.name AS head_name, fh.late_fee_applies, fh.late_fee_rule_id, fh.sort_order
                  FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id
                  WHERE d.enrollment_id = ? AND d.carried_forward = 0
                  ORDER BY d.due_date, fh.sort_order, d.id', [$enrId]);
    $late = late_fees_map($list, $asOf, $rule);
    $out = [];
    foreach ($list as $d) {
        $d['balance'] = demand_balance($d);
        if ($d['balance'] <= 0) continue;
        $d['late_fee'] = $late[$d['id']] ?? 0.0;
        $d['is_due'] = $d['due_date'] <= $asOf;
        $out[] = $d;
    }
    return $out;
}

/** Summary numbers for one enrollment. */
function fee_summary(int $enrId, ?string $asOf = null): array
{
    $asOf = $asOf ?? today();
    $all = rows('SELECT d.*, fh.late_fee_applies, fh.late_fee_rule_id FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id
                 WHERE d.enrollment_id = ? AND d.carried_forward = 0 ORDER BY d.due_date, fh.sort_order, d.id', [$enrId]);
    $s = ['total' => 0, 'concession' => 0, 'waived' => 0, 'paid' => 0, 'balance' => 0, 'due_now' => 0, 'late_fee' => 0, 'late_paid' => 0];
    $s['late_fee'] = array_sum(late_fees_map($all, $asOf));
    foreach ($all as $d) {
        $s['total'] += (float)$d['amount'];
        $s['concession'] += (float)$d['concession'];
        $s['waived'] += (float)$d['waived'];
        $s['paid'] += (float)$d['paid'];
        $s['late_paid'] += (float)$d['late_fee_paid'];
        $b = demand_balance($d);
        $s['balance'] += $b;
        if ($d['due_date'] <= $asOf) $s['due_now'] += max(0, $b);
    }
    foreach ($s as $k => $v) $s[$k] = round($v, 2);
    $s['payable_now'] = round($s['due_now'] + $s['late_fee'], 2);
    return $s;
}

function next_number(string $counter): int
{
    q('INSERT IGNORE INTO counters (name, value) VALUES (?, 0)', [$counter]);
    $v = (int)val('SELECT value FROM counters WHERE name = ? FOR UPDATE', [$counter]) + 1;
    q('UPDATE counters SET value = ? WHERE name = ?', [$v, $counter]);
    return $v;
}

function make_receipt_no(array $year): string
{
    $n = next_number('receipt_' . $year['id']);
    $prefix = setting('receipt_prefix', 'RCP');
    return $prefix . '/' . $year['name'] . '/' . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}

/**
 * Record a payment.
 * $lines: [demand_id => ['pay' => amount, 'late' => late fee charged]]
 * Late fee charged below the calculated amount is recorded as waived (needs permission).
 */
function record_receipt(int $enrId, array $lines, array $meta): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $e = enrollment_full($enrId);
        if (!$e) throw new RuntimeException('Student enrollment not found.');
        $year = row('SELECT * FROM academic_years WHERE id = ?', [$e['academic_year_id']]);
        $date = $meta['date'];
        $rule = late_rule();
        $allDem = rows('SELECT d.*, fh.late_fee_applies, fh.late_fee_rule_id FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id
                        WHERE d.enrollment_id = ? ORDER BY d.due_date, fh.sort_order, d.id FOR UPDATE', [$enrId]);
        $lateMap = late_fees_map($allDem, $date, $rule);
        $items = [];
        $feeTotal = 0; $lateTotal = 0; $lateWaived = 0;
        foreach ($lines as $did => $ln) {
            $pay = round((float)($ln['pay'] ?? 0), 2);
            $late = round((float)($ln['late'] ?? 0), 2);
            if ($pay <= 0 && $late <= 0) continue;
            $d = row('SELECT d.*, fh.name AS head_name, fh.late_fee_applies, fh.late_fee_rule_id FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id
                      WHERE d.id = ? AND d.enrollment_id = ? FOR UPDATE', [(int)$did, $enrId]);
            if (!$d) throw new RuntimeException('Fee item not found.');
            if ((int)$d['carried_forward'] === 1) throw new RuntimeException($d['head_name'] . ' ' . $d['period_label'] . ' was carried forward to the next year. Collect it there as Previous Year Dues.');
            $bal = demand_balance($d);
            if ($pay > $bal + 0.001) throw new RuntimeException('Amount for ' . $d['head_name'] . ' ' . $d['period_label'] . ' is more than its balance (' . money($bal) . ').');
            if ($pay < 0 || $late < 0) throw new RuntimeException('Amounts cannot be negative.');
            $calcLate = $lateMap[$d['id']] ?? 0.0;
            if ($late > $calcLate + 0.001) throw new RuntimeException('Late fee for ' . $d['period_label'] . ' cannot be more than ' . money($calcLate) . '.');
            $waive = 0.0;
            // Late fee is settled when the instalment is paid off; anything not charged is waived.
            if ($calcLate > 0 && $pay >= $bal - 0.001) {
                $waive = round($calcLate - $late, 2);
                if ($waive > 0 && !can('fees.waive_late')) throw new RuntimeException('You are not allowed to reduce late fees.');
            }
            update('fee_demands', [
                'paid' => round((float)$d['paid'] + $pay, 2),
                'late_fee_paid' => round((float)$d['late_fee_paid'] + $late, 2),
                'late_fee_waived' => round((float)$d['late_fee_waived'] + $waive, 2),
            ], 'id = ?', [$d['id']]);
            if ($pay > 0) $items[] = ['fee_demand_id' => $d['id'], 'fee_head_id' => $d['fee_head_id'], 'description' => $d['head_name'] . ' – ' . $d['period_label'], 'item_type' => 'fee', 'amount' => $pay, 'late_fee_waived' => 0];
            if ($late > 0 || $waive > 0) $items[] = ['fee_demand_id' => $d['id'], 'fee_head_id' => $d['fee_head_id'], 'description' => 'Late fee – ' . $d['head_name'] . ' ' . $d['period_label'], 'item_type' => 'late_fee', 'amount' => $late, 'late_fee_waived' => $waive];
            $feeTotal += $pay; $lateTotal += $late; $lateWaived += $waive;
        }
        if ($feeTotal + $lateTotal <= 0) throw new RuntimeException('Enter an amount to collect.');
        $no = make_receipt_no($year);
        $rid = insert('receipts', [
            'receipt_no' => $no, 'student_id' => $e['student_id'], 'enrollment_id' => $enrId, 'academic_year_id' => $e['academic_year_id'],
            'receipt_date' => $date, 'fee_amount' => round($feeTotal, 2), 'late_fee' => round($lateTotal, 2), 'late_fee_waived' => round($lateWaived, 2),
            'total_amount' => round($feeTotal + $lateTotal, 2), 'mode' => $meta['mode'], 'reference' => nullif($meta['reference'] ?? ''),
            'bank_name' => nullif($meta['bank_name'] ?? ''), 'paid_by' => nullif($meta['paid_by'] ?? ''), 'remarks' => nullif($meta['remarks'] ?? ''),
            'created_by' => $_SESSION['uid'],
        ]);
        foreach ($items as $it) insert('receipt_items', $it + ['receipt_id' => $rid]);
        audit('receipt.create', 'receipt', $rid, ['no' => $no, 'total' => $feeTotal + $lateTotal, 'mode' => $meta['mode']]);
        $pdo->commit();
        return $rid;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Cancel a receipt and put the amounts back as unpaid. */
function cancel_receipt(int $rid, string $reason): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = row('SELECT * FROM receipts WHERE id = ? FOR UPDATE', [$rid]);
        if (!$r) throw new RuntimeException('Receipt not found.');
        if ((int)$r['cancelled'] === 1) throw new RuntimeException('Receipt is already cancelled.');
        $items = rows('SELECT ri.*, d.carried_forward FROM receipt_items ri LEFT JOIN fee_demands d ON d.id = ri.fee_demand_id WHERE ri.receipt_id = ?', [$rid]);
        foreach ($items as $it) {
            if ((int)$it['carried_forward'] === 1) throw new RuntimeException('This receipt is from a year whose dues are already carried forward. Instead of cancelling, add a correcting charge in the new year.');
        }
        foreach ($items as $it) {
            if (!$it['fee_demand_id']) continue;
            if ($it['item_type'] === 'fee') {
                q('UPDATE fee_demands SET paid = GREATEST(0, paid - ?) WHERE id = ?', [$it['amount'], $it['fee_demand_id']]);
            } else {
                q('UPDATE fee_demands SET late_fee_paid = GREATEST(0, late_fee_paid - ?), late_fee_waived = GREATEST(0, late_fee_waived - ?) WHERE id = ?',
                    [$it['amount'], $it['late_fee_waived'], $it['fee_demand_id']]);
            }
        }
        update('receipts', ['cancelled' => 1, 'cancelled_by' => $_SESSION['uid'], 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $reason], 'id = ?', [$rid]);
        audit('receipt.cancel', 'receipt', $rid, ['no' => $r['receipt_no'], 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Add a one-off charge to a student (fine, books, bounce charge...). */
function add_charge(int $enrId, int $headId, string $label, float $amount, string $due, string $notes = ''): int
{
    $key = 'X' . date('ymdHis') . random_int(10, 99);
    return insert('fee_demands', ['enrollment_id' => $enrId, 'fee_head_id' => $headId, 'period_key' => $key,
        'period_label' => $label, 'due_date' => $due, 'amount' => round($amount, 2), 'notes' => nullif($notes)]);
}

/**
 * Promote / move a student into a new academic year. Unpaid balance (and optionally unpaid late fee)
 * of the old year becomes a "Previous Year Dues" item in the new year.
 */
function promote_enrollment(int $oldEnrId, int $newYearId, int $classId, ?int $sectionId, bool $carryLate, string $status = 'promoted'): int
{
    $old = enrollment_full($oldEnrId);
    if (!$old) throw new RuntimeException('Enrollment not found.');
    if (val('SELECT id FROM enrollments WHERE student_id = ? AND academic_year_id = ?', [$old['student_id'], $newYearId])) {
        throw new RuntimeException(student_name($old) . ' is already enrolled in that year.');
    }
    $year = row('SELECT * FROM academic_years WHERE id = ?', [$newYearId]);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sum = fee_summary($oldEnrId, $old['end_date'] > today() ? today() : $old['end_date']);
        $carry = $sum['balance'] + ($carryLate ? $sum['late_fee'] : 0);
        $newId = insert('enrollments', ['student_id' => $old['student_id'], 'academic_year_id' => $newYearId, 'class_id' => $classId,
            'section_id' => $sectionId, 'is_new' => 0, 'transport_route_id' => $old['transport_route_id'], 'join_date' => $year['start_date']]);
        update('enrollments', ['status' => $status], 'id = ?', [$oldEnrId]);
        // concessions continue into the new year (they can be removed from the student's profile)
        q('INSERT INTO student_concessions (enrollment_id, concession_type_id, kind, value, fee_head_id, reason, approved_by)
           SELECT ?, concession_type_id, kind, value, fee_head_id, reason, approved_by FROM student_concessions WHERE enrollment_id = ?', [$newId, $oldEnrId]);
        if ($carry > 0) {
            q('UPDATE fee_demands SET carried_forward = 1 WHERE enrollment_id = ? AND (amount - concession - paid - waived) > 0', [$oldEnrId]);
            insert('fee_demands', ['enrollment_id' => $newId, 'fee_head_id' => system_head_id(SYS_PREV_DUES), 'period_key' => 'PREV',
                'period_label' => 'From ' . $old['year_name'], 'due_date' => month_due_date((int)date('Y', strtotime($year['start_date'])), (int)date('n', strtotime($year['start_date']))),
                'amount' => round($carry, 2), 'notes' => 'Carried forward from ' . $old['year_name']]);
        }
        generate_demands($newId);
        $pdo->commit();
        audit('student.promote', 'enrollment', $newId, ['from' => $oldEnrId, 'carry' => $carry]);
        return $newId;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Dues per enrollment for a year (used by dues list, dashboard, reports). */
function dues_rows(int $yearId, string $asOf, array $filters = []): array
{
    $where = ['e.academic_year_id = ?', "e.status <> 'left'" . scope_sql('e')];
    $p = [$yearId];
    if (!empty($filters['class_id'])) { $where[] = 'e.class_id = ?'; $p[] = $filters['class_id']; }
    if (!empty($filters['section_id'])) { $where[] = 'e.section_id = ?'; $p[] = $filters['section_id']; }
    $rule = late_rule();
    $list = rows('SELECT e.id AS enrollment_id, e.student_id, s.admission_no, s.first_name, s.last_name, s.father_name, s.father_phone, s.whatsapp,
                         c.name AS class_name, c.sort_order, sec.name AS section_name, e.roll_no
                  FROM enrollments e JOIN students s ON s.id = e.student_id JOIN classes c ON c.id = e.class_id
                  LEFT JOIN sections sec ON sec.id = e.section_id
                  WHERE ' . implode(' AND ', $where) . ' ORDER BY c.sort_order, sec.name, s.first_name', $p);
    if (!$list) return [];
    $ids = array_column($list, 'enrollment_id');
    $in = implode(',', array_map('intval', $ids));
    $agg = [];
    $byEnr = [];
    $allD = rows("SELECT d.*, fh.late_fee_applies, fh.late_fee_rule_id FROM fee_demands d JOIN fee_heads fh ON fh.id = d.fee_head_id
                   WHERE d.enrollment_id IN ($in) AND d.carried_forward = 0 ORDER BY d.enrollment_id, d.due_date, fh.sort_order, d.id");
    foreach ($allD as $d) $byEnr[$d['enrollment_id']][] = $d;
    $lateAll = [];
    foreach ($byEnr as $eid => $dl) $lateAll[$eid] = array_sum(late_fees_map($dl, $asOf, $rule));
    foreach ($allD as $d) {
        $id = $d['enrollment_id'];
        $agg[$id] = $agg[$id] ?? ['total' => 0, 'paid' => 0, 'balance' => 0, 'due_now' => 0, 'late_fee' => 0, 'oldest_due' => null, 'concession' => 0];
        $b = demand_balance($d);
        $agg[$id]['total'] += (float)$d['amount'] - (float)$d['concession'] - (float)$d['waived'];
        $agg[$id]['concession'] += (float)$d['concession'];
        $agg[$id]['paid'] += (float)$d['paid'];
        $agg[$id]['balance'] += $b;
        if ($d['due_date'] <= $asOf && $b > 0) {
            $agg[$id]['due_now'] += $b;
            if (!$agg[$id]['oldest_due'] || $d['due_date'] < $agg[$id]['oldest_due']) $agg[$id]['oldest_due'] = $d['due_date'];
        }
    }
    foreach ($lateAll as $eid => $lf) if (isset($agg[$eid])) $agg[$eid]['late_fee'] = $lf;
    foreach ($list as &$r) {
        $a = $agg[$r['enrollment_id']] ?? ['total' => 0, 'paid' => 0, 'balance' => 0, 'due_now' => 0, 'late_fee' => 0, 'oldest_due' => null, 'concession' => 0];
        $r = array_merge($r, array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $a));
        $r['payable_now'] = round($r['due_now'] + $r['late_fee'], 2);
    }
    unset($r);
    return $list;
}
