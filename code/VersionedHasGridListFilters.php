<?php
namespace Modular\GridList;

use Modular\Relationships\HasGridListFilters;
use Modular\Traits\versioned_many_many;

class VersionedHasGridListFilters extends HasGridListFilters {
	use versioned_many_many;

}