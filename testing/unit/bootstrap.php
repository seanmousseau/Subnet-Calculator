<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2) . '/Subnet-Calculator/includes/';

require $base . 'config.php';
require $base . 'functions-ipv4.php';
require $base . 'functions-ipv6.php';
require $base . 'functions-split.php';
require $base . 'functions-util.php';
require $base . 'functions-vlsm.php';
require $base . 'functions-vlsm6.php';
require $base . 'functions-supernet.php';
require $base . 'functions-supernet6.php';
require $base . 'functions-ula.php';
require $base . 'functions-session.php';
require $base . 'functions-resolve.php';
require $base . 'functions-range.php';
require $base . 'functions-range6.php';
require $base . 'functions-tree.php';
require $base . 'functions-tree-diff.php';
require $base . 'functions-tree-presets.php';
