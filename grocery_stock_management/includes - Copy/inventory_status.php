<?php
declare(strict_types=1);

function inventoryStatusFor(int $quantity, int $reorderLevel): array
{
    if ($quantity === 0) {
        return [
            'key' => 'out_of_stock',
            'label' => 'Out of Stock',
            'class' => 'status-out',
        ];
    }

    if ($quantity <= $reorderLevel) {
        return [
            'key' => 'low_stock',
            'label' => 'Low Stock',
            'class' => 'status-low',
        ];
    }

    return [
        'key' => 'in_stock',
        'label' => 'In Stock',
        'class' => 'status-in',
    ];
}
