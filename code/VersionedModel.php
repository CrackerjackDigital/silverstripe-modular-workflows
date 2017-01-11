<?php
namespace Modular;

// only this needs importing as other traits are still in 'Modular\' namespace.
use Modular\Collections\VersionedManyManyList;
use Modular\Interfaces\Arities;
use Modular\Interfaces\VersionedRelationship;
use Modular\Traits\custom_get;
use Modular\Traits\custom_many_many;

class VersionedModel extends \DataObject {
	use lang;
	use related;
	use backprop;
	use custom_get;
	use custom_many_many;

	/**
	 * This array should enabled events on the VersionedModel which need to be back-propogated to related models e.g. via
	 * belongs_many_many and has_one relationships from the model (as handled by backprop trait).
	 *
	 * Actually handling these events on this model and calling the backprop needs to be added to relevant event handler method in
	 * this class, there is no magic wiring, see onBeforeWrite below for an example which also checks only to backprop if the model
	 * has changed.
	 *
	 * @var array
	 */
	private static $backprop_events = [
		'changing' => 'eventData',           // save original record data to info for event
		'changed'  => true,                 // enable the event handler (this way can disable in config as can't otherwise remove)
	];

	private static $custom_list_class_name = 'Modular\Collections\VersionedDataList';

	private static $custom_many_many_list_class_name = 'Modular\Collections\VersionedManyManyList';

	/**
	 * Invoking a model returns itself.
	 *
	 * @return $this
	 */
	public function __invoke() {
		return $this;
	}

	public static function class_name() {
		return get_called_class();
	}

	/**
	 * VersionedModel lists are Modular\Workflows\VersionedDataList's.
	 *
	 * @param null   $callerClass
	 * @param string $filter
	 * @param string $sort
	 * @param string $join
	 * @param null   $limit
	 * @param string $containerClass
	 * @return mixed
	 */
	public static function get($callerClass = null, $filter = "", $sort = "", $join = "", $limit = null, $containerClass = 'DataList') {
		return static::custom_get($callerClass, $filter, $sort, $join, $limit, $containerClass);
	}

	/**
	 * Inject call to getCustomManyManyComponents so we get a VersionedManyManyList.
	 *
	 * @param string $componentName
	 * @param null   $filter
	 * @param null   $sort
	 * @param null   $join
	 * @param null   $limit
	 * @return mixed
	 */
	public function getManyManyComponents($componentName, $filter = null, $sort = null, $join = null, $limit = null) {
		return $this->getCustomManyManyComponents($componentName, $filter, $sort, $join, $limit);
	}

	/**
	 * Returns an array of original and current record values no matter what the event is.
	 * @param string $event ignored
	 * @return array
	 */
	public function eventData($event) {
		return [
			'original' => $this->original,
		    'updated' => $this->record
		];
	}

	/**
	 * Notify related models that this model is changing in the database via 'backprop' mechanism.
	 */
	public function onBeforeWrite() {
		parent::onBeforeWrite();
		$this->initBackprop();

		if ($this->isChanged()) {
			// notify related records this one is changing, this will save the original data
			$this->backprop('changing');
		}
	}

	/**
	 * Notify related models that this model changed in the database via 'backprop' mechanism.
	 * Tidies up any linked versions or 'LiveCopy' status relationships to Archived status, they become the records versioned history.
	 */
	public function onAfterWrite() {
		parent::onAfterWrite();

		// notify related records this one changed if we did a 'changing' notification
		if ($this->backpropData('changing')) {
			$this->backprop('changed');
		}
	}


}