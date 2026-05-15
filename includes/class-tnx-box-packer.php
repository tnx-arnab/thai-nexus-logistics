<?php
/**
 * TNX Box Packer Class
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
class TNX_Box implements BoxInterface {
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
class TNX_Packable_Item implements ItemInterface {
    private $description;
    private $width;
    private $length;
    private $depth;
    private $weight;
    private $keepFlat;

    public function __construct($product, $qty) {
        $this->description = $product->get_name();
        // WooCommerce units are typically cm/kg, BoxPacker expects mm/g or consistent units.
        // We'll use mm/g for precision.
        $this->width  = (float) $product->get_width() ?: 10.0;
        $this->length = (float) $product->get_length() ?: 10.0;
        $this->depth  = (float) $product->get_height() ?: 10.0;
        $this->weight = (float) $product->get_weight() ?: 0.5;
        $this->keepFlat = false; // Could be a meta field later
    }

    public function getDescription(): string { return $this->description; }
    public function getWidth(): int { return (int) ($this->width * 10); }
    public function getLength(): int { return (int) ($this->length * 10); }
    public function getDepth(): int { return (int) ($this->depth * 10); }
    public function getWeight(): int { return (int) ($this->weight * 1000); }
    public function getAllowedRotation(): Rotation {
        return $this->keepFlat ? Rotation::KeepFlat : Rotation::BestFit;
    }
}

/**
 * Packing Result Value Object
 */
class TNX_Packing_Result {
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
                'items'  => [$item->getDescription()],
            ];
            $all[] = $box;

        }
        return $all;
    }
}

/**
 * Main Packer Service
 */
class TNX_Box_Packer {
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
     * @return TNX_Packing_Result
     */
    public function pack_items($items) {
        $result = new TNX_Packing_Result();
        $box_definitions = get_option('tnx_box_definitions', []);

        $validation_result = $this->validate_items($items);
        if ($validation_result->has_errors()) {
            return $validation_result;
        }

        if (empty($box_definitions)) {
            return $this->fallback_naive($items);
        }

        try {
            $packer = new Packer();
            $box_objs = [];
            foreach ($box_definitions as $box_data) {
                $box_objs[] = new TNX_Box($box_data);
                $packer->addBox($box_objs[count($box_objs)-1]);
            }

            // Identify items that are too large for ALL boxes before packing
            $packable_items = [];
            foreach ($items as $item_values) {
                $product = $item_values['data'];
                $qty = $item_values['quantity'];
                
                if (!$product->needs_shipping()) continue;

                $item_obj = new TNX_Packable_Item($product, $qty);
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
                $box_items = [];
                foreach ($packed_box->items->asItemArray() as $p_item) {
                    $box_items[] = $p_item->getDescription();
                }

                $result->add_box([
                    'name'   => $box_type->getReference(),
                    'length' => (float) $box_type->getOuterLength() / 10,
                    'width'  => (float) $box_type->getOuterWidth() / 10,
                    'height' => (float) $box_type->getOuterDepth() / 10,
                    'weight' => (float) $packed_box->getWeight() / 1000,
                    'items'  => $box_items,
                ]);

            }
            
        } catch (\Exception $e) {
            // If packing fails mid-way, fallback to naive for everything
            return $this->fallback_naive($items);
        }

        return $result;
    }

    /**
     * Validate all items have dimensions and weight
     */
    private function validate_items($items) {
        $result = new TNX_Packing_Result();
        foreach ($items as $item_values) {
            $product = $item_values['data'];
            if (!$product->needs_shipping()) continue;

            $l = (float) $product->get_length();
            $w = (float) $product->get_width();
            $h = (float) $product->get_height();
            $wt = (float) $product->get_weight();

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
        $result = new TNX_Packing_Result();
        $total_weight = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;

        foreach ($items as $item_values) {
            $product = $item_values['data'];
            $qty = $item_values['quantity'];

            if (!$product->needs_shipping()) continue;

            $weight = (float) $product->get_weight() ?: 0.5;
            $length = (float) $product->get_length() ?: 10;
            $width = (float) $product->get_width() ?: 10;
            $height = (float) $product->get_height() ?: 10;

            $total_weight += ($weight * $qty);
            $max_length = max($max_length, $length);
            $max_width = max($max_width, $width);
            $max_height = max($max_height, $height);
        }

        if ($total_weight > 0) {
            $items_desc = [];
            foreach ($items as $item_values) {
                if ($item_values['data']->needs_shipping()) {
                    $items_desc[] = $item_values['data']->get_name();
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
}
