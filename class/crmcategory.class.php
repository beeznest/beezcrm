<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmcategory.class.php
 * \ingroup crm
 * \brief   From v1.2.0. The Dolibarr categories of third parties (type "customer", which holds prospects and customers) seen from
 *          the CRM: their colours under the account names, a choice on the deal card, and a filter. The categories themselves,
 *          their colours and their hierarchy are core's (Tags/Categories module): nothing is copied, nothing has a table here.
 */

require_once __DIR__.'/../lib/crm.lib.php';

/**
 * Categories of the third party of a deal.
 */
class CrmCategory
{
	/** @var DoliDB */
	private $db;

	/** @var string Language key of the last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * True when Dolibarr's Tags/Categories module is on: without it the CRM shows no category.
	 */
	public static function enabled(): bool
	{
		return isModEnabled('category');
	}

	/**
	 * The categories of third parties that exist, parents first: rows with id, label, color (normalised), fk_parent, parent_label.
	 *
	 * @return array<int,array{id:int,label:string,color:string,fk_parent:int,parent_label:string}>
	 */
	public function fetchAvailable(): array
	{
		if (!self::enabled()) {
			return array();
		}
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		$prefix = $this->db->prefix();
		$sql = 'SELECT c.rowid, c.label, c.color, c.fk_parent, p.label as parent_label FROM '.$prefix.'categorie as c';
		$sql .= ' LEFT JOIN '.$prefix.'categorie as p ON p.rowid = c.fk_parent';
		$sql .= ' WHERE c.type = '.((int) (Categorie::$MAP_ID[Categorie::TYPE_CUSTOMER] ?? 2)).' AND c.entity IN ('.getEntity('category').')';
		$sql .= ' ORDER BY c.fk_parent, c.position, c.label';
		$list = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$list[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'label' => (string) $obj->label,
				'color' => crmCategoryColor((string) $obj->color),
				'fk_parent' => (int) $obj->fk_parent,
				'parent_label' => (string) $obj->parent_label,
			);
		}
		return $list;
	}

	/**
	 * The ids of the categories chosen as one-answer groups in the setup (CRM_CATEGORY_SINGLE): inside such a category a third
	 * party has at most one sub-category, as for a degree of interest.
	 *
	 * @return int[]
	 */
	public function singleParents(): array
	{
		return crmIdList(getDolGlobalString('CRM_CATEGORY_SINGLE'));
	}

	/**
	 * The categories of several third parties at once, for the lists: socid => list of {label, color}, in the order of the
	 * categories.
	 *
	 * @param int[] $socIds Third party ids
	 * @return array<int,array<int,array{label:string,color:string}>>
	 */
	public function fetchForThirdparties(array $socIds): array
	{
		$socIds = array_values(array_unique(array_filter(array_map('intval', $socIds), function (int $id): bool {
			return $id > 0;
		})));
		if (!self::enabled() || count($socIds) === 0) {
			return array();
		}
		$prefix = $this->db->prefix();
		$sql = 'SELECT cs.fk_soc, c.label, c.color FROM '.$prefix.'categorie_societe as cs INNER JOIN '.$prefix.'categorie as c ON c.rowid = cs.fk_categorie';
		$sql .= ' WHERE cs.fk_soc IN ('.implode(',', $socIds).') AND c.entity IN ('.getEntity('category').') ORDER BY c.fk_parent, c.position, c.label';
		$map = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$map[(int) $obj->fk_soc][] = array('label' => (string) $obj->label, 'color' => crmCategoryColor((string) $obj->color));
		}
		return $map;
	}

	/**
	 * The ids of the categories a third party has.
	 *
	 * @return int[]
	 */
	public function assigned(int $socId): array
	{
		if (!self::enabled() || $socId <= 0) {
			return array();
		}
		$prefix = $this->db->prefix();
		$sql = 'SELECT cs.fk_categorie FROM '.$prefix.'categorie_societe as cs INNER JOIN '.$prefix.'categorie as c ON c.rowid = cs.fk_categorie';
		$sql .= ' WHERE cs.fk_soc = '.((int) $socId).' AND c.entity IN ('.getEntity('category').')';
		$ids = array();
		$resql = $this->db->query($sql);
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$ids[] = (int) $obj->fk_categorie;
		}
		return $ids;
	}

	/**
	 * A category and the categories below it, for the filter of the lists.
	 *
	 * @return int[]
	 */
	public function withDescendants(int $categoryId): array
	{
		$all = $this->fetchAvailable();
		if (!isset($all[$categoryId])) {
			return array();
		}
		$ids = array($categoryId);
		$added = true;
		while ($added) {
			$added = false;
			foreach ($all as $row) {
				if (in_array($row['fk_parent'], $ids, true) && !in_array($row['id'], $ids, true)) {
					$ids[] = $row['id'];
					$added = true;
				}
			}
		}
		return $ids;
	}

	/**
	 * Set the categories of the third party of a deal to this list (only the categories of third parties that exist are
	 * considered; the one-answer groups keep a single sub-category). The user needs the right to write deals and must be allowed
	 * to see the deal; the Dolibarr right to manage categories is not needed, which keeps the choice limited to the deals of the
	 * user: with that right a user could change every category of every third party.
	 *
	 * @param int[] $categoryIds Wanted categories
	 * @return int Number of changes, or -1 with a language key in ->error
	 */
	public function setForDeal(User $user, int $dealId, array $categoryIds): int
	{
		require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		require_once __DIR__.'/opportunity.class.php';
		if (!self::enabled()) {
			$this->error = 'CrmErrorCategoryModule';
			return -1;
		}
		$deal = new Opportunity($this->db);
		if (!$user->hasRight('crm', 'write') || $dealId <= 0 || $deal->fetch($dealId) <= 0 || !crmCanReadOwner($user, (int) $deal->fk_user_resp)) {
			$this->error = 'CrmErrorCategoryRights';
			return -1;
		}
		if ((int) $deal->fk_soc <= 0) {
			$this->error = 'CrmErrorCategoryNoAccount';
			return -1;
		}
		$available = $this->fetchAvailable();
		$single = array_values(array_intersect($this->singleParents(), array_keys($available)));
		$wanted = array();
		foreach ($categoryIds as $categoryId) {
			$categoryId = (int) $categoryId;
			// A one-answer group is a heading: only its sub-categories can be chosen, one of them.
			if (!isset($available[$categoryId]) || in_array($categoryId, $single, true) || in_array($categoryId, $wanted, true)) {
				continue;
			}
			$parent = $available[$categoryId]['fk_parent'];
			if (in_array($parent, $single, true)) {
				foreach ($wanted as $already) {
					if ($available[$already]['fk_parent'] === $parent) {
						continue 2;
					}
				}
			}
			$wanted[] = $categoryId;
		}
		$societe = new Societe($this->db);
		if ($societe->fetch((int) $deal->fk_soc) <= 0) {
			$this->error = 'CrmErrorCategoryNoAccount';
			return -1;
		}
		$current = $this->assigned((int) $deal->fk_soc);
		$changes = 0;
		foreach ($available as $categoryId => $row) {
			$has = in_array($categoryId, $current, true);
			$wants = in_array($categoryId, $wanted, true);
			if ($has === $wants) {
				continue;
			}
			$category = new Categorie($this->db);
			if ($category->fetch($categoryId) <= 0) {
				continue;
			}
			$result = $wants ? $category->add_type($societe, Categorie::TYPE_CUSTOMER) : $category->del_type($societe, Categorie::TYPE_CUSTOMER);
			if ($result < 0) {
				$this->error = crmDbError($this->db);
				return -1;
			}
			$changes++;
		}
		return $changes;
	}
}
