<?php
namespace Modular\Collections;

use DataObject;
use DB;
use InvalidArgumentException;
use SQLDelete;

class VersionedManyManyList extends \ManyManyList  {
	const VersionedStatusFieldName = 'VersionedStatus';
	const StatusCurrent = 'Current';
	const StatusRemoved = 'Removed';
	/**
	 * Add an item to this many_many relationship
	 * Does so by adding an entry to the joinTable.
	 *
	 * @param mixed $item
	 * @param array $extraFields A map of additional columns to insert into the joinTable.
	 *                           Column names should be ANSI quoted.
	 */
	public function add($item, $extraFields = array()) {
		parent::add($item, $extraFields);
	}

	public function foreignIDFilter($id = null) {
		if (\Versioned::current_stage() == 'Stage') {

			$joinTable = $this->getJoinTable();
			if ($filter = parent::foreignIDFilter($id)) {
				// check that the VersionedStatus field exists on the join table.
				if (array_key_exists(static::VersionedStatusFieldName, DB::field_list($joinTable))) {
					// add VersionedStatus filter to include only 'Current' records
					$filter = [
						[static::VersionedStatusFieldName => self::StatusCurrent],
						$filter
					];
				}
			}
			return $filter;
		}
	}

	/**
	 * Remove the given item from this list.
	 *
	 * Note that for a ManyManyList, the item is never actually deleted, only
	 * the join table is affected
	 *
	 * @param int $itemID The item ID
	 */
	public function removeByID($itemID) {
		if (!is_numeric($itemID)) {
			throw new InvalidArgumentException("ManyManyList::removeById() expecting an ID");
		}

		$query = new \SQLUpdate("\"{$this->joinTable}\"");

		if ($filter = $this->foreignIDWriteFilter($this->getForeignID())) {
			$query->setWhere($filter);
		} else {
			user_error("Can't call ManyManyList::remove() until a foreign ID is set", E_USER_WARNING);
		}

		$query->addWhere(array("\"{$this->localKey}\"" => $itemID));

		// now update the 'VersionedStatus' field to be 'Removed'
		$query->setAssignments(array(VersionedManyManyList::VersionedStatusFieldName => VersionedManyManyList::StatusRemoved));
		$query->execute();
	}

	/**
	 * Remove all items from this many-many join.  To remove a subset of items,
	 * filter it first.
	 *
	 * @return void
	 */
	public function removeAll() {
		parent::removeAll();
	}

	public function getForeignID() {
		return parent::getForeignID();
	}

	/**
	 * Returns a copy of this list with the ManyMany relationship linked to
	 * the given foreign ID.
	 *
	 * @param int|array $id An ID or an array of IDs.
	 */
	public function forForeignID($id) {
		return parent::forForeignID($id);
	}
}