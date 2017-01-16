<?php
namespace Modular\Workflows;

use Modular\Collections\VersionedManyManyList;
use Modular\Interfaces\VersionedRelationship as VersionedRelationshipInterface;
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

	private static $publish_related = true;

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

	public function onBeforePublish() {

	}

	/**
	 * After publishing the extended model publish any versioned relationships too.
	 * archive
	 */
	public function onAfterPublish() {
		if (static::publish_related()) {
			foreach ($this->extensionsByInterface(VersionedRelationshipInterface::class) as $extensionClassName => $extensionInstance) {
				$relationshipName = $extensionClassName::relationship_name();
				/** @var VersionedManyManyList $list */
				$list = $this()->$relationshipName();
				if ($list instanceof VersionedRelationshipInterface) {
					$list->publishItems();
				}
			}
		}
	}

	/**
	 * After rolling back the extended model rollback any versioned relationships too.
	 */
	public function onAfterRollback() {
		if (static::publish_related()) {
			foreach ($this->extensionsByInterface(VersionedRelationshipInterface::class) as $extensionClassName => $extensionInstance) {
				$relationshipName = $extensionClassName::relationship_name();
				/** @var VersionedManyManyList $list */
				$list = $this()->$relationshipName();
				if ($list instanceof VersionedRelationshipInterface) {
					$list->rollbackItems();
				}
			}

		}
	}

	/**
	 * Should the foreign models for this relationship also be published when the extended model is published.
	 *
	 * @return bool
	 */
	public static function publish_related() {
		return static::config()->get('publish_related');
	}
}