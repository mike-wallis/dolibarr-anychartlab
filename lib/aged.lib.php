<?php
/**
 * Any-Chart Reports Lab — ledger-based Accounts Receivable / Accounts Payable.
 *
 * Built from the accounting ledger (llx_accounting_bookkeeping), not from invoices:
 * entries on the customer / supplier control accounts (Accounting setup:
 * ACCOUNTING_ACCOUNT_CUSTOMER / ACCOUNTING_ACCOUNT_SUPPLIER), per third party
 * (subledger account). Open items come from Dolibarr lettering when entries are
 * lettered; otherwise payments and credit notes are matched to the oldest open
 * invoices of the same third party (FIFO). The report total therefore always equals
 * the control account balance, and it works with any chart of accounts.
 */

/**
 * Default control account(s) for a report type, from Accounting setup.
 *
 * @param string $type ar or ap
 * @return string[]
 */
function anychartlab_aged_default_accounts($type)
{
	$acc = getDolGlobalString($type === 'ap' ? 'ACCOUNTING_ACCOUNT_SUPPLIER' : 'ACCOUNTING_ACCOUNT_CUSTOMER');
	return ($acc !== '' ? array($acc) : array());
}

/**
 * Ageing buckets.
 *
 * @param string $basis due (days past due date) or doc (days since document date)
 * @return array<string,string> key => label
 */
function anychartlab_aged_buckets($basis)
{
	if ($basis === 'doc') {
		return array('b0' => '0-30 days', 'b1' => '31-60', 'b2' => '61-90', 'b3' => '91-120', 'b4' => 'Over 120');
	}
	return array('b0' => 'Not yet due', 'b1' => '1-30 days overdue', 'b2' => '31-60', 'b3' => '61-90', 'b4' => 'Over 90');
}

/**
 * Bucket key for an item.
 *
 * @param int    $days  Days past the basis date (negative = not yet due)
 * @param string $basis due or doc
 * @return string
 */
function anychartlab_aged_bucket($days, $basis)
{
	if ($basis === 'doc') {
		return ($days <= 30 ? 'b0' : ($days <= 60 ? 'b1' : ($days <= 90 ? 'b2' : ($days <= 120 ? 'b3' : 'b4'))));
	}
	return ($days <= 0 ? 'b0' : ($days <= 30 ? 'b1' : ($days <= 60 ? 'b2' : ($days <= 90 ? 'b3' : 'b4'))));
}

/**
 * Build the aged report.
 *
 * @param DoliDB   $db
 * @param int      $entity
 * @param string   $type     ar (receivables: amount = debit - credit) or ap (payables: credit - debit)
 * @param string[] $accounts Control account numbers
 * @param int      $asof
 * @param string   $basis    due or doc
 * @return array{parties:array,totals:array,total:float,nothirdparty:float,lettered:int,entries:int}
 */
function anychartlab_aged_build($db, $entity, $type, $accounts, $asof, $basis)
{
	$res = array('parties' => array(), 'totals' => array(), 'total' => 0.0, 'nothirdparty' => 0.0, 'lettered' => 0, 'entries' => 0);
	foreach (array_keys(anychartlab_aged_buckets($basis)) as $k) {
		$res['totals'][$k] = 0.0;
	}
	if (!$accounts) {
		return $res;
	}
	$in = array();
	foreach ($accounts as $a) {
		$in[] = "'".$db->escape($a)."'";
	}
	$sql = "SELECT b.rowid, b.doc_date, b.doc_type, b.doc_ref, b.piece_num, b.code_journal, b.label_operation, b.subledger_account, b.subledger_label,";
	$sql .= " b.thirdparty_code, b.debit, b.credit, b.date_lim_reglement, b.lettering_code";
	$sql .= " FROM ".MAIN_DB_PREFIX."accounting_bookkeeping as b";
	$sql .= " WHERE b.entity = ".((int) $entity)." AND b.numero_compte IN (".implode(',', $in).")";
	$sql .= " AND b.doc_date <= '".$db->idate($asof)."'";
	$sql .= " ORDER BY b.doc_date, b.piece_num, b.rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		return $res;
	}
	$byParty = array();
	while ($o = $db->fetch_object($resql)) {
		$res['entries']++;
		$key = (string) $o->subledger_account;
		$amount = ($type === 'ap' ? (float) $o->credit - (float) $o->debit : (float) $o->debit - (float) $o->credit);
		$docDate = $db->jdate($o->doc_date);
		$due = ($o->date_lim_reglement ? $db->jdate($o->date_lim_reglement) : $docDate);
		if (!isset($byParty[$key])) {
			$byParty[$key] = array('code' => $key, 'name' => ($o->subledger_label !== '' ? $o->subledger_label : $key), 'items' => array());
		}
		if ($o->subledger_label !== '' && $byParty[$key]['name'] === $key) {
			$byParty[$key]['name'] = $o->subledger_label;
		}
		$byParty[$key]['items'][] = array(
			'date' => $docDate, 'due' => $due, 'ref' => (string) $o->doc_ref, 'type' => (string) $o->doc_type,
			'journal' => (string) $o->code_journal, 'label' => (string) $o->label_operation,
			'amount' => $amount, 'letter' => (string) $o->lettering_code,
		);
	}

	foreach ($byParty as $key => $p) {
		// 1. lettered entries: a fully lettered group is settled; a partial one stays open as is
		$open = array();
		$groups = array();
		foreach ($p['items'] as $it) {
			if ($it['letter'] !== '') {
				$groups[$it['letter']][] = $it;
				$res['lettered']++;
			} else {
				$open[] = $it;
			}
		}
		foreach ($groups as $g) {
			$sum = 0.0;
			foreach ($g as $it) {
				$sum += $it['amount'];
			}
			if (abs($sum) >= 0.005) {
				$open = array_merge($open, $g);
			}
		}
		// 2. FIFO: credits (payments, credit notes) settle the oldest debits (invoices)
		$debits = array();
		$credits = array();
		foreach ($open as $it) {
			if ($it['amount'] > 0) {
				$debits[] = $it;
			} elseif ($it['amount'] < 0) {
				$credits[] = $it;
			}
		}
		usort($debits, function ($a, $b) {
			return ($a['date'] <=> $b['date']) ?: strcmp($a['ref'], $b['ref']);
		});
		$pool = 0.0;
		foreach ($credits as $c) {
			$pool += -$c['amount'];
		}
		$items = array();
		foreach ($debits as $d) {
			$remain = $d['amount'];
			if ($pool > 0) {
				$use = min($pool, $remain);
				$remain -= $use;
				$pool -= $use;
			}
			if ($remain >= 0.005) {
				$d['remain'] = $remain;
				$items[] = $d;
			}
		}
		if ($pool >= 0.005) {
			// credits left over: unallocated payments / credit notes, shown as negative items
			usort($credits, function ($a, $b) {
				return $b['date'] <=> $a['date'];
			});
			foreach ($credits as $c) {
				if ($pool < 0.005) {
					break;
				}
				$take = min($pool, -$c['amount']);
				$c['remain'] = -$take;
				$c['unallocated'] = 1;
				$items[] = $c;
				$pool -= $take;
			}
		}
		if (!$items) {
			continue;
		}
		$party = array('code' => $p['code'], 'name' => $p['name'], 'items' => array(), 'buckets' => array(), 'total' => 0.0);
		foreach (array_keys(anychartlab_aged_buckets($basis)) as $k) {
			$party['buckets'][$k] = 0.0;
		}
		foreach ($items as $it) {
			$days = (int) floor(($asof - ($basis === 'doc' ? $it['date'] : $it['due'])) / 86400);
			$it['days'] = $days;
			$it['bucket'] = anychartlab_aged_bucket($days, $basis);
			$party['buckets'][$it['bucket']] += $it['remain'];
			$party['total'] += $it['remain'];
			$party['items'][] = $it;
		}
		usort($party['items'], function ($a, $b) {
			return $a['date'] <=> $b['date'];
		});
		foreach ($party['buckets'] as $k => $v) {
			$res['totals'][$k] += $v;
		}
		$res['total'] += $party['total'];
		if ($key === '') {
			$res['nothirdparty'] += $party['total'];
			$party['name'] = '(no third party on the entry)';
		}
		$res['parties'][] = $party;
	}
	usort($res['parties'], function ($a, $b) {
		return strcasecmp($a['name'], $b['name']);
	});
	return $res;
}

/**
 * Balance of the control accounts in the ledger, in the report's sign.
 *
 * @return float
 */
function anychartlab_aged_ledger_balance($db, $entity, $type, $accounts, $asof)
{
	$bal = anychartlab_balances($db, $entity, null, $asof);
	$sum = 0.0;
	foreach ($accounts as $a) {
		$sum += (float) ($bal[$a] ?? 0);
	}
	return ($type === 'ap' ? -$sum : $sum);
}

/**
 * Amount still owing on Dolibarr's validated unpaid invoices, per third party, keyed by
 * the third party's customer/supplier code AND accounting code (either can be the
 * subledger account on ledger entries). Today's position (not as of a past date).
 *
 * @return array<string,array{name:string,remain:float,count:int}>
 */
function anychartlab_aged_unpaid_invoices($db, $entity, $type)
{
	$out = array();
	if ($type === 'ap') {
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		$table = 'facture_fourn';
		$codes = 's.code_fournisseur as code, s.code_compta_fournisseur as acode';
	} else {
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$table = 'facture';
		$codes = 's.code_client as code, s.code_compta as acode';
	}
	$sql = "SELECT f.rowid, s.nom, ".$codes." FROM ".MAIN_DB_PREFIX.$table." as f INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
	$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.fk_statut = 1 AND f.paye = 0";
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$inv = ($type === 'ap' ? new FactureFournisseur($db) : new Facture($db));
		if ($inv->fetch((int) $o->rowid) <= 0) {
			continue;
		}
		$remain = (float) $inv->getRemainToPay();
		foreach (array_unique(array_filter(array((string) $o->code, (string) $o->acode))) as $k) {
			if (!isset($out[$k])) {
				$out[$k] = array('name' => $o->nom, 'remain' => 0.0, 'count' => 0);
			}
			$out[$k]['remain'] += $remain;
			$out[$k]['count']++;
		}
	}
	return $out;
}

/**
 * Short bucket labels for narrow columns (PDF).
 *
 * @param string $basis due or doc
 * @return array<string,string>
 */
function anychartlab_aged_buckets_short($basis)
{
	if ($basis === 'doc') {
		return array('b0' => '0-30', 'b1' => '31-60', 'b2' => '61-90', 'b3' => '91-120', 'b4' => '120+');
	}
	return array('b0' => 'Not due', 'b1' => '1-30', 'b2' => '31-60', 'b3' => '61-90', 'b4' => '90+');
}
