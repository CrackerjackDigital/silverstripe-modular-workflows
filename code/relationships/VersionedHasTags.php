<?php
namespace Modular\Relationships;

use Modular\Interfaces\VersionedRelationship;
use Modular\Traits\versioned_many_many;

class VersionedHasTags extends HasTags implements VersionedRelationship {
	use versioned_many_many;
}