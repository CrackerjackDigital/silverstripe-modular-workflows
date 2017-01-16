<?php
namespace Modular\Collections;

use DB;
use InvalidArgumentException;
use Modular\Application;
use Modular\Interfaces\VersionedModel as VersionedModelInterface;
use Modular\Interfaces\VersionedRelationship;
use Modular\Model;
use Modular\VersionedModel;

/**
 * VersionedManyManyList keeps track of many_many relationships internally to the same table (so no _versions table required), and
 * uses a Status field on the many_many_extraFields (MMEF) to determine what stage the records can be seen on (Stage, Live), coupled to
 * logic which updates the MMEF when items are added and removed from the list. DataObjects which this list tracks should be instances
 * of VersionedModel, not DataObject.
 *
 * -    When a new record is added to the list it will be written to Stage and added with a MMEF status of 'Staged' and so
 *      only visible on Stage and in CMS.
 *
 * -    When an existing model in the list is 'added' which already has a published version:
 *
 *      A copy of the model is created and put on Live with the original values
 *
 *      A relationship record with an MMEF status of 'Live' is created, so is only visible on Live
 *      and a link (id) to the 'Staged' record for tracking and removal later.
 *
 * -    When a Page or VersionedModel is published all items in this list are checked (in VersionedModel.onAfterPublish)
 *
 *      Records with an MMEF status of 'Live' are removed from Live (updated to MMEF status 'Archived' and removed from Live) and
 *
 *      Records with a status of 'Staged' are published to Live and their MMEF status updated to 'Published'.
 *
 * -    When a model is removed from the list (.removeByID method) and there is an existing 'Live' record linked to that model:
 *
 *      Data from the Live record is copied back to the Staged record
 *
 *      The Live record is deleted and the relationship MMEF data updated to 'Published'.
 *
 * -    When a model is removed and there is no Live record for the model being removed
 *      then it is just deleted along with it's relationship record.
 *
 * @package Modular\Collections
 */
class VersionedManyManyList extends \ManyManyList implements VersionedRelationship {
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
	 * Removes the LiveCopy from Stage by deleting it from Stage and flagging the relationship as 'Archived', writes the Staged version to Live and updates
	 * the relationship to 'Published'.
	 */
	public function publishItems() {
		$oldStage = \Versioned::current_stage();
		\Versioned::reading_stage('Stage');

		foreach ($this as $model) {
			if ($this->hasLinkedItem($model, self::StatusLiveCopy)) {
				// remove linked items
				$this->archiveLinkedItems($model);
			}
			// update existing relationship to 'Published'
			$this->updateItemExtraData(
				$model,
				[
					self::VersionedStatusFieldName => self::StatusPublished,
					self::VersionedMemberFieldName => null,
				    self::VersionedNumberFieldName => Application::get_current_page()->Version
				]
			);
			// write the model stages assigned to the state (e.g. 'Stage', 'Live' )
			$this->writeToStateStages($model, self::StatusPublished);

			// remove from stages which destination state doesn't have
			$this->removeFromNonStateStages($model, self::StatusPublished);

		}
		\Versioned::reading_stage($oldStage);
	}

	/**
	 * Restore any 'Archived' items to Stage
	 */
	public function rollbackItems() {
		// find all
	}

	/**
	 * Add the model to all stages for a state. Ignores 'internal' states, e.g. 'Archived'.
	 *
	 * @param Model|\Versioned $model
	 * @param string|array     $states
	 */
	public function writeToStateStages($model, $states) {
		$stages = static::stages_for_states($states);
		foreach ($stages as $stage) {
			if (!static::is_internal_stage($stage)) {
				$model->writeToStage($stage);
			}
		}
	}

	/**
	 * Remove model from all stages not assigned to any of the provided states. Ignores 'internal' states, e.g. 'Archived'.
	 *
	 * @param Model|\Versioned $model
	 * @param string|array     $states
	 */
	public function removeFromNonStateStages($model, $states) {
		$nonStateStages = array_diff(
			array_reduce(
				static::state_stage_map(),
				function ($previousStages, $stages) {
					return array_merge($previousStages, $stages);
				},
				[]
			),
			self::stages_for_states($states)
		);
		foreach ($nonStateStages as $stage) {
			if (!self::is_internal_stage($stage)) {
				$model->deleteFromStage($stage);
			}
		}
	}

	/**
	 * Return true if the stage is a 'real' one (e.g. Stage or Live) or false if it's an internal one used only for state tracking (e.g. 'Archive')
	 *
	 * @param $stage
	 * @return bool
	 */
	public static function is_internal_stage($stage) {
		return in_array($stage, [self::StatusArchived]);
	}

	/**
	 * Remove linked items from the list by updating relationship to 'archived' and removing the model from Live.
	 *
	 * @param \DataObject|\Versioned|int $forModel
	 * @param array|string               $states remove in these states
	 */
	public function archiveLinkedItems($forModel, $states = VersionedManyManyList::StatusLiveCopy) {
		// remove the linked copies from Live by updating to 'Archived' and deleting the model from Live
		if ($linked = $this->linkedItems($forModel, $states)->execute()) {
			foreach ($linked as $rel) {
				// find the 'live' model
				if ($live = $forModel::get()->byID($rel[ $this->localKey ])) {
					// and delete from stage
					$live->deleteFromStage('Live');
				}
			}
			// now update all the relationships for the model to 'Archived'
			$this->updateLinkedVersions($forModel->ID, VersionedManyManyList::StatusArchived, VersionedManyManyList::StatusLiveCopy);
		}
	}

	/**
	 * This is used to build the filter criteria for the list, we modify default behaviour so we filter to Stages for the current state
	 * (e.g. with a status of Published shows on Stage and Live, a status of LiveCopy will only show on Live).
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
				// none provided, leave only states in map which have current stage as an option
				// no stage will come out as 'Archive' and so fail the filter as expected.
				$states = $this->states_for_stage($currentStage);

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
	 * @param \DataObject|\Versioned|int $itemOrID
	 * @param array                      $extraData A map of additional columns to insert into the joinTable, such as VersionedStatus
	 */
	public function add($itemOrID, $extraData = array()) {
		$item = ($itemOrID instanceof \DataObject) ? $itemOrID : \DataObject::get($this->dataClass)->byID($itemOrID);

		// deal with VersionedModel records
		if ($item instanceof VersionedModelInterface) {
			// check for existing linked versioned with a 'Published' status as if it exists we don't need to create a new one.
			$hasLinkedVersions = $this->linkedItems($item, self::StatusPublished)->count();

			// check for an existing published version, this means we need to spin off a new one with those details if one does exist
			$hasPublished = $this->hasExisting($item, self::StatusPublished);

			// if no linked version exists but a published version exists we need to create a 'Live' copy.
			if (!$hasLinkedVersions && $hasPublished) {

				if ($item instanceof VersionedModel) {
					// get the 'old' values from before updates to the model where applied
					$originalValues = @$item->backpropData('changing')['original'] ?: [];

					// create a copy of the published VersionedModel and its relationships initialised with the 'old' values
					$live = $this->duplicateItem($item, true, $originalValues);
					// put on stage so can find & manipulate easier
					$live->writeToStage('Stage');

					// put the copy on Live
					$live->writeToStage('Live');

					// unpublish the item being edited from Live
					$item->deleteFromStage('Live');

				} else {
					// get the current live page so we can add a relationship record to it
					$live = \DataObject::get($this->parentClass())->byID($this->getForeignID());

				}
				// setup the relationship for the new 'live' record and backlink to the 'real' record, set member ID on it and the current page version
				$liveExtraData = [
					self::VersionedStatusFieldName => self::StatusLiveCopy,         // only show copy on live
					self::VersionedLinkFieldName   => $item->ID,                    // link back to original item
					self::VersionedMemberFieldName => \Member::currentUserID(),
					self::VersionedNumberFieldName => Application::get_current_page()->Version
				];

				// copy data from the existing relationship, e.g. Sort fields etc
				$existing = $this->existingQuery($item, self::StatusPublished);
				if ($first = $existing->firstRow()) {
					if ($record = $existing->execute()->record()) {
						$liveExtraData = array_merge(
							$record,
							$liveExtraData
						);
					}
				}
				// unset the ID so add doesn't just update the existing one.
				unset($liveExtraData['ID']);

				// add should set this but here we go
				$liveExtraData[ $this->localKey ] = $live->ID;

				// add a new relationship to the 'live' record with a backlink to the 'real' item which is being edited
				// this link will be used when the item is published to identify temporary live records and remove them.
				parent::add(
					$live,
					$liveExtraData
				);

				// finished creating live copy
			}

			// add or update existing relationship to status 'Staged' so we can keep on editing it without impacting the live site,
			// also update the VersionedMemberID to current member ID and the version to the version of the page being edited.
			$extraData = array_merge(
				[
					self::VersionedStatusFieldName => self::StatusStaged,
					self::VersionedMemberFieldName => \Member::currentUserID(),
					self::VersionedNumberFieldName => Application::get_current_page()->Version
				],
				$extraData
			);

		}
		// add the record to the list, possibly with amended extra data for Staged version.
		parent::add($item, $extraData);
	}

	/**
	 * Creates new relationships with a status of 'Staged'
	 * @param array $idList
	 */
	public function setByIDList($idList) {
		$has = array();

		// Index current data
		foreach ($this->column() as $id) {
			$has[ $id ] = true;
		}

		// Keep track of items to delete
		$itemsToDelete = $has;

		// add items in the list
		// $id is the database ID of the record
		if ($idList) {
			foreach ($idList as $id) {
				unset($itemsToDelete[ $id ]);
				if ($id) {
					// add or update relationship to/with a status of 'Staged'
					$stageExtraData = [
						self::VersionedStatusFieldName => self::StatusStaged,
						self::VersionedMemberFieldName => \Member::currentUserID(),
					    self::VersionedNumberFieldName => Application::get_current_page()->Version
					];

					$this->add($id, $stageExtraData);
				}
			}
		}

		// Remove any items that haven't been mentioned
		$this->removeMany(array_keys($itemsToDelete));
	}

	public function removeMany($ids) {
		foreach ($ids as $id) {
			$this->removeByID($id);
		}
		return $this;
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
			$linkedQuery = $this->linkedItems($itemID, self::StatusLiveCopy);

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
				    VersionedManyManyList::VersionedNumberFieldName => Application::get_current_page()->Version
				];
				$this->updateItemExtraData($itemID, $extraData);

			} else {
				// no copies etc, just on stage so delete it if we were the creator
				if ($data = $this->findRelationship($itemID, self::StatusStaged)) {
					if (isset($data[ self::VersionedMemberFieldName ]) && $data[ self::VersionedMemberFieldName ] == \Member::currentUserID()) {
						// if it's a versioned model then delete it
						if ($item instanceof VersionedModel) {
							$item->delete();
						}
					}
				}

				// tidy up the relationship we don't want it anymore
				parent::removeByID($itemID);
			}
		}
	}

	/**
	 * Return a copy of provided item using provided data.
	 *
	 * @param \DataObject|\Versioned|VersionedModel $item
	 * @param bool                                  $writeAndDuplicateRelationships
	 * @param array                                 $data          to initialise copy with
	 * @param array                                 $excludeFields don't update the copy with these fields
	 * @return \DataObject|\Modular\backprop|\Versioned
	 */
	public function duplicateItem($item, $writeAndDuplicateRelationships = true, $data = [], $excludeFields = ['ID', 'ClassName', 'Created', 'Version']) {
		// never these
		unset($data['ID']);
		unset($data['ClassName']);

		/** @var \DataObject|\Versioned|VersionedModel $copy */

		$copy = $item->duplicate($writeAndDuplicateRelationships);

		// transform to key => null for array_diff_key
		$excludeFields = array_fill_keys(
			$excludeFields,
			null
		);

		// prepare update with all fields including those which don't exist in the data which
		// are needed to clear values which weren't set on the original
		// $data may not contain all the fields if no value was set originaly.
		$updateWith = array_merge(
			array_diff_key(
				array_fill_keys(
					array_keys($item->toMap()),
					null
				),
				$excludeFields
			),
			$data
		);
		$copy->update($updateWith);
		if ($writeAndDuplicateRelationships) {
			$copy->write();
		}
		return $copy;
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

		$linkedVersions = $this->linkedItems($itemID, $ifInStates);
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

	public function hasLinkedItem($model, $states) {
		return $this->linkedItems($model, $states)->count();
	}

	/**
	 * Return items in this list of relationships which have a 'link id' of the provided item ID and which are in the provided state.
	 * Similair to existingQuery but uses the LinkedCopyID field to select the relationship instead of the item ID field (localKey)
	 *
	 * @param \DataObject|int $itemOrID
	 * @param array|string    $states
	 * @return \SQLSelect
	 */
	public function linkedItems($itemOrID, $states) {
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
	 * Return a parameterised filter for selecting by states, or empty if no states.
	 *
	 * @param $states
	 * @return array e.g [ "VersionedStatus in (?, ?)" => [ 'LiveCopy', 'Published' ] ]
	 */
	protected function statesFilter($states) {
		if ($states = is_array($states) ? $states : [$states]) {
			$filter = [
				static::VersionedStatusFieldName . ' in (' . DB::placeholders($states) . ')' => $states,
			];
		} else {
			$filter = [];
		}
		return $filter;
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
	 * @param \DataObject|\Versioned $itemOrID
	 * @param array                  $extraData to set on the relationship(s)
	 */
	public function updateItemExtraData($itemOrID, array $extraData) {
		$itemID = ($itemOrID instanceof \DataObject) ? $itemOrID->ID : $itemOrID;

		$query = new \SQLUpdate("\"{$this->joinTable}\"");

		if ($filter = $this->foreignIDWriteFilter($this->getForeignID())) {
			$query->setWhere($filter);
		} else {
			user_error("Can't update item extra data as no ID", E_USER_WARNING);
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

	public static function states_for_stage($stage) {
		return array_keys(
			array_filter(
				self::state_stage_map(),
				function ($stages) use ($stage) {
					return in_array($stage, $stages);
				}
			)
		);
	}

	/**
	 * @param array|string $states e.g. self::StatusPublished
	 * @return array
	 */
	public static function stages_for_states($states) {
		$states = is_array($states) ? $states : [$states];

		// filter to the states we're interested
		$map = array_intersect_key(
			self::state_stage_map(),
			array_flip($states)
		);
		return array_reduce(
			$map,
			function ($prev, $stages) {
				return array_merge($prev, $stages);
			},
			[]
		);

	}

}