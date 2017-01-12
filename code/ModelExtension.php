<?php
namespace Modular\Workflows;

use Modular\Collections\VersionedManyManyList;
use Modular\Interfaces\Arities;
use Modular\Interfaces\VersionedRelationship;
use Modular\reflection;
use Modular\related;

/**
 * Adds permissions checks and a mechanism to allow all related models of the extended models type to be
 * published when the 'owner' is published.
 *
 * @package Modular\Workflows
 */
class ModelExtension extends \Modular\ModelExtension {
	use related;
	use reflection;

	const AuthorGroup    = 'content-authors';
	const PublisherGroup = 'content-publishers';

	const ActionEdit = 'edit';
	const ActionView = 'view';

	// configure which groups are allowed to view records
	// TODO move to security model in DB
	private static $view_groups = [
		self::AuthorGroup    => true,
		self::PublisherGroup => true,
	];

	// configure which groups are allowed to edit records
	// TODO move to security model in DB
	private static $edit_groups = [
		self::AuthorGroup    => true,
		self::PublisherGroup => false,
	];

	public function canDoIt($what, $member = null) {
		$member = $member
			? (is_numeric($member) ? \Member::get()->byID($member) : $member)
			: \Member::currentUser();

		if ($member) {
			$groups = array_keys(array_filter($this->config()->get("{$what}_groups")));

			return $member->inGroups($groups) || \Permission::check('ADMIN', 'any', $member);
		}
	}

	public function canCreate($member = null) {
		return $this->canDoIt(self::ActionEdit, $member);
	}

	public function canView($member = null) {
		return $this->canDoIt(self::ActionView, $member);
	}

	public function canViewVersioned($member = null) {
		return $this->canDoIt(self::ActionView, $member);
	}

	public function canEdit($member) {
		return $this->canDoIt(self::ActionEdit, $member);
	}

	public function canDelete($member) {
		return $this->canDoIt(self::ActionEdit, $member);
	}

	public function onAfterPublish() {
		foreach ($this->extensionsByInterface(VersionedRelationship::class) as $extensionClassName => $extensionInstance ) {
			$relationshipName = $extensionClassName::relationship_name();
			/** @var VersionedManyManyList $list */
			$list = $this()->$relationshipName();

			// walk through the list looking for models with a status of 'Editing', these should be written to Live and the
			// relationship extra data updated to a status 'Published'
			foreach ($list as $model) {
				if ($extra = $list->getExtraData($relationshipName, $model->ID)) {
					// check for 'Editing' mode
					if ($extra[VersionedManyManyList::VersionedStatusFieldName] == VersionedManyManyList::StatusStaged) {
						// publish the item
						$model->writeToStage('Live');

						// 'publish' the relationship
						$list->updateItemExtraData(
							$model->ID,
							[
								VersionedManyManyList::VersionedStatusFieldName => VersionedManyManyList::StatusPublished
							]
						);
					}
				}
			}
		}
	}

}