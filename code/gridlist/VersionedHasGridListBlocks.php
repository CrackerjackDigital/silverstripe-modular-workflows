<?php
namespace Modular\GridList;

use Modular\Interfaces\VersionedRelationship;
use Modular\Relationships\HasGridListBlocks;
use Modular\Traits\versioned_many_many;

class VersionedHasGridListBlocks extends HasGridListBlocks implements VersionedRelationship {
	use versioned_many_many;
}