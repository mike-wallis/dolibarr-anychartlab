<?php
/**
 * Any-Chart Reports Lab — display settings (PDF look, number format, screen
 * indent and report defaults). Stored as module constants ANYCHARTLAB_*.
 */

$res = 0;
if (!$res && is_file('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && is_file('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dirname(__DIR__).'/lib/anychartlab.lib.php';

if (!$user->hasRight('anychartlab', 'setup') && !$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$defaults = anychartlab_display_defaults();

// Field definitions: key => array(type, label, options/help)
$fonts = array('' => 'Dolibarr default PDF font', 'helvetica' => 'Helvetica', 'times' => 'Times', 'courier' => 'Courier', 'dejavusans' => 'DejaVu Sans (wide character support)', 'freesans' => 'FreeSans');
$sections = array(
	'PDF page' => array(
		'PDF_PAPER' => array('select', 'Paper size', array('' => 'Dolibarr default ('.getDolGlobalString('MAIN_PDF_FORMAT', 'A4').')', 'A4' => 'A4', 'LETTER' => 'US Letter', 'LEGAL' => 'US Legal')),
		'PDF_ORIENTATION' => array('select', 'Orientation', array('P' => 'Portrait', 'L' => 'Landscape')),
		'PDF_MARGIN' => array('number', 'Margins (mm)', '5 to 40'),
	),
	'PDF text' => array(
		'PDF_FONT' => array('select', 'Font', $fonts),
		'PDF_FONTSIZE' => array('number', 'Base font size (points)', 'Each kind of line adds its own size change (Line styles below). 6 to 14'),
		'PDF_TITLESIZE' => array('number', 'Report title size (points)', '8 to 28'),
	),
	'PDF header' => array(
		'PDF_SHOW_COMPANY' => array('yesno', 'Show company name', ''),
		'PDF_COMPANY_TEXT' => array('text', 'Heading text instead of the company name', 'Empty = company name from Setup > Company ('.($mysoc->name ?? '').'). E.g. a trading name.'),
		'PDF_SHOW_LOGO' => array('yesno', 'Show company logo', 'Logo from Setup > Company'),
		'PDF_SHOW_PERIOD' => array('yesno', 'Show the period / as-of date line', ''),
	),
	'PDF footer' => array(
		'PDF_FOOTER_TEXT' => array('text', 'Footer text', 'Empty = no footer text'),
		'PDF_SHOW_PRINTDATE' => array('yesno', 'Show printed date and time', ''),
		'PDF_SHOW_PAGENUM' => array('yesno', 'Show page numbers', ''),
	),
	'PDF columns' => array(
		'PDF_SHOW_ACCOUNT_COL' => array('yesno', 'Show the Account column', 'Off: the account number is printed in front of the label'),
		'PDF_SHOW_NOTE_COL' => array('yesno', 'Show the Note column', 'Notes and ledger-entry descriptions'),
	),
	'Numbers (screen and PDF)' => array(
		'NEG_FORMAT' => array('select', 'Negative amounts', array('minus' => '-1,234.56', 'paren' => '(1,234.56)')),
		'DECIMALS' => array('select', 'Decimals', array('2' => '2 decimals (1,234.56)', '0' => 'Whole units (1,235)')),
	),
	'Accounts Receivable / Payable' => array(
		'AGED_ORDER' => array('select', 'Ageing columns', array('oldest' => 'Oldest first (90+, 61-90, 31-60, 1-30, not due)', 'newest' => 'Newest first (not due, 1-30, 31-60, 61-90, 90+)')),
	),
	'Report defaults (when a report is first opened)' => array(
		'DEFAULT_VIEW' => array('select', 'View', array('detailed' => 'Detailed (sub-accounts under their parent)', 'summary' => 'Summary (parent accounts only)')),
		'DEFAULT_HIDE_EMPTY' => array('yesno', 'Hide empty lines', 'With a layout'),
		'DEFAULT_SHOW_ENTRIES' => array('yesno', 'Show entries', 'Detailed view'),
	),
);

/*
 * Actions
 */

if ($action === 'save') {
	$error = 0;
	foreach ($sections as $fields) {
		foreach ($fields as $key => $def) {
			if ($def[0] === 'yesno') {
				$val = GETPOSTINT($key) ? '1' : '0';
			} elseif ($def[0] === 'number') {
				$val = (string) price2num(GETPOST($key, 'alphanohtml'));
				if ($val === '' || !is_numeric($val)) {
					$val = $defaults[$key];
				}
			} elseif ($def[0] === 'select') {
				$val = GETPOST($key, 'alphanohtml');
				if (!array_key_exists($val, $def[2])) {
					$val = $defaults[$key];
				}
			} else {
				$val = trim(GETPOST($key, 'alphanohtml'));
			}
			// Empty text is stored as a single space so it is not replaced by the default again
			if ($def[0] === 'text' && $val === '' && $defaults[$key] !== '') {
				$val = ' ';
			}
			if (dolibarr_set_const($db, 'ANYCHARTLAB_'.$key, $val, 'chaine', 0, 'Any-Chart Reports Lab display setting', $conf->entity) < 0) {
				$error++;
			}
		}
	}
	foreach (anychartlab_style_kinds() as $kind => $klabel) {
		$st = array(
			'font' => (array_key_exists(GETPOST('st_'.$kind.'_font', 'alphanohtml'), $fonts) ? GETPOST('st_'.$kind.'_font', 'alphanohtml') : ''),
			'size' => (float) price2num(GETPOST('st_'.$kind.'_size', 'alphanohtml')),
			'bold' => GETPOSTINT('st_'.$kind.'_bold') ? 1 : 0,
			'italic' => GETPOSTINT('st_'.$kind.'_italic') ? 1 : 0,
			'upper' => GETPOSTINT('st_'.$kind.'_upper') ? 1 : 0,
			'indent' => (float) price2num(GETPOST('st_'.$kind.'_indent', 'alphanohtml')),
			'line' => (in_array(GETPOST('st_'.$kind.'_line', 'aZ09'), array('none', 'amount', 'full', 'double')) ? GETPOST('st_'.$kind.'_line', 'aZ09') : 'none'),
			'shade' => GETPOSTINT('st_'.$kind.'_shade') ? 1 : 0,
			'colour' => (preg_match('/^#?[0-9a-fA-F]{6}$/', GETPOST('st_'.$kind.'_colour', 'alphanohtml')) ? '#'.ltrim(GETPOST('st_'.$kind.'_colour', 'alphanohtml'), '#') : '#000000'),
		);
		if (dolibarr_set_const($db, 'ANYCHARTLAB_STYLE_'.strtoupper($kind), json_encode($st), 'chaine', 0, 'Any-Chart Reports Lab line style', $conf->entity) < 0) {
			$error++;
		}
	}
	setEventMessages($error ? 'Some settings could not be saved.' : 'Display settings saved.', null, $error ? 'errors' : 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'reset') {
	foreach ($defaults as $key => $v) {
		dolibarr_del_const($db, 'ANYCHARTLAB_'.$key, $conf->entity);
	}
	foreach (anychartlab_style_kinds() as $kind => $klabel) {
		dolibarr_del_const($db, 'ANYCHARTLAB_STYLE_'.strtoupper($kind), $conf->entity);
	}
	setEventMessages('Display settings reset to the defaults.', null);
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$form = new Form($db);
llxHeader('', 'Display settings', '', '', 0, 0, '', '', '', 'mod-anychartlab page-display');
print load_fiche_titre('Display settings', '', 'accountancy');
anychartlab_print_nav('admin/display.php', anychartlab_active_chart($db));

$bs = dol_buildpath('/anychartlab/balancesheet.php', 1);
$is = dol_buildpath('/anychartlab/incomestatement.php', 1);
print '<div class="info">How the Balance Sheet and Income Statement look, on screen and in PDF. Save, then try <a href="'.$bs.'?action=pdfpreview&token='.newToken().'" target="_blank">Preview PDF of the Balance Sheet</a> or <a href="'.$is.'?action=pdfpreview&token='.newToken().'" target="_blank">of the Income Statement</a> (current options; layouts and other report options are chosen on the report pages).</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
foreach ($sections as $title => $fields) {
	print '<tr class="liste_titre"><th colspan="3">'.dol_escape_htmltag($title).'</th></tr>';
	foreach ($fields as $key => $def) {
		$val = anychartlab_cfg($key);
		print '<tr class="oddeven"><td class="titlefieldcreate">'.dol_escape_htmltag($def[1]).'</td><td>';
		if ($def[0] === 'yesno') {
			print '<input type="checkbox" name="'.$key.'" value="1"'.($val === '1' ? ' checked' : '').'>';
		} elseif ($def[0] === 'number') {
			print '<input type="text" class="flat width50 right" name="'.$key.'" value="'.dol_escape_htmltag($val).'">';
		} elseif ($def[0] === 'select') {
			print $form->selectarray($key, $def[2], $val, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200');
		} else {
			print '<input type="text" class="flat minwidth300" name="'.$key.'" value="'.dol_escape_htmltag(trim($val)).'">';
		}
		$help = ($def[0] === 'select' ? '' : (string) $def[2]);
		print '</td><td class="opacitymedium">'.dol_escape_htmltag($help).($val !== $defaults[$key] && trim($val) !== trim($defaults[$key]) ? ' <span class="badge badge-info">changed</span>' : '').'</td></tr>';
	}
}
print '</table><br>';

// Line styles grid (screen and PDF)
$styleDefaults = anychartlab_style_defaults();
$lineOptions = array('none' => 'None', 'amount' => 'Over amount', 'full' => 'Full width', 'double' => 'Double');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="10">Line styles (screen and PDF)</th></tr>';
print '<tr class="liste_titre"><th>Kind of line</th><th>Font</th><th class="center">Size ±pt</th><th class="center">Bold</th><th class="center">Italic</th><th class="center">Upper case</th><th class="center">Indent mm</th><th>Rule above</th><th class="center">Shaded</th><th>Colour</th></tr>';
foreach (anychartlab_style_kinds() as $kind => $klabel) {
	$st = anychartlab_style($kind);
	$changed = ($st != $styleDefaults[$kind]);
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($klabel).($changed ? ' <span class="badge badge-info">changed</span>' : '').'</td>';
	print '<td>'.$form->selectarray('st_'.$kind.'_font', array('' => 'Report font') + array_slice($fonts, 1, null, true), $st['font'], 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
	print '<td class="center"><input type="text" class="flat width40 right" name="st_'.$kind.'_size" value="'.dol_escape_htmltag((string) $st['size']).'"></td>';
	foreach (array('bold', 'italic', 'upper') as $flag) {
		print '<td class="center"><input type="checkbox" name="st_'.$kind.'_'.$flag.'" value="1"'.($st[$flag] ? ' checked' : '').'></td>';
	}
	print '<td class="center"><input type="text" class="flat width40 right" name="st_'.$kind.'_indent" value="'.dol_escape_htmltag((string) $st['indent']).'"></td>';
	print '<td>'.$form->selectarray('st_'.$kind.'_line', $lineOptions, $st['line'], 0, 0, 0, '', 0, 0, 0, '', 'maxwidth125').'</td>';
	print '<td class="center"><input type="checkbox" name="st_'.$kind.'_shade" value="1"'.($st['shade'] ? ' checked' : '').'></td>';
	print '<td><input type="color" name="st_'.$kind.'_colour" value="'.dol_escape_htmltag($st['colour']).'"></td></tr>';
}
print '</table>';
print '<p class="opacitymedium">Size is added to the base font size (e.g. +1 for headings, -1.5 for ledger entries). Indent moves the account number and label to the right (sub-accounts and entries). Rule above draws a line above the row: over the amount column, the full width, or double.</p>';
print '<div class="center" style="margin:12px 0"><input type="submit" class="button button-save" value="Save"> ';
print '<a class="button button-cancel" href="'.$_SERVER['PHP_SELF'].'?action=reset&token='.newToken().'">Reset to defaults</a></div>';
print '</form>';

llxFooter();
$db->close();
