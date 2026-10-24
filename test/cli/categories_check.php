<?php
declare(strict_types=1);

/**
 * \file    test/cli/categories_check.php
 * \ingroup crm
 * \brief   From v1.2.0. Dolibarr categories of third parties in the CRM: the colour bands under the account name, the choice on
 *          the deal card (rights, one-answer groups), the filter of the lists. Needs the Tags/Categories module. Rolled back.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmcategory.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the Tags/Categories module is enabled', CrmCategory::enabled());
if (!CrmCategory::enabled()) {
	crmCliFinish();
}
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;
$mkCategory = function (string $label, string $color, int $parent = 0) use ($db, $user): int {
	$c = new Categorie($db);
	$c->label = $label;
	$c->type = Categorie::TYPE_CUSTOMER;
	$c->color = $color;
	$c->fk_parent = $parent;
	return (int) $c->create($user);
};
$interest = $mkCategory('ZInterest', '', 0);
$hot = $mkCategory('ZHot', 'ff0000', $interest);
$warm = $mkCategory('ZWarm', '#ffa500', $interest);
$cold = $mkCategory('ZCold', '00f', $interest);
$lang = $mkCategory('ZLanguage', '', 0);
$french = $mkCategory('ZFrench', '00ff00', $lang);
$plain = $mkCategory('ZNoColour', '', 0);
// Core refuses a colour that is not hexadecimal, but the column is free text: write one directly.
$evil = $mkCategory('ZEvil', '', 0);
$db->query('UPDATE '.$prefix."categorie SET color = 'red;background:url(//x.example/a)' WHERE rowid = ".$evil);
crmCliCheck('categories were created', $interest > 0 && $hot > 0 && $warm > 0 && $cold > 0 && $french > 0 && $plain > 0 && $evil > 0);
$conf->global->CRM_CATEGORY_SINGLE = (string) $interest;

$model = new CrmCategory($db);
$available = $model->fetchAvailable();
crmCliCheck('the categories of third parties are listed, colours normalised', isset($available[$hot]) && $available[$hot]['color'] === '#ff0000' && $available[$warm]['color'] === '#ffa500' && $available[$cold]['color'] === '#0000ff' && $available[$plain]['color'] === '' && $available[$evil]['color'] === '' && $available[$hot]['parent_label'] === 'ZInterest');
crmCliCheck('the one-answer groups come from the setting', $model->singleParents() === array($interest));
crmCliCheck('a category and its descendants', $model->withDescendants($interest) === array($interest, $hot, $warm, $cold) || count(array_diff($model->withDescendants($interest), array($interest, $hot, $warm, $cold))) === 0 && count($model->withDescendants($interest)) === 4);

$db->query('INSERT INTO '.$prefix."societe (nom, entity, client, datec) VALUES ('ZCATSOC', ".$entity.", 2, '".$db->idate(dol_now())."')");
$socId = (int) $db->last_insert_id($prefix.'societe');
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$mkDeal = function (int $soc, int $owner) use ($db, $user, $pipelineId): Opportunity {
	$d = new Opportunity($db);
	$d->fk_soc = $soc;
	$d->label = 'ZCATDEAL';
	$d->fk_pipeline = $pipelineId;
	$d->fk_user_resp = $owner;
	$d->create($user);
	return $d;
};
$deal = $mkDeal($socId, (int) $user->id);
$noAccount = $mkDeal(0, (int) $user->id);

crmCliCheck('the choice is saved: one answer of the group, one other', $model->setForDeal($user, (int) $deal->id, array($hot, $french)) === 2 && count(array_diff($model->assigned($socId), array($hot, $french))) === 0 && count($model->assigned($socId)) === 2);
crmCliCheck('the same choice changes nothing', $model->setForDeal($user, (int) $deal->id, array($hot, $french)) === 0);
$model->setForDeal($user, (int) $deal->id, array($hot, $cold, $french));
crmCliCheck('a one-answer group keeps a single sub-category', in_array($hot, $model->assigned($socId), true) && !in_array($cold, $model->assigned($socId), true));
$model->setForDeal($user, (int) $deal->id, array($interest, $french));
crmCliCheck('the heading of a one-answer group cannot be chosen, and no answer clears the group', !in_array($interest, $model->assigned($socId), true) && !in_array($hot, $model->assigned($socId), true) && in_array($french, $model->assigned($socId), true));
$model->setForDeal($user, (int) $deal->id, array(999999, $warm, $warm));
crmCliCheck('an unknown category is ignored, a double counts once', $model->assigned($socId) === array($warm));
crmCliCheck('with no deal account there is nothing to tag', $model->setForDeal($user, (int) $noAccount->id, array($hot)) < 0 && $model->error === 'CrmErrorCategoryNoAccount');

// Rights: the CRM's own, not Dolibarr's right to manage categories.
$limited = new User($db);
$limited->id = (int) $user->id + 100000;
$limited->admin = 0;
$limited->rights = new stdClass();
$limited->rights->crm = new stdClass();
$limited->rights->crm->read = 1;
$limited->rights->crm->write = 1;
crmCliCheck('a user cannot tag the deal of another owner', $model->setForDeal($limited, (int) $deal->id, array($hot)) < 0 && $model->error === 'CrmErrorCategoryRights');
$own = $mkDeal($socId, (int) $limited->id);
crmCliCheck('a user can tag the deals they own, without the Dolibarr right on categories', $model->setForDeal($limited, (int) $own->id, array($hot, $french)) >= 0 && in_array($hot, $model->assigned($socId), true));
unset($limited->rights->crm->write);
crmCliCheck('without the right to write nothing changes', $model->setForDeal($limited, (int) $own->id, array($cold)) < 0 && in_array($hot, $model->assigned($socId), true));

// Display.
$map = $model->fetchForThirdparties(array($socId, 0, -4));
$names = array_column($map[$socId] ?? array(), 'label');
crmCliCheck('the categories of a third party for the lists', in_array('ZHot', $names, true) && in_array('ZFrench', $names, true));
$html = crmAccountMarks('Acme', $map[$socId]);
crmCliCheck('two coloured categories give two bands under the name', substr_count($html, '<i style=') === 2 && strpos($html, 'background:#ff0000') !== false && strpos($html, 'background:#00ff00') !== false && strpos($html, 'ZHot') !== false);
crmCliCheck('without a coloured category the name is returned as it is', crmAccountMarks('Acme', array(array('label' => 'x', 'color' => ''))) === 'Acme' && crmAccountMarks('Acme', array()) === 'Acme');
$many = crmAccountMarks('Acme', array(array('label' => 'a', 'color' => 'f00'), array('label' => 'b', 'color' => '0f0'), array('label' => 'c', 'color' => '00f'), array('label' => 'd', 'color' => 'ff0')));
crmCliCheck('three bands at most', substr_count($many, '<i style=') === 3);
crmCliCheck('a colour that is not a colour is never printed', strpos(crmAccountMarks('A', array(array('label' => 'x', 'color' => 'red;background:url(//x)'))), 'url') === false);

// Filter.
$list = new Opportunity($db);
$ids = function (array $rows): array {
	return array_map(function ($r) {
		return (int) $r->rowid;
	}, $rows);
};
$model->setForDeal($user, (int) $deal->id, array($hot));
crmCliCheck('the list filters on a category', in_array((int) $deal->id, $ids($list->fetchList($user, '', 0, 0, '', 0, 0, 'ref', 'DESC', $hot)), true) && !in_array((int) $deal->id, $ids($list->fetchList($user, '', 0, 0, '', 0, 0, 'ref', 'DESC', $cold)), true));
crmCliCheck('a category includes the ones below it', in_array((int) $deal->id, $ids($list->fetchList($user, '', 0, 0, '', 0, 0, 'ref', 'DESC', $interest)), true));
crmCliCheck('the count follows the filter', $list->countList($user, '', 0, 0, '', $cold) === 0 && $list->countList($user, '', 0, 0, '', $hot) >= 1);
crmCliCheck('an unknown category shows nothing', $list->countList($user, '', 0, 0, '', 99999999) === 0);

// Setting.
crmCliCheck('the setting accepts ids and nothing else', crmNormalizeSetting('CRM_CATEGORY_SINGLE', '3,5') === '3,5' && crmNormalizeSetting('CRM_CATEGORY_SINGLE', '') === '' && crmNormalizeSetting('CRM_CATEGORY_SINGLE', '3,x') === null && crmNormalizeSetting('CRM_CATEGORY_SINGLE', '3,,5') === null && crmNormalizeSetting('CRM_CATEGORY_SINGLE', '3;5') === null && crmNormalizeSetting('CRM_CATEGORY_SINGLE', '3,3,05') === '3,5');

$db->rollback();
crmCliFinish();
