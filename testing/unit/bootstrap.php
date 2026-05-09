<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2) . '/Subnet-Calculator/includes/';

require $base . 'config.php';
require $base . 'functions-ipv4.php';
require $base . 'functions-ipv6.php';
require $base . 'functions-split.php';
require $base . 'functions-util.php';
require $base . 'functions-type6.php';
require $base . 'functions-vlsm.php';
require $base . 'functions-vlsm6.php';
require $base . 'functions-supernet.php';
require $base . 'functions-supernet6.php';
require $base . 'functions-zone6.php';
require $base . 'functions-derive6.php';
require $base . 'functions-slaac6.php';
require $base . 'functions-rdns6.php';
require $base . 'functions-mapped6.php';
require $base . 'functions-embedded-v4.php';
require $base . 'functions-6to4.php';
require_once $base . 'functions-teredo.php';
// phpcs:disable PSR1.Files.SideEffects -- module include for ipv4_to_isatap_iid()/decode_isatap_iid().
require_once $base . 'functions-isatap.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for compute_6rd_delegation()/extract_6rd_ipv4().
require_once $base . 'functions-6rd.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for nat64_embed()/nat64_extract()/dns64_synthesize().
require_once $base . 'functions-nat64.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for plan_prefix_delegation().
require_once $base . 'functions-prefix-plan6.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for rfc3531_allocation_order()/rfc3531_apply().
require_once $base . 'functions-rfc3531.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for decode_multicast()/multicast_well_known_registry().
require_once $base . 'functions-multicast6.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for build_ssm_group()/decode_ssm_group().
require_once $base . 'functions-ssm6.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for build_embedded_rp_group()/decode_embedded_rp_group().
require_once $base . 'functions-embedded-rp6.php';
// phpcs:enable PSR1.Files.SideEffects
// phpcs:disable PSR1.Files.SideEffects -- module include for pmtu_compute().
require_once $base . 'functions-pmtu6.php';
// phpcs:enable PSR1.Files.SideEffects
require $base . 'functions-ula.php';
require $base . 'functions-session.php';
require $base . 'functions-resolve.php';
require $base . 'functions-range.php';
require $base . 'functions-range6.php';
require $base . 'functions-tree.php';
require $base . 'functions-tree-diff.php';
require $base . 'functions-tree-presets.php';
require $base . 'functions-bulk.php';
