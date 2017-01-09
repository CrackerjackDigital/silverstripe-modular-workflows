<?php
if (isset($_REQUEST['flush'])) {
	if (!file_exists(__DIR__ . '/_manifest_exclude')) {
// require an autoloader for traits in this module if we're doing a dev/build
		spl_autoload_register(function ($class) {
			if (false !== strpos($class, 'Modular\\Traits\\')) {
				$class = current(array_reverse(explode('\\', $class)));
				// traits are all lower case
				if (strtolower($class) == $class) {
					// short-circuit 'traits' folder
					if (file_exists($path = __DIR__ . "/code/traits/$class.php")) {
						require_once($path);
						return;
					}
				}
			}
		});
	}
}
