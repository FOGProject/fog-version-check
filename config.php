<?php
/**
 * Read by version.php only.
 *
 * Nothing in FOGProject/fogproject reads this value -- it is served at
 * /version/version.php and predates the branch-aware endpoint in index.php.
 * It has not tracked a real release in a long time (stable passed 1.5.10.2254
 * while this still says 1.5.10.74), so it is carried here to keep the URL
 * answering rather than because anything depends on the number.
 *
 * It is TRACKED, so changing it is a commit and a pull, not an edit on the
 * host -- that is the point of the repo.
 */

define('VERSION', '1.5.10.74');
