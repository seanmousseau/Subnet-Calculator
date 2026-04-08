<?php
declare(strict_types=1);

$base = dirname(__DIR__, 2) . '/Subnet-Calculator/includes/';

require $base . 'config.php';
require $base . 'functions-ipv4.php';
require $base . 'functions-ipv6.php';
require $base . 'functions-split.php';
require $base . 'functions-util.php';
require $base . 'functions-vlsm.php';
