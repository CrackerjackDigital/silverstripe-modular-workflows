<?php
namespace Modular\GridList;

use Modular\Relationships\HasGridListBlocks;
use Modular\Traits\versioned_many_many;

class VersionedHasGridListBlocks extends HasGridListBlocks {
	use versioned_many_many;
}