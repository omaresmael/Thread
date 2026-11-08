<?php
// php import.php <sqlite file> <sql file>: runs the SQL file against the SQLite database.
[, $database, $sql] = $argv;
(new PDO("sqlite:{$database}"))->exec(file_get_contents($sql));
