<?php
namespace Modular\GridList;

use Modular\Interfaces\VersionedRelationship;
use Modular\Relationships\HasGridListFilters;
use Modular\Traits\versioned_many_many;

class VersionedHasGridListFilters extends HasGridListFilters implements VersionedRelationship {
	use versioned_many_many;

}