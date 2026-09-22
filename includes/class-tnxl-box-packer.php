<?php
/**
 * TNXL Box Packer Class
 * Handles 3D bin packing for cart items.
 */

if (!defined('ABSPATH')) exit;

use DVDoug\BoxPacker\Packer;
use DVDoug\BoxPacker\Rotation;
use DVDoug\BoxPacker\Box as BoxInterface;
use DVDoug\BoxPacker\Item as ItemInterface;
use DVDoug\BoxPacker\PackedBox;

/**
 * Internal Box Class for Packer
 */
class TNXL_Box implements BoxInterface {
    private $reference;
    private $outerWidth;
    private $outerLength;
    private $outerDepth;
    private $emptyWeight;
    private $innerWidth;
    private $innerLength;
    private $innerDepth;
    private $maxWeight;

    public function __construct($data) {
        $this->reference   = $data['name'] ?? 'Box';
        $this->innerWidth  = (float) ($data['inner_width'] ?? 0);
        $this->innerLength = (float) ($data['inner_length'] ?? 0);
        $this->innerDepth  = (float) ($data['inner_depth'] ?? 0);
        
        $this->outerWidth  = max((float) ($data['outer_width'] ?? 0), $this->innerWidth ?: 0.1);
        $this->outerLength = max((float) ($data['outer_length'] ?? 0), $this->innerLength ?: 0.1);
        $this->outerDepth  = max((float) ($data['outer_depth'] ?? 0), $this->innerDepth ?: 0.1);
        
        $this->emptyWeight = (float) ($data['empty_weight'] ?? 0.1);
        $this->maxWeight   = (float) ($data['max_weight'] ?? 10);

    }

    public function getReference(): string { return $this->reference; }
    public function getOuterWidth(): int { return (int) ($this->outerWidth * 10); } // Convert to mm
    public function getOuterLength(): int { return (int) ($this->outerLength * 10); }
    public function getOuterDepth(): int { return (int) ($this->outerDepth * 10); }
    public function getEmptyWeight(): int { return (int) ($this->emptyWeight * 1000); } // Convert to g
    public function getInnerWidth(): int { return (int) ($this->innerWidth * 10); }
    public function getInnerLength(): int { return (int) ($this->innerLength * 10); }
    public function getInnerDepth(): int { return (int) ($this->innerDepth * 10); }
    public function getMaxWeight(): int { return (int) ($this->maxWeight * 1000); }
}

/**
 * Internal Item Class for Packer
 */
class TNXL_Packable_Item implements ItemInterface {
    private $description;
    private $width;
    private $length;
    private $depth;
    private $weight;
    private $keepFlat;
    private $product_id;

    public function __construct($product, $qty) {
        $this->description = $product->get_name();
        $this->product_id = (int) $product->get_id();
        // Product measurements use the store units. Normalize them to the
        // cm/kg units used by box definitions and the Thai Nexus API.
        $measurements = TNXL_Product::get_shipping_measurements($product);
        $this->width  = $measurements['width'];
        $this->length = $measurements['length'];
        $this->depth  = $measurements['height'];
        $this->weight = $measurements['weight'];
        $this->keepFlat = false; // Could be a meta field later
    }

    public function getDescription(): string { return $this->description; }
    public function getProductId(): int { return $this->product_id; }
    public function getWidth(): int { return (int) ($this->width * 10); }
    public function getLength(): int { return (int) ($this->length * 10); }
    public function getDepth(): int { return (int) ($this->depth * 10); }
    public function getWeight(): int { return (int) ($this->weight * 1000); }

    /**
     * BoxPacker 3.x Item API (removed in 4.0). Kept for sites with a stale vendor/ copy.
     */
    public function getKeepFlat(): bool {
        return $this->keepFlat;
    }

    public function getAllowedRotation(): Rotation {
        return $this->keepFlat ? Rotation::KeepFlat : Rotation::BestFit;
    }
}

/**
 * Packing Result Value Object
 */
class TNXL_Packing_Result {
    private $boxes = [];
    private $unpacked_items = [];
    private $errors = [];

    public function add_box($box_data) { $this->boxes[] = $box_data; }
    public function add_unpacked($item) { $this->unpacked_items[] = $item; }
    public function add_error($message) { $this->errors[] = $message; }
    
    public function get_boxes() { return $this->boxes; }
    public function get_unpacked_items() { return $this->unpacked_items; }
    public function get_errors() { return $this->errors; }
    public function has_errors() { return !empty($this->errors); }
    
    public function is_single_box() { return count($this->boxes) === 1 && empty($this->unpacked_items); }
    public function has_unpacked() { return !empty($this->unpacked_items); }
    
    public function get_primary_box() { return $this->boxes[0] ?? null; }
    
    /**
     * Get all boxes including oversized items as individual virtual boxes
     */
    public function get_all_shipment_boxes() {
        $all = $this->boxes;
        foreach ($this->unpacked_items as $item) {
            $box = [
                'name'   => __('Individual Item (Oversized)', 'thai-nexus-logistics'),
                'length' => (float) $item->getLength() / 10,
                'width'  => (float) $item->getWidth() / 10,
                'height' => (float) $item->getDepth() / 10,
                'weight' => (float) $item->getWeight() / 1000,
                'items'  => array(self::box_item_record($item->getDescription(), 1, method_exists($item, 'getProductId') ? $item->getProductId() : 0)),
            ];
            $all[] = $box;

        }
        return $all;
    }
}

/**
 * Main Packer Service
 */
class TNXL_Box_Packer {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Pack items into boxes
     * 
     * @param array $items Array of ['data' => WC_Product, 'quantity' => int]
     * @return TNXL_Packing_Result
     */
    public function pack_items($items) {
        if (class_exists('TNXL_Settings') && TNXL_Settings::is_actual_weight_only()) {
            return $this->pack_actual_weight_only($items);
        }

        $result = new TNXL_Packing_Result();
        $box_definitions = get_option('tnxl_box_definitions', []);

        $validation_result = $this->validate_items($items);
        if ($validation_result->has_errors()) {
            return $validation_result;
        }

        $boxed_result = $this->pack_boxed_product_cart($items);
        if ($boxed_result instanceof TNXL_Packing_Result) {
            return $boxed_result;
        }

        if (empty($box_definitions)) {
            return $this->fallback_naive($items);
        }

        try {
            $packer = new Packer();
            $box_objs = [];
            usort($box_definitions, static function ($a, $b) {
                $va = ((float) ($a['inner_length'] ?? 0)) * ((float) ($a['inner_width'] ?? 0)) * ((float) ($a['inner_depth'] ?? 0));
                $vb = ((float) ($b['inner_length'] ?? 0)) * ((float) ($b['inner_width'] ?? 0)) * ((float) ($b['inner_depth'] ?? 0));
                if ($va === $vb) {
                    return ((float) ($a['max_weight'] ?? 0)) <=> ((float) ($b['max_weight'] ?? 0));
                }
                return $va <=> $vb;
            });
            foreach ($box_definitions as $box_data) {
                $box_objs[] = new TNXL_Box($box_data);
                $packer->addBox($box_objs[count($box_objs)-1]);
            }

            // Identify items that are too large for ALL boxes before packing
            $packable_items = [];
            foreach ($items as $item_values) {
                $product = $item_values['data'];
                $qty = $item_values['quantity'];
                
                if (!$product->needs_shipping()) continue;

                $item_obj = new TNXL_Packable_Item($product, $qty);
                $fits_any = false;
                foreach ($box_objs as $box) {
                    // Check if item fits in box (volumetric + weight)
                    // BoxPacker uses rotation, so we check if any orientation fits
                    if ($item_obj->getWeight() <= $box->getMaxWeight() &&
                        (($item_obj->getWidth() <= $box->getInnerWidth() && $item_obj->getLength() <= $box->getInnerLength() && $item_obj->getDepth() <= $box->getInnerDepth()) ||
                         ($item_obj->getWidth() <= $box->getInnerLength() && $item_obj->getLength() <= $box->getInnerWidth() && $item_obj->getDepth() <= $box->getInnerDepth()) ||
                         ($item_obj->getWidth() <= $box->getInnerWidth() && $item_obj->getLength() <= $box->getInnerDepth() && $item_obj->getDepth() <= $box->getInnerLength()) ||
                         ($item_obj->getWidth() <= $box->getInnerDepth() && $item_obj->getLength() <= $box->getInnerWidth() && $item_obj->getDepth() <= $box->getInnerLength()) ||
                         ($item_obj->getWidth() <= $box->getInnerLength() && $item_obj->getLength() <= $box->getInnerDepth() && $item_obj->getDepth() <= $box->getInnerWidth()) ||
                         ($item_obj->getWidth() <= $box->getInnerDepth() && $item_obj->getLength() <= $box->getInnerLength() && $item_obj->getDepth() <= $box->getInnerWidth()))) {
                        $fits_any = true;
                        break;
                    }
                }

                if ($fits_any) {
                    $packer->addItem($item_obj, $qty);
                } else {
                    for ($i = 0; $i < $qty; $i++) {
                        $result->add_unpacked($item_obj);
                    }
                }
            }

            $packed_boxes = $packer->pack();

            foreach ($packed_boxes as $packed_box) {
                $box_type = $packed_box->box;
                $box_items = self::aggregate_packed_items($packed_box->items->asItemArray());

                $result->add_box([
                    'name'   => $box_type->getReference(),
                    'length' => (float) $box_type->getInnerLength() / 10,
                    'width'  => (float) $box_type->getInnerWidth() / 10,
                    'height' => (float) $box_type->getInnerDepth() / 10,
                    'weight' => (float) $packed_box->getWeight() / 1000,
                    'items'  => $box_items,
                ]);

            }
            
        } catch (\Throwable $e) {
            // If packing fails mid-way, fallback to naive for everything
            return $this->fallback_naive($items);
        }

        return $result;
    }

    /**
     * A cart containing one unique boxed product ships each unit in its retail box.
     *
     * @return TNXL_Packing_Result|null
     */
    private function pack_boxed_product_cart($items) {
        $product_id = null;
        $units = array();

        foreach ($items as $item_values) {
            $product = $item_values['data'] ?? null;
            if (!$product instanceof WC_Product || !$product->needs_shipping()) {
                continue;
            }

            $effective_id = $product->get_parent_id() ?: $product->get_id();
            if ($product_id !== null && $product_id !== $effective_id) {
                return null;
            }
            if (!TNXL_Product::is_boxed_product($product)) {
                return null;
            }

            $product_id = $effective_id;
            $quantity = max(0, absint($item_values['quantity'] ?? 0));
            for ($index = 0; $index < $quantity; $index++) {
                $units[] = $product;
            }
        }

        if ($product_id === null || empty($units)) {
            return null;
        }

        $result = new TNXL_Packing_Result();
        $max_boxes = max(1, (int) apply_filters('tnxl_max_shipment_boxes', 50));
        if (count($units) > $max_boxes) {
            $result->add_error(sprintf(
                /* translators: %d: maximum number of retail boxes per checkout */
                __('This boxed-product cart requires more than the supported maximum of %d shipment boxes.', 'thai-nexus-logistics'),
                $max_boxes
            ));
            return $result;
        }

        foreach ($units as $product) {
            $measurements = TNXL_Product::get_shipping_measurements($product);
            $result->add_box(array(
                'name'   => __('Retail Product Box', 'thai-nexus-logistics'),
                'length' => $measurements['length'],
                'width'  => $measurements['width'],
                'height' => $measurements['height'],
                'weight' => $measurements['weight'],
                'items'  => array(self::box_item_record($product->get_name(), 1, (int) $product->get_id())),
            ));
        }

        return $result;
    }

    /**
     * One parcel from product weights. Filler cube so LWH/5000 stays below actual kg.
     */
    private function pack_actual_weight_only($items) {
        $result = new TNXL_Packing_Result();
        $total_weight = 0.0;
        $labels = array();
        $all_document = true;
        $has_shipping = false;

        foreach ($items as $item_values) {
            $product = $item_values['data'] ?? null;
            if (!$product instanceof WC_Product || !$product->needs_shipping()) {
                continue;
            }
            $has_shipping = true;
            $qty = max(1, absint($item_values['quantity'] ?? 1));
            $measurements = TNXL_Product::get_shipping_measurements($product);
            $wt = $measurements['weight'];
            if ($wt <= 0) {
                $result->add_error(sprintf(
                    __('Product "%s" is missing required shipping weight.', 'thai-nexus-logistics'),
                    $product->get_name()
                ));
                continue;
            }
            $total_weight += $wt * $qty;
            $labels[] = self::box_item_record($product->get_name(), $qty, (int) $product->get_id());
            if (!TNXL_Product::is_document($product)) {
                $all_document = false;
            }
        }

        if ($result->has_errors()) {
            return $result;
        }
        if (!$has_shipping || $total_weight <= 0) {
            $result->add_error(__('No shippable items', 'thai-nexus-logistics'));
            return $result;
        }

        $dims = self::filler_dims_below_actual_weight($total_weight);
        $result->add_box(array(
            'name'   => __('Actual weight', 'thai-nexus-logistics'),
            'length' => $dims['length'],
            'width'  => $dims['width'],
            'height' => $dims['height'],
            'weight' => $total_weight,
            'items'  => $labels,
            'is_document' => $all_document,
        ));

        return $result;
    }

    /**
     * @return array{length:float,width:float,height:float}
     */
    public static function filler_dims_below_actual_weight(float $actual_kg): array {
        $weight = max(0.001, $actual_kg);
        $max_volume = $weight * 5000 * 0.5;
        $side = max(1, (int) floor(pow($max_volume, 1 / 3)));
        while ($side > 1 && (($side * $side * $side) / 5000) >= $weight) {
            $side -= 1;
        }
        if (($side * $side * $side) / 5000 >= $weight) {
            $side = 1;
        }
        return array('length' => (float) $side, 'width' => (float) $side, 'height' => (float) $side);
    }

    /**
     * Validate all items have dimensions and weight
     */
    private function validate_items($items) {
        $result = new TNXL_Packing_Result();
        foreach ($items as $item_values) {
            $product = $item_values['data'] ?? null;
            if (!$product instanceof WC_Product || !$product->needs_shipping()) continue;

            $measurements = TNXL_Product::get_shipping_measurements($product);
            $l = $measurements['length'];
            $w = $measurements['width'];
            $h = $measurements['height'];
            $wt = $measurements['weight'];

            if (!$l || !$w || !$h || !$wt) {
                // translators: %s: product name
                $error_msg = sprintf( __('Product "%s" is missing required shipping dimensions or weight.', 'thai-nexus-logistics'),
                    $product->get_name()
                );
                $result->add_error($error_msg);
            }
        }
        return $result;
    }

    /**
     * Naive fallback aggregation
     */
    private function fallback_naive($items) {
        $result = new TNXL_Packing_Result();
        $total_weight = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;

        foreach ($items as $item_values) {
            $product = $item_values['data'] ?? null;
            $qty = $item_values['quantity'];

            if (!$product instanceof WC_Product || !$product->needs_shipping()) continue;

            $measurements = TNXL_Product::get_shipping_measurements($product);
            $weight = $measurements['weight'];
            $length = $measurements['length'];
            $width = $measurements['width'];
            $height = $measurements['height'];

            $total_weight += ($weight * $qty);
            $max_length = max($max_length, $length);
            $max_width = max($max_width, $width);
            $max_height = max($max_height, $height);
        }

        if ($total_weight > 0) {
            $items_desc = [];
            foreach ($items as $item_values) {
                if ($item_values['data']->needs_shipping()) {
                    $items_desc[] = self::box_item_record(
                        $item_values['data']->get_name(),
                        max(1, (int) ($item_values['quantity'] ?? 1)),
                        (int) $item_values['data']->get_id()
                    );
                }
            }
            $result->add_box([
                'name'   => __('Standard Package (Fallback)', 'thai-nexus-logistics'),
                'length' => $max_length,
                'width'  => $max_width,
                'height' => $max_height,
                'weight' => $total_weight,
                'items'  => $items_desc,
            ]);

        }

        return $result;
    }

    /**
     * @param object[] $packed_items
     * @return array<int, array{product_id:int,description:string,quantity:int}>
     */
    private static function aggregate_packed_items(array $packed_items): array {
        $counts = array();
        foreach ($packed_items as $packed_item) {
            $description = method_exists($packed_item, 'getDescription') ? (string) $packed_item->getDescription() : '';
            $product_id = method_exists($packed_item, 'getProductId') ? (int) $packed_item->getProductId() : 0;
            $key = $product_id > 0 ? 'id:' . $product_id : 'name:' . $description;
            if (!isset($counts[$key])) {
                $counts[$key] = self::box_item_record($description, 0, $product_id);
            }
            $counts[$key]['quantity']++;
        }
        return array_values($counts);
    }

    /**
     * @return array{product_id:int,description:string,quantity:int}
     */
    public static function box_item_record(string $description, int $quantity, int $product_id = 0): array {
        return array(
            'product_id'  => $product_id,
            'description' => $description,
            'quantity'    => max(0, $quantity),
        );
    }

    /**
     * Human-readable names for shipment descriptions (legacy string items still work).
     *
     * @param mixed[] $items
     * @return string[]
     */
    public static function summarize_box_items(array $items): array {
        $names = array();
        foreach ($items as $item) {
            if (is_array($item)) {
                $name = (string) ($item['description'] ?? '');
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $names[] = $qty > 1 ? $name . ' x' . $qty : $name;
                continue;
            }
            $names[] = (string) $item;
        }
        return $names;
    }
}

