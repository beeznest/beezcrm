<?php
/* Copyright (C) 2026 CRM contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

/**
 * \file    core/boxes/crmwidget.php
 * \ingroup crm
 * \brief   Home box: the current user's overdue steps and deals with no next step.
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * The filename is the lowercase class name, which is how Dolibarr finds the box.
 */
class crmwidget extends ModeleBoxes
{
	/**
	 * @var string
	 */
	public $boxcode = 'crmfollowup';
	/**
	 * @var string
	 */
	public $boximg = 'fa-handshake';
	/**
	 * @var string
	 */
	public $boxlabel = 'CrmBoxTitle';
	/**
	 * @var string
	 */
	public $lang = 'crm@crm';
	/**
	 * @var string[]
	 */
	public $depends = array('crm');
	/**
	 * @var string
	 */
	public $version = '1.0';

	/**
	 * @param DoliDB $db    Database handler
	 * @param string $param Extra parameter
	 */
	public function __construct(DoliDB $db, $param = '')
	{
		global $user;
		parent::__construct($db, $param);
		$this->param = $param;
		$this->enabled = isModEnabled('crm');
		$this->hidden = (!$user->hasRight('crm', 'read') && !$user->hasRight('crm', 'readall'));
	}

	/**
	 * Always the current user, even when that user can read every owner.
	 *
	 * @param int $max Maximum lines
	 * @return void
	 */
	public function loadBox($max = 5)
	{
		global $langs, $user;
		$langs->load('crm@crm');
		$this->max = $max;
		$this->info_box_head = array(
			'text' => $langs->trans('CrmBoxTitle'),
			'sublink' => dol_buildpath('/crm/index.php', 1),
		);
		if (!$user->hasRight('crm', 'read') && !$user->hasRight('crm', 'readall')) {
			return;
		}
		require_once __DIR__.'/../../class/crmreport.class.php';
		$report = new CrmReport($this->db);
		$hygiene = $report->hygieneForOwner((int) $user->id);
		$lines = array();
		foreach ($hygiene['overdue'] as $row) {
			$lines[] = array((int) $row->fk_element, $langs->trans('CrmOverdue').': '.$row->ref.' — '.$row->label);
		}
		foreach ($hygiene['no_next'] as $row) {
			$lines[] = array((int) $row->rowid, $langs->trans('CrmNoNextStep').': '.$row->ref.' — '.$row->label);
		}
		$i = 0;
		foreach ($lines as $line) {
			if ($i >= $max) {
				break;
			}
			$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.$line[0];
			$this->info_box_contents[$i][0] = array(
				'text' => '<a href="'.$url.'">'.dol_escape_htmltag($line[1]).'</a>',
			);
			$i++;
		}
		if ($i === 0) {
			$this->info_box_contents[0][0] = array('text' => $langs->trans('CrmNoRows'));
		}
	}
}
