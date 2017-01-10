<?php
namespace Modular\Traits;

use Modular\Collections\VersionedManyManyList;

/**
 * Add to a relationship defining class which implements a versioned many_many relationship, e.g.
 *
 * @package Modular\Workflows
 */
trait versioned_many_many {
	/**
	 * Add versioned fields defined in VersionedManyManyList to many_many_extraFields.
	 *
	 * If there is already an extraStatics on the class will need to be added in that method due to trait inheritance/override.
	 *
	 * @param null $class
	 * @param null $extension
	 * @return array
	 */
	public function extraStatics($class = null, $extension = null) {
		$extraFields = array_merge_recursive(
			parent::extraStatics($class, $extension),
			[
				'many_many_extraFields' => $this->versionedExtraFields(),
			]
		);
		return $extraFields;
	}

	/**
	 * Returns an array of field definitions suitable for merging (recursively) into return from extraStatics call.
	 * @param null $class
	 * @param null $extension
	 * @return array
	 */
	public function versionedExtraFields($class = null, $extension = null) {
		return [
			static::relationship_name() => [
				VersionedManyManyList::VersionedStatusFieldName => "Enum('" . VersionedManyManyList::StatusCurrent . "," . VersionedManyManyList::StatusRemoved . "')",
			    VersionedManyManyList::VersionedNumberFieldName => 'Int'
			]
		];
	}
}