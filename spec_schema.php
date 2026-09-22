<?php

const SPEC_SCHEMA = [
    'pc' => [
        'casing'         => ['Casing', 'e.g. Boston EK-03B/03C'],
        'processor'      => ['Processor', 'e.g. Alder Lake i5-12400 18Mb'],
        'motherboard'    => ['Motherboard', 'e.g. Gigabyte B760M DS3H AX'],
        'ram'            => ['RAM', 'e.g. Kingston KVR DDR4 8Gb 3200Mhz x2'],
        'ssd'            => ['SSD', 'e.g. Kingston 256Gb SATA KC600 3.0'],
        'psu'            => ['PSU', 'e.g. Inplay 550W GS550-Ultra'],
        'gpu'            => ['GPU', 'e.g. Integrated / GTX 1650'],
        'spec_note'      => ['Specs Note', 'e.g. with external Bluetooth and WiFi Antenna'],
    ],
    'laptop' => [
        'brand'          => ['Brand', 'e.g. Lenovo'],
        'model'          => ['Model', 'e.g. ThinkPad E14'],
        'processor'      => ['Processor', 'e.g. Ryzen 5 5500U'],
        'storage'        => ['Storage', 'e.g. 512Gb SSD'],
    ],
    'projector' => [
        'brand'          => ['Brand', 'e.g. Epson'],
        'class'          => ['Class/Type', 'e.g. EB-X36'],
        'remote'         => ['Remote', 'e.g. Included'],
    ],
];

const SPEC_KIND_ITEM_TYPE_NAMES = [
    'pc'        => 'PC/System Unit',
    'laptop'    => 'Laptop',
    'projector' => 'Projector',
];

function spec_schema_key_for_type_name($typeName) {
    $typeNameLower = strtolower(trim((string)$typeName));
    foreach (SPEC_KIND_ITEM_TYPE_NAMES as $key => $name) {
        if (strtolower($name) === $typeNameLower) {
            return $key;
        }
    }
    return null;
}

function save_spec_values($conn, $table, $ownerColumn, $ownerId, array $specs) {
    $del = $conn->prepare("DELETE FROM $table WHERE $ownerColumn = ?");
    $del->bind_param('i', $ownerId);
    $del->execute();

    $nonEmpty = array_filter($specs, function ($v) { return trim((string)$v) !== ''; });
    if (empty($nonEmpty)) {
        return;
    }
    $ins = $conn->prepare("INSERT INTO $table ($ownerColumn, spec_key, spec_value) VALUES (?,?,?)");
    foreach ($nonEmpty as $key => $value) {
        $value = trim((string)$value);
        $ins->bind_param('iss', $ownerId, $key, $value);
        $ins->execute();
    }
}

function load_spec_values($conn, $table, $ownerColumn, $ownerId) {
    $stmt = $conn->prepare("SELECT spec_key, spec_value FROM $table WHERE $ownerColumn = ?");
    $stmt->bind_param('i', $ownerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[$row['spec_key']] = $row['spec_value'];
    }
    return $out;
}
