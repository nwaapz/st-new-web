<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/price-import.php';

$withCars = ['skip_cars' => true, 'needs_car_setup' => false];
$noCars = ['skip_cars' => false, 'needs_car_setup' => true];
assert($withCars['needs_car_setup'] === false);
assert($noCars['needs_car_setup'] === true);

$rows = [
    ['index' => 0, 'skip_cars' => true, 'action' => 'update', 'price_text' => '1,000'],
    ['index' => 1, 'skip_cars' => false, 'action' => 'update', 'price_text' => '1,000'],
    ['index' => 2, 'skip_cars' => false, 'action' => 'create', 'price_text' => '1,000', 'category_id' => 0, 'pack_size' => 0],
];
foreach ($rows as &$row) {
    $skip = !empty($row['skip_cars']);
    $row['needs_car_setup'] = !$skip;
}
unset($row);

$filterOn = array_values(array_filter($rows, static fn (array $r): bool => !empty($r['needs_car_setup'])));
assert(count($filterOn) === 2, 'filter should show create + update without cars');

echo "OK\n";
