<?php
namespace Modular\Collections;

use DB;
use InvalidArgumentException;
use Modular\backprop;
use Modular\GridField\GridFieldOrderableRows;
use Modular\VersionedModel;
use SQLQuery;
use SS_Query;

class VersionedManyManyList extends \ManyManyList {
	const VersionedNumberFieldName = 'VersionedNumber';
	const VersionedStatusFieldName = 'VersionedStatus';
	const VersionedLinkFieldName   = 'VersionedLinkID';
	const VersionedMemberFieldName = 'VersionedMemberID';           // who owns this relationship (created, updated)?

	const StatusStaged    = 'Editing';             // Only on Stage
	const StatusPublished = 'Published';           // On Stage and Live
	const StatusLiveCopy  = 'Live';                // Only on Live
	const StatusArchived  = 'Archived';            // Neither Stage nor Live

	const DefaultStatus = self::StatusStaged;               // status to create relationships with

	private static $state_stage_map = [
		self::StatusPublished => ['Stage', 'Live'],         // standard record visible in CMS and Live (i.e. Published)
		self::StatusStaged    => ['Stage'],                 // newly created or being edited
		self::StatusLiveCopy  => ['Live'],                  // copy made to live while real record is being edited
		self::StatusArchived  => ['Archived'],              // excluded from Stage & Live as there is no 'Archived' stage atm
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
	 * This is used to build the filter criteria for this list, we modify default behaviour so
	 * if we're in Stage mode then filter out items which don't have a VersionedStatus of 'Current'.
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
	 * For VersionedModels creates a copy of the item with values from before it was updated and publishes this to live along with a relationship
	 * in this list's table with a 'Live' status. This copy will be visible on the Live site. The relationship to the item being added (if new)
	 * or updated (if existing) is then updated to 'Editing' status and so will be filtered out of Live mode but visible in Stage, so in the CMS.
	 *
	 * Does so by adding an entry to the joinTable.
	 *
	 * @param \DataObject $item
	 * @param array       $extraData   A map of additional columns to insert into the joinTable.
	 *                                 Column names should be ANSI quoted.
	 */
	public function add($item, $extraData = array()) {
		// deal with VersionedModel records
		if ($item instanceof VersionedModel) {
			// check for existing linked versioned with a 'Published' status as if it exists we don't need to create a new one.
			$hasLinkedVersions = $this->linkedVersions($item, self::StatusPublished)->count();

			// check for an existing published version, this means we need to spin off a new one with those details if one does exist
			$hasPublished = $this->hasExisting($item, self::StatusPublished);

			// if no linked version exists but a published version exists we need to create a 'Live' copy.
			if (!$hasLinkedVersions && $hasPublished) {

				// get the 'old' values from before updates to the model where applied
				$savedValues = @$item->backpropData('changing')['original'] ?: [];

				unset($savedValues['ID']);
				unset($savedValues['ClassName']);
				// remove unset($savedValues[$this->localKey]);

				// create a copy of the published item and its relationships
				/** @var \DataObject|\Versioned|backprop $live */
				$live = $item->duplicate(true);

				$live->update($savedValues);

				// write with old values
				$live->write();

				// put on stage so can find & manipulate easier
				$live->writeToStage('Stage');

				// put on Live
				$live->writeToStage('Live');

				// setup the relationship for the new 'live' record and backlink to the 'real' record, set member ID on it
				$liveExtraData = [
					self::VersionedStatusFieldName => self::StatusLiveCopy,         // only show copy on live
					self::VersionedLinkFieldName   => $item->ID,                    // link back to original item
					self::VersionedMemberFieldName => \Member::currentUserID(),
				];

				// copy data from the existing relationship, e.g. Sort fields etc
				$existing = $this->existingQuery($item, self::StatusPublished);
				if ($first = $existing->firstRow()) {
					if ($record = $existing->execute()->record()) {
						$liveExtraData = array_merge(
							$record,
							$liveExtraData
						);
						// unset the ID so add doesn't just update the existing one.
						unset($liveExtraData['ID']);
					}
				}
				// add should set this but here we go
				$liveExtraData[$this->localKey] = $live->ID;

				// add a new relationship to the 'live' record with a backlink to the 'real' item which is being edited
				// this link will be used when the item is published to identify temporary live records and remove them.
				parent::add(
					$live,
					$liveExtraData
				);

				// finished creating live copy
			}

			// add or update existing one to status 'Staged' so we can keep on editing it without impacting the live site,
			// also update the VersionedMemberID to current member ID.
			$extraData = array_merge(
				[
					self::VersionedStatusFieldName => self::DefaultStatus,
					self::VersionedMemberFieldName => \Member::currentUserID(),
				],
				$extraData
			);
		}
		parent::add($item, $extraData);
	}

	/**
	 * Update any linked versions for the provided item/ID. Generally used to remove an old version of a record from
	 * the Live site when a new version is being published, e.g. in VersionedModel.onAfterWrite(). This won't unpublish the
	 * linked version models though.
	 *
	 * @param \DataObject|int $itemOrID
	 * @param string          $toState    new state to set, e.g. 'Archived' to remove from live site
	 * @param array|string    $ifInStates optional array of states to target for update
	 * @return \SQLSelect the linked versions which were updated
	 */
	public function updateLinkedVersions($itemOrID, $toState, $ifInStates = []) {
		$itemID = ($itemOrID instanceof \DataObject) ? $itemOrID->ID : $itemOrID;

		$linkedVersions = $this->linkedVersions($itemID, $ifInStates);
		if ($linkedVersions->count()) {
			// convert to an update and set state on the selected records.
			$update = $linkedVersions->toUpdate();
			$update->addAssignments([
				self::VersionedStatusFieldName => $toState,
			]);
			$update->execute();
		}

		// return the versions we updated
		return $linkedVersions;
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
	 * Return items in this list of relationships which have a 'link id' of the provided item ID and which are in the provided state.
	 * Similair to existingQuery but uses the LinkedCopyID field to select the relationship instead of the item ID field (localKey)
	 *
	 * @param \DataObject|int $itemOrID
	 * @param array|string    $states
	 * @return \SQLSelect
	 */
	public function linkedVersions($itemOrID, $states) {
		$itemID = ($itemOrID instanceof \DataObject) ? $itemOrID->ID : $itemOrID;

		$query = new \SQLSelect("*", "\"{$this->joinTable}\"");
		$query->addWhere([
			self::VersionedLinkFieldName => $itemID,
		]);
		if ($states) {
			$query->addWhere($this->statesFilter($states));
		}
		return $query;
	}

	/**
	 * Check if this list already has the item.
	 *
	 * @param int|\DataObject $itemOrID
	 * @param string|array    $states what states to check for existing relationship, e.g. self.StatusPublished, self.AnyStatus
	 * @return \SQLSelect
	 */
	protected function existingQuery($itemOrID, $states = []) {
		$itemID = ($itemOrID instanceof \DataObject)
			? $itemOrID->ID
			: $itemOrID;

		// With the current query, simply add the foreign and local conditions
		// The query can be a bit odd, especially if custom relation classes
		// don't join expected tables (@see Member_GroupSet for example).
		$query = new \SQLSelect("*", "\"{$this->joinTable}\"");
		$query->addWhere(array(
			"\"{$this->joinTable}\".\"{$this->localKey}\"" => $itemID,
		));
		if ($states) {
			$query->addWhere($this->statesFilter($states));
		}
		return $query;
	}


	/**
	 * Return a parameterised filter for selecting by states,
	 *
	 * @param $states
	 * @return array e.g [ "VersionedStatus in (?, ?)" => [ 'LiveCopy', 'Published' ] ]
	 */
	protected function statesFilter($states) {
		$states = is_array($states) ? $states : [$states];
		return [
			static::VersionedStatusFieldName . ' in (' . DB::placeholders($states) . ')' => $states,
		];
	}

	/**
	 *
	 *
	 * Update the given item in the list so that is flagged as 'Archived'. This will be checked by versioned_many_many trait exhibiting extensions
	 * to ensure that 'Archived' items no longer get included in filter for display if in Stage mode.
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

		/** @var \DataObject|\Versioned $item */
		if ($item = \DataObject::get($this->dataClass)->byID($itemID)) {
			// if we are removing a record which a LiveCopy we need to restore the data from the LiveCopy to the 'Editing' record.
			$linkedQuery = $this->linkedVersions($itemID, self::StatusLiveCopy);

			// try and get a relationship for the Live Copy of the record
			if ($linkedRow = $linkedQuery->firstRow()->execute()->record()) {

				if ($linkedID = $linkedRow[ $this->localKey ]) {

					/** @var \DataObject|\Versioned $linkedModel */
					if ($linkedModel = \DataObject::get($this->dataClass)->byID($linkedID)) {

						$liveData = $linkedModel->toMap();

						// we have the live copy now, copy back the data to the existing item
						$item->update($liveData);

						// save old data to model
						$item->write();

						// write item model to stage
						$item->writeToStage('Stage');

						// publish the item again with the 'old' details
						$item->writeToStage('Live');

						// delete the LiveCopy as we are done with it
						$linkedModel->deleteFromStage('Live');

					}
				}
				$deleter = $linkedQuery->toDelete();
				$deleter->execute();

				// update the relationship to the edited item to 'Published' so is visible on Stage and Live again
				$extraData = [
					VersionedManyManyList::VersionedStatusFieldName => VersionedManyManyList::StatusPublished,
				];
				$this->updateItemExtraData($itemID, $extraData);

			} else {
				// no copies etc, just on stage so delete it if we were the creator
				if ($data = $this->findRelationship($itemID, self::StatusStaged)) {
					if (isset($data[ self::VersionedMemberFieldName ]) && $data[ self::VersionedMemberFieldName ] == \Member::currentUserID()) {
						$item->delete();
					}
				}

				// tidy up the relationship we don't want it anymore
				parent::removeByID($itemID);
			}
		}
	}

	/**
	 * Find the first relationship record and return it as an array.
	 *
	 * @param \DataObject|int $itemOrID
	 * @param array|string    $states
	 * @return array
	 */
	public function findRelationship($itemOrID, $states = []) {
		return $this->existingQuery($itemOrID, $states)->firstRow()->execute()->record();
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