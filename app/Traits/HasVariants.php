<?php

namespace App\Traits;

use App\Http\Resources\ProductItem;
use App\Shop\Products\Product;

/**
 *  Has Variants
 */
trait HasVariants
{
    public function variants()
    {
        return $this->hasMany(Product::class, 'parent_id')->with(['metas']);
    }

    public function parent()
    {
        return $this->belongsTo(Product::class, 'parent_id')->with(['metas']);
    }

    public function getBundles()
    {
        if ($this->setup != 'variable') {
            return [];
        }
        if (! count($this->variants)) {
            return [];
        }
        $groups = [];
        foreach ($this->variants as $variant) {
            $bundle = explode(':', $variant['variant_attributes']);
            $bundle_label = explode(',', $bundle[1]);
            if ($bundle[0]==='Bundle') {
                array_push($groups, [
                'attribute'=> $bundle[1],  // Variant Name
                'bottles' => $bundle_label[0],
                'label' => $bundle_label[1],
                'product'=>new ProductItem($variant)
            ]);
            }
        }
        return $groups;
    }
    public function getVariantGroups()
    {
        if ($this->setup != 'variable') {
            return [];
        }

        $groups = [];

        if (count($this->variants)) {
            foreach ($this->variants as $variant) {
                $array = explode('|', $variant['variant_attributes']);

                if (! empty($array)) {
                    foreach ($array as $group) {
                        $gr = explode(':', $group);
                        $groups[$gr[0]][] = [
                            'attributes' => $gr[1],
                            'product' => new ProductItem($variant),
                        ];
                    }
                }
            }
        }

        return $groups;
    }

    public function getVariantAttributes()
    {
        if ($this->type != 'variant' || empty($this->variant_attributes)) {
            return [];
        }

        $attributes = [];

        $array = explode('|', $this->variant_attributes);

        if (! empty($array)) {
            foreach ($array as $group) {
                $gr = explode(':', $group);
                $attributes[] = $gr[1];
            }
        }

        return $attributes;
    }
}
