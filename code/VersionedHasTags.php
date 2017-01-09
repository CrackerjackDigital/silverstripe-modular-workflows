<?php
namespace Modular\Relationships;

use Modular\Traits\versioned_many_many;

class VersionedHasTags extends HasTags {
	use versioned_many_many;
}