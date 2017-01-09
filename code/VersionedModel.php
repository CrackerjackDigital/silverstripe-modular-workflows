<?php
namespace Modular;

// only this needs importing as other traits are still in 'Modular\' namespace.
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
		'onBeforeWrite' => true,
		'onAfterWrite'  => true,
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
	 * Notify related models that this model changed.
	 */
	public function onBeforeWrite() {
		parent::onBeforeWrite();

		if ($info = $this->shouldBackProp(__FUNCTION__)) {
			if ($this->isChanged()) {
				$this->backprop(__FUNCTION__, $info, $this);
			}
		}
	}

	public function onAfterWrite() {
		parent::onAfterWrite();
		if ($info = $this->shouldBackProp(__FUNCTION__)) {
			$this->backprop(__FUNCTION__, $info, $this);
		}
	}

}