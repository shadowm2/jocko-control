<?php

/*
|--------------------------------------------------------------------------
| Mirrored shop data
|--------------------------------------------------------------------------
|
| The read-only copy of a local installation's business data. Each entity is
| one table of jocko-shop. The `fields` list is an allowlist: a field a shop
| sends that is not listed here is dropped when the event is projected, so a
| secret (password, OTP, token, setting) can never be stored even if a shop
| sent it by mistake. Never add credentials here.
|
| Field types: int, string, text, decimal, bool, date, datetime, enum, or
| ref:<entity> for a foreign key to another entity of the same shop.
| `id`, `created_at`, `updated_at` and `deleted_at` are implicit.
|
| label    Fields joined to name a record in lists and links.
| columns  Fields shown in the record list (the label is always first).
| children Entities that point back here, as entity => foreign key field.
| parent   Foreign key kept in an indexed column (parent_id) so a parent's
|          children can be found and added up without reading every row.
| amount   Field kept in an indexed column (amount) to add up per parent.
| date     Fields tried in order for the date a record counts for (dated_at);
|          created_at when none is set.
|
*/

return [

    'entities' => [

        'order' => [
            'group' => 'sales',
            'label' => ['order_number'],
            'columns' => ['user_id', 'user_car_id', 'status', 'created_at'],
            'fields' => [
                'slug' => 'string',
                'user_id' => 'ref:user',
                'user_car_id' => 'ref:user_car',
                'warehouse_id' => 'ref:warehouse',
                'order_number' => 'string',
                'status' => 'enum',
                'description' => 'text',
            ],
            'children' => ['order_item' => 'order_id', 'payment' => 'order_id'],
        ],

        'order_item' => [
            'group' => 'sales',
            'parent' => 'order_id',
            'amount' => 'total',
            'label' => ['id'],
            'columns' => ['order_id', 'item_id', 'quantity', 'unit_price', 'total'],
            'fields' => [
                'slug' => 'string',
                'order_id' => 'ref:order',
                'item_id' => 'ref:item',
                'unit_id' => 'ref:unit',
                'quantity' => 'decimal',
                'unit_price' => 'decimal',
                'total' => 'decimal',
            ],
        ],

        'payment' => [
            'group' => 'sales',
            'parent' => 'order_id',
            'amount' => 'amount',
            'date' => ['paid_at'],
            'label' => ['id'],
            'columns' => ['order_id', 'amount', 'method', 'paid_at'],
            'fields' => [
                'slug' => 'string',
                'order_id' => 'ref:order',
                'amount' => 'decimal',
                'method' => 'enum',
                'paid_at' => 'date',
                'description' => 'text',
                'cheque_number' => 'string',
                'cheque_bank' => 'string',
                'cheque_due_at' => 'date',
                'cheque_status' => 'enum',
            ],
        ],

        'user' => [
            'group' => 'people',
            'label' => ['first_name', 'last_name'],
            'columns' => ['type', 'mobile', 'email'],
            'fields' => [
                'slug' => 'string',
                'type' => 'enum',
                'first_name' => 'string',
                'last_name' => 'string',
                'email' => 'string',
                'mobile' => 'string',
            ],
            'children' => ['user_car' => 'user_id', 'order' => 'user_id'],
        ],

        'user_car' => [
            'group' => 'people',
            'label' => ['id'],
            'columns' => ['user_id', 'car_id', 'color_id', 'manufactured_at'],
            'fields' => [
                'slug' => 'string',
                'user_id' => 'ref:user',
                'car_id' => 'ref:car',
                'color_id' => 'ref:color',
                'manufactured_at' => 'date',
                'description' => 'text',
            ],
            'children' => ['order' => 'user_car_id'],
        ],

        'car_company' => [
            'group' => 'vehicles',
            'label' => ['name'],
            'columns' => ['slug'],
            'fields' => ['slug' => 'string', 'name' => 'string'],
            'children' => ['car' => 'car_company_id'],
        ],

        'car' => [
            'group' => 'vehicles',
            'label' => ['name'],
            'columns' => ['car_company_id'],
            'fields' => ['slug' => 'string', 'car_company_id' => 'ref:car_company', 'name' => 'string'],
        ],

        'color' => [
            'group' => 'vehicles',
            'label' => ['name'],
            'columns' => ['title', 'hex'],
            'fields' => ['slug' => 'string', 'name' => 'string', 'title' => 'string', 'hex' => 'string'],
        ],

        'item' => [
            'group' => 'inventory',
            'label' => ['name'],
            'columns' => ['category_id', 'unit_group_id', 'is_active'],
            'fields' => [
                'category_id' => 'ref:category',
                'unit_group_id' => 'ref:unit_group',
                'name' => 'string',
                'slug' => 'string',
                'description' => 'text',
                'is_active' => 'bool',
            ],
            'children' => ['item_brand' => 'item_id', 'warehouse_item' => 'item_id', 'stock_movement' => 'item_id'],
        ],

        'item_brand' => [
            'group' => 'inventory',
            'label' => ['id'],
            'columns' => ['item_id', 'brand_id', 'sku', 'purchase_price', 'sale_price'],
            'fields' => [
                'slug' => 'string',
                'item_id' => 'ref:item',
                'brand_id' => 'ref:brand',
                'sku' => 'string',
                'barcode' => 'string',
                'part_number' => 'string',
                'purchase_price' => 'decimal',
                'sale_price' => 'decimal',
                'is_active' => 'bool',
            ],
        ],

        'brand' => [
            'group' => 'inventory',
            'label' => ['name'],
            'columns' => ['is_active'],
            'fields' => ['slug' => 'string', 'name' => 'string', 'description' => 'text', 'is_active' => 'bool'],
            'children' => ['item_brand' => 'brand_id'],
        ],

        'category' => [
            'group' => 'inventory',
            'label' => ['name'],
            'columns' => ['type', 'parent_id', 'is_active'],
            'fields' => [
                'parent_id' => 'ref:category',
                'name' => 'string',
                'slug' => 'string',
                'type' => 'enum',
                'depth' => 'int',
                'description' => 'text',
                'icon' => 'string',
                'is_active' => 'bool',
                'sort_order' => 'int',
            ],
            'children' => ['category' => 'parent_id', 'item' => 'category_id'],
        ],

        'unit_group' => [
            'group' => 'inventory',
            'label' => ['name'],
            'columns' => ['slug'],
            'fields' => ['slug' => 'string', 'name' => 'string'],
            'children' => ['unit' => 'unit_group_id'],
        ],

        'unit' => [
            'group' => 'inventory',
            'label' => ['name'],
            'columns' => ['symbol', 'unit_group_id', 'conversion_factor', 'is_base'],
            'fields' => [
                'slug' => 'string',
                'name' => 'string',
                'symbol' => 'string',
                'unit_group_id' => 'ref:unit_group',
                'conversion_factor' => 'decimal',
                'is_base' => 'bool',
                'is_active' => 'bool',
            ],
        ],

        'warehouse' => [
            'group' => 'inventory',
            'label' => ['name'],
            'columns' => ['is_active'],
            'fields' => ['slug' => 'string', 'name' => 'string', 'description' => 'text', 'is_active' => 'bool'],
            'children' => ['warehouse_item' => 'warehouse_id'],
        ],

        'warehouse_item' => [
            'group' => 'inventory',
            'label' => ['id'],
            'columns' => ['warehouse_id', 'item_id', 'quantity', 'min_quantity', 'max_quantity'],
            'fields' => [
                'slug' => 'string',
                'warehouse_id' => 'ref:warehouse',
                'item_id' => 'ref:item',
                'quantity' => 'decimal',
                'min_quantity' => 'decimal',
                'max_quantity' => 'decimal',
            ],
        ],

        'stock_movement' => [
            'group' => 'inventory',
            'label' => ['id'],
            'columns' => ['type', 'item_id', 'quantity', 'balance_after', 'created_at'],
            'fields' => [
                'slug' => 'string',
                'item_id' => 'ref:item',
                'warehouse_item_id' => 'ref:warehouse_item',
                'user_id' => 'ref:user',
                'from_warehouse_id' => 'ref:warehouse',
                'to_warehouse_id' => 'ref:warehouse',
                'quantity' => 'decimal',
                'unit_cost' => 'decimal',
                'total_cost' => 'decimal',
                'balance_after' => 'decimal',
                'type' => 'enum',
                'reference_type' => 'string',
                'reference_id' => 'int',
                'description' => 'text',
            ],
        ],

        'supplier' => [
            'group' => 'purchasing',
            'label' => ['slug'],
            'columns' => ['user_id', 'phone', 'city_id', 'is_active'],
            'fields' => [
                'slug' => 'string',
                'user_id' => 'ref:user',
                'phone' => 'string',
                'city_id' => 'ref:city',
                'address' => 'text',
                'postal_code' => 'string',
                'website' => 'string',
                'tax_number' => 'string',
                'is_active' => 'bool',
                'notes' => 'text',
            ],
            'children' => ['purchase' => 'supplier_id'],
        ],

        'purchase' => [
            'group' => 'purchasing',
            'date' => ['ordered_at'],
            'label' => ['order_number'],
            'columns' => ['supplier_id', 'warehouse_id', 'status', 'ordered_at'],
            'fields' => [
                'slug' => 'string',
                'supplier_id' => 'ref:supplier',
                'warehouse_id' => 'ref:warehouse',
                'order_number' => 'string',
                'status' => 'enum',
                'ordered_at' => 'datetime',
                'expected_at' => 'datetime',
                'received_at' => 'datetime',
                'notes' => 'text',
                'created_by' => 'ref:user',
            ],
            'children' => ['purchase_item' => 'purchase_id'],
        ],

        'purchase_item' => [
            'group' => 'purchasing',
            'parent' => 'purchase_id',
            'amount' => 'total',
            'label' => ['id'],
            'columns' => ['purchase_id', 'item_id', 'quantity', 'received_quantity', 'unit_price', 'total'],
            'fields' => [
                'slug' => 'string',
                'purchase_id' => 'ref:purchase',
                'item_id' => 'ref:item',
                'unit_id' => 'ref:unit',
                'quantity' => 'decimal',
                'received_quantity' => 'decimal',
                'unit_price' => 'decimal',
                'discount' => 'decimal',
                'tax' => 'decimal',
                'total' => 'decimal',
            ],
        ],

        'media' => [
            'group' => 'inventory',
            'parent' => 'owner_id',
            'label' => ['original_name'],
            'columns' => ['owner_type', 'owner_id', 'collection', 'mime_type'],
            // Pictures of other records. The shop sends a downscaled preview of the
            // file separately (POST /api/v1/sync/files), keyed by this record's id.
            'fields' => [
                'owner_type' => 'string',
                'owner_id' => 'int',
                'collection' => 'string',
                'mime_type' => 'string',
                'order' => 'int',
                'original_name' => 'string',
            ],
        ],

        'country' => [
            'group' => 'geography',
            'label' => ['name'],
            'columns' => ['code', 'code3', 'phone_code', 'is_active'],
            'fields' => ['name' => 'string', 'code' => 'string', 'code3' => 'string', 'phone_code' => 'string', 'is_active' => 'bool'],
            'children' => ['province' => 'country_id'],
        ],

        'province' => [
            'group' => 'geography',
            'label' => ['name'],
            'columns' => ['country_id', 'is_active'],
            'fields' => ['country_id' => 'ref:country', 'name' => 'string', 'slug' => 'string', 'is_active' => 'bool'],
            'children' => ['city' => 'province_id'],
        ],

        'city' => [
            'group' => 'geography',
            'label' => ['name'],
            'columns' => ['province_id', 'is_active'],
            'fields' => ['province_id' => 'ref:province', 'name' => 'string', 'slug' => 'string', 'is_active' => 'bool'],
        ],

    ],

];
