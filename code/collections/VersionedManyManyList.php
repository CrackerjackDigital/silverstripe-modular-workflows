<?php
namespace Modular\Collections;

use DB;
use InvalidArgumentException;
use Modular\backprop;
use Modular\VersionedModel;
use SQLQuery;
use SS_Query;

class VersionedManyManyList extends \ManyManyList {
	const VersionedNumberFieldName = 'VersionedNumber';
	const VersionedStatusFieldName = 'VersionedStatus';
	const VersionedLinkFieldName   = 'VersionedLinkID';
	const StatusStaged             = 'Staged';          // Only on on Stage
	const StatusPublished          = 'Published';       // On Stage and Live
	const StatusRemoved            = 'Removed';         // Only on Live
	const StatusArchived           = 'Archived';        // Neither Stage nor Live

	const DefaultStatus = self::StatusStaged;           // status to create relationships with

	private static $state_stage_map = [
		self::StatusPublished => ['Stage', 'Live'],
		self::StatusStaged    => ['Stage'],
		self::StatusRemoved   => ['Live'],
		self::StatusArchived  => ['NoSuchStage'],           // will always be excluded
	];

	/**
	 * Return array of Status values, first one default state.
	 * Can also be used to build an enum field (see versioned_many_many.versionedExtraFields ).
	 */
	public static function states() {
		return array_keys(static::state_stage_map());
	}

	/**
	 * Returns map of allowed states to the Stages they are allowed to show on.
	 *
	 * @return array
	 */
	public static function state_stage_map() {
		return static::config()->get('state_stage_map');
	}

	/**
	 * Add an item to this many_many relationship
	 * Does so by adding an entry to the joinTable.
	 *
	 * @param \DataObject $item
	 * @param array       $extraData   A map of additional columns to insert into the joinTable.
	 *                                 Column names should be ANSI quoted.
	 */
	public function add($item, $extraData = array()) {
		if ($item instanceof VersionedModel) {
			// check for existing linked versioned with a 'Published' status as they are the copies being shown on live at the moment.
			$linkedVersions = $this->linkedVersions($item, self::StatusPublished);

			if ($linkedVersions->count() == 0) {

				// we need to create a copy of the published item one and save as 'Removed' so it only appears on the live site
				/** @var \DataObject|\Versioned|backprop $live */
				$live = clone $item;

				$oldValues = $item->backpropData('changing');

				// set fields back to what they were before last write as tracked by 'backprop' handling.
				$live->update($oldValues);
				$live->ID = null;
				$live->write(false, true, true);

//			$relationshipID = $this->existingQuery($item, self::StatusPublished)->execute()->first()->ID;

				// add (or update) the existing linked copy relationship with status of Published and link back to original item.
				// this link will be used when the item is published to identify temporary live records and remove them.
				$this->add(
					$live,
					[
						self::VersionedStatusFieldName => self::StatusPublished,
						self::VersionedLinkFieldName   => $item->ID,
					]
				);
				// now we need to publish the block without triggering publish handlers which would do a cleanup
				$live->doPublish('Live');
			}
			// add or update existing one to status 'Staged' so we can keep on editing it without impacting the live site
			$extraData = array_merge(
				[
					self::VersionedStatusFieldName => self::StatusStaged,
				],
				$extraData
			);
		}
		parent::add($item, $extraData);
	}

	/**
	 * Update any linked versions to status 'Archived'
	 *
	 * @param \DataObject|int $itemOrID
	 * @param string          $toState    new state to set
	 * @param array|string    $ifInStates optional array of states to target for update
	 */
	public function updateLinkedVersions($itemOrID, $toState = self::StatusArchived, $ifInStates = []) {
		$itemID = ($itemOrID instanceof \DataObject) ? $itemOrID->ID : $itemOrID;

		$query = new \SQLUpdate("\"{$this->joinTable}\"");
		$query->addWhere([
			self::VersionedLinkFieldName => $itemID,
		]);
		if ($ifInStates) {
			$query->addWhere($this->statesFilter($ifInStates));
		}
		$query->addAssignments([
			self::VersionedStatusFieldName => $toState,
		]);
		$sql = $query->sql();
		$query->execute();
	}

	/**
	 * Return items in this list of relationships which have a 'link id' of the provided item ID and which are in the provided state.
	 *
	 * @param \DataObject|int $itemOrID
	 * @param array|string    $states
	 * @return \SQLSelect
	 */
	protected function linkedVersions($itemOrID, $states) {
		$itemID = ($itemOrID instanceof \DataObject) ? $itemOrID->ID : $itemOrID;

		$query = new \SQLSelect("ID", "\"{$this->joinTable}\"");
		$query->addWhere([
			self::VersionedLinkFieldName => $itemID,
		]);
		if ($states) {
			$query->addWhere($this->statesFilter($states));
		}
		return $query;
	}

	/**
	 * Check if any relationships exist to the item in the given state(s)
	 *
	 * @param \DataObject|int $itemOrID
	 * @param array|string    $states
	 * @return bool
	 */
	protected function hasExisting($itemOrID, $states) {
		return $this->existingQuery($itemOrID, $states)->count() > 0;
	}

	/**
	 * Check if this list already has the item.
	 *
	 * @param int|\DataObject $itemOrID
	 * @param string|array    $states what states to check for existing relationship, e.g. self.StatusPublished, self.AnyStatus
	 * @return SQLQuery
	 */
	protected function existingQuery($itemOrID, $states = []) {
		$itemID = ($itemOrID instanceof \DataObject)
			? $itemOrID->ID
			: $itemOrID;

		// With the current query, simply add the foreign and local conditions
		// The query can be a bit odd, especially if custom relation classes
		// don't join expected tables (@see Member_GroupSet for example).
		$query = new SQLQuery("ID", "\"{$this->joinTable}\"");
		$query->addWhere(array(
			"\"{$this->joinTable}\".\"{$this->localKey}\"" => $itemID,
		));
		if ($states) {
			$query->addWhere($this->statesFilter($states));
		}
		return $query;
	}

	/**
	 * If we're in Stage mode then filter out items which don't have a VersionedStatus of 'Current'.
	 *
	 * @param int|null          $id
	 * @param string|array|null $states check for this status
	 * @return array
	 */
	public function foreignIDFilter($id = null, $states = []) {
		$filter = parent::foreignIDFilter($id);

		$joinTable = $this->getJoinTable();

		// check that the VersionedStatus field exists on the join table.
		if (array_key_exists(static::VersionedStatusFieldName, DB::field_list($joinTable))) {
			$currentStage = \Versioned::current_stage();

			if ($states) {
				// use provided states for building filter
				$states = is_array($states) ? $states : [$states];
			} else {
				// leave only states in map which have current stage as an option
				// no stage will come out as 'NoSuchStage' and so fail the filter as expected.
				$states = array_keys(
					array_filter(
						self::state_stage_map(),
						function ($stages) use ($currentStage) {
							return in_array($currentStage, $stages);
						}
					)
				);

			}

			// add VersionedStatus filter to include only records allowed for current stage
			// filtering out any empty components
			$filter = array_filter([
				$this->statesFilter($states),
				$filter ?: [],
			]);
		}
		return $filter;
	}

	/**
	 * Return a filter for selecting by states
	 *
	 * @param $states
	 * @return array
	 */
	protected function statesFilter($states) {
		$states = is_array($states) ? $states : [$states];
		return [
			static::VersionedStatusFieldName . ' in (' . DB::placeholders($states) . ')' => $states,
		];
	}

	/**
	 * Update the given item in the list so that is flagged as 'Removed'. This will be checked by versioned_many_many trait exhibiting extensions
	 * to ensure that 'Removed' items no longer get included in filter for display if in Stage mode. Records with 'Removed' status are then updated
	 * to 'Deleted' when the model that owns the many_many relationship is published.
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
		$extraData = [
			VersionedManyManyList::VersionedStatusFieldName => VersionedManyManyList::StatusRemoved,
		];
		$this->updateItemExtraData($itemID, $extraData);
	}

	/**
	 * Update extra data on a relationship in this list.
	 *
	 * @param       $itemID
	 * @param array $extraData
	 */
	public function updateItemExtraData($itemID, array $extraData) {

		$query = new \SQLUpdate("\"{$this->joinTable}\"");

		if ($filter = $this->foreignIDWriteFilter($this->getForeignID())) {
			$query->setWhere($filter);
		} else {
			user_error("Can't call ManyManyList::remove() until a foreign ID is set", E_USER_WARNING);
		}

		$query->addWhere(array("\"{$this->localKey}\"" => $itemID));

		// update the relationship record with extra data
		$query->setAssignments($extraData);
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