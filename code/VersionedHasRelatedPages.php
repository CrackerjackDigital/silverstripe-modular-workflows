<?php
namespace Modular\Relationships;

use Modular\Traits\versioned_many_many;

class VersionedHasRelatedPages extends HasRelatedPages {
	use versioned_many_many;

}