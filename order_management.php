<?php
require 'db.php';
require 'functions.php';
need_role($pdo, ['staff','manager','admin']);
go('office.php?tab=orders');
?>
