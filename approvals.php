<?php
require 'db.php';
require 'functions.php';
need_role($pdo, ['manager','admin']);
go('office.php?tab=approvals');
?>
